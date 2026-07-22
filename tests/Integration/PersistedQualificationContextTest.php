<?php
/**
 * Persisted-flow qualification integration coverage.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

	require_once __DIR__ . '/Stubs/persisted-qualification-global-stubs.php';
	require_once __DIR__ . '/Stubs/persisted-qualification-stubs.php';

	use DataMachine\Core\ExecutionContext;
	use ExtraChillEvents\Abilities\VenueQualificationAbilities;
	use ExtraChillEvents\Cli\UnqualifiableFlowsCommand;
	use ExtraChillEvents\Core\QualifyVerdict;
	use PHPUnit\Framework\TestCase;

	class PersistedQualificationContextTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['ec_persisted_qualification_abilities'] = array();
			ExecutionContext::$scope                           = array();
		}

		public function test_real_ability_caller_passes_persisted_config_and_classifies_all_processed(): void {
			$scraper = new class() {
				public array $input = array();

				public function execute( array $input ): array {
					$this->input = $input;
					return array(
						'success'         => true,
						'extraction_info' => array(
							'extraction_method'         => 'squarespace',
							'source_type'               => 'universal_web_scraper',
							'payload_type'              => 'event',
							'extracted_packet_count'    => 2,
							'unique_source_event_count' => 2,
							'production_max_items'      => 1,
						),
						'event_data'      => array(
							'items' => array(
								array( 'title' => 'One' ),
								array( 'title' => 'Two' ),
							),
						),
					);
				}
			};

			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = $scraper;
			$GLOBALS['ec_persisted_qualification_abilities']['datamachine/test-handler']                = new class() {
				public function execute(): array {
					return array(
						'success'    => true,
						'packets'    => array(
							array( 'metadata' => array( 'item_identifier' => 'event-one' ) ),
							array( 'metadata' => array( 'item_identifier' => 'event-two' ) ),
						),
						'truncation' => array( 'truncated' => false ),
					);
				}
			};

			$ability = new class() extends VenueQualificationAbilities {
				protected function loadPersistedFlowContext( int $flow_id ): array {
					return array(
						'flow_id'        => $flow_id,
						'pipeline_id'    => 3,
						'flow_step_id'   => 'step-42',
						'job_id'         => '9001',
						'handler_config' => array(
							'source_url'       => 'https://venue.example/events',
							'exclude_keywords' => 'comedy',
							'venue'            => 1491,
							'max_items'        => 1,
						),
					);
				}
			};

			$result = $ability->executeQualifyVenue(
				array(
					'flow_id'         => 42,
					'persist_verdict' => false,
				)
			);

			$this->assertSame( QualifyVerdict::QUALIFIED_STRUCTURED, $result['verdict'] );
			$this->assertSame( 2, $result['production_context']['raw_extracted'] );
			$this->assertSame( 2, $result['production_context']['processed'] );
			$this->assertSame( 0, $result['production_context']['production_eligible'] );
			$this->assertTrue( $result['production_context']['complete'] );
			$this->assertSame( 'comedy', $scraper->input['handler_config']['exclude_keywords'] );
			$this->assertSame( 1491, $scraper->input['handler_config']['venue'] );
			$this->assertSame( 'step-42', ExecutionContext::$scope['flow_step_id'] );
			$this->assertSame( '9001', ExecutionContext::$scope['job_id'] );
		}

		public function test_ad_hoc_mode_reports_missing_production_context(): void {
			$GLOBALS['ec_persisted_qualification_abilities']['data-machine-events/test-event-scraper'] = new class() {
				public function execute(): array {
					return array(
						'success'         => true,
						'extraction_info' => array(
							'extraction_method'         => 'jsonld',
							'source_type'               => 'universal_web_scraper',
							'payload_type'              => 'event',
							'extracted_packet_count'    => 2,
							'unique_source_event_count' => 2,
						),
						'event_data'      => array( 'items' => array( array(), array() ) ),
					);
				}
			};

			$result = ( new VenueQualificationAbilities() )->executeQualifyVenue(
				array(
					'url'             => 'https://venue.example/events',
					'persist_verdict' => false,
				)
			);

			$this->assertFalse( $result['production_context']['context_supplied'] );
			$this->assertStringContainsString( 'no persisted production flow context', $result['production_context']['reason'] );
		}

		public function test_all_processed_stable_zero_is_expected_zero(): void {
			require_once dirname( __DIR__, 2 ) . '/inc/Cli/FlowHelpers.php';
			require_once dirname( __DIR__, 2 ) . '/inc/Cli/UnqualifiableFlowsCommand.php';

			$command = new class() extends UnqualifiableFlowsCommand {
				public function classify( array $production ): string {
					return $this->classify_qualified_action( $production, null );
				}
			};

			$this->assertSame(
				'expected_zero',
				$command->classify(
					array(
						'complete'             => true,
						'production_eligible' => 0,
					)
				)
			);
		}
	}
