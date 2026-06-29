<?php
/**
 * Comment counts per period and "most discussed" content. Reads directly
 * from WordPress' own wp_comments table - comments are already tracked
 * natively, so there's no event table or front-end JS needed here, just
 * reporting on top of data WordPress already has.
 *
 * Only counts approved comments (comment_approved = '1'), and only on
 * trackable post types, so this lines up with what the rest of the
 * "Statistieken" page already reports on.
 */

/**
 * Comment totals for a date range (UTC), or all-time when $days is 0.
 */
function turf_get_comment_totals( $days, $offset_days = 0 ) {
	global $wpdb;

	list( $placeholders, $post_types ) = turf_post_type_in_clause();

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->comments c
			INNER JOIN $wpdb->posts p ON p.ID = c.comment_post_ID
			WHERE c.comment_approved = '1' AND p.post_type IN ($placeholders) AND p.post_status = 'publish'",
			$post_types
		) );
	}

	if ( TURF_PERIOD_TODAY === $days ) {
		$end   = ( 0 === $offset_days ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d 00:00:00' );
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$offset_days} days" ) );
	} else {
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );
		$start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $offset_days + $days ) . ' days' ) );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $wpdb->comments c
		INNER JOIN $wpdb->posts p ON p.ID = c.comment_post_ID
		WHERE c.comment_approved = '1' AND p.post_type IN ($placeholders) AND p.post_status = 'publish'
		AND c.comment_date_gmt >= %s AND c.comment_date_gmt < %s",
		array_merge( $post_types, array( $start, $end ) )
	) );
}

function turf_count_commented_posts( $days ) {
	global $wpdb;

	list( $placeholders, $post_types ) = turf_post_type_in_clause();

	$where_date = '';
	$params     = $post_types;

	if ( 0 !== $days ) {
		$where_date = 'AND c.comment_date_gmt >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT c.comment_post_ID) FROM $wpdb->comments c
		INNER JOIN $wpdb->posts p ON p.ID = c.comment_post_ID
		WHERE c.comment_approved = '1' AND p.post_type IN ($placeholders) AND p.post_status = 'publish' $where_date",
		$params
	) );
}

function turf_get_top_commented_posts( $days, $page = 1 ) {
	global $wpdb;

	list( $placeholders, $post_types ) = turf_post_type_in_clause();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	$where_date = '';
	$params     = $post_types;

	if ( 0 !== $days ) {
		$where_date = 'AND c.comment_date_gmt >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$params[] = TURF_PER_PAGE;
	$params[] = $offset;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT c.comment_post_ID AS post_id, COUNT(*) AS comments
		FROM $wpdb->comments c
		INNER JOIN $wpdb->posts p ON p.ID = c.comment_post_ID
		WHERE c.comment_approved = '1' AND p.post_type IN ($placeholders) AND p.post_status = 'publish' $where_date
		GROUP BY c.comment_post_ID
		ORDER BY comments DESC
		LIMIT %d OFFSET %d",
		$params
	) );
}

function turf_render_top_commented_posts( $days ) {
	$param          = 'pg_comments';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;

	$total = turf_count_commented_posts( $days );

	if ( ! $total ) {
		echo '<p>' . esc_html__( 'Nog geen reacties voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}

	$total_pages = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page        = min( $requested_page, $total_pages );
	$rows        = turf_get_top_commented_posts( $days, $page );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Titel', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Type', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Reacties', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$title = get_the_title( $row->post_id );
				$edit  = get_edit_post_link( $row->post_id );
				$type  = get_post_type( $row->post_id );
				?>
				<tr>
					<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ?: '#' . $row->post_id ); ?></td>
					<td><?php echo esc_html( $type ? turf_get_post_type_label( $type ) : '—' ); ?></td>
					<td><?php echo (int) $row->comments; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php turf_render_pagination( $param, $page, $total_pages ); ?>
		</div></div>
	<?php endif; ?>
	<?php
}
