<?php
/**
 * Generic click tracking for specific UI elements (e.g. view-toggle buttons,
 * filter chips, social icons) - a separate concern from page views: not
 * "what was viewed" but "what was interacted with".
 *
 * Convention: add data-turf-click="<key>" to any element worth measuring.
 * js/clicks.js picks up every click on such an element via one delegated
 * listener and beacons it to the AJAX action below - no further markup or
 * JS changes needed beyond the attribute itself.
 *
 * Outbound links are the one exception: js/clicks.js also auto-detects any
 * click on an <a href="..."> pointing at a different hostname - no
 * data-turf-click attribute needed anywhere in theme/content for that to
 * work - and tracks it under the fixed TURF_OUTBOUND_CLICK_KEY key, with the
 * destination hostname in its own target_url column (an explicit
 * data-turf-click on a link still wins over the automatic detection, so a
 * site can deliberately label specific outbound links its own way instead).
 */

define( 'TURF_CLICKS_DB_VERSION', '1.2' );

/**
 * Sentinel click_key for automatically-detected outbound link clicks -
 * sanitize_key()-safe (lowercase + dashes only), so it can't collide with a
 * real data-turf-click key a site happens to choose.
 */
define( 'TURF_OUTBOUND_CLICK_KEY', 'outbound-link' );

function turf_clicks_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_clicks';
}

function turf_clicks_install() {
	if ( get_option( 'turf_clicks_db_version' ) === TURF_CLICKS_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_clicks_table();
	$charset_collate = $wpdb->get_charset_collate();

	// target_url holds a full outbound URL (not just a hostname) since 1.2, so
	// it needs to be long enough for real query-string-laden links - hence 512
	// rather than the original 255. dbDelta widens the existing column in place.
	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		click_key VARCHAR(100) NOT NULL,
		context VARCHAR(191) NOT NULL DEFAULT '',
		target_url VARCHAR(512) NULL DEFAULT NULL,
		clicked_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY key_lookup (click_key, clicked_at)
	) $charset_collate;" );

	update_option( 'turf_clicks_db_version', TURF_CLICKS_DB_VERSION );
}
add_action( 'init', 'turf_clicks_install' );

function turf_clicks_enqueue() {
	wp_enqueue_script(
		'turf-clicks',
		TURF_URL . 'js/clicks.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-clicks', 'turfClicks', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	) );
}
add_action( 'wp_enqueue_scripts', 'turf_clicks_enqueue' );

function turf_clicks_ajax_track() {
	// sanitize_key() already restricts to [a-z0-9_-], which is exactly the
	// charset we want for a click_key - anything else collapses to ''.
	$key = isset( $_POST['key'] ) ? substr( sanitize_key( wp_unslash( $_POST['key'] ) ), 0, 100 ) : '';

	if ( ! $key ) {
		wp_send_json_error( 'invalid key', 400 );
	}

	// Optional stricter allow-list (off by default) for sites/integrations
	// that want to reject any key they didn't explicitly register - doesn't
	// apply to the built-in outbound-link key, since that's not something a
	// site registers itself.
	$allowed = apply_filters( 'turf_clicks_allowed_keys', array() );

	if ( TURF_OUTBOUND_CLICK_KEY !== $key && ! empty( $allowed ) && ! in_array( $key, $allowed, true ) ) {
		wp_send_json_error( 'key not allowed', 400 );
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	// No nonce here on purpose: this is low-stakes interaction analytics, and
	// navigator.sendBeacon - needed for clicks that immediately navigate away
	// - doesn't combine well with nonces.
	if ( turf_is_bot( $user_agent ) ) {
		wp_send_json_success();
	}

	$context = isset( $_POST['context'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['context'] ) ), 0, 191 ) : '';

	$target_url = null;

	if ( TURF_OUTBOUND_CLICK_KEY === $key && isset( $_POST['target'] ) ) {
		// Full external URL, validated with esc_url_raw() (only http/https
		// schemes survive), truncated to the column width.
		$raw        = esc_url_raw( wp_unslash( $_POST['target'] ) );
		$target_url = $raw ? substr( $raw, 0, 512 ) : null;
	}

	if ( TURF_OUTBOUND_CLICK_KEY === $key && ! $target_url ) {
		wp_send_json_success(); // No usable destination URL - nothing worth recording.
	}

	global $wpdb;
	$wpdb->insert(
		turf_clicks_table(),
		array(
			'click_key'   => $key,
			'context'     => $context,
			'target_url'  => $target_url,
			'clicked_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s' )
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_turf_track_click', 'turf_clicks_ajax_track' );
add_action( 'wp_ajax_nopriv_turf_track_click', 'turf_clicks_ajax_track' );
