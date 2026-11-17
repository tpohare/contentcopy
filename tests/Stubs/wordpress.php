<?php

declare(strict_types=1);

/**
 * WordPress test doubles for the Pest suite.
 *
 * These are deliberately tiny: each fake implements only what the units under
 * test actually call, with in-memory stores instead of a database. Anything
 * behaviour-heavy (HTTP, the real uploads directory) is canned.
 *
 * Rules for touching this file:
 * - Fakes default to "empty world" (no posts, no meta, no users). Tests opt
 *   into state explicitly, so a test can never pass because of leftover data.
 * - Call `contentcopy_test_reset()` in `beforeEach()` (done globally below
 *   via Pest's own hook in each suite that needs it, or per test file).
 * - Never define `acf_get_field()`: its absence is what exercises the
 *   no-ACF code paths.
 */

// ---------------------------------------------------------------------------
// Core constants the plugin reads for sizes and durations.
// ---------------------------------------------------------------------------

defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
defined( 'GB_IN_BYTES' ) || define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );

// ---------------------------------------------------------------------------
// Minimal class stand-ins.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Bare-bones post object. Real WP_Post is magic-method heavy; the code
	 * under test only reads public properties, so plain props suffice.
	 */
	class WP_Post {

		/** @var int */
		public $ID = 0;

		/** @var string */
		public $post_title = '';

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_excerpt = '';

		/** @var string */
		public $post_name = '';

		/** @var string */
		public $post_status = 'draft';

		/** @var string */
		public $post_date_gmt = '2024-01-01 00:00:00';

		/** @var string */
		public $post_modified_gmt = '2024-01-01 00:00:00';

		/** @var int */
		public $post_author = 0;

		/** @var string */
		public $post_type = 'post';

		/** @var int */
		public $menu_order = 0;

		/** @var string */
		public $comment_status = 'open';

		/** @var string */
		public $ping_status = 'open';

		/** @var int */
		public $post_parent = 0;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/** Minimal term object: slug, name and parent chain only. */
	class WP_Term {

		/** @var string */
		public $slug = '';

		/** @var string */
		public $name = '';

		/** @var int */
		public $parent = 0;

		/** @var int */
		public $term_id = 0;
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/** Minimal user object: the identity fields the author matcher reads. */
	class WP_User {

		/** @var int */
		public $ID = 0;

		/** @var string */
		public $user_email = '';

		/** @var string */
		public $user_login = '';

		/** @var string */
		public $display_name = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal error object: code, message and data, like the real thing. */
	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		/**
		 * @param string $code    Machine-readable error code.
		 * @param string $message Human-readable explanation.
		 * @param mixed  $data    Extra context (often an HTTP status).
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}

		/** @return mixed */
		public function get_error_data() {
			return $this->data;
		}
	}
}

// ---------------------------------------------------------------------------
// In-memory stores. Reset between tests with contentcopy_test_reset().
// ---------------------------------------------------------------------------

$GLOBALS['contentcopy_test_meta']         = [];
$GLOBALS['contentcopy_test_uuid_counter'] = 0;
$GLOBALS['contentcopy_test_post_types']   = [];
$GLOBALS['contentcopy_test_upload_urls']  = [];
$GLOBALS['contentcopy_test_options']      = [];

if ( ! function_exists( 'contentcopy_test_reset' ) ) {
	/**
	 * Empty every fake store so each test starts from an empty world.
	 *
	 * @return void
	 */
	function contentcopy_test_reset() {
		$GLOBALS['contentcopy_test_meta']         = [];
		$GLOBALS['contentcopy_test_uuid_counter'] = 0;
		$GLOBALS['contentcopy_test_post_types']   = [];
		$GLOBALS['contentcopy_test_upload_urls']  = [];
		$GLOBALS['contentcopy_test_options']      = [];
	}
}

// ---------------------------------------------------------------------------
// Strings, paths and formatting.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'trailingslashit' ) ) {
	/** @param string $value @return string */
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/** @param string $value @return string */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Optional path to append.
	 * @return string Fake site URL with the path appended.
	 */
	function home_url( $path = '' ) {
		return 'https://source.test' . (string) $path;
	}
}

