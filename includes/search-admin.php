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

/**
 * What each search in the period led to, derived from what the same visitor
 * did next (no extra tracking): within turf_session_gap_seconds() after the
 * search, the earliest of
 *   'clicked' - a view of a post/page (a search result, as far as Turf can tell)
 *   'refined' - a search for a different term
 *   'other'   - a view of some other page (an archive, the home page, ...)
 * or 'left' when none of those happened. Views of the search results page
 * itself and repeat searches for the same term (paging through results)
 * don't count as a next step. Capped at turf_session_row_limit() searches,
 * newest first.
 *
 * @return object[] Each with ->search_term, ->outcome, ->next_post_id and
 *                  ->next_term (the latter two only for their outcome).
 */
function turf_search_get_outcomes( $days ) {
	return turf_stats_cached( array( 'search_outcomes', $days ), function () use ( $days ) {
		global $wpdb;
		$searches = turf_search_table();
		$views    = turf_table();
		$window   = turf_session_gap_seconds();

		list( $where_date, $date_params ) = turf_period_where_sql( $days, 's.searched_at' );

		$next_view = "FROM $views v
			WHERE v.visitor_hash = s.visitor_hash AND v.suspect = 0
			AND v.viewed_at > s.searched_at AND v.viewed_at <= s.searched_at + INTERVAL %d SECOND";

		$next_search = "FROM $searches s2
			WHERE s2.visitor_hash = s.visitor_hash AND s2.search_term != s.search_term
			AND s2.searched_at > s.searched_at AND s2.searched_at <= s.searched_at + INTERVAL %d SECOND";

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.search_term,
				( SELECT MIN(v.viewed_at) $next_view AND v.post_id IS NOT NULL ) AS next_post_at,
				( SELECT v.post_id $next_view AND v.post_id IS NOT NULL ORDER BY v.viewed_at LIMIT 1 ) AS next_post_id,
				( SELECT MIN(v.viewed_at) $next_view AND v.post_id IS NULL AND ( v.page_type IS NULL OR v.page_type != 'search' ) ) AS next_other_at,
				( SELECT MIN(s2.searched_at) $next_search ) AS next_search_at,
				( SELECT s2.search_term $next_search ORDER BY s2.searched_at LIMIT 1 ) AS next_term
			FROM $searches s
			WHERE s.visitor_hash != '' $where_date
			ORDER BY s.searched_at DESC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix tables and literal subqueries.
			array_merge( array( $window, $window, $window, $window, $window ), $date_params, array( turf_session_row_limit() ) )
		) );

		foreach ( $rows as $row ) {
			$candidates = array_filter( array(
				'clicked' => $row->next_post_at,
				'refined' => $row->next_search_at,
				'other'   => $row->next_other_at,
			) );

			asort( $candidates );
			$row->outcome = $candidates ? array_key_first( $candidates ) : 'left';
		}

		return $rows;
	} );
}

/**
 * Grouping key for a search term - "Agenda" and "agenda" are one term.
 */
function turf_search_term_key( $term ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $term ) : strtolower( $term );
}

function turf_search_outcome_labels() {
	return array(
		'clicked' => __( 'Opened a result', 'turf-stats' ),
		'refined' => __( 'Searched again', 'turf-stats' ),
		'other'   => __( 'Went elsewhere', 'turf-stats' ),
		'left'    => __( 'Left', 'turf-stats' ),
	);
}

/**
 * Per search term: how many searches, and the share of each outcome - most
 * searched terms first, with an "All searches" total on top.
 */
