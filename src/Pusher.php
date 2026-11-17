<?php

declare(strict_types=1);

namespace ContentCopy;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Source-side push orchestration: the journey of one post to another site.
 *
 * A push is a nine-stage pipeline, and reading `push()` top to bottom tells
 * you the whole story before any single stage's details:
 *
 * 1. `ensureConfigured` — refuse early when no destination exists.
 * 2. `loadIdentity` — stamp the UUID, learn the destination's limits.
 * 3. `collectMedia` — find every attachment the post depends on.
 * 4. `syncMedia` — upload each file; abort before writing anything if one fails.
 * 5. `collectForms` — find every Gravity Forms form the post embeds.
 * 6. `syncForms` — send each form definition ahead of the content; abort if one fails.
 * 7. `resolvePostReferences` — map ACF post-reference fields to destination IDs.
 * 8. `checkDestination` — look the UUID up over there; refuse to clobber surprise edits.
 * 9. `buildPayload` — remap everything into a `PushPayload`.
 *
 * …and then `deliver()` sends the payload in one authenticated request.
 *
 * The ordering is the atomicity guarantee: media and forms are fully staged
 * before the destination writes a single post row, and the destination itself
 * writes drafts first. A failure at any stage leaves the destination post
 * untouched, and every stage aborts by returning a `WP_Error` instead of
 * calling `$next()`.
 */
final class Pusher {

	/**
	 * How many times one piece of a chunked upload is sent before giving up.
	 *
	 * Only transport failures are repeated, and the destination deduplicates
	 * pieces it already holds, so three attempts costs nothing and rescues
	 * the common case of a single dropped connection half-way through a
	 * large file.
	 *
	 * @var int
	 */
	private const CHUNK_ATTEMPTS = 3;

	/**
	 * @var LoggerInterface Where the push narrates itself (file log plus the editor's in-memory collector).
	 */
	private LoggerInterface $logger;

