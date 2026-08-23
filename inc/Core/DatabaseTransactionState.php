<?php
/**
 * Portable MySQL/MariaDB transaction-state probe.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Silently resolves whether the current database session is in a transaction. */
final class DatabaseTransactionState {
	/** Return 0/1 when supported, otherwise null. */
	public static function probe(): ?int {
		global $wpdb;
		$can_suppress = method_exists( $wpdb, 'suppress_errors' );
		$previous     = $can_suppress ? $wpdb->suppress_errors( true ) : null;
		try {
			foreach ( array( 'SELECT @@session.in_transaction', 'SELECT @@in_transaction' ) as $query ) {
				try {
					$state = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed compatibility probes across supported transactional MySQL/MariaDB runtimes.
				} catch ( \Throwable $throwable ) {
					unset( $throwable );
					continue;
				}
				if ( '' === (string) $wpdb->last_error && in_array( (string) $state, array( '0', '1' ), true ) ) {
					return (int) $state;
				}
			}
			return null;
		} finally {
			if ( $can_suppress ) {
				$wpdb->suppress_errors( $previous );
			}
		}
	}
}
