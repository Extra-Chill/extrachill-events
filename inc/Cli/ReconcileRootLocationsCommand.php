<?php
/**
 * Dry-run-first repair for qualified root location terms.
 *
 * Handles two root-pollution classes (#854):
 *  1. Qualified "City, Subdivision" duplicates — matched by
 *     QualifiedRootLocation and repaired with a verified redirect.
 *  2. Venue-named and unqualified city roots (e.g. "The Abbey",
 *     "Hamburg") — classified here, repaired by resolving every attached
 *     event's canonical location from its own venue metadata, then
 *     deleting the polluted root only when no event is orphaned.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifiedRootLocation;
use ExtraChillEvents\Core\RootLocationRepair;

defined( 'ABSPATH' ) || exit;

/** Reports and optionally repairs root location terms that violate the canonical hierarchy. */
final class ReconcileRootLocationsCommand {

	/**
	 * Report or reconcile unsafe root location terms.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Move relationships, create and verify redirects, and delete safe duplicates. Requires an administrator user context. Default is dry-run.
	 *
	 * [--format=<format>]
	 * : Output format: table, json, or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com
	 *     wp extrachill events locations reconcile-roots --url=events.extrachill.com --user=<administrator-login-or-id> --apply
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered. Use --url=events.extrachill.com.' );
			return;
		}

		$apply = ! empty( $assoc_args['apply'] );
		if ( $apply ) {
			$this->assert_apply_ready();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( $terms->get_error_message() );
			return;
		}

		$repair = $apply ? $this->repair_service() : null;
		$rows   = array();

		foreach ( $terms as $term ) {
			$match = QualifiedRootLocation::match( $term, $terms );

			if ( 'safe_match' === $match['status'] ) {
				$status = $match['status'];
				$reason = $match['reason'];
				if ( $apply ) {
					$result = $repair->repair( $term, $match['canonical'] );
					$status = $result['status'];
					$reason = $result['reason'];
				} else {
					$status = 'would_reconcile';
				}

				$rows[] = array(
					'candidate_id'  => (int) $term->term_id,
					'candidate'     => $term->name,
					'canonical_id'  => $match['canonical'] ? (int) $match['canonical']->term_id : '',
					'canonical'     => $match['canonical'] ? $match['canonical']->name : '',
					'relationships' => (int) $term->count,
					'status'        => $status,
					'reason'        => $reason,
				);
				continue;
			}

			if ( 'not_candidate' !== $match['status'] && 'unresolved' !== $match['status'] ) {
				$rows[] = array(
					'candidate_id'  => (int) $term->term_id,
					'candidate'     => $term->name,
					'canonical_id'  => '',
					'canonical'     => '',
					'relationships' => (int) $term->count,
					'status'        => $match['status'],
					'reason'        => $match['reason'],
				);
				continue;
			}

			// #854: classify venue-named and unqualified city roots the
			// qualified matcher cannot resolve. Structural roots
			// (continents, countries, parent nodes) produce no row.
			$row = $this->polluted_root_row( $term, $terms, $apply, $repair );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'candidate_id', 'candidate', 'canonical_id', 'canonical', 'relationships', 'status', 'reason' )
		);

		if ( ! $apply ) {
			\WP_CLI::log( 'Dry run: no redirects, relationships, or terms changed. Re-run with --apply only after reviewing every row.' );
		}
	}

	/**
	 * Build the report/repair row for one polluted root candidate.
	 *
	 * Returns null for structural roots: anything with children, any
	 * continent, and any known country display name. Venue-named and
	 * unqualified city roots are classified, and their attached events are
	 * resolved to canonical locations derived from each event's own venue
	 * metadata. A root is only deleted when every attached event resolves
	 * (and, on apply, is reassigned and verified) — a root term is never
	 * left orphaning events, and an event is never pointed back at the
	 * polluted root being repaired.
	 *
	 * @param object                 $term    Root candidate.
	 * @param array<object>          $terms   All location terms.
	 * @param bool                   $apply   Whether this is an apply run.
	 * @param RootLocationRepair|null $repair Repair service (apply only).
	 * @return array|null Report row, or null when the root is structural.
	 */
	public function polluted_root_row( object $term, array $terms, bool $apply, ?RootLocationRepair $repair ): ?array {
		if ( 0 !== (int) ( $term->parent ?? 0 ) ) {
			return null;
		}

		foreach ( $terms as $other ) {
			if ( (int) ( $other->parent ?? 0 ) === (int) $term->term_id ) {
				return null;
			}
		}

		if ( ! function_exists( 'extrachill_events_is_structural_location_root_name' )
			|| extrachill_events_is_structural_location_root_name( (string) $term->name ) ) {
			return null;
		}

		$row = array(
			'candidate_id'  => (int) $term->term_id,
			'candidate'     => $term->name,
			'canonical_id'  => '',
			'canonical'     => '',
			'relationships' => (int) $term->count,
			'status'        => 'skipped',
			'reason'        => 'classifier_unavailable',
		);

		if ( ! function_exists( 'extrachill_events_city_looks_like_venue' )
			|| ! function_exists( 'extrachill_events_resolve_location_term_for_venue_city' ) ) {
			return $row;
		}

		$class         = extrachill_events_city_looks_like_venue( (string) $term->name ) ? 'venue_named_root' : 'unqualified_city_root';
		$row['reason'] = $class;

		$event_ids = get_objects_in_term( (int) $term->term_id, 'location' );
		if ( is_wp_error( $event_ids ) ) {
			$event_ids = array();
		}
		$event_ids = array_map( 'intval', array_values( $event_ids ) );

		if ( array() === $event_ids ) {
			$row['status'] = 'not_candidate';
			$row['reason'] = $class . '_empty_prune_orphans_scope';
			return $row;
		}

		// Resolve every attached event against its own venue. In dry runs
		// term creation stays off; unresolved events that the create path
		// would still handle are predicted instead of resolved.
		$canonical_ids     = array();
		$predicted_creates = 0;
		$unresolved        = 0;
		foreach ( $event_ids as $event_id ) {
			$canonical = $this->resolve_event_location( $event_id, $apply );
			if ( $canonical instanceof \WP_Term && (int) $canonical->term_id !== (int) $term->term_id ) {
				$canonical_ids[ (int) $canonical->term_id ] = (int) $canonical->term_id;
				continue;
			}
			if ( ! $apply && $this->creation_would_succeed( $event_id ) ) {
				++$predicted_creates;
				continue;
			}
			++$unresolved;
		}

		if ( $unresolved > 0 ) {
			$row['status'] = 'skipped';
			$row['reason'] = $class . '_unresolved_events_block_deletion';
			return $row;
		}

		if ( 0 === count( $canonical_ids ) && $predicted_creates > 0 ) {
			$row['status'] = 'would_create_and_reconcile';
			$row['reason'] = $class . '_canonical_created_on_apply';
			return $row;
		}

		if ( 1 === count( $canonical_ids ) ) {
			$canonical_id = (int) reset( $canonical_ids );
			$canonical    = get_term( $canonical_id, 'location' );
			if ( ! $canonical instanceof \WP_Term ) {
				$row['status'] = 'failed';
				$row['reason'] = $class . '_canonical_term_unreadable';
				return $row;
			}
			$row['canonical_id'] = $canonical_id;
			$row['canonical']    = $canonical->name;

			if ( ! $apply ) {
				$row['status'] = $predicted_creates > 0 ? 'would_create_and_reconcile' : 'would_reconcile';
				$row['reason'] = $class . '_events_to_canonical_redirect_and_delete';
				return $row;
			}

			$result = $repair instanceof RootLocationRepair ? $repair->repair( $term, $canonical ) : array(
				'status' => 'failed',
				'reason' => 'repair_service_unavailable',
			);
			$this->reset_location_name_cache();
			$row['status'] = 'reconciled' === $result['status'] ? 'reconciled' : 'failed';
			$row['reason'] = $class . '_' . $result['reason'];
			return $row;
		}

		// Multiple canonicals: reassign each event to its own canonical and
		// leave the emptied root for prune-orphans. No deletion here.
		if ( ! $apply ) {
			$row['status'] = $predicted_creates > 0 ? 'would_create_and_reassign' : 'would_reassign_only';
			$row['reason'] = $class . '_multiple_canonicals_left_for_prune_orphans';
			return $row;
		}

		$moved = $this->reassign_events( $term, $event_ids );
		$this->reset_location_name_cache();
		$row['status'] = $moved ? 'reassigned_no_delete' : 'failed';
		$row['reason'] = $moved
			? $class . '_events_reassigned_left_for_prune_orphans'
			: $class . '_reassignment_failed_rolled_back';
		return $row;
	}

