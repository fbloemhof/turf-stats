<?php
/**
 * "Statistieken" admin page - site-wide overview (à la Jetpack) plus top
 * viewed posts/archive pages per period, paginated.
 *
 * Note: legacy views imported via the CLI command (see post-views-cli.php)
 * have no timestamp and no visitor data, so they only show up in the
 * "Weergaven" total under "Alles" - not in the 7/30/90-day windows, and
 * never in "Bezoekers" (unique visitors), which only exist for views
 * recorded since this plugin went live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_admin_menu() {
	$hook = add_menu_page(
		__( 'Statistics', 'turf-stats' ),
		__( 'Statistics', 'turf-stats' ),
		'manage_options',
		'turf-stats',
		'turf_render_admin_page',
		'dashicons-chart-bar',
		26
	);

	add_action( "load-$hook", 'turf_views_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_admin_menu' );

/**
 * Three sections, each its own context group so they can render as
 * separate areas on the page (one full-width box, a 2-up grid of compact
 * breakdowns, then the rest full-width again) while still being one
 * continuous drag/drop scope for postboxes.js: 'turf_overview' (the chart +
 * stat boxes), 'turf_compact' (the device/browser/etc. breakdowns, laid out
 * two-per-row via turf_render_postbox_grid_column()), and 'turf_wide' (peak
 * hours, then the per-post-type/taxonomy tables).
 */
function turf_views_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days( 'today' );

	add_meta_box( 'turf_overview', __( 'Overview', 'turf-stats' ), function () use ( $days ) {
		turf_render_overview( $days );
	}, $hook, 'turf_overview' );

	add_meta_box( 'turf_online_now_pages', __( 'Currently viewed', 'turf-stats' ), 'turf_render_online_now_pages', $hook, 'turf_overview' );

	turf_maybe_add_meta_box( 'turf_content_activity', __( 'Content activity', 'turf-stats' ), function () use ( $days ) {
		turf_render_content_activity( $days );
	}, $hook, 'turf_overview' );

	$compact_boxes = array(
		array( 'turf_device', __( 'Device', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'device_type', $days );
		} ),
		array( 'turf_browser', __( 'Browser', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'browser', $days );
		} ),
		array( 'turf_os', __( 'Operating system', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'os', $days );
		} ),
		array( 'turf_screen', __( 'Screen resolution', 'turf-stats' ), function () use ( $days ) {
			turf_render_screen_breakdown( $days );
		} ),
		array( 'turf_language', __( 'Language', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'language', $days );
		} ),
		array( 'turf_country', __( 'Country of origin', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'country', $days );
		} ),
		array( 'turf_new_returning', __( 'New vs. returning', 'turf-stats' ), function () use ( $days ) {
			turf_render_new_vs_returning( $days );
		} ),
		array( 'turf_referrer', __( 'Source', 'turf-stats' ), function () use ( $days ) {
			turf_render_referrer_breakdown( $days );
		} ),
		array( 'turf_top_referrers', __( 'Top referring sites', 'turf-stats' ), function () use ( $days ) {
			turf_render_top_referrer_hosts( $days );
		} ),
		array( 'turf_utm_source', __( 'Campaign source (UTM)', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'utm_source', $days, true );
		} ),
		array( 'turf_utm_medium', __( 'Campaign medium (UTM)', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'utm_medium', $days, true );
		} ),
		array( 'turf_utm_content', __( 'Campaign content (UTM)', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'utm_content', $days, true );
		} ),
		array( 'turf_other_pages', __( 'Other pages', 'turf-stats' ), function () use ( $days ) {
			turf_render_other_pages_breakdown( $days );
		} ),
	);

	foreach ( $compact_boxes as $box ) {
		list( $id, $title, $callback ) = $box;
		turf_maybe_add_meta_box( $id, $title, $callback, $hook, 'turf_compact' );
	}

	turf_maybe_add_meta_box( 'turf_caching', __( 'Caching', 'turf-stats' ), function () use ( $days ) {
		turf_render_caching( $days );
	}, $hook, 'turf_wide' );

	add_meta_box( 'turf_peak_hours', __( 'Peak hours', 'turf-stats' ), function () use ( $days ) {
		// A single day is too sparse for a meaningful 7x24 heatmap - shows
		// the last 7 days for context instead, same as the Vandaag chart.
		turf_render_peak_hours( turf_is_single_day( $days ) ? 7 : $days );
	}, $hook, 'turf_wide' );

	$post_types = turf_trackable_post_types();
	usort( $post_types, function ( $a, $b ) {
		return strnatcasecmp( turf_get_post_type_label( $a ), turf_get_post_type_label( $b ) );
	} );

	foreach ( $post_types as $post_type ) {
		turf_maybe_add_meta_box(
			'turf_posts_' . $post_type,
			turf_get_post_type_label( $post_type ),
			function () use ( $post_type, $days ) {
				turf_render_admin_table( $post_type, $days );
			},
			$hook,
			'turf_wide'
		);
	}

	turf_maybe_add_meta_box( 'turf_comments', __( 'Most discussed', 'turf-stats' ), function () use ( $days ) {
		turf_render_top_commented_posts( $days );
	}, $hook, 'turf_wide' );

	$taxonomies = turf_trackable_taxonomies();
	usort( $taxonomies, function ( $a, $b ) {
		return strnatcasecmp( turf_get_taxonomy_label( $a ), turf_get_taxonomy_label( $b ) );
	} );

	foreach ( $taxonomies as $taxonomy ) {
		turf_maybe_add_meta_box(
			'turf_terms_' . $taxonomy,
			turf_get_taxonomy_label( $taxonomy ),
			function () use ( $taxonomy, $days ) {
				turf_render_admin_terms_table( $taxonomy, $days );
			},
			$hook,
			'turf_wide'
		);
	}
}

/**
 * Builds a `post_type IN (%s, %s, ...)` placeholder string plus the matching
 * args array, for queries that should span all trackable post types at once.
 */
function turf_post_type_in_clause() {
	$types        = turf_trackable_post_types();
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

	return array( $placeholders, $types );
}

/**
 * Site-wide queries need to count post views, taxonomy-archive views, and
 * "other" page views (author/date archives, search, etc. - post_id,
 * term_id and page_type are mutually exclusive per row, see
 * includes/views.php) together. This builds the shared JOIN + WHERE that
 * restricts the first two to currently-live, trackable content, so deleted
 * posts/terms or types that were later excluded via the filters don't linger
 * in the totals; "other" rows have no such per-object check (there's no
 * single post/term to validate against), they're just always included.
 *
 * @param string $alias Table alias of the events table in the calling query.
 *                      The default 'v' keeps the historical 'p'/'tt' join
 *                      aliases (some callers reference those directly); any
 *                      other alias derives its own join aliases, so the same
 *                      conditions can also scope a correlated subquery (see
 *                      turf_get_online_now_pages()) without colliding with
 *                      the outer query's joins. Internal literal, never user
 *                      input.
 *
 * @return array{0: string, 1: string, 2: array} [$join_sql, $where_sql, $params]
 */
function turf_site_join_and_where( $alias = 'v' ) {
	global $wpdb;

	list( $post_placeholders, $post_types ) = turf_post_type_in_clause();

	$taxonomies        = turf_trackable_taxonomies();
	$tax_placeholders  = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

	$p  = 'v' === $alias ? 'p' : "{$alias}_p";
	$tt = 'v' === $alias ? 'tt' : "{$alias}_tt";

	$join = "LEFT JOIN $wpdb->posts $p ON $p.ID = $alias.post_id
		LEFT JOIN $wpdb->term_taxonomy $tt ON $tt.term_id = $alias.term_id";

	$where = "(
		($alias.post_id IS NOT NULL AND $p.post_type IN ($post_placeholders) AND $p.post_status = 'publish')
		OR ($alias.term_id IS NOT NULL AND $tt.taxonomy IN ($tax_placeholders))
		OR ($alias.page_type IS NOT NULL)
	)";

	return array( $join, $where, array_merge( $post_types, $taxonomies ) );
}

/**
 * Site-wide views + unique visitors for a single date range (UTC).
 *
 * @param int $days        Length of the range in days, or TURF_PERIOD_TODAY.
 * @param int $offset_days How many days ago the range ends (0 = ending now).
 *                          For TURF_PERIOD_TODAY specifically, 0 means
 *                          "today" (midnight to now) and 1 means "yesterday"
 *                          (midnight to midnight) - the generic "shift the
 *                          whole window back by $days" offset math below
 *                          doesn't apply to a single calendar day.
 */
function turf_get_range_site_totals( $days, $offset_days = 0 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	list( $start, $end ) = turf_period_window( $days, $offset_days );

	$bounds = '';
	if ( null !== $start ) {
		$bounds = 'AND v.viewed_at >= %s AND v.viewed_at < %s';
		$params = array_merge( $params, array( $start, $end ) );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where
		$bounds",
		$params
	) );

	return array( 'views' => (int) $row->views, 'visitors' => (int) $row->visitors );
}

/**
 * Raw (non-deduplicated) pageviews for a single date range, from the
 * wp_turf_raw_hits aggregate - the counterpart to the deduped "views" above.
 * Boundaries mirror turf_get_range_site_totals() exactly so the two headline
 * numbers always cover the same window. Returns null when there's no raw data
 * for the range at all (e.g. the whole range predates this feature), so the
 * caller can hide the box rather than show a misleading 0.
 */
function turf_get_range_raw_views( $days, $offset_days = 0 ) {
	global $wpdb;

	$table = turf_raw_hits_table();

	if ( 0 === $days ) {
		// "All" - whole table, no date bounds (the offset math below collapses
		// to an empty range at days = 0).
		$sum = $wpdb->get_var( "SELECT SUM(hits) FROM $table" );
	} else {
		list( $start, $end ) = turf_period_window( $days, $offset_days );
		$sum = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(hits) FROM $table WHERE hit_hour >= %s AND hit_hour < %s",
			$start,
			$end
		) );
	}

	return ( null === $sum ) ? null : (int) $sum;
}

/**
 * Raw browser pageviews and origin (PHP) renders for a single date range, in
 * one query - the two numbers behind the cache-offload metric. Same boundary
 * logic as turf_get_range_raw_views(). Returns null when there's no raw-hit
 * data at all for the range (so the caching box can hide rather than divide by
 * zero).
 *
 * @return array{raw:int, origin:int}|null
 */
