import {
	createRoot,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import {
	ActionRow,
	BlockShell,
	BlockShellHeader,
	BlockShellInner,
	FieldGroup,
	InlineStatus,
} from '@extrachill/components';
import {
	bookingPayload,
	createIdempotencyKey,
	submitInquiry,
} from './transport';

const SUPPORTED_TYPES = [
	'text',
	'textarea',
	'email',
	'phone',
	'url',
	'number',
	'select',
	'checkbox',
];

const EMPTY_VALUES = {
	artist_name: '',
	contact_name: '',
	contact_email: '',
	contact_phone: '',
	requested_space_key: '',
	requested_start_at: '',
	requested_end_at: '',
	message: '',
};

export function VenueBookingInquiryApp( {
	config,
	endpoint,
	headline,
	buttonLabel,
	showVenueProfile,
	container,
} ) {
	const prefix = useId().replaceAll( ':', '' );
	const [ values, setValues ] = useState( EMPTY_VALUES );
	const [ files, setFiles ] = useState( [] );
	const [ state, setState ] = useState( 'idle' );
	const [ errors, setErrors ] = useState( {} );
	const [ message, setMessage ] = useState( '' );
	const [ receipt, setReceipt ] = useState( null );
	const [ retryAfter, setRetryAfter ] = useState( 0 );
	const keyRef = useRef( createIdempotencyKey() );
	const attemptedRef = useRef( false );
	const errorSummaryRef = useRef();
	const successRef = useRef();
	const fileInputRef = useRef();

	const supported = config.intake_fields.every( ( field ) =>
		SUPPORTED_TYPES.includes( field.type )
	);

	useEffect( () => {
		if ( retryAfter < 1 ) {
			return undefined;
		}
		const timer = window.setTimeout(
			() => setRetryAfter( ( seconds ) => Math.max( 0, seconds - 1 ) ),
			1000
		);
		return () => window.clearTimeout( timer );
	}, [ retryAfter ] );

	useEffect( () => {
		if ( Object.keys( errors ).length ) {
			errorSummaryRef.current?.focus();
		}
	}, [ errors ] );

	useEffect( () => {
		if ( receipt ) {
			successRef.current?.focus();
		}
	}, [ receipt ] );

	const mutate = ( key, value ) => {
		if ( attemptedRef.current ) {
			keyRef.current = createIdempotencyKey();
			attemptedRef.current = false;
		}
		setReceipt( null );
		setErrors( {} );
		setState( 'idle' );
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
	};

	const changeFiles = ( selected ) => {
		if ( attemptedRef.current ) {
			keyRef.current = createIdempotencyKey();
			attemptedRef.current = false;
		}
		setFiles(
			Array.from( selected )
				.slice( 0, config.attachments.max_files )
				.map( ( file ) => ( {
					file,
					purpose: config.attachments.purposes[ 0 ],
				} ) )
		);
		setReceipt( null );
	};

	const changeFilePurpose = ( index, purpose ) => {
		if ( attemptedRef.current ) {
			keyRef.current = createIdempotencyKey();
			attemptedRef.current = false;
		}
		setFiles( ( current ) =>
			current.map( ( entry, entryIndex ) =>
				entryIndex === index ? { ...entry, purpose } : entry
			)
		);
		setReceipt( null );
	};

	const resetTurnstile = () => {
		const widget = container.querySelector( '.cf-turnstile' );
		if ( window.turnstile?.reset ) {
			try {
				window.turnstile.reset( widget || undefined );
			} catch {
				// The hidden token is still cleared below when the loader is unavailable.
			}
		}
		const token = container.querySelector(
			'input[name="cf-turnstile-response"]'
		);
		if ( token ) {
			token.value = '';
		}
	};

	const validate = () => {
		const next = {};
		[
			'artist_name',
			'contact_name',
			'contact_email',
			'requested_start_at',
			'message',
		].forEach( ( key ) => {
			if ( ! values[ key ] ) {
				next[ key ] = 'This field is required.';
			}
		} );
		if (
			values.requested_end_at &&
			values.requested_end_at < values.requested_start_at
		) {
			next.requested_end_at =
				'The end must be after the requested start.';
		}
		config.intake_fields.forEach( ( field ) => {
			const value = values[ `question:${ field.key }` ];
			if ( field.required && ! value ) {
				next[ `question:${ field.key }` ] = 'This field is required.';
			}
		} );
		Object.entries( config.consents ).forEach( ( [ key, consent ] ) => {
			if ( consent.required && ! values[ `consent:${ key }` ] ) {
				next[ `consent:${ key }` ] = 'Consent is required to submit.';
			}
		} );
		return next;
	};

	const onSubmit = async ( event ) => {
		event.preventDefault();
		const validation = validate();
		if ( Object.keys( validation ).length ) {
			setErrors( validation );
			setState( 'recoverable_error' );
			return;
		}
		const tokenInput = container.querySelector(
			'input[name="cf-turnstile-response"]'
		);
		const token = tokenInput?.value?.trim() || '';
		if ( ! token ) {
			setErrors( {
				turnstile_response:
					'Complete the security check before submitting.',
			} );
			setState( 'recoverable_error' );
			return;
		}

		attemptedRef.current = true;
		setErrors( {} );
		setMessage( '' );
		setState( 'submitting' );
		try {
			const result = await submitInquiry(
				endpoint,
				bookingPayload( values, config, token, keyRef.current ),
				files
			);
			if ( ! result?.public_id || ! result?.submitted_at ) {
				throw new Error(
					'The booking service returned an invalid response.'
				);
			}
			setReceipt( Object.freeze( { ...result } ) );
			setState( 'success' );
		} catch ( error ) {
			const code = error?.code || '';
			const fieldErrors =
				error?.data?.fields || error?.data?.field_errors || {};
			if (
				code === 'public_write_rate_limited' ||
				error?.data?.status === 429
			) {
				setRetryAfter(
					Number.parseInt( error?.data?.retry_after, 10 ) || 60
				);
				setState( 'rate_limit' );
			} else if (
				code === 'booking_config_revision_conflict' ||
				code === 'booking_inquiry_stale_config'
			) {
				setState( 'stale_config' );
			} else if (
				code === 'booking_inquiry_admission_disabled' ||
				code === 'booking_inquiry_unavailable'
			) {
				setState( 'unavailable' );
			} else {
				setErrors( fieldErrors );
				setMessage(
					error?.message ||
						'The inquiry could not be submitted safely. Please try again.'
				);
				setState( 'recoverable_error' );
			}
		} finally {
			resetTurnstile();
			if ( files.length ) {
				setFiles( [] );
				if ( fileInputRef.current ) {
					fileInputRef.current.value = '';
				}
				setMessage( 'Please reselect attachments before retrying.' );
			}
		}
	};

	if ( ! supported ) {
		return (
			<BlockShell>
				<BlockShellInner maxWidth="narrow">
					<InlineStatus tone="error">
						This venue inquiry configuration is unavailable.
					</InlineStatus>
				</BlockShellInner>
			</BlockShell>
		);
	}

	if ( receipt ) {
		return (
			<BlockShell>
				<BlockShellInner maxWidth="narrow">
					<div
						ref={ successRef }
						tabIndex="-1"
						className="ec-venue-booking-inquiry__receipt"
					>
						<BlockShellHeader title="Inquiry received" />
						<InlineStatus tone="success">
							Your reference is{ ' ' }
							<strong>{ receipt.public_id }</strong>. Submitted{ ' ' }
							{ receipt.submitted_at }.
						</InlineStatus>
					</div>
				</BlockShellInner>
			</BlockShell>
		);
	}

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader title={ headline } />
				{ showVenueProfile && (
					<div className="ec-venue-booking-inquiry__venue">
						<h3>{ config.venue.name }</h3>
						{ config.venue.description && (
							<p>{ config.venue.description }</p>
						) }
						{ ( config.venue.city || config.venue.state ) && (
							<p>
								{ [ config.venue.city, config.venue.state ]
									.filter( Boolean )
									.join( ', ' ) }
							</p>
						) }
					</div>
				) }
				{ Object.keys( errors ).length > 0 && (
					<div
						ref={ errorSummaryRef }
						tabIndex="-1"
						role="alert"
						className="ec-venue-booking-inquiry__errors"
					>
						<strong>Please review the highlighted fields.</strong>
						<ul>
							{ Object.values( errors ).map( ( error, index ) => (
								<li key={ index }>{ error }</li>
							) ) }
						</ul>
					</div>
				) }
				{ [ 'stale_config', 'unavailable', 'rate_limit' ].includes(
					state
				) && (
					<InlineStatus
						tone={ state === 'rate_limit' ? 'warning' : 'error' }
					>
						{ state === 'stale_config' &&
							'This venue changed its inquiry requirements. Reload before submitting.' }
						{ state === 'unavailable' &&
							'Booking intake is temporarily unavailable.' }
						{ state === 'rate_limit' &&
							`Please wait ${ retryAfter } seconds before trying again.` }
					</InlineStatus>
				) }
				<form
					onSubmit={ onSubmit }
					noValidate
					className="ec-venue-booking-inquiry__form"
				>
					<div className="ec-venue-booking-inquiry__grid">
						<Control
							prefix={ prefix }
							name="artist_name"
							label="Artist or project name"
							required
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
						<Control
							prefix={ prefix }
							name="contact_name"
							label="Contact name"
							required
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
						<Control
							prefix={ prefix }
							name="contact_email"
							label="Contact email"
							type="email"
							required
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
						<Control
							prefix={ prefix }
							name="contact_phone"
							label="Contact phone"
							type="tel"
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
						{ config.spaces.length > 0 && (
							<Control
								prefix={ prefix }
								name="requested_space_key"
								label="Requested space"
								type="select"
								options={ config.spaces.map( ( space ) => ( {
									value: space.key,
									label: space.name,
								} ) ) }
								values={ values }
								errors={ errors }
								mutate={ mutate }
							/>
						) }
						<Control
							prefix={ prefix }
							name="requested_start_at"
							label="Requested start"
							type="datetime-local"
							required
							min={ config.date_constraints.minimum_start }
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
						<Control
							prefix={ prefix }
							name="requested_end_at"
							label="Requested end"
							type="datetime-local"
							min={ config.date_constraints.minimum_start }
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
					</div>
					<Control
						prefix={ prefix }
						name="message"
						label="Tell the venue about the project and proposed show"
						type="textarea"
						required
						values={ values }
						errors={ errors }
						mutate={ mutate }
					/>
					{ config.intake_fields.map( ( field ) => (
						<ConfiguredControl
							key={ field.key }
							prefix={ prefix }
							field={ field }
							values={ values }
							errors={ errors }
							mutate={ mutate }
						/>
					) ) }
					{ Object.entries( config.consents ).map(
						( [ key, consent ] ) => (
							<Control
								key={ key }
								prefix={ prefix }
								name={ `consent:${ key }` }
								label={ consent.label }
								type="checkbox"
								required={ consent.required }
								values={ values }
								errors={ errors }
								mutate={ mutate }
							/>
						)
					) }
					{ config.attachments.enabled && (
						<FieldGroup
							label="Private booking attachments"
							help="Files are never added to the WordPress Media Library."
						>
							<input
								ref={ fileInputRef }
								type="file"
								multiple
								onChange={ ( event ) =>
									changeFiles( event.target.files )
								}
							/>
							{ files.map( ( item, index ) => (
								<div
									key={ `${ item.file.name }-${ index }` }
									className="ec-venue-booking-inquiry__file-purpose"
								>
									<label
										htmlFor={ `${ prefix }-file-purpose-${ index }` }
									>
										{ item.file.name }
									</label>
									<select
										id={ `${ prefix }-file-purpose-${ index }` }
										value={ item.purpose }
										onChange={ ( event ) =>
											changeFilePurpose(
												index,
												event.target.value
											)
										}
									>
										{ config.attachments.purposes.map(
											( purpose ) => (
												<option
													key={ purpose }
													value={ purpose }
												>
													{ purpose.replaceAll(
														'_',
														' '
													) }
												</option>
											)
										) }
									</select>
								</div>
							) ) }
						</FieldGroup>
					) }
					{ message && (
						<InlineStatus tone="warning">{ message }</InlineStatus>
					) }
					<div
						aria-live="polite"
						className="ec-venue-booking-inquiry__live"
					>
						{ state === 'submitting' ? 'Sending inquiry…' : '' }
					</div>
					<ActionRow>
						<button
							type="submit"
							className="button-1 button-large"
							disabled={
								state === 'submitting' ||
								retryAfter > 0 ||
								state === 'unavailable' ||
								state === 'stale_config'
							}
						>
							{ state === 'submitting'
								? 'Sending…'
								: buttonLabel }
						</button>
					</ActionRow>
				</form>
			</BlockShellInner>
		</BlockShell>
	);
}

