<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Optional Gravity Forms awareness: finds the forms a page embeds, and packs
 * them for the wire.
 *
 * A form is not content in the WordPress sense — it lives in its own tables,
 * not in `wp_posts` — so pushing a page that embeds one would otherwise land
 * a shortcode pointing at form 5 on a site where form 5 is something else
 * entirely, or nothing at all. This class is the source half of fixing that:
 * it spots the references, gives each form a cross-site identity, and exports
 * the definition along with the settings that make it behave the way it does
 * (notifications, confirmations and add-on feeds).
 *
 * Identity deserves an explanation. Forms have no UUID of their own, and this
 * plugin deliberately does not write one into the form itself: round-tripping
 * a form definition through `update_form()` just to stamp an identifier would
 * risk corrupting a working form on the *source* site. The mapping lives in a
 * site option instead, which is written often and read cheaply and cannot
 * damage anything if it is lost.
 *
 * Every method degrades to a no-op when Gravity Forms is not active, so the
 * plugin never requires it.
 */
final class GravityFormsBridge {

	/**
	 * Option holding this site's form ID => UUID map.
	 *
	 * @var string
	 */
	public const OPTION_UUIDS = 'contentcopy_form_uuids';

	/**
	 * Whether Gravity Forms is available right now.
	 *
	 * The single gate every other method checks first: no Gravity Forms, no
	 * forms to find, and no fatal from calling its API.
	 *
	 * @return bool True when `GFAPI` exists.
	 */
	public static function active(): bool {
		return class_exists( 'GFAPI' );
	}

