<?php
/**
 * Exact-match rules for detecting noncanonical root location terms.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LocationTermIntegrity {
	/**
	 * Reject a root duplicate when its exact canonical hierarchical city exists.
	 *
	 * @param string|\WP_Error $term     Proposed term name.
	 * @param string           $taxonomy Taxonomy name.
	 * @param array            $args     Insert arguments.
	 * @return string|\WP_Error
	 */
	public static function prevent_root_duplicate( $term, string $taxonomy, array $args ) {
		if ( 'location' !== $taxonomy || is_wp_error( $term ) || ! empty( $args['parent'] ) ) {
			return $term;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $term;
		}

		$candidate = (object) array(
			'term_id' => 0,
			'name'    => (string) $term,
			'parent'  => 0,
		);
		$match     = self::match_root_term( $candidate, $terms );
		if ( 'safe_match' !== $match['status'] ) {
			return $term;
		}

		return new \WP_Error(
			'location_root_duplicate',
			sprintf(
				'Use canonical location term #%d (%s) instead of creating a root duplicate.',
				(int) $match['canonical']->term_id,
				$match['canonical']->name
			)
		);
	}

	/**
	 * Match a root "City, ST" term to one canonical City child of that state.
	 *
	 * @param object        $root  Root term candidate.
	 * @param array<object> $terms All location terms.
	 * @return array{status:string,canonical:?object,reason:string}
	 */
	public static function match_root_term( object $root, array $terms ): array {
		if ( 0 !== (int) $root->parent ) {
			return self::result( 'not_candidate', null, 'term_is_not_root' );
		}

		if ( ! preg_match( '/^(.+),\s*([A-Za-z]{2})$/', trim( (string) $root->name ), $parts ) ) {
			return self::result( 'not_candidate', null, 'name_has_no_state_suffix' );
		}

		$city       = self::key( $parts[1] );
		$state_name = self::state_name( $parts[2] );
		if ( null === $state_name ) {
			return self::result( 'ambiguous', null, 'unknown_state_abbreviation' );
		}

		$states = array();
		foreach ( $terms as $term ) {
			if ( self::key( $term->name ) === self::key( $state_name ) ) {
				$states[] = (int) $term->term_id;
			}
		}

		$matches = array();
		foreach ( $terms as $term ) {
			if ( self::key( $term->name ) === $city && in_array( (int) $term->parent, $states, true ) ) {
				$matches[] = $term;
			}
		}

		if ( 1 === count( $matches ) ) {
			return self::result( 'safe_match', $matches[0], 'exact_city_and_state_parent' );
		}

		return self::result(
			empty( $matches ) ? 'unresolved' : 'ambiguous',
			null,
			empty( $matches ) ? 'no_exact_canonical_match' : 'multiple_exact_canonical_matches'
		);
	}

	private static function key( string $value ): string {
		return strtolower( trim( $value ) );
	}

	private static function state_name( string $abbreviation ): ?string {
		$abbreviations = explode( ' ', 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY' );
		$names         = explode( '|', 'Alabama|Alaska|Arizona|Arkansas|California|Colorado|Connecticut|Delaware|District of Columbia|Florida|Georgia|Hawaii|Idaho|Illinois|Indiana|Iowa|Kansas|Kentucky|Louisiana|Maine|Maryland|Massachusetts|Michigan|Minnesota|Mississippi|Missouri|Montana|Nebraska|Nevada|New Hampshire|New Jersey|New Mexico|New York|North Carolina|North Dakota|Ohio|Oklahoma|Oregon|Pennsylvania|Rhode Island|South Carolina|South Dakota|Tennessee|Texas|Utah|Vermont|Virginia|Washington|West Virginia|Wisconsin|Wyoming' );
		$states        = array_combine( $abbreviations, $names );

		return $states[ strtoupper( trim( $abbreviation ) ) ] ?? null;
	}

	private static function result( string $status, ?object $canonical, string $reason ): array {
		return array(
			'status'    => $status,
			'canonical' => $canonical,
			'reason'    => $reason,
		);
	}
}

add_filter( 'pre_insert_term', array( LocationTermIntegrity::class, 'prevent_root_duplicate' ), 10, 3 );
