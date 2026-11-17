<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a directory inside uploads that the web server will not serve.
 *
 * Two parts of this plugin need private storage under `wp-content/uploads`:
 * the log files (post titles, URLs, custom field names) and the chunk spool
 * (fragments of files still in transit). Uploads are world-readable by
 * default, so both get the same three defences, because no single one covers
 * every host: an `.htaccess` deny (Apache), an `index.php` (directory
 * listings), and unguessable filenames chosen by the caller (nginx, which
 * ignores `.htaccess` entirely).
 *
 * The third defence is the caller's job, not this class's — a directory can
 * only be made unlistable, not unreadable, on a host that insists on serving
 * it.
 */
final class ProtectedDirectory {

	/**
	 * Create the directory if it is missing and drop in the guard files.
	 *
	 * Runs idempotently on every resolution: `wp_mkdir_p()` on an existing
	 * directory is a no-op, and guard files are only written when absent, so
	 * a host that manages its own `.htaccess` is never fought with.
	 *
	 * @param string $directory Absolute directory path, with trailing slash.
	 *
	 * @return string|null The same directory, or null when it could not be created.
	 */
	public static function ensure( string $directory ): ?string {
		if ( ! wp_mkdir_p( $directory ) ) {
			return null;
		}

		$guards = [
			'.htaccess' => "# Added by Content Copy.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		];

		foreach ( $guards as $name => $contents ) {
			$target = $directory . $name;

			if ( ! file_exists( $target ) ) {
				file_put_contents( $target, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard file in a directory this class owns.
			}
		}

		return $directory;
	}
}
