<?php
/**
 * Cold network booking block tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;


/** Proves the network companion in a cold non-Events process. */
final class NetworkBookingBlockColdTest extends TestCase {
	/** Render without loading private booking-domain dependencies. */
	public function test_companion_only_render_has_bounded_dependencies_and_restores_main_context(): void {
		require_once dirname( __DIR__ ) . '/extrachill-events-network-blocks.php';

		$this->assertFalse( class_exists( '\ExtraChillEvents\Core\BookingRepository', false ) );
		extrachill_events_register_network_blocks();
		$this->assertCount( 1, $GLOBALS['ec_network_block_test']['registered'] );

		$attributes = array( 'venueId' => 1524 );
		ob_start();
		include dirname( __DIR__ ) . '/blocks/venue-booking-inquiry/render.php';
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cold Room', $output );
		$this->assertStringContainsString( 'https:\/\/extrachill.com\/wp-json\/extrachill\/v1\/venues\/1524\/booking-inquiries', $output );
		$this->assertTrue( $GLOBALS['ec_network_block_test']['turnstile_enqueued'] );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_network_block_test']['stack'] );
	}
}
