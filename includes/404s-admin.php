<?php
/**
 * "404's" submenu page - top requested-but-missing paths per period.
 */

function turf_404s_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( '404s', 'turf-stats' ),
		__( '404s', 'turf-stats' ),
		'manage_options',
		'turf-404s',
		'turf_404s_render_admin_page'
	);

	add_action( "load-$hook", 'turf_404s_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_404s_admin_menu' );

function turf_404s_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	add_meta_box( 'turf_404s_top', __( 'Most-hit URLs', 'turf-stats' ), function () use ( $days ) {
		turf_404s_render_top_paths( $days );
	}, $hook, 'normal' );
}

/**
 * Initial rows shown before the "Show more" toggle on the 404s list -
 * deliberately higher than the default 5 (404-hunting wants a longer list at a
 * glance), passed to the list via data-turf-visible below.
 */
define( 'TURF_404S_VISIBLE', 20 );

function turf_404s_get_top_paths( $days ) {
	global $wpdb;
	$table = turf_404s_table();

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT path, COUNT(*) AS hits, MAX(hit_at) AS last_hit FROM $table
			GROUP BY path ORDER BY hits DESC LIMIT %d",
			turf_list_max()
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT path, COUNT(*) AS hits, MAX(hit_at) AS last_hit FROM $table
		WHERE hit_at >= %s
		GROUP BY path ORDER BY hits DESC LIMIT %d",
		turf_period_start_sql_date( $days ),
		turf_list_max()
	) );
}

function turf_404s_render_top_paths( $days ) {
	$rows = turf_404s_get_top_paths( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No 404s recorded for this period.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped" data-turf-visible="<?php echo (int) TURF_404S_VISIBLE; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Path', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times hit', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Last', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $row->path ); ?></code></td>
					<td><?php echo (int) $row->hits; ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $row->last_hit, 'd-m-Y H:i' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function turf_404s_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '404s', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Which non-existent URLs visitors hit - handy for finding and fixing broken links.', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-404s' ) ); ?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}
