<?php

use ExtraChillEvents\Core\LocationTermIntegrity;
use PHPUnit\Framework\TestCase;

final class LocationTermIntegrityTest extends TestCase {
	public function test_matches_explicit_state_suffix_to_exact_hierarchical_city(): void {
		$terms = array(
			(object) array( 'term_id' => 1, 'name' => 'South Carolina', 'parent' => 100 ),
			(object) array( 'term_id' => 2, 'name' => 'Charleston', 'parent' => 1 ),
			(object) array( 'term_id' => 3, 'name' => 'Charleston, SC', 'parent' => 0 ),
		);

		$result = LocationTermIntegrity::match_root_term( $terms[2], $terms );

		$this->assertSame( 'safe_match', $result['status'] );
		$this->assertSame( 2, $result['canonical']->term_id );
	}

	public function test_does_not_fuzzily_match_a_different_city_name(): void {
		$terms = array(
			(object) array( 'term_id' => 1, 'name' => 'South Carolina', 'parent' => 100 ),
			(object) array( 'term_id' => 2, 'name' => 'Charleston Heights', 'parent' => 1 ),
			(object) array( 'term_id' => 3, 'name' => 'Charleston, SC', 'parent' => 0 ),
		);

		$result = LocationTermIntegrity::match_root_term( $terms[2], $terms );

		$this->assertSame( 'unresolved', $result['status'] );
		$this->assertNull( $result['canonical'] );
	}

	public function test_reports_multiple_exact_state_matches_as_ambiguous(): void {
		$terms = array(
			(object) array( 'term_id' => 1, 'name' => 'Georgia', 'parent' => 100 ),
			(object) array( 'term_id' => 2, 'name' => 'Greensboro', 'parent' => 1 ),
			(object) array( 'term_id' => 3, 'name' => 'Greensboro', 'parent' => 1 ),
			(object) array( 'term_id' => 4, 'name' => 'Greensboro, GA', 'parent' => 0 ),
		);

		$result = LocationTermIntegrity::match_root_term( $terms[3], $terms );

		$this->assertSame( 'ambiguous', $result['status'] );
		$this->assertNull( $result['canonical'] );
	}

	public function test_root_city_without_state_is_not_an_automatic_candidate(): void {
		$terms = array( (object) array( 'term_id' => 1, 'name' => 'Portland', 'parent' => 0 ) );

		$result = LocationTermIntegrity::match_root_term( $terms[0], $terms );

		$this->assertSame( 'not_candidate', $result['status'] );
	}
}
