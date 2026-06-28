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
		echo '<p>' . esc_html__( 'Nog geen sessies met meer dan één paginaweergave voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}

	$rows = turf_get_top_transitions( $days );
	?>
	<p class="description">
		<?php esc_html_e( 'Van welke pagina bezoekers naar welke andere pagina doorklikken binnen één bezoek (sessies van max. 30 minuten tussen weergaven).', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Van', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Naar', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Keer', 'turf-stats' ); ?></th>
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
