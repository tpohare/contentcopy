<?php
/**
 * Minimal ACF function signatures for static analysis only.
 *
 * This file is never loaded at runtime. It exists so PHPStan knows the
 * functions AcfBridge calls when ACF is active.
 *
 * @package ContentCopy
 */

declare(strict_types=1);

if ( ! function_exists( 'acf_get_field' ) ) {
	/**
	 * @param string $selector Field key.
	 * @return array<string, mixed>|false|null
	 */
	function acf_get_field( string $selector ): array|false|null {
		return null;
	}
}
