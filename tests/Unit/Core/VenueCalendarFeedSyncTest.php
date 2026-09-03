<?php
/**
 * Venue calendar feed sync scope tests.
 *
 * The three scope rules are the load-bearing correctness property of feed
 * sync, so they are asserted against the query arguments the runner actually
 * builds rather than through a full WordPress boot.
 *
 * A feed sync that could reach outside its own binding would be able to
 * unpublish a scraped event, overwrite a public submission awaiting review, or
 * mutate another venue's calendar. These tests exist to make that class of
 * regression fail loudly.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

require_once __DIR__ . '/../../../inc/Core/VenueCalendarFeed.php';

use ExtraChillEvents\Core\VenueCalendarFeed;

/**
 * Recording stubs for the WordPress functions the runner touches.
 *
 * Declared before the class under test is loaded so the runner binds to these
 * rather than to real WordPress.
 */
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$GLOBALS['ecf_get_posts_args'][] = $args;
		return $GLOBALS['ecf_get_posts_return'] ?? array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['ecf_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['ecf_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		return $GLOBALS['ecf_term_meta'][ $term_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['ecf_term_meta'][ $term_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		unset( $GLOBALS['ecf_term_meta'][ $term_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args ) {
		$GLOBALS['ecf_updated_posts'][] = $args;
		return $args['ID'];
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return 'mysql' === $type ? '2026-09-03 12:00:00' : time();
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {
		return true;
	}
}

if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
	function datamachine_get_event_dates( $post_id ) {
		return $GLOBALS['ecf_event_dates'][ $post_id ] ?? null;
	}
}

require_once __DIR__ . '/../../../inc/Core/VenueCalendarFeedSync.php';

use ExtraChillEvents\Core\VenueCalendarFeedSync;

class VenueCalendarFeedSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ecf_get_posts_args']   = array();
		$GLOBALS['ecf_get_posts_return'] = array();
		$GLOBALS['ecf_post_meta']        = array();
		$GLOBALS['ecf_term_meta']        = array();
		$GLOBALS['ecf_updated_posts']    = array();
		$GLOBALS['ecf_event_dates']      = array();
	}

	private function invoke( string $method, array $args ) {
		$reflection = new ReflectionMethod( VenueCalendarFeedSync::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	private function meta_pairs( array $query ): array {
		$pairs = array();
		foreach ( $query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) ) {
				$pairs[ $clause['key'] ] = $clause['value'] ?? null;
			}
		}
		return $pairs;
	}

	/**
	 * Scope rule 1. Without all three constraints together, a sync could adopt
	 * an event created by a scraper or by a different venue's feed.
	 */
	public function test_owned_event_lookup_is_scoped_by_source_identity_and_venue() {
		$this->invoke( 'find_owned_event', array( 1524, 'uid-abc::2026-09-10' ) );

		$args  = $GLOBALS['ecf_get_posts_args'][0];
		$pairs = $this->meta_pairs( $args['meta_query'] );

		$this->assertSame( 'venue_calendar_feed', $pairs['_datamachine_event_source'] );
		$this->assertSame( 'uid-abc::2026-09-10', $pairs['_datamachine_event_source_id'] );
		$this->assertSame( 1524, $pairs[ VenueCalendarFeedSync::META_FEED_VENUE ] );
		$this->assertSame( 'AND', $args['meta_query']['relation'] );
	}

	/**
	 * Scope rule 3. Public submissions land pending for human review; a feed
	 * sync adopting one would bypass that review entirely.
	 */
	public function test_owned_event_lookup_never_matches_pending_events() {
		$this->invoke( 'find_owned_event', array( 1524, 'uid-abc::2026-09-10' ) );

		$statuses = $GLOBALS['ecf_get_posts_args'][0]['post_status'];

		$this->assertNotContains( 'pending', $statuses );
	}

	public function test_cancellation_scan_is_scoped_to_this_venue_feed() {
		$this->invoke( 'cancel_absent_events', array( 1524, array() ) );

		$args  = $GLOBALS['ecf_get_posts_args'][0];
		$pairs = $this->meta_pairs( $args['meta_query'] );

		$this->assertSame( 'venue_calendar_feed', $pairs['_datamachine_event_source'] );
		$this->assertSame( 1524, $pairs[ VenueCalendarFeedSync::META_FEED_VENUE ] );
		$this->assertSame( 'publish', $args['post_status'] );
	}

	/**
	 * An event still present in the feed must never be cancelled.
	 */
	public function test_events_still_present_in_the_feed_are_left_alone() {
		$GLOBALS['ecf_get_posts_return'] = array( 900 );
		$GLOBALS['ecf_post_meta'][900]   = array( '_datamachine_event_source_id' => 'uid-keep::2026-09-10' );
		$GLOBALS['ecf_event_dates'][900] = (object) array( 'start_datetime' => '2026-12-01 20:00:00' );

		$cancelled = $this->invoke( 'cancel_absent_events', array( 1524, array( 'uid-keep::2026-09-10' ) ) );

		$this->assertSame( 0, $cancelled );
		$this->assertSame( array(), $GLOBALS['ecf_updated_posts'] );
	}

	/**
	 * A future event that disappeared upstream is drafted, never deleted. It
	 * may already have been shared, linked, or attended.
	 */
	public function test_absent_future_event_is_drafted_not_deleted() {
		$GLOBALS['ecf_get_posts_return'] = array( 901 );
		$GLOBALS['ecf_post_meta'][901]   = array( '_datamachine_event_source_id' => 'uid-gone::2026-12-01' );
		$GLOBALS['ecf_event_dates'][901] = (object) array( 'start_datetime' => '2026-12-01 20:00:00' );

		$cancelled = $this->invoke( 'cancel_absent_events', array( 1524, array() ) );

		$this->assertSame( 1, $cancelled );
		$this->assertSame(
			array(
				array(
					'ID'          => 901,
					'post_status' => 'draft',
				),
			),
			$GLOBALS['ecf_updated_posts']
		);
	}

	/**
	 * A past event ageing out of a rolling feed window is normal and must not
	 * be retroactively cancelled.
	 */
	public function test_absent_past_event_is_not_cancelled() {
		$GLOBALS['ecf_get_posts_return'] = array( 902 );
		$GLOBALS['ecf_post_meta'][902]   = array( '_datamachine_event_source_id' => 'uid-old::2026-01-01' );
		$GLOBALS['ecf_event_dates'][902] = (object) array( 'start_datetime' => '2026-01-01 20:00:00' );

		$cancelled = $this->invoke( 'cancel_absent_events', array( 1524, array() ) );

		$this->assertSame( 0, $cancelled );
		$this->assertSame( array(), $GLOBALS['ecf_updated_posts'] );
	}

	/**
	 * An event with no recorded identity cannot be proven absent, so it is
	 * left alone rather than guessed at.
	 */
	public function test_event_without_identity_is_left_alone() {
		$GLOBALS['ecf_get_posts_return'] = array( 903 );
		$GLOBALS['ecf_post_meta'][903]   = array( '_datamachine_event_source_id' => '' );
		$GLOBALS['ecf_event_dates'][903] = (object) array( 'start_datetime' => '2026-12-01 20:00:00' );

		$cancelled = $this->invoke( 'cancel_absent_events', array( 1524, array() ) );

		$this->assertSame( 0, $cancelled );
		$this->assertSame( array(), $GLOBALS['ecf_updated_posts'] );
	}

	/**
	 * Failures must accumulate and park the binding, so a dead feed is not
	 * polled forever.
	 */
	public function test_repeated_failures_park_the_binding_in_error() {
		for ( $i = 0; $i < VenueCalendarFeed::MAX_CONSECUTIVE_FAILURES; $i++ ) {
			$this->invoke( 'record_failure', array( 1524, 'Feed unreachable.' ) );
		}

		$this->assertSame(
			VenueCalendarFeed::MAX_CONSECUTIVE_FAILURES,
			$GLOBALS['ecf_term_meta'][1524][ VenueCalendarFeedSync::META_FAILURES ]
		);
		$this->assertSame(
			VenueCalendarFeed::STATUS_ERROR,
			$GLOBALS['ecf_term_meta'][1524][ VenueCalendarFeed::META_STATUS ]
		);
	}

	public function test_a_single_failure_does_not_park_the_binding() {
		$this->invoke( 'record_failure', array( 1524, 'Feed unreachable.' ) );

		$this->assertSame( 1, $GLOBALS['ecf_term_meta'][1524][ VenueCalendarFeedSync::META_FAILURES ] );
		$this->assertArrayNotHasKey(
			VenueCalendarFeed::META_STATUS,
			$GLOBALS['ecf_term_meta'][1524]
		);
	}
}
