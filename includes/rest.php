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
 */

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

function turf_track_rest_post_view( $response, $post, $request ) {
	if ( turf_is_rest_single_item_request( $request, $post->ID ) ) {
		turf_track_view( $post->ID, 'post', array( 'referrer_host' => TURF_REST_SOURCE_MARKER ) );
	}

	return $response;
}

function turf_track_rest_term_view( $response, $term, $request ) {
	if ( turf_is_rest_single_item_request( $request, $term->term_id ) ) {
		turf_track_view( $term->term_id, 'term', array( 'referrer_host' => TURF_REST_SOURCE_MARKER ) );
	}

	return $response;
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
