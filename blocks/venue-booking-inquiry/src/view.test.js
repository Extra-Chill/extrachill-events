import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import { act } from 'react';
import { initializeBookingInquiries, VenueBookingInquiryApp } from './view';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@extrachill/components', () => {
	const React = require( 'react' );
	const Wrapper = ( { children } ) =>
		React.createElement( 'div', null, children );
	return {
		ActionRow: Wrapper,
		BlockShell: Wrapper,
		BlockShellInner: Wrapper,
		BlockShellHeader: ( { title } ) =>
			React.createElement( 'h2', null, title ),
		InlineStatus: Wrapper,
		FieldGroup: ( { children, error, htmlFor, label, required } ) =>
			React.createElement(
				'div',
				null,
				label &&
					React.createElement(
						'label',
						{ htmlFor },
						label,
						required ? ' *' : ''
					),
				children,
				error
			),
	};
} );

const config = {
	version: 1,
	revision: 7,
	enabled: true,
	venue: {
		term_id: 55,
		name: 'The Room',
		description: 'Independent venue.',
		city: 'Charleston',
		state: 'SC',
		website: '',
	},
	intake_fields: [
		{
			key: 'draw_history',
			label: 'Recent draw history',
			type: 'textarea',
			required: true,
			options: [],
		},
	],
	spaces: [ { key: 'main', name: 'Main Room', is_default: true } ],
	date_constraints: { minimum_start: '2026-07-26' },
	consents: {
		booking_privacy: {
			id: 'venue-booking-privacy',
			version: 1,
			label: 'I agree to the booking privacy policy.',
			required: true,
		},
		artist_onboarding: {
			id: 'artist-platform-onboarding',
			version: 1,
			label: 'Tell me about artist tools.',
			required: false,
		},
	},
	attachments: { enabled: false, max_files: 0, purposes: [] },
};

