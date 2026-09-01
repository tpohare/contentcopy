<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\ContentHash.
 *
 * The hash is the tripwire for the whole conflict system: both sites compute
 * it the same way, so these tests pin the two properties that matter —
 * identical bodies always hash identically, and any change to the editable
 * body always changes the hash.
 */

use ContentCopy\ContentHash;

beforeEach( fn () => contentcopy_test_reset() );

it( 'hashes the same body to the same fingerprint', function () {
	expect( ContentHash::of( 'Title', 'Body', 'Excerpt' ) )
		->toBe( ContentHash::of( 'Title', 'Body', 'Excerpt' ) );
} );

it( 'changes the fingerprint when any editable part changes', function () {
	$original = ContentHash::of( 'Title', 'Body', 'Excerpt' );

	expect( ContentHash::of( 'Edited', 'Body', 'Excerpt' ) )->not->toBe( $original )
		and expect( ContentHash::of( 'Title', 'Edited', 'Excerpt' ) )->not->toBe( $original )
		and expect( ContentHash::of( 'Title', 'Body', 'Edited' ) )->not->toBe( $original );
} );

it( 'fingerprints a post from its editable fields', function () {
	$post               = new WP_Post();
	$post->post_title   = 'Title';
	$post->post_content = 'Body';
	$post->post_excerpt = 'Excerpt';

	expect( ContentHash::ofPost( $post ) )->toBe( ContentHash::of( 'Title', 'Body', 'Excerpt' ) );
} );
