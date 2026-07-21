<?php
/**
 * Events Calendar Ability
 *
 * Returns paginated date-grouped calendar events with optional
 * taxonomy, geo-proximity, scope, and search filtering.
 *
 * Delegates to the data-machine-events/get-calendar-page ability
 * and transforms the result into a consumer-friendly shape.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_calendar_ability' );

/**
 * Register extrachill/events-calendar ability.
 */
function extrachill_events_register_calendar_ability(): void {

	wp_register_ability(
		'extrachill/events-calendar',
		array(
			'label'               => __( 'Events Calendar', 'extrachill-events' ),
			'description'         => __( 'Returns paginated date-grouped calendar events with optional taxonomy, geo, scope, and search filtering.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'page'     => array(
						'type'        => 'integer',
						'description' => 'Page number (1-indexed).',
						'default'     => 1,
						'minimum'     => 1,
					),
					'venue'    => array(
						'type'        => 'string',
						'description' => 'Filter by venue taxonomy slug.',
					),
					'promoter' => array(
						'type'        => 'string',
						'description' => 'Filter by promoter taxonomy slug.',
					),
					'location' => array(
						'type'        => 'string',
						'description' => 'Filter by location taxonomy slug.',
					),
					'scope'    => array(
						'type'        => 'string',
						'description' => 'Time-window scope.',
						'enum'        => array( 'today', 'tonight', 'this-weekend', 'this-week' ),
					),
					'lat'      => array(
						'type'        => 'number',
						'description' => 'Latitude for geo filtering.',
					),
					'lng'      => array(
						'type'        => 'number',
						'description' => 'Longitude for geo filtering.',
					),
					'radius'   => array(
						'type'        => 'integer',
						'description' => 'Radius in miles for geo filtering.',
						'default'     => 25,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'Free-text search query.',
					),
					'past'     => array(
						'type'        => 'boolean',
						'description' => 'Include past events.',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'dates'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'date'   => array( 'type' => 'string' ),
								'label'  => array( 'type' => 'string' ),
								'events' => array( 'type' => 'array' ),
							),
						),
					),
					'total'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'has_more' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_calendar',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Execute the events-calendar ability.
 *
 * @param array $input Input matching input_schema.
 * @return array|WP_Error Calendar response or error.
 */
function extrachill_events_ability_calendar( array $input ): array|\WP_Error {
	$ability = wp_get_ability( 'data-machine-events/get-calendar-page' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'Calendar ability is not registered.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	$delegate_input = array(
		'paged'        => (int) ( $input['page'] ?? 1 ),
		'include_html' => false,
		'include_gaps' => false,
		'past'         => ! empty( $input['past'] ),
	);

	if ( ! empty( $input['scope'] ) ) {
		$delegate_input['scope'] = sanitize_text_field( $input['scope'] );
	}

	if ( ! empty( $input['search'] ) ) {
		$delegate_input['event_search'] = sanitize_text_field( $input['search'] );
	}

	// Build taxonomy filter from slug params.
	$tax_filter = array();
	$mappings   = array(
		'venue'    => 'venue',
		'promoter' => 'promoter',
		'location' => 'location',
	);

	foreach ( $mappings as $param => $taxonomy ) {
		$slug = $input[ $param ] ?? '';
		if ( empty( $slug ) ) {
			continue;
		}

		$term = get_term_by( 'slug', sanitize_text_field( $slug ), $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$tax_filter[ $taxonomy ] = array( $term->term_id );
		}
	}

	if ( ! empty( $tax_filter ) ) {
		$delegate_input['tax_filter'] = $tax_filter;
	}

	// Geo params.
	if ( isset( $input['lat'] ) && isset( $input['lng'] ) ) {
		$delegate_input['geo_lat']    = (float) $input['lat'];
		$delegate_input['geo_lng']    = (float) $input['lng'];
		$delegate_input['geo_radius'] = (int) ( $input['radius'] ?? 25 );
	}

	$result = $ability->execute( $delegate_input );

	if ( is_wp_error( $result ) ) {
		return new \WP_Error(
			'calendar_error',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return extrachill_events_transform_calendar_response( $result );
}

/**
 * Transform calendar ability result into consumer-friendly shape.
 *
 * @param array $result Raw result from data-machine-events/get-calendar-page.
 * @return array Transformed response.
 */
function extrachill_events_transform_calendar_response( array $result ): array {
	$dates = array();

	foreach ( $result['paged_date_groups'] ?? array() as $group ) {
		$date_obj = date_create( $group['date'] );
		$label    = $date_obj ? $date_obj->format( 'l, F j, Y' ) : $group['date'];

		$events = array();
		foreach ( $group['events'] ?? array() as $event ) {
			$events[] = extrachill_events_transform_calendar_event( $event );
		}

		$dates[] = array(
			'date'   => $group['date'],
			'label'  => $label,
			'events' => $events,
		);
	}

	return array(
		'dates'    => $dates,
		'total'    => $result['total_event_count'] ?? 0,
		'page'     => $result['current_page'] ?? 1,
		'has_more' => ( $result['current_page'] ?? 1 ) < ( $result['max_pages'] ?? 1 ),
	);
}

/**
 * Adapt one canonical calendar occurrence without discarding its context.
 *
 * @param array $event Canonical calendar event occurrence.
 * @return array Adapted event.
 */
function extrachill_events_transform_calendar_event( array $event ): array {
	$event_data = $event['event_data'] ?? array();
	$post_id    = (int) ( $event['post_id'] ?? 0 );
	$datetime   = '';

	if ( ! empty( $event_data['startDate'] ) ) {
		$datetime = $event_data['startDate'] . 'T' . ( $event_data['startTime'] ?? '00:00:00' );
	}

	$end_datetime = null;
	if ( ! empty( $event_data['endDate'] ) ) {
		$end_datetime = $event_data['endDate'] . 'T' . ( $event_data['endTime'] ?? '23:59:59' );
	}

	$ticket_url = $event_data['ticketUrl'] ?? null;
	if ( empty( $ticket_url ) ) {
		$meta_ticket_url = get_post_meta( $post_id, '_datamachine_ticket_url', true );
		$ticket_url      = $meta_ticket_url ? $meta_ticket_url : null;
	}

	return array(
		'id'                 => $post_id,
		'title'              => $event['title'] ?? '',
		'datetime'           => $datetime,
		'end_datetime'       => $end_datetime,
		'venue'              => extrachill_events_get_calendar_venue( $post_id, $event_data ),
		'organizer'          => empty( $event_data['organizer'] ) ? null : array(
			'name' => $event_data['organizer'],
			'url'  => $event_data['organizerUrl'] ?? '',
			'type' => $event_data['organizerType'] ?? '',
		),
		'performer'          => array(
			'name' => $event_data['performer'] ?? '',
			'type' => $event_data['performerType'] ?? '',
		),
		'taxonomies'         => extrachill_events_get_calendar_taxonomies( $post_id ),
		'status'             => $event_data['eventStatus'] ?? null,
		'occurrence_context' => $event['display_context'] ?? array(),
		'ticket_url'         => $ticket_url,
		'permalink'          => get_permalink( $post_id ),
	);
}

/**
 * Return the canonical venue attached to an event in the shared adapter shape.
 *
 * @param int   $post_id    Event post ID.
 * @param array $event_data Canonical hydrated event data.
 * @return array|null Adapted venue or null.
 */
function extrachill_events_get_calendar_venue( int $post_id, array $event_data ): ?array {
	$terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'all' ) );
	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return null;
	}

	$term  = $terms[0];
	$venue = function_exists( 'data_machine_events_get_venue_data' )
		? data_machine_events_get_venue_data( (int) $term->term_id )
		: null;

	if ( ! is_array( $venue ) ) {
		$venue = array(
			'term_id' => $term->term_id,
			'name'    => $term->name,
			'slug'    => $term->slug,
		);
	}

	$venue['formatted_address'] = $event_data['address'] ?? null;
	if ( empty( $venue['timezone'] ) && ! empty( $event_data['venueTimezone'] ) ) {
		$venue['timezone'] = $event_data['venueTimezone'];
	}

	return extrachill_events_transform_venue_detail( $venue );
}

/**
 * Return canonical taxonomy assignments used by Extra Chill event consumers.
 *
 * @param int $post_id Event post ID.
 * @return array Taxonomy slug to term summaries.
 */
function extrachill_events_get_calendar_taxonomies( int $post_id ): array {
	$taxonomies = array();

	foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}

		$taxonomies[ $taxonomy ] = array_map(
			static function ( $term ) use ( $taxonomy ): array {
				$link = get_term_link( $term, $taxonomy );

				return array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'link'    => is_wp_error( $link ) ? '' : $link,
				);
			},
			$terms
		);
	}

	return $taxonomies;
}
