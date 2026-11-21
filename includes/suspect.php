<?php
/**
 * Behavior-based bot detection for the view log - the counterpart to
 * turf_is_bot(), which only catches user-agents that admit to being a bot.
 * Bots that run JavaScript and send a normal browser UA get past that check
 * and are recorded as visitors; these two signals catch them by what they
 * do instead:
 *
 * 1. At record time (turf_is_rate_limited()): a visitor_hash adding more new
 *    view rows in a short window than a person can read.
 * 2. Once a day, after the fact (turf_flag_suspect_visitors()): a
 *    visitor_hash with many views on one day and not a single engagement
 *    beacon (scroll depth/time on page, see js/views.js). A browser sends
 *    that beacon on practically every page it leaves, so dozens of views
 *    without one is a headless client, not a reader.
 *
 * Suspect rows are flagged, never deleted, so a threshold that turns out too
 * strict can be undone (wp turf-stats unflag-bots). The flag value records
 * how the row was caught:
 *   0 = normal
 *   1 = flagged at record time - never added to the postmeta/termmeta total
 *   2 = flagged after the fact - its view had been counted, and was
 *       subtracted from the postmeta/termmeta total when it was flagged
 * Every report excludes suspect rows (see turf_site_join_and_where() and
 * turf_not_suspect_sql()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TURF_SUSPECT_RATE_LIMITED', 1 );
define( 'TURF_SUSPECT_NO_ENGAGEMENT', 2 );

/**
 * " AND <alias>.suspect = 0" - for the few view-log queries that don't go
 * through turf_site_join_and_where() (which already includes this).
 * $alias is an internal literal, never user input; '' for an unaliased table.
 */
function turf_not_suspect_sql( $alias = '' ) {
	$column = '' === $alias ? 'suspect' : "$alias.suspect";

	return " AND $column = 0";
}

/**
 * More new view rows than this within turf_rate_limit_window() flags the
 * visitor - one new page every 10 seconds, sustained for 10 minutes, with
 * the defaults. Deliberately generous: several people behind one IP with an
 * identical device (a class on school iPads) share a visitor_hash.
 */
function turf_rate_limit_max_views() {
	return (int) apply_filters( 'turf_rate_limit_max_views', 60 );
}

function turf_rate_limit_window() {
	return (int) apply_filters( 'turf_rate_limit_window', 10 * MINUTE_IN_SECONDS );
}

/**
 * Minimum views on one (UTC) day, all without an engagement beacon, before
 * the daily check flags a visitor_hash.
 */
function turf_suspect_min_daily_views() {
	return (int) apply_filters( 'turf_suspect_min_daily_views', 30 );
}

/**
 * Whether this visitor already added turf_rate_limit_max_views() rows within
 * the window. Counts rows of any suspect state, so a bot that keeps going
 * stays flagged instead of being let through again once its earlier rows
 * were flagged.
 */
function turf_is_rate_limited( $visitor_hash ) {
	$max = turf_rate_limit_max_views();

	if ( $max <= 0 ) {
		return false;
	}

	global $wpdb;
	$table = turf_table();

	$recent = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE visitor_hash = %s AND viewed_at >= %s",
		$visitor_hash,
		gmdate( 'Y-m-d H:i:s', time() - turf_rate_limit_window() )
	) );

	return $recent >= $max;
}

/**
 * REST/connector-app views come from one backend server (one IP, one UA) on
 * behalf of many app users, and never run the tracking JS - both signals
 * would misfire on them, so they're exempt.
 */
function turf_is_server_side_source( $referrer_host ) {
	return in_array( $referrer_host, array( TURF_REST_SOURCE_MARKER, TURF_CONNECTOR_APP_SOURCE_MARKER ), true );
}

/**
 * Adds $delta (negative to subtract) to the cached postmeta/termmeta view
 * totals, given rows of (object_id, n). Never drops a total below 0.
 *
 * @param object[] $rows Each with ->object_id and ->n.
 * @param string   $type 'post' or 'term'.
 * @param int      $sign -1 to subtract, 1 to add back.
 */
