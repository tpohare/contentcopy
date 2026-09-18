<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\MediaCollector's pure parsers.
 *
 * Media discovery has two halves: parsing explicit IDs out of content (block
 * attributes, gallery lists, editor classes) and resolving upload URLs back
 * to attachments. Both are pinned here without touching the database.
 */

use ContentCopy\MediaCollector;

beforeEach( fn () => contentcopy_test_reset() );

it( 'parses comma-separated ID lists', function () {
	expect( MediaCollector::parseIdList( '1, 2,3' ) )->toBe( [ 1, 2, 3 ] );
	expect( MediaCollector::parseIdList( '' ) )->toBe( [] );
	expect( MediaCollector::parseIdList( '  7  ' ) )->toBe( [ 7 ] );
} );

it( 'finds upload URLs regardless of scheme', function () {
	$collector = new MediaCollector();

	$text = 'see http://source.test/wp-content/uploads/2024/report.pdf and '
		. 'https://source.test/wp-content/uploads/2024/report.pdf and '
		. '//source.test/wp-content/uploads/2024/report.pdf plus '
		. 'https://other.test/file.pdf';

	$found = $collector->uploadUrls( $text );

	// All three local spellings collapse to one scheme-relative URL; the
	// foreign host is ignored.
	expect( $found )->toHaveCount( 1 )
		and expect( $found[0] )->toContain( 'report.pdf' )
		and expect( $found[0] )->not->toContain( 'other.test' );
} );

it( 'reads attachment IDs from block markup and classic shortcodes', function () {
	$collector = new MediaCollector();

	$content = '<!-- wp:image {"id":11} --><figure class="wp-block-image">'
		. '<img class="wp-image-12" /></figure><!-- /wp:image -->'
		. '<!-- wp:gallery {"ids":[13, 14]} --><!-- /wp:gallery -->'
		. '[gallery ids="15,16"]';

	$found = $collector->fromContent( $content );

	expect( $found )->toContain( 11, 12, 13, 14, 15, 16 );
} );

it( 'resolves upload URLs in content to attachment IDs', function () {
	$GLOBALS['contentcopy_test_upload_urls'] = [
		'//source.test/wp-content/uploads/2024/photo.jpg' => 99,
	];

	$collector = new MediaCollector();
	$found     = $collector->fromContent( '<p><img src="https://source.test/wp-content/uploads/2024/photo.jpg" /></p>' );

	expect( $found )->toContain( 99 );
} );

it( 'collects a resolvable attachment and skips the rest', function () {
	$GLOBALS['contentcopy_test_upload_urls'] = [
		'//source.test/wp-content/uploads/2024/photo.jpg' => 99,
	];

	$post               = new WP_Post();
	$post->ID           = 5;
	$post->post_content = '<p><img src="https://source.test/wp-content/uploads/2024/photo.jpg" /></p>'
		. '<img class="wp-image-12345" />';

	$collector = new MediaCollector();
	$found     = $collector->collect( $post );

	// 99 exists on disk (the stub materialises a temp file); 12345 is not an
	// attachment and is dropped.
	expect( $found )->toBe( [ 99 ] );
} );
