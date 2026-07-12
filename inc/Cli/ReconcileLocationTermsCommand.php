<?php
/**
 * Reconcile exact legacy root city duplicates into canonical hierarchy terms.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\LocationTermIntegrity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReconcileLocationTermsCommand {

	/**
	 * Diagnose location integrity and optionally reconcile safe exact matches.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Create redirects, move relationships, and delete matched duplicates. Default is dry-run.
	 *
	 * [--format=<format>]
	 * : table, json, or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events locations reconcile-integrity --url=events.extrachill.com
	 *     wp extrachill events locations reconcile-integrity --url=events.extrachill.com --apply
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! taxonomy_exists( 'location' ) ) {
			\WP_CLI::error( 'The location taxonomy is not registered. Use --url=events.extrachill.com.' );
		}

		$apply = ! empty( $assoc_args['apply'] );
		$terms = get_terms(
			array(
				'taxonomy'   => 'location',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			\WP_CLI::error( $terms->get_error_message() );
		}

		$rows = array();
		foreach ( $terms as $term ) {
			$match = LocationTermIntegrity::match_root_term( $term, $terms );
			if ( 'not_candidate' === $match['status'] ) {
				continue;
			}

			$canonical = $match['canonical'];
			$status    = $match['status'];
			if ( $apply && 'safe_match' === $status ) {
				$status = $this->reconcile( $term, $canonical );
			}

			$rows[] = array(
				'duplicate_id'  => (int) $term->term_id,
				'duplicate'     => $term->name,
				'canonical_id'  => $canonical ? (int) $canonical->term_id : '',
				'canonical'     => $canonical ? $canonical->name : '',
				'relationships' => (int) $term->count,
				'status'        => $apply ? $status : ( 'safe_match' === $status ? 'would_reconcile' : $status ),
				'reason'        => $match['reason'],
			);
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'duplicate_id', 'duplicate', 'canonical_id', 'canonical', 'relationships', 'status', 'reason' )
		);

		if ( ! $apply ) {
			\WP_CLI::log( 'Dry run: no redirects, relationships, or terms were changed. Re-run with --apply after review.' );
		}
	}

	private function reconcile( \WP_Term $duplicate, \WP_Term $canonical ): string {
		$redirect_ability = wp_get_ability( 'extrachill-seo/add-redirect' );
		if ( ! $redirect_ability ) {
			return 'blocked_redirect_ability_unavailable';
		}

		$from_link = get_term_link( $duplicate );
		$to_link   = get_term_link( $canonical );
		if ( is_wp_error( $from_link ) || is_wp_error( $to_link ) ) {
			return 'blocked_invalid_term_url';
		}

		$from = wp_parse_url( $from_link, PHP_URL_PATH );
		$to   = wp_parse_url( $to_link, PHP_URL_PATH );
		if ( ! is_string( $from ) || ! is_string( $to ) ) {
			return 'blocked_invalid_term_url';
		}

		$object_ids = get_objects_in_term( (int) $duplicate->term_id, 'location' );
		if ( is_wp_error( $object_ids ) ) {
			return 'failed_relationship_lookup';
		}

		// Add every canonical relationship before removing any legacy relationship.
		foreach ( $object_ids as $object_id ) {
			$added = wp_set_object_terms( (int) $object_id, array( (int) $canonical->term_id ), 'location', true );
			if ( is_wp_error( $added ) ) {
				return 'failed_relationship_add';
			}
		}

		foreach ( $object_ids as $object_id ) {
			$removed = wp_remove_object_terms( (int) $object_id, array( (int) $duplicate->term_id ), 'location' );
			if ( is_wp_error( $removed ) ) {
				return 'failed_relationship_remove';
			}
		}

		$redirect = $redirect_ability->execute(
			array(
				'from_url'    => $from,
				'to_url'      => $to,
				'status_code' => 301,
				'note'        => 'Location taxonomy integrity reconciliation',
			)
		);
		if ( is_wp_error( $redirect ) || empty( $redirect['success'] ) ) {
			return 'blocked_redirect_creation_failed';
		}

		$deleted = wp_delete_term( (int) $duplicate->term_id, 'location' );
		return true === $deleted ? 'reconciled' : 'failed_delete';
	}
}