function turf_adjust_view_totals( $rows, $type, $sign ) {
	global $wpdb;

	$meta_table = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
	$id_column  = 'term' === $type ? 'term_id' : 'post_id';

	foreach ( $rows as $row ) {
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE $meta_table SET meta_value = GREATEST( CAST( meta_value AS SIGNED ) + %d, 0 ) WHERE $id_column = %d AND meta_key = %s",
			$sign * (int) $row->n,
			$row->object_id,
			TURF_META_KEY
		) );

		if ( ! $updated && $sign > 0 ) {
			'term' === $type
				? add_term_meta( $row->object_id, TURF_META_KEY, (int) $row->n, true )
				: add_post_meta( $row->object_id, TURF_META_KEY, (int) $row->n, true );
		}
	}
}

/**
 * The after-the-fact check for one UTC day [$day_start, $day_end). Three
 * passes, all limited to rows of that day:
 *
 * 1. Per visitor: visitor_hashes with at least
 *    turf_suspect_min_daily_views() views and no engagement beacon on any
 *    of them.
 * 2. Per fingerprint (screen size + country + language + browser + OS): a
 *    bot spread over several IPs stays under the per-visitor threshold with
 *    each of them, but shares one device fingerprint. When at least two
 *    visitor_hashes with the same fingerprint together reach the threshold
 *    and not one view with that fingerprint sent a beacon, all of them are
 *    flagged. Rows without a screen size (server-side tracking) are left
 *    out: they have no fingerprint to speak of. A fingerprint that real
 *    readers share (1920x1080, NL, nl, Chrome, Windows) always has beacons,
 *    so it never qualifies.
 * 3. REST views (TURF_REST_SOURCE_MARKER) of visitor_hashes that were
 *    flagged on this day or the 6 days before - the same client fetching
 *    posts through the API, which the first two passes don't count.
 *
 * Unless $dry_run, the still-unflagged rows of every hash found are flagged
 * and subtracted from the postmeta/termmeta totals.
 *
 * A day without a single engagement beacon site-wide is skipped: that means
 * engagement wasn't being recorded yet (or the tracking JS was broken), not
 * that every visitor was a bot.
 *
 * @return array{skipped: bool, visitors: int, views: int, rows: object[]}
 *               rows = per-visitor detail (visitor_hash, country, browser,
 *               os, views) for reporting.
 */
