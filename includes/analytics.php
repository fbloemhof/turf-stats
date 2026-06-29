<?php
/**
 * Read-only analytics derived from the existing view log - no new tables,
 * no new tracking. Peak-hours heatmap and trending content.
 */

/**
 * 7x24 grid of view counts by weekday (0 = Monday) and hour of day, in the
 * site's own local time (not UTC, which is how viewed_at is stored) - a
 * raw-UTC heatmap would be meaningless to whoever reads it. Uses a single
 * fixed UTC offset for the whole query rather than per-row DST-aware
 * conversion, since that needs MySQL's timezone tables to be loaded (not
 * guaranteed) - acceptable to be off by an hour for rows from the "other"
 * side of a DST change.
 */
function turf_get_peak_hours( $days ) {
	global $wpdb;
	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$offset_seconds = (int) round( ( (float) get_option( 'gmt_offset' ) ) * HOUR_IN_SECONDS );
	$local_expr     = "DATE_ADD(v.viewed_at, INTERVAL $offset_seconds SECOND)";

	$where_date = '';

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT WEEKDAY($local_expr) AS weekday, HOUR($local_expr) AS hour, COUNT(*) AS views
		FROM $table v
		$join
		WHERE $where $where_date
		GROUP BY weekday, hour",
		$params
	) );

	$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );

	foreach ( $rows as $row ) {
		$grid[ (int) $row->weekday ][ (int) $row->hour ] = (int) $row->views;
	}

	return $grid;
}

/**
 * Content with the biggest jump in views over the last 24h vs. the 24h
 * before that - "rising now", not just "popular overall". Independent of
 * the page's period selector (always a fixed 24h/48h window, same idea as
 * "Nu online").
 */
function turf_get_trending( $limit = 10 ) {
	global $wpdb;
	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$day_ago      = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
	$two_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT v.post_id AS post_id, v.term_id AS term_id,
			SUM(CASE WHEN v.viewed_at >= %s THEN 1 ELSE 0 END) AS recent,
			SUM(CASE WHEN v.viewed_at < %s THEN 1 ELSE 0 END) AS previous
		FROM $table v
		$join
		WHERE $where AND v.viewed_at >= %s
		GROUP BY v.post_id, v.term_id",
		array_merge( array( $day_ago, $day_ago ), $params, array( $two_days_ago ) )
	) );

	$scored = array();

	foreach ( $rows as $row ) {
		$recent = (int) $row->recent;

		if ( $recent < 1 ) {
			continue;
		}

		$previous = (int) $row->previous;

		$scored[] = array(
			'type'     => $row->post_id ? 'post' : 'term',
			'id'       => (int) ( $row->post_id ? $row->post_id : $row->term_id ),
			'recent'   => $recent,
			'previous' => $previous,
			'score'    => $previous > 0 ? ( $recent - $previous ) : $recent,
		);
	}

	usort( $scored, function ( $a, $b ) {
		return $b['score'] - $a['score'];
	} );

	return array_slice( $scored, 0, $limit );
}
