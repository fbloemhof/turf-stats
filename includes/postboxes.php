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

	wp_enqueue_script( 'turf-postbox-more', TURF_URL . 'js/postbox-more.js', array(), TURF_VERSION, true );
	wp_localize_script( 'turf-postbox-more', 'turfPostboxMore', array(
		'moreLabel' => __( 'Toon %d meer', 'turf-stats' ),
		'lessLabel' => __( 'Toon minder', 'turf-stats' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'turf_postboxes_enqueue' );

/**
 * Renders just one postbox-container + its boxes, with no outer wrapper -
 * the building block for everything below. A page with several sections
 * (e.g. Statistieken's overview / compact-breakdowns / tables) calls this
 * (or turf_render_postbox_columns()) more than once inside one shared
 * #poststuff wrapper of its own; turf_render_postboxes() below covers the
 * common single-section case.
 */
function turf_render_postbox_column( $hook, $context ) {
	?>
	<div class="postbox-container">
		<?php do_meta_boxes( $hook, $context, null ); ?>
	</div>
	<?php
}

/**
 * Renders several postbox-container columns side by side, one per given
 * context - each its own independent (but cross-connected, via WP core's
 * own postboxes.js) sortable area, so boxes can be dragged between columns.
 * Doesn't rely on WP core's own columns-N CSS (tuned for a wide-main +
 * narrow-sidebar layout, not equal columns) - .turf-postbox-columns below
 * is Turf's own, deliberately equal-width, layout.
 *
 * @param string[] $contexts Context strings used when registering the boxes for these columns via add_meta_box().
 */
function turf_render_postbox_columns( $hook, array $contexts ) {
	?>
	<div class="turf-postbox-columns">
		<?php foreach ( $contexts as $context ) : ?>
			<?php turf_render_postbox_column( $hook, $context ); ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Renders the single-column postbox container with everything registered
 * for $hook under $context via add_meta_box(). Call from the page's own
 * render callback, after the load-$hook action (where add_meta_box() calls
 * happen) has run.
 */
function turf_render_postboxes( $hook, $context = 'normal' ) {
	?>
	<div id="poststuff">
		<?php turf_render_postbox_column( $hook, $context ); ?>
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