function setControl( container, label, value ) {
	const labels = Array.from( container.querySelectorAll( 'label' ) );
	const match = labels.find( ( item ) => item.textContent.includes( label ) );
	const control = match && container.querySelector( `#${ match.htmlFor }` );
	if ( ! control ) {
		throw new Error( `Missing control: ${ label }` );
	}
	act( () => {
		if ( control.type === 'checkbox' ) {
			if ( control.checked !== Boolean( value ) ) {
				control.click();
			}
		} else {
			let prototype = HTMLInputElement.prototype;
			if ( control.tagName === 'TEXTAREA' ) {
				prototype = HTMLTextAreaElement.prototype;
			} else if ( control.tagName === 'SELECT' ) {
				prototype = HTMLSelectElement.prototype;
			}
			const setter = Object.getOwnPropertyDescriptor(
				prototype,
				'value'
			).set;
			setter.call( control, value );
			control.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			control.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	} );
	return control;
}

function unmount( root ) {
	act( () => root.unmount() );
}

async function renderApp( projectedConfig = config ) {
	const container = document.createElement( 'div' );
	const mount = document.createElement( 'div' );
	const token = document.createElement( 'input' );
	token.name = 'cf-turnstile-response';
	container.append( mount, token );
	document.body.appendChild( container );
	const root = createRoot( mount );
	await act( async () => {
		root.render(
			<VenueBookingInquiryApp
				config={ projectedConfig }
				endpoint="https://events.example/wp-json/extrachill/v1/venues/55/booking-inquiries"
				headline="Booking Inquiry"
				buttonLabel="Send Inquiry"
				showVenueProfile
				container={ container }
			/>
		);
	} );
	return { container, mount, root, token };
}

function completeForm( container ) {
	setControl( container, 'Artist or project name', 'Test Band' );
	setControl( container, 'Contact name', 'Artist Person' );
	setControl( container, 'Contact email', 'artist@example.com' );
	setControl( container, 'Requested start', '2026-08-20T20:00' );
	setControl( container, 'Tell the venue', 'We would like to play.' );
	setControl( container, 'Recent draw history', '150 paid last visit.' );
	setControl( container, 'I agree', true );
}

async function submit( container, token ) {
	token.value = 'turnstile-token';
	await act( async () => {
		container
			.querySelector( 'form' )
			.dispatchEvent(
				new Event( 'submit', { bubbles: true, cancelable: true } )
			);
		await Promise.resolve();
	} );
}

describe( 'VenueBookingInquiryApp', () => {
	beforeAll( () => {
		global.IS_REACT_ACT_ENVIRONMENT = true;
	} );

	beforeEach( () => {
		document.body.innerHTML = '';
		apiFetch.mockReset();
		window.turnstile = { reset: jest.fn() };
	} );

	afterEach( () => {
		delete window.turnstile;
	} );

	it( 'renders ordered server fields and focuses grouped validation errors', async () => {
		const { container, root } = await renderApp();
		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );

		expect( container.textContent ).toContain( 'Recent draw history' );
		expect( container.querySelector( '[role="alert"]' ) ).toBe(
			document.activeElement
		);
		expect( apiFetch ).not.toHaveBeenCalled();
		unmount( root );
	} );

	it( 'submits no client identity or browser-persisted data for anonymous or authenticated sessions', async () => {
		const storageSpy = jest.spyOn( Storage.prototype, 'setItem' );
		window.dataLayer = { push: jest.fn() };
		apiFetch.mockResolvedValue( {
			public_id: 'receipt-1',
			venue_term_id: 55,
			submitted_at: '2026-07-26 12:00:00',
		} );
		const { container, root, token } = await renderApp();
		completeForm( container );
		await submit( container, token );

		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.data.user_id ).toBeUndefined();
		expect( request.data.submitter_user_id ).toBeUndefined();
		expect( request.data.intake.configuration_revision ).toBe( 7 );
		expect( request.data.intake.consents.booking_privacy ).toEqual( {
			id: 'venue-booking-privacy',
			version: 1,
			accepted: true,
		} );
		expect( storageSpy ).not.toHaveBeenCalled();
		expect( window.dataLayer.push ).not.toHaveBeenCalled();
		expect( container.textContent ).toContain( 'receipt-1' );
		expect(
			container.querySelector( '.ec-venue-booking-inquiry__receipt' )
		).toBe( document.activeElement );
		unmount( root );
		storageSpy.mockRestore();
	} );

	it( 'keeps an idempotency key for exact retries and rotates after mutation', async () => {
		apiFetch
			.mockRejectedValueOnce( {
				code: 'transport_error',
				message: 'Offline.',
			} )
			.mockRejectedValueOnce( {
				code: 'transport_error',
				message: 'Offline.',
			} )
			.mockResolvedValueOnce( {
				public_id: 'receipt-2',
				venue_term_id: 55,
				submitted_at: '2026-07-26 12:00:00',
			} );
		const { container, root, token } = await renderApp();
		completeForm( container );
		await submit( container, token );
		await submit( container, token );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.idempotency_key ).toBe(
			apiFetch.mock.calls[ 1 ][ 0 ].data.idempotency_key
		);

		setControl( container, 'Artist or project name', 'Changed Band' );
		await submit( container, token );
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data.idempotency_key ).not.toBe(
			apiFetch.mock.calls[ 1 ][ 0 ].data.idempotency_key
		);
		expect( container.textContent ).toContain( 'receipt-2' );
		unmount( root );
	} );

	it( 'resets single-use Turnstile tokens after attempts and rejects expired tokens', async () => {
		apiFetch.mockRejectedValue( {
			code: 'transport_error',
			message: 'private server details',
		} );
		const { container, root, token } = await renderApp();
		completeForm( container );
		await submit( container, token );
		expect( window.turnstile.reset ).toHaveBeenCalledTimes( 1 );
		expect( token.value ).toBe( '' );
		expect( container.textContent ).not.toContain(
			'private server details'
		);

		await act( async () => {
			container
				.querySelector( 'form' )
				.dispatchEvent(
					new Event( 'submit', { bubbles: true, cancelable: true } )
				);
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( container.textContent ).toContain(
			'Complete the security check'
		);
		unmount( root );
	} );

	it( 'honors rate-limit waits and stale configuration states', async () => {
		apiFetch.mockRejectedValue( {
			code: 'public_write_rate_limited',
			data: { status: 429, retry_after: 12 },
		} );
		const { container, root, token } = await renderApp();
		completeForm( container );
		await submit( container, token );
		expect( container.textContent ).toContain( 'wait 12 seconds' );
		expect(
			container.querySelector( 'button[type="submit"]' ).disabled
		).toBe( true );
		unmount( root );
	} );

	it( 'keeps attachments gated and requires reselection after a server attempt', async () => {
		apiFetch.mockRejectedValue( {
			code: 'booking_attachment_upload_failed',
		} );
		const enabled = {
			...config,
			attachments: {
				enabled: true,
				max_files: 2,
				purposes: [ 'press_release', 'technical_rider' ],
			},
		};
		const { container, root, token } = await renderApp( enabled );
		completeForm( container );
		const input = container.querySelector( 'input[type="file"]' );
		const file = new File( [ 'press' ], 'press.txt', {
			type: 'text/plain',
		} );
		Object.defineProperty( input, 'files', {
			value: [ file ],
			configurable: true,
		} );
		act( () =>
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) )
		);
		await submit( container, token );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].body ).toBeInstanceOf( FormData );
		expect( container.textContent ).toContain( 'reselect attachments' );
		expect( input.value ).toBe( '' );
		unmount( root );
	} );

	it( 'initializes multiple independent instances and fails closed on invalid config', async () => {
		document.body.innerHTML = `
			<div class="ec-venue-booking-inquiry" data-config='${ JSON.stringify(
				config
			) }' data-endpoint="/one" data-headline="One" data-button-label="Send" data-show-venue-profile="1"><div class="ec-venue-booking-inquiry__app"></div></div>
			<div class="ec-venue-booking-inquiry" data-config='${ JSON.stringify(
				config
			) }' data-endpoint="/two" data-headline="Two" data-button-label="Send" data-show-venue-profile="0"><div class="ec-venue-booking-inquiry__app"></div></div>
			<div class="ec-venue-booking-inquiry" data-config="private malformed data"><div class="ec-venue-booking-inquiry__app"></div></div>`;
		await act( async () => {
			initializeBookingInquiries();
			await Promise.resolve();
		} );

		expect( document.querySelectorAll( 'form' ) ).toHaveLength( 2 );
		expect( document.body.textContent ).toContain(
			'This booking inquiry is unavailable.'
		);
	} );
} );
