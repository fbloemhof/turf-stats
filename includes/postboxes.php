<?php
/**
 * Shared "WP-native postboxes" page shell for Turf's admin pages - every
 * stat block becomes a real wp-admin .postbox: collapsible, draggable/
 * reorderable (persisted via WP core's own existing meta-box-order AJAX
 * handler - nothing custom needed for that), and individually hideable via
 * the screen's native "Schermopties" panel. Same mechanism the Dashboard
 * widgets use, just pointed at our own pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		/* translators: %d: number of additional rows hidden behind the toggle */
		'moreLabel' => __( 'Show %d more', 'turf-stats' ),
		'lessLabel' => __( 'Show less', 'turf-stats' ),
	) );

	// The collapse is done in CSS that's in the <head> before the page paints,
	// so the extra rows are never rendered-then-hidden (which caused a visible
	// jump/reflow on load). JavaScript then only adds the "Show more" button and
	// toggles .turf-expanded to reveal them - no per-row show/hide on load.
	// A list can raise its initial visible count with data-turf-visible="N"
	// (the 404s table uses 20); the default is 5.

	wp_register_style( 'turf-postbox-more', false, array(), TURF_VERSION );
	wp_enqueue_style( 'turf-postbox-more' );
	wp_add_inline_style( 'turf-postbox-more',
		'.bk-stats-bar-list:not(.turf-expanded) > .bk-stats-bar-row:nth-child(n+6){display:none}' .
		'.postbox .inside > table:not(.bk-stats-heatmap):not(.turf-expanded) > tbody > tr:nth-child(n+6){display:none}' .
		'.postbox .inside > table[data-turf-visible="20"]:not(.turf-expanded) > tbody > tr:nth-child(-n+20){display:table-row}' .
		'.postbox .inside > table[data-turf-visible="20"]:not(.turf-expanded) > tbody > tr:nth-child(n+21){display:none}' .
		'.bk-stats-more-link{display:block;margin:6px 0 2px;background:none;border:none;padding:0;color:var(--wp-admin-theme-color,#2271b1);cursor:pointer;font-size:12px;text-decoration:underline}' .
		'.bk-stats-more-link:hover{text-decoration:none}' .
		/* Date-jump: sit inline right after the period tabs (same row), not on its own full-width block underneath. */
		'.turf-date-jump{clear:none;display:inline-flex;align-items:center;gap:6px;margin:0 0 0 16px;vertical-align:middle;font-size:13px;font-family:inherit}' .
		'.turf-date-jump label{font-weight:400;color:#50575e}' .
		'.turf-date-jump input[type="date"]{font-family:inherit;font-size:13px;vertical-align:middle}' .
		'.turf-date-jump .button{margin-left:0}'
	);

	wp_add_inline_style( 'turf-postbox-more', turf_admin_inline_css() );
}
add_action( 'admin_enqueue_scripts', 'turf_postboxes_enqueue' );

/**
 * Outputs the two hidden nonce fields WP core's own postboxes.js looks for
 * by ID (#meta-box-order-nonce, #closedpostboxesnonce) when it saves drag
 * order / open-closed state via AJAX (the 'meta-box-order' and
 * 'closed-postboxes' actions, both handled by wp-admin/includes/ajax-actions.php
 * with no extra wiring needed on our side). Core pages like post.php and
 * index.php (Dashboard) get these for free from their own templates; a
 * custom admin page has to print them itself, or those AJAX calls 404/fail
 * their nonce check silently and nothing ever gets persisted.
 */
function turf_postboxes_nonce_fields() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, turf_register_postbox_hook(), true ) ) {
		return;
	}

	wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
	wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
}
add_action( 'in_admin_header', 'turf_postboxes_nonce_fields' );