	/**
	 * Find every form ID referenced in a piece of text.
	 *
	 * Both ways of embedding a form are recognised, because sites usually
	 * contain both: the shortcode (in either spelling, with attributes in any
	 * order) and the block, whose `formId` attribute may be a string or a
	 * number depending on which version wrote it.
	 *
	 * Text is scanned rather than parsed, so this also catches forms embedded
	 * inside custom fields, widget content and page builder payloads — which
	 * is exactly where the shortcodes that break after a copy tend to hide.
	 *
	 * @param string $text Content, or any string that might embed a form.
	 *
	 * @return list<int> Referenced form IDs, in no particular order, deduplicated.
	 */
	public static function idsIn( string $text ): array {
		if ( '' === $text ) {
			return [];
		}

		$found = [];

		// [gravityform id="5" …] and [gravityforms id=5 …], attributes in any
		// order, quoted or not.
		if ( preg_match_all( '/\[gravityforms?\b[^\]]*\bid=["\']?(\d+)["\']?/i', $text, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$found[] = (int) $id;
			}
		}

		// <!-- wp:gravityforms/form {"formId":"5", …} -->
		if ( preg_match_all( '/"formId"\s*:\s*["\']?(\d+)["\']?/', $text, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$found[] = (int) $id;
			}
		}

		return array_values( array_unique( array_filter( $found, static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * This site's cross-site identity for one form, assigning one if needed.
	 *
	 * @param int $formId Local form ID.
	 *
	 * @return string The form's UUID, or '' when one could not be assigned.
	 */
	public static function uuidFor( int $formId ): string {
		if ( $formId <= 0 ) {
			return '';
		}

		$map = self::uuidMap();

		if ( isset( $map[ $formId ] ) && Uuid::isValid( $map[ $formId ] ) ) {
			return $map[ $formId ];
		}

		$uuid           = wp_generate_uuid4();
		$map[ $formId ] = $uuid;

		update_option( self::OPTION_UUIDS, $map, false );

		return $uuid;
	}

	/**
	 * Find the local form carrying a UUID, if any.
	 *
	 * The map is authoritative but not trusted blindly: a form deleted since
	 * the last push leaves a stale entry behind, so the ID is confirmed
	 * against Gravity Forms before it is handed back.
	 *
	 * @param string $uuid Cross-site form identity.
	 *
	 * @return int Local form ID, or 0 when this site has no copy.
	 */
	public static function findLocal( string $uuid ): int {
		if ( ! Uuid::isValid( $uuid ) || ! self::active() ) {
			return 0;
		}

		$formId = (int) array_search( $uuid, self::uuidMap(), true );

		if ( $formId <= 0 ) {
			return 0;
		}

		$form = \GFAPI::get_form( $formId );

		if ( ! is_array( $form ) ) {
			self::forget( $formId );

			return 0;
		}

		return $formId;
	}

	/**
	 * Record that a local form answers to a UUID.
	 *
	 * Used on the destination after a form is created, so the next push finds
	 * it instead of creating a second copy.
	 *
	 * @param int    $formId Local form ID.
	 * @param string $uuid   Cross-site form identity.
	 *
	 * @return void
	 */
	public static function remember( int $formId, string $uuid ): void {
		if ( $formId <= 0 || ! Uuid::isValid( $uuid ) ) {
			return;
		}

		$map = self::uuidMap();

		// One UUID identifies one form per site: drop any other form claiming
		// this identity before recording the new one.
		foreach ( array_keys( $map, $uuid, true ) as $stale ) {
			unset( $map[ $stale ] );
		}

		$map[ $formId ] = $uuid;

		update_option( self::OPTION_UUIDS, $map, false );
	}

	/**
	 * Export one form and everything that makes it behave as it does.
	 *
	 * What travels: the form definition (fields, labels, validation,
	 * conditional logic, form settings), its notifications, its confirmations
	 * and its add-on feeds. What does not: entries. Entries are submitted
	 * data belonging to the site that collected them, and copying them into
	 * another site's records would be a privacy incident, not a feature.
	 *
	 * @param int $formId Local form ID.
	 *
	 * @return array<string, mixed>|null The packed form, or null when it does not exist.
	 */
	public static function export( int $formId ): ?array {
		if ( ! self::active() ) {
			return null;
		}

		$form = \GFAPI::get_form( $formId );

		if ( ! is_array( $form ) ) {
			return null;
		}

		$uuid = self::uuidFor( $formId );

		if ( '' === $uuid ) {
			return null;
		}

		// The destination's own ID must win on update, and must be assigned
		// on create, so the source's ID is never part of the definition.
		unset( $form['id'] );

		return [
			'uuid'      => $uuid,
			'source_id' => $formId,
			'title'     => is_string( $form['title'] ?? null ) ? $form['title'] : '',
			'is_active' => ! isset( $form['is_active'] ) || (bool) $form['is_active'],
			'form'      => $form,
			'feeds'     => self::feedsFor( $formId ),
		];
	}

	/**
	 * Export the add-on feeds attached to a form.
	 *
	 * Feeds are what make a form do something beyond storing an entry: send
	 * to a mailing list, raise a payment, post to a webhook. They are part of
	 * "the form's settings" in every sense that matters, so they travel — but
	 * they frequently reference credentials, list IDs and endpoints that only
	 * exist on the source's connected accounts, which is why the destination
	 * warns about every one it writes.
	 *
	 * @param int $formId Local form ID.
	 *
	 * @return list<array{addon_slug: string, meta: array<string, mixed>, is_active: bool}>
	 */
	private static function feedsFor( int $formId ): array {
		/**
		 * Filter whether add-on feeds travel with a form.
		 *
		 * @param bool $copy   Whether to copy feeds.
		 * @param int  $formId Form being exported.
		 */
		if ( ! apply_filters( 'contentcopy_copy_form_feeds', true, $formId ) ) {
			return [];
		}

		$feeds = \GFAPI::get_feeds( null, $formId );

		// Gravity Forms answers "no feeds" with a WP_Error rather than an
		// empty array, which is a perfectly ordinary outcome here.
		if ( ! is_array( $feeds ) ) {
			return [];
		}

		$out = [];

		foreach ( $feeds as $feed ) {
			if ( ! is_array( $feed ) || ! is_string( $feed['addon_slug'] ?? null ) ) {
				continue;
			}

			$out[] = [
				'addon_slug' => $feed['addon_slug'],
				'meta'       => is_array( $feed['meta'] ?? null ) ? $feed['meta'] : [],
				'is_active'  => ! isset( $feed['is_active'] ) || (bool) $feed['is_active'],
			];
		}

		return $out;
	}

	/**
	 * Drop a form from the identity map.
	 *
	 * @param int $formId Local form ID.
	 *
	 * @return void
	 */
	private static function forget( int $formId ): void {
		$map = self::uuidMap();

		if ( ! isset( $map[ $formId ] ) ) {
			return;
		}

		unset( $map[ $formId ] );

		update_option( self::OPTION_UUIDS, $map, false );
	}

	/**
	 * This site's form ID => UUID map.
	 *
	 * @return array<int, string> Local form ID to cross-site identity.
	 */
	private static function uuidMap(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_UUIDS, [] ) : [];

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$map = [];

		foreach ( $stored as $formId => $uuid ) {
			if ( is_numeric( $formId ) && is_string( $uuid ) ) {
				$map[ (int) $formId ] = $uuid;
			}
		}

		return $map;
	}
}
