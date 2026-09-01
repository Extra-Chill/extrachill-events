/* global describe, expect, test */

/**
 * Internal dependencies
 */
import {
	availabilityErrorState,
	bookingDateInterval,
	buildAvailabilityPayload,
	buildPayload,
	clearDraft,
	DRAFT_TTL,
	DRAFT_VERSION,
	draftStorageKey,
	errorState,
	loadDraft,
	saveDraft,
} from './submission';

const config = {
	venue: { id: 42 },
	revision: 7,
	consent: { id: 'booking-privacy', version: 3 },
	fields: [ { key: 'draw', type: 'text' } ],
};
const values = {
	artistName: 'Test Band',
	contactName: 'Alex',
	contactEmail: 'alex@example.com',
	contactPhone: '',
	spaceKey: 'main-room',
	requestedDate: '2026-08-12',
	message: 'Routing through Charleston.',
	fields: { draw: '150' },
	consent: true,
	turnstileResponse: 'must-not-persist',
	idempotencyKey: 'must-not-persist',
	accessToken: 'must-not-persist',
	files: [ 'must-not-persist' ],
	receipt: { public_id: 'must-not-persist' },
};

const storage = ( failures = {} ) => {
	const data = new Map();
	return {
		get length() {
			return data.size;
		},
		key: ( index ) => Array.from( data.keys() )[ index ] || null,
		getItem: ( key ) => {
			if ( failures.read ) {
				throw new Error( 'read denied' );
			}
			return data.get( key ) || null;
		},
		setItem: ( key, value ) => {
			if ( failures.write ) {
				throw new Error( 'write denied' );
			}
			data.set( key, value );
		},
		removeItem: ( key ) => {
			if ( failures.remove ) {
				throw new Error( 'remove denied' );
			}
			data.delete( key );
		},
	};
};

