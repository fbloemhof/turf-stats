<?php
/**
 * CSV export for a fixed set of core reports (overview totals, top pages,
 * top referrers, device/browser/OS breakdown, search terms, 404s) - not
 * every postbox, by design (a deliberately smaller v1 scope). Each export is a
 * plain admin-ajax GET link, gated the same way every other admin-ajax
 * handler in this plugin is (capability + nonce), reusing the existing
 * getters directly rather than scraping the rendered HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV-injection guard: a string value starting with `= + - @` (or a tab/CR,
 * which some parsers also treat as a formula lead-in) is prefixed with a
 * leading apostrophe so spreadsheet software opens it as literal text
 * instead of evaluating it as a formula. Only string values need this -
 * every numeric column in these exports is already cast (int)/(float) by
 * the getters, so a legitimately negative number is never touched.
 *
 * The strings this actually matters for: referrer host (from an anonymous
 * visitor's Referer header), search term, and post/page title - all
 * attacker-influenced input that ends up in a file Excel will open.
 */
function turf_csv_safe_value( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Streams $rows as a downloadable CSV and ends the request. $columns_map is
 * `[ csv_header_label => callable( $row ) ]`, evaluated in order - lets each
 * export handler shape its own columns without a bespoke fputcsv() loop.
 *
 * @param string   $filename     Suggested download filename (sanitized).
 * @param array    $rows         Rows to export (any shape $columns_map's
 *                                 callables understand - stdClass or array).
 * @param callable[] $columns_map Ordered map of header label => value getter.
 */
function turf_stream_csv( $filename, array $rows, array $columns_map ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );

	$out = fopen( 'php://output', 'w' );

	// UTF-8 BOM so Excel auto-detects the encoding instead of mangling
	// non-ASCII referrer hosts/search terms/titles.
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, array_keys( $columns_map ) );

	foreach ( $rows as $row ) {
		$line = array();
		foreach ( $columns_map as $get_value ) {
			$line[] = turf_csv_safe_value( call_user_func( $get_value, $row ) );
		}
		fputcsv( $out, $line );
	}

	fclose( $out );
	exit;
}

/**
 * Renders a small "Export CSV" link for one of the 5 registered export
 * actions, carrying whatever period is currently on screen (period/date/
 * range_start/range_end - including a custom range) plus a shared nonce, so
 * the download always matches what's on screen.
 *
 * @param string $action     wp_ajax_ action name, e.g. 'turf_export_top_pages'.
 * @param array  $extra_args Extra query args the handler needs (e.g. 'post_type', 'column').
 */
function turf_render_export_link( $action, array $extra_args = array() ) {
	$args = array_merge( $extra_args, array(
		'action'  => $action,
		'_wpnonce' => wp_create_nonce( 'turf_export' ),
	) );

	foreach ( array( 'period', 'date', 'range_start', 'range_end' ) as $key ) {
		if ( isset( $_GET[ $key ] ) ) {
			$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		}
	}

	$url = add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	?>
	<div class="turf-export-link-wrap">
		<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Export CSV', 'turf-stats' ); ?></a>
	</div>
	<?php
}

/**
 * Shared capability + nonce check for all 5 export handlers below - a plain
 * GET link (not a POST/AJAX fetch), so the nonce arrives as `_wpnonce`.
 */
function turf_export_check_access() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'turf_export' ) ) {
		wp_die( esc_html__( 'Forbidden', 'turf-stats' ), 403 );
	}
}

function turf_ajax_export_overview() {
	turf_export_check_access();

	$days = turf_get_requested_days( 'today' );

	$totals = ( 0 === $days ) ? turf_get_alltime_site_totals() : turf_get_range_site_totals( $days );
	$rows   = array(
		array( __( 'Views', 'turf-stats' ), (int) $totals['views'] ),
		array( __( 'Visitors', 'turf-stats' ), (int) $totals['visitors'] ),
	);

	if ( 0 !== $days ) {
		$raw = turf_get_range_raw_views( $days );
		if ( null !== $raw ) {
			$rows[] = array( __( 'Raw views', 'turf-stats' ), (int) $raw );
		}

		$bounce_rate = turf_get_bounce_rate( $days );
		if ( null !== $bounce_rate ) {
			$rows[] = array( __( 'Bounce rate (%)', 'turf-stats' ), $bounce_rate );
		}

		$avg_seconds = turf_get_avg_session_seconds( $days );
		if ( null !== $avg_seconds ) {
			$rows[] = array( __( 'Avg. time/visit (s)', 'turf-stats' ), $avg_seconds );
		}

		$avg_load_ms = turf_get_avg_load_time_ms( $days );
		if ( null !== $avg_load_ms ) {
			$rows[] = array( __( 'Avg. load time (ms)', 'turf-stats' ), $avg_load_ms );
		}
	}

	$comments = turf_get_comment_totals( $days );
	$rows[]   = array( __( 'Comments', 'turf-stats' ), (int) $comments );

	turf_stream_csv( 'turf-overview.csv', $rows, array(
		__( 'Metric', 'turf-stats' ) => function ( $row ) {
			return $row[0];
		},
		__( 'Value', 'turf-stats' ) => function ( $row ) {
			return $row[1];
		},
	) );
}
add_action( 'wp_ajax_turf_export_overview', 'turf_ajax_export_overview' );