function turf_flag_suspect_visitors( $day_start, $day_end, $dry_run = false ) {
	global $wpdb;
	$table  = turf_table();
	$result = array( 'skipped' => false, 'visitors' => 0, 'views' => 0, 'rows' => array() );

	$min_views = turf_suspect_min_daily_views();

	if ( $min_views <= 0 ) {
		$result['skipped'] = true;
		return $result;
	}

	$day_has_engagement = $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM $table WHERE viewed_at >= %s AND viewed_at < %s AND duration_seconds IS NOT NULL LIMIT 1",
		$day_start,
		$day_end
	) );

	if ( ! $day_has_engagement ) {
		$result['skipped'] = true;
		return $result;
	}

	// Pass 1. Counts every row of the day (flagged ones included) towards the
	// threshold, but only the still-unflagged ones are reported/flagged.
	$candidates = $wpdb->get_results( $wpdb->prepare(
		"SELECT visitor_hash, MAX(country) AS country, MAX(browser) AS browser, MAX(os) AS os,
			SUM(suspect = 0) AS views
		FROM $table
		WHERE viewed_at >= %s AND viewed_at < %s
		AND referrer_host NOT IN (%s, %s)
		GROUP BY visitor_hash
		HAVING COUNT(*) >= %d AND SUM(duration_seconds IS NOT NULL) = 0 AND views > 0",
		$day_start,
		$day_end,
		TURF_REST_SOURCE_MARKER,
		TURF_CONNECTOR_APP_SOURCE_MARKER,
		$min_views
	) );

	// Pass 2: the hashes behind qualifying fingerprints, each of which must
	// itself have no beacon on this day (not even on a row with another
	// fingerprint).
	$fingerprint_candidates = $wpdb->get_results( $wpdb->prepare(
		"SELECT v.visitor_hash, MAX(v.country) AS country, MAX(v.browser) AS browser, MAX(v.os) AS os,
			SUM(v.suspect = 0) AS views
		FROM $table v
		JOIN (
			SELECT screen_width, screen_height, country, language, browser, os
			FROM $table
			WHERE viewed_at >= %s AND viewed_at < %s
			AND referrer_host NOT IN (%s, %s)
			AND screen_width IS NOT NULL AND screen_height IS NOT NULL
			GROUP BY screen_width, screen_height, country, language, browser, os
			HAVING COUNT(*) >= %d AND COUNT(DISTINCT visitor_hash) >= 2 AND SUM(duration_seconds IS NOT NULL) = 0
		) fp ON fp.screen_width = v.screen_width AND fp.screen_height = v.screen_height
			AND fp.country = v.country AND fp.language = v.language
			AND fp.browser = v.browser AND fp.os = v.os
		WHERE v.viewed_at >= %s AND v.viewed_at < %s
		AND v.referrer_host NOT IN (%s, %s)
		AND NOT EXISTS (
			SELECT 1 FROM $table e
			WHERE e.visitor_hash = v.visitor_hash AND e.viewed_at >= %s AND e.viewed_at < %s
			AND e.duration_seconds IS NOT NULL
		)
		GROUP BY v.visitor_hash
		HAVING views > 0",
		$day_start,
		$day_end,
		TURF_REST_SOURCE_MARKER,
		TURF_CONNECTOR_APP_SOURCE_MARKER,
		$min_views,
		$day_start,
		$day_end,
		TURF_REST_SOURCE_MARKER,
		TURF_CONNECTOR_APP_SOURCE_MARKER,
		$day_start,
		$day_end
	) );

	$seen = array_flip( wp_list_pluck( $candidates, 'visitor_hash' ) );

	foreach ( $fingerprint_candidates as $row ) {
		if ( ! isset( $seen[ $row->visitor_hash ] ) ) {
			$candidates[]               = $row;
			$seen[ $row->visitor_hash ] = true;
		}
	}

	// Pass 3: hashes with only REST views left to flag on this day, because
	// they were caught on this day (already flagged, or found above) or in
	// the 6 days before. Their views were never counted in passes 1-2, so
	// only the REST rows are reported here.
	$rest_candidates = $wpdb->get_results( $wpdb->prepare(
		"SELECT r.visitor_hash, MAX(r.country) AS country, MAX(r.browser) AS browser, MAX(r.os) AS os,
			COUNT(*) AS views
		FROM $table r
		WHERE r.viewed_at >= %s AND r.viewed_at < %s
		AND r.referrer_host = %s AND r.suspect = 0
		AND EXISTS (
			SELECT 1 FROM $table s
			WHERE s.visitor_hash = r.visitor_hash AND s.suspect != 0
			AND s.viewed_at >= %s AND s.viewed_at < %s
		)
		GROUP BY r.visitor_hash",
		$day_start,
		$day_end,
		TURF_REST_SOURCE_MARKER,
		gmdate( 'Y-m-d H:i:s', strtotime( $day_start . ' -6 days' ) ),
		$day_end
	) );

	foreach ( $rest_candidates as $row ) {
		if ( ! isset( $seen[ $row->visitor_hash ] ) ) {
			$candidates[]               = $row;
			$seen[ $row->visitor_hash ] = true;
		}
	}

	if ( ! $candidates ) {
		return $result;
	}

	$result['rows']     = $candidates;
	$result['visitors'] = count( $candidates );
	$result['views']    = array_sum( array_map( 'intval', wp_list_pluck( $candidates, 'views' ) ) );

	if ( $dry_run ) {
		return $result;
	}

	turf_flag_visitor_day( wp_list_pluck( $candidates, 'visitor_hash' ), $day_start, $day_end );

	return $result;
}

/**
 * Flags every still-unflagged row (REST views included) of these
 * visitor_hashes in [$day_start, $day_end) as TURF_SUSPECT_NO_ENGAGEMENT,
 * and subtracts them from the postmeta/termmeta totals.
 *
 * Per batch of hashes: subtract from the totals first, then flag - both
 * keyed on the same "unflagged rows of these hashes on this day" set, which
 * can't change underneath (it's a past day).
 *
 * @param string[] $hashes visitor_hash values.
 */
function turf_flag_visitor_day( $hashes, $day_start, $day_end ) {
	global $wpdb;
	$table = turf_table();

	foreach ( array_chunk( array_values( array_unique( $hashes ) ), 200 ) as $batch ) {
		$placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
		$params       = array_merge( array( $day_start, $day_end ), $batch );
		$where        = "viewed_at >= %s AND viewed_at < %s AND suspect = 0 AND visitor_hash IN ($placeholders)";

		foreach ( array( 'post' => 'post_id', 'term' => 'term_id' ) as $type => $column ) {
			turf_adjust_view_totals(
				$wpdb->get_results( $wpdb->prepare(
					"SELECT $column AS object_id, COUNT(*) AS n FROM $table WHERE $where AND $column IS NOT NULL GROUP BY $column",
					$params
				) ),
				$type,
				-1
			);
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE $table SET suspect = %d WHERE $where",
			array_merge( array( TURF_SUSPECT_NO_ENGAGEMENT ), $params )
		) );
	}
}

