<?php
/** Tests for the /submit landing section (#959). */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) { return number_format( (float) $number ); }
}
if ( ! function_exists( 'extrachill_events_get_calendar_stats' ) ) {
	function extrachill_events_get_calendar_stats(): array { return $GLOBALS['submit_landing_stats'] ?? array(); }
}

require_once dirname( __DIR__, 3 ) . '/inc/core/submit-landing.php';

/** @covers ::extrachill_events_render_submit_landing */
final class SubmitLandingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['submit_landing_stats'] = array(
			'events'    => 33658,
			'venues'    => 4100,
			'locations' => 787,
		);
	}

	public function test_landing_states_value_steps_and_paths(): void {
		$html = extrachill_events_render_submit_landing( 'ec-event-form-1' );

		$this->assertStringContainsString( 'Get your show on the calendar', $html );
		$this->assertStringContainsString( 'Adding a show is free.', $html );
		$this->assertStringContainsString( 'What happens next', $html );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( 'go live right away', $html );
		$this->assertStringContainsString( 'Have a whole calendar or tour?', $html );
		$this->assertStringContainsString( 'Just one show?', $html );
	}

	public function test_reach_line_uses_calendar_stats(): void {
		$html = extrachill_events_render_submit_landing( 'ec-event-form-1' );

		$this->assertStringContainsString( '33,658 upcoming events across 787 cities and regions', $html );
	}

	public function test_reach_line_is_omitted_without_stats(): void {
		$GLOBALS['submit_landing_stats'] = array(
			'events'    => 0,
			'locations' => 0,
		);

		$this->assertSame( '', extrachill_events_submit_landing_reach() );
		$this->assertStringNotContainsString( 'upcoming events across', extrachill_events_render_submit_landing( 'ec-event-form-1' ) );
	}

	public function test_paths_link_in_page_and_are_tracked(): void {
		$html = extrachill_events_render_submit_landing( 'ec-event-form-7' );

		$this->assertStringContainsString( 'href="#' . EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR . '"', $html );
		$this->assertStringContainsString( 'href="#ec-event-form-7"', $html );
		$this->assertSame( 2, substr_count( $html, 'data-ec-track="events_submit_landing"' ) );
		$this->assertStringContainsString( 'data-ec-track-placement="import"', $html );
		$this->assertStringContainsString( 'data-ec-track-placement="manual"', $html );
	}

	public function test_block_render_template_wires_landing_and_anchor(): void {
		$render = (string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/event-submission/render.php' );
		$block  = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/event-submission/block.json' ), true );

		$this->assertTrue( $block['attributes']['landing']['default'] );
		$this->assertStringContainsString( 'extrachill_events_render_submit_landing( $form_id )', $render );
		$this->assertSame( 2, substr_count( $render, 'id="<?php echo esc_attr( $import_anchor ); ?>"' ), 'Both import panels (signed in and signed out) need the anchor id.' );
	}
}