function turf_search_render_success( $days ) {
	$rows = turf_search_get_outcomes( $days );

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}

	$labels = turf_search_outcome_labels();
	$empty  = array_fill_keys( array_keys( $labels ), 0 );
	$total  = array( 'searches' => 0, 'outcomes' => $empty );
	$terms  = array();

	foreach ( $rows as $row ) {
		$key = turf_search_term_key( $row->search_term );

		if ( ! isset( $terms[ $key ] ) ) {
			$terms[ $key ] = array( 'label' => $row->search_term, 'searches' => 0, 'outcomes' => $empty );
		}

		++$terms[ $key ]['searches'];
		++$terms[ $key ]['outcomes'][ $row->outcome ];
		++$total['searches'];
		++$total['outcomes'][ $row->outcome ];
	}

	uasort( $terms, function ( $a, $b ) {
		return $b['searches'] - $a['searches'];
	} );

	$terms = array_slice( $terms, 0, turf_list_max() );
	$cell  = function ( $count, $searches ) {
		return (int) round( $count / max( 1, $searches ) * 100 ) . '%';
	};
	?>
	<p class="description">
		<?php esc_html_e( 'What visitors did within 30 minutes after a search: opened a post or page, searched for something else, went to another page, or left the site.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped" data-turf-visible="10">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Search term', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times searched', 'turf-stats' ); ?></th>
				<?php foreach ( $labels as $label ) : ?>
					<th><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'All searches', 'turf-stats' ); ?></strong></td>
				<td><strong><?php echo esc_html( number_format_i18n( $total['searches'] ) ); ?></strong></td>
				<?php foreach ( $total['outcomes'] as $count ) : ?>
					<td><strong><?php echo esc_html( $cell( $count, $total['searches'] ) ); ?></strong></td>
				<?php endforeach; ?>
			</tr>
			<?php foreach ( $terms as $term ) : ?>
				<tr>
					<td><?php echo esc_html( $term['label'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $term['searches'] ) ); ?></td>
					<?php foreach ( $term['outcomes'] as $count ) : ?>
						<td><?php echo esc_html( $cell( $count, $term['searches'] ) ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * "Searched for X, then for Y" pairs - the vocabulary gap between what
 * visitors type and what the site calls it; good candidates for synonyms,
 * redirects or better titles.
 */
function turf_search_render_refinements( $days ) {
	$pairs = array();

	foreach ( turf_search_get_outcomes( $days ) as $row ) {
		if ( 'refined' !== $row->outcome ) {
			continue;
		}

		$key = turf_search_term_key( $row->search_term ) . "\0" . turf_search_term_key( $row->next_term );

		if ( ! isset( $pairs[ $key ] ) ) {
			$pairs[ $key ] = array( 'from' => $row->search_term, 'to' => $row->next_term, 'count' => 0 );
		}

		++$pairs[ $key ]['count'];
	}

	if ( ! $pairs ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}

	uasort( $pairs, function ( $a, $b ) {
		return $b['count'] - $a['count'];
	} );
	?>
	<p class="description">
		<?php esc_html_e( 'Searches followed by a search for something else - candidates for synonyms, redirects or clearer titles.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped turf-table--label-label-num" data-turf-visible="10">
		<thead>
			<tr>
				<th><?php esc_html_e( 'First searched for', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Then searched for', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_slice( $pairs, 0, turf_list_max() ) as $pair ) : ?>
				<tr>
					<td><?php echo esc_html( $pair['from'] ); ?></td>
					<td><?php echo esc_html( $pair['to'] ); ?></td>
					<td><?php echo (int) $pair['count']; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Which post/page visitors opened after searching for a term.
 */
function turf_search_render_clicked_results( $days ) {
	$pairs = array();

	foreach ( turf_search_get_outcomes( $days ) as $row ) {
		if ( 'clicked' !== $row->outcome ) {
			continue;
		}

		$key = turf_search_term_key( $row->search_term ) . "\0" . $row->next_post_id;

		if ( ! isset( $pairs[ $key ] ) ) {
			$pairs[ $key ] = array( 'term' => $row->search_term, 'post_id' => (int) $row->next_post_id, 'count' => 0 );
		}

		++$pairs[ $key ]['count'];
	}

	if ( ! $pairs ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}

	uasort( $pairs, function ( $a, $b ) {
		return $b['count'] - $a['count'];
	} );
	?>
	<table class="wp-list-table widefat fixed striped turf-table--label-label-num" data-turf-visible="10">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Search term', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Opened', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_slice( $pairs, 0, turf_list_max() ) as $pair ) : ?>
				<tr>
					<td><?php echo esc_html( $pair['term'] ); ?></td>
					<td>
						<?php if ( get_post( $pair['post_id'] ) ) : ?>
							<a href="<?php echo esc_url( get_permalink( $pair['post_id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $pair['post_id'] ) ); ?></a>
						<?php else : ?>
							<span class="description">&mdash;</span>
						<?php endif; ?>
					</td>
					<td><?php echo (int) $pair['count']; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