/**
 * Registers a metabox only if its content callback actually produces output -
 * so blocks with no data for the selected period disappear entirely instead of
 * showing an empty "No data yet" placeholder. The callback is run once here
 * (its output captured) and the captured HTML is what the box echoes at
 * do_meta_boxes() time, so there's no double query: the same work that would
 * have happened at render just happens a moment earlier, at registration.
 *
 * For this to hide a box, the render callback must emit nothing when it has no
 * data (return early without echoing), rather than printing a placeholder.
 */
function turf_maybe_add_meta_box( $id, $title, $render_callback, $hook, $context = 'normal' ) {
	ob_start();
	call_user_func( $render_callback );
	$html = trim( ob_get_clean() );

	if ( '' === $html ) {
		return;
	}

	add_meta_box( $id, $title, function () use ( $html ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup captured from the render callback.
	}, $hook, $context );
}

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
 * Shared period resolution, used by every Turf admin page and by the metabox
 * content callbacks registered for it.
 *
 * TURF_PERIOD_TODAY, TURF_PERIOD_YESTERDAY and a fixed-date day are all
 * "single calendar day" periods. Each is represented by the same shape: a
 * day-anchor (a `Y-m-d` string in the site's own timezone) plus, for the
 * previous-period comparison, a fixed $offset_days back from that anchor.
 * Keeping the three as one concept - rather than special-casing TODAY
 * everywhere - means "Gisteren" and "a specific date" behave exactly like
 * "Vandaag" (single-day headline totals, hourly chart, 7-day context chart)
 * with no extra branching in the renderers.
 *
 * TURF_PERIOD_TODAY is a dedicated sentinel for "Vandaag" - deliberately
 * not 0, which already means "Alles" (no date filter at all) throughout
 * the codebase's many `0 === $days` / `$days > 0` checks. Kept distinct so
 * those existing checks don't need to change: 0 still means "no filter",
 * any other value (positive N, or this sentinel) still means "yes, filter".
 *
 * TURF_PERIOD_YESTERDAY is a separate sentinel from TURF_PERIOD_TODAY so the
 * two can be distinguished in the tab list and in date math, but both funnel
 * through the same day-anchor helpers below.
 */
define( 'TURF_PERIOD_TODAY', -1 );
define( 'TURF_PERIOD_YESTERDAY', -2 );

/**
 * @return array<int|string,int> Map of URL `period` slug to its resolved
 *                               value. Positive ints are N-day ranges; 0 is
 *                               "Alles"; the two sentinels are single days.
 */
function turf_period_days_map() {
	return array(
		'today'     => TURF_PERIOD_TODAY,
		'yesterday' => TURF_PERIOD_YESTERDAY,
		'7'         => 7,
		'30'        => 30,
		'90'        => 90,
		'all'       => 0,
	);
}

/**
 * Whether a resolved period is a single calendar day (Today / Yesterday / a
 * fixed date from `?date=`) rather than an N-day range or "Alles". Drives the
 * single-day rendering branch (`turf_render_overview()`) and the hourly chart.
 *
 * @param int $days Resolved period value.
 */
function turf_is_single_day( $days ) {
	return TURF_PERIOD_TODAY === $days || TURF_PERIOD_YESTERDAY === $days || $days < TURF_PERIOD_TODAY;
}

/**
 * The calendar day (site timezone, `Y-m-d`) a single-day period is anchored
 * to. Today and Yesterday are derived dynamically; a fixed date passed via
 * `?date=YYYY-MM-DD` overrides both - that date is the anchor, and whether it
 * is "today" or "yesterday" is irrelevant to the math (the window is exactly
 * that calendar day).
 *
 * @param int $days Resolved period value (expected to be a single-day period).
 * @return string   `Y-m-d` in the site's configured timezone.
 */
function turf_single_day_anchor( $days ) {
	$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';

	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		// Validate the calendar date actually exists (rejects e.g. 2026-02-30).
		$parsed = date_parse( $date );
		if ( checkdate( $parsed['month'], $parsed['day'], $parsed['year'] ) ) {
			return $date;
		}
	}

	// No/invalid URL date: invert the fixed-date sentinel (days since epoch,
	// negated) so a passed period value still resolves to the right day
	// without the original request param - e.g. AJAX refreshes.
	if ( is_int( $days ) && $days < TURF_PERIOD_YESTERDAY ) {
		$epoch_days = TURF_PERIOD_TODAY - $days;
		$ts         = $epoch_days * DAY_IN_SECONDS;
		$guess      = gmdate( 'Y-m-d', $ts );
		$parsed     = date_parse( $guess );
		if ( checkdate( $parsed['month'], $parsed['day'], $parsed['year'] ) ) {
			return $guess;
		}
	}

	$tz = wp_timezone();
	$dt = new DateTime( 'now', $tz );
	$dt->setTime( 0, 0, 0 );

	if ( TURF_PERIOD_YESTERDAY === $days ) {
		$dt->modify( '-1 day' );
	}

	return $dt->format( 'Y-m-d' );
}