	/**
	 * Resolve one event's canonical location from its own venue metadata.
	 *
	 * @param int  $event_id Event post ID.
	 * @param bool $create   Allow the resolver to create the canonical chain.
	 * @return \WP_Term|null
	 */
	public function resolve_event_location( int $event_id, bool $create ): ?\WP_Term {
		if ( ! function_exists( 'extrachill_events_resolve_location_term_for_venue_city' ) ) {
			return null;
		}

		$venues = get_the_terms( $event_id, 'venue' );
		if ( ! $venues || is_wp_error( $venues ) ) {
			return null;
		}

		// $venues is a non-empty WP_Term[] here: the guard above rejects false,
		// WP_Error and the empty array, so reset() cannot return false.
		$venue = reset( $venues );

		return extrachill_events_resolve_location_term_for_venue_city(
			(string) get_term_meta( $venue->term_id, '_venue_city', true ),
			(string) get_term_meta( $venue->term_id, '_venue_state', true ),
			(string) get_term_meta( $venue->term_id, '_venue_zip', true ),
			(string) get_term_meta( $venue->term_id, '_venue_country', true ),
			$create
		);
	}

	/**
	 * Whether the create path would handle an event the match path could not.
	 *
	 * Dry-run prediction only — no terms are written. Mirrors the resolver's
	 * own gates: venue-shaped cities and unrecognised countries refuse; an
	 * international candidate must also validate positively against GeoNames.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	private function creation_would_succeed( int $event_id ): bool {
		if ( ! function_exists( 'extrachill_events_get_country_display_name' )
			|| ! function_exists( 'extrachill_events_normalize_country_name' )
			|| ! function_exists( 'extrachill_events_city_looks_like_venue' ) ) {
			return false;
		}

		$venues = get_the_terms( $event_id, 'venue' );
		if ( ! $venues || is_wp_error( $venues ) ) {
			return false;
		}

		// $venues is a non-empty WP_Term[] here: the guard above rejects false,
		// WP_Error and the empty array, so reset() cannot return false.
		$venue = reset( $venues );

		$city    = trim( (string) get_term_meta( $venue->term_id, '_venue_city', true ) );
		$country = trim( (string) get_term_meta( $venue->term_id, '_venue_country', true ) );
		if ( '' === $city || extrachill_events_city_looks_like_venue( $city ) ) {
			return false;
		}

		$country_name = extrachill_events_get_country_display_name( extrachill_events_normalize_country_name( $country ) );
		if ( '' === $country_name ) {
			return false;
		}

		if ( 'United States' === $country_name ) {
			return true;
		}

		return function_exists( 'extrachill_events_validate_city_in_country' )
			&& true === extrachill_events_validate_city_in_country( $city, $country_name );
	}

	/**
	 * Reassign every attached event to its own canonical location.
	 *
	 * Snapshots the original relationships and rolls back on any failure or
	 * verification miss, mirroring RootLocationRepair's compensation.
	 *
	 * @param object        $term      Polluted root term.
	 * @param array<int>    $event_ids Attached event post IDs.
	 * @return bool Whether every event ended at its canonical location.
	 */
	private function reassign_events( object $term, array $event_ids ): bool {
		$original = array();
		foreach ( $event_ids as $event_id ) {
			$terms = wp_get_object_terms( $event_id, 'location', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				return false;
			}
			$original[ $event_id ] = array_map( 'intval', (array) $terms );
		}

		$targets = array();
		foreach ( $event_ids as $event_id ) {
			$canonical = $this->resolve_event_location( $event_id, true );
			if ( ! $canonical instanceof \WP_Term || (int) $canonical->term_id === (int) $term->term_id ) {
				return $this->rollback_reassignment( $original );
			}
			$targets[ $event_id ] = (int) $canonical->term_id;
		}

		foreach ( $targets as $event_id => $canonical_id ) {
			$result = wp_set_object_terms( $event_id, array( $canonical_id ), 'location', false );
			if ( is_wp_error( $result ) ) {
				return $this->rollback_reassignment( $original );
			}
		}

		foreach ( $targets as $event_id => $canonical_id ) {
			$terms = wp_get_object_terms( $event_id, 'location', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || ! in_array( $canonical_id, array_map( 'intval', (array) $terms ), true ) ) {
				return $this->rollback_reassignment( $original );
			}
		}

		return true;
	}

