import { bookingPayload } from './transport';

describe( 'booking inquiry transport mapping', () => {
	it( 'matches the protected API schema without identity, files, or persistence metadata', () => {
		const payload = bookingPayload(
			{
				artist_name: 'Band',
				contact_name: 'Person',
				contact_email: 'person@example.com',
				contact_phone: '',
				requested_space_key: 'main',
				requested_start_at: '2026-08-20T20:00',
				requested_end_at: '',
				message: 'Hello',
				'question:draw': 100,
				'consent:booking_privacy': true,
			},
			{
				revision: 9,
				intake_fields: [ { key: 'draw', type: 'number' } ],
				consents: {
					booking_privacy: { id: 'privacy', version: 2 },
				},
			},
			'token',
			'exact-key'
		);

		expect( payload.idempotency_key ).toBe( 'exact-key' );
		expect( payload.requested_start_at ).toBe( '2026-08-20 20:00:00' );
		expect( payload.intake.answers.draw ).toBe( 100 );
		expect( payload.intake.consents.booking_privacy ).toEqual( {
			id: 'privacy',
			version: 2,
			accepted: true,
		} );
		expect( payload.user_id ).toBeUndefined();
		expect( payload.attachments ).toBeUndefined();
	} );
} );
