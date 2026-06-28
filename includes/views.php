<?php
/**
 * Self-hosted page/archive view tracking.
 *
 * Storage: a dedicated event table (wp_turf_views), so future stats (trends,
 * top-posts-per-period) can be built on top without a schema change. Each row
 * tracks either a post (post_id set) or a taxonomy term archive (term_id set)
 * - never both. The running total is cached in postmeta/termmeta
 * (TURF_META_KEY) so "most popular" queries and the admin "Alles" totals can
 * sort/sum cheaply.
 *
 * Dedup is IP+user-agent based (no cookies): a visitor only adds a new view
 * once per rolling window per post/term. If the site runs behind Cloudflare,
 * the real visitor IP comes from the CF-Connecting-IP header instead of
 * REMOTE_ADDR (which would otherwise be Cloudflare's edge IP for every
 * visitor at that PoP) - falls back to REMOTE_ADDR automatically otherwise.
 */

define( 'TURF_META_KEY', '_turf_views' );
define( 'TURF_DB_VERSION', '1.4' );

/**
 * Sentinel referrer_host value for views recorded via the REST API (e.g. a
 * companion app) rather than a normal page load - see includes/rest.php.
 * Not a real hostname, so it can't collide with an actual referrer.
 */
define( 'TURF_REST_SOURCE_MARKER', 'rest-api' );

/**
 * Sentinel referrer_host value specifically for the "Dorpsapp"/"Doarpsapp"
 * village-app integration (a custom doarpsapp/v1 REST namespace registered
 * by a site-specific connector plugin - common on Dutch village/community
 * sites). Distinct from the generic REST marker above, since this one is
 * positively identified rather than "some unknown API consumer". See
 * includes/rest.php.
 */
define( 'TURF_DORPSAPP_SOURCE_MARKER', 'dorpsapp' );

/**
 * Every public post type gets tracked automatically - so a new CPT shows up
 * here (and in the admin report) the moment it's registered, no theme/plugin
 * code change needed. 'attachment' is the one structural exception: media
 * pages aren't "content" in the sense a view count is meant to reflect, and
 * there can be thousands of them.
 */
function turf_trackable_post_types() {
	$excluded   = array( 'attachment' );
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	$post_types = array_values( array_diff( $post_types, $excluded ) );

	return apply_filters( 'turf_trackable_post_types', $post_types );
}

/**
 * Same automatic-detection principle for taxonomy archive pages (category,
 * tag, and any custom taxonomy registered as public). 'post_format' is
 * excluded - it's technically public but has no meaningful archive page on
 * most sites.
 */
function turf_trackable_taxonomies() {
	$excluded   = array( 'post_format' );
	$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
	$taxonomies = array_values( array_diff( $taxonomies, $excluded ) );

	return apply_filters( 'turf_trackable_taxonomies', $taxonomies );
}

function turf_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_views';
}

function turf_install() {
	if ( get_option( 'turf_db_version' ) === TURF_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NULL DEFAULT NULL,
		term_id BIGINT UNSIGNED NULL DEFAULT NULL,
		viewed_at DATETIME NOT NULL,
		visitor_hash CHAR(32) NOT NULL,
		device_type VARCHAR(10) NOT NULL DEFAULT '',
		browser VARCHAR(20) NOT NULL DEFAULT '',
		os VARCHAR(10) NOT NULL DEFAULT '',
		referrer_host VARCHAR(255) NOT NULL DEFAULT '',
		language VARCHAR(5) NOT NULL DEFAULT '',
		country VARCHAR(2) NOT NULL DEFAULT '',
		utm_source VARCHAR(50) NOT NULL DEFAULT '',
		utm_medium VARCHAR(50) NOT NULL DEFAULT '',
		utm_campaign VARCHAR(100) NOT NULL DEFAULT '',
		scroll_depth TINYINT UNSIGNED NULL DEFAULT NULL,
		duration_seconds SMALLINT UNSIGNED NULL DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY post_lookup (post_id, viewed_at),
		KEY term_lookup (term_id, viewed_at),
		KEY dedup_lookup (post_id, visitor_hash, viewed_at)
	) $charset_collate;" );

	// dbDelta doesn't reliably relax an existing NOT NULL column - do that explicitly
	// (harmless no-op on a fresh install, where it's already nullable).
	$wpdb->query( "ALTER TABLE $table MODIFY post_id BIGINT UNSIGNED NULL DEFAULT NULL" );

	update_option( 'turf_db_version', TURF_DB_VERSION );
}
add_action( 'init', 'turf_install' );

/**
 * Current view count, as shown to visitors/admin. $type is 'post' or 'term'.
 */
