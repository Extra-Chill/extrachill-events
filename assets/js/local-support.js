( () => {
	const root = document.querySelector( '.ec-local-support' );
	if ( ! root ) {
		return;
	}

	root.querySelectorAll( 'form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', () => {
			form.setAttribute( 'aria-busy', 'true' );
			const button = form.querySelector( 'button[type="submit"]' );
			if ( button ) {
				button.disabled = true;
				button.textContent =
					button.dataset.loadingLabel || 'Updating...';
			}
		} );
	} );

	const organizer = root.querySelector( '[data-organizer-select]' );
	if ( organizer ) {
		organizer.addEventListener( 'change', () => {
			const [ type, id ] = organizer.value.split( ':' );
			root.querySelector( '[data-organizer-type]' ).value = type;
			root.querySelector( '[data-organizer-id]' ).value = id;
			root.querySelector( '[data-acting-organizer-type]' ).value = type;
			root.querySelector( '[data-acting-organizer-id]' ).value = id;
			root.querySelector( '[data-organizer-booking-id]' ).value =
				organizer.selectedOptions[ 0 ].dataset.bookingId || '0';
		} );
	}

	const consent = root.querySelector( '[data-consent-form]' );
	if ( consent ) {
		const updatePreview = () => {
			const rows = [
				...consent.querySelectorAll( '[data-consent-field]:checked' ),
			].map( ( checkbox ) => {
				const input = consent.querySelector(
					`[data-contact-field="${ checkbox.value }"]`
				);
				const label =
					checkbox.value[ 0 ].toUpperCase() +
					checkbox.value.slice( 1 );
				return `${ label }: ${ input.value.trim() || '(required)' }`;
			} );
			consent.querySelector( '[data-consent-preview]' ).textContent =
				rows.length
					? rows.join( ' | ' )
					: 'No contact fields selected.';
		};
		consent.addEventListener( 'input', updatePreview );
		updatePreview();
	}
} )();