function turf_get_range_cache_totals( $days, $offset_days = 0 ) {
	global $wpdb;

	$table = turf_raw_hits_table();

	if ( 0 === $days ) {
		// "All" - whole table, no date bounds (the offset math below collapses
		// to an empty range at days = 0).
		$row = $wpdb->get_row( "SELECT SUM(hits) AS raw, SUM(origin_hits) AS origin FROM $table" );
	} else {
		list( $start, $end ) = turf_period_window( $days, $offset_days );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(hits) AS raw, SUM(origin_hits) AS origin
			FROM $table WHERE hit_hour >= %s AND hit_hour < %s",
			$start,
			$end
		) );
	}

	if ( ! $row || null === $row->raw ) {
		return null;
	}

	return array( 'raw' => (int) $row->raw, 'origin' => (int) $row->origin );
}

/**
 * Site-wide views + unique visitors per day for the last $days days, zero-filled.
 */
function turf_get_daily_site_totals( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	// Single-day periods (Today/Yesterday/fixed date) show the 7 days *ending
	// on* the anchor day as their context chart - same role as the old
	// hard-coded 7 for Today, but anchored so the rightmost bar is the
	// selected day rather than always "today".
	if ( turf_is_single_day( $days ) ) {
		return turf_get_daily_site_totals_ending_on( $days );
	}

	$start = turf_period_start_sql_date( $days );

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(v.viewed_at) AS day, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where
		AND v.viewed_at >= %s
		GROUP BY DATE(v.viewed_at)",
		array_merge( $params, array( $start ) )
	), OBJECT_K );

	$daily = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
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
 * All-time site totals: "Weergaven" includes the imported legacy baseline
 * (postmeta running total), "Bezoekers" only reflects views recorded since
 * this plugin went live (the event table has no visitor data for imports).
 */
function turf_get_alltime_site_totals() {
	global $wpdb;

	list( $placeholders, $post_types ) = turf_post_type_in_clause();

	$post_views = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(m.meta_value + 0) FROM $wpdb->posts p
		INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list; values go through prepare().
		array_merge( array( TURF_META_KEY ), $post_types )
	) );

	$taxonomies       = turf_trackable_taxonomies();
	$tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

	$term_views = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(m.meta_value + 0) FROM $wpdb->term_taxonomy tt
		INNER JOIN $wpdb->termmeta m ON m.term_id = tt.term_id AND m.meta_key = %s
		WHERE tt.taxonomy IN ($tax_placeholders)",
		array_merge( array( TURF_META_KEY ), $taxonomies )
	) );

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$visitors = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.visitor_hash) FROM $table v $join WHERE $where",
		$params
	) );

	return array( 'views' => (int) $post_views + (int) $term_views, 'visitors' => (int) $visitors );
}

/**
 * Percentage change vs. a previous value. Null means "not computable"
 * (previous was 0) - rendered as "nieuw" rather than a bogus percentage.
 */
function turf_pct_change( $current, $previous ) {
	if ( $previous <= 0 ) {
		return $current > 0 ? null : 0;
	}

	return (int) round( ( ( $current - $previous ) / $previous ) * 100 );
}

/**
 * Views-per-visitor as a locale-formatted string with one decimal (e.g.
 * "1,6"), or "—" when there are no visitors to divide by. Uses the deduped
 * "Weergaven" number, the same one shown in its own box - so the ratio is
 * "how many distinct things did the average visitor look at", not inflated by
 * raw repeat hits.
 */
function turf_format_views_per_visitor( $views, $visitors ) {
	if ( $visitors <= 0 ) {
		return '—';
	}

	return number_format_i18n( $views / $visitors, 1 );
}

function turf_render_change_badge( $change ) {
	if ( null === $change ) {
		echo '<span class="bk-stats-box__change bk-stats-box__change--new">' . esc_html__( 'new', 'turf-stats' ) . '</span>';
		return;
	}

	$class     = $change >= 0 ? 'up' : 'down';
	$direction = $change >= 0 ? '↑' : '↓';

	printf(
		'<span class="bk-stats-box__change bk-stats-box__change--%s">%s %s%%</span>',
		esc_attr( $class ),
		esc_html( $direction ),
		esc_html( abs( $change ) )
	);
}

/**
 * Just the label/value/change markup, with no .bk-stats-box wrapper -
 * shared between the normal page render (turf_render_stat_box() below
 * wraps this) and the AJAX refresh handler (which re-renders only this
 * inner markup into an existing, already-wrapped box - see
 * turf_ajax_overview_stats()).
 */
function turf_render_stat_box_inner( $label, $value, $change, $suffix = '', $preformatted = false ) {
	// $preformatted values (e.g. "2m 45s", "1,6") are already display-ready
	// strings - don't run them through number_format_i18n(), which expects a
	// bare number.
	$display = $preformatted ? $value . $suffix : number_format_i18n( $value ) . $suffix;
	?>
	<span class="bk-stats-box__label"><?php echo esc_html( $label ); ?></span>
	<span class="bk-stats-box__value"><?php echo esc_html( $display ); ?></span>
	<?php if ( false !== $change ) : ?>
		<?php turf_render_change_badge( $change ); ?>
	<?php endif; ?>
	<?php
}

/**
 * @param string $key Optional - when set, the box gets id="turf-stat-$key"
 *                     so the AJAX refresh script can find and update it.
 */
function turf_render_stat_box( $label, $value, $change, $suffix = '', $key = '', $preformatted = false ) {
	?>
	<div class="bk-stats-box"<?php echo $key ? ' id="turf-stat-' . esc_attr( $key ) . '"' : ''; ?>>
		<?php turf_render_stat_box_inner( $label, $value, $change, $suffix, $preformatted ); ?>
	</div>
	<?php
}

function turf_capture_stat_box_inner( $label, $value, $change, $suffix = '', $preformatted = false ) {
	ob_start();
	turf_render_stat_box_inner( $label, $value, $change, $suffix, $preformatted );
	return ob_get_clean();
}

/**
 * Keeps Weergaven/Bezoekers/Reacties/Bouncepercentage live without a page
 * reload - same idea as "Nu online" (includes/online-now.php), just for
 * the rest of the overview row. The chart, peak-hours heatmap, and every
 * breakdown/table below it are NOT live - re-rendering those via AJAX
 * would mean re-doing far more work for far less benefit (they don't
 * change meaningfully within a few seconds the way "right now" numbers do).
 */
function turf_overview_refresh_enqueue( $hook ) {
	if ( 'toplevel_page_turf-stats' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'turf-overview-refresh',
		TURF_URL . 'js/overview-refresh.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-overview-refresh', 'turfOverviewRefresh', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'turf_overview_stats' ),
		'interval' => 30000, // ms
	) );
}
add_action( 'admin_enqueue_scripts', 'turf_overview_refresh_enqueue' );

