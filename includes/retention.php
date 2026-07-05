<?php
/**
 * Data retention for the raw event logs (wp_turf_views, wp_turf_clicks).
 *
 * GDPR's storage-limitation principle (art. 5(1)(e)) means raw,
 * visitor-linkable rows shouldn't be kept indefinitely. The running totals
 * in postmeta/termmeta (TURF_META_KEY) are pure aggregates and are NOT
 * affected by this - pruning old detail rows only means device/browser/
 * referrer breakdowns can no longer be queried for periods older than the
 * retention window. "Weergaven" totals (which read from postmeta/termmeta)
 * stay intact forever.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many months of raw rows to keep. 0 or less disables pruning entirely.
 * Filterable for sites that want a different policy.
 */
function turf_retention_months() {
	return (int) apply_filters( 'turf_retention_months', 18 );
}

function turf_prune_old_events() {
	$months = turf_retention_months();

	if ( $months <= 0 ) {
		return;
	}

	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );

	// Table name -> its timestamp column. All table names come from the
	// plugin's own fixed turf_*_table() helpers ($wpdb->prefix plus a literal),
	// never from user input; only $cutoff is variable, and it goes through
	// $wpdb->prepare().
	$tables = array(
		turf_table()        => 'viewed_at',
		turf_clicks_table() => 'clicked_at',
		turf_404s_table()   => 'hit_at',
		turf_bots_table()   => 'visited_at',
		turf_search_table() => 'searched_at',
		turf_forms_table()  => 'submitted_at',
	);

	if ( turf_woo_active() ) {
		$tables[ turf_woo_table() ] = 'occurred_at';
	}

	foreach ( $tables as $table => $time_column ) {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE {$time_column} < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name and literal column from the fixed list above.
			$cutoff
		) );
	}
}
add_action( 'turf_prune_old_events', 'turf_prune_old_events' );

function turf_schedule_pruning() {
	if ( ! wp_next_scheduled( 'turf_prune_old_events' ) ) {
		wp_schedule_event( time(), 'daily', 'turf_prune_old_events' );
	}
}
add_action( 'init', 'turf_schedule_pruning' );
