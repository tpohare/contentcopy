<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every attachment a post depends on.
 *
 * A post's media hides in four places, and missing any one of them produces
 * a destination copy with broken images or dead PDF links:
 *
 * 1. The featured image (`_thumbnail_id`).
 * 2. Attached children — files uploaded "to" this post in the media modal.
 * 3. Files embedded in the content: block attributes, editor image classes,
 *    gallery lists, classic shortcodes, and hand-written links to uploads.
 * 4. Files referenced from custom fields: upload URLs inside any string, and
 *    IDs inside ACF attachment fields (whose type proves the reference).
 *
 * Two things this class deliberately does *not* do:
 *
 * - It never treats a bare number in an unknown field as a reference. A value
 *   of `3` is far more likely a column count than attachment 3, and remapping
 *   it would corrupt content silently. Only typed evidence (ACF field types,
 *   explicit block attributes, URLs under the uploads directory) counts.
 * - It drops attachments whose file is missing from disk, with a warning.
 *   Uploading a database row with no bytes behind it would fail later with a
 *   worse message; failing here names the file while the source is in hand.
 */
final class MediaCollector {

	/**
	 * @var LoggerInterface Skipped files are logged here.
	 */
	private LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger Where to log skips; silent when omitted.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Collect the attachment IDs a post needs, dropping unusable ones.
	 *
	 * Candidates from all four hiding places are merged, de-duplicated, and
	 * verified: the ID must still be an attachment (not a deleted-then-reused
	 * ID) and its file must exist on disk.
	 *
	 * @param \WP_Post $post Source post.
	 *
	 * @return list<int> Verified attachment IDs, de-duplicated.
	 */
	public function collect( \WP_Post $post ): array {
		$candidates = [
			...$this->featured( $post ),
			...$this->children( $post->ID ),
			...$this->fromContent( (string) $post->post_content ),
			...$this->fromMeta( $post->ID ),
		];

		$valid = [];

		foreach ( array_unique( $candidates ) as $id ) {
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}

			$file = get_attached_file( $id );

			if ( false === $file || ! file_exists( $file ) ) {
				$this->logger->warning(
					'Attachment {id} ("{title}") has no file on disk and was skipped.',
					[
						'id'    => $id,
						'title' => get_the_title( $id ),
					]
				);

				continue;
			}

			$valid[] = $id;
		}

		$this->logger->info(
			'Collected {count} attachment(s) for post {post}.',
			[
				'count' => count( $valid ),
				'post'  => $post->ID,
			]
		);