function turf_ajax_overview_stats() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_overview_stats' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$days  = isset( $_POST['days'] ) ? (int) $_POST['days'] : 7;
	$boxes = array();

	// A fixed date arriving from the refresh polling must reach the day
	// helpers (they read $_GET['date']), so surface it there for the
	// lifetime of this request.
	if ( ! empty( $_POST['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['date'] ) ) ) ) {
		$_GET['date'] = sanitize_text_field( wp_unslash( $_POST['date'] ) );
	}

	if ( 0 === $days ) {
		$totals   = turf_get_alltime_site_totals();
		$comments = turf_get_comment_totals( 0 );

		$boxes['weergaven'] = turf_capture_stat_box_inner( __( 'Views', 'turf-stats' ), $totals['views'], false );
		$boxes['bezoekers'] = turf_capture_stat_box_inner( __( 'Visitors', 'turf-stats' ), $totals['visitors'], false );
		$boxes['reacties']  = turf_capture_stat_box_inner( __( 'Comments', 'turf-stats' ), $comments, false );
	} else {
		$offset            = turf_previous_period_offset( $days );
		$current           = turf_get_range_site_totals( $days, 0 );
		$previous          = turf_get_range_site_totals( $days, $offset );
		$current_comments  = turf_get_comment_totals( $days, 0 );
		$previous_comments = turf_get_comment_totals( $days, $offset );

		$boxes['weergaven'] = turf_capture_stat_box_inner( __( 'Views', 'turf-stats' ), $current['views'], turf_pct_change( $current['views'], $previous['views'] ) );

		$raw = turf_get_range_raw_views( $days, 0 );
		if ( null !== $raw ) {
			$raw_prev      = turf_get_range_raw_views( $days, $offset );
			$boxes['rauw'] = turf_capture_stat_box_inner( __( 'Raw views', 'turf-stats' ), $raw, turf_pct_change( $raw, (int) $raw_prev ) );
		}

		$boxes['bezoekers']   = turf_capture_stat_box_inner( __( 'Visitors', 'turf-stats' ), $current['visitors'], turf_pct_change( $current['visitors'], $previous['visitors'] ) );
		$boxes['perbezoeker'] = turf_capture_stat_box_inner( __( 'Views/visitor', 'turf-stats' ), turf_format_views_per_visitor( $current['views'], $current['visitors'] ), false, '', true );
		$boxes['reacties']    = turf_capture_stat_box_inner( __( 'Comments', 'turf-stats' ), $current_comments, turf_pct_change( $current_comments, $previous_comments ) );

		$bounce_rate = turf_get_bounce_rate( $days );

		if ( null !== $bounce_rate ) {
			$boxes['bounce'] = turf_capture_stat_box_inner( __( 'Bounce rate', 'turf-stats' ), $bounce_rate, false, '%' );
		}

		$avg_seconds = turf_get_avg_session_seconds( $days );

		if ( null !== $avg_seconds ) {
			$boxes['duur'] = turf_capture_stat_box_inner( __( 'Avg. time/visit', 'turf-stats' ), turf_format_duration( $avg_seconds ), false, '', true );
		}

		// Always included (turf_format_load_time( null ) renders '—'), unlike
		// the older optional boxes: the refresh JS can only update boxes that
		// already exist in the DOM, so a key that comes and goes would either
		// never appear (absent at page load) or go stale (absent from a later
		// response) - see js/overview-refresh.js.
		$boxes['laadtijd'] = turf_capture_stat_box_inner( __( 'Avg. load time', 'turf-stats' ), turf_format_load_time( turf_get_avg_load_time_ms( $days ) ), false, '', true );
	}

	wp_send_json_success( array( 'boxes' => $boxes ) );
}
add_action( 'wp_ajax_turf_overview_stats', 'turf_ajax_overview_stats' );

/**
 * Site-wide "Afgelopen N dagen" overview: totals with %-change vs. the
 * preceding period, plus a daily views/visitors bar chart (à la Jetpack).
 * For "Alles" there's no meaningful daily resolution, so just the totals.
 * Includes both post views and taxonomy-archive views.
 */
function turf_render_overview( $days ) {
	if ( 0 === $days ) {
		$totals   = turf_get_alltime_site_totals();
		$comments = turf_get_comment_totals( 0 );
		?>
		<div class="bk-stats-overview">
			<div class="bk-stats-overview__totals" id="turf-overview-totals" data-days="<?php echo esc_attr( $days ); ?>">
				<?php turf_render_online_now(); ?>
				<?php turf_render_stat_box( __( 'Views', 'turf-stats' ), $totals['views'], false, '', 'weergaven' ); ?>
				<?php turf_render_stat_box( __( 'Visitors', 'turf-stats' ), $totals['visitors'], false, '', 'bezoekers' ); ?>
				<?php turf_render_stat_box( __( 'Comments', 'turf-stats' ), $comments, false, '', 'reacties' ); ?>
			</div>
		</div>
		<?php
		return;
	}

	if ( turf_is_single_day( $days ) ) {
		$current           = turf_get_range_site_totals( $days, 0 );
		$previous          = turf_get_range_site_totals( $days, 1 );
		$current_comments  = turf_get_comment_totals( $days, 0 );
		$previous_comments = turf_get_comment_totals( $days, 1 );
		?>
		<div class="bk-stats-overview">
			<div class="bk-stats-overview__totals" id="turf-overview-totals" data-days="<?php echo esc_attr( $days ); ?>" data-date="<?php echo esc_attr( turf_single_day_anchor( $days ) ); ?>">
				<?php turf_render_online_now(); ?>
				<?php turf_render_overview_stat_boxes( $days, $current, $previous, $current_comments, $previous_comments, 1 ); ?>
			</div>
			<?php turf_render_hourly_visitors_chart( $days ); ?>
			<?php turf_render_daily_chart( turf_get_daily_site_totals( $days ) ); ?>
		</div>
		<?php
		return;
	}

	$daily             = turf_get_daily_site_totals( $days );
	$offset            = turf_previous_period_offset( $days );
	$current           = turf_get_range_site_totals( $days, 0 );
	$previous          = turf_get_range_site_totals( $days, $offset );
	$current_comments  = turf_get_comment_totals( $days, 0 );
	$previous_comments = turf_get_comment_totals( $days, $offset );
	?>
	<div class="bk-stats-overview">
		<div class="bk-stats-overview__totals" id="turf-overview-totals" data-days="<?php echo esc_attr( $days ); ?>">
			<?php turf_render_online_now(); ?>
			<?php turf_render_overview_stat_boxes( $days, $current, $previous, $current_comments, $previous_comments, $offset ); ?>
		</div>

		<?php turf_render_daily_chart( $daily ); ?>
	</div>
	<?php
}

/**
 * The headline stat boxes shared by the "Vandaag" and N-day overviews
 * (Weergaven, Rauwe weergaven, Bezoekers, Weergaven/bezoeker, Reacties,
 * Bouncepercentage, Gem. tijd/bezoek). Kept in one place so the two branches
 * of turf_render_overview() - and the AJAX refresh handler, which re-renders
 * the same set - can't drift apart. Every box carries a key so the 30s
 * refresh can target it; the session-derived boxes (bounce, duration) are
 * simply omitted when there's no data for them.
 *
 * @param array $current  Current-period totals from turf_get_range_site_totals().
 * @param array $previous Preceding-period totals, for the %-change badges.
 * @param int   $offset   The offset used for $previous (so raw views can use
 *                        the matching preceding window).
 */
function turf_render_overview_stat_boxes( $days, $current, $previous, $current_comments, $previous_comments, $offset ) {
	turf_render_stat_box( __( 'Views', 'turf-stats' ), $current['views'], turf_pct_change( $current['views'], $previous['views'] ), '', 'weergaven' );

	$raw = turf_get_range_raw_views( $days, 0 );
	if ( null !== $raw ) {
		$raw_prev = turf_get_range_raw_views( $days, $offset );
		turf_render_stat_box( __( 'Raw views', 'turf-stats' ), $raw, turf_pct_change( $raw, (int) $raw_prev ), '', 'rauw' );
	}

	turf_render_stat_box( __( 'Visitors', 'turf-stats' ), $current['visitors'], turf_pct_change( $current['visitors'], $previous['visitors'] ), '', 'bezoekers' );
	turf_render_stat_box( __( 'Views/visitor', 'turf-stats' ), turf_format_views_per_visitor( $current['views'], $current['visitors'] ), false, '', 'perbezoeker', true );
	turf_render_stat_box( __( 'Comments', 'turf-stats' ), $current_comments, turf_pct_change( $current_comments, $previous_comments ), '', 'reacties' );

	$bounce_rate = turf_get_bounce_rate( $days );
	if ( null !== $bounce_rate ) {
		turf_render_stat_box( __( 'Bounce rate', 'turf-stats' ), $bounce_rate, false, '%', 'bounce' );
	}

	$avg_seconds = turf_get_avg_session_seconds( $days );
	if ( null !== $avg_seconds ) {
		turf_render_stat_box( __( 'Avg. time/visit', 'turf-stats' ), turf_format_duration( $avg_seconds ), false, '', 'duur', true );
	}

	// Rendered even without data ('—') so the box exists in the DOM for the
	// AJAX refresh to fill in once measurements arrive - see the matching
	// note in turf_ajax_overview_stats().
	turf_render_stat_box( __( 'Avg. load time', 'turf-stats' ), turf_format_load_time( turf_get_avg_load_time_ms( $days ) ), false, '', 'laadtijd', true );
}

/**
 * The daily views/visitors bar chart (à la Jetpack), shared between the
 * "Vandaag" overview (which always shows the last 7 days here for context,
 * regardless of the single-day headline totals above it) and the regular
 * N-day overview (which shows exactly the selected period).
 */
function turf_render_daily_chart( $daily ) {
	$max   = max( 1, max( array_column( $daily, 'views' ) ) );
	$count = count( $daily );

	// Value labels also show on the 30/90-day charts, not just the default
	// 7-day view - but a 90-column chart has far less width per bar than a
	// 7-column one, so two things adapt as columns get denser: the label
	// shrinks in steps, and only every Nth bar gets one at all (a 3+ digit
	// label is wider than a 90-day column, so labelling every bar would just
	// smear neighbours into each other). $label_every is anchored to the
	// NEWEST bar so the most recent day is always labelled, counting back
	// from there (weekly steps on the 90-day chart).
	if ( $count <= 14 ) {
		$density_class = '';
		$label_every   = 1;
	} elseif ( $count <= 45 ) {
		$density_class = ' bk-stats-chart--dense';
		$label_every   = 2;
	} else {
		$density_class = ' bk-stats-chart--very-dense';
		$label_every   = 7;
	}

	// Bars are scaled to 88% of the plot height instead of 100%, reserving a
	// consistent strip above the tallest possible bar for its value label -
	// so the label is never clipped, at any period length.
	$plot_max_pct = 88;
	?>
	<div class="bk-stats-overview__legend">
		<span class="bk-stats-legend bk-stats-legend--views"><?php esc_html_e( 'Views', 'turf-stats' ); ?></span>
		<span class="bk-stats-legend bk-stats-legend--visitors"><?php esc_html_e( 'Visitors', 'turf-stats' ); ?></span>
	</div>

	<div class="bk-stats-chart<?php echo esc_attr( $density_class ); ?>">
		<?php foreach ( array_values( $daily ) as $i => $day ) : ?>
			<?php
			$views_pct    = round( ( $day['views'] / $max ) * $plot_max_pct );
			$visitors_pct = round( ( $day['visitors'] / $max ) * $plot_max_pct );
			$show_value   = 0 === ( $count - 1 - $i ) % $label_every;
			$title        = sprintf(
				/* translators: 1: date, 2: number of views, 3: number of visitors */
				__( '%1$s — %2$s views, %3$s visitors', 'turf-stats' ),
				date_i18n( 'd M', strtotime( $day['date'] ) ),
				number_format_i18n( $day['views'] ),
				number_format_i18n( $day['visitors'] )
			);
			?>
			<div class="bk-stats-chart__col" title="<?php echo esc_attr( $title ); ?>">
				<div class="bk-stats-chart__bars">
					<div class="bk-stats-chart__bar bk-stats-chart__bar--views" style="height:<?php echo (int) $views_pct; ?>%"></div>
					<div class="bk-stats-chart__bar bk-stats-chart__bar--visitors" style="height:<?php echo (int) $visitors_pct; ?>%"></div>
					<?php if ( $show_value ) : ?>
						<span class="bk-stats-chart__value" style="bottom:<?php echo (int) $views_pct; ?>%"><?php echo esc_html( number_format_i18n( $day['views'] ) ); ?></span>
					<?php endif; ?>
				</div>
				<span class="bk-stats-chart__label"><?php echo esc_html( date_i18n( 'd M', strtotime( $day['date'] ) ) ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Distinct visitors per hour for a single local-time day, always zero-filled
 * across the full 0-23 hour range (unlike the old today-only version, which
 * shortened as the day went on) - a fixed 24-hour axis lets today's (partial)
 * line and yesterday's (complete) line share the same x-scale for a direct
 * overlay. $offset_days = 0 is today, 1 is yesterday.
 *
 * Hours later than "now" on today (0 === $offset_days) haven't happened yet -
 * those get `visitors => null` rather than a fabricated 0, so the renderer
 * can stop the line at the current hour instead of drawing a false drop to
 * zero for the rest of the day.
 */
function turf_get_hourly_visitors_for_offset( $offset_days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	// The UTC->local offset is taken at the TARGET day's noon, not "now":
	// around a DST transition the current offset would bucket yesterday's
	// hours one off. Noon rather than midnight so the reference moment sits
	// safely inside the day regardless of when (2-3am) the clocks shift.
	$tz  = wp_timezone();
	$ref = new DateTimeImmutable( 'now', $tz );

	if ( $offset_days > 0 ) {
		$ref = $ref->modify( sprintf( '-%d days noon', $offset_days ) );
	}

	$offset_seconds = (int) $tz->getOffset( $ref );
	$local_expr     = "DATE_ADD(v.viewed_at, INTERVAL $offset_seconds SECOND)";

	$start = turf_local_midnight_utc( $offset_days );
	$end   = ( 0 === $offset_days ) ? current_time( 'mysql', true ) : turf_local_midnight_utc( $offset_days - 1 );

	$params[] = $start;
	$params[] = $end;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT HOUR($local_expr) AS hour, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where AND v.viewed_at >= %s AND v.viewed_at < %s
		GROUP BY hour",
		$params
	), OBJECT_K );

	$last_hour = ( 0 === $offset_days ) ? (int) current_time( 'H' ) : 23;
	$hourly    = array();

	for ( $h = 0; $h <= 23; $h++ ) {
		$hourly[] = array(
			'hour'     => $h,
			'visitors' => ( $h > $last_hour ) ? null : ( isset( $results[ $h ] ) ? (int) $results[ $h ]->visitors : 0 ),
		);
	}

	return $hourly;
}

/**
 * Inline SVG line chart of distinct-visitors-per-hour: today's (partial)
 * line plus yesterday's (complete) line overlaid for direct comparison, both
 * on the same fixed 0-23 hour axis. Pure markup, no chart library and no JS -
 * the SVG scales to the box width via its viewBox, and non-scaling strokes
 * keep the lines crisp at any width. Bails out only when there's truly
 * nothing to plot (zero visitors across both days so far).
 */
function turf_render_hourly_visitors_chart( $days = TURF_PERIOD_TODAY ) {
	// The primary line is the selected day (today by default, or the anchor
	// day for Yesterday / a fixed date); the comparison line is the day
	// immediately before it. Offsets are expressed relative to the anchor so
	// the overlay stays a same-scale "this day vs. the day before" comparison
	// regardless of which day is selected.
	$anchor_offset = turf_single_day_anchor_offset( $days );
	$today     = turf_get_hourly_visitors_for_offset( $anchor_offset );
	$yesterday = turf_get_hourly_visitors_for_offset( $anchor_offset + 1 );

	$known_visitors = array_filter(
		array_merge( array_column( $today, 'visitors' ), array_column( $yesterday, 'visitors' ) ),
		function ( $v ) { return null !== $v; }
	);

	if ( ! $known_visitors || 0 === array_sum( $known_visitors ) ) {
		return;
	}

	$max = max( 1, max( $known_visitors ) );

	// viewBox coordinate space; CSS scales it to 100% width.
	$w       = 720;
	$h       = 140;
	$pad_top = 10;
	$pad_bot = 18;
	$plot_h  = $h - $pad_top - $pad_bot;

	// Hour 0-23 always maps to the same x position on both lines, regardless
	// of where either line's real data stops - that shared, fixed scale is
	// what makes the overlay comparable at a glance.
	$to_point = function ( $entry ) use ( $w, $pad_top, $plot_h, $max ) {
		return array(
			'x'        => round( ( $entry['hour'] / 23 ) * $w, 1 ),
			'y'        => round( $pad_top + ( 1 - $entry['visitors'] / $max ) * $plot_h, 1 ),
			'hour'     => $entry['hour'],
			'visitors' => $entry['visitors'],
		);
	};

	$build_points = function ( $hourly ) use ( $to_point ) {
		$points = array();

		foreach ( $hourly as $entry ) {
			if ( null === $entry['visitors'] ) {
				break; // Today only - stop at "now", don't draw hours that haven't happened yet.
			}

			$points[] = $to_point( $entry );
		}

		return $points;
	};

	$to_line_path = function ( $points ) {
		$path = '';
		foreach ( $points as $i => $p ) {
			$path .= ( 0 === $i ? 'M' : 'L' ) . $p['x'] . ' ' . $p['y'] . ' ';
		}
		return trim( $path );
	};

	// A yesterday with zero visitors all day (fresh install, first day after
	// an upgrade) draws nothing rather than a flat dashed line of 24 zero
	// dots along the baseline - that line is pure noise, and today alone was
	// this chart's original form anyway.
	$has_yesterday = array_sum( array_column( $yesterday, 'visitors' ) ) > 0;

	$today_points     = $build_points( $today );
	$yesterday_points = $has_yesterday ? $build_points( $yesterday ) : array();
	$today_line       = $to_line_path( $today_points );
	$yesterday_line   = $to_line_path( $yesterday_points );
	$baseline         = $pad_top + $plot_h;

	$area_path = '';
	if ( $today_points ) {
		$last      = $today_points[ count( $today_points ) - 1 ];
		$area_path = $today_line . ' L' . $last['x'] . ' ' . $baseline . ' L' . $today_points[0]['x'] . ' ' . $baseline . ' Z';
	}
	?>
	<div class="bk-stats-hourly">
		<div class="bk-stats-overview__legend">
			<span class="bk-stats-legend bk-stats-legend--visitors"><?php echo esc_html( date_i18n( 'j M', strtotime( turf_single_day_anchor( $days ) ) ) ); ?></span>
			<?php if ( $has_yesterday ) : ?>
				<span class="bk-stats-legend bk-stats-legend--yesterday"><?php echo esc_html( date_i18n( 'j M', strtotime( turf_single_day_anchor( $days ) . ' -1 day' ) ) ); ?></span>
			<?php endif; ?>
		</div>
		<svg class="bk-stats-hourly__svg" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" role="img" aria-label="<?php esc_attr_e( 'Visitors per hour', 'turf-stats' ); ?>">
			<line class="bk-stats-hourly__grid" x1="0" y1="<?php echo esc_attr( $baseline ); ?>" x2="<?php echo (int) $w; ?>" y2="<?php echo esc_attr( $baseline ); ?>" vector-effect="non-scaling-stroke" />

			<?php if ( $yesterday_line ) : ?>
				<path class="bk-stats-hourly__line bk-stats-hourly__line--yesterday" d="<?php echo esc_attr( $yesterday_line ); ?>" vector-effect="non-scaling-stroke" />
				<?php foreach ( $yesterday_points as $p ) : ?>
					<circle class="bk-stats-hourly__dot bk-stats-hourly__dot--yesterday" cx="<?php echo esc_attr( $p['x'] ); ?>" cy="<?php echo esc_attr( $p['y'] ); ?>" r="2" vector-effect="non-scaling-stroke">
						<title>
					<?php
					printf(
						/* translators: 1: hour of day (0-23), 2: date, 3: number of visitors */
						esc_html__( '%1$02d:00 %2$s — %3$s visitors', 'turf-stats' ),
						(int) $p['hour'],
						esc_html( date_i18n( 'j M', strtotime( turf_single_day_anchor( $days ) . ' -1 day' ) ) ),
						esc_html( number_format_i18n( $p['visitors'] ) )
					);
					?>
					</title>
					</circle>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( $area_path ) : ?>
				<path class="bk-stats-hourly__area" d="<?php echo esc_attr( $area_path ); ?>" />
			<?php endif; ?>
			<?php if ( $today_line ) : ?>
				<path class="bk-stats-hourly__line" d="<?php echo esc_attr( $today_line ); ?>" vector-effect="non-scaling-stroke" />
			<?php endif; ?>
			<?php foreach ( $today_points as $p ) : ?>
				<circle class="bk-stats-hourly__dot" cx="<?php echo esc_attr( $p['x'] ); ?>" cy="<?php echo esc_attr( $p['y'] ); ?>" r="2.5" vector-effect="non-scaling-stroke">
					<title>
				<?php
				printf(
					/* translators: 1: hour of day (0-23), 2: number of visitors */
					esc_html__( '%1$02d:00 — %2$s visitors', 'turf-stats' ),
					(int) $p['hour'],
					esc_html( number_format_i18n( $p['visitors'] ) )
				);
				?>
				</title>
				</circle>
			<?php endforeach; ?>
			<?php
			// Sparse hour axis labels (every 3rd hour + hour 23) so they don't
			// collide on a narrow box - fixed to the 0-23 scale regardless of
			// how far either line's real data currently reaches.
			for ( $hour = 0; $hour <= 23; $hour++ ) :
				if ( 0 !== $hour % 3 && 23 !== $hour ) {
					continue;
				}
				$x      = round( ( $hour / 23 ) * $w, 1 );
				$anchor = ( 0 === $hour ) ? 'start' : ( ( 23 === $hour ) ? 'end' : 'middle' );
				?>
				<text class="bk-stats-hourly__axis" x="<?php echo esc_attr( $x ); ?>" y="<?php echo (int) $h; ?>" text-anchor="<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( sprintf( '%02d', $hour ) ); ?></text>
			<?php endfor; ?>
		</svg>
	</div>
	<?php
}

/**
 * Editorial activity (not visitor activity) for the period: how many
 * trackable posts of each type were newly published ("toegevoegd") or
 * edited after their initial publication ("gewijzigd"). One box per post
 * type that actually had activity - skipped entirely on a quiet
 * day/period, so it doesn't clutter the overview otherwise.
 */
/**
 * "Caching" box: how much of the site's traffic is served from cache instead
 * of running PHP. A page served from cache (SiteGround dynamic cache,
 * Cloudflare full-page cache, …) never reaches WordPress, but the cached HTML
 * still carries Turf's tracking JS - so it fires a raw hit without a matching
 * origin render. Offload % = (raw pageviews - origin renders) / raw pageviews.
 *
 * The number is a combined figure across whatever caches sit in front of the
 * site: from the origin's side a cache hit is invisible, so it can't be
 * attributed to one layer. The detected-layers badges give context, not a
 * per-layer split. It's also an estimate: a visitor with JavaScript disabled
 * or an ad-blocker that blocks the tracker is origin-rendered but sends no raw
 * hit, nudging the percentage down.
 */
function turf_render_caching( $days ) {
	$totals = turf_get_range_cache_totals( $days, 0 );

	if ( null === $totals || $totals['raw'] <= 0 ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}

	$raw     = $totals['raw'];
	$origin  = $totals['origin'];
	$offload = max( 0, min( 100, (int) round( ( ( $raw - $origin ) / $raw ) * 100 ) ) );
	$env     = turf_cache_environment();
	?>
	<div class="bk-stats-overview__totals">
		<?php turf_render_stat_box( __( 'Served from cache', 'turf-stats' ), $offload, false, '%', 'cache' ); ?>
		<?php turf_render_stat_box( __( 'Origin renders', 'turf-stats' ), $origin, false ); ?>
		<?php turf_render_stat_box( __( 'Raw pageviews', 'turf-stats' ), $raw, false ); ?>
	</div>

	<p class="description bk-stats-cache-env">
		<?php esc_html_e( 'Detected in front of the site:', 'turf-stats' ); ?>
		<?php if ( $env ) : ?>
			<?php foreach ( $env as $label ) : ?>
				<span class="bk-stats-badge"><?php echo esc_html( $label ); ?></span>
			<?php endforeach; ?>
		<?php else : ?>
			<em><?php esc_html_e( 'no caching layer detected', 'turf-stats' ); ?></em>
		<?php endif; ?>
	</p>

	<p class="description">
		<?php esc_html_e( 'Combined across all caches in front of the site - a cache hit never reaches WordPress, so it can\'t be split per layer. Approximate: visitors who block the tracker are counted as origin renders.', 'turf-stats' ); ?>
	</p>

	<?php // The >= 50 raw-hits floor keeps the hint from firing on a handful
	// of day-one pageviews, where 0% offload is statistical noise rather
	// than evidence of a misconfigured cache layer. ?>
	<?php if ( $offload <= 1 && $env && $raw >= 50 ) : ?>
		<p class="description bk-stats-cache-hint">
			<?php esc_html_e( "A caching layer is detected in front of the site, but almost nothing is actually being served from cache for this period - that combination usually means the layer is caching static files (images/CSS/JS) but not the HTML pages themselves, which is the default for most CDNs/edge caches (e.g. Cloudflare's standard cache level only caches by file extension unless a \"Cache Everything\" rule or something like Automatic Platform Optimization is turned on).", 'turf-stats' ); ?>
			<?php esc_html_e( 'To verify directly: open a page on the site and check the response headers in your browser\'s network tab - Cloudflare adds a "cf-cache-status" header that reads "HIT" once a page is actually served from its edge cache, "DYNAMIC"/"MISS" otherwise.', 'turf-stats' ); ?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Compact table, not stat-box tiles - over a longer period (30/90 days),
 * most trackable post types end up with at least some activity, which
 * made the original stat-box version visually overwhelming. A table row
 * per active type stays readable regardless of how many there are (and
 * collapses behind the usual "Toon meer" past 5, like every other list).
 */
function turf_render_content_activity( $days ) {
	$post_types = turf_trackable_post_types();
	usort( $post_types, function ( $a, $b ) {
		return strnatcasecmp( turf_get_post_type_label( $a ), turf_get_post_type_label( $b ) );
	} );

	$rows = array();

	foreach ( $post_types as $post_type ) {
		$activity = turf_get_content_activity( $post_type, $days );

		if ( $activity['added'] <= 0 && $activity['modified'] <= 0 ) {
			continue;
		}

		$rows[] = array(
			'label'    => turf_get_post_type_label( $post_type ),
			'added'    => $activity['added'],
			'modified' => $activity['modified'],
		);
	}

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Type', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Added', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Edited', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['label'] ); ?></td>
					<td><?php echo (int) $row['added']; ?></td>
					<td><?php echo (int) $row['modified']; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * @return array{added: int, modified: int}
 */
function turf_get_content_activity( $post_type, $days ) {
	global $wpdb;

	$start = turf_period_start_sql_date( $days );

	$added = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_status = 'publish' AND post_date_gmt >= %s",
		$post_type,
		$start
	) );

	$modified = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $wpdb->posts
		WHERE post_type = %s AND post_status = 'publish'
		AND post_modified_gmt >= %s AND post_modified_gmt != post_date_gmt",
		$post_type,
		$start
	) );

	return array( 'added' => $added, 'modified' => $modified );
}

