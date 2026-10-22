<?php
/**
 * Attendee-only show ratings: Extra Chill's policy on top of the
 * data-machine-events rating primitive.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- Test doubles for WordPress and extrachill-users functions.

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'ec_is_events_site' ) ) {
	/** Return the Events-site context for this fixture. */
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/** Events blog. */
	function get_current_blog_id() {
		return 7;
	}
}

if ( ! function_exists( 'ec_users_is_event_marked' ) ) {
	/**
	 * Attendance double backed by $GLOBALS['ec_test_attendance'].
	 *
	 * @param int $user_id  User ID.
	 * @param int $event_id Event ID.
	 * @param int $blog_id  Blog ID.
	 */
	function ec_users_is_event_marked( int $user_id, int $event_id, int $blog_id = 0 ): bool {
		return isset( $GLOBALS['ec_test_attendance'][ "$user_id:$event_id:$blog_id" ] );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/concert-tracking-integration.php';

class AttendeeOnlyRatingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_test_attendance'] = array( '5:100:7' => true );
	}

	public function test_attendee_can_rate(): void {
		$this->assertTrue( ec_events_require_attendance_to_rate( true, 5, 100 ) );
	}

	public function test_non_attendee_cannot_rate(): void {
		$this->assertFalse( ec_events_require_attendance_to_rate( true, 6, 100 ) );
		$this->assertFalse( ec_events_require_attendance_to_rate( true, 5, 101 ) );
	}

	public function test_primitive_denial_is_never_overridden(): void {
		$this->assertFalse( ec_events_require_attendance_to_rate( false, 5, 100 ) );
	}

	public function test_gate_message_points_to_i_was_there(): void {
		$this->assertStringContainsString( 'I Was There', ec_events_rating_gate_message( 'default' ) );
	}

	public function test_hooks_are_registered(): void {
		$registered = static function ( string $hook, string $callback ): bool {
			foreach ( $GLOBALS['ec_test_filters'][ $hook ] ?? array() as $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( $callback === $entry[0] ) {
						return true;
					}
				}
			}
			return false;
		};

		$this->assertTrue( $registered( 'data_machine_events_can_rate_event', 'ec_events_require_attendance_to_rate' ) );
		$this->assertTrue( $registered( 'data_machine_events_rating_gate_message', 'ec_events_rating_gate_message' ) );
	}
}
