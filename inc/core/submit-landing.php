<?php
/**
 * Landing section for the event submission page (#959).
 *
 * /submit used to open with a single sentence before the form. This section
 * says what a submitter gets, what happens after they submit, and offers the
 * two real paths: import a whole calendar/tour, or add one show.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Analytics id for links in the landing section; placement tells them apart. */
const EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK = 'events_submit_landing';

/** Stable anchor for the event-source (bulk) import panel in the block. */
const EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR = 'ec-event-source-import';

/**
 * One-line reach statement built from the cached calendar stats.
 *
 * Returns '' when stats are unavailable, so the page never shows "0 events".
 *
 * @return string
 */
function extrachill_events_submit_landing_reach(): string {
	if ( ! function_exists( 'extrachill_events_get_calendar_stats' ) ) {
		return '';
	}
	$stats  = extrachill_events_get_calendar_stats();
	$events = (int) ( $stats['events'] ?? 0 );
	$cities = (int) ( $stats['locations'] ?? 0 );
	if ( $events <= 0 || $cities <= 0 ) {
		return '';
	}

	return sprintf(
		/* translators: 1: number of upcoming events, 2: number of cities and regions (location terms). */
		__( 'People use Extra Chill to find shows: %1$s upcoming events across %2$s cities and regions, free to browse with no login wall.', 'extrachill-events' ),
		number_format_i18n( $events ),
		number_format_i18n( $cities )
	);
}

/**
 * Render the landing section markup.
 *
 * @param string $form_id DOM id of the manual submission form, for the in-page link.
 * @return string
 */
function extrachill_events_render_submit_landing( string $form_id ): string {
	$reach = extrachill_events_submit_landing_reach();
	$steps = array(
		__( 'We check the details.', 'extrachill-events' ),
		__( 'Your show goes live on the calendar and on its venue and city pages.', 'extrachill-events' ),
		__( 'We email you the link so you can share it.', 'extrachill-events' ),
	);

	ob_start();
	?>
	<section class="ec-submit-landing" aria-labelledby="ec-submit-landing-title">
		<h2 id="ec-submit-landing-title" class="ec-submit-landing__title"><?php esc_html_e( 'Get your show on the calendar', 'extrachill-events' ); ?></h2>
		<p class="ec-submit-landing__lede">
			<?php esc_html_e( 'Adding a show is free.', 'extrachill-events' ); ?>
			<?php if ( '' !== $reach ) : ?>
				<?php echo esc_html( $reach ); ?>
			<?php endif; ?>
		</p>

		<h3 class="ec-submit-landing__subtitle"><?php esc_html_e( 'What happens next', 'extrachill-events' ); ?></h3>
		<ol class="ec-submit-landing__steps">
			<?php foreach ( $steps as $step ) : ?>
				<li><?php echo esc_html( $step ); ?></li>
			<?php endforeach; ?>
		</ol>
		<p class="ec-submit-landing__note"><?php esc_html_e( 'Once you’ve had a show published, the next ones you add while signed in go live right away.', 'extrachill-events' ); ?></p>

		<div class="ec-submit-landing__paths">
			<a class="ec-submit-landing__path" href="<?php echo esc_url( '#' . EXTRACHILL_EVENTS_SUBMIT_IMPORT_ANCHOR ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK ); ?>" data-ec-track-placement="import">
				<strong><?php esc_html_e( 'Have a whole calendar or tour?', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Import a venue calendar, tour page or festival schedule at once.', 'extrachill-events' ); ?></span>
			</a>
			<a class="ec-submit-landing__path" href="<?php echo esc_url( '#' . $form_id ); ?>" data-ec-track="<?php echo esc_attr( EXTRACHILL_EVENTS_SUBMIT_LANDING_TRACK ); ?>" data-ec-track-placement="manual">
				<strong><?php esc_html_e( 'Just one show?', 'extrachill-events' ); ?></strong>
				<span><?php esc_html_e( 'Fill in the form below. Add a flyer and we’ll pull in the details it shows.', 'extrachill-events' ); ?></span>
			</a>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}