/**
 * Site-wide breakdown by a single simple string column (device_type, browser,
 * os, language, country, utm_source, utm_medium, utm_content) for the selected period.
 * Includes both post views and taxonomy-archive views (a visitor's device/
 * language doesn't depend on what kind of page they're looking at).
 *
 * @param string $column        Whitelisted column name - can't go through $wpdb->prepare().
 * @param bool   $exclude_empty Drop the '' bucket (e.g. for UTM columns, where
 *                               almost every row has no campaign and showing
 *                               that as a giant bucket isn't useful).
 */
function turf_get_breakdown( $column, $days, $exclude_empty = false ) {
	global $wpdb;

	$allowed = array( 'device_type', 'browser', 'os', 'language', 'country', 'utm_source', 'utm_medium', 'utm_content' );

	if ( ! in_array( $column, $allowed, true ) ) {
		return array();
	}

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );
		$params = array_merge( $params, $date_params );
	}

	$where_empty = $exclude_empty ? "AND v.$column != ''" : '';

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.$column AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date $where_empty
		GROUP BY v.$column
		ORDER BY views DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is checked against the fixed $allowed whitelist above; table/join/where are internal literals.
		$params
	) );
}

/**
 * Site-wide breakdown by screen resolution (CSS pixels - see js/views.js),
 * for the selected period. Grouped by the width×height pair rather than a
 * single column, so it needs its own query instead of turf_get_breakdown() -
 * but deliberately NO row limit, matching every other breakdown: the shared
 * renderer already collapses past 5 rows behind "Show more", and a hard cap
 * would let the '' unknown bucket (all pre-feature rows) permanently crowd
 * out real screens. Rows from before this feature (or where the browser
 * didn't report a screen size) have NULL width/height - bucketed into '' so
 * turf_breakdown_label() falls through to the same "Unknown (from before
 * this feature)" text every other breakdown already uses for pre-feature
 * data.
 */
