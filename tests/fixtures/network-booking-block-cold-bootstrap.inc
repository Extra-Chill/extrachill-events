<?php
/**
 * Cold non-Events bootstrap for the network booking block.
 *
 * This fixture intentionally supplies only WordPress functions reached by the
 * network companion and public render projection.
 *
 * @package ExtraChillEvents\Tests
 */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {}

final class WP_Term {
	public int $term_id = 1524;
	public string $taxonomy = 'venue';
	public string $name = 'Cold Room';
	public string $description = 'Canonical Events venue.';
}

$GLOBALS['ec_network_block_test'] = array(
	'blog_id'    => 1,
	'stack'      => array(),
	'registered' => array(),
);

function add_action( $hook, $callback ): void {
	$GLOBALS['ec_network_block_test']['actions'][ $hook ][] = $callback;
}

function register_block_type( $path ) {
	$GLOBALS['ec_network_block_test']['registered'][] = $path;
	return true;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function ec_get_blog_id( $site ): ?int {
	return 'events' === $site ? 7 : null;
}

function get_current_blog_id(): int {
	return $GLOBALS['ec_network_block_test']['blog_id'];
}

function is_multisite(): bool {
	return true;
}

function get_site( $blog_id ) {
	return 7 === (int) $blog_id ? (object) array( 'blog_id' => 7 ) : null;
}

function switch_to_blog( $blog_id ): bool {
	$GLOBALS['ec_network_block_test']['stack'][] = get_current_blog_id();
	$GLOBALS['ec_network_block_test']['blog_id'] = (int) $blog_id;
	return true;
}

function restore_current_blog(): bool {
	$GLOBALS['ec_network_block_test']['blog_id'] = array_pop( $GLOBALS['ec_network_block_test']['stack'] );
	return true;
}

function get_term( $term_id, $taxonomy ) {
	return 7 === get_current_blog_id() && 1524 === (int) $term_id && 'venue' === $taxonomy ? new WP_Term() : null;
}

function get_term_meta( $term_id, $key, $single ) {
	unset( $single );
	if ( 7 !== get_current_blog_id() || 1524 !== (int) $term_id ) {
		return '';
	}
	if ( '_extrachill_booking_config' === $key ) {
		return array(
			'version'             => 3,
			'revision'            => 9,
			'enabled'             => true,
			'intake'              => array(
				'version' => 1,
				'fields'  => array(
					array(
						'key'      => 'draw',
						'label'    => 'Recent draw',
						'type'     => 'number',
						'required' => true,
						'options'  => array(),
					),
				),
			),
			'public_requirements' => array( 'Include routing.' ),
			'consent'             => array(
				'id'       => 'booking-privacy',
				'version'  => 1,
				'label'    => 'I agree.',
				'required' => true,
			),
			'spaces'              => array(),
		);
	}
	$meta = array(
		'_venue_address' => '42 Test Street',
		'_venue_city'    => 'Charleston',
		'_venue_state'   => 'SC',
		'_venue_zip'     => '29403',
	);
	return $meta[ $key ] ?? '';
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function __( $text ): string {
	return $text;
}

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ): string {
	return sanitize_text_field( $value );
}

function sanitize_email( $value ): string {
	return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? (string) $value : '';
}

function wp_strip_all_tags( $value ): string {
	return strip_tags( (string) $value );
}

function wp_unique_id( $prefix ): string {
	return $prefix . 'cold';
}

function is_user_logged_in(): bool {
	return false;
}

function ec_enqueue_turnstile_script(): void {
	$GLOBALS['ec_network_block_test']['turnstile_enqueued'] = true;
}

function rest_url( $path ): string {
	return 'https://extrachill.com/wp-json/' . ltrim( $path, '/' );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function get_block_wrapper_attributes( $attributes ): string {
	return 'class="' . $attributes['class'] . '"';
}

function ec_render_turnstile_widget(): string {
	return '<div class="turnstile"></div>';
}

function wp_kses_post( $value ): string {
	return $value;
}

function esc_html_e( $value ): void {
	echo htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
