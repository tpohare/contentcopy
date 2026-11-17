<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\ChunkStore, the destination-side spool.
 *
 * These exercise the real filesystem (in the system temp directory, via the
 * canned uploads path in the stubs) because the whole point of the store is
 * what ends up on disk: pieces written at the right offsets, retries that do
 * not corrupt what already landed, and a checksum that refuses anything else.
 */

use ContentCopy\ChunkStore;

/**
 * Empty the spool directory so each test starts with no sessions.
 *
 * @return void
 */
function contentcopy_clear_spool(): void {
	$directory = sys_get_temp_dir() . '/contentcopy-uploads/contentcopy-chunks/';

	foreach ( (array) glob( $directory . '*' ) as $path ) {
		if ( is_string( $path ) && is_file( $path ) ) {
			unlink( $path );
		}
	}
}

/**
 * The envelope a source sends with the first piece of a file.
 *
 * @param string $bytes The complete file the pieces come from.
 *
 * @return array<string, mixed>
 */
function contentcopy_envelope( string $bytes ): array {
	return [
		'uuid'     => 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001',
		'filename' => 'report.pdf',
		'sha256'   => hash( 'sha256', $bytes ),
	];
}

beforeEach(
	function (): void {
		contentcopy_test_reset();
		contentcopy_clear_spool();
	}
);

it( 'spools pieces in order and assembles the complete file', function () {
	$file  = str_repeat( 'abcdefgh', 64 );
	$store = new ChunkStore();

	$token = $store->open( contentcopy_envelope( $file ), strlen( $file ), 7 );

	expect( $token )->toBeString();

	$chunk    = 128;
	$offset   = 0;
	$progress = [];

	while ( $offset < strlen( $file ) ) {
		$piece    = substr( $file, $offset, $chunk );
		$progress = $store->append( $token, $offset, $piece, hash( 'sha256', $piece ), 7 );

		expect( $progress )->toBeArray();

		$offset = $progress['received'];
	}

	expect( $progress['complete'] )->toBeTrue()
		and expect( $progress['received'] )->toBe( strlen( $file ) );

	$assembled = $store->assemble( $token, 7 );

	expect( $assembled )->toBeArray()
		and expect( file_get_contents( $assembled['path'] ) )->toBe( $file )
		and expect( $assembled['envelope']['filename'] )->toBe( 'report.pdf' );

	$store->discard( $token );

	expect( file_exists( $assembled['path'] ) )->toBeFalse();
} );

it( 'acknowledges a repeated piece without writing it twice', function () {
	$file  = str_repeat( 'x', 100 );
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( $file ), 100, 7 );

	$piece = substr( $file, 0, 50 );

	$first  = $store->append( $token, 0, $piece, hash( 'sha256', $piece ), 7 );
	$second = $store->append( $token, 0, $piece, hash( 'sha256', $piece ), 7 );

	// The retry is recognised as bytes the destination already holds, so the
	// running total does not move and nothing is appended a second time.
	expect( $first['received'] )->toBe( 50 )
		and expect( $second['received'] )->toBe( 50 )
		and expect( $second['complete'] )->toBeFalse();
} );

it( 'refuses a piece that disagrees with what it already stored', function () {
	$file  = str_repeat( 'x', 100 );
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( $file ), 100, 7 );

	$store->append( $token, 0, str_repeat( 'x', 50 ), hash( 'sha256', str_repeat( 'x', 50 ) ), 7 );

	$conflict = $store->append( $token, 0, str_repeat( 'y', 50 ), hash( 'sha256', str_repeat( 'y', 50 ) ), 7 );

	expect( $conflict )->toBeInstanceOf( WP_Error::class )
		and expect( $conflict->get_error_code() )->toBe( 'contentcopy_chunk_conflict' );
} );

it( 'refuses a corrupted piece and reports how far it got', function () {
	$file  = str_repeat( 'x', 100 );
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( $file ), 100, 7 );

	$corrupt = $store->append( $token, 0, str_repeat( 'x', 50 ), hash( 'sha256', 'something else' ), 7 );

	expect( $corrupt )->toBeInstanceOf( WP_Error::class )
		and expect( $corrupt->get_error_code() )->toBe( 'contentcopy_corrupt_chunk' );

	// Nothing was written, so a resume starts from the beginning.
	$gap = $store->append( $token, 50, str_repeat( 'x', 50 ), hash( 'sha256', str_repeat( 'x', 50 ) ), 7 );

	expect( $gap )->toBeInstanceOf( WP_Error::class )
		and expect( $gap->get_error_code() )->toBe( 'contentcopy_chunk_gap' )
		and expect( $gap->get_error_data()['received'] )->toBe( 0 );
} );

