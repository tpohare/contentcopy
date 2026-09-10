<?php

declare(strict_types=1);

namespace ContentCopy;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The push payload as a dedicated resource object.
 *
 * Modelled on Laravel's API resources: `PushPayload::fromPost()` translates
 * one source post (plus its remapper) into the exact shape the destination's
 * `remote/receive` route expects, and `toArray()` serialises it for the wire.
 *
 * Keeping this translation in one place means the pusher worries about
 * *transport* (media first, conflict checks, retries) while this class worries
 * about *representation* (which fields travel, and in what shape). If the
 * destination contract ever changes, this is the only file that needs to
 * change with it.
 *
 * A note on fidelity: every custom field travels verbatim, already remapped
 * by the given `Remapper`. The destination trusts this payload because it
 * arrived over an authenticated connection from a user allowed to publish —
 * the same trust WordPress extends to that user editing locally.
 */
final readonly class PushPayload {

	/**
	 * Assemble a payload. Prefer `fromPost()`; the constructor is public so
	 * tests (and future callers) can build payloads by hand.
	 *
	 * @param string                                                        $uuid          Cross-site identity for the item.
	 * @param string                                                        $postType      Source post type slug.
	 * @param string                                                        $title         Raw post title.
	 * @param string                                                        $content       Remapped post content (destination URLs and IDs).
	 * @param string                                                        $excerpt       Raw post excerpt.
	 * @param string                                                        $slug          Post slug (`post_name`).
	 * @param string                                                        $status        Source post status.
	 * @param string                                                        $dateGmt       GMT creation date, `Y-m-d H:i:s`.
	 * @param int                                                           $menuOrder     Menu order for hierarchical types.
	 * @param string                                                        $commentStatus `open` or `closed`.
	 * @param string                                                        $pingStatus    `open` or `closed`.
	 * @param bool                                                          $sticky        Whether the post is stuck to the front page.
	 * @param string                                                        $parentUuid    UUID of the parent item, or '' for none.
	 * @param array{email: string, login: string, name: string}             $author        Source author identifiers for destination matching.
	 * @param array<string, list<array{slug: string, name: string, parent_slug: string}>> $terms Terms grouped by taxonomy, parents listed before children.
	 * @param array<string, list<mixed>>                                    $meta          Remapped custom fields; each key maps to its list of values (multi-value meta survives the trip).
	 * @param string                                                        $origin        Traceability URL pointing back at the source item.
	 */
	public function __construct(
		public string $uuid,
		public string $postType,
		public string $title,
		public string $content,
		public string $excerpt,
		public string $slug,
		public string $status,
		public string $dateGmt,
		public int $menuOrder,
		public string $commentStatus,
		public string $pingStatus,
		public bool $sticky,
		public string $parentUuid,
		public array $author,
		public array $terms,
		public array $meta,
		public string $origin,
	) {}

	/**
	 * Translate a source post into its push payload.
	 *
	 * Content and custom fields are remapped through `$remapper` first, so by
	 * the time this returns, no source URL or source ID remains — the
	 * destination can write the payload without knowing anything about where
	 * it came from.
	 *
	 * @param \WP_Post $post     The source post, already validated as pushable.
	 * @param Remapper $remapper Remapper built from the job's synced media and post references.
	 *
	 * @return self The payload, ready for `toArray()` and the wire.
	 */
	public static function fromPost( \WP_Post $post, Remapper $remapper ): self {
		return new self(
			uuid: Uuid::ensure( $post->ID ),
			postType: $post->post_type,
			title: (string) $post->post_title,
			content: $remapper->content( (string) $post->post_content ),
			excerpt: (string) $post->post_excerpt,
			slug: (string) $post->post_name,
			status: (string) $post->post_status,
			dateGmt: (string) $post->post_date_gmt,
			menuOrder: (int) $post->menu_order,
			commentStatus: (string) $post->comment_status,
			pingStatus: (string) $post->ping_status,
			sticky: 'post' === $post->post_type && function_exists( 'is_sticky' ) && is_sticky( $post->ID ),
			parentUuid: $post->post_parent > 0 ? Uuid::ensure( (int) $post->post_parent ) : '',
			author: self::authorDescriptor( $post ),
			terms: self::termsDescriptor( $post ),
			meta: self::metaDescriptor( $post, $remapper ),
			origin: function_exists( 'home_url' ) ? home_url( '/?p=' . $post->ID ) : '',
		);
	}

	/**
	 * Serialise for the `remote/receive` JSON body.
	 *
	 * @return array<string, mixed> The exact array the destination validates.
	 */
	public function toArray(): array {
		return [
			'uuid'           => $this->uuid,
			'post_type'      => $this->postType,
			'title'          => $this->title,
			'content'        => $this->content,
			'excerpt'        => $this->excerpt,
			'slug'           => $this->slug,
			'status'         => $this->status,
			'date_gmt'       => $this->dateGmt,
			'menu_order'     => $this->menuOrder,
			'comment_status' => $this->commentStatus,
			'ping_status'    => $this->pingStatus,
			'sticky'         => $this->sticky,
			'parent_uuid'    => $this->parentUuid,
			'author'         => $this->author,
			'terms'          => $this->terms,
			'meta'           => $this->meta,
			'origin'         => $this->origin,
		];
	}

	/**
	 * Identify the source author three ways so the destination can match them.
	 *
	 * Email is the reliable key (logins differ between installs); the display
	 * name is informational only and never used for matching.
	 *
	 * @param \WP_Post $post Source post.
	 *
	 * @return array{email: string, login: string, name: string}
	 */
	private static function authorDescriptor( \WP_Post $post ): array {
		if ( ! function_exists( 'get_user_by' ) ) {
			return [ 'email' => '', 'login' => '', 'name' => '' ];
		}

		$user = get_user_by( 'id', (int) $post->post_author );

		if ( ! $user instanceof \WP_User ) {
			return [ 'email' => '', 'login' => '', 'name' => '' ];
		}

		return [
			'email' => (string) $user->user_email,
			'login' => (string) $user->user_login,
			'name'  => (string) $user->display_name,
		];
	}

	/**
	 * Group the post's terms by taxonomy, parents before children.
	 *
	 * The destination recreates missing terms parent-first, so ordering here
	 * is load-bearing: a child arriving before its parent would be created at
	 * the top level and then "found" there on every repeat push.
	 *
	 * @param \WP_Post $post Source post.
	 *
	 * @return array<string, list<array{slug: string, name: string, parent_slug: string}>>
	 */
	private static function termsDescriptor( \WP_Post $post ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			return [];
		}

		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );

		if ( ! is_array( $taxonomies ) ) {
			return [];
		}

		$out = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || ! function_exists( 'wp_get_post_terms' ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $post->ID, $taxonomy );

			if ( $terms instanceof WP_Error || ! is_array( $terms ) ) {
				continue;
			}

			// Top-level terms sort first so the destination can build the
			// chain downward. Deeper order beyond that does not matter: each
			// term carries its own parent slug.
			usort(
				$terms,
				static fn ( $a, $b ): int => ( $a instanceof \WP_Term && 0 === $a->parent ? 0 : 1 ) <=> ( $b instanceof \WP_Term && 0 === $b->parent ? 0 : 1 )
			);

			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$parentSlug = '';

				if ( $term->parent > 0 && function_exists( 'get_term' ) ) {
					$parent = get_term( $term->parent, $taxonomy );

					if ( $parent instanceof \WP_Term ) {
						$parentSlug = $parent->slug;
					}
				}

				$out[ $taxonomy ][] = [
					'slug'        => $term->slug,
					'name'        => $term->name,
					'parent_slug' => $parentSlug,
				];
			}
		}

		return $out;
	}

	/**
	 * Collect every custom field value, already remapped for the destination.
	 *
	 * Multi-value rows are preserved as lists (the destination replays each
	 * one with `add_post_meta`), and values the remapper flags as unwritable
	 * — an unmatchable featured image — are dropped with a logged warning
	 * rather than travelling as lies.
	 *
	 * @param \WP_Post $post     Source post.
	 * @param Remapper $remapper Remapper built from the job's synced media and post references.
	 *
	 * @return array<string, list<mixed>>
	 */
	private static function metaDescriptor( \WP_Post $post, Remapper $remapper ): array {
		$meta = function_exists( 'get_post_meta' ) ? get_post_meta( $post->ID ) : [];

		if ( ! is_array( $meta ) ) {
			return [];
		}

		$internal = Uuid::internalMetaKeys();
		$out      = [];

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;

			// Our own bookkeeping never crosses the wire, and edit locks are
			// per-install editor state that would be nonsense over there.
			if ( in_array( $key, $internal, true ) || in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
				continue;
			}

			foreach ( (array) $values as $raw ) {
				$remapped = $remapper->meta( $post->ID, $key, function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw );

				if ( null === $remapped ) {
					continue;
				}

				$out[ $key ][] = $remapped;
			}
		}

		return $out;
	}
}
