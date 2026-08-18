<?php

declare(strict_types=1);

/**
 * Code style enforcement for Content Copy.
 *
 * Deliberately minimal: a single rule that keeps the codebase on modern
 * short array syntax (`[]` instead of `array()`). Run with
 * `composer cs-fix`; CI-style check with `composer cs-check`.
 */

$finder = PhpCsFixer\Finder::create()
	->in( __DIR__ . '/src' )
	->in( __DIR__ . '/tests' )
	->in( __DIR__ . '/dev-stubs' )
	->name( '*.php' )
	->append( [ __DIR__ . '/contentcopy.php' ] );

return ( new PhpCsFixer\Config() )
	->setRules(
		[
			'array_syntax' => [ 'syntax' => 'short' ],
		]
	)
	->setFinder( $finder );
