<?php
/**
 * Tracks views that come through the WordPress REST API instead of a normal
 * page load - e.g. a companion app that fetches content via
 * /wp-json/wp/v2/posts/123 instead of rendering the site's own HTML pages.
 * The browser-based AJAX tracking in views.php can't see these at all (no
 * page is ever rendered, so no JS ever runs) - this hooks the REST response
 * pipeline directly instead, and reuses turf_track_view() for the actual
 * counting, so dedup, bot filtering, and device/browser/OS parsing (applied
 * to whatever user-agent the API client sends) all work the same way.
 *
 * Tagged with referrer_host = TURF_REST_SOURCE_MARKER, which shows up as its
 * own "App / REST API" bucket in the "Herkomst" breakdown. This can't
 * identify any specific app by name - any REST API consumer looks the same
 * from the server's side - it answers "is this content being read through
 * something other than the website", not "is it specifically app X".
 *
 * To find out what a specific app/integration's requests actually look like
 * (so you can recognize it specifically later), define TURF_DEBUG_REST as
 * true in wp-config.php for a while and watch your PHP/debug log - every
 * rest_prepare_* hit gets logged with its route, method, and user-agent.
 * Remove the define again once you've got what you need; this is meant to
 * be temporary, not left running.
 *
 * A site-specific companion-app connector plugin (e.g. a native app backend
 * that fetches content on visitors' behalf) can be recognized specifically
 * if it doesn't use the standard /wp/v2/... controllers at all, but its own
 * custom REST namespace (e.g. /wp-json/my-connector/v1/posts/123) - such a
 * request never fires rest_prepare_{type}. Hooked separately below via
 * rest_post_dispatch (fires for every REST response, any namespace),
 * reading the post ID straight out of the connector's own response payload
 * - this only works if the connector's single-item endpoints include an
 * "id" field. Tagged with its own TURF_CONNECTOR_APP_SOURCE_MARKER instead
 * of the generic one, so it shows up as its own bucket rather than lumped
 * into "App / REST API (other)". Off by default - see
 * turf_connector_app_route_patterns() below to opt a specific connector in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_register_rest_tracking() {
	foreach ( turf_trackable_post_types() as $post_type ) {
		$post_type_object = get_post_type_object( $post_type );

		if ( $post_type_object && $post_type_object->show_in_rest ) {
			add_filter( "rest_prepare_{$post_type}", 'turf_track_rest_post_view', 10, 3 );
		}
	}

	foreach ( turf_trackable_taxonomies() as $taxonomy ) {
		$taxonomy_object = get_taxonomy( $taxonomy );

		if ( $taxonomy_object && $taxonomy_object->show_in_rest ) {
			add_filter( "rest_prepare_{$taxonomy}", 'turf_track_rest_term_view', 10, 3 );
		}
	}
}
add_action( 'rest_api_init', 'turf_register_rest_tracking', 20 );
add_filter( 'rest_post_dispatch', 'turf_track_connector_app_view', 10, 3 );

function turf_track_rest_post_view( $response, $post, $request ) {
	$is_view = turf_is_rest_single_item_request( $request, $post->ID );
	turf_maybe_log_rest_debug( $request, $is_view, "post #{$post->ID}" );

	if ( $is_view ) {
		turf_track_view( $post->ID, 'post', array( 'referrer_host' => TURF_REST_SOURCE_MARKER ) );
	}

	return $response;
}

function turf_track_rest_term_view( $response, $term, $request ) {
	$is_view = turf_is_rest_single_item_request( $request, $term->term_id );
	turf_maybe_log_rest_debug( $request, $is_view, "term #{$term->term_id}" );

	if ( $is_view ) {
		turf_track_view( $term->term_id, 'term', array( 'referrer_host' => TURF_REST_SOURCE_MARKER ) );
	}

	return $response;
}

/**
 * Route patterns recognized as a companion app's own single-item endpoints.
 * Empty by default - a site running a specific connector plugin opts it in
 * via this filter, e.g.:
 *
 *     add_filter( 'turf_connector_app_route_patterns', function () {
 *         return array( '#^/my-connector/v1/posts/\d+$#' );
 *     } );
 */
function turf_connector_app_route_patterns() {
	return apply_filters( 'turf_connector_app_route_patterns', array() );
}

function turf_track_connector_app_view( $response, $server, $request ) {
	if ( ! ( $request instanceof WP_REST_Request ) || 'GET' !== $request->get_method() ) {
		return $response;
	}

	if ( current_user_can( 'edit_posts' ) ) {
		return $response;
	}

	$route   = $request->get_route();
	$matches = false;

	foreach ( turf_connector_app_route_patterns() as $pattern ) {
		if ( preg_match( $pattern, $route ) ) {
			$matches = true;
			break;
		}
	}

	if ( ! $matches ) {
		return $response;
	}

	$post_id = 0;

	if ( $response instanceof WP_REST_Response ) {
		$data    = $response->get_data();
		$post_id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
	}

	turf_maybe_log_rest_debug( $request, (bool) $post_id, "connector app {$route}" );

	if ( $post_id ) {
		turf_track_view( $post_id, 'post', array( 'referrer_host' => TURF_CONNECTOR_APP_SOURCE_MARKER ) );
	}

	return $response;
}

/**
 * Temporary diagnostic aid - see the TURF_DEBUG_REST note above. Logs every
 * rest_prepare_* hit, not just the ones that end up counted as a view, so
 * you can also see collection/list requests and editor traffic for context.
 */
function turf_maybe_log_rest_debug( $request, $counted, $item_label ) {
	if ( ! defined( 'TURF_DEBUG_REST' ) || ! TURF_DEBUG_REST ) {
		return;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '(geen)';
	$route      = ( $request instanceof WP_REST_Request ) ? $request->get_route() : '(onbekend)';
	$method     = ( $request instanceof WP_REST_Request ) ? $request->get_method() : '?';

	error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in diagnostic, gated behind the TURF_DEBUG_REST constant above (off by default).
		'[Turf REST debug] %s %s (%s) - geteld als view: %s - UA: %s',
		$method,
		$route,
		$item_label,
		$counted ? 'ja' : 'nee',
		$user_agent
	) );
}

/**
 * True only for a single-item GET request for this exact ID (e.g.
 * /wp/v2/posts/123) - not a collection/list request that happens to include
 * this item among others (rest_prepare_{type} fires once per item either
 * way), and not a block-editor "edit" context request.
 */
function turf_is_rest_single_item_request( $request, $id ) {
	if ( ! ( $request instanceof WP_REST_Request ) || 'GET' !== $request->get_method() ) {
		return false;
	}

	if ( 'edit' === $request->get_param( 'context' ) || current_user_can( 'edit_posts' ) ) {
		return false;
	}

	return (bool) preg_match( '#/' . $id . '$#', $request->get_route() );
}