describe( 'booking inquiry transport helpers', () => {
	test( 'maps a requested date to its complete local day', () => {
		expect( bookingDateInterval( values.requestedDate ) ).toEqual( {
			start: '2026-08-12 00:00:00',
			end: '2026-08-13 00:00:00',
		} );
		expect( bookingDateInterval( '2026-12-31' ) ).toEqual( {
			start: '2026-12-31 00:00:00',
			end: '2027-01-01 00:00:00',
		} );
	} );

	test( 'builds only contracted transport and intake values', () => {
		const payload = buildPayload(
			config,
			values,
			'request-key',
			'turnstile-token'
		);
		expect( payload ).toEqual( {
			venue: 42,
			idempotency_key: 'request-key',
			artist_name: 'Test Band',
			contact_name: 'Alex',
			contact_email: 'alex@example.com',
			contact_phone: null,
			requested_space_key: 'main-room',
			requested_start_at: '2026-08-12 00:00:00',
			requested_end_at: '2026-08-13 00:00:00',
			intake: {
				config_revision: 7,
				message: 'Routing through Charleston.',
				fields: { draw: '150' },
				consent: { id: 'booking-privacy', version: 3, accepted: true },
			},
			turnstile_response: 'turnstile-token',
		} );
		expect( payload ).not.toHaveProperty( 'user_id' );
		expect( payload ).not.toHaveProperty( 'attachments' );
	} );

	test( 'builds a privacy-safe date availability request', () => {
		expect( buildAvailabilityPayload( config, values ) ).toEqual( {
			venue: 42,
			requested_space_key: 'main-room',
			requested_start_at: '2026-08-12 00:00:00',
			requested_end_at: '2026-08-13 00:00:00',
		} );
	} );

	test( 'persists a strict allowlist to this tab only', () => {
		const tab = storage();
		const now = Date.now();
		expect( saveDraft( config, values, tab, now ) ).toBe( true );
		const raw = JSON.parse( tab.getItem( draftStorageKey( config ) ) );
		expect( raw.values ).toEqual( {
			artistName: 'Test Band',
			contactName: 'Alex',
			contactEmail: 'alex@example.com',
			contactPhone: '',
			spaceKey: 'main-room',
			requestedDate: '2026-08-12',
			message: 'Routing through Charleston.',
			fields: { draw: '150' },
		} );
		expect( JSON.stringify( raw ) ).not.toMatch(
			/consent|turnstile|idempotency|accessToken|files|receipt/
		);
		expect( raw ).not.toHaveProperty( 'scope' );

		const restored = loadDraft(
			config,
			{ ...values, consent: false },
			tab,
			now + 1000
		);
		expect( restored.values.artistName ).toBe( 'Test Band' );
		expect( restored.values.consent ).toBe( false );
		expect( restored.outcome ).toBe( 'restored' );
		expect( restored ).not.toHaveProperty( 'scope' );
	} );

	test( 'never writes an inquiry draft to persistent device storage', () => {
		const tab = storage();
		const device = storage();
		const originalLocal = Object.getOwnPropertyDescriptor(
			window,
			'localStorage'
		);
		Object.defineProperty( window, 'localStorage', {
			configurable: true,
			get: () => device,
		} );

		try {
			saveDraft( config, values, tab );
			expect( device.length ).toBe( 0 );
		} finally {
			if ( originalLocal ) {
				Object.defineProperty( window, 'localStorage', originalLocal );
			} else {
				delete window.localStorage;
			}
		}
	} );

	test( 'expires a draft once it outlives its time to live', () => {
		const tab = storage();
		const now = Date.now();
		saveDraft( config, values, tab, now );
		expect( tab.getItem( draftStorageKey( config ) ) ).not.toBeNull();

		const expired = loadDraft( config, values, tab, now + DRAFT_TTL + 1 );
		expect( expired.values ).toEqual( values );
		expect( expired.outcome ).toBe( 'expired' );
		expect( tab.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'clears incompatible schemas and stale configuration revisions', () => {
		const tab = storage();
		const oldConfig = { ...config, revision: 6 };
		saveDraft( oldConfig, values, tab );
		tab.setItem(
			draftStorageKey( config ),
			JSON.stringify( { version: 1, values: {} } )
		);
		const restored = loadDraft( config, values, tab );
		expect( restored.outcome ).toBe( 'incompatible' );
		expect( tab.getItem( draftStorageKey( oldConfig ) ) ).toBeNull();
		expect( tab.getItem( draftStorageKey( config ) ) ).toBeNull();

		tab.setItem(
			draftStorageKey( config ),
			JSON.stringify( {
				version: DRAFT_VERSION,
				venueId: config.venue.id,
				revision: config.revision,
				savedAt: 'invalid',
				values: { artistName: 'Must not restore' },
			} )
		);
		expect( loadDraft( config, values, tab ).outcome ).toBe(
			'incompatible'
		);
		expect( tab.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'rejects a draft written by the two-scope schema', () => {
		const tab = storage();
		tab.setItem(
			draftStorageKey( config ),
			JSON.stringify( {
				version: 2,
				venueId: config.venue.id,
				revision: config.revision,
				scope: 'device',
				savedAt: Date.now(),
				values: { artistName: 'Must not restore' },
			} )
		);

		expect( loadDraft( config, values, tab ).outcome ).toBe(
			'incompatible'
		);
		expect( tab.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'clears the draft after success or an explicit clear', () => {
		const tab = storage();
		tab.setItem( draftStorageKey( config ), 'draft' );
		expect( clearDraft( config, tab ) ).toBe( true );
		expect( tab.getItem( draftStorageKey( config ) ) ).toBeNull();
	} );

	test( 'reports denied storage without interrupting form behavior', () => {
		expect(
			loadDraft( config, values, storage( { read: true } ) ).outcome
		).toBe( 'read-failed' );
		expect( loadDraft( config, values, null ).outcome ).toBe(
			'read-failed'
		);
		expect( saveDraft( config, values, storage( { write: true } ) ) ).toBe(
			false
		);
		expect( saveDraft( config, values, null ) ).toBe( false );
		expect( clearDraft( config, storage( { remove: true } ) ) ).toBe(
			false
		);
		expect( clearDraft( config, null ) ).toBe( false );
	} );

	test( 'distinguishes safe retry, stale config, and reconciliation states', () => {
		const headers = new Headers( { 'Retry-After': '45' } );
		expect(
			errorState(
				{ status: 429, headers },
				{ code: 'public_write_rate_limited' }
			).message
		).toContain( '45 seconds' );
		expect(
			errorState(
				{ status: 409, headers },
				{ code: 'booking_inquiry_stale_config' }
			).retryable
		).toBe( false );
		expect(
			errorState(
				{ status: 503, headers },
				{ code: 'booking_inquiry_reconciliation_required' }
			).message
		).toContain( 'Do not resend' );
		expect(
			errorState(
				{ status: 409, headers },
				{ code: 'booking_inquiry_interval_unavailable' }
			).resetAvailability
		).toBe( true );
		expect(
			availabilityErrorState(
				{ status: 409 },
				{ message: 'That exact time is unavailable.' }
			).message
		).toBe( 'That exact time is unavailable.' );
	} );
} );
