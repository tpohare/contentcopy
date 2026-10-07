<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Destination-side file handling: turns uploaded bytes into attachments.
 *
 * Every file is matched by UUID first, then by content hash, and only then
 * created from scratch. That ordering is what makes repeat pushes cheap and
 * the media library stable: pushing the same post ten times uploads each file
 * once and reuses it nine times, instead of accumulating `report-1.pdf`
 * through `report-9.pdf`.
 *
 * Hash mismatches on a known UUID mean the file genuinely changed (a new
 * revision of the same PDF, say), so the bytes behind the existing attachment
 * are replaced in place — every post already referencing it picks up the new
 * version without a single URL changing.
 */
final class MediaImporter {

	/**
	 * Postmeta key holding the SHA-256 of the current file bytes.
	 *
	 * @var string
	 */
	public const META_HASH = '_contentcopy_sha256';

	/**
	 * @var LoggerInterface Reuses, replacements and imports are all logged here.
	 */
	private LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger Warning sink; silent when omitted.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Store one uploaded file and describe where it landed.
	 *
	 * The `$envelope` arrives as a base64 JSON header alongside the raw bytes
	 * (see `Client::postFile()`), because the body itself must stay raw —
	 * base64-encoding a 20 MB PDF just to fit a JSON field would inflate it
	 * by a third for no reason.
	 *
	 * @param string               $bytes    Raw file contents, exactly as read from disk on the source.
	 * @param array<string, mixed> $envelope File metadata: `uuid`, `filename`, `sha256`, `title`, `caption`, `description`, `alt`, `origin`.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not be stored.
	 */
	public function importBytes( string $bytes, array $envelope ): MediaDescriptor|WP_Error {
		$uuid = is_string( $envelope['uuid'] ?? null ) ? $envelope['uuid'] : '';

		if ( ! Uuid::isValid( $uuid ) ) {
			return new WP_Error( 'contentcopy_bad_file', __( 'The file arrived without a valid UUID.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		if ( '' === $bytes ) {
			return new WP_Error( 'contentcopy_empty_file', __( 'The file arrived empty. It may have exceeded a server upload limit in transit.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		// The checksum is the tripwire for corrupted transfers: the source
		// hashed the bytes it read, and anything else arriving here means the
		// file must be pushed again, never stored half-written.
		$expected = is_string( $envelope['sha256'] ?? null ) ? $envelope['sha256'] : '';
		$actual   = hash( 'sha256', $bytes );

		if ( '' !== $expected && ! hash_equals( $expected, $actual ) ) {
			return new WP_Error( 'contentcopy_corrupt_file', __( 'The file was corrupted in transit. Please push again.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$filename = isset( $envelope['filename'] ) ? sanitize_file_name( (string) $envelope['filename'] ) : '';

		if ( '' === $filename ) {
			return new WP_Error( 'contentcopy_bad_file', __( 'The file arrived without a filename.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		// The destination's own allow-list decides. A source that permits SVGs
		// pushing to a destination that does not gets a clear error, not a
		// silently missing image.
		$type = wp_check_filetype( $filename );

		if ( empty( $type['type'] ) ) {
			return new WP_Error(
				'contentcopy_bad_filetype',
				sprintf(
					/* translators: %s: filename. */
					__( 'The destination site does not allow files of the type of %s.', 'contentcopy' ),
					$filename
				),
				[ 'status' => 400 ]
			);
		}

		if ( strlen( $bytes ) > wp_max_upload_size() ) {
			return new WP_Error(
				'contentcopy_file_too_large',
				sprintf(
					/* translators: 1: filename, 2: size limit. */
					__( '%1$s is larger than the destination upload limit of %2$s.', 'contentcopy' ),
					$filename,
					size_format( wp_max_upload_size() )
				),
				[ 'status' => 400 ]
			);
		}

		// UUID match first: this exact file was pushed before.
		$existing = Uuid::findLocal( $uuid, 'attachment' );

		if ( $existing > 0 ) {
			return $this->reuse( $existing, $bytes, $actual, $envelope, $filename );
		}

		// Hash match second: the bytes already live here under a different
		// identity (uploaded by hand, or pushed before UUIDs existed). Adopt
		// the match so the next push finds it by UUID straight away.
		$byHash = $this->findByHash( $actual );

		if ( $byHash > 0 ) {
			if ( '' === Uuid::get( $byHash ) ) {
				update_post_meta( $byHash, Uuid::META, $uuid );
			}

			$this->logger->info(
				'Reused existing attachment {id} for {file} (hash match).',
				[ 'id' => $byHash, 'file' => $filename ]
			);

			$this->updateFields( $byHash, $envelope );

			return $this->describe( $byHash );
		}

		return $this->create( $bytes, $filename, $type['type'], $uuid, $actual, $envelope );
	}

	/**
	 * Handle a file whose UUID already exists on the destination.
	 *
	 * Same bytes: refresh the titles and hand back the existing attachment —
	 * no upload, no duplicate. Different bytes: replace the file in place so
	 * every existing reference picks up the new version, then regenerate the
	 * thumbnails for it.
	 *
	 * @param int                  $attachmentId Destination attachment ID.
	 * @param string               $bytes        Freshly uploaded bytes.
	 * @param string               $hash         SHA-256 of the fresh bytes.
	 * @param array<string, mixed> $envelope     File metadata from the source.
	 * @param string               $filename     Sanitised filename, for logging.
	 *
	 * @return MediaDescriptor|WP_Error Descriptor for the (possibly updated) attachment.
	 */
	private function reuse( int $attachmentId, string $bytes, string $hash, array $envelope, string $filename ): MediaDescriptor|WP_Error {
		$stored = (string) get_post_meta( $attachmentId, self::META_HASH, true );

		if ( '' !== $stored && hash_equals( $stored, $hash ) ) {
			$this->logger->info(
				'Reused existing attachment {id} for {file} (UUID match).',
				[ 'id' => $attachmentId, 'file' => $filename ]
			);

			$this->updateFields( $attachmentId, $envelope );

			return $this->describe( $attachmentId );
		}

		$current = get_attached_file( $attachmentId );

		if ( false === $current ) {
			return new WP_Error( 'contentcopy_missing_file', __( 'The existing attachment has no file and cannot be updated.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- intentional in-place replacement of a file we own.
		if ( false === file_put_contents( $current, $bytes, LOCK_EX ) ) {
			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not write the replacement file.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		update_post_meta( $attachmentId, self::META_HASH, $hash );
		$this->regenerate( $attachmentId );
		$this->updateFields( $attachmentId, $envelope );

		$this->logger->info(
			'Replaced the file behind attachment {id} ({file}).',
			[ 'id' => $attachmentId, 'file' => $filename ]
		);

		return $this->describe( $attachmentId );
	}

	/**
	 * Create a brand-new attachment from uploaded bytes.
	 *
	 * @param string               $bytes    Raw file contents.
	 * @param string               $filename Sanitised filename.
	 * @param string               $mime     Verified MIME type.
	 * @param string               $uuid     Cross-site identity to stamp on the attachment.
	 * @param string               $hash     SHA-256 of the bytes, stored for future dedup.
	 * @param array<string, mixed> $envelope Titles, caption, alt text and traceability origin.
	 *
	 * @return MediaDescriptor|WP_Error Descriptor for the new attachment.
	 */
	private function create( string $bytes, string $filename, string $mime, string $uuid, string $hash, array $envelope ): MediaDescriptor|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( $filename, null, $bytes );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'contentcopy_upload_failed', (string) $upload['error'], [ 'status' => 500 ] );
		}

		$title = is_string( $envelope['title'] ?? null ) && '' !== $envelope['title']
			? sanitize_text_field( $envelope['title'] )
			: pathinfo( $filename, PATHINFO_FILENAME );

		$attachmentId = wp_insert_attachment(
			[
				'guid'           => $upload['url'],
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_content'   => is_string( $envelope['description'] ?? null ) ? wp_kses_post( $envelope['description'] ) : '',
				'post_excerpt'   => is_string( $envelope['caption'] ?? null ) ? wp_kses_post( $envelope['caption'] ) : '',
				'post_status'    => 'inherit',
			],
			$upload['file']
		);

		// At runtime this can be 0 or a WP_Error despite what the stubs claim.
		if ( $attachmentId instanceof WP_Error ) {
			return $attachmentId;
		}

		$attachmentId = (int) $attachmentId;

		if ( 0 === $attachmentId ) {
			return new WP_Error( 'contentcopy_upload_failed', __( 'The destination could not create the attachment.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		update_post_meta( $attachmentId, Uuid::META, $uuid );
		update_post_meta( $attachmentId, self::META_HASH, $hash );

		if ( is_string( $envelope['origin'] ?? null ) && '' !== $envelope['origin'] ) {
			update_post_meta( $attachmentId, Uuid::META_ORIGIN, sanitize_text_field( $envelope['origin'] ) );
		}

		$this->regenerate( $attachmentId );

		if ( is_string( $envelope['alt'] ?? null ) && '' !== $envelope['alt'] ) {
			update_post_meta( $attachmentId, '_wp_attachment_image_alt', sanitize_text_field( $envelope['alt'] ) );
		}

		$this->logger->info(
			'Imported {file} as attachment {id}.',
			[ 'file' => $filename, 'id' => $attachmentId ]
		);

		return $this->describe( $attachmentId );
	}

	/**
	 * Refresh the human-facing fields of an adopted or reused attachment.
	 *
	 * Titles and captions belong to the content, so the source wins — but IDs
	 * and file paths are never touched here.
	 *
	 * @param int                  $attachmentId Destination attachment ID.
	 * @param array<string, mixed> $envelope     File metadata from the source.
	 *
	 * @return void
	 */
	private function updateFields( int $attachmentId, array $envelope ): void {
		if ( is_string( $envelope['alt'] ?? null ) ) {
			update_post_meta( $attachmentId, '_wp_attachment_image_alt', sanitize_text_field( $envelope['alt'] ) );
		}

		$updates = [];

		if ( is_string( $envelope['title'] ?? null ) && '' !== $envelope['title'] ) {
			$updates['post_title'] = sanitize_text_field( $envelope['title'] );
		}

		if ( is_string( $envelope['caption'] ?? null ) ) {
			$updates['post_excerpt'] = wp_kses_post( $envelope['caption'] );
		}

		if ( is_string( $envelope['description'] ?? null ) ) {
			$updates['post_content'] = wp_kses_post( $envelope['description'] );
		}

		if ( [] !== $updates ) {
			$updates['ID'] = $attachmentId;
			wp_update_post( $updates );
		}
	}

	/**
	 * Regenerate thumbnails for an attachment whose bytes just changed.
	 *
	 * Without this, replacing a file in place would leave stale
	 * `-150x150.jpg` variants of the *old* image behind the new one.
	 *
	 * @param int $attachmentId Destination attachment ID.
	 *
	 * @return void
	 */
	private function regenerate( int $attachmentId ): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = get_attached_file( $attachmentId );

		if ( false === $file ) {
			return;
		}

		$metadata = wp_generate_attachment_metadata( $attachmentId, $file );

		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachmentId, $metadata );
		}
	}

	/**
	 * Find an attachment already holding these exact bytes.
	 *
	 * @param string $hash SHA-256 of the file bytes.
	 *
	 * @return int Attachment ID, or 0 when these bytes are new here.
	 */
	private function findByHash( string $hash ): int {
		$found = function_exists( 'get_posts' )
			? get_posts(
				[
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off lookup per file, indexed by meta key.
					'meta_query'  => [
						[
							'key'   => self::META_HASH,
							'value' => $hash,
						],
					],
				]
			)
			: [];

		return ( is_array( $found ) && isset( $found[0] ) ) ? (int) $found[0] : 0;
	}

	/**
	 * Describe an attachment for the source's remapper.
	 *
	 * Size URLs are resolved per registered size so the remapper can rewrite
	 * `-150x150.jpg` variants exactly; anything the destination did not
	 * generate simply falls back to full size later via the descriptor.
	 *
	 * @param int $attachmentId Destination attachment ID.
	 *
	 * @return MediaDescriptor|WP_Error Descriptor, or an error when the attachment has no URL.
	 */
	private function describe( int $attachmentId ): MediaDescriptor|WP_Error {
		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $attachmentId ) : false;

		if ( false === $url ) {
			return new WP_Error( 'contentcopy_upload_failed', __( 'The attachment was created but its URL could not be determined.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$sizes    = [];
		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachmentId ) : false;

		if ( is_array( $metadata ) && is_array( $metadata['sizes'] ?? null ) ) {
			foreach ( array_keys( $metadata['sizes'] ) as $sizeName ) {
				$sized = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $attachmentId, (string) $sizeName ) : false;

				if ( is_string( $sized ) ) {
					$sizes[ (string) $sizeName ] = $sized;
				}
			}
		}

		return new MediaDescriptor( id: $attachmentId, url: $url, sizes: $sizes );
	}
}
