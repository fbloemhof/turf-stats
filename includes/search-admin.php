<?php
/**
 * Admin queries/renderers for search-term tracking - registered as two
 * metaboxes on the main Statistieken page from turf_views_register_metaboxes()
 * in views-admin.php, not their own submenu page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_search_get_top_terms( $days, $limit = 15 ) {
	global $wpdb;
	$table = turf_search_table();

	$where_date = '';
	$params     = array();

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'searched_at' );
		$params     = array_merge( $date_params, $params );
	}

	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT search_term, COUNT(*) AS searches, AVG(results_count) AS avg_results
		FROM $table
		WHERE 1=1 $where_date
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

	if ( 0 !== $days ) {
		list( $where_date, $date_params ) = turf_period_where_sql( $days, 'searched_at' );
		$params     = array_merge( $date_params, $params );
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
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Search term', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times searched', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. results', 'turf-stats' ); ?></th>
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
	turf_render_export_link( 'turf_export_search_terms' );
}

function turf_search_render_zero_results( $days ) {
	$rows = turf_search_get_zero_result_terms( $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<p class="description">
		<?php esc_html_e( 'What visitors search for but don\'t find - candidates for new content or a redirect.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Search term', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times searched', 'turf-stats' ); ?></th>
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
