<?php
/**
 * Venue booking inquiry server render.
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Core\VenueBookingConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$venue_id = absint( $attributes['venueId'] ?? 0 );
if ( ! $venue_id && is_tax( 'venue' ) ) {
	$queried  = get_queried_object();
	$venue_id = $queried instanceof WP_Term ? (int) $queried->term_id : 0;
}

$config = $venue_id ? ( new VenueBookingConfig() )->public_intake( $venue_id ) : new WP_Error( 'booking_venue_required' );
$ready  = is_array( $config ) && ! empty( $config['enabled'] );

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => 'ec-venue-booking-inquiry-shell',
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
	<?php if ( ! $ready ) : ?>
		<div class="ec-block-shell ec-block-shell--depth-0 ec-mobile-full-width-panel">
			<div class="ec-block-shell-inner ec-block-shell-inner--narrow">
				<div class="ec-inline-status ec-inline-status--<?php echo is_array( $config ) ? 'info' : 'error'; ?>" role="status">
					<?php echo esc_html( is_array( $config ) ? __( 'This venue is not accepting booking inquiries right now.', 'extrachill-events' ) : __( 'This booking inquiry is unavailable.', 'extrachill-events' ) ); ?>
				</div>
			</div>
		</div>
	<?php else : ?>
		<?php
		if ( function_exists( 'ec_enqueue_turnstile_script' ) ) {
			ec_enqueue_turnstile_script();
		}
		$instance_id = wp_unique_id( 'ec-booking-inquiry-' );
		$endpoint    = rest_url( sprintf( 'extrachill/v1/venues/%d/booking-inquiries', $venue_id ) );
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="ec-venue-booking-inquiry"
			data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
			data-endpoint="<?php echo esc_url( $endpoint ); ?>"
			data-headline="<?php echo esc_attr( $attributes['headline'] ?? __( 'Booking Inquiry', 'extrachill-events' ) ); ?>"
			data-button-label="<?php echo esc_attr( $attributes['buttonLabel'] ?? __( 'Send Inquiry', 'extrachill-events' ) ); ?>"
			data-show-venue-profile="<?php echo ! empty( $attributes['showVenueProfile'] ) ? '1' : '0'; ?>"
		>
			<div class="ec-venue-booking-inquiry__app" aria-busy="true"></div>
			<div class="ec-venue-booking-inquiry__turnstile">
				<?php
				if ( function_exists( 'ec_render_turnstile_widget' ) ) {
					echo wp_kses_post( ec_render_turnstile_widget( array( 'data-appearance' => 'always' ) ) );
				} else {
					esc_html_e( 'Security verification is unavailable.', 'extrachill-events' );
				}
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
