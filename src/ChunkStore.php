<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Destination-side spool for files that are too big to arrive in one request.
 *
 * A single-request upload is capped by the destination's own `post_max_size`
 * (and by whatever the web server in front of it allows), which on shared
 * hosting is routinely 8 MB. That cap has nothing to do with how large a file
 * WordPress can *store* — only with how much can cross the wire at once — so
 * big files are sent in pieces and reassembled here.
 *
 * The protocol is deliberately boring:
 *
 * 1. The first piece carries the file's metadata and total size. A session is
 *    opened, and its token goes back to the source.
 * 2. Every piece names the session and the byte offset it belongs at. Pieces
 *    are written at that offset and the running total is reported back.
 * 3. When the spooled size matches the declared total, `assemble()` verifies
 *    the whole file against the source's SHA-256 and hands the path over.
 *
 * Two properties fall out of that shape and are worth stating plainly:
 *
 * - **Retries are safe.** A piece that arrives twice — the usual outcome of a
 *   timeout on a connection where the request actually landed — is recognised
 *   by comparing it against the bytes already on disk, and acknowledged
 *   without being written again. A piece that *disagrees* with what is on
 *   disk is refused outright.
 * - **Nothing is ever half-imported.** The spool file is not an attachment.
 *   It becomes one only after the checksum of the complete file matches, so
 *   an abandoned upload costs disk space and nothing else.
 *
 * Abandoned sessions are the one cost that needs managing, so the store
 * sweeps anything older than its TTL whenever a new session opens, caps how
 * many a single user may have in flight, and refuses files larger than
 * `maxTotalSize()`.
 */
final class ChunkStore {

	/**
	 * How long an untouched session survives before it is swept.
	 *
	 * Long enough for a genuinely slow upload (a 500 MB file over a domestic
	 * uplink), short enough that a browser tab closed mid-push does not leave
	 * the spool full for the rest of the week.
	 *
	 * @var int
	 */
	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How many unfinished uploads one user may have in flight at once.
	 *
	 * A push uploads files one after another, so even a post with dozens of
	 * large attachments only ever needs one. More than a handful means
	 * something is retrying badly, and the cap keeps that from filling the
	 * disk.
	 *
	 * @var int
	 */
	private const MAX_OPEN_SESSIONS = 8;

	/**
	 * Directory name inside uploads, when no override is defined.
	 *
	 * @var string
	 */
	private const DIRECTORY = 'contentcopy-chunks/';

	/**
	 * @var LoggerInterface Session lifecycle narration; silent when omitted.
	 */
	private LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger Where to narrate; silent when omitted.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * The piece size this site wants the source to send.
	 *
	 * Derived from the destination's own request limit rather than picked by
	 * the source, because the destination is the only side that knows what
	 * its PHP and web server will accept. Four megabytes is comfortable
	 * everywhere and keeps peak memory per request small; the 80% headroom
	 * below the real limit leaves room for headers and for hosts whose
	 * effective limit is slightly under what PHP reports.
	 *
	 * @param int $maxUpload The site's single-request limit, in bytes.
	 *
	 * @return int Bytes per piece, always at least 64 KB.
	 */
	public static function chunkSize( int $maxUpload ): int {
		$headroom = $maxUpload > 0 ? (int) floor( $maxUpload * 0.8 ) : 0;
		$size     = $headroom > 0 ? min( 4 * MB_IN_BYTES, $headroom ) : 4 * MB_IN_BYTES;

		/**
		 * Filter the bytes per piece a chunked upload is split into.
		 *
		 * @param int $size      Bytes per piece.
		 * @param int $maxUpload The site's single-request limit, in bytes.
		 */
		$size = (int) apply_filters( 'contentcopy_chunk_size', $size, $maxUpload );

		return max( 64 * KB_IN_BYTES, $size );
	}

	/**
	 * The largest complete file this site will spool.
	 *
	 * The chunk route only requires `upload_files`, so without a ceiling any
	 * user who can upload a photo could fill the disk. Two gigabytes is far
	 * above any plausible media file and far below "the server stopped".
	 *
	 * @return int Maximum assembled size in bytes, or 0 for no limit.
	 */
	public static function maxTotalSize(): int {
		/**
		 * Filter the largest complete file a chunked upload may assemble.
		 *
		 * @param int $bytes Maximum assembled size in bytes. Return 0 for no limit.
		 */
		return max( 0, (int) apply_filters( 'contentcopy_max_chunked_size', 2 * GB_IN_BYTES ) );
	}

