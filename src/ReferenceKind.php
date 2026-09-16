<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * What a remappable ID inside a custom field points at.
 *
 * Custom fields are just numbers until something declares their meaning —
 * `3` could be a column count or attachment 3. `AcfBridge` resolves an ACF
 * field definition to one of these kinds, and only then does `Remapper`
 * treat the value as a reference. Everything else is left strictly alone,
 * which is what keeps a column count of `3` from becoming attachment 3's
 * destination ID.
 */
enum ReferenceKind {

	/**
	 * The value is an attachment ID (ACF image, file or gallery fields).
	 */
	case Media;

	/**
	 * The value is a regular post ID (ACF post object, relationship or page link fields).
	 */
	case Post;
}
