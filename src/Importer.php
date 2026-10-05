<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Destination-side post handling: turns a validated payload into a post.
 *
 * The payload arrives fully remapped — every URL and ID already points at
 * this site — so this class only validates, writes, and reports. It never
 * phones home: no callbacks to the source, no fetching, no guessing. That
 * one-way flow is what keeps the destination safe to expose; the worst a
 * malicious payload can do is create content the authenticated user could
 * have created by hand anyway.
 *
 * Writing order matters and is load-bearing:
 *
 * 1. The post row goes in as a **draft first** (creates) so a failure in
 *    steps 2–4 can never leave a half-imported post published. Promotion to
 *    the intended status is the last step before reporting.
 * 2. Terms are ensured parent-first, then assigned.
 * 3. All non-internal meta is wiped and replayed from the payload, so fields
 *    deleted on the source disappear on the destination too.
 * 4. The UUID and origin are stamped, and the hash of what was *actually
 *    written* is reported back — the source records that fingerprint for the
 *    next push's conflict check.
 */
final class Importer {

	/**
	 * @var Settings Destination configuration (fallback author lives here).
	 */
	private readonly Settings $settings;

	/**
	 * @var LoggerInterface Import narration; silent when omitted.
	 */
	private LoggerInterface $logger;

	/**
	 * @param Settings          $settings Destination configuration.
	 * @param LoggerInterface|null $logger Where to narrate; silent when omitted.
	 */
	public function __construct(
		Settings $settings,
		?LoggerInterface $logger = null,
	) {
		$this->settings = $settings;
		$this->logger   = $logger ?? new NullLogger();
	}

