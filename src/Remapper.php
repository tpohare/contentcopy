<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites source IDs and URLs to their destination equivalents.
 *
 * Remapping runs entirely on the source, after media has been synced, so the
 * destination only ever writes values that already point at itself. Keeping
 * it in one place means the destination needs no knowledge of the source's
 * URLs — and the source never has to explain them.
 *
 * Three different reference styles are rewritten, because WordPress stores
 * the same attachment three ways at once:
 *
 * - **URLs** in content and meta strings (including scheme variants and the
 *   JSON-escaped slashes blocks use).
 * - **Editor image classes** (`wp-image-123`), which the block editor uses to
 *   resolve the attachment when the block is next edited. Miss these and the
 *   copy *looks* fine until someone edits it.
 * - **Block attribute IDs** (`"id":123`, gallery `"ids"` lists, `data-id`,
 *   classic `[gallery]` shortcodes) for the same reason.
 *
 * Embedded Gravity Forms are rewritten too, in both the shortcode and block
 * spellings, from the map the push built by sending the form definitions
 * ahead of the content.
 *
 * Anything that cannot be mapped is never silently kept as a lie: unmapped
 * gallery items are dropped with a warning, an unmapped featured image is
 * unset, and unmapped ACF references keep their original ID loudly — with a
 * warning explaining how to fix it (push the target, push again).
 */
final class Remapper {

	/**
	 * Source URL => destination URL, longest source first.
	 *
	 * Pre-sorted so a full-size URL never clobbers the prefix of one of its
	 * own size-suffixed variants during replacement.
	 *
	 * @var array<string, string>
	 */
	private array $urls;

	/**
	 * @var LoggerInterface Every unmappable reference is reported here.
	 */
	private LoggerInterface $logger;

	/**
	 * @param array<int, MediaDescriptor> $media Source attachment ID => where it landed on the destination.
	 * @param array<int, int>             $posts Source post ID => destination post ID, for ACF post-reference fields.
	 * @param array<int, int>             $forms Source form ID => destination form ID, for embedded Gravity Forms.
	 * @param LoggerInterface|null        $logger Warning sink; silent when omitted.
	 */
	public function __construct(
		private readonly array $media,
		private readonly array $posts = [],
		private readonly array $forms = [],
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger ?? new NullLogger();
		$this->urls   = $this->buildUrlTable();
	}