function turf_get_screen_breakdown( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );
		$params = array_merge( $params, $date_params );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT
			CASE
				WHEN v.screen_width IS NULL OR v.screen_height IS NULL
					THEN CASE WHEN v.referrer_host IN ('" . TURF_REST_SOURCE_MARKER . "','" . TURF_CONNECTOR_APP_SOURCE_MARKER . "') THEN 'app' ELSE '' END
				ELSE CONCAT(v.screen_width, '×', v.screen_height)
			END AS label,
			MAX(v.screen_width) AS screen_width,
			COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		GROUP BY label
		ORDER BY views DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- join/where are internal literals from turf_site_join_and_where(); the two referrer markers are internal constants, not user input.
		$params
	) );
}

function turf_render_screen_breakdown( $days ) {
	$rows = turf_get_screen_breakdown( $days );

	turf_render_breakdown_rows( $rows, function ( $raw ) {
		// App/REST views never run the tracking JS, so they legitimately have
		// no screen size - bucket them separately instead of lumping them in
		// with browsers that failed to report one.
		if ( 'app' === $raw ) {
			return __( 'App / REST API (no screen size)', 'turf-stats' );
		}

		// Views with no usable screen size: either a browser that failed to
		// report one (in-app webview or fingerprinting-protected browser) or
		// a row from before this feature existed (always NULL then).
		if ( '' === $raw ) {
			return __( 'Unknown (no screen size recorded)', 'turf-stats' );
		}

		$label = turf_breakdown_label( 'screen', $raw );

		// Derive the device class from the width encoded in the "W×H" label,
		// so each resolution row also shows whether it was phone/tablet/
		// desktop-class - the viewport, not the user-agent string.
		$width = (int) explode( '×', $raw )[0];
		$class = turf_screen_device_class( $width );

		return $class ? $label . ' · ' . turf_device_class_label( $class ) : $label;
	} );
}

/**
 * Display label for a viewport-derived device class.
 *
 * @param string $class 'phone' | 'tablet' | 'desktop'.
 */
function turf_device_class_label( $class ) {
	$labels = array(
		'phone'   => __( 'Phone', 'turf-stats' ),
		'tablet'  => __( 'Tablet', 'turf-stats' ),
		'desktop' => __( 'Desktop', 'turf-stats' ),
	);

	return $labels[ $class ] ?? $class;
}

function turf_breakdown_label( $column, $raw ) {
	if ( 'country' === $column && '' === $raw ) {
		return __( 'Unknown (no Cloudflare country detection or own GeoIP integration)', 'turf-stats' );
	}

	if ( '' === $raw ) {
		return __( 'Unknown (from before this feature)', 'turf-stats' );
	}

	if ( 'device_type' === $column ) {
		$labels = array(
			'desktop' => __( 'Desktop', 'turf-stats' ),
			'mobile'  => __( 'Mobile', 'turf-stats' ),
			'tablet'  => __( 'Tablet', 'turf-stats' ),
		);

		return $labels[ $raw ] ?? $raw;
	}

	if ( 'country' === $column ) {
		return turf_country_label( $raw );
	}

	if ( 'language' === $column ) {
		return turf_language_label( $raw );
	}

	return $raw;
}

/**
 * Small lookup for the country/language codes realistically expected for a
 * Frisian/Dutch local-news audience - falls back to the bare code for
 * anything else rather than maintaining an exhaustive list.
 */
function turf_country_label( $code ) {
	$labels = array(
		'NL' => __( 'Netherlands', 'turf-stats' ),
		'BE' => __( 'Belgium', 'turf-stats' ),
		'DE' => __( 'Germany', 'turf-stats' ),
		'GB' => __( 'United Kingdom', 'turf-stats' ),
		'US' => __( 'United States', 'turf-stats' ),
		'FR' => __( 'France', 'turf-stats' ),
		'ES' => __( 'Spain', 'turf-stats' ),
		'IT' => __( 'Italy', 'turf-stats' ),
		'PL' => __( 'Poland', 'turf-stats' ),
		'CA' => __( 'Canada', 'turf-stats' ),
	);

	return $labels[ $code ] ?? $code;
}

function turf_language_label( $code ) {
	$labels = array(
		'nl' => __( 'Dutch', 'turf-stats' ),
		'fy' => __( 'Frisian', 'turf-stats' ),
		'en' => __( 'English', 'turf-stats' ),
		'de' => __( 'German', 'turf-stats' ),
		'fr' => __( 'French', 'turf-stats' ),
	);

	return $labels[ $code ] ?? $code;
}

/**
 * Android sends `Referer: android-app://<package-name>` for links opened
 * from inside many apps - once parsed as a URL host (which is all Turf
 * stores), that shows up as a raw reverse-DNS string like
 * "com.google.android.googlequicksearchbox" in the referrer-hosts list.
 * This is a static, baked-in lookup of well-known package names (same idea
 * as turf_country_label()/turf_language_label() above) - not a live
 * lookup, so it doesn't conflict with the no-external-calls design.
 * Filterable for package names not in this (necessarily incomplete) list.
 */
