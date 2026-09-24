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
 * A push is a seven-stage pipeline, and reading `push()` top to bottom tells
 * you the whole story before any single stage's details:
 *
 * 1. `ensureConfigured` — refuse early when no destination exists.
 * 2. `loadIdentity` — stamp the UUID, learn the destination's limits.
 * 3. `collectMedia` — find every attachment the post depends on.
 * 4. `syncMedia` — upload each file; abort before writing anything if one fails.
 * 5. `resolvePostReferences` — map ACF post-reference fields to destination IDs.
 * 6. `checkDestination` — look the UUID up over there; refuse to clobber surprise edits.
 * 7. `buildPayload` — remap everything into a `PushPayload`.
 *
 * …and then `deliver()` sends the payload in one authenticated request.
 *
 * The ordering is the atomicity guarantee: media is fully staged before the
 * destination writes a single row, and the destination itself writes drafts
 * first. A failure at any stage leaves the destination untouched, and every
 * stage aborts by returning a `WP_Error` instead of calling `$next()`.
 */
final class Pusher {

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

		foreach ( $job->mediaIds as $attachmentId ) {
			$descriptor = $this->uploadOne( $client, $attachmentId, $job->post->ID );

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
	 * @param Client $client       Authenticated destination client.
	 * @param int    $attachmentId Source attachment ID.
	 * @param int    $postId       Source post ID, for traceability logging.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not travel.
	 */
	private function uploadOne( Client $client, int $attachmentId, int $postId ): MediaDescriptor|WP_Error {
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

		$sourcePost = function_exists( 'get_post' ) ? get_post( $attachmentId ) : null;

		$envelope = [
			'uuid'        => Uuid::ensure( $attachmentId ),
			'filename'    => function_exists( 'wp_basename' ) ? wp_basename( $file ) : basename( $file ),
			'sha256'      => hash( 'sha256', $bytes ),
			'title'       => null !== $sourcePost ? (string) $sourcePost->post_title : '',
			'caption'     => null !== $sourcePost ? (string) $sourcePost->post_excerpt : '',
			'description' => null !== $sourcePost ? (string) $sourcePost->post_content : '',
			'alt'         => function_exists( 'get_post_meta' ) ? (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) : '',
			'origin'      => function_exists( 'home_url' ) ? home_url( '/?p=' . $postId ) : '',
		];

		$this->logger->info(
			'Uploading {file} ({size}).',
			[
				'file' => $envelope['filename'],
				'size' => function_exists( 'size_format' ) ? size_format( strlen( $bytes ) ) : (string) strlen( $bytes ),
			]
		);

		$result = $client->postFile( 'remote/media', $bytes, $envelope );

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
	 * Stage 5: resolve ACF post-reference fields to destination IDs.
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
	 * Stage 6: ask the destination what it already has, and refuse surprises.
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
	 * Stage 7: build the remapper and translate the post into its payload.
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
		$job->remapper = new Remapper( $job->media, $job->postReferences, $this->logger );
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
}
