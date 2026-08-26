<?php
/**
 * External feature-flag dependency for the disposable WordPress integration test.
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

if ( ! function_exists( 'ec_feature_available' ) ) {
	/**
	 * Enable only the venue-booking feature exercised by this isolated test.
	 *
	 * @param string $feature Feature identifier.
	 * @param int    $user_id User identifier.
	 */
	function ec_feature_available( string $feature, int $user_id ): bool {
		return 'venue_booking' === $feature && $user_id > 0;
	}
}

if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
	/** Read the canonical reciprocal Artist roster in the disposable runtime. */
	function ec_get_artists_for_user( int $user_id ): array {
		return array_values( array_unique( array_map( 'absint', (array) get_user_meta( $user_id, '_artist_profile_ids', true ) ) ) );
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	/** Map all logical sites to the isolated disposable site. */
	function ec_get_blog_id( string $site ): ?int {
		return in_array( $site, array( 'main', 'artist', 'events' ), true ) ? get_current_blog_id() : null;
	}
}
