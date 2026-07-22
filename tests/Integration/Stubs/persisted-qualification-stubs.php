<?php
/**
 * Isolated stubs for persisted qualification tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Core;

function wp_get_ability( string $name ) {
	return $GLOBALS['ec_persisted_qualification_abilities'][ $name ] ?? null;
}

function is_wp_error(): bool {
	return false;
}

function wp_remote_get(): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '<html><body>Events</body></html>',
	);
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_header(): string {
	return '';
}

namespace ExtraChillEvents\Abilities;

function add_action(): void {}

function is_wp_error(): bool {
	return false;
}

function wp_remote_get(): array {
	return \ExtraChillEvents\Core\wp_remote_get();
}

function wp_remote_retrieve_body( array $response ): string {
	return \ExtraChillEvents\Core\wp_remote_retrieve_body( $response );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

namespace DataMachine\Core;

class ExecutionContext {
	public static array $scope = array();

	public static function fromFlow( int $pipeline_id, int $flow_id, string $flow_step_id, ?string $job_id, string $handler_type ): self {
		self::$scope = compact( 'pipeline_id', 'flow_id', 'flow_step_id', 'job_id', 'handler_type' );
		return new self();
	}

	public function classifySourceItems( array $identifiers, int $max_items = 0 ): array {
		return array(
			'classifications' => array_map(
				static fn( string $identifier ): array => array(
					'item_identifier'    => $identifier,
					'processed'          => true,
					'reprocess_eligible' => false,
					'actively_claimed'   => false,
					'selected'           => false,
				),
				$identifiers
			),
			'diagnostics'     => array(
				'actively_claimed'             => 0,
				'processed_reprocess_eligible' => 0,
				'selected'                     => 0,
				'max_items'                    => $max_items,
			),
		);
	}
}
