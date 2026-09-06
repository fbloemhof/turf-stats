<?php
/**
 * Transient cache for the Statistieken page's expensive aggregate queries -
 * several of the breakdown/table queries become full-table scans once a
 * period drops its date bound (most notably "Alles"), and there is no other
 * caching layer anywhere in the plugin, so the same full scan was being
 * repeated on every single page load. A flat TTL is enough here: this is a
 * stats dashboard, not something that needs second-fresh numbers, and
 * invalidating on every pageview write would tax the much hotter write path
 * for no real benefit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable so a site owner can trade freshness for speed (or vice versa)
 * without a code change.
 */
function turf_stats_cache_ttl() {
	return (int) apply_filters( 'turf_stats_cache_ttl', 5 * MINUTE_IN_SECONDS );
}

/**
 * Memoizes $callback's return value in a transient keyed by $key_parts (every
 * argument that affects the result - column, days, post type, etc.). Callers
 * wrap the data-fetching function itself (the one returning raw rows/scalars),
 * not the turf_render_* echo functions, so rendering still runs fresh each
 * time against the cached data.
 */
function turf_stats_cached( $key_parts, $callback ) {
	$key = 'turf_c_' . md5( wp_json_encode( $key_parts ) );

	$value = get_transient( $key );
	if ( false !== $value ) {
		return $value;
	}

	$value = call_user_func( $callback );
	set_transient( $key, $value, turf_stats_cache_ttl() );

	return $value;
}
