import metadata from '../block.json';

describe( 'venue booking inquiry block metadata', () => {
	it( 'registers a dynamic application block with canonical placement attributes only', () => {
		expect( metadata.name ).toBe( 'extrachill/venue-booking-inquiry' );
		expect( metadata.render ).toBe( 'file:./render.php' );
		expect( Object.keys( metadata.attributes ) ).toEqual( [
			'venueId',
			'headline',
			'buttonLabel',
			'showVenueProfile',
		] );
		expect(
			Object.keys( metadata.attributes ).filter( ( key ) =>
				[
					'userId',
					'email',
					'files',
					'token',
					'receipt',
					'idempotencyKey',
				].includes( key )
			)
		).toEqual( [] );
	} );
} );
