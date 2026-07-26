import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { venueId, headline, buttonLabel, showVenueProfile } = attributes;
	const blockProps = useBlockProps( {
		className: 'ec-venue-booking-inquiry-editor',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Inquiry Settings', 'extrachill-events' ) }
				>
					<TextControl
						label={ __(
							'Canonical venue term ID',
							'extrachill-events'
						) }
						type="number"
						min={ 1 }
						value={ venueId || '' }
						onChange={ ( value ) =>
							setAttributes( {
								venueId: Number.parseInt( value, 10 ) || 0,
							} )
						}
					/>
					<TextControl
						label={ __( 'Headline', 'extrachill-events' ) }
						value={ headline }
						onChange={ ( value ) =>
							setAttributes( { headline: value } )
						}
					/>
					<TextControl
						label={ __( 'Button label', 'extrachill-events' ) }
						value={ buttonLabel }
						onChange={ ( value ) =>
							setAttributes( { buttonLabel: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show venue profile',
							'extrachill-events'
						) }
						checked={ showVenueProfile }
						onChange={ ( value ) =>
							setAttributes( { showVenueProfile: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<strong>
					{ headline || __( 'Booking Inquiry', 'extrachill-events' ) }
				</strong>
				<p>
					{ venueId
						? __(
								'Venue fields and availability are resolved securely when rendered.',
								'extrachill-events'
						  )
						: __(
								'Choose a canonical venue term ID to enable this inquiry block.',
								'extrachill-events'
						  ) }
				</p>
				<button type="button" className="button-1" disabled>
					{ buttonLabel || __( 'Send Inquiry', 'extrachill-events' ) }
				</button>
			</div>
		</>
	);
}