	/**
	 * Restore original event locations after a failed reassignment.
	 *
	 * @param array<int, array<int>> $original Original relationships by event ID.
	 * @return bool False — the reassignment did not complete.
	 */
	private function rollback_reassignment( array $original ): bool {
		foreach ( $original as $event_id => $term_ids ) {
			wp_set_object_terms( $event_id, $term_ids, 'location', false );
		}
		return false;
	}

	/** Drop the resolver's per-request location-name cache after term writes. */
	private function reset_location_name_cache(): void {
		if ( function_exists( 'extrachill_events_get_location_terms_by_name' ) ) {
			extrachill_events_get_location_terms_by_name( true );
		}
	}

	/** Ensure apply mode can use every redirect ability before inspecting candidates. */
	private function assert_apply_ready(): void {
		$ability_names = array(
			'extrachill-seo/list-redirects',
			'extrachill-seo/add-redirect',
			'extrachill-seo/delete-redirect',
		);

		foreach ( $ability_names as $ability_name ) {
			$ability = wp_get_ability( $ability_name );
			if ( ! $ability ) {
				\WP_CLI::error( sprintf( 'Apply requires the %s ability, but it is unavailable.', $ability_name ) );
				return;
			}

			if ( true !== $ability->check_permissions() ) {
				\WP_CLI::error( 'Apply requires an authorized WordPress administrator context for redirect management. Re-run with --user=<administrator-login-or-id>.' );
				return;
			}
		}
	}

