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
		return; // No output, so turf_maybe_add_meta_box() drops the box.
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

/**
 * "Landing pages" metabox content - where visitors' sessions begin, with a
 * per-landing bounce rate (share of those sessions that were single-page).
 * Registered from turf_analyse_register_metaboxes() in analyse-admin.php.
 */
function turf_render_landing_pages( $days ) {
	$rows = turf_get_top_landing_pages( $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<p class="description">
		<?php esc_html_e( 'The first page of each visit - where visitors arrive. Bounce % is the share of those visits that ended on this same page.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Landing page', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Sessions', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Bounce %', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $rows as $row ) :
				$label  = turf_resolve_object_label( $row['type'], $row['id'] );
				$bounce = $row['sessions'] ? (int) round( ( $row['bounced'] / $row['sessions'] ) * 100 ) : 0;
				?>
				<tr>
					<td><?php echo esc_html( $label ); ?></td>
					<td><?php echo (int) $row['sessions']; ?></td>
					<td><?php echo (int) $bounce; ?>%</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
