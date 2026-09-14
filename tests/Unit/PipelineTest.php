<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\Pipeline and ContentCopy\MediaDescriptor.
 *
 * The pipeline test pins the execution contract every push relies on: stages
 * run in order, each sees the previous stage's work, and a stage can abort
 * the whole run by returning a WP_Error instead of calling $next().
 */

use ContentCopy\MediaDescriptor;
use ContentCopy\Pipeline;

it( 'runs stages in order and passes the result through', function () {
	$order = [];

	$result = Pipeline::send( 1 )
		->through(
			[
				function ( $value, $next ) use ( &$order ) {
					$order[] = 'first';

					return $next( $value + 1 );
				},
				function ( $value, $next ) use ( &$order ) {
					$order[] = 'second';

					return $next( $value * 10 );
				},
			]
		)
		->then( fn ( $value ) => $value + 100 );

	expect( $order )->toBe( [ 'first', 'second' ] )
		and expect( $result )->toBe( 120 );
} );

it( 'aborts when a stage returns instead of calling next', function () {
	$reached = [];
	$abort   = new WP_Error( 'contentcopy_conflict', 'Stop.' );

	$result = Pipeline::send( 'job' )
		->through(
			[
				function ( $value, $next ) use ( &$reached, $abort ) {
					$reached[] = 'aborting';

					return $abort;
				},
				function ( $value, $next ) use ( &$reached ) {
					$reached[] = 'never';

					return $next( $value );
				},
			]
		)
		->then( fn () => 'destination' );

	expect( $result )->toBe( $abort )
		and expect( $reached )->toBe( [ 'aborting' ] );
} );

it( 'describes media with a full-size fallback for unknown sizes', function () {
	$media = new MediaDescriptor(
		id: 55,
		url: 'https://dest.test/uploads/img.jpg',
		sizes: [ 'thumbnail' => 'https://dest.test/uploads/img-150x150.jpg' ],
	);

	expect( $media->urlForSize( 'thumbnail' ) )->toBe( 'https://dest.test/uploads/img-150x150.jpg' )
		and expect( $media->urlForSize( 'medium_large' ) )->toBe( 'https://dest.test/uploads/img.jpg' )
		and expect( $media->toArray() )->toBe(
			[
				'id'    => 55,
				'url'   => 'https://dest.test/uploads/img.jpg',
				'sizes' => [ 'thumbnail' => 'https://dest.test/uploads/img-150x150.jpg' ],
			]
		);
} );
