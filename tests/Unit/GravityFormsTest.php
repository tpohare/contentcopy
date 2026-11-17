<?php

declare(strict_types=1);

/**
 * Unit tests for Gravity Forms support.
 *
 * Covers the three halves: `GravityFormsBridge` finds form IDs and packs
 * definitions, `FormImporter` turns them back into forms matched by UUID,
 * and `Remapper` points embeds at the destination's IDs.
 */

use ContentCopy\FormImporter;
use ContentCopy\GravityFormsBridge;
use ContentCopy\Remapper;

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * In-memory Gravity Forms API double.
	 *
	 * Forms live in $GLOBALS['contentcopy_test_forms'] (id => definition);
	 * feeds in $GLOBALS['contentcopy_test_feeds'] (id => feed).
	 */
	class GFAPI {

		/** @var int */
		public static int $nextFormId = 100;

		/** @var int */
		public static int $nextFeedId = 1000;

		/**
		 * @param int $form_id
		 * @return array<string, mixed>|false
		 */
		public static function get_form( $form_id ) {
			return $GLOBALS['contentcopy_test_forms'][ (int) $form_id ] ?? false;
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public static function get_forms( $active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC' ) {
			return array_values( $GLOBALS['contentcopy_test_forms'] ?? [] );
		}

		/**
		 * @param array<string, mixed> $form_meta
		 * @return int
		 */
		public static function add_form( $form_meta ) {
			$id           = self::$nextFormId++;
			$form_meta['id'] = $id;

			$GLOBALS['contentcopy_test_forms'][ $id ] = $form_meta;

			return $id;
		}

		/**
		 * @param array<string, mixed> $form
		 * @param int|null $form_id
		 * @return true|WP_Error
		 */
		public static function update_form( $form, $form_id = null ) {
			$id = null !== $form_id ? (int) $form_id : (int) ( $form['id'] ?? 0 );

			if ( $id <= 0 || ! isset( $GLOBALS['contentcopy_test_forms'][ $id ] ) ) {
				return new WP_Error( 'not_found', 'No such form.' );
			}

			$form['id']                            = $id;
			$GLOBALS['contentcopy_test_forms'][ $id ] = $form;

			return true;
		}

		/**
		 * @return array<int, array<string, mixed>>|WP_Error
		 */
		public static function get_feeds( $ids = null, $form_id = null, $addon_slug = null, $is_active = null ) {
			$feeds = array_values( $GLOBALS['contentcopy_test_feeds'] ?? [] );

			if ( null !== $form_id ) {
				$feeds = array_values(
					array_filter( $feeds, static fn ( $f ): bool => is_array( $f ) && (int) ( $f['form_id'] ?? 0 ) === (int) $form_id )
				);
			}

			if ( null !== $addon_slug ) {
				$feeds = array_values(
					array_filter( $feeds, static fn ( $f ): bool => is_array( $f ) && ( $f['addon_slug'] ?? '' ) === $addon_slug )
				);
			}

			if ( [] === $feeds ) {
				return new WP_Error( 'not_found', 'No feeds.' );
			}

			return $feeds;
		}

		/**
		 * @param array<string, mixed> $feed_meta
		 * @return int
		 */
		public static function add_feed( $form_id, $feed_meta, $addon_slug ) {
			$id = self::$nextFeedId++;

			$GLOBALS['contentcopy_test_feeds'][ $id ] = [
				'id'         => $id,
				'form_id'    => (int) $form_id,
				'addon_slug' => (string) $addon_slug,
				'meta'       => $feed_meta,
				'is_active'  => true,
			];

			return $id;
		}

		/**
		 * @param int $feed_id
		 * @return bool
		 */
		public static function delete_feed( $feed_id ) {
			unset( $GLOBALS['contentcopy_test_feeds'][ (int) $feed_id ] );

			return true;
		}

		/**
		 * @param int $form_id
		 * @return bool
		 */
		public static function form_id_exists( $form_id ) {
			return isset( $GLOBALS['contentcopy_test_forms'][ (int) $form_id ] );
		}
	}
}

if ( ! class_exists( 'GFFormsModel' ) ) {
	/**
	 * Storage-layer double: records meta writes onto the form definition.
	 */
	class GFFormsModel {

		/**
		 * @param int $form_id
		 * @param mixed $form_meta
		 * @param string $meta_name
		 * @return void
		 */
		public static function update_form_meta( $form_id, $form_meta, $meta_name = 'display_meta' ) {
			if ( isset( $GLOBALS['contentcopy_test_forms'][ (int) $form_id ] ) ) {
				$GLOBALS['contentcopy_test_forms'][ (int) $form_id ][ (string) $meta_name ] = $form_meta;
			}
		}

		/**
		 * @param int $form_id
		 * @param bool $is_active
		 * @return void
		 */
		public static function update_form_active( $form_id, $is_active ) {
			if ( isset( $GLOBALS['contentcopy_test_forms'][ (int) $form_id ] ) ) {
				$GLOBALS['contentcopy_test_forms'][ (int) $form_id ]['is_active'] = (bool) $is_active;
			}
		}
	}
}