/**
 * Maximum number of rows any in-box ranked list fetches. The lists collapse to
 * a handful of visible rows and expand in place via js/postbox-more.js (no
 * page-reload pagination), so this is the upper bound the toggle can reveal -
 * enough to be useful at a glance without pulling an unbounded list into an
 * admin postbox. Filterable for sites that want longer lists.
 */
function turf_list_max() {
	return (int) apply_filters( 'turf_list_max', 50 );
}

/**
 * @param string $default_period Which tab to use when no ?period= is in the
 *                                URL yet - most pages keep the historical
 *                                '7' default; Statistieken opts into 'today'.
 * @return int Resolved period: a positive N (days), 0 ("Alles"), or one of
 *            the single-day sentinels (TURF_PERIOD_TODAY /
 *            TURF_PERIOD_YESTERDAY). A fixed `?date=` always resolves to that
 *            date's negative sentinel, regardless of the `period` tab.
 */
function turf_get_requested_days( $default_period = '7' ) {
	// A fixed date in the URL wins: it represents "that specific day", which
	// is its own single-day period independent of whatever tab is also set.
	$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		// Encode as a small negative sentinel the day helpers can invert
		// without the URL param: -(whole days from the Unix epoch to the
		// picked date). TURF_PERIOD_TODAY is already -1, so this stays well
		// below any N-day range value and reads back via turf_single_day_anchor().
		return TURF_PERIOD_TODAY - (int) ( strtotime( $date ) / DAY_IN_SECONDS );
	}

	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : $default_period;
	$map    = turf_period_days_map();

	return isset( $map[ $period ] ) ? $map[ $period ] : 7;
}

/**
 * SQL-ready (UTC, 'Y-m-d 00:00:00') start-of-period boundary - the
 * midnight-aligned "since the start of this period" pattern used by every
 * breakdown/listing query throughout the admin pages. Today's midnight for
 * TURF_PERIOD_TODAY / a fixed date, otherwise midnight $days days ago.
 */
function turf_period_start_sql_date( $days ) {
	if ( TURF_PERIOD_TODAY === $days ) {
		return turf_local_midnight_utc( 0 );
	}

	return gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
}

/**
 * The calendar-day boundary (UTC `Y-m-d H:i:s`) a single-day period covers,
 * as `[ $start, $end )` - midnight of the anchor day to midnight of the next,
 * both converted to UTC so they compare correctly against `viewed_at`. For a
 * fixed date this is exactly that date; for Today/Yesterday it's derived from
 * the site timezone (the same local-midnight logic as the existing functions).
 *
 * @param int $days Resolved single-day period value.
 * @return array{0: string, 1: string} [ $start_utc, $end_utc ].
 */
function turf_single_day_window( $days ) {
	$anchor = turf_single_day_anchor( $days );

	$tz = wp_timezone();
	$dt = DateTime::createFromFormat( 'Y-m-d', $anchor, $tz );
	$dt->setTime( 0, 0, 0 );

	$start_dt = clone $dt;
	$end_dt   = clone $dt;
	$end_dt->modify( '+1 day' );

	$start_dt->setTimezone( new DateTimeZone( 'UTC' ) );
	$end_dt->setTimezone( new DateTimeZone( 'UTC' ) );

	return array( $start_dt->format( 'Y-m-d H:i:s' ), $end_dt->format( 'Y-m-d H:i:s' ) );
}

