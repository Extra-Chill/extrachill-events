import apiFetch from '@wordpress/api-fetch';

export function createIdempotencyKey() {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return `inquiry-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2 ) }`;
}

export function bookingPayload( values, config, token, idempotencyKey ) {
	return {
		idempotency_key: idempotencyKey,
		artist_name: values.artist_name,
		contact_name: values.contact_name,
		contact_email: values.contact_email,
		contact_phone: values.contact_phone || null,
		requested_space_key: values.requested_space_key || null,
		requested_start_at: normalizeDateTime( values.requested_start_at ),
		requested_end_at: normalizeDateTime( values.requested_end_at ),
		intake: {
			message: values.message,
			configuration_revision: config.revision,
			answers: Object.fromEntries(
				config.intake_fields.map( ( field ) => [
					field.key,
					values[ `question:${ field.key }` ] ??
						( field.type === 'checkbox' ? false : '' ),
				] )
			),
			consents: Object.fromEntries(
				Object.entries( config.consents ).map( ( [ key, consent ] ) => [
					key,
					{
						id: consent.id,
						version: consent.version,
						accepted: Boolean( values[ `consent:${ key }` ] ),
					},
				] )
			),
		},
		turnstile_response: token,
	};
}

export async function submitInquiry( endpoint, payload, files ) {
	if ( files.length ) {
		const body = new FormData();
		Object.entries( payload ).forEach( ( [ key, value ] ) => {
			body.set(
				key,
				typeof value === 'object' && value !== null
					? JSON.stringify( value )
					: value ?? ''
			);
		} );
		body.set(
			'attachment_purposes',
			JSON.stringify( files.map( ( item ) => item.purpose ) )
		);
		files.forEach( ( item ) =>
			body.append( 'attachments[]', item.file, item.file.name )
		);
		return apiFetch( { url: endpoint, method: 'POST', body } );
	}

	return apiFetch( { url: endpoint, method: 'POST', data: payload } );
}

function normalizeDateTime( value ) {
	return value ? `${ value.replace( 'T', ' ' ) }:00` : null;
}
