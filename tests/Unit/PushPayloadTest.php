<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\PushPayload.
 *
 * The payload is the contract with the destination: one source post in,
 * one remapped array out. These tests pin the shape — every key the
 * destination validates is present — and the two behaviours that matter
 * most: content leaves with destination URLs, and internal bookkeeping
 * (UUIDs, edit locks) never crosses the wire.
 */

use ContentCopy\MediaDescriptor;
use ContentCopy\PushPayload;
use ContentCopy\Remapper;

beforeEach( function () {
	contentcopy_test_reset();

	$GLOBALS['contentcopy_test_meta'][7] = [
		'colour'       => [ 'red' ],
		'_edit_lock'   => [ '1234:56' ],
		'_thumbnail_id' => [ 11 ],
	];

	$post               = new WP_Post();
	$post->ID           = 7;
	$post->post_type    = 'post';
	$post->post_title   = 'Hello';
	$post->post_content = '<img src="https://source.test/wp-content/uploads/2024/img.jpg" class="wp-image-11" />';
	$post->post_excerpt = 'Standfirst';
	$post->post_name    = 'hello';
	$post->post_status  = 'publish';
	$post->post_author  = 0;

	$this->remapper = new Remapper(
		[
			11 => new MediaDescriptor( id: 55, url: 'https://dest.test/uploads/img.jpg' ),
		]
	);
	$this->payload  = PushPayload::fromPost( $post, $this->remapper )->toArray();
} );

it( 'carries every field the destination validates', function () {
	expect( array_keys( $this->payload ) )->toBe(
		[
			'uuid',
			'post_type',
			'title',
			'content',
			'excerpt',
			'slug',
			'status',
			'date_gmt',
			'menu_order',
			'comment_status',
			'ping_status',
			'sticky',
			'parent_uuid',
			'author',
			'terms',
			'meta',
			'origin',
		]
	);
} );

it( 'remaps content and meta to the destination', function () {
	expect( $this->payload['content'] )
		->toContain( 'https://dest.test/uploads/img.jpg' )
		and expect( $this->payload['content'] )->not->toContain( 'source.test' )
		and expect( $this->payload['meta']['colour'] )->toBe( [ 'red' ] )
		and expect( $this->payload['meta']['_thumbnail_id'] )->toBe( [ '55' ] );
} );

it( 'never leaks internal bookkeeping to the destination', function () {
	expect( $this->payload['meta'] )->not->toHaveKey( '_contentcopy_uuid' )
		and expect( $this->payload['meta'] )->not->toHaveKey( '_edit_lock' )
		and expect( $this->payload['meta'] )->not->toHaveKey( '_edit_last' )
		and expect( $this->payload['origin'] )->toContain( 'source.test' );
} );

it( 'marks root-level items with an empty parent and unmatched authors plainly', function () {
	expect( $this->payload['parent_uuid'] )->toBe( '' )
		and expect( $this->payload['author'] )->toBe(
			[ 'email' => '', 'login' => '', 'name' => '' ]
		)
		and expect( $this->payload['terms'] )->toBe( [] );
} );
