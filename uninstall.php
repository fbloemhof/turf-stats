<?php
/**
 * Runs when the plugin is deleted through the WordPress admin.
 *
 * By default this removes only the plugin's own bookkeeping (the db-version
 * options and the scheduled pruning event) and leaves all collected
 * statistics in place - analytics history is precious, and an accidental
 * delete/reinstall shouldn't wipe years of data.
 *
 * To remove everything (event tables, view-count meta, options), opt in
 * BEFORE deleting the plugin, either with a constant in wp-config.php:
 *
 *     define( 'TURF_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * or by setting the option (e.g. via WP-CLI):
 *
 *     wp option update turf_remove_data_on_uninstall 1
 *
 * A constant/option rather than a filter, because at uninstall time the
 * plugin's own code isn't loaded, so its filters don't exist.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Always: the scheduled retention-pruning event (deactivation clears it too,
// but uninstall can run without a prior deactivation of this code version).
wp_clear_scheduled_hook( 'turf_prune_old_events' );

$turf_remove_data = ( defined( 'TURF_REMOVE_DATA_ON_UNINSTALL' ) && TURF_REMOVE_DATA_ON_UNINSTALL )
	|| get_option( 'turf_remove_data_on_uninstall' );

if ( ! $turf_remove_data ) {
	return;
}

global $wpdb;

// Event/aggregate tables.
foreach ( array(
	'turf_views',
	'turf_raw_hits',
	'turf_clicks',
	'turf_404s',
	'turf_bots',
	'turf_searches',
	'turf_form_submissions',
	'turf_woo_events',
) as $turf_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$turf_table}" ); // phpcs:ignore WordPress.DB -- table names are a fixed internal list.
}

// Cached per-post/per-term view totals.
$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key = %s", '_turf_views' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->termmeta WHERE meta_key = %s", '_turf_views' ) );

// Options.
foreach ( array(
	'turf_db_version',
	'turf_raw_hits_db_version',
	'turf_clicks_db_version',
	'turf_404s_db_version',
	'turf_bots_db_version',
	'turf_search_db_version',
	'turf_forms_db_version',
	'turf_woo_db_version',
	'turf_remove_data_on_uninstall',
) as $turf_option ) {
	delete_option( $turf_option );
}

// Per-user postbox state (order/closed/hidden) for Turf's admin screens -
// core stores these in usermeta keyed by screen id. Submenu screen ids embed
// the *translated* parent menu title ("statistics_page_turf-clicks" on an
// English site, "statistieken_page_turf-clicks" on a Dutch one), so match by
// pattern rather than a fixed list. The page slugs (turf-stats, turf-analyse,
// turf-clicks, turf-404s, turf-bots) all start with "turf-".
foreach ( array( 'meta-box-order_', 'closedpostboxes_', 'metaboxhidden_' ) as $turf_meta_prefix ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM $wpdb->usermeta WHERE meta_key LIKE %s",
		$wpdb->esc_like( $turf_meta_prefix ) . '%' . $wpdb->esc_like( '_page_turf-' ) . '%'
	) );
}
