<?php
/**
 * Local Support organizer provider registry tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportOrganizerProvider;
use ExtraChillEvents\Core\LocalSupportOrganizerProviderRegistry;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class LocalSupportOrganizerProviderRegistryTest extends BookingTestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
		);
	}

	public function test_duplicate_and_unknown_identity_claims_fail_closed(): void {
		$registry = new LocalSupportOrganizerProviderRegistry();
		$provider = new LocalSupportRegistryProvider( 'configured-owner' );
		$this->assertTrue( $registry->register( $provider ) );
		$this->assertSame( 'local_support_organizer_provider_duplicate', $registry->register( new LocalSupportRegistryProvider( 'configured-owner' ) )->get_error_code() );
		$error = $registry->authorize(
			LocalSupportAuthorization::ACTION_VIEW,
			$this->request(),
			array(
				'type' => 'stale-owner',
				'id'   => 9,
			),
			12,
			new LocalSupportAuthorization( null, $registry )
		);
		$this->assertSame( 'local_support_organizer_identity_unknown', $error->get_error_code() );
	}

	public function test_actions_and_choices_are_forwarded_without_owner_type_branching(): void {
		$registry = new LocalSupportOrganizerProviderRegistry();
		$provider = new LocalSupportRegistryProvider( 'configured-owner' );
		$registry->register( $provider );
		$authorization = new LocalSupportAuthorization( null, $registry );
		foreach ( array( LocalSupportAuthorization::ACTION_OPEN, LocalSupportAuthorization::ACTION_VIEW, LocalSupportAuthorization::ACTION_TRANSITION_REQUEST, LocalSupportAuthorization::ACTION_REVIEW_INTERESTS, LocalSupportAuthorization::ACTION_SELECT_INTEREST, LocalSupportAuthorization::ACTION_NOTIFY ) as $action ) {
			$this->assertTrue(
				$registry->authorize(
					$action,
					$this->request(),
					array(
						'type' => 'configured-owner',
						'id'   => 9,
					),
					12,
					$authorization
				)
			);
		}
		$this->assertSame( array( 'configured-owner:9' ), array_column( $registry->choices( 900, 12, $authorization ), 'reference' ) );
		$this->assertSame( array( 12 ), $registry->recipient_ids( $this->request(), $authorization ) );
	}

	public function test_provider_throw_and_multisite_context_leak_become_errors_and_restore_stack(): void {
		$registry = new LocalSupportOrganizerProviderRegistry();
		$provider = new LocalSupportRegistryProvider( 'configured-owner' );
		$registry->register( $provider );
		$authorization   = new LocalSupportAuthorization( null, $registry );
		$provider->throw = true;
		$this->assertSame( 'local_support_organizer_provider_failed', $registry->choices( 900, 12, $authorization )->get_error_code() );

		$provider->throw = false;
		$provider->leak  = true;
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( 'local_support_organizer_provider_context_corrupt', $registry->choices( 900, 12, $authorization )->get_error_code() );
		$this->assertSame( 7, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['stack'] );
	}

	private function request(): array {
		return array(
			'event_id'       => 900,
			'venue_term_id'  => 55,
			'organizer_type' => 'audit-source',
			'organizer_id'   => 4,
		);
	}
}

final class LocalSupportRegistryProvider implements LocalSupportOrganizerProvider {
	private $type;
	public $throw = false;
	public $leak  = false;

	public function __construct( string $type ) {
		$this->type = $type; }
	public function type(): string {
		return $this->type; }
	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null ) {
		unset( $action, $request, $user_id, $authorization, $locked, $scope );
		return 9 === $identity['id'];
	}
	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization ) {
		unset( $event_id, $user_id, $authorization );
		if ( $this->throw ) {
			throw new RuntimeException( 'provider failed' ); }
		if ( $this->leak ) {
			switch_to_blog( 4 ); }
		return array(
			array(
				'type'      => $this->type,
				'id'        => 9,
				'label'     => 'Configured owner',
				'reference' => $this->type . ':9',
			),
		);
	}
	public function recipient_ids( array $request, LocalSupportAuthorization $authorization ) {
		unset( $request, $authorization );
		return array( 12 );
	}
}
