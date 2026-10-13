<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\Logging\MemoryHandler.
 *
 * The collector is the editor's window into a push: the panel shows the
 * `problems()` list and the full `records()` trail ships with the REST
 * response. These tests pin both — warnings surface, info stays in the
 * trail, and context is reduced to JSON-safe scalars.
 */

use ContentCopy\Logging\MemoryHandler;
use Monolog\Logger;

it( 'collects records and surfaces warnings and errors', function () {
	$collector = new MemoryHandler();
	$logger    = new Logger( 'contentcopy.test' );
	$logger->pushHandler( $collector );

	$logger->info( 'Uploading {file}.', [ 'file' => 'a.pdf' ] );
	$logger->warning( 'Something looked odd.' );
	$logger->error( 'Something broke.' );

	expect( $collector->records() )->toHaveCount( 3 )
		and expect( $collector->problems() )->toBe(
			[ 'Something looked odd.', 'Something broke.' ]
		);
} );

it( 'keeps context JSON-safe', function () {
	$collector = new MemoryHandler();
	$logger    = new Logger( 'contentcopy.test' );
	$logger->pushHandler( $collector );

	// Throwables collapse to their message; anything else records its type,
	// so var_dump-style object graphs can never leak into the REST response.
	$logger->info( 'With a failure.', [ 'error' => new RuntimeException( 'Boom.' ) ] );
	$logger->info( 'With an object.', [ 'error' => new WP_Error( 'x', 'Nope.' ) ] );

	$records = $collector->records();

	expect( $records[0]['context'] )->toBe( [ 'error' => 'Boom.' ] )
		and expect( $records[1]['context'] )->toBe( [ 'error' => 'WP_Error' ] );
} );