function turf_referrer_app_labels() {
	return apply_filters( 'turf_referrer_app_labels', array(
		'com.google.android.googlequicksearchbox' => 'Google-app (Android)',
		'com.google.android.gms'                  => 'Google Play Services (Android)',
		'com.google.android.apps.magazines'       => 'Google Discover/News (Android)',
		'com.google.android.youtube'              => 'YouTube (Android)',
		'com.android.chrome'                      => 'Chrome (Android)',
		'com.android.vending'                     => 'Google Play Store (Android)',
		'com.sec.android.app.sbrowser'            => 'Samsung Internet (Android)',
		'com.microsoft.emmx'                       => 'Edge (Android)',
		'org.mozilla.firefox'                     => 'Firefox (Android)',
		'com.opera.browser'                       => 'Opera (Android)',
		'com.duckduckgo.mobile.android'           => 'DuckDuckGo (Android)',
		'com.facebook.katana'                     => 'Facebook (Android)',
		'com.facebook.lite'                       => 'Facebook Lite (Android)',
		'com.facebook.orca'                       => 'Messenger (Android)',
		'com.instagram.android'                   => 'Instagram (Android)',
		'com.twitter.android'                     => 'X / Twitter (Android)',
		'com.whatsapp'                             => 'WhatsApp (Android)',
		'com.linkedin.android'                    => 'LinkedIn (Android)',
		'com.pinterest'                            => 'Pinterest (Android)',
		'com.snapchat.android'                    => 'Snapchat (Android)',
		'com.apple.mobilesafari'                  => 'Safari (iOS)',
		'com.apple.SafariViewService'             => 'Safari (iOS, in-app)',
	) );
}

/**
 * Returns a friendly label for a known Android/iOS app referrer host, or
 * the raw host unchanged if it isn't recognized.
 */
function turf_referrer_host_label( $host ) {
	$labels = turf_referrer_app_labels();

	return $labels[ $host ] ?? $host;
}

/**
 * SQL CASE expression that buckets a referrer_host column into a traffic-
 * source label. Keep the substring lists in sync with the PHP equivalent,
 * turf_classify_referrer() - this exists separately so the
 * grouping/COUNT(DISTINCT ...) happens in SQL (matching how device_type and
 * browser breakdowns already work), not in PHP after the fact, where
 * per-bucket distinct-visitor counts can't be reconstructed correctly.
 */
function turf_referrer_case_sql( $column = 'v.referrer_host' ) {
	$site_host = esc_sql( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	$build_likes = function ( array $needles ) use ( $column ) {
		$conditions = array();
		foreach ( $needles as $needle ) {
			// %% (not %) - this string still goes through $wpdb->prepare() by
			// the caller for the date placeholder, which treats a lone % as
			// the start of its own placeholder.
			$conditions[] = "$column LIKE '%%" . esc_sql( $needle ) . "%%'";
		}
		return implode( ' OR ', $conditions );
	};

	$search_sql = $build_likes( array( 'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'ecosia.', 'startpage.' ) );
	$social_sql = $build_likes( array( 'facebook.', 'instagram.', 'x.com', 'twitter.', 'linkedin.', 'pinterest.', 't.co', 'whatsapp.' ) );

	$rest_marker      = esc_sql( TURF_REST_SOURCE_MARKER );
	$connector_marker = esc_sql( TURF_CONNECTOR_APP_SOURCE_MARKER );

	return "CASE
		WHEN $column = '' THEN 'direct'
		WHEN $column = '$connector_marker' THEN 'connector'
		WHEN $column = '$rest_marker' THEN 'app'
		WHEN $column = '$site_host' THEN 'intern'
		WHEN $search_sql THEN 'zoekmachine'
		WHEN $social_sql THEN 'social'
		ELSE 'overig'
	END";
}

function turf_get_referrer_breakdown( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();
	$case = turf_referrer_case_sql( 'v.referrer_host' );

	$where_date = '';

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );
		$params = array_merge( $params, $date_params );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT $case AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		GROUP BY label
		ORDER BY views DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $case/$join/$where are built entirely from literals in turf_referrer_case_sql()/turf_site_join_and_where(); no user input.
		$params
	) );
}

function turf_referrer_bucket_label( $bucket ) {
	$labels = array(
		'direct'      => __( 'Direct', 'turf-stats' ),
		// Filterable so a site running a specific connector plugin (see
		// turf_connector_app_route_patterns()) can show that product's own
		// name here instead of the generic default.
		'connector'   => apply_filters( 'turf_connector_app_label', __( 'Connector app', 'turf-stats' ) ),
		'app'         => __( 'App / REST API (other)', 'turf-stats' ),
		'intern'      => __( 'Internal (own site)', 'turf-stats' ),
		'zoekmachine' => __( 'Search engines', 'turf-stats' ),
		'social'      => __( 'Social media', 'turf-stats' ),
		'overig'      => __( 'Other', 'turf-stats' ),
	);

	return $labels[ $bucket ] ?? $bucket;
}

/**
 * Top individual referring hostnames (excluding direct/own-site traffic), for
 * anyone who wants to know *which* search engine or site specifically, beyond
 * the bucketed breakdown above.
 */
function turf_get_top_referrer_hosts( $days, $limit = 10 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$where_date = '';

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );
		$params = array_merge( $params, $date_params );
	}

	$params[] = $site_host;
	$params[] = TURF_REST_SOURCE_MARKER;
	$params[] = TURF_CONNECTOR_APP_SOURCE_MARKER;
	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.referrer_host AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		AND v.referrer_host != '' AND v.referrer_host != %s AND v.referrer_host != %s AND v.referrer_host != %s
		GROUP BY v.referrer_host
		ORDER BY views DESC
		LIMIT %d",
		$params
	) );
}

/**
 * Shared renderer for any "rows with views+visitors, stacked bar per row"
 * breakdown block (device, browser, referrer bucket, top referrer hosts).
 * The block's heading comes from the metabox title it's rendered inside of -
 * this only outputs the bar rows themselves.
 *
 * @param object[] $rows           Each with ->label, ->views, ->visitors.
 * @param callable $label_callback Maps a raw $row->label to a display label.
 * @param int      $visible        How many rows to show before the "Show more"
 *                                  toggle collapses the rest. 0 (default) shows
 *                                  every row; any positive N caps the initial
 *                                  reveal at N (the existing postbox-more JS adds
 *                                  the toggle when there are more rows than N).
 */
function turf_render_breakdown_rows( $rows, $label_callback, $visible = 0 ) {
	// No output at all when empty, so turf_maybe_add_meta_box() can drop the
	// whole box instead of showing an empty "No data yet" placeholder.
	if ( ! $rows ) {
		return;
	}

	$views_list  = array_map( 'intval', wp_list_pluck( $rows, 'views' ) );
	$total_views = array_sum( $views_list );
	$max_views   = $views_list ? max( 1, max( $views_list ) ) : 1;

	// Wrapper so the before-paint collapse CSS and the "Show more" toggle can
	// target exactly these bar-rows (the .inside can also hold a description
	// <p> or note as a sibling, which must not be counted as a row).
	$visible_attr = $visible > 0 ? ' data-turf-visible="' . (int) $visible . '"' : '';
	echo '<div class="bk-stats-bar-list"' . $visible_attr . '>';

	foreach ( $rows as $row ) :
		$views        = (int) $row->views;
		$visitors     = (int) $row->visitors;
		$views_pct    = (int) round( ( $views / $max_views ) * 100 );
		$visitors_pct = (int) round( ( $visitors / $max_views ) * 100 );
		$share        = $total_views ? (int) round( ( $views / $total_views ) * 100 ) : 0;
		$value_text   = sprintf(
			/* translators: 1: number of views, 2: percentage share of total views, 3: number of unique visitors */
			__( '%1$s views (%2$d%%) · %3$s visitors', 'turf-stats' ),
			number_format_i18n( $views ),
			$share,
			number_format_i18n( $visitors )
		);
		?>
		<div class="bk-stats-bar-row" title="<?php echo esc_attr( $value_text ); ?>">
			<span class="bk-stats-bar-row__label"><?php echo esc_html( call_user_func( $label_callback, $row->label ) ); ?></span>
			<span class="bk-stats-bar-row__track">
				<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--views" style="width:<?php echo (int) $views_pct; ?>%"></span>
				<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--visitors" style="width:<?php echo (int) $visitors_pct; ?>%"></span>
			</span>
			<span class="bk-stats-bar-row__value"><?php echo esc_html( $value_text ); ?></span>
		</div>
		<?php
	endforeach;

	echo '</div>';
}

function turf_render_breakdown( $column, $days, $exclude_empty = false ) {
	$rows = turf_get_breakdown( $column, $days, $exclude_empty );

	turf_render_breakdown_rows( $rows, function ( $raw ) use ( $column ) {
		return turf_breakdown_label( $column, $raw );
	} );
}

/**
 * "Visitors" is structurally unreliable for the connector/app buckets: those
 * requests come from a backend server (one fixed IP, one fixed user-agent),
 * not from individual end-user devices - there's nothing in the request
 * that could distinguish one real person from another, so they all hash to
 * the same (or very few) visitor_hash values regardless of how many people
 * are actually using that integration. "Views" still reflects real fetch
 * activity; "Visitors" for these buckets specifically doesn't mean what it
 * means everywhere else on this page. Shown only when relevant, not as a
 * permanent notice.
 */
function turf_render_referrer_breakdown( $days ) {
	$rows = turf_get_referrer_breakdown( $days );

	turf_render_breakdown_rows( $rows, 'turf_referrer_bucket_label' );

	foreach ( $rows as $row ) {
		if ( in_array( $row->label, array( 'connector', 'app' ), true ) ) {
			echo '<p class="description">' . esc_html__( 'These sources run through a backend server on the connector\'s side, not through individual visitors\' devices - so "Visitors" isn\'t reliable for them. "Views" still is.', 'turf-stats' ) . '</p>';
			break;
		}
	}
}

function turf_render_top_referrer_hosts( $days ) {
	// Fetch the full list (capped at turf_list_max()) but reveal only the top
	// 10 by default - the shared postbox-more toggle then exposes "Toon alle
	// verwijzende sites" for the rest, so a busy site isn't flooded by dozens
	// of rows while the headline referrers stay one glance away.
	$rows = turf_get_top_referrer_hosts( $days, turf_list_max() );

	turf_render_breakdown_rows( $rows, 'turf_referrer_host_label', 10 );
}