it( 'refuses a piece that would overflow the declared size', function () {
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( 'short' ), 10, 7 );

	$tooBig = $store->append( $token, 0, str_repeat( 'x', 20 ), '', 7 );

	expect( $tooBig )->toBeInstanceOf( WP_Error::class )
		and expect( $tooBig->get_error_code() )->toBe( 'contentcopy_bad_chunk' );
} );

it( 'keeps one user out of another user\'s upload', function () {
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( 'hello' ), 5, 7 );

	$stranger = $store->append( $token, 0, 'hello', hash( 'sha256', 'hello' ), 8 );

	expect( $stranger )->toBeInstanceOf( WP_Error::class )
		and expect( $stranger->get_error_code() )->toBe( 'contentcopy_forbidden' );
} );

it( 'refuses to assemble a file whose checksum does not match', function () {
	$store = new ChunkStore();

	// The envelope describes a different file from the one actually sent,
	// which is exactly what a silently dropped piece looks like.
	$token = $store->open(
		[
			'uuid'     => 'aaaaaaaa-bbbb-4ccc-8ddd-000000000001',
			'filename' => 'report.pdf',
			'sha256'   => hash( 'sha256', 'the real file' ),
		],
		5,
		7
	);

	$store->append( $token, 0, 'hello', hash( 'sha256', 'hello' ), 7 );

	$assembled = $store->assemble( $token, 7 );

	expect( $assembled )->toBeInstanceOf( WP_Error::class )
		and expect( $assembled->get_error_code() )->toBe( 'contentcopy_corrupt_file' );
} );

it( 'refuses to assemble before the whole file has arrived', function () {
	$file  = str_repeat( 'x', 100 );
	$store = new ChunkStore();
	$token = $store->open( contentcopy_envelope( $file ), 100, 7 );

	$store->append( $token, 0, str_repeat( 'x', 50 ), '', 7 );

	$assembled = $store->assemble( $token, 7 );

	expect( $assembled )->toBeInstanceOf( WP_Error::class )
		and expect( $assembled->get_error_code() )->toBe( 'contentcopy_incomplete_upload' );
} );

it( 'refuses a file larger than the destination will spool', function () {
	$store = new ChunkStore();

	$opened = $store->open( contentcopy_envelope( 'huge' ), 3 * GB_IN_BYTES, 7 );

	expect( $opened )->toBeInstanceOf( WP_Error::class )
		and expect( $opened->get_error_code() )->toBe( 'contentcopy_file_too_large' );
} );

it( 'does not recognise a token it never issued', function () {
	$store = new ChunkStore();

	$unknown = $store->append( str_repeat( 'a', 32 ), 0, 'hello', '', 7 );

	expect( $unknown )->toBeInstanceOf( WP_Error::class )
		and expect( $unknown->get_error_code() )->toBe( 'contentcopy_unknown_session' );

	// A token that is not even token-shaped is refused the same way, which
	// is what keeps a path out of the spool directory.
	$traversal = $store->append( '../../wp-config', 0, 'hello', '', 7 );

	expect( $traversal )->toBeInstanceOf( WP_Error::class )
		and expect( $traversal->get_error_code() )->toBe( 'contentcopy_unknown_session' );
} );

it( 'sizes pieces to fit under the destination request limit', function () {
	// A generous limit caps at the comfortable default rather than sending
	// enormous pieces; a tight one leaves headroom below it.
	expect( ChunkStore::chunkSize( 64 * MB_IN_BYTES ) )->toBe( 4 * MB_IN_BYTES )
		and expect( ChunkStore::chunkSize( 2 * MB_IN_BYTES ) )->toBe( (int) ( 2 * MB_IN_BYTES * 0.8 ) )
		and expect( ChunkStore::chunkSize( 1024 ) )->toBe( 64 * KB_IN_BYTES );
} );
