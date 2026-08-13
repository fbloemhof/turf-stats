<?php
/**
 * Per-author statistics - no new tracking, just the existing view log
 * grouped by post_author instead of by post. Registered as a metabox on
 * the Statistieken page from turf_views_register_metaboxes().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_get_author_breakdown( $days, $limit = 15 ) {
	global $wpdb;
	$table = turf_table();

	// $placeholders is a generated "%s, %s, ..." placeholder list (one per
	// trackable post type), not data - the values themselves are passed to
	// $wpdb->prepare() below. Same for $placeholders_authors further down.
	list( $placeholders, $post_types ) = turf_post_type_in_clause();

	if ( 0 === $days ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_author AS author_id, SUM(m.meta_value + 0) AS views, COUNT(DISTINCT p.ID) AS posts
			FROM $wpdb->posts p
			INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'
			GROUP BY p.post_author
			ORDER BY views DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list, see above.
			array_merge( array( TURF_META_KEY ), $post_types, array( $limit ) )
		) );
	} else {
		list( $where_sql, $date_params ) = turf_period_where_sql( $days, 'v.viewed_at' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_author AS author_id, COUNT(*) AS views, COUNT(DISTINCT v.post_id) AS posts
			FROM {$table} v
			INNER JOIN $wpdb->posts p ON p.ID = v.post_id
			WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'
			$where_sql
			GROUP BY p.post_author
			ORDER BY views DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name; $placeholders is a generated %s list.
			array_merge( $post_types, $date_params, array( $limit ) )
		) );
	}

	if ( ! $rows ) {
		return $rows;
	}

	// Engagement (reading time/scroll) only exists in the event log, never in
	// the legacy postmeta baseline - fetched separately in one query for all
	// matching authors at once, then merged in, rather than per-row N+1s.
	$author_ids   = wp_list_pluck( $rows, 'author_id' );
	$placeholders_authors = implode( ',', array_fill( 0, count( $author_ids ), '%d' ) );

	$engagement = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.post_author AS author_id, AVG(v.duration_seconds) AS avg_duration, AVG(v.scroll_depth) AS avg_scroll
		FROM {$table} v
		INNER JOIN $wpdb->posts p ON p.ID = v.post_id
		WHERE p.post_author IN ($placeholders_authors) AND p.post_type IN ($placeholders) AND v.duration_seconds IS NOT NULL
		GROUP BY p.post_author", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name; both $placeholders_* are generated %d/%s lists.
		array_merge( $author_ids, $post_types )
	), OBJECT_K );

	foreach ( $rows as $row ) {
		$eng                = $engagement[ $row->author_id ] ?? null;
		$row->avg_duration   = $eng ? $eng->avg_duration : null;
		$row->avg_scroll     = $eng ? $eng->avg_scroll : null;
	}

	return $rows;
}

function turf_render_author_breakdown( $days ) {
	$rows = turf_get_author_breakdown( $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Author', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Posts', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Views', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. reading time', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Avg. scroll depth', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$author     = get_userdata( $row->author_id );
				$name       = $author ? $author->display_name : '#' . $row->author_id;
				$avg_duration = isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : null;
				$avg_scroll   = isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null;
				?>
				<tr>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo (int) $row->posts; ?></td>
					<td><?php echo (int) $row->views; ?></td>
					<td><?php echo esc_html( turf_format_duration( $avg_duration ) ); ?></td>
					<td><?php echo esc_html( turf_format_scroll( $avg_scroll ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
