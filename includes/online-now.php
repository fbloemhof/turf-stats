<?php
/**
 * "Online now" - a live count of distinct visitors active in the last few
 * minutes, like Clicky's "Online now". Uses the same view-event table and
 * visitor_hash already recorded for regular tracking - no new tracking
 * mechanism needed, just a query with a short time window plus an
 * auto-refreshing widget on the admin page.
 */

/**
 * How recent a view has to be to count as "still online". Filterable.
 */
function turf_online_now_window() {
	return (int) apply_filters( 'turf_online_now_window', 5 * MINUTE_IN_SECONDS );
}

function turf_get_online_now_count() {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$since      = gmdate( 'Y-m-d H:i:s', time() - turf_online_now_window() );
	$params[]   = $since;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.visitor_hash) FROM $table v $join WHERE $where AND v.viewed_at >= %s",
		$params
	) );
}

/**
 * Same .bk-stats-box markup as the regular totals (Weergaven/Bezoekers/...),
 * so it sits in that row as a matching square box instead of a separately
 * styled badge. The pulsing dot next to the label is the only visual
 * difference, signalling "this one updates live" - id="turf-online-now-value"
 * is what js/online-now.js polls and rewrites.
 */
function turf_render_online_now() {
	?>
	<div class="bk-stats-box">
		<span class="bk-stats-box__label">
			<span class="bk-stats-online-now__dot"></span>
			<?php esc_html_e( 'Nu online', 'turf-stats' ); ?>
		</span>
		<span class="bk-stats-box__value" id="turf-online-now-value"><?php echo esc_html( number_format_i18n( turf_get_online_now_count() ) ); ?></span>
	</div>
	<?php
}

function turf_online_now_enqueue( $hook ) {
	if ( 'toplevel_page_turf-stats' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'turf-online-now',
		TURF_URL . 'js/online-now.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-online-now', 'turfOnlineNow', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'turf_online_now' ),
		'interval' => 20000, // ms
	) );
}
add_action( 'admin_enqueue_scripts', 'turf_online_now_enqueue' );

function turf_ajax_online_now() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_online_now' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	wp_send_json_success( array( 'count' => turf_get_online_now_count() ) );
}
add_action( 'wp_ajax_turf_online_now', 'turf_ajax_online_now' );