	/**
	 * Open a session for one incoming file.
	 *
	 * @param array<string, mixed> $envelope File metadata from the source (uuid, filename, sha256, titles…).
	 * @param int                  $total    Declared size of the complete file, in bytes.
	 * @param int                  $userId   Authenticated user, recorded as the session's owner.
	 *
	 * @return string|WP_Error Session token, or why one could not be opened.
	 */
	public function open( array $envelope, int $total, int $userId ): string|WP_Error {
		$directory = $this->directory();

		if ( null === $directory ) {
			return new WP_Error(
				'contentcopy_no_spool',
				__( 'The destination has nowhere to spool a large upload. Check that the uploads directory is writable.', 'contentcopy' ),
				[ 'status' => 500 ]
			);
		}

		if ( $total <= 0 ) {
			return new WP_Error( 'contentcopy_bad_chunk', __( 'The upload did not declare how large the file is.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$ceiling = self::maxTotalSize();

		if ( $ceiling > 0 && $total > $ceiling ) {
			return new WP_Error(
				'contentcopy_file_too_large',
				sprintf(
					/* translators: 1: file size, 2: size limit. */
					__( 'The file is %1$s, which is larger than the %2$s the destination accepts.', 'contentcopy' ),
					size_format( $total ),
					size_format( $ceiling )
				),
				[ 'status' => 400 ]
			);
		}

		$this->sweep( $directory );

		if ( $this->openSessions( $directory, $userId ) >= self::MAX_OPEN_SESSIONS ) {
			return new WP_Error(
				'contentcopy_too_many_uploads',
				__( 'Too many unfinished uploads are already in progress for this user. Wait for them to expire, then push again.', 'contentcopy' ),
				[ 'status' => 429 ]
			);
		}

		$token = wp_generate_password( 32, false, false );
		$paths = $this->paths( $directory, $token );

		if ( null === $paths ) {
			return new WP_Error( 'contentcopy_no_spool', __( 'The destination could not name a spool file.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$meta = [
			'total'    => $total,
			'sha256'   => is_string( $envelope['sha256'] ?? null ) ? $envelope['sha256'] : '',
			'user'     => $userId,
			'created'  => time(),
			'envelope' => $envelope,
		];

		if ( false === file_put_contents( $paths['meta'], (string) wp_json_encode( $meta ), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- spool file this class owns.
			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not start the upload.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		if ( false === file_put_contents( $paths['part'], '', LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- spool file this class owns.
			wp_delete_file( $paths['meta'] );

			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not start the upload.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$this->logger->info(
			'Receiving {file} in pieces ({size}).',
			[
				'file' => is_string( $envelope['filename'] ?? null ) ? $envelope['filename'] : '(unnamed)',
				'size' => size_format( $total ),
			]
		);

		return $token;
	}

	/**
	 * Write one piece at its declared offset.
	 *
	 * The offset — not arrival order — decides where bytes land, which is
	 * what makes a retry harmless. Three cases are distinguished, and the
	 * distinction is the whole safety argument:
	 *
	 * - The piece starts exactly where the spool ends: append it.
	 * - The piece lies entirely within what is already stored: it is a retry
	 *   of something that landed. Confirm the stored bytes match and
	 *   acknowledge without writing, so a piece claiming an old offset can
	 *   never rewrite history.
	 * - The piece starts beyond the end of the spool: refuse, and report how
	 *   far the file actually got so the source can resume from there.
	 *
	 * @param string $token     Session token.
	 * @param int    $offset    Byte offset this piece belongs at.
	 * @param string $bytes     The piece itself.
	 * @param string $chunkHash SHA-256 of the piece, or '' to skip the check.
	 * @param int    $userId    Authenticated user, matched against the session owner.
	 *
	 * @return array{received: int, total: int, complete: bool}|WP_Error Progress so far, or why the piece was refused.
	 */
	public function append( string $token, int $offset, string $bytes, string $chunkHash, int $userId ): array|WP_Error {
		$session = $this->session( $token, $userId );

		if ( $session instanceof WP_Error ) {
			return $session;
		}

		$length = strlen( $bytes );
		$total  = (int) $session['meta']['total'];

		if ( '' !== $chunkHash && ! hash_equals( $chunkHash, hash( 'sha256', $bytes ) ) ) {
			return new WP_Error(
				'contentcopy_corrupt_chunk',
				__( 'A piece of the file was corrupted in transit. Please push again.', 'contentcopy' ),
				[ 'status' => 400 ]
			);
		}

		if ( $offset < 0 || $offset + $length > $total ) {
			return new WP_Error(
				'contentcopy_bad_chunk',
				__( 'A piece of the file did not fit the file it belongs to. Please push again.', 'contentcopy' ),
				[ 'status' => 400 ]
			);
		}

		$part = $session['part'];

		// Read and write, creating when missing and never truncating: the
		// write puts the piece at its offset, and the read is what
		// recognises a piece that already landed.
		$handle = fopen( $part, 'c+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- spool file this class owns.

		if ( false === $handle ) {
			return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not open the spooled upload.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not lock the spooled upload.', 'contentcopy' ), [ 'status' => 500 ] );
			}

			$stat     = fstat( $handle );
			$received = is_array( $stat ) ? (int) $stat['size'] : 0;

			if ( $offset > $received ) {
				return new WP_Error(
					'contentcopy_chunk_gap',
					__( 'A piece of the file arrived out of order.', 'contentcopy' ),
					[
						'status'   => 409,
						'received' => $received,
						'total'    => $total,
					]
				);
			}

			if ( $offset + $length <= $received ) {
				$stored = stream_get_contents( $handle, $length, $offset );

				if ( ! is_string( $stored ) || ! hash_equals( hash( 'sha256', $stored ), hash( 'sha256', $bytes ) ) ) {
					return new WP_Error(
						'contentcopy_chunk_conflict',
						__( 'A piece of the file disagreed with what the destination already had. Please push again.', 'contentcopy' ),
						[
							'status'   => 409,
							'received' => $received,
							'total'    => $total,
						]
					);
				}
			} else {
				if ( -1 === fseek( $handle, $offset ) ) {
					return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not position the spooled upload.', 'contentcopy' ), [ 'status' => 500 ] );
				}

				if ( $length !== fwrite( $handle, $bytes ) ) {
					return new WP_Error( 'contentcopy_write_failed', __( 'The destination could not write a piece of the upload. It may be out of disk space.', 'contentcopy' ), [ 'status' => 500 ] );
				}

				fflush( $handle );

				$received = $offset + $length;
			}
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- spool file this class owns.
		}

		// Keep the session alive for as long as pieces keep arriving: the
		// sweep goes by the metadata file's modification time, and a slow
		// upload must not be swept out from under itself.
		touch( $session['meta_path'] );
		clearstatcache( true, $part );

		return [
			'received' => $received,
			'total'    => $total,
			'complete' => $received >= $total,
		];
	}

	/**
	 * Verify a complete spool file and hand over its path and metadata.
	 *
	 * This is the tripwire for every way a transfer can go wrong without
	 * anyone noticing: a piece silently dropped by a proxy, a truncated
	 * write, a disk that ran out mid-upload. The source hashed the file
	 * before sending it, so anything else here means the file must be pushed
	 * again — and the spool is discarded so the next attempt starts clean.
	 *
	 * @param string $token  Session token.
	 * @param int    $userId Authenticated user, matched against the session owner.
	 *
	 * @return array{path: string, envelope: array<string, mixed>}|WP_Error The verified file, or why it was refused.
	 */
	public function assemble( string $token, int $userId ): array|WP_Error {
		$session = $this->session( $token, $userId );

		if ( $session instanceof WP_Error ) {
			return $session;
		}

		$part  = $session['part'];
		$total = (int) $session['meta']['total'];

		clearstatcache( true, $part );

		$size = file_exists( $part ) ? (int) filesize( $part ) : 0;

		if ( $size !== $total ) {
			return new WP_Error(
				'contentcopy_incomplete_upload',
				__( 'The destination has only part of the file. Please push again.', 'contentcopy' ),
				[
					'status'   => 409,
					'received' => $size,
					'total'    => $total,
				]
			);
		}

		$expected = is_string( $session['meta']['sha256'] ?? null ) ? $session['meta']['sha256'] : '';
		$actual   = hash_file( 'sha256', $part );

		if ( '' !== $expected && ( false === $actual || ! hash_equals( $expected, $actual ) ) ) {
			$this->discard( $token );

			return new WP_Error(
				'contentcopy_corrupt_file',
				__( 'The file was corrupted in transit. Please push again.', 'contentcopy' ),
				[ 'status' => 400 ]
			);
		}

		$envelope = is_array( $session['meta']['envelope'] ?? null ) ? $session['meta']['envelope'] : [];

		/** @var array<string, mixed> $envelope Written by `open()` from the source's own envelope. */
		return [
			'path'     => $part,
			'envelope' => $envelope,
		];
	}

	/**
	 * Delete a session's spool and metadata, whether or not they still exist.
	 *
	 * Called after a successful import (where the spool file has usually
	 * already been moved into the uploads directory) and after a failed one,
	 * so a session never outlives the request that finished with it.
	 *
	 * @param string $token Session token.
	 *
	 * @return void
	 */
	public function discard( string $token ): void {
		$directory = $this->directory();

		if ( null === $directory ) {
			return;
		}

		$paths = $this->paths( $directory, $token );

		if ( null === $paths ) {
			return;
		}

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Load and authorise one session.
	 *
	 * Ownership is checked here rather than at the route, because the token
	 * is the only thing the source sends: without this, one user's token
	 * would let any other uploader append to their file.
	 *
	 * @param string $token  Session token.
	 * @param int    $userId Authenticated user.
	 *
	 * @return array{meta: array<string, mixed>, part: string, meta_path: string}|WP_Error
	 */
	private function session( string $token, int $userId ): array|WP_Error {
		$directory = $this->directory();

		if ( null === $directory ) {
			return new WP_Error( 'contentcopy_no_spool', __( 'The destination has nowhere to spool a large upload.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		$paths = $this->paths( $directory, $token );

		if ( null === $paths || ! file_exists( $paths['meta'] ) ) {
			return new WP_Error(
				'contentcopy_unknown_session',
				__( 'The destination does not recognise this upload, or it has expired. Please push again.', 'contentcopy' ),
				[ 'status' => 410 ]
			);
		}

		$meta = json_decode( (string) file_get_contents( $paths['meta'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- spool file this class owns.

		if ( ! is_array( $meta ) || ! isset( $meta['total'], $meta['user'] ) ) {
			return new WP_Error( 'contentcopy_unknown_session', __( 'The destination could not read the state of this upload. Please push again.', 'contentcopy' ), [ 'status' => 410 ] );
		}

		if ( (int) $meta['user'] !== $userId ) {
			return new WP_Error( 'contentcopy_forbidden', __( 'This upload belongs to a different user on the destination.', 'contentcopy' ), [ 'status' => 403 ] );
		}

		/** @var array<string, mixed> $meta Shape written by `open()`. */
		return [
			'meta'      => $meta,
			'part'      => $paths['part'],
			'meta_path' => $paths['meta'],
		];
	}

	/**
	 * The spool and metadata paths for a token.
	 *
	 * The token is the only caller-supplied part of these paths, so it is
	 * checked against a strict alphanumeric shape rather than sanitised: a
	 * value that is not exactly what `open()` generated has no business
	 * naming a file at all.
	 *
	 * @param string $directory Spool directory, with trailing slash.
	 * @param string $token     Session token.
	 *
	 * @return array{part: string, meta: string}|null Paths, or null when the token is not a token.
	 */
	private function paths( string $directory, string $token ): ?array {
		if ( 32 !== strlen( $token ) || ! ctype_alnum( $token ) ) {
			return null;
		}

		return [
			'part' => $directory . $token . '.part',
			'meta' => $directory . $token . '.json',
		];
	}

	/**
	 * How many unfinished sessions a user currently owns.
	 *
	 * @param string $directory Spool directory, with trailing slash.
	 * @param int    $userId    User to count for.
	 *
	 * @return int Open session count.
	 */
	private function openSessions( string $directory, int $userId ): int {
		$count = 0;

		foreach ( (array) glob( $directory . '*.json' ) as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}

			$meta = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- spool file this class owns.

			if ( is_array( $meta ) && (int) ( $meta['user'] ?? 0 ) === $userId ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Delete sessions nothing has touched for longer than the TTL.
	 *
	 * Runs when a new session opens rather than on a cron hook, so the spool
	 * is tidied by the same traffic that fills it and a site that never
	 * receives a chunked upload never schedules anything.
	 *
	 * @param string $directory Spool directory, with trailing slash.
	 *
	 * @return void
	 */
	private function sweep( string $directory ): void {
		$cutoff = time() - self::TTL;

		foreach ( (array) glob( $directory . '*.json' ) as $path ) {
			if ( ! is_string( $path ) || (int) filemtime( $path ) > $cutoff ) {
				continue;
			}

			$part = substr( $path, 0, -5 ) . '.part';

			wp_delete_file( $path );

			if ( file_exists( $part ) ) {
				wp_delete_file( $part );
			}

			$this->logger->info( 'Swept an abandoned upload from the chunk spool.' );
		}
	}

	/**
	 * The spool directory, created and protected on first use.
	 *
	 * A `CONTENTCOPY_CHUNK_DIR` constant overrides the location entirely, for
	 * hosts that keep large temporary files outside the uploads directory.
	 *
	 * @return string|null Directory with a trailing slash, or null when it cannot be created.
	 */
	private function directory(): ?string {
		$override = defined( 'CONTENTCOPY_CHUNK_DIR' ) ? (string) \CONTENTCOPY_CHUNK_DIR : '';

		if ( '' !== $override ) {
			return ProtectedDirectory::ensure( trailingslashit( $override ) );
		}

		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		return ProtectedDirectory::ensure( trailingslashit( (string) $uploads['basedir'] ) . self::DIRECTORY );
	}
}
