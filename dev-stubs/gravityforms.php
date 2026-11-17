<?php
/**
 * Minimal Gravity Forms signatures for static analysis only.
 *
 * This file is never loaded at runtime. It exists so PHPStan knows the API
 * `GravityFormsBridge` and `FormImporter` call when Gravity Forms is active.
 * Only the handful of methods this plugin uses are declared.
 *
 * @package ContentCopy
 */

declare(strict_types=1);

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * Gravity Forms' public API.
	 */
	class GFAPI {

		/**
		 * @param int $form_id Form ID.
		 * @return array<string, mixed>|false The form meta, or false when it does not exist.
		 */
		public static function get_form( $form_id ) {
			return false;
		}

		/**
		 * @param bool|null   $active      Whether to return active forms; null for both.
		 * @param bool|null   $trash       Whether to return trashed forms; null for both.
		 * @param string      $sort_column Column to sort by.
		 * @param string      $sort_dir    Sort direction.
		 * @return array<int, array<string, mixed>> Form metas.
		 */
		public static function get_forms( $active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC' ) {
			return [];
		}

		/**
		 * @param array<string, mixed> $form_meta Form definition.
		 * @return int|WP_Error New form ID, or why it could not be created.
		 */
		public static function add_form( $form_meta ) {
			return 0;
		}

		/**
		 * @param array<string, mixed> $form    Form definition.
		 * @param int|null             $form_id Form to update; taken from the definition when null.
		 * @return true|WP_Error
		 */
		public static function update_form( $form, $form_id = null ) {
			return true;
		}

		/**
		 * @param array<int>|null $ids        Feed IDs.
		 * @param int|null        $form_id    Form the feeds belong to.
		 * @param string|null     $addon_slug Add-on slug.
		 * @param bool|null       $is_active  Whether to limit to active feeds.
		 * @return array<int, array<string, mixed>>|WP_Error Feeds, or an error when none match.
		 */
		public static function get_feeds( $ids = null, $form_id = null, $addon_slug = null, $is_active = null ) {
			return [];
		}

		/**
		 * @param int                  $form_id    Form the feed belongs to.
		 * @param array<string, mixed> $feed_meta  Feed settings.
		 * @param string               $addon_slug Add-on slug.
		 * @return int|WP_Error New feed ID, or why it could not be created.
		 */
		public static function add_feed( $form_id, $feed_meta, $addon_slug ) {
			return 0;
		}

		/**
		 * @param int $feed_id Feed to delete.
		 * @return bool|WP_Error
		 */
		public static function delete_feed( $feed_id ) {
			return true;
		}

		/**
		 * @param int $form_id Form ID.
		 * @return bool Whether the form exists.
		 */
		public static function form_id_exists( $form_id ) {
			return false;
		}
	}
}

if ( ! class_exists( 'GFFormsModel' ) ) {
	/**
	 * Gravity Forms' storage layer. Only the bits with no API equivalent.
	 */
	class GFFormsModel {

		/**
		 * @param int    $form_id   Form ID.
		 * @param mixed  $form_meta Value to store.
		 * @param string $meta_name Column to store it in (`notifications`, `confirmations`).
		 * @return void
		 */
		public static function update_form_meta( $form_id, $form_meta, $meta_name = 'display_meta' ) {}

		/**
		 * @param int  $form_id   Form ID.
		 * @param bool $is_active Whether the form accepts entries.
		 * @return void
		 */
		public static function update_form_active( $form_id, $is_active ) {}
	}
}
