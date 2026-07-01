<?php
/**
 * "Bezoekersroutes" metabox content - top page-to-page transitions within a
 * session. Registered from turf_views_register_metaboxes() in
 * views-admin.php.
 */

function turf_render_session_routes( $days ) {
	$sessions = turf_compute_sessions( $days );

	$multi_page_sessions = array_filter( $sessions, function ( $session ) {
		return count( $session['pages'] ) > 1;
	} );

	if ( ! $multi_page_sessions ) {
		echo '<p>' . esc_html__( 'No sessions with more than one page view yet for this period.', 'turf-stats' ) . '</p>';
		return;
	}

	$rows = turf_get_top_transitions( $days );
	?>
	<p class="description">
		<?php esc_html_e( 'Which page visitors click through to next within a single visit (sessions with max. 30 minutes between views).', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'From', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'To', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['from'] ); ?></td>
					<td><?php echo esc_html( $row['to'] ); ?></td>
					<td><?php echo (int) $row['count']; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