		return $valid;
	}

	/**
	 * The featured image, if the post has one.
	 *
	 * @param \WP_Post $post Source post.
	 *
	 * @return list<int> Zero or one attachment ID.
	 */
	private function featured( \WP_Post $post ): array {
		$id = (int) get_post_thumbnail_id( $post );

		return $id > 0 ? [ $id ] : [];
	}

	/**
	 * Attachments whose parent is this post.
	 *
	 * Uploading "to" a post in the media modal sets `post_parent`, which
	 * catches PDFs and images that nothing references yet — the "I'll link
	 * this later" files that would otherwise be orphaned by the push.
	 *
	 * @param int $postId Source post ID.
	 *
	 * @return list<int> Child attachment IDs.
	 */
	private function children( int $postId ): array {
		$children = get_children(
			[
				'post_parent' => $postId,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 500,
				'fields'      => 'ids',
			]
		);

		return is_array( $children ) ? array_map( intval( ... ), array_values( $children ) ) : [];
	}

	/**
	 * Attachment IDs referenced from post content.
	 *
	 * Reads the explicit IDs blocks record first (`wp-image-*` classes,
	 * `"id"` block attributes, gallery `"ids"` lists, classic `[gallery]`
	 * shortcodes), then resolves any remaining local upload URLs — which
	 * catches classic-editor markup, text widgets pasted into content, and
	 * hand-written links to PDFs.
	 *
	 * @param string $content Raw post content.
	 *
	 * @return list<int> Referenced attachment IDs, possibly with duplicates.
	 */
	public function fromContent( string $content ): array {
		$ids = [];

		// The editor's image class, and core block attribute IDs.
		foreach ( [ '/wp-image-(\d+)/', '/"id"\s*:\s*(\d+)/' ] as $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				$ids = [ ...$ids, ...array_map( intval( ... ), $matches[1] ) ];
			}
		}

		// Gallery blocks and the classic gallery shortcode both list IDs.
		foreach ( [ '/"ids"\s*:\s*\[([0-9,\s]*)\]/', '/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/' ] as $pattern ) {
			if ( ! preg_match_all( $pattern, $content, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $list ) {
				$ids = [ ...$ids, ...self::parseIdList( $list ) ];
			}
		}

		foreach ( $this->uploadUrls( $content ) as $url ) {
			$resolved = self::urlToAttachmentId( $url );

			if ( $resolved > 0 ) {
				$ids[] = $resolved;
			}
		}

		return $ids;
	}

	/**
	 * Attachment IDs referenced from post meta.
	 *
	 * Only unambiguous references are followed:
	 *
	 * - Upload URLs inside *any* string value — a URL under the uploads
	 *   directory is evidence regardless of which field holds it.
	 * - Integer IDs inside ACF fields whose *type* proves they are
	 *   attachments (image, file, gallery). The type is the evidence; a bare
	 *   number anywhere else is ignored (see the class docblock for why).
	 *
	 * @param int $postId Source post ID.
	 *
	 * @return list<int> Referenced attachment IDs, possibly with duplicates.
	 */
	public function fromMeta( int $postId ): array {
		$meta = get_post_meta( $postId );

		if ( ! is_array( $meta ) ) {
			return [];
		}

		$ids      = [];
		$internal = Uuid::internalMetaKeys();

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $internal, true ) ) {
				continue;
			}

			$field = AcfBridge::fieldFor( $postId, (string) $key );
			$kind  = null !== $field ? AcfBridge::referenceKind( $field ) : null;

			foreach ( (array) $values as $raw ) {
				$value = maybe_unserialize( $raw );

				foreach ( self::stringsIn( $value ) as $string ) {
					foreach ( $this->uploadUrls( $string ) as $url ) {
						$resolved = self::urlToAttachmentId( $url );

						if ( $resolved > 0 ) {
							$ids[] = $resolved;
						}
					}
				}

				if ( ReferenceKind::Media === $kind ) {
					$ids = [ ...$ids, ...self::integersIn( $value ) ];
				}
			}
		}

		return $ids;
	}

	/**
	 * Pull every URL inside this site's uploads directory out of a string.
	 *
	 * Matched without the scheme so http, https and protocol-relative spellings
	 * of the same file are all found. Block attributes store slashes escaped
	 * (`https:\/\/...`), which is unescaped before matching.
	 *
	 * @param string $text Text to scan — content, a meta string, anything.
	 *
	 * @return list<string> Scheme-relative upload URLs, de-duplicated.
	 */
	public function uploadUrls( string $text ): array {
		if ( '' === $text ) {
			return [];
		}

		$base = wp_get_upload_dir()['baseurl'] ?? '';

		if ( '' === $base ) {
			return [];
		}

		$hostAndPath = (string) preg_replace( '#^https?://#', '', $base );
		$pattern     = '#' . preg_quote( $hostAndPath, '#' ) . '/[^\s"\'<>()\\\\]+#i';

		// Block attributes store slashes escaped.
		$text = str_replace( '\/', '/', $text );

		if ( ! preg_match_all( $pattern, $text, $matches ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_map(
					static fn ( string $path ): string => (string) set_url_scheme( '//' . $path ),
					$matches[0]
				)
			)
		);
	}

	/**
	 * Resolve an uploads URL to an attachment ID, allowing for size suffixes.
	 *
	 * Content usually references the *sized* variant (`photo-800x600.jpg`)
	 * while the attachment row points at the original, so a direct miss is
	 * retried with the `-800x600` suffix stripped. Query strings and
	 * fragments (`?ver=…`, `#page=2`) are cut before matching.
	 *
	 * @param string $url Upload URL, any scheme.
	 *
	 * @return int Attachment ID, or 0 when nothing matches.
	 */
	public static function urlToAttachmentId( string $url ): int {
		$url    = (string) strtok( $url, '?#' );
		$direct = (int) attachment_url_to_postid( $url );

		if ( $direct > 0 ) {
			return $direct;
		}

		// Strip an intermediate size suffix such as -800x600 and retry.
		$full = (string) preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $url );

		return $full === $url ? 0 : (int) attachment_url_to_postid( $full );
	}

	/**
	 * Parse a delimited ID list like gallery shortcodes and blocks use.
	 *
	 * @param string $list      Raw list, e.g. `"12, 34,56"`.
	 * @param string $delimiter Delimiter; galleries always use commas.
	 *
	 * @return list<int> The IDs in order, blanks dropped.
	 */
	public static function parseIdList( string $list, string $delimiter = ',' ): array {
		$parts = array_map( trim( ... ), explode( $delimiter, $list ) );

		return array_values( array_map( intval( ... ), array_filter( $parts, static fn ( string $part ): bool => '' !== $part ) ) );
	}

	/**
	 * Flatten every string inside a nested value.
	 *
	 * Meta values nest arbitrarily (repeaters inside groups inside flexible
	 * content), and a URL can hide at any depth — so the walk is recursive
	 * and returns every string leaf regardless of depth.
	 *
	 * @param mixed $value Value to walk.
	 *
	 * @return list<string> Every string found, in walk order.
	 */
	private static function stringsIn( mixed $value ): array {
		if ( is_string( $value ) ) {
			return [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$strings = [];

		foreach ( $value as $item ) {
			$strings = [ ...$strings, ...self::stringsIn( $item ) ];
		}

		return $strings;
	}

	/**
	 * Flatten every integer-like scalar inside a nested value.
	 *
	 * Accepts real ints and digit strings (`"42"`, the way form-saved fields
	 * arrive) while rejecting floats, alphanumerics and everything else —
	 * only values that could genuinely be IDs come out.
	 *
	 * @param mixed $value Value to walk.
	 *
	 * @return list<int> Every integer found, in walk order.
	 */
	private static function integersIn( mixed $value ): array {
		if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			return [ (int) $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$integers = [];

		foreach ( $value as $item ) {
			$integers = [ ...$integers, ...self::integersIn( $item ) ];
		}

		return $integers;
	}
}
