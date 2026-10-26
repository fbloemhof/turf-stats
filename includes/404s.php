<?php
/**
 * Tracks which URLs visitors hit that resolve to a 404, so broken/missing
 * links can be found and fixed. Separate from page views (different
 * question: "what's missing" vs "what's popular") and from clicks (server-
 * side signal, not a UI interaction).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TURF_404S_DB_VERSION', '1.1' );

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

	// Added in 1.1 (dbDelta adds them in place; older rows keep the defaults):
	// - query_keys: parameter *names* only (e.g. "status&transaction_id"),
	//   never values - a payment-return URL can carry anything.
	// - referrer_path: the linking page, only for internal referrers, so a
	//   broken link can be traced to the page that contains it.
	// - visitor_hash: the same hash views use, so a 404 can be matched to a
	//   visitor that also ran the tracking JS (= almost certainly a person).
	// - likely_human: request-level heuristic, see turf_404s_looks_human().
	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		path VARCHAR(255) NOT NULL,
		query_keys VARCHAR(255) NOT NULL DEFAULT '',
		referrer_host VARCHAR(255) NOT NULL DEFAULT '',
		referrer_path VARCHAR(255) NOT NULL DEFAULT '',
		visitor_hash CHAR(32) NOT NULL DEFAULT '',
		likely_human TINYINT UNSIGNED NOT NULL DEFAULT 0,
		hit_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY path_lookup (path, hit_at),
		KEY hit_at_lookup (hit_at)
	) $charset_collate;" );

	update_option( 'turf_404s_db_version', TURF_404S_DB_VERSION );
}
add_action( 'init', 'turf_404s_install' );

/**
 * Sorted, de-duplicated parameter names of a query string, joined with "&"
 * (e.g. "status&transaction_id") - the values are dropped on purpose. Names
 * alone are enough to recognize e.g. a payment provider's return URL, without
 * storing whatever personal or transaction data its values hold.
 */
function turf_404s_query_keys( $query ) {
	$keys = array();

	foreach ( explode( '&', (string) $query ) as $pair ) {
		$name = urldecode( explode( '=', $pair, 2 )[0] );
		$name = (string) preg_replace( '/\[.*$/', '', $name ); // a[]=1&a[]=2 -> "a"
		$name = (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', $name );

		if ( '' !== $name ) {
			$keys[ substr( $name, 0, 50 ) ] = true;
		}
	}

	$keys = array_keys( $keys );
	sort( $keys );

	return substr( implode( '&', $keys ), 0, 255 );
}

/**
 * Request-level guess whether a 404 came from a person rather than a
 * scanner/bot that slipped past turf_is_bot(): real browsers always send
 * Accept-Language, and vulnerability scanners probe a recognizable set of
 * paths (PHP files, dotfiles, wp-login/xmlrpc, admin tools) a visitor never
 * links to. Weak on its own - the admin report also counts a hit as human
 * when the same visitor ran the tracking JS on a normal page (see
 * turf_404s_human_sql()).
 */
function turf_404s_looks_human( $path ) {
	if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
		return false;
	}

	$scanner_pattern = apply_filters(
		'turf_404s_scanner_pattern',
		'#\.(php\d?|aspx?|jsp|cgi|env|git|svn|sql|bak|old|ini|ya?ml|log|zip|tar|gz)(/|$)|/\.|wp-login|xmlrpc|wp-config|wp-admin|wp-includes|phpmyadmin|cgi-bin#i'
	);

	return ! preg_match( $scanner_pattern, $path );
}

function turf_404s_track() {
	if ( ! is_404() || current_user_can( 'edit_posts' ) ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( turf_is_bot( $user_agent ) ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	$path = $request_uri ? wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
	$path = $path ? substr( sanitize_text_field( $path ), 0, 255 ) : '/';

	$query_keys = turf_404s_query_keys( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ) );

	$referrer_host = '';
	$referrer_path = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referrer      = wp_unslash( $_SERVER['HTTP_REFERER'] );
		$referrer_host = turf_sanitize_referrer_host( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );

		// Only the own site's pages: that's where a fixable broken link lives,
		// and an external referrer's full URL can carry anything.
		if ( turf_is_own_host( $referrer_host ) ) {
			$referrer_path = substr( sanitize_text_field( (string) wp_parse_url( $referrer, PHP_URL_PATH ) ), 0, 255 );
		}
	}

	global $wpdb;
	$wpdb->insert(
		turf_404s_table(),
		array(
			'path'          => $path,
			'query_keys'    => $query_keys,
			'referrer_host' => $referrer_host,
			'referrer_path' => $referrer_path,
			'visitor_hash'  => turf_visitor_hash( $user_agent ),
			'likely_human'  => turf_404s_looks_human( $path ) ? 1 : 0,
			'hit_at'        => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
	);
}
add_action( 'template_redirect', 'turf_404s_track' );
