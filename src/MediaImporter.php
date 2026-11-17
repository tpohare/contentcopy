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
 *
 * Files arrive two ways and are treated identically once they are on disk:
 * small ones as the body of a single request (`importBytes()`), large ones
 * reassembled from pieces by `ChunkStore` (`importAssembled()`). Everything
 * below those two entry points works from a path rather than from a string in
 * memory, because the whole point of the chunked route is never to hold a
 * 500 MB file in PHP's heap.
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
	 * Store one file that arrived whole, in the body of a single request.
	 *
	 * The `$envelope` arrives as a base64 JSON header alongside the raw bytes
	 * (see `Client::postFile()`), because the body itself must stay raw —
	 * base64-encoding a 20 MB PDF just to fit a JSON field would inflate it
	 * by a third for no reason.
	 *
	 * The bytes are written to a temporary file and handed to the shared
	 * path-based implementation. That is not a detour: the old code wrote a
	 * temporary file anyway to check the real type of the contents, and
	 * working from a path means a single implementation serves both arrival
	 * routes.
	 *
	 * @param string               $bytes    Raw file contents, exactly as read from disk on the source.
	 * @param array<string, mixed> $envelope File metadata: `uuid`, `filename`, `sha256`, `title`, `caption`, `description`, `alt`, `origin`.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not be stored.
	 */
	public function importBytes( string $bytes, array $envelope ): MediaDescriptor|WP_Error {
		if ( '' === $bytes ) {
			return new WP_Error( 'contentcopy_empty_file', __( 'The file arrived empty. It may have exceeded a server upload limit in transit.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$filename = isset( $envelope['filename'] ) ? sanitize_file_name( (string) $envelope['filename'] ) : 'upload';
		$tmp      = wp_tempnam( '' !== $filename ? $filename : 'upload' );

		if ( '' === $tmp ) {
			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not create a temporary file for the upload.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file this method owns and deletes below.
		if ( false === file_put_contents( $tmp, $bytes, LOCK_EX ) ) {
			wp_delete_file( $tmp );

			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not write the upload to disk.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		// A file arriving whole is bounded by PHP's own request limits, so it
		// is held to `wp_max_upload_size()` — the same ceiling the media
		// library applies. Chunked arrivals are not, and pass 0.
		$result = $this->importPath( $tmp, $envelope, (int) wp_max_upload_size() );

		// A no-op when the import moved the file into place.
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $result;
	}

	/**
	 * Store one file that arrived in pieces and was reassembled on disk.
	 *
	 * `ChunkStore` has already verified the complete file against the
	 * source's checksum by this point, so the only thing that changes
	 * relative to a single-request arrival is the ceiling: PHP's per-request
	 * limit is irrelevant to a file that never crossed the wire in one
	 * request, and the spool enforced its own limit while receiving it.
	 *
	 * @param string               $path     Absolute path to the assembled file.
	 * @param array<string, mixed> $envelope File metadata the source sent with the first piece.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not be stored.
	 */
	public function importAssembled( string $path, array $envelope ): MediaDescriptor|WP_Error {
		return $this->importPath( $path, $envelope, 0 );
	}

	/**
	 * Store one file that is already on disk, however it got there.
	 *
	 * @param string               $path     Absolute path to the file.
	 * @param array<string, mixed> $envelope File metadata from the source.
	 * @param int                  $limit    Largest file to accept, or 0 for no per-request ceiling.
	 *
	 * @return MediaDescriptor|WP_Error Where the file landed, or why it could not be stored.
	 */
	private function importPath( string $path, array $envelope, int $limit ): MediaDescriptor|WP_Error {
		$uuid = is_string( $envelope['uuid'] ?? null ) ? $envelope['uuid'] : '';

		if ( ! Uuid::isValid( $uuid ) ) {
			return new WP_Error( 'contentcopy_bad_file', __( 'The file arrived without a valid UUID.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		clearstatcache( true, $path );

		$size = file_exists( $path ) ? (int) filesize( $path ) : 0;

		if ( $size <= 0 ) {
			return new WP_Error( 'contentcopy_empty_file', __( 'The file arrived empty. It may have exceeded a server upload limit in transit.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		// The checksum is the tripwire for corrupted transfers: the source
		// hashed the bytes it read, and anything else arriving here means the
		// file must be pushed again, never stored half-written.
		$expected = is_string( $envelope['sha256'] ?? null ) ? $envelope['sha256'] : '';
		$hashed   = hash_file( 'sha256', $path );
		$actual   = false !== $hashed ? $hashed : '';

		if ( '' === $actual ) {
			return new WP_Error( 'contentcopy_read_failed', __( 'The destination could not read the uploaded file back.', 'contentcopy' ), [ 'status' => 500 ] );
		}

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

		if ( $limit > 0 && $size > $limit ) {
			return new WP_Error(
				'contentcopy_file_too_large',
				sprintf(
					/* translators: 1: filename, 2: size limit. */
					__( '%1$s is larger than the destination upload limit of %2$s.', 'contentcopy' ),
					$filename,
					size_format( $limit )
				),
				[ 'status' => 400 ]
			);
		}

		// The extension said one thing; make the contents agree before
		// anything is written. `wp_check_filetype_and_ext()` is what
		// `wp_handle_upload()` uses, so a file arriving over REST is held to
		// exactly the standard a file arriving through the media library
		// would be.
		$verified = self::verifyFile( $path, $filename, (string) $type['type'] );

		if ( $verified instanceof WP_Error ) {
			return $verified;
		}

		// UUID match first: this exact file was pushed before.
		$existing = Uuid::findLocal( $uuid, 'attachment' );

		if ( $existing > 0 ) {
			return $this->reuse( $existing, $path, $actual, $envelope, $filename, $verified );
		}

		// Hash match second: the bytes already live here under a different
		// identity (uploaded by hand, or pushed before UUIDs existed). Adopt
		// the match so the next push finds it by UUID straight away.
		$byHash = $this->findByHash( $actual );

		if ( $byHash > 0 ) {
			// The bytes are identical, so the file can be reused either way —
			// but stamping it with a UUID and overwriting its title is an edit
			// of someone else's attachment. Without the rights for that, point
			// at it and leave it exactly as it is.
			if ( ! current_user_can( 'edit_post', $byHash ) ) {
				$this->logger->warning(
					'Reused existing attachment {id} for {file} (hash match) without updating it: your destination user cannot edit it.',
					[ 'id' => $byHash, 'file' => $filename ]
				);

				return $this->describe( $byHash );
			}

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

		return $this->create( $path, $filename, $verified, $uuid, $actual, $envelope );
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
	 * @param string               $path         Path to the freshly uploaded file.
	 * @param string               $hash         SHA-256 of the fresh bytes.
	 * @param array<string, mixed> $envelope     File metadata from the source.
	 * @param string               $filename     Sanitised filename, for logging.
	 * @param string               $mime         MIME type verified against the actual bytes.
	 *
	 * @return MediaDescriptor|WP_Error Descriptor for the (possibly updated) attachment.
	 */
	private function reuse( int $attachmentId, string $path, string $hash, array $envelope, string $filename, string $mime ): MediaDescriptor|WP_Error {
		// Replacing bytes under an existing attachment is an edit of that
		// attachment, so it answers to the same capability the media library
		// would ask for — `upload_files` on the route only says this user may
		// add files, not that they may rewrite this one.
		if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
			return new WP_Error(
				'contentcopy_forbidden',
				__( 'Your destination user cannot modify the existing copy of this file.', 'contentcopy' ),
				[ 'status' => 403 ]
			);
		}

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

		// The replacement inherits the existing file's name, path and URL, so
		// the incoming bytes have to be the same kind of thing the old ones
		// were. Without this, anything the destination accepts as an upload
		// could be written underneath an image's URL — already linked from
		// every post that references it, and still served as an image.
		$currentType = wp_check_filetype( wp_basename( $current ) );
		$currentMime = is_string( $currentType['type'] ?? null ) ? $currentType['type'] : '';

		if ( '' === $currentMime || $currentMime !== $mime ) {
			return new WP_Error(
				'contentcopy_type_mismatch',
				sprintf(
					/* translators: 1: incoming filename, 2: incoming MIME type, 3: existing MIME type. */
					__( '%1$s is a %2$s file, but the destination copy it would replace is a %3$s file. Delete the destination copy and push again to store it as a new file.', 'contentcopy' ),
					$filename,
					$mime,
					'' === $currentMime ? __( 'unknown', 'contentcopy' ) : $currentMime
				),
				[ 'status' => 400 ]
			);
		}

		// Streamed into the existing file rather than renamed over it, so the
		// attachment keeps its inode, its permissions and its ownership —
		// and so a multi-gigabyte replacement never passes through memory.
		if ( ! self::replaceFile( $path, $current ) ) {
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
	 * @param string               $path     Path to the uploaded file.
	 * @param string               $filename Sanitised filename.
	 * @param string               $mime     Verified MIME type.
	 * @param string               $uuid     Cross-site identity to stamp on the attachment.
	 * @param string               $hash     SHA-256 of the bytes, stored for future dedup.
	 * @param array<string, mixed> $envelope Titles, caption, alt text and traceability origin.
	 *
	 * @return MediaDescriptor|WP_Error Descriptor for the new attachment.
	 */
	private function create( string $path, string $filename, string $mime, string $uuid, string $hash, array $envelope ): MediaDescriptor|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = self::placeFile( $path, $filename );

		if ( $upload instanceof WP_Error ) {
			return $upload;
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
	 * Confirm the file's contents really are what its name claims they are.
	 *
	 * `wp_check_filetype()` only reads the extension — rename a script
	 * `photo.jpg` and it is happy. This runs the same content check
	 * `wp_handle_upload()` applies to a browser upload, so a file arriving
	 * over REST is held to the standard a file arriving through the media
	 * library would be, before anything is written into the uploads
	 * directory.
	 *
	 * A `proper_filename` in the result is WordPress saying "these contents
	 * are not that extension, call it this instead"; here that is a refusal
	 * rather than a rename, because the source and destination must agree on
	 * the filename for the next push to match it.
	 *
	 * @param string $path     Path to the file to inspect.
	 * @param string $filename Sanitised filename the file will be stored as.
	 * @param string $declared MIME type the extension implied.
	 *
	 * @return string|WP_Error The verified MIME type, or why the file was refused.
	 */
	private static function verifyFile( string $path, string $filename, string $declared ): string|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// The real contents are read from `$path`, while `$filename` decides
		// which extension they are judged against — so a spool file called
		// `a1b2….part` is still checked as the `report.pdf` it will become.
		$checked = wp_check_filetype_and_ext( $path, $filename );

		$verified = is_string( $checked['type'] ?? null ) ? (string) $checked['type'] : '';
		$proper   = is_string( $checked['proper_filename'] ?? null ) ? (string) $checked['proper_filename'] : '';

		if ( '' === $verified || '' !== $proper || $verified !== $declared ) {
			return new WP_Error(
				'contentcopy_bad_filetype',
				sprintf(
					/* translators: %s: filename. */
					__( 'The contents of %s do not match its file extension, so the destination refused it.', 'contentcopy' ),
					$filename
				),
				[ 'status' => 400 ]
			);
		}

		return $verified;
	}

	/**
	 * Move a verified file into the uploads directory under a free name.
	 *
	 * `wp_upload_bits()` does exactly this in one call, but only from a
	 * string in memory — which is the one thing the chunked route exists to
	 * avoid. The sequence here is the same one core runs: resolve the month
	 * directory, claim a filename nothing else is using, put the file there,
	 * then match the directory's permissions.
	 *
	 * @param string $path     Path to the verified file.
	 * @param string $filename Sanitised filename to store it as.
	 *
	 * @return array{file: string, url: string}|WP_Error Where the file landed, or why it could not be stored.
	 */
	private static function placeFile( string $path, string $filename ): array|WP_Error {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['path'] ) ) {
			return new WP_Error(
				'contentcopy_upload_failed',
				is_string( $uploads['error'] ?? null ) && '' !== $uploads['error']
					? (string) $uploads['error']
					: __( 'The destination has no writable uploads directory.', 'contentcopy' ),
				[ 'status' => 500 ]
			);
		}

		$directory = (string) $uploads['path'];
		$unique    = wp_unique_filename( $directory, $filename );
		$target    = trailingslashit( $directory ) . $unique;

		if ( ! self::moveFile( $path, $target ) ) {
			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not write the uploaded file into the uploads directory.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		// Inherit the uploads directory's permissions, exactly as
		// `wp_upload_bits()` does: a file created by PHP otherwise ends up
		// with whatever the umask dictates, which on some hosts is not
		// readable by the web server that has to serve it.
		$stat = stat( $directory );

		if ( is_array( $stat ) ) {
			chmod( $target, $stat['mode'] & 0007777 & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- file this method just created.
		}

		return [
			'file' => $target,
			'url'  => trailingslashit( (string) $uploads['url'] ) . $unique,
		];
	}

	/**
	 * Put a file at a path it does not yet occupy.
	 *
	 * A rename is instant and costs no disk space, so it is preferred — but
	 * only when both paths are on the same filesystem, since `rename()`
	 * across devices fails noisily on some platforms. The copy fallback
	 * streams, so neither branch loads the file into memory.
	 *
	 * @param string $from Source path; consumed on success.
	 * @param string $to   Destination path.
	 *
	 * @return bool True when the file now lives at `$to`.
	 */
	private static function moveFile( string $from, string $to ): bool {
		if ( self::sameDevice( dirname( $from ), dirname( $to ) ) && rename( $from, $to ) ) {
			return true;
		}

		if ( ! self::replaceFile( $from, $to ) ) {
			return false;
		}

		wp_delete_file( $from );

		return true;
	}

	/**
	 * Stream one file's contents over another, locking the target.
	 *
	 * Used both for the copy fallback above and for replacing the bytes
	 * behind an existing attachment, where keeping the original file in
	 * place — same inode, same permissions — is the point.
	 *
	 * @param string $from Source path.
	 * @param string $to   Target path; created when missing, truncated when not.
	 *
	 * @return bool True when the whole file was written.
	 */
	private static function replaceFile( string $from, string $to ): bool {
		$source = fopen( $from, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed copy of a file this plugin owns.

		if ( false === $source ) {
			return false;
		}

		$target = fopen( $to, 'cb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed copy of a file this plugin owns.

		if ( false === $target ) {
			fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream opened above.

			return false;
		}

		$written = false;

		if ( flock( $target, LOCK_EX ) ) {
			$written = ftruncate( $target, 0 )
				&& false !== stream_copy_to_stream( $source, $target )
				&& fflush( $target );

			flock( $target, LOCK_UN );
		}

		fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream opened above.
		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream opened above.

		return $written;
	}

	/**
	 * Whether two directories live on the same filesystem.
	 *
	 * Asked before renaming, so a cross-device move degrades to a copy
	 * instead of emitting a warning and failing. Windows reports device 0
	 * for everything, where renames work regardless.
	 *
	 * @param string $a First directory.
	 * @param string $b Second directory.
	 *
	 * @return bool True when a rename between them can be expected to work.
	 */
	private static function sameDevice( string $a, string $b ): bool {
		$first  = stat( $a );
		$second = stat( $b );

		return is_array( $first ) && is_array( $second ) && $first['dev'] === $second['dev'];
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
