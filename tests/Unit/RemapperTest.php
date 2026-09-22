<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\Remapper.
 *
 * Remapping is where a push is won or lost: every source URL and editor
 * image reference must come out pointing at the destination, and anything
 * unmappable must be reported, not silently kept. The fake world here has one
 * synced attachment (source 11 → destination 55), and each test pins one
 * rewriting rule against it.
 */

use ContentCopy\MediaDescriptor;
use ContentCopy\Remapper;

beforeEach( function () {
	contentcopy_test_reset();

	$this->remapper = new Remapper(
		[
			11 => new MediaDescriptor(
				id: 55,
				url: 'https://dest.test/uploads/img.jpg',
				sizes: [ 'thumbnail' => 'https://dest.test/uploads/img-150x150.jpg' ],
			),
		]
	);
} );

it( 'rewrites full-size and intermediate-size URLs in content', function () {
	$content = '<p><img src="https://source.test/wp-content/uploads/2024/img.jpg" />'
		. '<img src="https://source.test/wp-content/uploads/2024/img-150x150.jpg" /></p>'
		. '<!-- wp:image {"id":11,"url":"https:\/\/source.test\/wp-content\/uploads\/2024\/img.jpg"} -->';

	$rewritten = $this->remapper->content( $content );

	expect( $rewritten )->not->toContain( 'source.test' )
		and expect( $rewritten )->toContain( 'https://dest.test/uploads/img.jpg' )
		and expect( $rewritten )->toContain( 'https://dest.test/uploads/img-150x150.jpg' );
} );

it( 'rewrites editor image classes and block attribute IDs', function () {
	$rewritten = $this->remapper->content( '<img class="wp-image-11" /><!-- wp:image {"id":11} -->' );

	expect( $rewritten )->toContain( 'wp-image-55' )
		and expect( $rewritten )->toContain( '"id":55' )
		and expect( $rewritten )->not->toContain( 'wp-image-11' );
} );

it( 'leaves unknown attachment IDs alone', function () {
	$rewritten = $this->remapper->content( '<img class="wp-image-999" /><!-- wp:image {"id":999} -->' );

	expect( $rewritten )->toContain( 'wp-image-999' )
		and expect( $rewritten )->toContain( '"id":999' );
} );

it( 'remaps the featured image to the destination attachment', function () {
	expect( $this->remapper->meta( 5, '_thumbnail_id', 11 ) )->toBe( '55' );
} );

it( 'drops an unmappable featured image instead of pointing at a stranger', function () {
	expect( $this->remapper->meta( 5, '_thumbnail_id', 999 ) )->toBeNull();
} );

it( 'rewrites upload URLs inside plain custom fields', function () {
	$value = $this->remapper->meta( 5, 'subtitle', 'See https://source.test/wp-content/uploads/2024/img.jpg' );

	expect( $value )->toBe( 'See https://dest.test/uploads/img.jpg' );
} );