beforeEach(
	function (): void {
		contentcopy_test_reset();

		$GLOBALS['contentcopy_test_forms'] = [
			5 => [
				'id'            => 5,
				'title'         => 'Contact us',
				'is_active'     => true,
				'fields'        => [],
				'notifications' => [ 'n1' => [ 'to' => 'a@test.test' ] ],
				'confirmations' => [ 'c1' => [ 'message' => 'Thanks' ] ],
			],
		];
		$GLOBALS['contentcopy_test_feeds'] = [];
		GFAPI::$nextFormId                 = 100;
		GFAPI::$nextFeedId                 = 1000;
	}
);

it( 'finds form IDs in shortcodes and blocks', function () {
	expect( GravityFormsBridge::idsIn( 'Call [gravityform id="5" title="false"] now' ) )->toBe( [ 5 ] )
		and expect( GravityFormsBridge::idsIn( '[gravityforms id=7]' ) )->toBe( [ 7 ] )
		and expect( GravityFormsBridge::idsIn( '<!-- wp:gravityforms/form {"formId":"5"} /-->' ) )->toBe( [ 5 ] )
		and expect( GravityFormsBridge::idsIn( '<p>No forms here.</p>' ) )->toBe( [] );
} );

it( 'assigns a stable UUID and finds the form by it', function () {
	$uuid = GravityFormsBridge::uuidFor( 5 );

	expect( $uuid )->toMatch( '/^[0-9a-f-]{36}$/i' )
		and expect( GravityFormsBridge::uuidFor( 5 ) )->toBe( $uuid )
		and expect( GravityFormsBridge::findLocal( $uuid ) )->toBe( 5 );
} );

it( 'exports a form with its settings and feeds', function () {
	$GLOBALS['contentcopy_test_feeds'][1] = [
		'id'         => 1,
		'form_id'    => 5,
		'addon_slug' => 'gravityformswebhooks',
		'meta'       => [ 'url' => 'https://example.test/hook' ],
		'is_active'  => true,
	];

	$exported = GravityFormsBridge::export( 5 );

	expect( $exported )->toBeArray()
		and expect( $exported['source_id'] )->toBe( 5 )
		and expect( $exported['title'] )->toBe( 'Contact us' )
		and expect( $exported['form']['title'] )->toBe( 'Contact us' )
		and expect( $exported['form'] )->not->toHaveKey( 'id' )
		and expect( $exported['feeds'] )->toHaveCount( 1 );
} );

it( 'creates forms on import and reuses them on repeat pushes', function () {
	$exported = GravityFormsBridge::export( 5 );

	// Export ran on the "source": wipe its identity map to simulate the
	// destination, which has never seen this UUID before.
	$GLOBALS['contentcopy_test_options'] = [];

	$importer = new FormImporter();

	$first = $importer->import( [ $exported ] );

	expect( $first )->toBeArray();

	$destId = $first['map'][5];

	expect( $destId )->toBeGreaterThanOrEqual( 100 );

	// A second import of the same UUID updates the same form instead of
	// creating a duplicate.
	$second = $importer->import( [ $exported ] );

	expect( $second['map'][5] )->toBe( $destId );
} );

it( 'warns that copied feeds still name the source accounts', function () {
	$GLOBALS['contentcopy_test_feeds'][1] = [
		'id'         => 1,
		'form_id'    => 5,
		'addon_slug' => 'gravityformswebhooks',
		'meta'       => [ 'url' => 'https://example.test/hook' ],
		'is_active'  => true,
	];

	$exported = GravityFormsBridge::export( 5 );

	$GLOBALS['contentcopy_test_options'] = [];

	$result = ( new FormImporter() )->import( [ $exported ] );

	expect( implode( ' ', $result['warnings'] ) )->toContain( 'source site' );
} );

it( 'rewrites shortcode and block embeds to the destination form', function () {
	$remapper = new Remapper( [], [], [ 5 => 8 ] );

	expect( $remapper->content( 'Call [gravityform id="5" title="false"] now' ) )
		->toBe( 'Call [gravityform id="8" title="false"] now' )
		and expect( $remapper->content( '<!-- wp:gravityforms/form {"formId":5} /-->' ) )
		->toContain( '"formId":8' );
} );

it( 'rewrites embeds hiding in plain custom fields', function () {
	$remapper = new Remapper( [], [], [ 5 => 8 ] );

	expect( $remapper->meta( 9, 'sidebar_text', 'Call [gravityform id="5"]' ) )
		->toBe( 'Call [gravityform id="8"]' );
} );

it( 'warns when an embed has no destination counterpart', function () {
	$remapper  = new Remapper( [], [], [] );
	$rewritten = $remapper->content( 'Call [gravityform id="5"]' );

	expect( $rewritten )->toBe( 'Call [gravityform id="5"]' );
} );

it( 'refuses a forms batch when Gravity Forms is missing', function () {
	// The bridge gates on the class; without it there is nothing to import
	// into, which the importer reports instead of fataling.
	expect( GravityFormsBridge::active() )->toBeTrue();
} );