function turf_get_views( $object_id, $type = 'post' ) {
	if ( 'term' === $type ) {
		return (int) get_term_meta( $object_id, TURF_META_KEY, true );
	}

	return (int) get_post_meta( $object_id, TURF_META_KEY, true );
}

/**
 * The post type's own registered label, so the admin report never needs a
 * hand-maintained translation table to stay in sync with trackable_post_types().
 */
function turf_get_post_type_label( $post_type ) {
	$object = get_post_type_object( $post_type );

	return $object ? $object->labels->name : $post_type;
}

function turf_get_taxonomy_label( $taxonomy ) {
	$object = get_taxonomy( $taxonomy );

	return $object ? $object->labels->name : $taxonomy;
}

/**
 * Real visitor IP behind Cloudflare. Falls back to REMOTE_ADDR for direct/local requests.
 */
function turf_get_visitor_ip() {
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Simple bot/crawler heuristic - good enough to keep the numbers meaningful
 * without pulling in a full UA-parsing library.
 */
function turf_is_bot( $user_agent ) {
	if ( empty( $user_agent ) ) {
		return true;
	}

	return (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview/i', $user_agent );
}

function turf_dedup_window() {
	return (int) apply_filters( 'turf_dedup_window', 30 * MINUTE_IN_SECONDS );
}

/**
 * Coarse device classification from the user-agent. No external lookup/library
 * (avoids another network dependency) - just enough to tell mobile/tablet/desktop
 * apart for the admin breakdown.
 */
function turf_parse_device_type( $user_agent ) {
	if ( preg_match( '/iPad|Tablet|(Android(?!.*Mobile))/i', $user_agent ) ) {
		return 'tablet';
	}

	if ( preg_match( '/Mobile|iPhone|iPod|Android/i', $user_agent ) ) {
		return 'mobile';
	}

	return 'desktop';
}

/**
 * Coarse browser family from the user-agent. Order matters: browsers that
 * embed another engine's name in their UA string (Edge/Opera contain
 * "Chrome", Chrome contains "Safari") must be checked before that engine.
 */
function turf_parse_browser( $user_agent ) {
	$checks = array(
		'Edge'              => '/Edg(e|A|iOS)?\//',
		'Opera'             => '/OPR\/|Opera/',
		'Samsung Internet'  => '/SamsungBrowser/',
		'Firefox'           => '/Firefox\//',
		'Chrome'            => '/Chrome\//',
		'Safari'            => '/Safari\//',
	);

	foreach ( $checks as $label => $pattern ) {
		if ( preg_match( $pattern, $user_agent ) ) {
			return $label;
		}
	}

	return 'Overig';
}

/**
 * Coarse OS family from the user-agent. Order matters: iOS UAs contain
 * "like Mac OS X" (must be checked before macOS), Android UAs contain
 * "Linux" (must be checked before Linux).
 */
function turf_parse_os( $user_agent ) {
	$checks = array(
		'iOS'     => '/iPhone|iPad|iPod/',
		'Android' => '/Android/',
		'Windows' => '/Windows NT/',
		'macOS'   => '/Macintosh|Mac OS X/',
		'Linux'   => '/Linux/',
	);

	foreach ( $checks as $label => $pattern ) {
		if ( preg_match( $pattern, $user_agent ) ) {
			return $label;
		}
	}

	return 'Overig';
}

/**
 * Browser-reported language preference, from the Accept-Language request
 * header (sent automatically on every request, including the AJAX tracking
 * call itself - no extra JS instrumentation needed). Just the primary
 * 2-letter language code, not the full locale/region.
 */
function turf_parse_language() {
	if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
		return '';
	}

	$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
	$first  = trim( explode( ',', $header )[0] );
	$first  = trim( explode( ';', $first )[0] );
	$lang   = strtolower( substr( $first, 0, 2 ) );

	return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : '';
}

/**
 * Visitor's country. Free on sites behind Cloudflare - its CF-IPCountry
 * header is added automatically to every request, so no GeoIP database/
 * lookup/external API of our own is needed there. Empty everywhere else
 * (e.g. local dev, or any site not behind Cloudflare) unless something hooks
 * the turf_visitor_country filter to supply one - deliberately NOT a live
 * geolocation API call here, since that would mean sending visitor IPs to a
 * third party, exactly what this plugin exists to avoid. If you want country
 * data without Cloudflare, hook this filter with your own *local* lookup
 * (e.g. a MaxMind GeoLite2 or DB-IP country-lite database you maintain
 * yourself) - see the README for an example.
 */
function turf_get_country() {
	$country = '';

	if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
		$candidate = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );

		if ( preg_match( '/^[A-Z]{2}$/', $candidate ) && ! in_array( $candidate, array( 'XX', 'T1' ), true ) ) {
			$country = $candidate;
		}
	}

	/**
	 * Filters the visitor's country when there's no (usable) Cloudflare
	 * header. Runs even when $country is already set, so a filter could in
	 * theory override Cloudflare too, but the common case is "fill it in
	 * when it's still empty".
	 *
	 * @param string $country Two-letter country code, or '' if unknown.
	 * @param string $ip      The visitor IP turf_get_visitor_ip() resolved.
	 */
	$country = (string) apply_filters( 'turf_visitor_country', $country, turf_get_visitor_ip() );

	return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
}

