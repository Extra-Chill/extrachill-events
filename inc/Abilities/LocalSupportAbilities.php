<?php
/**
 * Private local support abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\LocalSupportService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers authenticated, non-REST local support contracts. */
class LocalSupportAbilities {

	/** @var LocalSupportService */
	private $service;

	public function __construct( ?LocalSupportService $service = null ) {
		$this->service = $service ? $service : new LocalSupportService();
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/** Register the private domain surface. */
	public function register(): void {
		$this->register_ability( 'open-local-support-request', __( 'Open Local Support Request', 'extrachill-events' ), $this->open_schema(), array( $this, 'open_request' ), false, true );
		$this->register_ability( 'get-local-support-request', __( 'Get Local Support Request', 'extrachill-events' ), $this->id_schema( 'request_id' ), array( $this, 'get_request' ), true, true );
		$this->register_ability( 'transition-local-support-request', __( 'Transition Local Support Request', 'extrachill-events' ), $this->transition_request_schema(), array( $this, 'transition_request' ), false, true );
		$this->register_ability( 'express-local-support-interest', __( 'Express Local Support Interest', 'extrachill-events' ), $this->express_schema(), array( $this, 'express_interest' ), false, true );
		$this->register_ability( 'transition-local-support-interest', __( 'Transition Local Support Interest', 'extrachill-events' ), $this->transition_interest_schema(), array( $this, 'transition_interest' ), false, true );
		$this->register_ability( 'set-local-support-contact-consent', __( 'Set Local Support Contact Consent', 'extrachill-events' ), $this->consent_schema(), array( $this, 'set_contact_consent' ), false, true );
		$this->register_ability(
			'list-local-support-interests',
			__( 'List Local Support Interests', 'extrachill-events' ),
			$this->list_schema(),
			array( $this, 'list_interests' ),
			true,
			true,
			array(
				'type'  => 'array',
				'items' => $this->interest_schema(),
			)
		);
	}

	/** Execute request creation. */
	public function open_request( array $input ) {
		return $this->service->open_request( $input, get_current_user_id() );
	}

	/** Execute organizer request read. */
	public function get_request( array $input ) {
		$identity = $this->identity( $input );
		return is_wp_error( $identity ) ? $identity : $this->service->get_request( (int) $input['request_id'], get_current_user_id(), $identity );
	}

	/** Execute request transition. */
	public function transition_request( array $input ) {
		$identity = $this->identity( $input );
		return is_wp_error( $identity ) ? $identity : $this->service->transition_request( (int) $input['request_id'], (string) $input['to_status'], (int) $input['expected_version'], (string) $input['idempotency_key'], get_current_user_id(), $identity );
	}

	/** Execute interest creation. */
	public function express_interest( array $input ) {
		return $this->service->express_interest( (int) $input['request_id'], (int) $input['artist_term_id'], (string) $input['idempotency_key'], get_current_user_id() );
	}

	/** Execute interest transition. */
	public function transition_interest( array $input ) {
		$identity = $this->identity( $input );
		return is_wp_error( $identity ) ? $identity : $this->service->transition_interest( (int) $input['interest_id'], (string) $input['to_status'], (int) $input['expected_version'], (string) $input['idempotency_key'], get_current_user_id(), $identity );
	}

	/** Execute request-scoped contact consent. */
	public function set_contact_consent( array $input ) {
		return $this->service->set_contact_consent( (int) $input['interest_id'], (bool) $input['granted'], (array) ( $input['contact'] ?? array() ), (array) ( $input['fields'] ?? array() ), (int) $input['expected_version'], (string) $input['idempotency_key'], get_current_user_id() );
	}

	/** Execute organizer shortlist read. */
	public function list_interests( array $input ) {
		$identity = $this->identity( $input );
		return is_wp_error( $identity ) ? $identity : $this->service->list_interests( (int) $input['request_id'], get_current_user_id(), (int) ( $input['limit'] ?? 100 ), $identity );
	}

	/** Require an authenticated actor; domain authorization remains in the service. */
	public function authenticated(): bool {
		return get_current_user_id() > 0;
	}

	/** Register one consistently private ability. */
	private function register_ability( string $slug, string $label, array $input, callable $callback, bool $read_only, bool $idempotent, ?array $output = null ): void {
		wp_register_ability(
			'extrachill-events/' . $slug,
			array(
				'label'               => $label,
				'description'         => __( 'Private event-scoped local support operation.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => $output ? $output : $this->record_schema(),
				'execute_callback'    => $callback,
				'permission_callback' => array( $this, 'authenticated' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => $read_only,
						'idempotent'  => $idempotent,
						'destructive' => ! $read_only,
					),
				),
			)
		);
	}

	private function open_schema(): array {
		return $this->object_schema(
			array(
				'event_id'              => $this->integer(),
				'booking_id'            => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'organizer_type'        => $this->identity_type(),
				'organizer_id'          => $this->integer(),
				'acting_organizer_type' => $this->identity_type(),
				'acting_organizer_id'   => $this->integer(),
				'idempotency_key'       => $this->key_schema(),
			),
			array( 'event_id', 'organizer_type', 'organizer_id', 'idempotency_key' )
		);
	}

	private function express_schema(): array {
		return $this->object_schema(
			array(
				'request_id'      => $this->integer(),
				'artist_term_id'  => $this->integer(),
				'idempotency_key' => $this->key_schema(),
			),
			array( 'request_id', 'artist_term_id', 'idempotency_key' )
		);
	}

	private function transition_request_schema(): array {
		return $this->object_schema(
			array(
				'request_id'            => $this->integer(),
				'to_status'             => array(
					'type' => 'string',
					'enum' => LocalSupportService::REQUEST_STATUSES,
				),
				'expected_version'      => $this->integer(),
				'idempotency_key'       => $this->key_schema(),
				'acting_organizer_type' => $this->identity_type(),
				'acting_organizer_id'   => $this->integer(),
			),
			array( 'request_id', 'to_status', 'expected_version', 'idempotency_key' )
		);
	}

	private function transition_interest_schema(): array {
		return $this->object_schema(
			array(
				'interest_id'           => $this->integer(),
				'to_status'             => array(
					'type' => 'string',
					'enum' => LocalSupportService::INTEREST_STATUSES,
				),
				'expected_version'      => $this->integer(),
				'idempotency_key'       => $this->key_schema(),
				'acting_organizer_type' => $this->identity_type(),
				'acting_organizer_id'   => $this->integer(),
			),
			array( 'interest_id', 'to_status', 'expected_version', 'idempotency_key' )
		);
	}

	private function consent_schema(): array {
		return $this->object_schema(
			array(
				'interest_id'      => $this->integer(),
				'granted'          => array( 'type' => 'boolean' ),
				'fields'           => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'name', 'email', 'phone' ),
					),
					'uniqueItems' => true,
				),
				'contact'          => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'  => array(
							'type'      => 'string',
							'maxLength' => 255,
						),
						'email' => array(
							'type'      => 'string',
							'format'    => 'email',
							'maxLength' => 255,
						),
						'phone' => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
					),
					'additionalProperties' => false,
				),
				'expected_version' => $this->integer(),
				'idempotency_key'  => $this->key_schema(),
			),
			array( 'interest_id', 'granted', 'expected_version', 'idempotency_key' )
		);
	}

	private function list_schema(): array {
		return $this->object_schema(
			array(
				'request_id'            => $this->integer(),
				'limit'                 => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 100,
				),
				'acting_organizer_type' => $this->identity_type(),
				'acting_organizer_id'   => $this->integer(),
			),
			array( 'request_id' )
		);
	}

	private function id_schema( string $field ): array {
		return $this->object_schema(
			array(
				$field                  => $this->integer(),
				'acting_organizer_type' => $this->identity_type(),
				'acting_organizer_id'   => $this->integer(),
			),
			array( $field )
		);
	}

	private function object_schema( array $properties, array $required ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		return $schema;
	}

	private function integer(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	private function key_schema(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 191,
		);
	}

	private function identity_type(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[a-z][a-z0-9_-]{0,31}$',
		);
	}

	private function identity( array $input ) {
		$has_type = array_key_exists( 'acting_organizer_type', $input );
		$has_id   = array_key_exists( 'acting_organizer_id', $input );
		if ( ! $has_type && ! $has_id ) {
			return null;
		}
		if ( $has_type !== $has_id ) {
			return new \WP_Error( 'local_support_organizer_identity_incomplete', __( 'Both organizer identity fields are required together.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$type = sanitize_key( (string) $input['acting_organizer_type'] );
		$id   = absint( $input['acting_organizer_id'] );
		if ( '' === $type || $id < 1 ) {
			return new \WP_Error( 'local_support_organizer_identity_invalid', __( 'A valid organizer identity is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'type' => $type,
			'id'   => $id,
		);
	}

	private function record_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => true,
		);
	}

	private function interest_schema(): array {
		return $this->record_schema();
	}
}