function turf_ajax_export_top_pages() {
	turf_export_check_access();

	$days       = turf_get_requested_days( 'today' );
	$post_type  = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
	$post_types = turf_trackable_post_types();

	if ( ! in_array( $post_type, $post_types, true ) ) {
		wp_die( esc_html__( 'Invalid post type', 'turf-stats' ), 400 );
	}

	$rows = turf_get_top_posts_for_period( $post_type, $days );

	turf_stream_csv( 'turf-top-' . $post_type . '.csv', $rows, array(
		__( 'Title', 'turf-stats' ) => function ( $row ) {
			return get_the_title( $row->post_id ) ?: ( '#' . $row->post_id );
		},
		__( 'Views', 'turf-stats' ) => function ( $row ) {
			return (int) $row->views;
		},
		__( 'Visitors', 'turf-stats' ) => function ( $row ) {
			return (int) $row->visitors;
		},
		__( 'Avg. reading time (s)', 'turf-stats' ) => function ( $row ) {
			return isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : '';
		},
		__( 'Avg. scroll depth (%)', 'turf-stats' ) => function ( $row ) {
			return isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : '';
		},
	) );
}
add_action( 'wp_ajax_turf_export_top_pages', 'turf_ajax_export_top_pages' );

function turf_ajax_export_referrers() {
	turf_export_check_access();

	$days = turf_get_requested_days( 'today' );
	$rows = turf_get_top_referrer_hosts( $days, 500 );

	turf_stream_csv( 'turf-referrers.csv', $rows, array(
		__( 'Referrer host', 'turf-stats' ) => function ( $row ) {
			return $row->label;
		},
		__( 'Views', 'turf-stats' ) => function ( $row ) {
			return (int) $row->views;
		},
		__( 'Visitors', 'turf-stats' ) => function ( $row ) {
			return (int) $row->visitors;
		},
	) );
}
add_action( 'wp_ajax_turf_export_referrers', 'turf_ajax_export_referrers' );

/**
 * Shared handler for Device/Browser/OS - one action, `column` picks which.
 * Narrower whitelist than turf_get_breakdown()'s own (which also allows
 * language/country/utm_*) - deliberately, since those are out of this
 * feature's confirmed scope.
 */
function turf_ajax_export_breakdown() {
	turf_export_check_access();

	$column = isset( $_GET['column'] ) ? sanitize_key( $_GET['column'] ) : '';
	if ( ! in_array( $column, array( 'device_type', 'browser', 'os' ), true ) ) {
		wp_die( esc_html__( 'Invalid column', 'turf-stats' ), 400 );
	}

	$days = turf_get_requested_days( 'today' );
	$rows = turf_get_breakdown( $column, $days );

	turf_stream_csv( 'turf-' . $column . '.csv', $rows, array(
		__( 'Label', 'turf-stats' ) => function ( $row ) use ( $column ) {
			return turf_breakdown_label( $column, $row->label );
		},
		__( 'Views', 'turf-stats' ) => function ( $row ) {
			return (int) $row->views;
		},
		__( 'Visitors', 'turf-stats' ) => function ( $row ) {
			return (int) $row->visitors;
		},
	) );
}
add_action( 'wp_ajax_turf_export_breakdown', 'turf_ajax_export_breakdown' );

function turf_ajax_export_search_terms() {
	turf_export_check_access();

	$days = turf_get_requested_days( 'today' );
	$rows = turf_search_get_top_terms( $days, 500 );

	turf_stream_csv( 'turf-search-terms.csv', $rows, array(
		__( 'Search term', 'turf-stats' ) => function ( $row ) {
			return $row->search_term;
		},
		__( 'Searches', 'turf-stats' ) => function ( $row ) {
			return (int) $row->searches;
		},
		__( 'Avg. results', 'turf-stats' ) => function ( $row ) {
			return is_numeric( $row->avg_results ) ? round( (float) $row->avg_results, 1 ) : '';
		},
	) );
}
add_action( 'wp_ajax_turf_export_search_terms', 'turf_ajax_export_search_terms' );

function turf_ajax_export_404s() {
	turf_export_check_access();

	$days = turf_get_requested_days( 'today' );
	$rows = turf_404s_get_top_paths( $days );

	turf_stream_csv( 'turf-404s.csv', $rows, array(
		__( 'Path', 'turf-stats' ) => function ( $row ) {
			return $row->path;
		},
		__( 'Times hit', 'turf-stats' ) => function ( $row ) {
			return (int) $row->hits;
		},
		__( 'Last hit', 'turf-stats' ) => function ( $row ) {
			return get_date_from_gmt( $row->last_hit, 'Y-m-d H:i:s' );
		},
	) );
}
add_action( 'wp_ajax_turf_export_404s', 'turf_ajax_export_404s' );
