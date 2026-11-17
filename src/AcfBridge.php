<?php
declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * Optional ACF awareness: resolves custom field definitions by their keys.
 *
 * ACF stores a companion `_fieldname` meta row holding the field key for
 * every field, which lets this class identify *exactly* which custom fields
 * hold attachment or post IDs — instead of guessing from values, which is how
 * a column count of `3` becomes a phantom reference to attachment 3.
 *
 * Every method degrades to a no-op when ACF is not active, so the plugin
 * never requires it: without ACF there are simply no field definitions to
 * resolve, and URL-based remapping carries the rest.
 */
final class AcfBridge {

	/** Field types whose value is one or more attachment IDs. */
	private const ATTACHMENT_TYPES = [ 'image', 'file', 'gallery' ];

	/** Field types whose value is one or more post IDs. */
	private const POST_TYPES = [ 'post_object', 'relationship', 'page_link' ];

	/**
	 * Field types whose value is one or more Gravity Forms form IDs.
	 *
	 * Both spellings are accepted because the field type depends on which
	 * add-on provides it, and sites rarely know which one they have.
	 */
	private const FORM_TYPES = [ 'forms', 'form', 'gravity_forms_field' ];

	/**
	 * Whether ACF's field API is available right now.
	 *
	 * The single gate every other method checks first: no ACF, no field
	 * definitions, no references — and no fatal from calling its functions.
	 *
	 * @return bool True when `acf_get_field()` exists.
	 */
	public static function active(): bool {
		return function_exists( 'acf_get_field' );
	}

	/**
	 * Resolve the ACF field definition behind a meta key, if any.
	 *
	 * Reads the companion `_{$metaKey}` row for the field key, then the field
	 * definition itself. Non-ACF meta (including the companion rows, which
	 * start with `_`) resolves to null, as does everything when ACF is
	 * inactive.
	 *
	 * @param int    $postId  Source post ID.
	 * @param string $metaKey Custom field key to look up.
	 *
	 * @return array<string, mixed>|null Field definition, or null when the key is not an ACF field.
	 */
	public static function fieldFor( int $postId, string $metaKey ): ?array {
		if ( ! self::active() || str_starts_with( $metaKey, '_' ) ) {
			return null;
		}

		$fieldKey = get_post_meta( $postId, '_' . $metaKey, true );

		if ( ! is_string( $fieldKey ) || ! str_starts_with( $fieldKey, 'field_' ) ) {
			return null;
		}

		// Called directly; dev-stubs/acf.php gives PHPStan the signature.
		// active() already confirmed the function exists at runtime.
		$field = acf_get_field( $fieldKey );

		return is_array( $field ) ? $field : null;
	}

	/**
	 * What a field's value points at, or null when it holds no references.
	 *
	 * Image, file and gallery fields hold attachment IDs — unless configured
	 * to return a URL, in which case their values are plain strings and URL
	 * replacement handles them without any ID mapping. Post object,
	 * relationship and page-link fields hold post IDs, and form fields hold
	 * Gravity Forms IDs. Anything else (text, numbers, booleans, layouts) is
	 * not a reference by definition.
	 *
	 * @param array<string, mixed> $field ACF field definition.
	 *
	 * @return ReferenceKind|null What the field's value references, or null for non-references.
	 */
	public static function referenceKind( array $field ): ?ReferenceKind {
		$type = is_string( $field['type'] ?? null ) ? $field['type'] : '';

		if ( in_array( $type, self::POST_TYPES, true ) ) {
			return ReferenceKind::Post;
		}

		if ( in_array( $type, self::FORM_TYPES, true ) ) {
			return ReferenceKind::Form;
		}

		if ( ! in_array( $type, self::ATTACHMENT_TYPES, true ) ) {
			return null;
		}

		// Fields configured to return a URL hold strings, which are remapped
		// by URL replacement rather than by ID.
		$format = is_string( $field['return_format'] ?? null ) ? $field['return_format'] : 'id';

		return 'url' === $format ? null : ReferenceKind::Media;
	}

	/**
	 * Human label for a field, for warning messages.
	 *
	 * Prefers the admin label (`Hero image`) over the machine name
	 * (`hero_image`): warnings are read by editors, not developers.
	 *
	 * @param array<string, mixed> $field ACF field definition.
	 *
	 * @return string Label, name, or '' when neither exists.
	 */
	public static function label( array $field ): string {
		foreach ( [ 'label', 'name' ] as $key ) {
			if ( is_string( $field[ $key ] ?? null ) && '' !== $field[ $key ] ) {
				return $field[ $key ];
			}
		}

		return '';
	}
}
