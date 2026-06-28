<?php
/**
 * Optional WooCommerce integration - product views already work for free
 * (WooCommerce products are just a public CPT, already covered by Turf's
 * automatic post-type detection). This adds the two funnel steps nothing
 * else in Turf exposes: add-to-cart and completed checkout, so the
 * view -> cart -> checkout funnel can be read end to end. Entirely inert
 * if WooCommerce isn't active - and since plugins load in alphabetical
 * order ("turf-stats" loads before "woocommerce"), the class_exists()
 * check can't happen at file top-level, only once `init` has fired.
 */

define( 'TURF_WOO_DB_VERSION', '1.0' );

function turf_woo_active() {
	return class_exists( 'WooCommerce' );
}

function turf_woo_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_woo_events';
}

function turf_woo_install() {
	if ( ! turf_woo_active() || get_option( 'turf_woo_db_version' ) === TURF_WOO_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_woo_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_type VARCHAR(20) NOT NULL,
		product_id BIGINT UNSIGNED NULL DEFAULT NULL,
		visitor_hash CHAR(32) NOT NULL,
		occurred_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY type_lookup (event_type, occurred_at),
		KEY product_lookup (product_id, occurred_at)
	) $charset_collate;" );

	update_option( 'turf_woo_db_version', TURF_WOO_DB_VERSION );
}
add_action( 'init', 'turf_woo_install' );

function turf_woo_register_hooks() {
	if ( ! turf_woo_active() ) {
		return;
	}

	add_action( 'woocommerce_add_to_cart', 'turf_woo_track_add_to_cart', 10, 2 );
	add_action( 'woocommerce_thankyou', 'turf_woo_track_checkout' );
}
add_action( 'init', 'turf_woo_register_hooks' );

function turf_woo_visitor_hash() {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	return turf_visitor_hash( $user_agent );
}

function turf_woo_track_add_to_cart( $cart_item_key, $product_id ) {
	global $wpdb;

	$wpdb->insert(
		turf_woo_table(),
		array(
			'event_type'   => 'add_to_cart',
			'product_id'   => (int) $product_id,
			'visitor_hash' => turf_woo_visitor_hash(),
			'occurred_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s' )
	);
}

/**
 * woocommerce_thankyou fires every time the order-received page loads, not
 * just once - a refresh would double-count without this guard. $order's own
 * meta API is used (not get_post_meta()) so this still works correctly
 * under WooCommerce's High-Performance Order Storage, where orders aren't
 * wp_posts rows at all.
 */
function turf_woo_track_checkout( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || $order->get_meta( '_turf_checkout_tracked' ) ) {
		return;
	}

	global $wpdb;
	$wpdb->insert(
		turf_woo_table(),
		array(
			'event_type'   => 'checkout',
			'product_id'   => null,
			'visitor_hash' => turf_woo_visitor_hash(),
			'occurred_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%d', '%s', '%s' )
	);

	$order->update_meta_data( '_turf_checkout_tracked', 1 );
	$order->save();
}
