<?php
/**
 * Plugin Name:       Content Copy
 * Description:       Push a post, page or custom post type — with its media, terms and custom fields — from one WordPress site to another. Install on both sites.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.4
 * Author:            Tony O'Hare
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contentcopy
 *
 * @package ContentCopy
 */

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

// The plugin's public constants. Namespaced, so VERSION and FILE read as
// ContentCopy\VERSION and ContentCopy\FILE everywhere else.
const VERSION = '0.1.0';
const FILE    = __FILE__;

// Bail early — with an explanation, not a white screen — on hosts still
// running older PHP. Everything below uses PHP 8.4 syntax unconditionally.
if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: current PHP version. */
						__( 'Content Copy requires PHP 8.4 or newer. This site runs PHP %s.', 'contentcopy' ),
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

// Composer dependencies (Monolog and friends) ship inside the plugin
// directory. If they were never installed, say exactly how to fix it.
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Content Copy is missing its dependencies. Run "composer install --no-dev" in the plugin directory.', 'contentcopy' )
			);
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Everything boots from here: the singleton wires up settings, identity,
// the editor UI and both REST sides (source and destination routes are
// always both registered — one install plays either role).
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
