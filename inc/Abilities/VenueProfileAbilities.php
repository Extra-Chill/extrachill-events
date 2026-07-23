<?php
/**
 * Authorized canonical venue profile abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueProfile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers bounded venue profile read and complete-replacement operations. */
class VenueProfileAbilities {

	/**
	 * Whether hooks were registered in this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Exact venue membership authorization service.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Canonical profile service.
	 *
	 * @var VenueProfile
	 */
	private $profiles;

	/**
	 * Construct the ability registrar.
	 *
	 * @param VenueProfile|null       $profiles      Optional profile service.
	 * @param VenueAuthorization|null $authorization Optional authorization service.
	 */
	public function __construct( ?VenueProfile $profiles = null, ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->profiles      = $profiles ? $profiles : new VenueProfile( $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register stable read and update contracts. */
	public function register(): void {
		$venue_property = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Canonical Events-site venue term ID.', 'extrachill-events' ),
		);

		wp_register_ability(
			'extrachill/get-venue-profile',
			array(
				'label'               => __( 'Get Venue Profile', 'extrachill-events' ),
				'description'         => __( 'Read the canonical member-facing profile for an authorized venue.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'venue_term_id' => $venue_property ),
					'required'             => array( 'venue_term_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->document_schema(),
				'execute_callback'    => array( $this, 'get_profile' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/update-venue-profile',
			array(
				'label'               => __( 'Update Venue Profile', 'extrachill-events' ),
				'description'         => __( 'Atomically replace the member-editable venue profile at an expected revision.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'     => $venue_property,
						'expected_revision' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'profile'           => $this->profile_schema(),
					),
					'required'             => array( 'venue_term_id', 'expected_revision', 'profile' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->document_schema(),
				'execute_callback'    => array( $this, 'update_profile' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	/**
	 * Authorize the established feature gate and exact active venue membership.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error Authorization result.
	 */
	public function can_access_venue( array $input ) {
		return $this->authorization->authorize(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			VenueAuthorization::ACTION_ACCESS_VENUE
		);
	}

	/**
	 * Read one authorized canonical venue profile.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Profile document or an error.
	 */
	public function get_profile( array $input ) {
		$venue_term_id = absint( $input['venue_term_id'] ?? 0 );
		$allowed       = $this->authorization->authorize( get_current_user_id(), $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return is_wp_error( $allowed ) ? $allowed : $this->profiles->get( $venue_term_id );
	}

	/**
	 * Replace one authorized canonical venue profile.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Updated profile document or an error.
	 */
	public function update_profile( array $input ) {
		return $this->profiles->update(
			absint( $input['venue_term_id'] ?? 0 ),
			(array) ( $input['profile'] ?? array() ),
			(int) ( $input['expected_revision'] ?? -1 ),
			get_current_user_id()
		);
	}

	/** Return the strict member-editable profile schema. */
	private function profile_schema(): array {
		$nullable_text = static function ( int $max_length ): array {
			return array(
				'type'      => 'string',
				'maxLength' => $max_length,
			);
		};
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'description' => $nullable_text( 10000 ),
				'address'     => $nullable_text( 255 ),
				'city'        => $nullable_text( 191 ),
				'state'       => $nullable_text( 100 ),
				'zip'         => $nullable_text( 32 ),
				'country'     => $nullable_text( 100 ),
				'phone'       => $nullable_text( 64 ),
				'website'     => array(
					'type'      => 'string',
					'maxLength' => 2048,
				),
				'capacity'    => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
					'maximum' => 1000000,
				),
			),
			'required'             => array( 'name', 'description', 'address', 'city', 'state', 'zip', 'country', 'phone', 'website', 'capacity' ),
			'additionalProperties' => false,
		);
	}

	/** Return the stable versioned output schema. */
	private function document_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version'            => array(
					'type' => 'integer',
					'enum' => array( VenueProfile::VERSION ),
				),
				'venue_term_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'revision'           => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'updated_by_user_id' => array( 'type' => array( 'integer', 'null' ) ),
				'updated_at'         => array( 'type' => array( 'string', 'null' ) ),
				'profile'            => $this->profile_schema(),
			),
			'required'             => array( 'version', 'venue_term_id', 'revision', 'updated_by_user_id', 'updated_at', 'profile' ),
			'additionalProperties' => false,
		);
	}
}