	/** Build the repair service with WordPress and SEO ability adapters. */
	private function repair_service(): RootLocationRepair {
		return new RootLocationRepair(
			array(
				'prepare_redirect'    => array( $this, 'prepare_redirect' ),
				'get_objects'         => static fn( int $term_id ) => get_objects_in_term( $term_id, 'location' ),
				'get_object_terms'    => static fn( int $object_id ) => wp_get_object_terms( $object_id, 'location', array( 'fields' => 'ids' ) ),
				'add_relationship'    => static fn( int $object_id, int $term_id ) => wp_set_object_terms( $object_id, array( $term_id ), 'location', true ),
				'remove_relationship' => static fn( int $object_id, int $term_id ) => wp_remove_object_terms( $object_id, array( $term_id ), 'location' ),
				'create_redirect'     => array( $this, 'create_redirect' ),
				'verify_redirect'     => array( $this, 'verify_redirect' ),
				'delete_redirect'     => array( $this, 'delete_redirect' ),
				'delete_term'         => static fn( int $term_id ) => wp_delete_term( $term_id, 'location' ),
				'term_exists'         => static function ( int $term_id ): bool {
					$term = get_term( $term_id, 'location' );
					return $term && ! is_wp_error( $term );
				},
			)
		);
	}

	/**
	 * Preflight redirect URLs, abilities, and existing-rule conflicts.
	 *
	 * @param object $duplicate Duplicate root location term.
	 * @param object $canonical Canonical hierarchy location term.
	 */
	public function prepare_redirect( object $duplicate, object $canonical ) {
		$from_link = get_term_link( $duplicate );
		$to_link   = get_term_link( $canonical );
		if ( is_wp_error( $from_link ) || is_wp_error( $to_link ) ) {
			return new \WP_Error( 'invalid_term_url', 'Could not resolve both term archive URLs.' );
		}

		$from = wp_parse_url( $from_link, PHP_URL_PATH );
		$to   = wp_parse_url( $to_link, PHP_URL_PATH );
		if ( ! is_string( $from ) || ! is_string( $to ) || '' === $from || '' === $to ) {
			return new \WP_Error( 'invalid_term_url', 'Could not resolve both term archive paths.' );
		}

		$context = array(
			'from'     => untrailingslashit( '/' . ltrim( $from, '/' ) ),
			'to'       => untrailingslashit( '/' . ltrim( $to, '/' ) ),
			'existing' => false,
		);
		$rules   = $this->redirect_rules( $context['from'] );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		foreach ( $rules as $rule ) {
			$rule_from = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'from_url' ), '/' ) );
			if ( $rule_from !== $context['from'] ) {
				continue;
			}
			$rule_to = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'to_url' ), '/' ) );
			if ( $rule_to !== $context['to'] || 301 !== (int) $this->rule_field( $rule, 'status_code' ) ) {
				return new \WP_Error( 'conflicting_redirect_exists', 'The legacy path already has a different active redirect.' );
			}
			$context['existing'] = true;
			return $context;
		}

		if ( ! wp_get_ability( 'extrachill-seo/add-redirect' ) || ! wp_get_ability( 'extrachill-seo/delete-redirect' ) ) {
			return new \WP_Error( 'redirect_abilities_unavailable', 'Redirect create/delete abilities are required before relationship mutation.' );
		}

		return $context;
	}

	/**
	 * Create the preflighted redirect and return its ID.
	 *
	 * @param array $context Redirect paths and preflight state.
	 */
	public function create_redirect( array $context ) {
		$ability = wp_get_ability( 'extrachill-seo/add-redirect' );
		$result  = $ability ? $ability->execute(
			array(
				'from_url'    => $context['from'],
				'to_url'      => $context['to'],
				'status_code' => 301,
				'note'        => 'Qualified root location reconciliation',
			)
		) : null;

		return is_array( $result ) && ! empty( $result['success'] ) ? (int) $result['id'] : new \WP_Error( 'redirect_creation_failed', 'The redirect ability did not create a rule.' );
	}

	/**
	 * Verify the exact active redirect after creation.
	 *
	 * @param array $context Redirect paths and preflight state.
	 */
	public function verify_redirect( array $context ): bool {
		$rules = $this->redirect_rules( $context['from'] );
		if ( is_wp_error( $rules ) ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			$from = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'from_url' ), '/' ) );
			$to   = untrailingslashit( '/' . ltrim( (string) $this->rule_field( $rule, 'to_url' ), '/' ) );
			if ( $from === $context['from'] && $to === $context['to'] && 301 === (int) $this->rule_field( $rule, 'status_code' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a redirect created by this run during compensation.
	 *
	 * @param int $redirect_id Redirect rule ID.
	 */
	public function delete_redirect( int $redirect_id ): bool {
		$ability = wp_get_ability( 'extrachill-seo/delete-redirect' );
		$result  = $ability ? $ability->execute( array( 'id' => $redirect_id ) ) : null;
		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Retrieve active redirect rules through the read-only SEO ability.
	 *
	 * @param string $from Legacy source path.
	 */
	private function redirect_rules( string $from ) {
		$ability = wp_get_ability( 'extrachill-seo/list-redirects' );
		if ( ! $ability ) {
			return new \WP_Error( 'redirect_lookup_unavailable', 'The redirect lookup ability is required before relationship mutation.' );
		}
		$result = $ability->execute(
			array(
				'search' => $from,
				'active' => 1,
				'limit'  => 100,
			)
		);
		return is_wp_error( $result ) || ! is_array( $result ) ? new \WP_Error( 'redirect_lookup_failed', 'Could not verify redirects.' ) : $result;
	}

	/**
	 * Read a redirect field from ability object/array output.
	 *
	 * @param object|array $rule  Redirect ability row.
	 * @param string       $field Field name.
	 */
	private function rule_field( $rule, string $field ) {
		return is_object( $rule ) ? ( $rule->{$field} ?? null ) : ( $rule[ $field ] ?? null );
	}
}
