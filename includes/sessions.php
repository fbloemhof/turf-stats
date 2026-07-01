<?php
/**
 * Reconstructs visitor "sessions" from the existing view log - consecutive
 * pageviews by the same visitor_hash, chained together as long as the gap
 * between them stays under the session window. No new table: this reads
 * straight from wp_turf_views, the same way the rest of the site-wide
 * queries do. Feeds two things: the bounce-rate proxy (a session with only
 * one pageview never clicked onward) and the "Bezoekersroutes" breakdown
 * (which page visitors go to next).
 */

/**
 * How long a gap between two pageviews from the same visitor is still
 * considered the same session.
 */
function turf_session_gap_seconds() {
	return (int) apply_filters( 'turf_session_gap_seconds', 30 * MINUTE_IN_SECONDS );
}

/**
 * Hard cap on rows pulled into PHP for session reconstruction - sessionizing
 * is done here, not in SQL (MySQL has no clean "gaps and islands" primitive
 * without window functions on every supported version), so this bounds
 * memory use on a site with a very large or unfiltered "Alles" view log.
 */
function turf_session_row_limit() {
	return (int) apply_filters( 'turf_session_row_limit', 20000 );
}

function turf_get_session_rows( $days ) {
	global $wpdb;
	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$params[] = turf_session_row_limit();

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.visitor_hash AS visitor_hash, v.post_id AS post_id, v.term_id AS term_id, v.viewed_at AS viewed_at
		FROM $table v
		$join
		WHERE $where $where_date
		ORDER BY v.visitor_hash, v.viewed_at
		LIMIT %d",
		$params
	) );
}

/**
 * Groups the raw rows into sessions. Memoized per request - both the
 * bounce-rate stat and the "Bezoekersroutes" box need the same
 * reconstruction for the same $days and shouldn't run the query twice.
 *
 * @return array[] Each session: array('pages' => array(array('type' => 'post'|'term', 'id' => int), ...))
 */
function turf_compute_sessions( $days ) {
	static $cache = array();

	if ( isset( $cache[ $days ] ) ) {
		return $cache[ $days ];
	}

	$gap  = turf_session_gap_seconds();
	$rows = turf_get_session_rows( $days );

	$sessions         = array();
	$current_visitor  = null;
	$current_session  = null;
	$last_viewed_time = null;

	foreach ( $rows as $row ) {
		$viewed_time = strtotime( $row->viewed_at . ' UTC' );
		$page        = $row->post_id
			? array( 'type' => 'post', 'id' => (int) $row->post_id )
			: array( 'type' => 'term', 'id' => (int) $row->term_id );

		$is_new_session = ( $row->visitor_hash !== $current_visitor )
			|| ( null === $last_viewed_time )
			|| ( $viewed_time - $last_viewed_time > $gap );

		if ( $is_new_session ) {
			if ( null !== $current_session ) {
				$sessions[] = $current_session;
			}

			$current_session = array( 'pages' => array() );
			$current_visitor = $row->visitor_hash;
		}

		$current_session['pages'][] = $page;
		$last_viewed_time            = $viewed_time;
	}

	if ( null !== $current_session ) {
		$sessions[] = $current_session;
	}

	$cache[ $days ] = $sessions;

	return $sessions;
}

/**
 * Percentage of sessions that had exactly one pageview and never clicked
 * onward - the closest proxy to a "bounce rate" Turf can offer without a
 * dedicated exit/engagement event. Skipped for "Alles" (days = 0): with no
 * upper bound on the row count, reconstructing sessions across the entire
 * history is both slow and not a meaningful "rate" (it would just measure
 * however much history happens to be in the table).
 */
function turf_get_bounce_rate( $days ) {
	if ( 0 === $days ) {
		return null;
	}

	$sessions = turf_compute_sessions( $days );

	if ( ! $sessions ) {
		return null;
	}

	$bounced = 0;

	foreach ( $sessions as $session ) {
		if ( 1 === count( $session['pages'] ) ) {
			++$bounced;
		}
	}

	return (int) round( ( $bounced / count( $sessions ) ) * 100 );
}

