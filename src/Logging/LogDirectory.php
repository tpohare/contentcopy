<?php
declare(strict_types=1);

namespace ContentCopy\Logging;

use ContentCopy\ProtectedDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and protects the directory log files are written to.
 *
 * Logs record post titles, URLs and custom field keys, so they must not be
 * publicly readable. Three defences are applied, because no single one covers
 * every host: an .htaccess deny (Apache), an index.php (directory listings),
 * and a per-site secret in the filename (nginx, which ignores .htaccess).
 * The first two are `ProtectedDirectory`'s job; the third is this class's.
 */
final class LogDirectory {

	private const OPTION_SECRET = 'contentcopy_log_secret';

	private static ?string $resolved = null;

	/**
	 * Absolute path to the log directory, creating it (and its guards) on first use.
	 *
	 * A `CONTENTCOPY_LOG_DIR` constant overrides the location entirely, for
	 * hosts that keep writable storage outside the uploads directory. Returns
	 * null only when no writable directory can be produced — callers treat
	 * that as "file logging unavailable" and carry on with the null handler.
	 *
	 * @return string|null Directory with a trailing slash, or null when it cannot be created.
	 */
	public static function path(): ?string {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$override = defined( 'CONTENTCOPY_LOG_DIR' ) ? (string) \CONTENTCOPY_LOG_DIR : '';

		if ( '' !== $override ) {
			return self::$resolved = self::prepare( trailingslashit( $override ) );
		}

		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		return self::$resolved = self::prepare( trailingslashit( $uploads['basedir'] ) . 'contentcopy-logs/' );
	}

	/**
	 * Absolute path of the log file for a channel.
	 *
	 * RotatingFileHandler appends the date to whatever this returns, so this
	 * is the base name only. The per-site secret in the filename is the
	 * nginx-proofing: hosts that serve uploads unconditionally cannot have
	 * their directory listings read, but they *can* serve a guessable file —
	 * so the filename is made unguessable instead.
	 *
	 * @param string $channel Either `source` or `destination`.
	 *
	 * @return string|null Log file base path, or null when no directory is available.
	 */
	public static function file( string $channel ): ?string {
		$directory = self::path();

		if ( null === $directory ) {
			return null;
		}

		return $directory . sprintf( 'contentcopy-%s-%s.log', sanitize_key( $channel ), self::secret() );
	}

	/**
	 * Create the directory and drop in the protective files.
	 *
	 * @param string $directory Absolute directory path, with trailing slash.
	 *
	 * @return string|null The same directory, or null when creation failed.
	 */
	private static function prepare( string $directory ): ?string {
		return ProtectedDirectory::ensure( $directory );
	}

	/**
	 * Per-site random string mixed into log filenames so they cannot be guessed.
	 *
	 * Generated once and kept in an option (no autoload — it is needed maybe
	 * twice per request). Sixteen lowercase alphanumeric characters is
	 * plenty against guessing; this is obscurity *on top of* the access
	 * controls, never instead of them.
	 *
	 * @return string The site's log secret.
	 */
	private static function secret(): string {
		$secret = get_option( self::OPTION_SECRET );

		if ( is_string( $secret ) && 16 === strlen( $secret ) ) {
			return $secret;
		}

		$secret = wp_generate_password( 16, false, false );
		update_option( self::OPTION_SECRET, $secret, false );

		return $secret;
	}
}
