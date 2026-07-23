<?php
/**
 * Venue booking event conversion ability.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the narrow idempotent booking-to-event contract. */
class VenueBookingEventAbilities {
	private static bool $registered = false;
	/** @var BookingEventConversionService */
	private $conversion;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?BookingEventConversionService $conversion = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->conversion    = $conversion ? $conversion : new BookingEventConversionService( $this->bookings, null, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/convert-booking-to-event',
			array(
				'label'               => __( 'Convert Booking to Event', 'extrachill-events' ),
				'description'         => __( 'Idempotently converts a confirmed venue booking into its canonical event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	public function execute( array $input ) {
		return $this->conversion->convert( (int) $input['booking_id'], (int) $input['expected_version'], get_current_user_id() );
	}

	/** Missing records deliberately use the same denial as inaccessible records. */
	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking ) ? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id', 'expected_version' ),
			'additionalProperties' => false,
		);
	}

	private function output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'        => array( 'type' => 'integer' ),
				'booking_version'   => array( 'type' => 'integer' ),
				'event_id'          => array( 'type' => 'integer' ),
				'event_url'         => array( 'type' => 'string' ),
				'event_action'      => array(
					'type' => 'string',
					'enum' => array( 'created', 'updated', 'no_change', 'existing' ),
				),
				'already_converted' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'booking_id', 'booking_version', 'event_id', 'event_url', 'event_action', 'already_converted' ),
			'additionalProperties' => false,
		);
	}
}