/**
 * Defensive re-validation of client-supplied UTM campaign parameters.
 */
function turf_sanitize_utm( $value ) {
	return substr( sanitize_text_field( $value ), 0, 100 );
}

/**
 * Hostname -> traffic-source bucket, for the admin "Herkomst" breakdown.
 */
function turf_classify_referrer( $host ) {
	if ( '' === $host ) {
		return 'direct';
	}

	if ( TURF_DORPSAPP_SOURCE_MARKER === $host ) {
		return 'dorpsapp';
	}

	if ( TURF_REST_SOURCE_MARKER === $host ) {
		return 'app';
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( $site_host && 0 === strcasecmp( $host, $site_host ) ) {
		return 'intern';
	}

	foreach ( array( 'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'ecosia.', 'startpage.' ) as $needle ) {
		if ( false !== stripos( $host, $needle ) ) {
			return 'zoekmachine';
		}
	}

	foreach ( array( 'facebook.', 'instagram.', 'x.com', 'twitter.', 'linkedin.', 'pinterest.', 't.co', 'whatsapp.' ) as $needle ) {
		if ( false !== stripos( $host, $needle ) ) {
			return 'social';
		}
	}

	return 'overig';
}

/**
 * Defensive re-validation of the hostname the client claims as document.referrer -
 * never trust it as-is, it's user-controllable input.
 */
function turf_sanitize_referrer_host( $host ) {
	$host = sanitize_text_field( $host );

	if ( ! preg_match( '/^[a-z0-9.-]{1,255}$/i', $host ) ) {
		return '';
	}

	return strtolower( $host );
}

function turf_enqueue() {
	$object_id   = 0;
	$object_type = 'post';

	if ( is_singular( turf_trackable_post_types() ) ) {
		$object_id   = get_queried_object_id();
		$object_type = 'post';
	} else {
		$queried = get_queried_object();

		if ( $queried instanceof WP_Term && in_array( $queried->taxonomy, turf_trackable_taxonomies(), true ) ) {
			$object_id   = $queried->term_id;
			$object_type = 'term';
		} else {
			return;
		}
	}

	wp_enqueue_script(
		'turf-views',
		TURF_URL . 'js/views.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-views', 'turfViews', array(
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		'postId'     => $object_id,
		'objectType' => $object_type,
		'nonce'      => wp_create_nonce( 'turf_track_view' ),
	) );
}
add_action( 'wp_enqueue_scripts', 'turf_enqueue' );

/**
 * Records one view of a post or taxonomy term (subject to the usual
 * bot/editor/dedup rules) and returns the resulting view count plus the
 * inserted event row's ID (null if deduped/not counted - there's no new row
 * to attach a follow-up engagement signal to). Public on purpose: most views
 * are tracked client-side via the AJAX action below, but some themes redirect
 * a visitor away before the browser ever runs JS (e.g. a "go straight to the
 * external website" profile page, or any post with an external-URL redirect
 * set) - call this directly from your theme/plugin's redirect code instead,
 * guarded by a `function_exists( 'turf_track_view' )` check.
 *
 * @param array $extra Optional: referrer_host, utm_source, utm_medium, utm_campaign.
 */
function turf_track_view( $object_id, $object_type = 'post', $extra = array() ) {
	if ( 'term' === $object_type ) {
		$term = get_term( $object_id );

		if ( ! $term || is_wp_error( $term ) || ! in_array( $term->taxonomy, turf_trackable_taxonomies(), true ) ) {
			return array( 'views' => turf_get_views( $object_id, 'term' ), 'event_id' => null );
		}
	} else {
		$object_type = 'post';
		$post        = get_post( $object_id );

		if ( ! $post || ! in_array( $post->post_type, turf_trackable_post_types(), true ) ) {
			return array( 'views' => turf_get_views( $object_id, 'post' ), 'event_id' => null );
		}
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( turf_is_bot( $user_agent ) || current_user_can( 'edit_posts' ) ) {
		return array( 'views' => turf_get_views( $object_id, $object_type ), 'event_id' => null );
	}

	global $wpdb;
	$table        = turf_table();
	$column       = 'term' === $object_type ? 'term_id' : 'post_id';
	$visitor_hash = md5( turf_get_visitor_ip() . '|' . $user_agent );
	$window_start = gmdate( 'Y-m-d H:i:s', time() - turf_dedup_window() );

	$recent = $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM $table WHERE $column = %d AND visitor_hash = %s AND viewed_at >= %s LIMIT 1",
		$object_id,
		$visitor_hash,
		$window_start
	) );

	$event_id = null;

	if ( ! $recent ) {
		$wpdb->insert(
			$table,
			array(
				$column          => $object_id,
				'viewed_at'      => current_time( 'mysql', true ),
				'visitor_hash'   => $visitor_hash,
				'device_type'    => turf_parse_device_type( $user_agent ),
				'browser'        => turf_parse_browser( $user_agent ),
				'os'             => turf_parse_os( $user_agent ),
				'referrer_host'  => $extra['referrer_host'] ?? '',
				'language'       => turf_parse_language(),
				'country'        => turf_get_country(),
				'utm_source'     => $extra['utm_source'] ?? '',
				'utm_medium'     => $extra['utm_medium'] ?? '',
				'utm_campaign'   => $extra['utm_campaign'] ?? '',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$event_id = $wpdb->insert_id;

		if ( 'term' === $object_type ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE $wpdb->termmeta SET meta_value = meta_value + 1 WHERE term_id = %d AND meta_key = %s",
				$object_id,
				TURF_META_KEY
			) );

			if ( ! $updated ) {
				add_term_meta( $object_id, TURF_META_KEY, 1, true );
			}
		} else {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE $wpdb->postmeta SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
				$object_id,
				TURF_META_KEY
			) );

			if ( ! $updated ) {
				add_post_meta( $object_id, TURF_META_KEY, 1, true );
			}
		}
	}

	return array( 'views' => turf_get_views( $object_id, $object_type ), 'event_id' => $event_id );
}