/**
 * Breakdown of "other" page views (author/date archives, search results,
 * the blog index, anything else turf_track_other_view() recorded) by
 * page_type - the only place this dimension shows up, since these rows
 * have no post_id/term_id to list in a per-post-type/taxonomy table.
 */
function turf_get_other_pages_breakdown( $days ) {
	global $wpdb;
	$table = turf_table();

	if ( 0 === $days ) {
		return $wpdb->get_results(
			"SELECT page_type AS label, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors
			FROM $table
			WHERE page_type IS NOT NULL
			GROUP BY page_type
			ORDER BY views DESC"
		);
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT page_type AS label, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors
		FROM $table
		WHERE page_type IS NOT NULL AND viewed_at >= %s
		GROUP BY page_type
		ORDER BY views DESC",
		turf_period_start_sql_date( $days )
	) );
}

function turf_other_page_type_label( $type ) {
	$labels = array(
		'author' => __( 'Author archive', 'turf-stats' ),
		'date'   => __( 'Date archive', 'turf-stats' ),
		'search' => __( 'Search results', 'turf-stats' ),
		'home'   => __( 'Blog index', 'turf-stats' ),
		'other'  => __( 'Other', 'turf-stats' ),
	);

	return $labels[ $type ] ?? $type;
}

function turf_render_other_pages_breakdown( $days ) {
	$rows = turf_get_other_pages_breakdown( $days );

	turf_render_breakdown_rows( $rows, 'turf_other_page_type_label' );
}

/**
 * New vs. returning visitors for the period: "returning" means that
 * visitor_hash has at least one row from before the period started. Already
 * possible with the existing hash, just not surfaced anywhere until now.
 */
function turf_get_new_vs_returning( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	if ( 0 !== $days ) {
		$start = turf_period_start_sql_date( $days );
		$end   = current_time( 'mysql', true );
	} else {
		$start = '1970-01-01 00:00:00';
		$end   = current_time( 'mysql', true );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT
			COUNT(DISTINCT CASE WHEN earlier.visitor_hash IS NULL THEN v.visitor_hash END) AS new_visitors,
			COUNT(DISTINCT CASE WHEN earlier.visitor_hash IS NOT NULL THEN v.visitor_hash END) AS returning_visitors
		FROM $table v
		$join
		LEFT JOIN $table earlier ON earlier.visitor_hash = v.visitor_hash AND earlier.viewed_at < %s
		WHERE $where AND v.viewed_at >= %s AND v.viewed_at < %s",
		array_merge( array( $start ), $params, array( $start, $end ) )
	) );

	$new       = $row ? (int) $row->new_visitors : 0;
	$returning = $row ? (int) $row->returning_visitors : 0;

	// Nothing at all this period - return empty so the box hides rather than
	// showing two zero-length bars.
	if ( 0 === $new && 0 === $returning ) {
		return array();
	}

	return array(
		(object) array( 'label' => 'nieuw', 'views' => $new, 'visitors' => $new ),
		(object) array( 'label' => 'terugkerend', 'views' => $returning, 'visitors' => $returning ),
	);
}

function turf_render_new_vs_returning( $days ) {
	$rows = turf_get_new_vs_returning( $days );

	turf_render_breakdown_rows( $rows, function ( $raw ) {
		return 'nieuw' === $raw ? __( 'New visitors', 'turf-stats' ) : __( 'Returning visitors', 'turf-stats' );
	} );
}

/**
 * All accent colors below use the WP admin's own --wp-admin-theme-color
 * custom property (set by core per active color scheme - Default/Light/
 * Blue/Coffee/Ectoplasm/Midnight/Ocean/Sunrise) instead of a fixed hex
 * value, so Turf's charts/bars match whatever scheme the user picked
 * rather than always being green. The box chrome itself (border, header,
 * collapse arrow) needs no custom CSS at all - that's core's own .postbox
 * styling, already scheme-aware.
 *
 * Returned as a string (not echoed) so it can be attached via
 * wp_add_inline_style() to the 'turf-postbox-more' handle already
 * registered/enqueued in turf_postboxes_enqueue() (includes/postboxes.php)
 * - same gating, same admin_enqueue_scripts timing, no separate <style> tag.
 */
