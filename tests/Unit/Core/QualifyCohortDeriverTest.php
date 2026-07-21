<?php
/**
 * Tests for deterministic qualification remediation cohorts.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyCohortDeriver;
use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Covers evidence classification, stable ordering, and bounded output.
 */
class QualifyCohortDeriverTest extends TestCase {

	/** Test platform, structure, and extractor failure derivation. */
	public function test_derives_platform_structured_and_extractor_failure(): void {
		$cohort = QualifyCohortDeriver::derive(
			array(
				'http_status'        => 200,
				'platforms_detected' => array( 'wordpress_generic' ),
				'structured_data'    => array(
					'jsonld_events'    => 4,
					'event_page_shape' => 'listing',
				),
				'extractor_attempts' => array(
					array(
						'name'   => 'JsonLdExtractor',
						'exists' => true,
						'ran'    => true,
						'events' => 0,
					),
				),
			),
			QualifyVerdict::EXTRACTION_GAP
		);

		$this->assertSame( 'extractor', $cohort['category'] );
		$this->assertSame( 'wordpress_generic', $cohort['platform'] );
		$this->assertSame( 'jsonld', $cohort['structured_signal'] );
		$this->assertSame( 'listing', $cohort['page_shape'] );
		$this->assertSame( 'JsonLdExtractor', $cohort['extractor'] );
		$this->assertSame( 'attempt_failed', $cohort['reason'] );
	}

	/** Test login walls remain separate from generic no-evidence pages. */
	public function test_separates_login_wall_from_generic_no_evidence(): void {
		$login   = QualifyCohortDeriver::derive(
			array(
				'http_status' => 200,
				'final_url'   => 'https://www.facebook.com/login.php?next=%2Fvenue',
			),
			QualifyVerdict::EXTRACTION_GAP,
			'https://facebook.com/venue'
		);
		$generic = QualifyCohortDeriver::derive(
			array(
				'http_status' => 200,
				'final_url'   => 'https://venue.example/about',
			),
			QualifyVerdict::EXTRACTION_GAP,
			'https://venue.example/'
		);
		$social  = QualifyCohortDeriver::derive(
			array(
				'http_status' => 200,
				'final_url'   => 'https://facebook.com/venue',
			),
			QualifyVerdict::EXTRACTION_GAP,
			'https://facebook.com/venue'
		);

		$this->assertSame( 'non_actionable', $login['category'] );
		$this->assertSame( 'facebook.com', $login['platform'] );
		$this->assertSame( 'login_wall', $login['reason'] );
		$this->assertSame( 'no_event_evidence', $generic['reason'] );
		$this->assertSame( 'facebook.com', $social['platform'] );
		$this->assertSame( 'unsupported_social_source', $social['reason'] );
	}

	/** Test stable ranking, URL ordering, URL caps, and output safety. */
	public function test_grouping_has_stable_order_and_bounded_urls(): void {
		$rows = array();
		foreach ( array( 'd', 'b', 'a', 'c' ) as $slug ) {
			$rows[] = array(
				'url'         => 'https://' . $slug . '.example/events',
				'verdict'     => QualifyVerdict::EXTRACTION_GAP,
				'fingerprint' => wp_json_encode(
					array(
						'http_status'        => 200,
						'platforms_detected' => array( 'squarespace' ),
						'structured_data'    => array( 'event_page_shape' => 'listing' ),
						'extractor_attempts' => array(
							array(
								'name'   => 'SquarespaceExtractor',
								'exists' => true,
								'ran'    => false,
							),
						),
					)
				),
			);
		}
		$rows[] = array(
			'url'         => 'https://blocked.example/events',
			'verdict'     => QualifyVerdict::BOT_BLOCKED,
			'fingerprint' => wp_json_encode( array( 'http_status' => 403 ) ),
		);
		$rows[] = array(
			'url'         => 'https://qualified.example/events',
			'verdict'     => QualifyVerdict::QUALIFIED_STRUCTURED,
			'fingerprint' => wp_json_encode( array( 'http_status' => 200 ) ),
		);

		$groups         = QualifyCohortDeriver::group( array_reverse( $rows ) );
		$forward_groups = QualifyCohortDeriver::group( $rows );

		$this->assertSame( $forward_groups, $groups );
		$this->assertCount( 2, $groups );
		$this->assertSame( 4, $groups[0]['count'] );
		$this->assertSame(
			array(
				'https://a.example/events',
				'https://b.example/events',
				'https://c.example/events',
			),
			$groups[0]['representative_urls']
		);
		$this->assertSame( 'operational', $groups[1]['category'] );
		$this->assertArrayNotHasKey( 'fingerprint', $groups[0] );
	}
}
