<?php
/**
 * Shared "WP-native postboxes" page shell for Turf's admin pages - every
 * stat block becomes a real wp-admin .postbox: collapsible, draggable/
 * reorderable (persisted via WP core's own existing meta-box-order AJAX
 * handler - nothing custom needed for that), and individually hideable via
 * the screen's native "Schermopties" panel. Same mechanism the Dashboard
 * widgets use, just pointed at our own pages.
 */

/**
 * Tracks which admin page hooks have postboxes, so turf_postboxes_enqueue()
 * only loads the postbox JS where it's actually needed.
 */
function turf_register_postbox_hook( $hook = null ) {
	static $hooks = array();

	if ( null !== $hook ) {
		$hooks[] = $hook;
	}

	return $hooks;
}

function turf_postboxes_enqueue( $hook ) {
	if ( ! in_array( $hook, turf_register_postbox_hook(), true ) ) {
		return;
	}

	wp_enqueue_script( 'postbox' );
	wp_add_inline_script(
		'postbox',
		'jQuery(function($){ postboxes.add_postbox_toggles(' . wp_json_encode( $hook ) . '); });'
	);
}
add_action( 'admin_enqueue_scripts', 'turf_postboxes_enqueue' );

/**
 * Renders the single-column postbox container with everything registered
 * for $hook via add_meta_box(). Call from the page's own render callback,
 * after the load-$hook action (where add_meta_box() calls happen) has run.
 */
function turf_render_postboxes( $hook ) {
	?>
	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-1">
			<div id="postbox-container-1" class="postbox-container">
				<?php do_meta_boxes( $hook, 'normal', null ); ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Shared "7 / 30 / 90 dagen / Alles" period resolution, used by every Turf
 * admin page and by the metabox content callbacks registered for it.
 */
function turf_period_days_map() {
	return array( '7' => 7, '30' => 30, '90' => 90, 'all' => 0 );
}

function turf_get_requested_days() {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7';
	$map    = turf_period_days_map();

	return isset( $map[ $period ] ) ? $map[ $period ] : 7;
}

function turf_render_period_tabs( $base_url ) {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7';
	$labels = array(
		'7'   => __( '7 dagen', 'turf-stats' ),
		'30'  => __( '30 dagen', 'turf-stats' ),
		'90'  => __( '90 dagen', 'turf-stats' ),
		'all' => __( 'Alles', 'turf-stats' ),
	);
	?>
	<ul class="subsubsub">
		<?php foreach ( $labels as $key => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'period', $key, $base_url ) ); ?>" <?php echo $period === (string) $key ? 'class="current"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a> |
			</li>
		<?php endforeach; ?>
	</ul>
	<br class="clear" />
	<?php
}
