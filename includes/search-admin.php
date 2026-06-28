<?php
/**
 * Admin queries/renderers for search-term tracking - registered as two
 * metaboxes on the main Statistieken page from turf_views_register_metaboxes()
 * in views-admin.php, not their own submenu page.
 */

function turf_search_get_top_terms( $days, $limit = 15 ) {
	global $wpdb;
	$table = turf_search_table();

	$where_date = '';
	$params     = array();

	if ( $days > 0 ) {
		$where_date = 'WHERE searched_at >= %s';
		$params[]   = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
	}

	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT search_term, COUNT(*) AS searches, AVG(results_count) AS avg_results
		FROM $table $where_date
		GROUP BY search_term
		ORDER BY searches DESC
		LIMIT %d",
		$params
	) );
}

function turf_search_get_zero_result_terms( $days, $limit = 15 ) {
	global $wpdb;
	$table = turf_search_table();

	$where_date = '';
	$params     = array();

	if ( $days > 0 ) {
		$where_date = 'AND searched_at >= %s';
		$params[]   = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
	}

	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT search_term, COUNT(*) AS searches
		FROM $table
		WHERE results_count = 0 $where_date
		GROUP BY search_term
		ORDER BY searches DESC
		LIMIT %d",
		$params
	) );
}

function turf_search_render_top_terms( $days ) {
	$rows = turf_search_get_top_terms( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Nog geen zoekopdrachten voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Zoekterm', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Keer gezocht', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Gem. resultaten', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->search_term ); ?></td>
					<td><?php echo (int) $row->searches; ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $row->avg_results, 1 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function turf_search_render_zero_results( $days ) {
	$rows = turf_search_get_zero_result_terms( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Geen zoekopdrachten zonder resultaat voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Wat bezoekers zoeken maar niet vinden - kandidaten voor nieuwe content of een redirect.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Zoekterm', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Keer gezocht', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->search_term ); ?></td>
					<td><?php echo (int) $row->searches; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