function turf_resolve_object_label( $type, $id ) {
	if ( 'post' === $type ) {
		$title = get_the_title( $id );
		return '' !== $title ? $title : '#' . $id;
	}

	$term = get_term( $id );

	return ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $id;
}

/**
 * Top "from this page, visitors next went to this page" transitions across
 * all sessions with more than one pageview - the simplest useful slice of
 * "visitor routes" without needing a full path-graph UI.
 */
function turf_get_top_transitions( $days, $limit = 10 ) {
	$sessions    = turf_compute_sessions( $days );
	$transitions = array();

	foreach ( $sessions as $session ) {
		$pages = $session['pages'];

		for ( $i = 0; $i < count( $pages ) - 1; $i++ ) {
			$from = turf_resolve_object_label( $pages[ $i ]['type'], $pages[ $i ]['id'] );
			$to   = turf_resolve_object_label( $pages[ $i + 1 ]['type'], $pages[ $i + 1 ]['id'] );
			$key  = $from . ' → ' . $to;

			if ( ! isset( $transitions[ $key ] ) ) {
				$transitions[ $key ] = array( 'from' => $from, 'to' => $to, 'count' => 0 );
			}

			++$transitions[ $key ]['count'];
		}
	}

	usort( $transitions, function ( $a, $b ) {
		return $b['count'] - $a['count'];
	} );

	return array_slice( $transitions, 0, $limit );
}

/**
 * Average session (visit) duration in seconds. A session's duration is the
 * time from its first to its last pageview, plus the reading time recorded on
 * that last page (the engagement beacon's duration_seconds) - so it captures
 * both the time spent moving between pages and the time spent on the final
 * one. A single-page session is just that one page's recorded reading time
 * (0 if the visitor left before the engagement beacon fired).
 *
 * This is the honest ceiling of what Turf can know: it can only include
 * reading time for pages that actually sent an engagement beacon (posts/terms
 * with the tracker's #post-views hook), so it leans conservative rather than
 * inventing time. Skipped for "Alles" (days = 0), same as the bounce rate -
 * an unbounded history isn't a meaningful single average.
 *
 * Reuses its own query rather than turf_compute_sessions(), since that
 * memoized reconstruction deliberately drops per-row timestamps and reading
 * time (it only needs the page sequence for bounce/routes).
 */
function turf_get_avg_session_seconds( $days ) {
	if ( 0 === $days ) {
		return null;
	}

	global $wpdb;
	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$params[] = turf_session_row_limit();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT v.visitor_hash AS visitor_hash, v.viewed_at AS viewed_at, v.duration_seconds AS duration_seconds
		FROM $table v
		$join
		WHERE $where $where_date
		ORDER BY v.visitor_hash, v.viewed_at
		LIMIT %d",
		$params
	) );

	if ( ! $rows ) {
		return null;
	}

	$gap = turf_session_gap_seconds();

	$total_seconds   = 0;
	$session_count   = 0;
	$current_visitor = null;
	$session_start   = null;
	$last_time       = null;
	$last_duration   = 0;

	$flush = function () use ( &$total_seconds, &$session_count, &$session_start, &$last_time, &$last_duration ) {
		if ( null === $session_start ) {
			return;
		}

		$total_seconds += ( $last_time - $session_start ) + $last_duration;
		++$session_count;
	};

	foreach ( $rows as $row ) {
		$time     = strtotime( $row->viewed_at . ' UTC' );
		$duration = null !== $row->duration_seconds ? (int) $row->duration_seconds : 0;

		$is_new_session = ( $row->visitor_hash !== $current_visitor )
			|| ( null === $last_time )
			|| ( $time - $last_time > $gap );

		if ( $is_new_session ) {
			$flush();

			$current_visitor = $row->visitor_hash;
			$session_start   = $time;
		}

		$last_time     = $time;
		$last_duration = $duration;
	}

	$flush();

	if ( ! $session_count ) {
		return null;
	}

	return (int) round( $total_seconds / $session_count );
}
