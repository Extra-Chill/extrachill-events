<?php
/**
 * Tests for QualifyDigestAbilities renderers.
 *
 * Renderers are pure: in goes the gather_data() shape, out comes a string.
 * Tests feed seeded data structures and assert the rendered output
 * contains the expected sections and counts.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Abilities\QualifyDigestAbilities;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/digest-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/QualifyDigestAbilities.php';

class QualifyDigestAbilityTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function seed_data(): array {
		return array(
			'counts'             => array(
				'paused_total'        => 4,
				'resumed_total'       => 12,
				'new_qualified_total' => 18,
				'unsupported_total'   => 9,
				'stale_total'         => 1,
			),
			'paused_by_verdict'  => array(
				QualifyVerdict::EXTRACTION_GAP => 3,
				QualifyVerdict::BOT_BLOCKED    => 1,
			),
			'standing_inventory' => array(
				'active:daily'                             => 147,
				'paused:' . QualifyVerdict::EXTRACTION_GAP => 22,
				'paused:' . QualifyVerdict::BOT_BLOCKED    => 4,
				'paused:' . QualifyVerdict::COVERED_ELSEWHERE => 2,
			),
			'top_extraction_gap' => array(
				array(
					'hint'  => 'squarespace — no upcoming array',
					'count' => 8,
				),
				array(
					'hint'  => 'wordpress_generic — no Tribe plugin',
					'count' => 5,
				),
				array(
					'hint'  => 'wix — no events block',
					'count' => 3,
				),
			),
			'stale_flows'        => array(
				array(
					'flow_id'              => 99,
					'flow_name'            => 'Broken Venue',
					'paused_reason'        => QualifyVerdict::BOT_BLOCKED,
					'consecutive_failures' => 6,
				),
			),
		);
	}

	public function test_text_renderer_contains_summary_counts(): void {
		$ability = new QualifyDigestAbilities();
		$data    = $this->seed_data();
		$body    = $ability->render_text( $data, strtotime( '2025-05-18 00:00:00' ), strtotime( '2025-05-24 00:00:00' ) );

		$this->assertStringContainsString( 'EVENT CALENDAR QUALIFY DIGEST', $body );
		$this->assertStringContainsString( 'Paused this week:        4 flows', $body );
		$this->assertStringContainsString( 'Auto-resumed this week:  12 flows', $body );
		$this->assertStringContainsString( 'New venues qualified:    18', $body );
		$this->assertStringContainsString( 'Unsupported sources:     9', $body );
		$this->assertStringContainsString( 'Stale paused flows:      1', $body );
	}

	public function test_text_renderer_contains_top_3_fingerprints(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_text( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'squarespace — no upcoming array', $body );
		$this->assertStringContainsString( 'wordpress_generic — no Tribe plugin', $body );
		$this->assertStringContainsString( 'wix — no events block', $body );
	}

	public function test_text_renderer_surfaces_stale_flows(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_text( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'Stale paused flows — manual review recommended', $body );
		$this->assertStringContainsString( 'Broken Venue', $body );
		$this->assertStringContainsString( '6 failures', $body );
	}

	public function test_html_renderer_contains_all_sections(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_html( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( 'Event Calendar Qualify Digest', $body );
		$this->assertStringContainsString( '<h2>Summary</h2>', $body );
		$this->assertStringContainsString( '<h2>Paused this week — by verdict</h2>', $body );
		$this->assertStringContainsString( '<h2>Standing inventory</h2>', $body );
		$this->assertStringContainsString( '<h2>Top extraction_gap fingerprints</h2>', $body );
		$this->assertStringContainsString( '<h2>Stale paused flows — manual review recommended</h2>', $body );
	}

	public function test_html_renderer_renders_zero_state_when_nothing_changed(): void {
		$ability = new QualifyDigestAbilities();
		$empty   = array(
			'counts'             => array(
				'paused_total'        => 0,
				'resumed_total'       => 0,
				'new_qualified_total' => 0,
				'unsupported_total'   => 0,
				'stale_total'         => 0,
			),
			'paused_by_verdict'  => array(),
			'standing_inventory' => array(),
			'top_extraction_gap' => array(),
			'stale_flows'        => array(),
		);
		$body    = $ability->render_html( $empty, time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( 'Nothing paused this week.', $body );
		$this->assertStringContainsString( 'No universal_web_scraper flows registered.', $body );
		$this->assertStringContainsString( 'No extraction_gap verdicts this week.', $body );
		// Stale section must be absent when there are no stale flows.
		$this->assertStringNotContainsString( '<h2>Stale paused flows', $body );
	}

	public function test_html_renderer_renders_counts(): void {
		$ability = new QualifyDigestAbilities();
		$body    = $ability->render_html( $this->seed_data(), time() - WEEK_IN_SECONDS, time() );

		$this->assertStringContainsString( '147', $body, 'standing inventory active:daily count' );
		$this->assertStringContainsString( '22', $body, 'paused extraction_gap count' );
		$this->assertStringContainsString( '18', $body, 'new qualified total' );
		$this->assertStringContainsString( '9', $body, 'unsupported source total' );
	}

	public function test_digest_counts_only_latest_unsupported_urls_in_window(): void {
		$start = strtotime( '2026-07-01 00:00:00 UTC' );
		$end   = strtotime( '2026-07-08 00:00:00 UTC' );

		$wpdb            = new QualifyDigestWpdbStub(
			array(
				array(
					'id'           => 1,
					'url_hash'     => 'repeat',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-02 00:00:00',
				),
				array(
					'id'           => 2,
					'url_hash'     => 'repeat',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-03 00:00:00',
				),
				array(
					'id'           => 3,
					'url_hash'     => 'recovered',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-04 00:00:00',
				),
				array(
					'id'           => 4,
					'url_hash'     => 'recovered',
					'verdict'      => QualifyVerdict::QUALIFIED_STRUCTURED,
					'qualified_at' => '2026-07-05 00:00:00',
				),
				array(
					'id'           => 5,
					'url_hash'     => 'old',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-06-15 00:00:00',
				),
				array(
					'id'           => 6,
					'url_hash'     => 'current',
					'verdict'      => QualifyVerdict::UNSUPPORTED_SOURCE,
					'qualified_at' => '2026-07-06 00:00:00',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$data = ( new QualifyDigestAbilities() )->gather_data( $start, $end );

		$this->assertSame( 2, $data['counts']['unsupported_total'] );
		$this->assertStringContainsString( 'MAX(id) AS max_id', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'GROUP BY url_hash', $wpdb->unsupported_query );
		$this->assertStringContainsString( 'latest.max_id = v.id', $wpdb->unsupported_query );
	}
}

class QualifyDigestWpdbStub {

	public string $prefix            = 'c8c_';
	public string $unsupported_query = '';
	private array $verdict_rows;
	private array $prepared_args = array();

	public function __construct( array $verdict_rows ) {
		$this->verdict_rows = $verdict_rows;
	}

	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_string( $arg ) ? "'{$arg}'" : (string) $arg, $sql, 1 );
		}
		$this->prepared_args[ $sql ] = $args;
		return $sql;
	}

	public function get_results( string $sql, $output = ARRAY_A ): array {
		return array();
	}

	public function get_var( string $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->prefix . 'dme_qualify_verdicts';
		}
		if ( false === strpos( $sql, "v.verdict = '" . QualifyVerdict::UNSUPPORTED_SOURCE . "'" ) ) {
			return 0;
		}

		$this->unsupported_query = $sql;
		$args                    = $this->prepared_args[ $sql ];
		$latest                  = array();
		foreach ( $this->verdict_rows as $row ) {
			$hash = $row['url_hash'];
			if ( ! isset( $latest[ $hash ] ) || $row['id'] > $latest[ $hash ]['id'] ) {
				$latest[ $hash ] = $row;
			}
		}

		$count = 0;
		foreach ( $latest as $row ) {
			if ( QualifyVerdict::UNSUPPORTED_SOURCE === $row['verdict']
				&& $row['qualified_at'] >= $args[1]
				&& $row['qualified_at'] <= $args[2] ) {
				++$count;
			}
		}
		return $count;
	}
}
