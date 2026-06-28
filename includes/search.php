<?php
/**
 * Tracks WP's own front-end search (not a third-party search plugin) - what
 * visitors search for, and how many results each query found, so zero-
 * result searches (a clear "we don't have this" signal) can be told apart
 * from normal ones. Mirrors the 404 tracker's pattern: a server-side hook on
 * template_redirect, no JS involved.
 */

define( 'TURF_SEARCH_DB_VERSION', '1.0' );

function turf_search_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_searches';
}

function turf_search_install() {
	if ( get_option( 'turf_search_db_version' ) === TURF_SEARCH_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_search_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		search_term VARCHAR(255) NOT NULL,
		results_count INT UNSIGNED NOT NULL DEFAULT 0,
		visitor_hash CHAR(32) NOT NULL,
		searched_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY term_lookup (search_term, searched_at),
		KEY zero_results (results_count, searched_at)
	) $charset_collate;" );

	update_option( 'turf_search_db_version', TURF_SEARCH_DB_VERSION );
}
add_action( 'init', 'turf_search_install' );

function turf_search_track() {
	if ( ! is_search() || current_user_can( 'edit_posts' ) ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( turf_is_bot( $user_agent ) ) {
		return;
	}

	$term = sanitize_text_field( get_search_query( false ) );

	if ( '' === $term ) {
		return;
	}

	global $wp_query, $wpdb;

	$wpdb->insert(
		turf_search_table(),
		array(
			'search_term'   => substr( $term, 0, 255 ),
			'results_count' => (int) $wp_query->found_posts,
			'visitor_hash'  => turf_visitor_hash( $user_agent ),
			'searched_at'   => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s' )
	);
}
add_action( 'template_redirect', 'turf_search_track' );
