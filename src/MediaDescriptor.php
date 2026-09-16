<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * A single attachment as the destination sees it.
 *
 * Think of this as a tiny API resource: the destination's media endpoint
 * resolves every uploaded (or reused) file into one of these, and the rest of
 * the push treats the object as the single source of truth for "where does
 * this file live over there?".
 *
 * It is deliberately a readonly data object rather than a plain array. Arrays
 * shaped like `['id' => ..., 'url' => ...]` are easy to build and easy to
 * misread six months later; a named object makes every call site say exactly
 * what it means (`$media->urlForSize( 'thumbnail' )` versus
 * `$media['sizes']['thumbnail'] ?? $media['url']`).
 */
final readonly class MediaDescriptor {

	/**
	 * Build a descriptor for a destination attachment.
	 *
	 * @param int                    $id    Attachment ID on the destination site.
	 * @param string                 $url   Full-size URL on the destination site.
	 * @param array<string, string>  $sizes Intermediate-size name (e.g. `thumbnail`) to its destination URL.
	 */
	public function __construct(
		public int $id,
		public string $url,
		public array $sizes = [],
	) {}

	/**
	 * The destination URL for one registered image size.
	 *
	 * When the destination never generated the requested size — different
	 * registered sizes over there, or a file type WordPress does not resize —
	 * the full-size URL is returned instead. A slightly heavier image always
	 * beats a broken one.
	 *
	 * @param string $size Registered image size name, e.g. `thumbnail` or `medium_large`.
	 *
	 * @return string Destination URL for that size, or the full-size URL as a fallback.
	 */
	public function urlForSize( string $size ): string {
		return $this->sizes[ $size ] ?? $this->url;
	}

	/**
	 * Serialise for the few places that still need a plain array.
	 *
	 * @return array{id: int, url: string, sizes: array<string, string>}
	 */
	public function toArray(): array {
		return [
			'id'    => $this->id,
			'url'   => $this->url,
			'sizes' => $this->sizes,
		];
	}
}