/**
 * How many calendar days back (in the site timezone) the single-day anchor
 * sits from today - 0 for today, 1 for yesterday, N for a fixed date N days
 * ago. Used to translate a single-day period into the `local_midnight_utc()`
 * offsets the original Today-only code already understood, so the existing
 * boundary math reuses unchanged.
 *
 * @param int $days Resolved single-day period value.
 * @return int Days back from today to the anchor day.
 */
function turf_single_day_anchor_offset( $days ) {
	if ( TURF_PERIOD_TODAY === $days ) {
		return 0;
	}

	if ( TURF_PERIOD_YESTERDAY === $days ) {
		return 1;
	}

	// Fixed date: floor the local-midnight-to-local-midnight day difference.
	$tz = wp_timezone();
	$today = new DateTime( 'now', $tz );
	$today->setTime( 0, 0, 0 );

	$anchor = DateTime::createFromFormat( 'Y-m-d', turf_single_day_anchor( $days ), $tz );
	$anchor->setTime( 0, 0, 0 );

	return (int) round( ( $today->getTimestamp() - $anchor->getTimestamp() ) / DAY_IN_SECONDS );
}

/**
 * Resolved `[ $start, $end )` UTC boundaries for any period and offset, the
 * single source of truth every range query should use. Unifies the three
 * shapes that used to be special-cased inline:
 *
 *  - `0` ("Alles"): both null - no date filter at all.
 *  - single day (Today/Yesterday/fixed date), offset 0: the anchor day, with
 *    the *upper* bound "now" when the anchor is today (so Today shows
 *    "so far", like before) but the full day's midnight when the anchor is in
 *    the past (yesterday / a picked date).
 *  - single day, offset > 0: the same anchor day shifted back by $offset_days
 *    (the previous-period comparison - day before yesterday, or the day before
 *    a picked date). Always a full midnight-to-midnight day.
 *  - N-day range: the trailing window ending now (offset 0) or shifted back.
 *
 * @param int $days        Resolved period value.
 * @param int $offset_days How many days back the window's end sits (0 = now).
 * @return array{0: string|null, 1: string|null} [ $start, $end ] (null = open).
 */
function turf_period_window( $days, $offset_days = 0 ) {
	if ( 0 === $days ) {
		return array( null, null );
	}

	if ( turf_is_single_day( $days ) ) {
		$anchor_offset = turf_single_day_anchor_offset( $days );

		// Start of the (anchor - offset) day; end of that same day.
		$start = turf_local_midnight_utc( $anchor_offset + $offset_days );

		// The current/live day's upper bound is "now", not next midnight -
		// matches the historical Today behaviour (partial day so far).
		if ( 0 === $offset_days && 0 === $anchor_offset ) {
			$end = current_time( 'mysql', true );
		} else {
			$end = turf_local_midnight_utc( $anchor_offset + $offset_days - 1 );
		}

		return array( $start, $end );
	}

	$end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );
	$start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $offset_days + $days ) . ' days' ) );

	return array( $start, $end );
}

/**
 * A `viewed_at` (or `visited_at`) WHERE fragment + params for a period, the
 * drop-in replacement for the old `... >= turf_period_start_sql_date($days)`
 * pattern. Unlike that helper, this also closes the window's *upper* bound for
 * single-day periods - without it, "Gisteren" / a picked date would silently
 * include every day from the anchor up to now (the anchor day plus all later
 * ones), which is wrong. N-day ranges and "Alles" keep the open-ended
 * `>= start` shape they always had.
 *
 * @param int    $days     Resolved period value.
 * @param string $column   Column to compare (default `v.viewed_at`).
 * @return array{0: string, 1: string[]} [ $sql_fragment, $params ].
 */