if ( ! function_exists( 'set_url_scheme' ) ) {
	/**
	 * @param string      $url    URL to adjust.
	 * @param string|null $scheme Replacement scheme, or null to leave the URL alone.
	 * @return string The URL with the requested scheme.
	 */
	function set_url_scheme( $url, $scheme = null ) {
		$url = (string) $url;

		if ( null === $scheme ) {
			return $url;
		}

		return (string) preg_replace( '#^[a-z0-9+.-]+://#i', $scheme . '://', $url );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/** @param int $bytes @return string */
	function size_format( $bytes ) {
		return number_format( (float) $bytes ) . ' B';
	}
}

if ( ! function_exists( '__' ) ) {
	/** @param string $text @return string */
	function __( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Hookless filters: the default value always wins.
	 *
	 * @param string $hook  Filter name, ignored.
	 * @param mixed  $value Default value.
	 * @param mixed  ...$args Extra arguments, ignored.
	 * @return mixed The value, unfiltered.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default when the option was never stored.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return $GLOBALS['contentcopy_test_options'][ (string) $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value to store.
	 * @return bool
	 */
	function update_option( $name, $value ) {
		$GLOBALS['contentcopy_test_options'][ (string) $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @param mixed $value @return string|false */
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

// ---------------------------------------------------------------------------
// Filesystem: real operations in the system temp directory, so the chunk
// spool can be exercised end to end without a WordPress install.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/** @param string $directory @return bool */
	function wp_mkdir_p( $directory ) {
		$directory = (string) $directory;

		return is_dir( $directory ) || mkdir( $directory, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/** @param string $path @return void */
	function wp_delete_file( $path ) {
		if ( file_exists( (string) $path ) ) {
			unlink( (string) $path );
		}
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length        Characters to return.
	 * @param bool $specialChars  Ignored; the plugin never asks for them.
	 * @param bool $extraSpecial  Ignored.
	 * @return string Alphanumeric string of the requested length.
	 */
	function wp_generate_password( $length = 12, $specialChars = true, $extraSpecial = false ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';

		for ( $i = 0; $i < (int) $length; $i++ ) {
			$password .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $password;
	}
}

// ---------------------------------------------------------------------------
// Sanitisation: just enough to behave, never to secure (nothing here ships).
// ---------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** @param string $value @return string */
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/** @param string $value @return string */
	function sanitize_file_name( $value ) {
		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * @param mixed $value
	 * @return mixed Unserialised when the string looks serialised.
	 */
	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( ! preg_match( '/^[a-zA-Z]:[0-9]+[:;{]/', $value ) && 'N;' !== $value ) {
			return $value;
		}

		$unserialised = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test double only.

		return false === $unserialised && 'b:0;' !== $value ? $value : $unserialised;
	}
}

// ---------------------------------------------------------------------------
// Identity: predictable UUIDs so tests can assert on them.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * @return string A valid v4 UUID, counting up per test run.
	 */
	function wp_generate_uuid4() {
		++$GLOBALS['contentcopy_test_uuid_counter'];

		return sprintf( 'aaaaaaaa-bbbb-4ccc-8ddd-%012d', $GLOBALS['contentcopy_test_uuid_counter'] );
	}
}

if ( ! function_exists( 'wp_is_uuid' ) ) {
	/**
	 * @param mixed $uuid    Candidate value.
	 * @param mixed $version Expected version (only 4 is honoured here).
	 * @return bool
	 */
	function wp_is_uuid( $uuid ) {
		return is_string( $uuid ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
	}
}

// ---------------------------------------------------------------------------
// Postmeta: the in-memory database behind UUID and meta tests.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key, or '' for the whole row.
	 * @param bool   $single  Return the first value instead of the list.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$row = $GLOBALS['contentcopy_test_meta'][ (int) $post_id ] ?? [];

		if ( '' === $key ) {
			return $row;
		}

		$values = $row[ (string) $key ] ?? [];

		return $single ? ( $values[0] ?? '' ) : $values;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value to store (replaces all existing rows).
	 * @return bool
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['contentcopy_test_meta'][ (int) $post_id ][ (string) $key ] = [ $value ];

		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/** @return bool */
	function add_post_meta( $post_id, $key, $value ) {
		$GLOBALS['contentcopy_test_meta'][ (int) $post_id ][ (string) $key ][] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/** @return bool */
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['contentcopy_test_meta'][ (int) $post_id ][ (string) $key ] );

		return true;
	}
}

// ---------------------------------------------------------------------------
// Media and content helpers.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/** @return array{basedir: string, baseurl: string, error: bool} */
	function wp_get_upload_dir() {
		return [
			'basedir' => sys_get_temp_dir() . '/contentcopy-uploads',
			'baseurl' => 'https://source.test/wp-content/uploads',
			'error'   => false,
		];
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	/**
	 * Resolve canned upload URLs to canned attachment IDs.
	 *
	 * Tests register mappings via $GLOBALS['contentcopy_test_upload_urls']
	 * (`url => id`); anything unregistered resolves to 0, like a real miss.
	 *
	 * @param string $url Upload URL.
	 * @return int Attachment ID or 0.
	 */
	function attachment_url_to_postid( $url ) {
		return (int) ( $GLOBALS['contentcopy_test_upload_urls'][ (string) $url ] ?? 0 );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	/**
	 * @param int $id Attachment ID.
	 * @return string|false Canned URL for ID 11, false otherwise.
	 */
	function wp_get_attachment_url( $id ) {
		return 11 === (int) $id ? 'https://source.test/wp-content/uploads/2024/img.jpg' : false;
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * @param int $id Attachment ID.
	 * @return array<string, mixed>|false One thumbnail size for ID 11, false otherwise.
	 */
	function wp_get_attachment_metadata( $id ) {
		if ( 11 !== (int) $id ) {
			return false;
		}

		return [
			'sizes' => [
				'thumbnail' => [ 'file' => 'img-150x150.jpg' ],
			],
		];
	}
}

if ( ! function_exists( 'get_children' ) ) {
	/** @return array<int> Always empty: collector tests attach nothing. */
	function get_children() {
		return [];
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/** @return int Always 0: collector tests use no featured image. */
	function get_post_thumbnail_id() {
		return 0;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * @param int $id Post ID.
	 * @return string `attachment` for the canned file ID, `post` otherwise.
	 */
	function get_post_type( $id ) {
		return 99 === (int) $id ? 'attachment' : 'post';
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * @param int $id Attachment ID.
	 * @return string|false Path to a real temp file for ID 99, false otherwise.
	 */
	function get_attached_file( $id ) {
		if ( 99 !== (int) $id ) {
			return false;
		}

		$path = sys_get_temp_dir() . '/contentcopy-test-photo.jpg';

		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, 'fake-jpeg-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		}

		return $path;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/** @return string */
	function get_the_title() {
		return 'Test attachment';
	}
}

// ---------------------------------------------------------------------------
// Payload helpers: an empty world (no taxonomies, no users) unless a test
// registers state through the $GLOBALS maps above.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/** @return array<int, string> Always empty: payload tests use uncategorised posts. */
	function get_object_taxonomies() {
		return [];
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	/** @return false Always misses: payload tests use authorless posts. */
	function get_user_by() {
		return false;
	}
}

if ( ! function_exists( 'is_sticky' ) ) {
	/** @return bool */
	function is_sticky() {
		return false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/** @param mixed $thing @return bool */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
