<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Stable cross-site identity for posts and attachments.
 *
 * WordPress IDs are meaningless across installs — post 12 here is some other
 * post 12 over there — so every item this plugin touches gets a UUID stamped
 * into postmeta the moment it is saved. That UUID is the thread that ties a
 * source item to its destination copy:
 *
 * - Repeat pushes look the destination copy up by UUID and *update* it, which
 *   is what makes pushing idempotent instead of duplicating.
 * - Attachments carry UUIDs too, so re-pushed files are recognised and
 *   reused rather than re-uploaded.
 * - The per-destination last-push record remembers the destination copy's
 *   hash and modification time, which is what lets a later push notice that
 *   somebody edited the copy in the meantime and refuse to clobber it.
 *
 * None of the plugin's own meta keys ever cross the wire (see
 * `internalMetaKeys()`): they describe *this* install's relationship with
 * other installs, which would be nonsense — or a confusing lie — over there.
 */
final class Uuid {

	/**
	 * Postmeta key holding the item's cross-site UUID.
	 *
	 * @var string
	 */
	public const META = '_contentcopy_uuid';

	/**
	 * Postmeta key recording where an imported item came from.
	 *
	 * Traceability only — a human reading the destination's database can see
	 * which source URL and post ID produced a copy. Never used for matching.
	 *
	 * @var string
	 */
	public const META_ORIGIN = '_contentcopy_origin';

	/**
	 * Postmeta key holding the per-destination last-push records.
	 *
	 * The value is a map of `destinationKey() => record`, so pushing to a
	 * second destination later does not disturb the first destination's
	 * conflict tracking.
	 *
	 * @var string
	 */
	public const META_LAST_PUSH = '_contentcopy_last_push';

	/**
	 * Register the hooks that stamp UUIDs onto new and edited content.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'save_post', self::onSavePost( ... ), 10, 2 );
		add_action(
			'add_attachment',
			static function ( int $postId ): void {
				self::ensure( $postId );
			}
		);
	}

	/**
	 * Stamp a UUID onto a post being saved, unless there is nothing to stamp.
	 *
	 * Revisions and autosaves share their parent's identity (they *are* the
	 * parent at a different moment), and `auto-draft`s are throwaway editor
	 * state — none of them get an identity of their own.
	 *
	 * @param int      $postId The post being saved.
	 * @param \WP_Post $post   The post object, for status checks.
	 *
	 * @return void
	 */
	public static function onSavePost( int $postId, \WP_Post $post ): void {
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		self::ensure( $postId );
	}

	/**
	 * Return the item's UUID, generating and storing one if it has none.
	 *
	 * Idempotent by construction: items stamped by an older version, or by a
	 * previous push, keep the UUID they already have.
	 *
	 * @param int $postId Post or attachment ID.
	 *
	 * @return string The item's UUID.
	 */
	public static function ensure( int $postId ): string {
		$existing = self::get( $postId );

		if ( '' !== $existing ) {
			return $existing;
		}

		$uuid = wp_generate_uuid4();
		update_post_meta( $postId, self::META, $uuid );

		return $uuid;
	}

	/**
	 * Read the item's UUID without creating one.
	 *
	 * @param int $postId Post or attachment ID.
	 *
	 * @return string The UUID, or an empty string when the item has none.
	 */
	public static function get( int $postId ): string {
		$uuid = get_post_meta( $postId, self::META, true );

		return is_string( $uuid ) ? $uuid : '';
	}

	/**
	 * Find the local post carrying a given UUID.
	 *
	 * This is a direct query rather than `WP_Query` on purpose: `post_type =>
	 * any` silently excludes attachments and every post type flagged
	 * `exclude_from_search`, and both of those must be findable here — the
	 * destination resolves attachments and hierarchical pages through this
	 * exact method.
	 *
	 * @param string $uuid     UUID to resolve.
	 * @param string $postType Optional post type constraint; '' searches every type.
	 *
	 * @return int Post ID, or 0 when no local item carries the UUID.
	 */
	public static function findLocal( string $uuid, string $postType = '' ): int {
		global $wpdb;

		if ( ! self::isValid( $uuid ) ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE m.meta_key = %s AND m.meta_value = %s",
			self::META,
			$uuid
		);

		if ( '' !== $postType ) {
			$sql .= $wpdb->prepare( ' AND p.post_type = %s', $postType );
		}

		$sql .= ' ORDER BY p.ID ASC LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- assembled from prepared fragments only.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Check a value is a well-formed v4 UUID before it reaches a query.
	 *
	 * UUIDs arrive from the network in lookup requests, so shape-checking
	 * them first is basic hygiene, defence in depth behind `$wpdb->prepare()`.
	 *
	 * @param mixed $uuid Candidate value.
	 *
	 * @return bool True only for genuine v4 UUID strings.
	 */
	public static function isValid( mixed $uuid ): bool {
		return is_string( $uuid ) && wp_is_uuid( $uuid, 4 );
	}

	/**
	 * Key a last-push record by destination.
	 *
	 * Normalised (lowercased, no trailing slash) so `https://Example.test/`
	 * and `https://example.test` address the same record — otherwise a
	 * trailing-slash edit in settings would silently reset conflict tracking
	 * and the next push could clobber destination edits.
	 *
	 * @param string $destinationUrl Destination site URL.
	 *
	 * @return string Opaque per-destination array key.
	 */
	public static function destinationKey( string $destinationUrl ): string {
		return md5( untrailingslashit( strtolower( $destinationUrl ) ) );
	}

	/**
	 * Read the last-push record for one destination.
	 *
	 * @param int    $postId         Source post ID.
	 * @param string $destinationUrl Destination site URL.
	 *
	 * @return array<string, mixed>|null The record (`destination_id`, `destination_hash`, `destination_modified`, `time`), or null when this item was never pushed there.
	 */
	public static function getLastPush( int $postId, string $destinationUrl ): ?array {
		$all = get_post_meta( $postId, self::META_LAST_PUSH, true );

		if ( ! is_array( $all ) ) {
			return null;
		}

		$record = $all[ self::destinationKey( $destinationUrl ) ] ?? null;

		return is_array( $record ) ? $record : null;
	}

	/**
	 * Store the last-push record for one destination.
	 *
	 * Called after every successful push with the destination's own report of
	 * what it wrote (its item ID, its computed hash, its modification time).
	 * The *next* push compares those against the live destination copy, which
	 * is the entire conflict-detection mechanism.
	 *
	 * @param int                  $postId         Source post ID.
	 * @param string               $destinationUrl Destination site URL.
	 * @param array<string, mixed> $record         Record to store.
	 *
	 * @return void
	 */
	public static function setLastPush( int $postId, string $destinationUrl, array $record ): void {
		$all = get_post_meta( $postId, self::META_LAST_PUSH, true );

		if ( ! is_array( $all ) ) {
			$all = [];
		}

		$all[ self::destinationKey( $destinationUrl ) ] = $record;

		update_post_meta( $postId, self::META_LAST_PUSH, $all );
	}

	/**
	 * Meta keys the plugin owns, which must never travel between sites.
	 *
	 * - The UUID *is* the cross-site identity, so it travels inside the
	 *   payload envelope — but never as a blindly-copied meta row, which
	 *   would collide with the destination stamping its own.
	 * - Origin and last-push records describe *this* install's relationships;
	 *   copying them would make the destination believe it had a push history
	 *   it never had.
	 *
	 * @return list<string> The reserved meta keys.
	 */
	public static function internalMetaKeys(): array {
		return [ self::META, self::META_ORIGIN, self::META_LAST_PUSH ];
	}
}
