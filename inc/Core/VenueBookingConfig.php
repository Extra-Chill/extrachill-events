<?php
/**
 * Venue booking configuration service.
 *
 * @package ExtraChillEvents\Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.Missing -- Concise internal helpers are typed and named by purpose.
namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates one versioned termmeta document on canonical venue terms. */
class VenueBookingConfig {

	public const META_KEY = '_extrachill_booking_config';
	public const VERSION  = 1;

	/** Return normalized config for a venue. */
	public function get( int $venue_term_id ): array {
		$stored = get_term_meta( $venue_term_id, self::META_KEY, true );
		$result = $this->normalize( is_array( $stored ) ? $stored : array() );
		return is_wp_error( $result ) ? $this->defaults() : $result;
	}

	/** Validate and replace a venue's bounded booking config document. */
	public function save( int $venue_term_id, array $config ) {
		$venue = get_term( $venue_term_id, 'venue' );
		if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_booking_config_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
		}
		$normalized = $this->normalize( $config );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$result = update_term_meta( $venue_term_id, self::META_KEY, $normalized );
		return false === $result ? new \WP_Error( 'booking_config_save_failed', __( 'The venue booking configuration could not be saved.', 'extrachill-events' ) ) : $normalized;
	}

	/** Normalize and validate the complete versioned contract. */
	public function normalize( array $config ) {
		$basis_points = isset( $config['default_deal']['revenue_share_basis_points'] ) ? (int) $config['default_deal']['revenue_share_basis_points'] : 0;
		if ( $basis_points < 0 || $basis_points > 10000 ) {
			return new \WP_Error( 'invalid_booking_revenue_share', __( 'Revenue share basis points must be between 0 and 10000.', 'extrachill-events' ) );
		}
		$basis         = sanitize_key( (string) ( $config['default_deal']['revenue_share_basis'] ?? 'gross_ticket_sales' ) );
		$allowed_basis = array( 'gross_ticket_sales', 'net_ticket_sales', 'door_receipts' );
		if ( ! in_array( $basis, $allowed_basis, true ) ) {
			return new \WP_Error( 'invalid_booking_revenue_basis', __( 'The revenue share basis is invalid.', 'extrachill-events' ) );
		}
		$spaces = $this->normalize_spaces( $config['spaces'] ?? array() );
		if ( is_wp_error( $spaces ) ) {
			return $spaces;
		}
		$fields = $this->normalize_intake_fields( $config['intake']['fields'] ?? array() );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$channels = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_slice( (array) ( $config['marketing_channels'] ?? array() ), 0, 20 ) ) ) ) );
		$hold_ttl = isset( $config['hold_ttl_minutes'] ) ? (int) $config['hold_ttl_minutes'] : 1440;
		if ( $hold_ttl < 5 || $hold_ttl > 10080 ) {
			return new \WP_Error( 'invalid_booking_hold_ttl', __( 'Hold TTL must be between 5 minutes and 7 days.', 'extrachill-events' ) );
		}
		return array(
			'version'                   => self::VERSION,
			'enabled'                   => ! empty( $config['enabled'] ),
			'intake'                    => array(
				'version' => max( 1, (int) ( $config['intake']['version'] ?? 1 ) ),
				'fields'  => $fields,
			),
			'spaces'                    => $spaces,
			'default_deal'              => array(
				'version'                    => max( 1, (int) ( $config['default_deal']['version'] ?? 1 ) ),
				'type'                       => mb_substr( sanitize_key( (string) ( $config['default_deal']['type'] ?? 'custom' ) ), 0, 32 ),
				'guarantee_cents'            => max( 0, (int) ( $config['default_deal']['guarantee_cents'] ?? 0 ) ),
				'revenue_share_basis_points' => $basis_points,
				'revenue_share_basis'        => $basis,
				'currency'                   => strtoupper( mb_substr( sanitize_text_field( (string) ( $config['default_deal']['currency'] ?? 'USD' ) ), 0, 3 ) ),
			),
			'ticket_provider_reference' => $this->nullable_text( $config['ticket_provider_reference'] ?? null, 191 ),
			'marketing_channels'        => $channels,
			'hold_ttl_minutes'          => $hold_ttl,
		);
	}

	/** Default disabled venue contract. */
	public function defaults(): array {
		return array(
			'version'                   => self::VERSION,
			'enabled'                   => false,
			'intake'                    => array(
				'version' => 1,
				'fields'  => array(),
			),
			'spaces'                    => array(),
			'default_deal'              => array(
				'version'                    => 1,
				'type'                       => 'custom',
				'guarantee_cents'            => 0,
				'revenue_share_basis_points' => 0,
				'revenue_share_basis'        => 'gross_ticket_sales',
				'currency'                   => 'USD',
			),
			'ticket_provider_reference' => null,
			'marketing_channels'        => array(),
			'hold_ttl_minutes'          => 1440,
		);
	}

	private function normalize_spaces( $spaces ) {
		if ( ! is_array( $spaces ) || count( $spaces ) > 50 ) {
			return new \WP_Error( 'invalid_booking_spaces', __( 'Venue spaces must be an array of at most 50 items.', 'extrachill-events' ) );
		}
		$normalized = array();
		$seen       = array();
		$default    = false;
		foreach ( $spaces as $space ) {
			$key  = sanitize_key( (string) ( $space['key'] ?? '' ) );
			$name = sanitize_text_field( (string) ( $space['name'] ?? '' ) );
			if ( '' === $key || '' === $name || isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'invalid_booking_space', __( 'Each venue space needs a unique key and name.', 'extrachill-events' ) );
			}
			$is_default = ! empty( $space['is_default'] );
			if ( $is_default && $default ) {
				return new \WP_Error( 'multiple_default_booking_spaces', __( 'Only one venue space may be the default.', 'extrachill-events' ) );
			}
			$default      = $default || $is_default;
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'        => mb_substr( $key, 0, 64 ),
				'name'       => mb_substr( $name, 0, 191 ),
				'is_default' => $is_default,
			);
		}
		if ( ! empty( $normalized ) && ! $default ) {
			$normalized[0]['is_default'] = true;
		}
		return $normalized;
	}

	private function normalize_intake_fields( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > 50 ) {
			return new \WP_Error( 'invalid_booking_intake', __( 'Intake fields must be an array of at most 50 items.', 'extrachill-events' ) );
		}
		$normalized = array();
		$seen       = array();
		$types      = array( 'text', 'textarea', 'email', 'phone', 'number', 'select', 'checkbox', 'url' );
		foreach ( $fields as $field ) {
			$key  = sanitize_key( (string) ( $field['key'] ?? '' ) );
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( '' === $key || isset( $seen[ $key ] ) || ! in_array( $type, $types, true ) ) {
				return new \WP_Error( 'invalid_booking_intake_field', __( 'Each intake field needs a unique key and supported type.', 'extrachill-events' ) );
			}
			$seen[ $key ] = true;
			$normalized[] = array(
				'key'      => mb_substr( $key, 0, 64 ),
				'label'    => mb_substr( sanitize_text_field( (string) ( $field['label'] ?? $key ) ), 0, 191 ),
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => array_values( array_slice( array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) ), 0, 100 ) ),
			);
		}
		return $normalized;
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}
}