function ConfiguredControl( { field, ...props } ) {
	const options = field.options.map( ( option ) => ( {
		value: option,
		label: option,
	} ) );
	return (
		<Control
			{ ...props }
			name={ `question:${ field.key }` }
			label={ field.label }
			type={ field.type }
			required={ field.required }
			options={ options }
		/>
	);
}

function Control( {
	prefix,
	name,
	label,
	type = 'text',
	required = false,
	options = [],
	min,
	values,
	errors,
	mutate,
} ) {
	const id = `${ prefix }-${ name.replaceAll( ':', '-' ) }`;
	const errorId = `${ id }-error`;
	const value = values[ name ] ?? ( type === 'checkbox' ? false : '' );
	let input;
	if ( type === 'textarea' ) {
		input = (
			<textarea
				id={ id }
				rows="5"
				value={ value }
				required={ required }
				aria-invalid={ Boolean( errors[ name ] ) }
				aria-describedby={ errors[ name ] ? errorId : undefined }
				onChange={ ( event ) => mutate( name, event.target.value ) }
			/>
		);
	} else if ( type === 'select' ) {
		input = (
			<select
				id={ id }
				value={ value }
				required={ required }
				aria-invalid={ Boolean( errors[ name ] ) }
				aria-describedby={ errors[ name ] ? errorId : undefined }
				onChange={ ( event ) => mutate( name, event.target.value ) }
			>
				<option value="">Select an option</option>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
		);
	} else if ( type === 'checkbox' ) {
		input = (
			<input
				id={ id }
				type="checkbox"
				checked={ Boolean( value ) }
				required={ required }
				aria-invalid={ Boolean( errors[ name ] ) }
				aria-describedby={ errors[ name ] ? errorId : undefined }
				onChange={ ( event ) => mutate( name, event.target.checked ) }
			/>
		);
	} else {
		input = (
			<input
				id={ id }
				type={ type === 'phone' ? 'tel' : type }
				value={ value }
				min={ min }
				required={ required }
				aria-invalid={ Boolean( errors[ name ] ) }
				aria-describedby={ errors[ name ] ? errorId : undefined }
				onChange={ ( event ) => mutate( name, event.target.value ) }
			/>
		);
	}
	return (
		<FieldGroup
			htmlFor={ id }
			label={ label }
			required={ required }
			error={
				errors[ name ] && <span id={ errorId }>{ errors[ name ] }</span>
			}
		>
			{ input }
		</FieldGroup>
	);
}

export function initializeBookingInquiries() {
	document
		.querySelectorAll( '.ec-venue-booking-inquiry' )
		.forEach( ( container ) => {
			const mount = container.querySelector(
				'.ec-venue-booking-inquiry__app'
			);
			if ( ! mount || mount.dataset.initialized ) {
				return;
			}
			mount.dataset.initialized = 'true';
			mount.removeAttribute( 'aria-busy' );
			try {
				const config = JSON.parse( container.dataset.config );
				createRoot( mount ).render(
					<VenueBookingInquiryApp
						config={ config }
						endpoint={ container.dataset.endpoint }
						headline={ container.dataset.headline }
						buttonLabel={ container.dataset.buttonLabel }
						showVenueProfile={
							container.dataset.showVenueProfile === '1'
						}
						container={ container }
					/>
				);
			} catch {
				mount.textContent = 'This booking inquiry is unavailable.';
			}
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initializeBookingInquiries );
} else {
	initializeBookingInquiries();
}
