<?php
/**
 * Tracks which URLs visitors hit that resolve to a 404, so broken/missing
 * links can be found and fixed. Separate from page views (different
 * question: "what's missing" vs "what's popular") and from clicks (server-
 * side signal, not a UI interaction).
 */

define( 'TURF_404S_DB_VERSION', '1.0' );

function turf_404s_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_404s';
}

function turf_404s_install() {
	if ( get_option( 'turf_404s_db_version' ) === TURF_404S_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_404s_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		path VARCHAR(255) NOT NULL,
		referrer_host VARCHAR(255) NOT NULL DEFAULT '',
		hit_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY path_lookup (path, hit_at)
	) $charset_collate;" );

	update_option( 'turf_404s_db_version', TURF_404S_DB_VERSION );
}
add_action( 'init', 'turf_404s_install' );

function turf_404s_track() {
	if ( ! is_404() || current_user_can( 'edit_posts' ) ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( turf_is_bot( $user_agent ) ) {
		return;
	}

	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	$path = $path ? substr( sanitize_text_field( $path ), 0, 255 ) : '/';

	$referrer_host = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referrer_host = turf_sanitize_referrer_host( (string) wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST ) );
	}

	global $wpdb;
	$wpdb->insert(
		turf_404s_table(),
		array(
			'path'          => $path,
			'referrer_host' => $referrer_host,
			'hit_at'        => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s' )
	);
}
add_action( 'template_redirect', 'turf_404s_track' );
