<?php
/**
 * "Formulieren" metabox - top form submissions per period, with a
 * conversion rate against the page the form last appeared on (when that
 * page resolves to a known post). Always registered - it just shows an
 * empty state if neither Contact Form 7 nor Gravity Forms is active.
 */

function turf_forms_get_top_forms( $days, $limit = 10 ) {
	global $wpdb;
	$table = turf_forms_table();

	$where_date = '';
	$params     = array();

	if ( 0 !== $days ) {
		$where_date = 'WHERE submitted_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT plugin, form_id, form_title, COUNT(*) AS submissions,
			SUBSTRING_INDEX(GROUP_CONCAT(page_path ORDER BY id DESC), ',', 1) AS last_page_path
		FROM $table $where_date
		GROUP BY plugin, form_id, form_title
		ORDER BY submissions DESC
		LIMIT %d",
		$params
	) );
}

function turf_forms_plugin_label( $plugin ) {
	$labels = array(
		'cf7'          => __( 'Contact Form 7', 'turf-stats' ),
		'gravityforms' => __( 'Gravity Forms', 'turf-stats' ),
	);

	return $labels[ $plugin ] ?? $plugin;
}

function turf_forms_render_top_forms( $days ) {
	$rows = turf_forms_get_top_forms( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Nog geen formulierinzendingen voor deze periode (of geen Contact Form 7/Gravity Forms actief).', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Formulier', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Inzendingen', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Conversie', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$label      = $row->form_title ?: ( turf_forms_plugin_label( $row->plugin ) . ' #' . $row->form_id );
				$conversion = '—';
				$post_id    = $row->last_page_path ? url_to_postid( home_url( $row->last_page_path ) ) : 0;

				if ( $post_id ) {
					global $wpdb;

					if ( 0 !== $days ) {
						$page_views = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM " . turf_table() . " WHERE post_id = %d AND viewed_at >= %s",
							$post_id,
							turf_period_start_sql_date( $days )
						) );
					} else {
						$page_views = turf_get_views( $post_id );
					}

					if ( $page_views > 0 ) {
						$conversion = number_format_i18n( ( $row->submissions / $page_views ) * 100, 1 ) . '%';
					}
				}
				?>
				<tr>
					<td>
						<?php echo esc_html( $label ); ?>
						<span class="description">(<?php echo esc_html( turf_forms_plugin_label( $row->plugin ) ); ?>)</span>
					</td>
					<td><?php echo (int) $row->submissions; ?></td>
					<td><?php echo esc_html( $conversion ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