	/**
	 * Build the source-URL => destination-URL table.
	 *
	 * Intermediate sizes are matched by size name. When the destination did
	 * not generate a matching size — different registered sizes over there,
	 * or a file type WordPress does not resize — the descriptor's full-size
	 * URL is substituted, which renders correctly even though it is heavier.
	 *
	 * @return array<string, string> Source URL => destination URL, longest source first.
	 */
	private function buildUrlTable(): array {
		$table = [];

		foreach ( $this->media as $sourceId => $destination ) {
			$sourceUrl = wp_get_attachment_url( $sourceId );

			if ( false === $sourceUrl || '' === $destination->url ) {
				continue;
			}

			$full      = $destination->url;
			$sizes     = $destination->sizes;
			$meta      = wp_get_attachment_metadata( $sourceId );
			$directory = trailingslashit( dirname( $sourceUrl ) );

			if ( is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ) {
				foreach ( $meta['sizes'] as $sizeName => $size ) {
					if ( ! is_array( $size ) || empty( $size['file'] ) ) {
						continue;
					}

					$table[ $directory . $size['file'] ] = $sizes[ $sizeName ] ?? $full;
				}
			}

			$table[ $sourceUrl ] = $full;
		}

		// Longest first, so a full-size URL never clobbers the prefix of a
		// size-suffixed one.
		uksort( $table, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $table;
	}

	/**
	 * Remap post content: URLs, the editor image class, and block attributes.
	 *
	 * Block attribute IDs are only rewritten when the ID belongs to a synced
	 * attachment. An unrelated numeric `"id"` attribute that happens to
	 * collide with a pushed attachment ID is accepted collateral — the
	 * alternative (rewriting nothing) breaks every real gallery.
	 *
	 * @param string $content Raw source content.
	 *
	 * @return string Content with every known reference pointing at the destination.
	 */
	public function content( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		$content = $this->replaceUrls( $content );

		// The editor image class, which the block editor uses to resolve the
		// attachment when the block is next edited.
		$content = (string) preg_replace_callback(
			'/wp-image-(\d+)/',
			fn ( array $m ): string => 'wp-image-' . ( $this->mappedMediaId( (int) $m[1] ) ?? $m[1] ),
			$content
		);

		// Block attribute IDs, scoped to attachments that were actually synced.
		$content = (string) preg_replace_callback(
			'/"id"\s*:\s*(\d+)/',
			fn ( array $m ): string => '"id":' . ( $this->mappedMediaId( (int) $m[1] ) ?? $m[1] ),
			$content
		);

		// Gallery block ID lists.
		$content = (string) preg_replace_callback(
			'/"ids"\s*:\s*\[([0-9,\s]*)\]/',
			fn ( array $m ): string => '"ids":[' . $this->remapIdList( $m[1] ) . ']',
			$content
		);

		// Gallery markup carries data-id alongside each image.
		$content = (string) preg_replace_callback(
			'/data-id=(["\'])(\d+)\1/',
			fn ( array $m ): string => 'data-id=' . $m[1] . ( $this->mappedMediaId( (int) $m[2] ) ?? $m[2] ) . $m[1],
			$content
		);

		// Classic gallery shortcode.
		$content = (string) preg_replace_callback(
			'/(\[gallery[^\]]*ids=)(["\'])([0-9,\s]+)\2/',
			fn ( array $m ): string => $m[1] . $m[2] . $this->remapIdList( $m[3] ) . $m[2],
			$content
		);

		return $this->replaceFormIds( $content );
	}

	/**
	 * Point embedded Gravity Forms shortcodes and blocks at the destination's forms.
	 *
	 * Both embed styles are rewritten wherever they appear — content, custom
	 * fields, page builder payloads — because a form ID that means one thing
	 * on the source means something else entirely here. A reference with no
	 * counterpart keeps its ID and raises a warning: better a form the editor
	 * is told about than a silently blank space where a contact form used
	 * to be.
	 *
	 * @param string $text Text to rewrite.
	 *
	 * @return string With destination form IDs throughout.
	 */
	private function replaceFormIds( string $text ): string {
		if ( '' === $text || ! str_contains( $text, 'gravityform' ) && ! str_contains( $text, 'formId' ) ) {
			return $text;
		}

		// [gravityform id="5" …] and [gravityforms id=5 …], attributes in any
		// order, quoted or not.
		$text = (string) preg_replace_callback(
			'/(\[gravityforms?\b[^\]]*\bid=)(["\']?)(\d+)\2/i',
			fn ( array $m ): string => $m[1] . $m[2] . $this->mappedFormLabel( (int) $m[3] ) . $m[2],
			$text
		);

		// <!-- wp:gravityforms/form {"formId":"5", …} -->
		return (string) preg_replace_callback(
			'/("formId"\s*:\s*)(["\']?)(\d+)\2/',
			fn ( array $m ): string => $m[1] . $m[2] . $this->mappedFormLabel( (int) $m[3] ) . $m[2],
			$text
		);
	}

	/**
	 * The destination form ID to write in place of a source one.
	 *
	 * @param int $sourceId Source form ID found in the text.
	 *
	 * @return string Destination form ID, or the original when it could not be mapped.
	 */
	private function mappedFormLabel( int $sourceId ): string {
		$mapped = $this->mappedFormId( $sourceId );

		if ( null === $mapped ) {
			$this->logger->warning(
				'An embedded form (form {id} on the source) has no counterpart on the destination, so the embed was left pointing at form {id}. Check that Gravity Forms is active on the destination.',
				[ 'id' => $sourceId ]
			);

			return (string) $sourceId;
		}

		return (string) $mapped;
	}

	/**
	 * Remap one custom field value.
	 *
	 * Three cases, in order:
	 *
	 * 1. `_thumbnail_id` — the featured image — maps to the destination
	 *    attachment ID, or to null (unset) when it could not be matched.
	 *    Keeping a stale source ID would display *someone else's* image.
	 * 2. ACF reference fields (typed by `AcfBridge`) have their IDs remapped
	 *    recursively through arbitrarily nested repeaters and groups.
	 * 3. Everything else gets URL replacement in strings, with bare numbers
	 *    left strictly alone (a `3` is a column count until proven otherwise).
	 *
	 * @param int    $postId Source post ID, for ACF field lookup.
	 * @param string $key    Meta key.
	 * @param mixed  $value  Unserialised meta value.
	 *
	 * @return mixed Remapped value, or null when the value must not be written at all.
	 */
	public function meta( int $postId, string $key, mixed $value ): mixed {
		if ( '_thumbnail_id' === $key ) {
			$mapped = $this->mappedMediaId( (int) $value );

			if ( null === $mapped ) {
				$this->logger->warning( 'The featured image could not be matched on the destination, so it was left unset.' );

				return null;
			}

			return (string) $mapped;
		}

		$field = AcfBridge::fieldFor( $postId, $key );
		$kind  = null !== $field ? AcfBridge::referenceKind( $field ) : null;

		if ( null !== $kind && null !== $field ) {
			return $this->remapIdsIn( $value, $kind, AcfBridge::label( $field ) );
		}

		$this->noteUnresolvedNumber( $key, $value );

		return $this->remapStringsIn( $value );
	}

	/**
	 * Resolve a source attachment ID to its destination counterpart.
	 *
	 * @param int $sourceId Source attachment ID.
	 *
	 * @return int|null Destination attachment ID, or null when it was never synced.
	 */
	public function mappedMediaId( int $sourceId ): ?int {
		return isset( $this->media[ $sourceId ] ) ? $this->media[ $sourceId ]->id : null;
	}

	/**
	 * Resolve a source post ID to its destination counterpart.
	 *
	 * Only references the pusher explicitly resolved via UUID lookup — there
	 * is no guessing here.
	 *
	 * @param int $sourceId Source post ID.
	 *
	 * @return int|null Destination post ID, or null when unknown.
	 */
	public function mappedPostId( int $sourceId ): ?int {
		return $this->posts[ $sourceId ] ?? null;
	}

	/**
	 * Resolve a source form ID to its destination counterpart.
	 *
	 * Only forms the push actually sent ahead of the content — there is no
	 * guessing here either.
	 *
	 * @param int $sourceId Source Gravity Forms form ID.
	 *
	 * @return int|null Destination form ID, or null when unknown.
	 */
	public function mappedFormId( int $sourceId ): ?int {
		return $this->forms[ $sourceId ] ?? null;
	}

	/**
	 * Replace every known source URL in a string.
	 *
	 * Each URL is replaced in four spellings — as-is, both explicit schemes,
	 * and JSON-escaped — because the same file appears as `https://…` in
	 * markup, `http://…` in legacy content, and `https:\/\/…` inside block
	 * attribute payloads.
	 *
	 * @param string $text Text to rewrite.
	 *
	 * @return string With destination URLs throughout.
	 */
	private function replaceUrls( string $text ): string {
		foreach ( $this->urls as $from => $to ) {
			$text = str_replace(
				[
					$from,
					(string) set_url_scheme( $from, 'http' ),
					(string) set_url_scheme( $from, 'https' ),
					str_replace( '/', '\/', $from ),
				],
				[
					$to,
					$to,
					$to,
					str_replace( '/', '\/', $to ),
				],
				$text
			);
		}

		return $text;
	}

	/**
	 * Remap a delimited gallery ID list, dropping unmappable items.
	 *
	 * A gallery item with no destination counterpart cannot render, so it is
	 * removed and named in a warning — a four-image gallery arriving with
	 * three working images beats a shortcode pointing at nothing.
	 *
	 * @param string $list      Raw ID list.
	 * @param string $delimiter Delimiter; galleries always use commas.
	 *
	 * @return string Remapped list.
	 */
	private function remapIdList( string $list, string $delimiter = ',' ): string {
		$mapped = [];

		foreach ( MediaCollector::parseIdList( $list, $delimiter ) as $id ) {
			$target = $this->mappedMediaId( $id );

			if ( null === $target ) {
				$this->logger->warning(
					'Gallery item {id} had no destination counterpart and was removed from the gallery.',
					[ 'id' => $id ]
				);

				continue;
			}

			$mapped[] = $target;
		}

		return implode( $delimiter, $mapped );
	}

	/**
	 * Recursively remap ACF reference IDs inside a custom field value.
	 *
	 * Walks repeaters, groups and flexible content to any depth. A reference
	 * with no destination counterpart keeps its original ID — deliberately,
	 * and loudly: the warning tells the editor to push the missing target
	 * and re-push, after which the same field remaps cleanly.
	 *
	 * @param mixed         $value Value to walk.
	 * @param ReferenceKind $kind  Whether IDs here point at media or posts.
	 * @param string        $label Field label, for warnings.
	 *
	 * @return mixed Value with known IDs replaced.
	 */
	private function remapIdsIn( mixed $value, ReferenceKind $kind, string $label ): mixed {
		if ( is_array( $value ) ) {
			return array_map( fn ( mixed $item ): mixed => $this->remapIdsIn( $item, $kind, $label ), $value );
		}

		if ( ! is_int( $value ) && ! ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			return $value;
		}

		$id     = (int) $value;
		$target = match ( $kind ) {
			ReferenceKind::Media => $this->mappedMediaId( $id ),
			ReferenceKind::Post  => $this->mappedPostId( $id ),
			ReferenceKind::Form  => $this->mappedFormId( $id ),
		};

		if ( null === $target ) {
			$this->logger->warning(
				'Field "{label}" references item {id}, which has no counterpart on the destination. The original ID was kept, so it will point at the wrong item or at nothing — push that item too, then push this one again.',
				[
					'label' => $label,
					'id'    => $id,
				]
			);

			return $value;
		}

		return is_int( $value ) ? $target : (string) $target;
	}

	/**
	 * Recursively rewrite upload URLs inside a custom field value.
	 *
	 * Strings get the full four-spelling replacement; arrays are walked;
	 * anything else passes through untouched.
	 *
	 * @param mixed $value Value to walk.
	 *
	 * @return mixed Value with destination URLs throughout.
	 */
	private function remapStringsIn( mixed $value ): mixed {
		return match ( true ) {
			is_string( $value ) => $this->replaceFormIds( $this->replaceUrls( $value ) ),
			is_array( $value )  => array_map( $this->remapStringsIn( ... ), $value ),
			default             => $value,
		};
	}

	/**
	 * Flag a numeric field whose value matches a pushed attachment ID.
	 *
	 * This is the "column count of 3" tripwire: the value was *not* remapped
	 * (nothing proves it is a reference), but the coincidence is suspicious
	 * enough to mention. The editor checks the destination and fixes it if
	 * the field really was a reference in disguise.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Unserialised meta value.
	 *
	 * @return void
	 */
	private function noteUnresolvedNumber( string $key, mixed $value ): void {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
			return;
		}

		$id = (int) $value;

		if ( $id > 0 && isset( $this->media[ $id ] ) ) {
			$this->logger->warning(
				'Custom field "{key}" holds the value {id}, which matches an attachment that was copied. It was left unchanged because nothing identifies it as a reference — check it on the destination if it was meant to be one.',
				[
					'key' => $key,
					'id'  => $id,
				]
			);
		}
	}
}