/**
 * Reverses flagging in [$start, $end): every suspect row becomes a normal
 * view again and is added back to the postmeta/termmeta totals - both kinds,
 * since rate-limited rows were never counted and no-engagement rows had
 * their count subtracted.
 *
 * @return int Number of rows unflagged.
 */
function turf_unflag_suspect_views( $start, $end ) {
	global $wpdb;
	$table = turf_table();
	$where = 'viewed_at >= %s AND viewed_at < %s AND suspect != 0';

	foreach ( array( 'post' => 'post_id', 'term' => 'term_id' ) as $type => $column ) {
		turf_adjust_view_totals(
			$wpdb->get_results( $wpdb->prepare(
				"SELECT $column AS object_id, COUNT(*) AS n FROM $table WHERE $where AND $column IS NOT NULL GROUP BY $column",
				$start,
				$end
			) ),
			$type,
			1
		);
	}

	return (int) $wpdb->query( $wpdb->prepare(
		"UPDATE $table SET suspect = 0 WHERE $where",
		$start,
		$end
	) );
}

/**
 * Daily cron: runs the after-the-fact check for every complete UTC day since
 * the last one processed (so a few missed cron runs catch up on their own),
 * at most 7 days per run. On the very first run only yesterday is checked -
 * older history is a deliberate, reviewed step: wp turf-stats flag-bots.
 */
function turf_run_suspect_check() {
	$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	$last_done = get_option( 'turf_suspect_last_day', '' );
	$day       = $last_done ? gmdate( 'Y-m-d', strtotime( $last_done . ' +1 day' ) ) : $yesterday;

	for ( $i = 0; $i < 7 && $day <= $yesterday; $i++ ) {
		turf_flag_suspect_visitors( $day . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) . ' 00:00:00' );
		update_option( 'turf_suspect_last_day', $day, false );

		$day = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
	}
}
add_action( 'turf_suspect_check', 'turf_run_suspect_check' );

function turf_schedule_suspect_check() {
	if ( ! wp_next_scheduled( 'turf_suspect_check' ) ) {
		// 03:00 UTC: a day's last engagement beacons (sent when a visitor
		// leaves, up to 30 minutes later) are in by then.
		wp_schedule_event( strtotime( 'tomorrow 03:00 UTC' ), 'daily', 'turf_suspect_check' );
	}
}
add_action( 'init', 'turf_schedule_suspect_check' );

/**
 * Flagged views for the Bots page, grouped by country + browser/OS - the
 * shape in which these bots show up ("8 visitors from Japan").
 */
function turf_get_suspect_breakdown( $days ) {
	return turf_stats_cached( array( 'suspect_breakdown', $days ), function () use ( $days ) {
		global $wpdb;
		$table = turf_table();

		list( $where_sql, $params ) = turf_period_where_sql( $days, 'viewed_at' );

		$params[] = turf_list_max();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT CONCAT( IF( country = '', '?', country ), ' · ', browser, ' / ', os ) AS label,
				COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors
			FROM $table
			WHERE suspect != 0 $where_sql
			GROUP BY country, browser, os
			ORDER BY views DESC
			LIMIT %d",
			$params
		) );
	} );
}

/**
 * Flagged views per flag reason for the period.
 *
 * @return array<int,int> views keyed by TURF_SUSPECT_* value.
 */
function turf_get_suspect_totals( $days ) {
	return turf_stats_cached( array( 'suspect_totals', $days ), function () use ( $days ) {
		global $wpdb;
		$table = turf_table();

		list( $where_sql, $params ) = turf_period_where_sql( $days, 'viewed_at' );

		$sql  = "SELECT suspect, COUNT(*) AS views FROM $table WHERE suspect != 0 $where_sql GROUP BY suspect";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$totals = array( TURF_SUSPECT_RATE_LIMITED => 0, TURF_SUSPECT_NO_ENGAGEMENT => 0 );

		foreach ( $rows as $row ) {
			$totals[ (int) $row->suspect ] = (int) $row->views;
		}

		return $totals;
	} );
}
