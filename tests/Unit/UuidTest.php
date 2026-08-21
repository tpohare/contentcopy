<?php

declare(strict_types=1);

/**
 * Unit tests for ContentCopy\Uuid.
 *
 * The UUID is the Dundee-to-Destiny thread that ties every repeat push to the
 * same destination item. These tests pin identity handling: generation,
 * validation, destination-scoped last-push records, and the lookup contract.
 */

use ContentCopy\Uuid;

beforeEach( fn () => contentcopy_test_reset() );

it( 'generates a valid UUID on first use and keeps it afterwards', function () {
	$first  = Uuid::ensure( 10 );
	$second = Uuid::ensure( 10 );

	expect( $first )->not->toBe( '' )
		and expect( Uuid::isValid( $first ) )->toBeTrue()
		and expect( $second )->toBe( $first );
} );

it( 'rejects malformed identities', function () {
	expect( Uuid::isValid( 'not-a-uuid' ) )->toBeFalse()
		and expect( Uuid::isValid( '' ) )->toBeFalse()
		and expect( Uuid::isValid( 123 ) )->toBeFalse()
		and expect( Uuid::findLocal( 'not-a-uuid' ) )->toBe( 0 );
} );

it( 'keys last-push records per destination', function () {
	Uuid::setLastPush( 10, 'https://one.test/', [ 'destination_id' => 1 ] );
	Uuid::setLastPush( 10, 'https://two.test', [ 'destination_id' => 2 ] );

	expect( Uuid::getLastPush( 10, 'https://one.test' ) )->toBe( [ 'destination_id' => 1 ] )
		and expect( Uuid::getLastPush( 10, 'https://TWO.test/' ) )->toBe( [ 'destination_id' => 2 ] )
		and expect( Uuid::getLastPush( 10, 'https://unknown.test' ) )->toBeNull();
} );