	/**
	 * Import one payload as a new post or an in-place update.
	 *
	 * Updates preserve the destination post ID (and therefore its URL and any
	 * local references to it); creates get a fresh ID. Either way the
	 * returned hash describes what is now in the database, closing the loop
	 * with the source's conflict tracking.
	 *
	 * @param array<string, mixed> $payload Decoded `remote/receive` payload (see `PushPayload::toArray()`).
	 * @param int                  $userId  Authenticated destination user, for capability checks and author fallback.
	 *
	 * @return array<string, mixed>|WP_Error Report (`id`, `link`, `outcome`, `hash`, `modified`, `warnings`), or why the import was refused.
	 */
	public function import( array $payload, int $userId ): array|WP_Error {
		$uuid = is_string( $payload['uuid'] ?? null ) ? $payload['uuid'] : '';

		if ( ! Uuid::isValid( $uuid ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'The payload is missing a valid UUID.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$postType = is_string( $payload['post_type'] ?? null ) ? $payload['post_type'] : '';
		$pto      = '' !== $postType ? get_post_type_object( $postType ) : null;

		// Unknown post types are refused, never auto-registered: creating a
		// type the destination never asked for would scatter content into a
		// void, while refusing names exactly what to register.
		if ( null === $pto ) {
			return new WP_Error(
				'contentcopy_unknown_post_type',
				sprintf(
					/* translators: %s: post type name. */
					__( 'The post type "%s" is not registered on the destination site. Register it there first, then push again.', 'contentcopy' ),
					$postType
				),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'contentcopy_forbidden', __( 'Your destination user cannot create content of this type.', 'contentcopy' ), [ 'status' => 403 ] );
		}

		$existingId = Uuid::findLocal( $uuid, $postType );

		if ( $existingId > 0 && ! current_user_can( 'edit_post', $existingId ) ) {
			return new WP_Error( 'contentcopy_forbidden', __( 'Your destination user cannot edit the existing copy of this item.', 'contentcopy' ), [ 'status' => 403 ] );
		}

		$warnings = [];

		$status = $this->resolveStatus( is_string( $payload['status'] ?? null ) ? $payload['status'] : 'draft', $warnings );
		$author = $this->resolveAuthor( $payload['author'] ?? null, $userId, $warnings );
		$parent = $this->resolveParent( $payload, $warnings );

		$title   = is_string( $payload['title'] ?? null ) ? $payload['title'] : '';
		$content = is_string( $payload['content'] ?? null ) ? $payload['content'] : '';
		$excerpt = is_string( $payload['excerpt'] ?? null ) ? $payload['excerpt'] : '';

		$postarr = [
			'post_title'     => $title,
			'post_content'   => $content,
			'post_excerpt'   => $excerpt,
			'post_name'      => is_string( $payload['slug'] ?? null ) ? sanitize_title( $payload['slug'] ) : '',
			'post_type'      => $postType,
			'post_status'    => $status,
			'post_parent'    => $parent,
			'post_author'    => $author,
			'menu_order'     => isset( $payload['menu_order'] ) ? (int) $payload['menu_order'] : 0,
			'comment_status' => 'open' === ( $payload['comment_status'] ?? '' ) ? 'open' : 'closed',
			'ping_status'    => 'open' === ( $payload['ping_status'] ?? '' ) ? 'open' : 'closed',
		];

		// The source date travels as GMT and is converted to local on write,
		// mirroring what WordPress itself does on save. Garbage dates are
		// ignored rather than fatal — "now" is always a safe answer.
		$dateGmt = is_string( $payload['date_gmt'] ?? null ) ? $payload['date_gmt'] : '';

		if ( '' !== $dateGmt && false !== strtotime( $dateGmt ) ) {
			$postarr['post_date_gmt'] = get_gmt_from_date( $dateGmt, 'Y-m-d H:i:s' ) ?: $dateGmt;
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'], 'Y-m-d H:i:s' );
		}

		if ( $existingId > 0 ) {
			$postarr['ID'] = $existingId;
			$postId        = wp_update_post( $postarr, true );

			if ( $postId instanceof WP_Error ) {
				$this->logger->error( 'Updating {type} {id} failed: {reason}', [ 'type' => $postType, 'id' => $existingId, 'reason' => $postId->get_error_message() ] );

				return $postId;
			}

			$outcome = PushStatus::Updated;
		} else {
			// Write as a draft first so a failure below never leaves a
			// half-imported post published.
			$postarr['post_status'] = 'draft';
			$postId                 = wp_insert_post( $postarr, true );

			if ( $postId instanceof WP_Error ) {
				$this->logger->error( 'Creating {type} failed: {reason}', [ 'type' => $postType, 'reason' => $postId->get_error_message() ] );

				return $postId;
			}

			if ( 'draft' !== $status ) {
				$promoted = wp_update_post(
					[
						'ID'          => $postId,
						'post_status' => $status,
					],
					true
				);

				if ( $promoted instanceof WP_Error ) {
					$warnings[] = __( 'The item was imported as a draft because the destination could not publish it.', 'contentcopy' );
					$this->logger->warning( 'Promoting {id} to {status} failed: {reason}', [ 'id' => $postId, 'status' => $status, 'reason' => $promoted->get_error_message() ] );
				}
			}

			$outcome = PushStatus::Created;
		}

		$postId = (int) $postId;

		update_post_meta( $postId, Uuid::META, $uuid );

		if ( is_string( $payload['origin'] ?? null ) && '' !== $payload['origin'] ) {
			update_post_meta( $postId, Uuid::META_ORIGIN, sanitize_text_field( $payload['origin'] ) );
		}

		$warnings = [ ...$warnings, ...$this->applyTerms( $postId, $postType, $payload['terms'] ?? [] ) ];
		$warnings = [ ...$warnings, ...$this->applyMeta( $postId, $payload['meta'] ?? [] ) ];
		$this->applySticky( $postId, $postType, $payload );

		$post = get_post( $postId );

		if ( null === $post ) {
			return new WP_Error( 'contentcopy_import_failed', __( 'The item was written but could not be read back.', 'contentcopy' ), [ 'status' => 500 ] );
		}

		// The hash is computed from what is actually in the database — not
		// from the payload — so filters or sanitisation on write cannot
		// desynchronise conflict tracking.
		$hash     = ContentHash::ofPost( $post );
		$modified = $post->post_modified_gmt;

		$this->logger->info(
			'{outcome} {type} {id} ("{title}").',
			[
				'outcome' => PushStatus::Created === $outcome ? 'Created' : 'Updated',
				'type'    => $postType,
				'id'      => $postId,
				'title'   => $post->post_title,
			]
		);

		return [
			'id'       => $postId,
			'link'     => get_permalink( $postId ),
			'outcome'  => $outcome->value,
			'message'  => $outcome->label(),
			'hash'     => $hash,
			'modified' => $modified,
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Settle the incoming status against what the destination allows.
	 *
	 * Unknown or unregistered statuses degrade to draft with a warning rather
	 * than failing the push: the content is safe, and the editor can set the
	 * intended status by hand once the type is registered properly.
	 *
	 * @param string       $status   Requested status.
	 * @param list<string> $warnings Warning sink.
	 *
	 * @return string A status safe to write.
	 */
	private function resolveStatus( string $status, array &$warnings ): string {
		$allowed = [ 'publish', 'draft', 'pending', 'private', 'future' ];

		if ( in_array( $status, $allowed, true ) && null !== get_post_status_object( $status ) ) {
			return $status;
		}

		$warnings[] = sprintf(
			/* translators: %s: post status. */
			__( 'Status "%s" is not available on the destination, so the item was saved as a draft.', 'contentcopy' ),
			$status
		);

		return 'draft';
	}

	/**
	 * Match the source author to a destination user.
	 *
	 * Email first (logins differ between installs; emails identify the human),
	 * then login, then the configured fallback author, then — openly, with a
	 * warning — the authenticated user. The chain guarantees an author is
	 * always found while preferring the most correct one.
	 *
	 * @param mixed        $author   Author descriptor from the source (`email`, `login`, `name`).
	 * @param int          $userId   Authenticated destination user ID.
	 * @param list<string> $warnings Warning sink.
	 *
	 * @return int Destination user ID to assign.
	 */
	private function resolveAuthor( mixed $author, int $userId, array &$warnings ): int {
		$candidate = 0;

		if ( is_array( $author ) ) {
			foreach ( [ 'email', 'login' ] as $key ) {
				if ( is_string( $author[ $key ] ?? null ) && '' !== $author[ $key ] ) {
					$found = 'email' === $key
						? get_user_by( 'email', sanitize_email( $author[ $key ] ) )
						: get_user_by( 'login', sanitize_user( $author[ $key ] ) );

					if ( $found instanceof \WP_User ) {
						$candidate = $found->ID;
						break;
					}
				}
			}
		}

		if ( 0 === $candidate ) {
			$fallback = $this->settings->fallbackAuthor;

			if ( '' !== $fallback ) {
				$found = is_email( $fallback )
					? get_user_by( 'email', $fallback )
					: get_user_by( 'login', $fallback );

				if ( $found instanceof \WP_User ) {
					$candidate = $found->ID;
				} else {
					$warnings[] = sprintf(
						/* translators: %s: configured fallback author. */
						__( 'The configured fallback author "%s" does not exist on the destination site.', 'contentcopy' ),
						$fallback
					);
				}
			}
		}

		if ( 0 === $candidate ) {
			$candidate   = $userId;
			$warnings[] = __( 'No matching author was found on the destination, so the item was assigned to the user the push authenticated as.', 'contentcopy' );
		}

		return $candidate;
	}

	/**
	 * Resolve the parent UUID to a local parent ID.
	 *
	 * A parent that has not been pushed yet resolves to top level *with a
	 * warning*, never silently: the editor pushes the parent and re-pushes,
	 * and the hierarchy heals itself.
	 *
	 * @param array<string, mixed> $payload  Receive payload.
	 * @param list<string>         $warnings Warning sink.
	 *
	 * @return int Destination parent ID, or 0 for top level.
	 */
	private function resolveParent( array $payload, array &$warnings ): int {
		$parentUuid = is_string( $payload['parent_uuid'] ?? null ) ? $payload['parent_uuid'] : '';

		if ( '' === $parentUuid ) {
			return 0;
		}

		$postType = is_string( $payload['post_type'] ?? null ) ? $payload['post_type'] : '';
		$found    = Uuid::findLocal( $parentUuid, $postType );

		if ( $found > 0 ) {
			return $found;
		}

		$warnings[] = __( 'The parent item has not been pushed to the destination yet, so this item was placed at the top level. Push the parent, then push this item again.', 'contentcopy' );

		return 0;
	}

	/**
	 * Assign terms, creating missing ones parent-first.
	 *
	 * Taxonomies unknown to this post type are skipped with a warning rather
	 * than failing the push — the content matters more than its labels, and
	 * the warning names exactly what to register.
	 *
	 * @param int    $postId   Destination post ID.
	 * @param string $postType Destination post type, for taxonomy validation.
	 * @param mixed  $terms    Raw terms payload (taxonomy => list of slug/name/parent_slug).
	 *
	 * @return list<string> Warnings raised while assigning.
	 */
	private function applyTerms( int $postId, string $postType, mixed $terms ): array {
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$warnings = [];

		foreach ( $terms as $taxonomy => $list ) {
			if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $postType, $taxonomy ) || ! is_array( $list ) ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy name. */
					__( 'Taxonomy "%s" is not registered for this content type on the destination, so its terms were skipped.', 'contentcopy' ),
					is_string( $taxonomy ) ? $taxonomy : get_debug_type( $taxonomy )
				);

				continue;
			}

			$ids = [];

			foreach ( $list as $term ) {
				if ( ! is_array( $term ) || ! is_string( $term['slug'] ?? null ) || '' === $term['slug'] ) {
					continue;
				}

				$id = $this->ensureTerm(
					$taxonomy,
					sanitize_title( $term['slug'] ),
					is_string( $term['name'] ?? null ) ? sanitize_text_field( $term['name'] ) : $term['slug'],
					is_string( $term['parent_slug'] ?? null ) ? sanitize_title( $term['parent_slug'] ) : '',
					$warnings
				);

				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			$result = wp_set_post_terms( $postId, $ids, $taxonomy );

			if ( $result instanceof WP_Error ) {
				$warnings[] = sprintf(
					/* translators: %s: taxonomy name. */
					__( 'Terms in "%s" could not be assigned.', 'contentcopy' ),
					$taxonomy
				);
			}
		}

		return $warnings;
	}

	/**
	 * Ensure one term exists, creating parents before children.
	 *
	 * The parent-first ordering mirrors the source's `termsDescriptor()`,
	 * which lists parents before children for exactly this reason: a child
	 * created before its parent would land at the top level and then be
	 * "found" there on every repeat push, permanently misfiled.
	 *
	 * @param string       $taxonomy   Taxonomy slug.
	 * @param string       $slug       Term slug.
	 * @param string       $name       Human name for newly created terms.
	 * @param string       $parentSlug Parent term slug, or '' for top level.
	 * @param list<string> $warnings   Warning sink.
	 *
	 * @return int Term ID, or 0 when creation failed.
	 */
	private function ensureTerm( string $taxonomy, string $slug, string $name, string $parentSlug, array &$warnings ): int {
		$parentId = 0;

		if ( '' !== $parentSlug ) {
			$parent = get_term_by( 'slug', $parentSlug, $taxonomy );

			if ( $parent instanceof \WP_Term ) {
				$parentId = $parent->term_id;
			} else {
				$created = wp_insert_term( ucwords( str_replace( [ '-', '_' ], ' ', $parentSlug ) ), $taxonomy, [ 'slug' => $parentSlug ] );

				if ( $created instanceof WP_Error ) {
					$warnings[] = sprintf(
						/* translators: %s: term slug. */
						__( 'Parent term "%s" could not be created.', 'contentcopy' ),
						$parentSlug
					);
				} else {
					$parentId = (int) $created['term_id'];
				}
			}
		}

		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( $existing instanceof \WP_Term ) {
			return $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug, 'parent' => $parentId ] );

		if ( $created instanceof WP_Error ) {
			$warnings[] = sprintf(
				/* translators: %s: term slug. */
				__( 'Term "%s" could not be created.', 'contentcopy' ),
				$slug
			);

			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Replace every non-internal meta row with the incoming values.
	 *
	 * Wipe-then-replay (rather than merge) is what makes deletions propagate:
	 * a field removed on the source disappears on the destination instead of
	 * haunting it forever. Each key's list of values is replayed with
	 * `add_post_meta`, preserving multi-value rows. Values the remapper
	 * flagged as unwritable (null) are skipped — an unmatchable featured
	 * image must unset, not store a lie.
	 *
	 * @param int   $postId Destination post ID.
	 * @param mixed $meta   Raw meta payload (key => list of values).
	 *
	 * @return list<string> Currently always empty; kept for symmetry with the other apply* methods.
	 */
	private function applyMeta( int $postId, mixed $meta ): array {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$internal = Uuid::internalMetaKeys();
		$warnings = [];
		$existing = get_post_meta( $postId );

		if ( is_array( $existing ) ) {
			foreach ( array_keys( $existing ) as $key ) {
				if ( ! in_array( (string) $key, $internal, true ) ) {
					delete_post_meta( $postId, (string) $key );
				}
			}
		}

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;

			if ( in_array( $key, $internal, true ) || in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				$stored = $this->sanitiseMetaValue( $value );

				if ( null === $stored ) {
					// Deliberately unset, e.g. an unmatchable featured image.
					continue;
				}

				add_post_meta( $postId, $key, $stored );
			}
		}

		return $warnings;
	}

	/**
	 * Sanitise one meta value for storage.
	 *
	 * Strings with markup keep their tags (content-adjacent HTML travelled
	 * intentionally); plain strings are stripped to text. Scalars and arrays
	 * pass through the deep sanitiser; anything exotic (objects, resources —
	 * which should never survive JSON anyway) is dropped as null.
	 *
	 * @param mixed $value Incoming value.
	 *
	 * @return mixed Safe value, or null to skip the row.
	 */
	private function sanitiseMetaValue( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return str_contains( $value, '<' ) ? wp_kses_post( $value ) : sanitize_text_field( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || is_array( $value ) || null === $value ) {
			return $this->sanitiseDeep( $value );
		}

		return null;
	}

	/**
	 * Recursively sanitise nested meta values.
	 *
	 * Nulls that were already null stay null (a meaningful value in ACF
	 * land); anything that sanitises *to* null is dropped, so a hostile or
	 * corrupt nested value cannot smuggle itself in as an empty row.
	 *
	 * @param mixed $value Value to walk.
	 *
	 * @return mixed Sanitised value.
	 */
	private function sanitiseDeep( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$out = [];

			foreach ( $value as $k => $v ) {
				$clean = $this->sanitiseMetaValue( $v );

				if ( null !== $clean || null === $v ) {
					$out[ $k ] = $clean;
				}
			}

			return $out;
		}

		return $value;
	}

	/**
	 * Mirror the sticky flag for plain posts.
	 *
	 * Stickiness is blog-front-page state, not content — but dropping it
	 * silently would un-stick announcements, so it travels explicitly and is
	 * applied (or cleared) to match the source.
	 *
	 * @param int                  $postId   Destination post ID.
	 * @param string               $postType Destination post type.
	 * @param array<string, mixed> $payload  Receive payload.
	 *
	 * @return void
	 */
	private function applySticky( int $postId, string $postType, array $payload ): void {
		if ( 'post' !== $postType || ! function_exists( 'stick_post' ) ) {
			return;
		}

		if ( ! empty( $payload['sticky'] ) ) {
			stick_post( $postId );
		} else {
			unstick_post( $postId );
		}
	}
}