function turf_admin_inline_css() {
	return <<<'CSS'
		.bk-stats-overview__totals { display: flex; gap: 24px; margin-bottom: 16px; flex-wrap: wrap; }
		.bk-stats-box { min-width: 120px; }
		.bk-stats-box__label { display: block; color: #646970; font-size: 13px; }
		.bk-stats-box__value { display: block; font-size: 24px; font-weight: 600; margin: 4px 0; }
		.bk-stats-box__change { font-size: 12px; font-weight: 600; }
		.bk-stats-box__change--up { color: var(--wp-admin-theme-color, #2271b1); }
		.bk-stats-box__change--down { color: #d63638; }
		.bk-stats-box__change--new { color: #646970; }
		.bk-stats-overview__legend { display: flex; gap: 16px; margin-bottom: 8px; font-size: 12px; color: #646970; }
		.bk-stats-legend::before { content: ""; display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
		.bk-stats-legend--views::before { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-legend--visitors::before { background: var(--wp-admin-theme-color, #2271b1); }
		.turf-date-jump { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 0 16px; vertical-align: middle; font-size: 13px; font-family: inherit; }
		.turf-date-jump label { font-weight: 400; color: #50575e; }
		.turf-date-jump input[type="date"] { font-family: inherit; font-size: 13px; vertical-align: middle; }
		.turf-review-nudge p { max-width: 70ch; }
		.turf-dash .bk-stats-overview__totals { margin-bottom: 12px; }
		.turf-dash__more { margin-top: 10px; }
		.bk-stats-chart { display: flex; align-items: flex-end; gap: 8px; height: 200px; padding: 10px 0; border-bottom: 1px solid #dcdcde; overflow-x: auto; }
		.bk-stats-chart__col { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; height: 100%; }
		.bk-stats-chart__bars { position: relative; width: 100%; max-width: 36px; height: 160px; }
		.bk-stats-chart__bar { position: absolute; bottom: 0; left: 0; width: 100%; border-radius: 2px 2px 0 0; }
		.bk-stats-chart__bar--views { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-chart__bar--visitors { background: var(--wp-admin-theme-color, #2271b1); }
		/* bottom: <bar height>% already places the label's bottom edge exactly
		   at the bar's top (no transform - translateY(-100%) on top of that
		   would double the offset and push the tallest bar's label out of the
		   12% headroom the chart reserves for it). */
		.bk-stats-chart__value {
			position: absolute; left: 0; right: 0; text-align: center;
			padding-bottom: 4px;
			font-size: 11px; font-variant-numeric: tabular-nums; color: #646970; white-space: nowrap;
		}
		/* 30-day chart: same column count roughly halves the width per bar
		   versus the default 7-day view, so the label needs to shrink to
		   still fit without touching its neighbors. */
		.bk-stats-chart--dense .bk-stats-chart__value { font-size: 9px; padding-bottom: 3px; }
		/* 90-day chart: narrower still - drop the thousands separator's
		   width by tightening tracking a touch further. The bigger fix for
		   the old "bars run off the right edge" bug: the 8px column gap
		   alone exceeded the box width at 90 columns, so collapse it here
		   and let the container scroll (overflow-x:auto) as a safety net. */
		.bk-stats-chart--very-dense { gap: 2px; }
		.bk-stats-chart--very-dense .bk-stats-chart__col { min-width: 2px; }
		.bk-stats-chart__label { margin-top: 6px; font-size: 11px; color: #646970; }
		.bk-stats-hourly { margin-bottom: 18px; }
		.bk-stats-hourly__svg { display: block; width: 100%; height: auto; overflow: visible; }
		.bk-stats-hourly__area { fill: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 12%, transparent); }
		.bk-stats-hourly__line { fill: none; stroke: var(--wp-admin-theme-color, #2271b1); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
		.bk-stats-hourly__dot { fill: var(--wp-admin-theme-color, #2271b1); }
		.bk-stats-legend--yesterday::before { background: #646970; }
		.bk-stats-hourly__line--yesterday { fill: none; stroke: #646970; stroke-width: 1.5; stroke-dasharray: 4 3; stroke-linejoin: round; stroke-linecap: round; opacity: 0.45; }
		.bk-stats-hourly__dot--yesterday { fill: #646970; opacity: 0.45; }
		.bk-stats-hourly__axis { fill: #646970; font-size: 10px; }
		.bk-stats-hourly__grid { stroke: #dcdcde; stroke-width: 1; }
		.bk-stats-cache-env { margin-top: 12px; }
		.bk-stats-badge { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 10px; background: var(--wp-admin-theme-color, #2271b1); color: #fff; font-size: 11px; line-height: 1.6; }
		.bk-stats-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; min-width: 0; max-width: 100%; }
		.bk-stats-bar-row__label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.bk-stats-bar-row__track { position: relative; width: 150px; flex-shrink: 0; background: #f0f0f1; border-radius: 3px; height: 10px; overflow: hidden; }
		.bk-stats-bar-row__fill { position: absolute; top: 0; left: 0; height: 100%; border-radius: 3px; }
		.bk-stats-bar-row__fill--views { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-bar-row__fill--visitors { background: var(--wp-admin-theme-color, #2271b1); }
		/* A *fixed* width, not shrink-to-content - rows with shorter text
		   (e.g. "17 weergaven (5%) · 10 bezoekers" vs. "247 weergaven (80%)
		   · 167 bezoekers") would otherwise each end at a different X
		   position, making the bars/labels above them drift row to row
		   instead of lining up. overflow+ellipsis is the backstop for an
		   edge-case number wider than this. */
		.bk-stats-bar-row__value { flex-shrink: 0; width: 220px; overflow: hidden; text-align: right; color: #646970; white-space: nowrap; text-overflow: ellipsis; }
		.turf-postbox-grid .meta-box-sortables {
			display: grid;
			grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
			gap: 20px;
			align-items: start;
			margin-bottom: 20px;
		}
		.turf-postbox-grid .postbox { margin: 0; }

		@media (max-width: 900px) {
			.turf-postbox-grid .meta-box-sortables { grid-template-columns: 1fr; }
		}
		@media (max-width: 600px) {
			.bk-stats-overview__totals { flex-wrap: wrap; }
			.bk-stats-box { flex: 1 1 auto; }
			.bk-stats-bar-row__track { width: 50px; }
			.bk-stats-bar-row__value { width: 110px; font-size: 11px; }
		}
		/* WP core adds .ui-sortable-handle to the draggable box header and
		   sets touch-action:none on it unconditionally (wp-admin/css/
		   common.css) - that disables native touch scrolling the instant a
		   finger lands on a box title, with no actual touch-drag to show
		   for it (jQuery UI Sortable here only handles mouse events). Lets
		   vertical scrolling through the handle again; doesn't affect
		   mouse-based dragging at all. */
		.postbox .postbox-header, .postbox .hndle, .postbox .handle-actions {
			touch-action: pan-y !important;
		}
		.bk-stats-online-now__dot {
			display: inline-block; flex-shrink: 0; width: 7px; height: 7px; border-radius: 50%;
			background: var(--wp-admin-theme-color, #2271b1);
			color: var(--wp-admin-theme-color, #2271b1);
			margin-right: 5px;
			animation: bk-stats-pulse 2s infinite;
		}
		@keyframes bk-stats-pulse {
			0% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 50%, transparent); }
			70% { box-shadow: 0 0 0 6px color-mix(in srgb, currentColor 0%, transparent); }
			100% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 0%, transparent); }
		}
		/* display: flex on the <li> drops display: list-item, which is what
		   renders (and increments) the native <ol> marker - so the ranking
		   numbers come from an explicit counter in ::before instead. */
		.bk-stats-online-pages { margin: 0; padding-left: 0; list-style: none; counter-reset: turf-online-page; }
		.bk-stats-online-pages li { display: flex; gap: 8px; margin-bottom: 6px; font-size: 13px; counter-increment: turf-online-page; }
		.bk-stats-online-pages li::before { content: counter(turf-online-page) "."; flex-shrink: 0; min-width: 16px; color: #646970; }
		.bk-stats-online-pages__label { flex: 1; min-width: 0; }
		.bk-stats-online-pages__count { flex-shrink: 0; color: #646970; }
		.bk-stats-heatmap { border-collapse: collapse; width: 100%; }
		.bk-stats-heatmap th { font-size: 10px; color: #646970; font-weight: 400; text-align: center; padding: 2px; }
		.bk-stats-heatmap td { height: 18px; border: 1px solid #fff; }
		CSS;
}

function turf_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Statistics', 'turf-stats' ); ?></h1>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-stats' ), 'today' ); ?>

		<?php
		$hook = get_current_screen()->id;
		?>
		<div id="poststuff">
			<?php turf_render_postbox_column( $hook, 'turf_overview' ); ?>
			<?php turf_render_postbox_grid_column( $hook, 'turf_compact' ); ?>
			<?php turf_render_postbox_column( $hook, 'turf_wide' ); ?>
		</div>
	</div>
	<?php
}

function turf_get_alltime_visitors( $post_id ) {
	global $wpdb;
	$table = turf_table();

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name.
		$post_id
	) );
}

/**
 * Average engagement (reading time, scroll depth) for a post or term, across
 * all rows that have an engagement signal (most won't, if the visitor left
 * before the "leave page" beacon could fire, or for views from before this
 * feature existed).
 */
function turf_get_alltime_engagement( $object_id, $type = 'post' ) {
	global $wpdb;
	$table  = turf_table();
	$column = 'term' === $type ? 'term_id' : 'post_id'; // Fixed two-value whitelist, never user input.

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT AVG(duration_seconds) AS avg_duration, AVG(scroll_depth) AS avg_scroll
		FROM {$table}
		WHERE {$column} = %d AND duration_seconds IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name and whitelisted column.
		$object_id
	) );

	return array(
		'avg_duration' => ( $row && null !== $row->avg_duration ) ? (int) round( $row->avg_duration ) : null,
		'avg_scroll'   => ( $row && null !== $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null,
	);
}

function turf_format_duration( $seconds ) {
	if ( null === $seconds ) {
		return '—';
	}

	$seconds = max( 0, (int) $seconds );

	if ( $seconds < 60 ) {
		return $seconds . 's';
	}

	$minutes = (int) floor( $seconds / 60 );
	$rest    = $seconds % 60;

	if ( $minutes < 60 ) {
		return $rest ? $minutes . 'm ' . $rest . 's' : $minutes . 'm';
	}

	$hours   = (int) floor( $minutes / 60 );
	$minutes = $minutes % 60;

	return $minutes ? $hours . 'u ' . $minutes . 'm' : $hours . 'u';
}

function turf_format_scroll( $pct ) {
	return null === $pct ? '—' : $pct . '%';
}

/**
 * Site-wide average page load time (ms) for the period - see
 * getLoadTimeMs() in js/views.js for how it's captured (Navigation Timing
 * API, sent on the same beacon as scroll depth/duration). A simple AVG over
 * every row that has one, not session-based like turf_get_avg_session_seconds() -
 * load time is a per-pageview measurement, not a per-visit one. Returns null
 * when there's no data yet for the period; turf_format_load_time() renders
 * that as '—' rather than a misleading 0.
 */
function turf_get_avg_load_time_ms( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );
		$params = array_merge( $params, $date_params );
	}

	$avg = $wpdb->get_var( $wpdb->prepare(
		"SELECT AVG(v.load_time_ms) FROM $table v $join WHERE $where $where_date AND v.load_time_ms IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is own-prefix; join/where are internal literals from turf_site_join_and_where(); no user input.
		$params
	) );

	return ( null === $avg ) ? null : (int) round( $avg );
}

function turf_format_load_time( $ms ) {
	if ( null === $ms ) {
		return '—';
	}

	return ( $ms < 1000 ) ? $ms . 'ms' : number_format_i18n( $ms / 1000, 1 ) . 's';
}

function turf_get_top_posts_for_period( $post_type, $days ) {
	global $wpdb;

	if ( 0 === $days ) {
		$query = new WP_Query( array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => turf_list_max(),
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'meta_key'            => TURF_META_KEY,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );

		$rows = array();
		foreach ( $query->posts as $post ) {
			$engagement = turf_get_alltime_engagement( $post->ID, 'post' );
			$rows[]     = (object) array(
				'post_id'      => $post->ID,
				'views'        => turf_get_views( $post->ID ),
				'visitors'     => turf_get_alltime_visitors( $post->ID ),
				'avg_duration' => $engagement['avg_duration'],
				'avg_scroll'   => $engagement['avg_scroll'],
			);
		}

		return $rows;
	}

	$table = turf_table();

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.post_id AS post_id, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors,
			AVG(v.duration_seconds) AS avg_duration, AVG(v.scroll_depth) AS avg_scroll
		FROM $table v
		INNER JOIN $wpdb->posts p ON p.ID = v.post_id
		WHERE p.post_type = %s AND p.post_status = 'publish'
		AND v.viewed_at >= %s
		GROUP BY v.post_id
		ORDER BY views DESC
		LIMIT %d",
		$post_type,
		turf_period_start_sql_date( $days ),
		turf_list_max()
	) );
}

function turf_render_admin_table( $post_type, $days ) {
	$rows = turf_get_top_posts_for_period( $post_type, $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Views', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Visitors', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. reading time', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. scroll depth', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$title       = get_the_title( $row->post_id );
				$edit        = get_edit_post_link( $row->post_id );
				$avg_duration = isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : null;
				$avg_scroll   = isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null;
				?>
				<tr>
					<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ?: '#' . $row->post_id ); ?></td>
					<td><?php echo (int) $row->views; ?></td>
					<td><?php echo (int) $row->visitors; ?></td>
					<td><?php echo esc_html( turf_format_duration( $avg_duration ) ); ?></td>
					<td><?php echo esc_html( turf_format_scroll( $avg_scroll ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Taxonomy-term equivalents of the post-table functions above.
 */
function turf_get_alltime_term_visitors( $term_id ) {
	global $wpdb;

	$table = turf_table();

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT visitor_hash) FROM {$table} WHERE term_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name.
		$term_id
	) );
}

function turf_get_top_terms_for_period( $taxonomy, $days ) {
	global $wpdb;

	if ( 0 === $days ) {
		$query = new WP_Term_Query( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => turf_list_max(),
			'orderby'    => 'meta_value_num',
			'order'      => 'DESC',
			'meta_key'   => TURF_META_KEY,
		) );

		$rows = array();
		foreach ( $query->get_terms() as $term ) {
			$engagement = turf_get_alltime_engagement( $term->term_id, 'term' );
			$rows[]     = (object) array(
				'term_id'      => $term->term_id,
				'views'        => turf_get_views( $term->term_id, 'term' ),
				'visitors'     => turf_get_alltime_term_visitors( $term->term_id ),
				'avg_duration' => $engagement['avg_duration'],
				'avg_scroll'   => $engagement['avg_scroll'],
			);
		}

		return $rows;
	}

	$table = turf_table();

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.term_id AS term_id, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors,
			AVG(v.duration_seconds) AS avg_duration, AVG(v.scroll_depth) AS avg_scroll
		FROM $table v
		INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = v.term_id
		WHERE tt.taxonomy = %s
		AND v.viewed_at >= %s
		GROUP BY v.term_id
		ORDER BY views DESC
		LIMIT %d",
		$taxonomy,
		turf_period_start_sql_date( $days ),
		turf_list_max()
	) );
}

function turf_render_admin_terms_table( $taxonomy, $days ) {
	$rows = turf_get_top_terms_for_period( $taxonomy, $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Views', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Visitors', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. reading time', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. scroll depth', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$term         = get_term( $row->term_id );
				$name         = $term && ! is_wp_error( $term ) ? $term->name : '#' . $row->term_id;
				$link         = $term && ! is_wp_error( $term ) ? get_term_link( $term ) : false;
				$avg_duration = isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : null;
				$avg_scroll   = isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null;
				?>
				<tr>
					<td><?php echo ( $link && ! is_wp_error( $link ) ) ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ); ?></td>
					<td><?php echo (int) $row->views; ?></td>
					<td><?php echo (int) $row->visitors; ?></td>
					<td><?php echo esc_html( turf_format_duration( $avg_duration ) ); ?></td>
					<td><?php echo esc_html( turf_format_scroll( $avg_scroll ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
