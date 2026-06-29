<?php
/**
 * Shared "WP-native postboxes" page shell for Turf's admin pages - every
 * stat block becomes a real wp-admin .postbox: collapsible, draggable/
 * reorderable (persisted via WP core's own existing meta-box-order AJAX
 * handler - nothing custom needed for that), and individually hideable via
 * the screen's native "Schermopties" panel. Same mechanism the Dashboard
 * widgets use, just pointed at our own pages.
 */

/**
 * Tracks which admin page hooks have postboxes, so turf_postboxes_enqueue()
 * only loads the postbox JS where it's actually needed.
 */
function turf_register_postbox_hook( $hook = null ) {
	static $hooks = array();

	if ( null !== $hook ) {
		$hooks[] = $hook;
	}

	return $hooks;
}

function turf_postboxes_enqueue( $hook ) {
	if ( ! in_array( $hook, turf_register_postbox_hook(), true ) ) {
		return;
	}

	wp_enqueue_script( 'postbox' );
	wp_add_inline_script(
		'postbox',
		'jQuery(function($){ postboxes.add_postbox_toggles(' . wp_json_encode( $hook ) . '); });'
	);

	wp_enqueue_script( 'turf-postbox-more', TURF_URL . 'js/postbox-more.js', array(), TURF_VERSION, true );
	wp_localize_script( 'turf-postbox-more', 'turfPostboxMore', array(
		'moreLabel' => __( 'Toon %d meer', 'turf-stats' ),
		'lessLabel' => __( 'Toon minder', 'turf-stats' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'turf_postboxes_enqueue' );

/**
 * Renders just one postbox-container + its boxes, with no outer wrapper -
 * the building block for everything below. A page with several sections
 * (e.g. Statistieken's overview / compact-breakdowns / tables) calls this
 * (or turf_render_postbox_grid_column()) more than once inside one shared
 * #poststuff wrapper of its own; turf_render_postboxes() below covers the
 * common single-section case.
 */
function turf_render_postbox_column( $hook, $context ) {
	?>
	<div class="postbox-container">
		<?php do_meta_boxes( $hook, $context, null ); ?>
	</div>
	<?php
}

/**
 * Renders one postbox-container whose boxes (all registered under the same
 * $context) lay out two-per-row in a CSS grid, in registration/drag order -
 * box 1 + box 2 on row one, box 3 + box 4 on row two, and so on. This is a
 * single sortable list (jQuery UI Sortable just reorders DOM children;
 * the grid then re-flows automatically based on the new order), unlike an
 * earlier attempt that used two separate side-by-side containers - that
 * approach fought WP core's own postbox-container width rules and broke.
 */
function turf_render_postbox_grid_column( $hook, $context ) {
	?>
	<div class="postbox-container turf-postbox-grid">
		<?php do_meta_boxes( $hook, $context, null ); ?>
	</div>
	<?php
}

/**
 * Renders the single-column postbox container with everything registered
 * for $hook under $context via add_meta_box(). Call from the page's own
 * render callback, after the load-$hook action (where add_meta_box() calls
 * happen) has run.
 */
function turf_render_postboxes( $hook, $context = 'normal' ) {
	?>
	<div id="poststuff">
		<?php turf_render_postbox_column( $hook, $context ); ?>
	</div>
	<?php
}

/**
 * Shared "7 / 30 / 90 dagen / Alles" period resolution, used by every Turf
 * admin page and by the metabox content callbacks registered for it.
 *
 * TURF_PERIOD_TODAY is a dedicated sentinel for "Vandaag" - deliberately
 * not 0, which already means "Alles" (no date filter at all) throughout
 * the codebase's many `0 === $days` / `$days > 0` checks. Kept distinct so
 * those existing checks don't need to change: 0 still means "no filter",
 * any other value (positive N, or this sentinel) still means "yes, filter".
 */
define( 'TURF_PERIOD_TODAY', -1 );

function turf_period_days_map() {
	return array( 'today' => TURF_PERIOD_TODAY, '7' => 7, '30' => 30, '90' => 90, 'all' => 0 );
}

/**
 * @param string $default_period Which tab to use when no ?period= is in the
 *                                URL yet - most pages keep the historical
 *                                '7' default; Statistieken opts into 'today'.
 */
function turf_get_requested_days( $default_period = '7' ) {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : $default_period;
	$map    = turf_period_days_map();

	return isset( $map[ $period ] ) ? $map[ $period ] : 7;
}

/**
 * SQL-ready (UTC, 'Y-m-d 00:00:00') start-of-period boundary - the
 * midnight-aligned "since the start of this period" pattern used by every
 * breakdown/listing query throughout the admin pages. Today's midnight for
 * TURF_PERIOD_TODAY, otherwise midnight $days days ago.
 */
function turf_period_start_sql_date( $days ) {
	if ( TURF_PERIOD_TODAY === $days ) {
		return turf_local_midnight_utc( 0 );
	}

	return gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
}

/**
 * Midnight $days_back days ago, in the site's own configured timezone (not
 * UTC), converted to a UTC 'Y-m-d H:i:s' string ready to compare against
 * viewed_at (always stored in UTC). "Vandaag" must match the calendar day
 * the site owner actually experiences locally - a site on Europe/Amsterdam
 * (UTC+1/+2) sees its day start 1-2 hours before UTC midnight, which a
 * plain gmdate('Y-m-d 00:00:00') boundary would miss entirely.
 */
function turf_local_midnight_utc( $days_back = 0 ) {
	$date = new DateTime( 'now', wp_timezone() );
	$date->setTime( 0, 0, 0 );

	if ( $days_back > 0 ) {
		$date->modify( "-{$days_back} days" );
	}

	$date->setTimezone( new DateTimeZone( 'UTC' ) );

	return $date->format( 'Y-m-d H:i:s' );
}

/**
 * The $offset_days to pass for the "previous period" half of a %-change
 * comparison (paired with turf_get_range_site_totals()-style functions
 * called as `($days, 0)` for current and `($days, <this>)` for previous).
 * For an N-day window, shifting the whole window back by N days is
 * correct; TURF_PERIOD_TODAY isn't a fixed-length window, so its "previous
 * period" is always exactly 1 day back (yesterday).
 */
function turf_previous_period_offset( $days ) {
	return TURF_PERIOD_TODAY === $days ? 1 : $days;
}

function turf_render_period_tabs( $base_url, $default_period = '7' ) {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : $default_period;
	$labels = array(
		'today' => __( 'Vandaag', 'turf-stats' ),
		'7'   => __( '7 dagen', 'turf-stats' ),
		'30'  => __( '30 dagen', 'turf-stats' ),
		'90'  => __( '90 dagen', 'turf-stats' ),
		'all' => __( 'Alles', 'turf-stats' ),
	);
	?>
	<ul class="subsubsub">
		<?php foreach ( $labels as $key => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'period', $key, $base_url ) ); ?>" <?php echo $period === (string) $key ? 'class="current"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a> |
			</li>
		<?php endforeach; ?>
	</ul>
	<br class="clear" />
	<?php
}