	/**
	 * @param Client   $client   Authenticated HTTP wrapper for the destination's routes.
	 * @param Settings $settings This site's destination configuration.
	 * @param LoggerInterface|null $logger Where to narrate the push; silent when omitted.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly Settings $settings,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Push one post to the configured destination.
	 *
	 * Cheap local validation (does the post exist, is it pushable at all?)
	 * happens up front so the pipeline only ever carries a viable job. The
	 * final result array carries the destination's report plus `source_id`
	 * and `uuid` for the editor to display.
	 *
	 * @param int  $postId Source post ID.
	 * @param bool $force  Overwrite even when the destination copy changed since the last push.
	 *
	 * @return array<string, mixed>|WP_Error Destination report on success; `contentcopy_conflict` (HTTP 409) when confirmation is needed.
	 */
	public function push( int $postId, bool $force = false ): array|WP_Error {
		$post = function_exists( 'get_post' ) ? get_post( $postId ) : null;

		if ( null === $post ) {
			return new WP_Error( 'contentcopy_not_found', __( 'That item no longer exists.', 'contentcopy' ), [ 'status' => 404 ] );
		}

		if ( function_exists( 'wp_is_post_revision' ) && ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) ) {
			return new WP_Error( 'contentcopy_bad_post', __( 'Revisions cannot be pushed. Push the item itself instead.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		if ( 'attachment' === $post->post_type ) {
			return new WP_Error( 'contentcopy_bad_post', __( 'Files are pushed automatically with their item. Push the post or page that uses the file instead.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		if ( in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return new WP_Error( 'contentcopy_bad_post', __( 'Only saved items can be pushed.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		// The whole push, reading as a sentence: configure, identify,
		// collect, sync, resolve, check, build — then deliver.
		return Pipeline::send( new PushJob( $post, $force ) )
			->through(
				[
					$this->ensureConfigured( ... ),
					$this->loadIdentity( ... ),
					$this->collectMedia( ... ),
					$this->syncMedia( ... ),
					$this->collectForms( ... ),
					$this->syncForms( ... ),
					$this->resolvePostReferences( ... ),
					$this->checkDestination( ... ),
					$this->buildPayload( ... ),
				]
			)
			->then( $this->deliver( ... ) );
	}

	/**
	 * Stage 1: refuse before touching the network when nothing is configured.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function ensureConfigured( PushJob $job, Closure $next ): mixed {
		if ( ! $this->settings->isConfigured ) {
			return new WP_Error( 'contentcopy_not_configured', __( 'No destination is configured. Open Tools → Content Copy and add one first.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$job->destination = $this->settings->destinationUrl;

		return $next( $job );
	}

	/**
	 * Stage 2: stamp the UUID and preflight the destination.
	 *
	 * The preflight (`remote/info`) fails fast on the two classic
	 * head-scratchers — wrong credentials and hosts that strip the
	 * `Authorization` header — before a single byte of media is uploaded.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function loadIdentity( PushJob $job, Closure $next ): mixed {
		$job->uuid = Uuid::ensure( $job->post->ID );

		$this->logger->info(
			'Pushing {type} {id} ("{title}") to {dest}.',
			[
				'type'  => $job->post->post_type,
				'id'    => $job->post->ID,
				'title' => $job->post->post_title,
				'dest'  => $job->destination,
			]
		);

		$info = $this->client->withLogger( $this->logger )->get( 'remote/info' );

		if ( $info instanceof WP_Error ) {
			return $info;
		}

		$job->info = $info;

		return $next( $job );
	}

	/**
	 * Stage 3: find every attachment the post depends on.
	 *
	 * Featured image, attached children, files embedded in the content, files
	 * referenced from custom fields — `MediaCollector` knows the hiding
	 * places; this stage just records the list on the job.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result.
	 */
	private function collectMedia( PushJob $job, Closure $next ): mixed {
		$job->mediaIds = ( new MediaCollector( $this->logger ) )->collect( $job->post );

		return $next( $job );
	}

	/**
	 * Stage 4: upload every attachment and record where each one landed.
	 *
	 * This is the point of no return in reverse: everything after this stage
	 * only *reads* the map built here, and anything that fails here aborts
	 * before the destination writes a single row. That is the atomicity
	 * guarantee — a failed upload can never leave a half-illustrated post.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function syncMedia( PushJob $job, Closure $next ): mixed {
		$client = $this->client->withLogger( $this->logger );
		$limits = self::destinationLimits( $job->info );

		foreach ( $job->mediaIds as $attachmentId ) {
			$descriptor = $this->uploadOne( $client, $attachmentId, $job->post->ID, $limits );

			if ( $descriptor instanceof WP_Error ) {
				return $descriptor;
			}

			$job->media[ $attachmentId ] = $descriptor;
		}

		return $next( $job );
	}

	/**
	 * Upload a single attachment and describe where it landed.
	 *
	 * The raw bytes travel unencoded (base64 would inflate a 20 MB PDF by a
	 * third); the small metadata envelope — UUID, filename, checksum, titles —
	 * rides along as a header the destination decodes.
	 *
	 * How the bytes travel depends on what the destination said it can take
	 * in one request. Anything that fits goes in a single POST, exactly as
	 * before. Anything larger is streamed in pieces, because the limit being
	 * hit is `post_max_size` — a limit on one request, not on what WordPress
	 * can store.
	 *
	 * @param Client                                       $client       Authenticated destination client.
	 * @param int                                          $attachmentId Source attachment ID.
	 * @param int                                          $postId       Source post ID, for traceability logging.
	 * @param array{single: int, chunk: int, total: int}   $limits       What the destination will accept.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not travel.
	 */
	private function uploadOne( Client $client, int $attachmentId, int $postId, array $limits ): MediaDescriptor|WP_Error {
		$file = function_exists( 'get_attached_file' ) ? get_attached_file( $attachmentId ) : false;

		if ( false === $file || ! file_exists( $file ) ) {
			return new WP_Error(
				'contentcopy_missing_file',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d has no file on disk.', 'contentcopy' ),
					$attachmentId
				),
				[ 'status' => 500 ]
			);
		}

		// Hashed from the file rather than from a string in memory, so even
		// the checksum of a multi-gigabyte file costs nothing to compute.
		$size = (int) filesize( $file );
		$hash = hash_file( 'sha256', $file );

		if ( $size <= 0 || false === $hash ) {
			return new WP_Error(
				'contentcopy_read_failed',
				sprintf(
					/* translators: %s: filename. */
					__( 'Could not read %s from disk.', 'contentcopy' ),
					basename( $file )
				),
				[ 'status' => 500 ]
			);
		}

		$sourcePost = function_exists( 'get_post' ) ? get_post( $attachmentId ) : null;

		$envelope = [
			'uuid'        => Uuid::ensure( $attachmentId ),
			'filename'    => function_exists( 'wp_basename' ) ? wp_basename( $file ) : basename( $file ),
			'sha256'      => $hash,
			'title'       => null !== $sourcePost ? (string) $sourcePost->post_title : '',
			'caption'     => null !== $sourcePost ? (string) $sourcePost->post_excerpt : '',
			'description' => null !== $sourcePost ? (string) $sourcePost->post_content : '',
			'alt'         => function_exists( 'get_post_meta' ) ? (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) : '',
			'origin'      => function_exists( 'home_url' ) ? home_url( '/?p=' . $postId ) : '',
		];

		if ( $limits['chunk'] > 0 && $size > $limits['chunk'] ) {
			if ( $limits['total'] > 0 && $size > $limits['total'] ) {
				return new WP_Error(
					'contentcopy_file_too_large',
					sprintf(
						/* translators: 1: filename, 2: file size, 3: size limit. */
						__( '%1$s is %2$s, which is larger than the %3$s the destination site accepts.', 'contentcopy' ),
						$envelope['filename'],
						self::humanSize( $size ),
						self::humanSize( $limits['total'] )
					),
					[ 'status' => 400 ]
				);
			}

			return $this->uploadInPieces( $client, $file, $envelope, $size, $limits['chunk'] );
		}

		// The destination is too old to accept a file in pieces and this one
		// will not fit in a single request. Saying so now — naming the file,
		// its size and the limit — beats uploading until PHP discards the
		// body and the destination reports an empty file.
		if ( $limits['single'] > 0 && $size > $limits['single'] ) {
			return new WP_Error(
				'contentcopy_file_too_large',
				sprintf(
					/* translators: 1: filename, 2: file size, 3: size limit. */
					__( '%1$s is %2$s, which is larger than the %3$s the destination site accepts in one upload. Update Content Copy on the destination site to send large files in pieces, or raise its upload_max_filesize and post_max_size.', 'contentcopy' ),
					$envelope['filename'],
					self::humanSize( $size ),
					self::humanSize( $limits['single'] )
				),
				[ 'status' => 400 ]
			);
		}

		$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- local read of a known media file.

		if ( false === $bytes ) {
			return new WP_Error(
				'contentcopy_read_failed',
				sprintf(
					/* translators: %s: filename. */
					__( 'Could not read %s from disk.', 'contentcopy' ),
					basename( $file )
				),
				[ 'status' => 500 ]
			);
		}

		$this->logger->info(
			'Uploading {file} ({size}).',
			[
				'file' => $envelope['filename'],
				'size' => self::humanSize( $size ),
			]
		);

		return self::describeUpload( $client->postFile( 'remote/media', $bytes, $envelope ) );
	}

	/**
	 * Upload one file as a sequence of pieces.
	 *
	 * Each piece is read straight off disk and posted on its own, so peak
	 * memory is one piece rather than one file, and each request stays well
	 * inside the destination's `post_max_size`. The destination spools the
	 * pieces, verifies the complete file against the checksum below, and only
	 * then creates the attachment — so an interrupted transfer leaves a
	 * discardable spool file, never a broken attachment.
	 *
	 * Progress comes back from the destination rather than being assumed
	 * here: `received` is authoritative, which is what lets a retried piece
	 * that actually landed be skipped instead of sent twice.
	 *
	 * @param Client               $client    Authenticated destination client.
	 * @param string               $file      Absolute path to the local file.
	 * @param array<string, mixed> $envelope  File metadata for the first piece.
	 * @param int                  $size      Total file size in bytes.
	 * @param int                  $chunkSize Bytes per piece, as the destination asked for.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not travel.
	 */
	private function uploadInPieces( Client $client, string $file, array $envelope, int $size, int $chunkSize ): MediaDescriptor|WP_Error {
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local read of a known media file.

		if ( false === $handle ) {
			return new WP_Error(
				'contentcopy_read_failed',
				sprintf(
					/* translators: %s: filename. */
					__( 'Could not read %s from disk.', 'contentcopy' ),
					basename( $file )
				),
				[ 'status' => 500 ]
			);
		}

		// One push now makes many sequential requests, each of which can take
		// a while on a slow uplink. The default 30 seconds would abort a large
		// file part-way through — and the spool on the other side would have
		// to be swept rather than finished.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$this->logger->info(
			'Uploading {file} ({size}) in pieces of {chunk}.',
			[
				'file'  => is_string( $envelope['filename'] ?? null ) ? $envelope['filename'] : basename( $file ),
				'size'  => self::humanSize( $size ),
				'chunk' => self::humanSize( $chunkSize ),
			]
		);

		$session = '';
		$offset  = 0;
		$index   = 0;
		$last    = [];

		try {
			while ( $offset < $size ) {
				$bytes = fread( $handle, $chunkSize );

				if ( false === $bytes || '' === $bytes ) {
					return new WP_Error(
						'contentcopy_read_failed',
						sprintf(
							/* translators: %s: filename. */
							__( 'Could not read %s from disk.', 'contentcopy' ),
							basename( $file )
						),
						[ 'status' => 500 ]
					);
				}

				$piece = [
					'session'      => $session,
					'index'        => $index,
					'offset'       => $offset,
					'total'        => $size,
					'chunk_sha256' => hash( 'sha256', $bytes ),
				];

				// The first piece carries the file's own metadata, which the
				// destination keeps for the whole session; later pieces only
				// need to say where they belong.
				$response = $this->sendPiece( $client, $bytes, '' === $session ? $piece + $envelope : $piece );

				if ( $response instanceof WP_Error ) {
					return $response;
				}

				$session = is_string( $response['session'] ?? null ) ? $response['session'] : '';

				if ( '' === $session ) {
					return new WP_Error( 'contentcopy_bad_response', __( 'The destination did not identify the upload it was receiving.', 'contentcopy' ), [ 'status' => 500 ] );
				}

				$received = isset( $response['received'] ) ? (int) $response['received'] : 0;

				// No forward progress means something is wrong on the other
				// side; looping on it would hammer the destination forever.
				if ( $received <= $offset ) {
					return new WP_Error(
						'contentcopy_stalled_upload',
						__( 'The destination stopped accepting pieces of the file. Please push again.', 'contentcopy' ),
						[ 'status' => 500 ]
					);
				}

				// The destination's count wins: it may already have had more
				// of the file than this request sent.
				$offset = $received;
				$last   = $response;
				++$index;

				if ( -1 === fseek( $handle, $offset ) ) {
					return new WP_Error(
						'contentcopy_read_failed',
						sprintf(
							/* translators: %s: filename. */
							__( 'Could not read %s from disk.', 'contentcopy' ),
							basename( $file )
						),
						[ 'status' => 500 ]
					);
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream opened above.
		}

		if ( empty( $last['complete'] ) ) {
			return new WP_Error( 'contentcopy_incomplete_upload', __( 'The destination did not confirm that the whole file arrived.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$this->logger->info(
			'Uploaded {file} in {count} pieces.',
			[
				'file'  => is_string( $envelope['filename'] ?? null ) ? $envelope['filename'] : basename( $file ),
				'count' => $index,
			]
		);

		return self::describeUpload( $last );
	}

	/**
	 * Send one piece, repeating it if the connection — not the destination —
	 * is what failed.
	 *
	 * A rejection is a decision and is returned immediately; a transport
	 * failure says nothing about whether the piece arrived, so it is simply
	 * sent again. That is safe because the destination recognises a piece it
	 * already holds by comparing checksums at the same offset, so the retry
	 * either fills the gap or is acknowledged as a duplicate.
	 *
	 * The one case this cannot deduplicate is the very first piece, whose
	 * reply carries the session token: if that reply is lost, the retry opens
	 * a second session and the first is left to be swept. One abandoned spool
	 * file is a better outcome than a failed push.
	 *
	 * @param Client               $client Authenticated destination client.
	 * @param string               $bytes  The piece itself.
	 * @param array<string, mixed> $piece  Envelope describing where the piece belongs.
	 *
	 * @return array<array-key, mixed>|WP_Error The destination's progress report, or why the piece was refused.
	 */
	private function sendPiece( Client $client, string $bytes, array $piece ): array|WP_Error {
		$attempts = 0;

		do {
			++$attempts;

			$response = $client->postFile( 'remote/media/chunk', $bytes, $piece );

			if ( ! $response instanceof WP_Error ) {
				return $response;
			}

			if ( 'contentcopy_transport' !== $response->get_error_code() || $attempts >= self::CHUNK_ATTEMPTS ) {
				return $response;
			}

			$this->logger->warning(
				'A piece of the file did not get through ({reason}); sending it again.',
				[ 'reason' => $response->get_error_message() ]
			);
		} while ( true );
	}

	/**
	 * Turn a media response from either upload route into a descriptor.
	 *
	 * @param array<array-key, mixed>|WP_Error $result Decoded response from `remote/media` or `remote/media/chunk`.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or the error unchanged.
	 */
	private static function describeUpload( array|WP_Error $result ): MediaDescriptor|WP_Error {
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( ! isset( $result['id'], $result['url'] ) ) {
			return new WP_Error( 'contentcopy_bad_response', __( 'The destination returned an unrecognised media response.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		return new MediaDescriptor(
			id: (int) $result['id'],
			url: (string) $result['url'],
			sizes: is_array( $result['sizes'] ?? null ) ? $result['sizes'] : [],
		);
	}

	/**
	 * Read the destination's upload limits out of its `remote/info` reply.
	 *
	 * A destination running an older version of the plugin reports no
	 * chunking block at all, which comes back as a zero here and keeps the
	 * push on the single-request path it already understands.
	 *
	 * @param array<string, mixed> $info Decoded `remote/info` payload.
	 *
	 * @return array{single: int, chunk: int, total: int} Single-request limit, bytes per piece, and largest chunked file.
	 */
	private static function destinationLimits( array $info ): array {
		return [
			'single' => self::intIn( $info, 'max_upload', 'bytes' ),
			'chunk'  => self::intIn( $info, 'chunking', 'chunk_size' ),
			'total'  => self::intIn( $info, 'chunking', 'max_total' ),
		];
	}

	/**
	 * Read one integer out of a nested diagnostics array.
	 *
	 * @param array<string, mixed> $info  Decoded `remote/info` payload.
	 * @param string               $group Outer key.
	 * @param string               $key   Inner key.
	 *
	 * @return int The value, or 0 when the destination did not report it.
	 */
	private static function intIn( array $info, string $group, string $key ): int {
		$outer = $info[ $group ] ?? null;

		if ( ! is_array( $outer ) || ! isset( $outer[ $key ] ) || ! is_numeric( $outer[ $key ] ) ) {
			return 0;
		}

		return max( 0, (int) $outer[ $key ] );
	}

	/**
	 * Format a byte count for a log line or an error message.
	 *
	 * @param int $bytes Size in bytes.
	 *
	 * @return string Human-readable size.
	 */
	private static function humanSize( int $bytes ): string {
		return function_exists( 'size_format' ) ? (string) size_format( $bytes ) : $bytes . ' B';
	}

	/**
	 * Stage 5: find every Gravity Forms form the post embeds.
	 *
	 * Shortcodes and blocks in the content, shortcodes hiding in custom field
	 * strings, and ACF form fields — `GravityFormsBridge::idsIn()` catches
	 * the text spellings, and the ACF branch catches the field type whose
	 * value *is* the ID.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result.
	 */
	private function collectForms( PushJob $job, Closure $next ): mixed {
		$found = GravityFormsBridge::idsIn( (string) $job->post->post_content );

		$meta = function_exists( 'get_post_meta' ) ? get_post_meta( $job->post->ID ) : [];

		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				$field = AcfBridge::fieldFor( $job->post->ID, (string) $key );

				if ( null !== $field && ReferenceKind::Form === AcfBridge::referenceKind( $field ) ) {
					foreach ( (array) $values as $raw ) {
						foreach ( self::integersIn( function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw ) as $candidate ) {
							$found[] = $candidate;
						}
					}

					continue;
				}

				foreach ( (array) $values as $raw ) {
					$unserialised = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;

					if ( is_string( $unserialised ) ) {
						foreach ( GravityFormsBridge::idsIn( $unserialised ) as $candidate ) {
							$found[] = $candidate;
						}
					} elseif ( is_array( $unserialised ) ) {
						foreach ( self::stringsIn( $unserialised ) as $text ) {
							foreach ( GravityFormsBridge::idsIn( $text ) as $candidate ) {
								$found[] = $candidate;
							}
						}
					}
				}
			}
		}

		$job->formIds = array_values( array_unique( array_filter( $found, static fn ( int $id ): bool => $id > 0 ) ) );

		return $next( $job );
	}

	/**
	 * Stage 6: send each embedded form ahead of the content.
	 *
	 * Forms travel as definitions, not IDs: a form 5 on the source means
	 * nothing on the destination until the destination has created (or
	 * updated) its own copy and reported back its ID. That map is what the
	 * remapper rewrites shortcodes and blocks with, so a failure here aborts
	 * before the post payload is even built.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function syncForms( PushJob $job, Closure $next ): mixed {
		if ( [] === $job->formIds ) {
			return $next( $job );
		}

		if ( ! GravityFormsBridge::active() ) {
			return new WP_Error(
				'contentcopy_no_gravity_forms',
				__( 'This item embeds a Gravity Forms form, but Gravity Forms is not active on the source site. Activate it there, then push again.', 'contentcopy' ),
				[ 'status' => 400 ]
			);
		}

		$exports = [];

		foreach ( $job->formIds as $formId ) {
			$exported = GravityFormsBridge::export( $formId );

			if ( null === $exported ) {
				return new WP_Error(
					'contentcopy_missing_form',
					sprintf(
						/* translators: %d: form ID. */
						__( 'Form %d no longer exists on the source site, so the push was stopped before the destination was changed.', 'contentcopy' ),
						$formId
					),
					[ 'status' => 400 ]
				);
			}

			$exports[] = $exported;
		}

		$result = $this->client->withLogger( $this->logger )->postJson( 'remote/forms', [ 'forms' => $exports ] );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$map = $result['map'] ?? null;

		if ( ! is_array( $map ) ) {
			return new WP_Error( 'contentcopy_bad_response', __( 'The destination returned an unrecognised forms response.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		foreach ( $map as $sourceId => $destId ) {
			$job->formMap[ (int) $sourceId ] = (int) $destId;
		}

		// A form that was exported but got no ID back keeps its source ID in
		// the content and raises a warning there, rather than failing a push
		// over a mapping the editor can see and fix.
		foreach ( $job->formIds as $formId ) {
			if ( ! isset( $job->formMap[ $formId ] ) ) {
				$this->logger->warning(
					'Form {id} was sent but the destination reported no ID for it; embeds still point at form {id}.',
					[ 'id' => $formId ]
				);
			}
		}

		if ( isset( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
			foreach ( $result['warnings'] as $warning ) {
				if ( is_string( $warning ) && '' !== $warning ) {
					$job->formWarnings[] = $warning;
				}
			}
		}

		$this->logger->info(
			'Synced {count} form(s).',
			[ 'count' => count( $job->formMap ) ]
		);

		return $next( $job );
	}

	/**
	 * Stage 7: resolve ACF post-reference fields to destination IDs.
	 *
	 * A relationship field holding source post 12 is meaningless over there.
	 * Each referenced post is looked up by its UUID, and only references
	 * whose target already exists on the destination are mapped — the
	 * `Remapper` warns about the rest and keeps the original ID so the editor
	 * can push the missing target and re-push.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function resolvePostReferences( PushJob $job, Closure $next ): mixed {
		$meta = function_exists( 'get_post_meta' ) ? get_post_meta( $job->post->ID ) : [];

		if ( ! is_array( $meta ) ) {
			return $next( $job );
		}

		$referenced = [];

		foreach ( $meta as $key => $values ) {
			$field = AcfBridge::fieldFor( $job->post->ID, (string) $key );

			if ( null === $field || ReferenceKind::Post !== AcfBridge::referenceKind( $field ) ) {
				continue;
			}

			foreach ( (array) $values as $raw ) {
				foreach ( self::integersIn( function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw ) as $candidate ) {
					if ( function_exists( 'get_post_type' ) && 'attachment' !== get_post_type( $candidate ) ) {
						$referenced[] = $candidate;
					}
				}
			}
		}

		$client = $this->client->withLogger( $this->logger );

		foreach ( array_unique( $referenced ) as $sourceId ) {
			$refPost = function_exists( 'get_post' ) ? get_post( $sourceId ) : null;

			if ( null === $refPost ) {
				continue;
			}

			$lookup = $client->get( 'remote/lookup', [ 'uuid' => Uuid::ensure( $sourceId ) ] );

			if ( $lookup instanceof WP_Error ) {
				return $lookup;
			}

			if ( ! empty( $lookup['found'] ) && isset( $lookup['id'] ) ) {
				$job->postReferences[ $sourceId ] = (int) $lookup['id'];
			}
		}

		return $next( $job );
	}

	/**
	 * Stage 8: ask the destination what it already has, and refuse surprises.
	 *
	 * After a successful push the source remembers the destination copy's
	 * hash and modification time. If neither matches what the destination
	 * reports now, somebody edited over there — overwriting silently would
	 * destroy their work, so the push aborts with `contentcopy_conflict`
	 * (HTTP 409) and the editor offers an explicit "overwrite" confirmation.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result, or a `WP_Error` aborting it.
	 */
	private function checkDestination( PushJob $job, Closure $next ): mixed {
		$lookup = $this->client->withLogger( $this->logger )->get(
			'remote/lookup',
			[
				'uuid'      => $job->uuid,
				'post_type' => $job->post->post_type,
			]
		);

		if ( $lookup instanceof WP_Error ) {
			return $lookup;
		}

		$job->remote = $lookup;

		if ( empty( $lookup['found'] ) || $job->force ) {
			return $next( $job );
		}

		$last = Uuid::getLastPush( $job->post->ID, $job->destination );

		if ( null === $last ) {
			return $next( $job );
		}

		$changed = ( (string) ( $lookup['modified'] ?? '' ) !== (string) ( $last['destination_modified'] ?? '' ) )
			|| ( (string) ( $lookup['hash'] ?? '' ) !== (string) ( $last['destination_hash'] ?? '' ) );

		if ( ! $changed ) {
			return $next( $job );
		}

		$this->logger->warning( 'Push stopped: the destination copy changed since the last push.' );

		return new WP_Error(
			'contentcopy_conflict',
			__( 'The destination copy has been edited since the last push. Pushing now would overwrite those changes. Push again with confirmation to overwrite them.', 'contentcopy' ),
			[
				'status'               => 409,
				'destination_id'       => $lookup['id'] ?? 0,
				'destination_modified' => $lookup['modified'] ?? '',
			]
		);
	}

	/**
	 * Stage 9: build the remapper and translate the post into its payload.
	 *
	 * By this point every source ID that *can* be mapped *is* mapped, so the
	 * payload coming out here contains no source URLs and no source IDs — the
	 * destination writes it verbatim.
	 *
	 * @param PushJob $job  The travelling job.
	 * @param Closure $next The rest of the pipeline.
	 *
	 * @return mixed The pipeline's eventual result.
	 */
	private function buildPayload( PushJob $job, Closure $next ): mixed {
		$job->remapper = new Remapper( $job->media, $job->postReferences, $job->formMap, $this->logger );
		$job->payload  = PushPayload::fromPost( $job->post, $job->remapper );

		return $next( $job );
	}

	/**
	 * The pipeline's destination: send the payload and record the push.
	 *
	 * The last-push record (destination ID, hash, modification time) is what
	 * the next push compares against, closing the loop opened in
	 * `checkDestination()`.
	 *
	 * @param PushJob $job The fully prepared job.
	 *
	 * @return array<string, mixed>|WP_Error Destination report plus `source_id` and `uuid`.
	 */
	private function deliver( PushJob $job ): array|WP_Error {
		if ( null === $job->payload ) {
			return new WP_Error( 'contentcopy_internal', __( 'The push payload was never built.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$result = $this->client->withLogger( $this->logger )->postJson(
			'remote/receive',
			$job->payload->toArray() + [ 'force' => $job->force ]
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		Uuid::setLastPush(
			$job->post->ID,
			$job->destination,
			[
				'destination_id'       => (int) ( $result['id'] ?? 0 ),
				'destination_hash'     => (string) ( $result['hash'] ?? '' ),
				'destination_modified' => (string) ( $result['modified'] ?? '' ),
				'time'                 => time(),
			]
		);

		$this->logger->info(
			'Push finished: {outcome} destination item {dest_id}.',
			[
				'outcome' => $result['outcome'] ?? 'done',
				'dest_id' => $result['id'] ?? 0,
			]
		);

		$result['source_id'] = $job->post->ID;
		$result['uuid']      = $job->uuid;

		if ( [] !== $job->formWarnings ) {
			$result['warnings'] = array_values(
				array_unique(
					[ ...( $result['warnings'] ?? [] ), ...$job->formWarnings ]
				)
			);
		}

		return $result;
	}

	/**
	 * Flatten every integer-like scalar inside a nested value.
	 *
	 * Used to harvest candidate post IDs out of ACF relationship-style
	 * fields, which may nest IDs inside repeaters, groups and flexible
	 * content layouts.
	 *
	 * @param mixed $value The (possibly nested) field value.
	 *
	 * @return list<int> Every integer hiding in there.
	 */
	private static function integersIn( mixed $value ): array {
		if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			return [ (int) $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$integers = [];

		foreach ( $value as $item ) {
			$integers = [ ...$integers, ...self::integersIn( $item ) ];
		}

		return $integers;
	}

	/**
	 * Flatten every string inside a nested value.
	 *
	 * Used to find shortcodes hiding inside page builder payloads, where a
	 * text field is three arrays deep and `idsIn()` only reads strings.
	 *
	 * @param mixed $value The (possibly nested) value.
	 *
	 * @return list<string> Every string hiding in there.
	 */
	private static function stringsIn( mixed $value ): array {
		if ( is_string( $value ) ) {
			return [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$strings = [];

		foreach ( $value as $item ) {
			$strings = [ ...$strings, ...self::stringsIn( $item ) ];
		}

		return $strings;
	}
}