function turf_period_where_sql( $days, $column = 'v.viewed_at' ) {
	if ( 0 === $days ) {
		return array( '', array() );
	}

	if ( turf_is_single_day( $days ) ) {
		list( $start, $end ) = turf_period_window( $days, 0 );

		return array( "AND $column >= %s AND $column < %s", array( $start, $end ) );
	}

	$start = turf_period_start_sql_date( $days );

	return array( "AND $column >= %s", array( $start ) );
}

/**
 * Per-day site totals for the 7 days ending on a single-day period's anchor.
 * Used as the "context" chart under the single-day overviews (Vandaag /
 * Gisteren / a fixed date) - the same role turf_get_daily_site_totals( 7 )
 * plays for TURF_PERIOD_TODAY, but anchored to the chosen day so the chart's
 * last (rightmost) bar is the selected day rather than always "today".
 *
 * @param int $days Resolved single-day period value.
 * @return array[] Same shape as turf_get_daily_site_totals(): one row per day
 *                 with date/views/visitors, oldest first, ending on the anchor.
 */
function turf_get_daily_site_totals_ending_on( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	list( $start, $end ) = turf_single_day_window( $days );

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(v.viewed_at) AS day, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where
		AND v.viewed_at >= %s AND v.viewed_at < %s
		GROUP BY DATE(v.viewed_at)",
		array_merge( $params, array( $start, $end ) )
	), OBJECT_K );

	$anchor = turf_single_day_anchor( $days );
	$daily  = array();

	// 7 days: the anchor day itself plus the 6 days before it.
	for ( $i = 6; $i >= 0; $i-- ) {
		$date = gmdate( 'Y-m-d', strtotime( "{$anchor} -{$i} days" ) );
		$row  = isset( $results[ $date ] ) ? $results[ $date ] : null;

		$daily[] = array(
			'date'     => $date,
			'views'    => $row ? (int) $row->views : 0,
			'visitors' => $row ? (int) $row->visitors : 0,
		);
	}

	return $daily;
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
	if ( turf_is_single_day( $days ) ) {
		return 1;
	}

	return $days;
}

/**
 * Convert a PHP date-format string (get_option('date_format')) into the
 * format token set jQuery UI's datepicker understands. Only the tokens we
 * realistically expect in a WP date format are mapped; anything unknown is
 * passed through verbatim so exotic formats degrade gracefully.
 */
function turf_render_period_tabs( $base_url, $default_period = '7' ) {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : $default_period;
	$labels = array(
		'today'     => __( 'Today', 'turf-stats' ),
		'yesterday' => __( 'Yesterday', 'turf-stats' ),
		'7'         => __( '7 days', 'turf-stats' ),
		'30'        => __( '30 days', 'turf-stats' ),
		'90'        => __( '90 days', 'turf-stats' ),
		'all'       => __( 'All', 'turf-stats' ),
	);

	$current_date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $current_date ) ) {
		$current_date = '';
	}
	// Preserve any other query args (e.g. order/post-type filters) across the
	// date jump by re-emitting them as hidden inputs, then override period/
	// date with the picker's submission.
	$hidden = '';
	foreach ( $_GET as $k => $v ) {
		if ( ! in_array( $k, array( 'date', 'period' ), true ) ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
	}
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

	<form class="turf-date-jump" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<?php echo $hidden; ?>
		<label for="turf-date"><?php echo esc_html__( 'Go to date', 'turf-stats' ); ?></label>
		<input type="date" id="turf-date" name="date" value="<?php echo esc_attr( $current_date ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
		<?php submit_button( __( 'Toon', 'turf-stats' ), 'secondary', '', false ); ?>
		<?php if ( $current_date ) : ?>
			<a class="button" href="<?php echo esc_url( remove_query_arg( array( 'date', 'period' ), $base_url ) ); ?>"><?php echo esc_html__( 'Clear date', 'turf-stats' ); ?></a>
		<?php endif; ?>
	</form>
	<br class="clear" />
	<?php
}
