<?php
/**
 * Sortable "Views" column on the posts list table (edit.php) for every
 * trackable post type - reads the cached postmeta total (TURF_META_KEY)
 * rather than querying the events table, so it's cheap enough for a list
 * that can hold hundreds of rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooked on admin_init (not loaded directly) so custom post types registered
 * on 'init' by other plugins/themes are already known to
 * turf_trackable_post_types() by the time these run.
 */
function turf_register_post_list_columns() {
	foreach ( turf_trackable_post_types() as $post_type ) {
		add_filter( "manage_{$post_type}_posts_columns", 'turf_add_views_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'turf_render_views_column', 10, 2 );
		add_filter( "manage_edit-{$post_type}_sortable_columns", 'turf_views_column_sortable' );
	}
}
add_action( 'admin_init', 'turf_register_post_list_columns' );

function turf_add_views_column( $columns ) {
	// Keep "Date" last, same spot WP always puts it, instead of letting it
	// trail after our column.
	$date = isset( $columns['date'] ) ? array( 'date' => $columns['date'] ) : array();
	unset( $columns['date'] );

	$columns['turf_views'] = __( 'Views', 'turf-stats' );

	return array_merge( $columns, $date );
}

function turf_render_views_column( $column, $post_id ) {
	if ( 'turf_views' !== $column ) {
		return;
	}

	echo esc_html( number_format_i18n( turf_get_views( $post_id, 'post' ) ) );
}

function turf_views_column_sortable( $columns ) {
	$columns['turf_views'] = 'turf_views';

	return $columns;
}

/**
 * Without an explicit width, the column shares the list table's fixed
 * layout equally with Title instead of shrinking to fit a short number -
 * same reason core narrows Comments/Date via CSS rather than leaving them
 * unstyled.
 */
function turf_post_list_column_css() {
	echo '<style>.column-turf_views { width: 10%; }</style>';
}
add_action( 'admin_head-edit.php', 'turf_post_list_column_css' );

/**
 * A post only gets its _turf_views postmeta row once it receives its first
 * view (see turf_get_views()), so never-viewed posts need to keep sorting as
 * 0 rather than drop out of the list.
 *
 * This is a plain LEFT JOIN + ORDER BY rather than WP_Query's meta_query
 * EXISTS/NOT EXISTS trick: that trick relies on GROUP BY to dedupe, and its
 * EXISTS side joins postmeta without a meta_key condition in the ON clause
 * (the key is only checked in WHERE), so a post with several other meta
 * fields joins one row per field - GROUP BY then collapses those to an
 * arbitrary row for the ORDER BY expression, which is why every row sorted
 * as 0 in practice. Joining once, with the meta_key condition baked into the
 * ON clause, gives at most one matching row per post - no ambiguity, no
 * GROUP BY needed.
 */
function turf_views_sort_join( $join, $query ) {
	if ( ! turf_is_views_sort_query( $query ) ) {
		return $join;
	}

	global $wpdb;

	return $join . $wpdb->prepare(
		" LEFT JOIN {$wpdb->postmeta} turf_views_sort ON ( turf_views_sort.post_id = {$wpdb->posts}.ID AND turf_views_sort.meta_key = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta/posts are table names, not user input.
		TURF_META_KEY
	);
}
add_filter( 'posts_join', 'turf_views_sort_join', 10, 2 );

function turf_views_sort_orderby( $orderby, $query ) {
	if ( ! turf_is_views_sort_query( $query ) ) {
		return $orderby;
	}

	$order = 'ASC' === strtoupper( $query->get( 'order' ) ) ? 'ASC' : 'DESC';

	return "CAST(turf_views_sort.meta_value AS SIGNED) $order";
}
add_filter( 'posts_orderby', 'turf_views_sort_orderby', 10, 2 );

/**
 * No is_main_query() check here on purpose: WP_Posts_List_Table builds its
 * own standalone WP_Query for edit.php rather than using the global main
 * query, so that check is never true there and would silently no-op this
 * whole sort (which is exactly what happened before this was removed -
 * WordPress fell back to its default date ordering instead of erroring).
 */
function turf_is_views_sort_query( $query ) {
	return is_admin() && 'turf_views' === $query->get( 'orderby' );
}