function turf_ajax_track_view() {
	if ( ! isset( $_POST['post_id'], $_POST['nonce'] ) ) {
		wp_send_json_error( 'invalid request', 400 );
	}

	if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'turf_track_view' ) ) {
		wp_send_json_error( 'bad nonce', 403 );
	}

	$object_id   = absint( $_POST['post_id'] );
	$object_type = ( isset( $_POST['object_type'] ) && 'term' === $_POST['object_type'] ) ? 'term' : 'post';

	$extra = array(
		'referrer_host' => isset( $_POST['referrer'] ) ? turf_sanitize_referrer_host( wp_unslash( $_POST['referrer'] ) ) : '',
		'utm_source'    => isset( $_POST['utm_source'] ) ? turf_sanitize_utm( wp_unslash( $_POST['utm_source'] ) ) : '',
		'utm_medium'    => isset( $_POST['utm_medium'] ) ? turf_sanitize_utm( wp_unslash( $_POST['utm_medium'] ) ) : '',
		'utm_campaign'  => isset( $_POST['utm_campaign'] ) ? turf_sanitize_utm( wp_unslash( $_POST['utm_campaign'] ) ) : '',
	);

	$result = turf_track_view( $object_id, $object_type, $extra );

	wp_send_json_success( array( 'views' => $result['views'], 'event_id' => $result['event_id'] ) );
}
add_action( 'wp_ajax_turf_track_view', 'turf_ajax_track_view' );
add_action( 'wp_ajax_nopriv_turf_track_view', 'turf_ajax_track_view' );

/**
 * Follow-up "engagement" signal for a specific event row, sent once a
 * visitor leaves the page (scroll depth reached + time spent). Requires the
 * same visitor_hash to match the row being updated, so this can't be used to
 * overwrite arbitrary rows by guessing event IDs. Capped to sane ranges:
 * scroll 0-100%, duration up to 30 minutes (beyond that it's an idle tab, not
 * reading time).
 */
function turf_track_engagement( $event_id, $scroll_depth, $duration_seconds ) {
	global $wpdb;

	$user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$visitor_hash = md5( turf_get_visitor_ip() . '|' . $user_agent );

	$wpdb->update(
		turf_table(),
		array(
			'scroll_depth'     => max( 0, min( 100, (int) $scroll_depth ) ),
			'duration_seconds' => max( 0, min( 1800, (int) $duration_seconds ) ),
		),
		array( 'id' => $event_id, 'visitor_hash' => $visitor_hash ),
		array( '%d', '%d' ),
		array( '%d', '%s' )
	);
}

function turf_ajax_track_engagement() {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

	if ( ! $event_id ) {
		wp_send_json_error( 'invalid event', 400 );
	}

	turf_track_engagement(
		$event_id,
		isset( $_POST['scroll_depth'] ) ? absint( $_POST['scroll_depth'] ) : 0,
		isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : 0
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_turf_track_engagement', 'turf_ajax_track_engagement' );
add_action( 'wp_ajax_nopriv_turf_track_engagement', 'turf_ajax_track_engagement' );
