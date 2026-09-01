<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Fingerprints the editable body of a post.
 *
 * Both sites compute this the same way from the same three fields, and the
 * source stores the destination's fingerprint after every successful push. On
 * the next push the stored fingerprint is compared against the live
 * destination copy: a mismatch means somebody edited the copy in the
 * meantime, and the push stops with a conflict instead of silently
 * overwriting their work.
 *
 * Only the *editable* body is fingerprinted — title, content, excerpt. IDs,
 * dates, authors and meta are transport, not authorship, and must not trip
 * the detector.
 */
final class ContentHash {

	/**
	 * Fingerprint three editable fields.
	 *
	 * Fields are joined with null bytes, which cannot appear in post text, so
	 * `('ab', 'c')` and `('a', 'bc')` can never collide the way naive
	 * concatenation would allow.
	 *
	 * @param string $title   Post title.
	 * @param string $content Raw post content.
	 * @param string $excerpt Post excerpt.
	 *
	 * @return string 128-bit xxHash fingerprint, hex-encoded.
	 */
	public static function of( string $title, string $content, string $excerpt ): string {
		return hash( 'xxh128', implode( "\0", [ $title, $content, $excerpt ] ) );
	}

	/**
	 * Fingerprint a post object from its editable fields.
	 *
	 * @param \WP_Post $post The post to fingerprint.
	 *
	 * @return string 128-bit xxHash fingerprint, hex-encoded.
	 */
	public static function ofPost( \WP_Post $post ): string {
		return self::of( $post->post_title, $post->post_content, $post->post_excerpt );
	}
}
