<?php
/**
 * "Online now" - a live count of distinct visitors active in the last few
 * minutes, like Clicky's "Online now". Uses the same view-event table and
 * visitor_hash already recorded for regular tracking - no new tracking
 * mechanism needed, just a query with a short time window plus an
 * auto-refreshing widget on the admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How recent a view has to be to count as "still online". Filterable.
 */
function turf_online_now_window() {
	return (int) apply_filters( 'turf_online_now_window', 5 * MINUTE_IN_SECONDS );
}

function turf_get_online_now_count() {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$since      = gmdate( 'Y-m-d H:i:s', time() - turf_online_now_window() );
	$params[]   = $since;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.visitor_hash) FROM $table v $join WHERE $where AND v.viewed_at >= %s",
		$params
	) );
}

/**
 * Which pages the still-online visitors (see turf_get_online_now_count())
 * are actually looking at right now, most-viewed first. post_id/term_id/
 * page_type are mutually exclusive per row (see includes/views.php), so
 * grouping by all three together groups by "the object", whichever kind it is.
 *
 * Only each visitor's single most recent view in the window counts (the
 * NOT EXISTS clause) - otherwise a visitor who browsed 4 pages in the last
 * few minutes would show up as "4 people online" spread across 4 rows,
 * even though turf_get_online_now_count() (rightly) counts them once. This
 * keeps the sum of `viewers` here consistent with that total: the page
 * they're on now, not everywhere they've been. To that end the subquery
 * mirrors the outer query exactly: the same trackability conditions (so a
 * newer view of a since-unpublished post can't suppress a visitor the count
 * still includes), the same window bound (which also lets it use the
 * visitor_lookup index instead of scanning the visitor's whole history),
 * and an id tie-break for two views landing in the same second.
 */
function turf_get_online_now_pages( $limit = 10 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params )             = turf_site_join_and_where();
	list( $later_join, $later_where, $later_params ) = turf_site_join_and_where( 'later' );

	$since  = gmdate( 'Y-m-d H:i:s', time() - turf_online_now_window() );
	$params = array_merge( $params, array( $since ), $later_params, array( $since, $limit ) );

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.post_id, v.term_id, v.page_type, COUNT(*) AS viewers
		FROM $table v
		$join
		WHERE $where AND v.viewed_at >= %s
		AND NOT EXISTS (
			SELECT 1 FROM $table later
			$later_join
			WHERE $later_where
			AND later.visitor_hash = v.visitor_hash
			AND (later.viewed_at > v.viewed_at OR (later.viewed_at = v.viewed_at AND later.id > v.id))
			AND later.viewed_at >= %s
		)
		GROUP BY v.post_id, v.term_id, v.page_type
		ORDER BY viewers DESC
		LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is own-prefix; join/where are internal literals from turf_site_join_and_where(); no user input.
		$params
	) );
}

/**
 * Resolves one turf_get_online_now_pages() row to a display label + edit
 * link, the same way turf_render_admin_table() (includes/views-admin.php)
 * does for the regular top-posts tables.
 *
 * @return array{label: string, edit_link: string|null}
 */
function turf_online_now_page_info( $row ) {
	if ( $row->post_id ) {
		$title = get_the_title( $row->post_id );

		return array(
			'label'     => $title ? $title : '#' . $row->post_id,
			'edit_link' => get_edit_post_link( $row->post_id ),
		);
	}

	if ( $row->term_id ) {
		$term = get_term( $row->term_id );

		return array(
			'label'     => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $row->term_id,
			'edit_link' => ( $term && ! is_wp_error( $term ) ) ? get_edit_term_link( $term ) : null,
		);
	}

	return array(
		'label'     => turf_other_page_type_label( $row->page_type ),
		'edit_link' => null,
	);
}

/**
 * The "Currently viewed" fragment - just the list markup, no wrapping
 * container. Shared by the initial page render (turf_render_online_now_pages())
 * and the 20s AJAX refresh (turf_ajax_online_now()), so both always agree.
 */
function turf_render_online_now_pages_list() {
	$rows = turf_get_online_now_pages();

	if ( ! $rows ) {
		echo '<p class="description">' . esc_html__( 'No pages currently being viewed.', 'turf-stats' ) . '</p>';
		return;
	}

	echo '<ol class="bk-stats-online-pages">';

	foreach ( $rows as $row ) {
		$info   = turf_online_now_page_info( $row );
		$viewers = (int) $row->viewers;
		/* translators: %s: number of visitors currently viewing this page */
		$viewers_text = sprintf( _n( '%s viewer', '%s viewers', $viewers, 'turf-stats' ), number_format_i18n( $viewers ) );
		?>
		<li>
			<span class="bk-stats-online-pages__label">
				<?php if ( $info['edit_link'] ) : ?>
					<a href="<?php echo esc_url( $info['edit_link'] ); ?>"><?php echo esc_html( $info['label'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $info['label'] ); ?>
				<?php endif; ?>
			</span>
			<span class="bk-stats-online-pages__count"><?php echo esc_html( $viewers_text ); ?></span>
		</li>
		<?php
	}

	echo '</ol>';
}

/**
 * "Currently viewed" meta box - the container + initial render. Unconditional
 * (not turf_maybe_add_meta_box()), same reasoning as "Online now" itself: a
 * live status box is still meaningful information when it's empty ("nobody's
 * looking at anything right now"), unlike the historical breakdown boxes that
 * hide entirely when there's no data for the period.
 */
function turf_render_online_now_pages() {
	echo '<div id="turf-online-now-pages">';
	turf_render_online_now_pages_list();
	echo '</div>';
}

/**
 * Same .bk-stats-box markup as the regular totals (Weergaven/Bezoekers/...),
 * so it sits in that row as a matching square box instead of a separately
 * styled badge. The pulsing dot next to the label is the only visual
 * difference, signalling "this one updates live" - id="turf-online-now-value"
 * is what js/online-now.js polls and rewrites.
 */
function turf_render_online_now() {
	?>
	<div class="bk-stats-box">
		<span class="bk-stats-box__label">
			<span class="bk-stats-online-now__dot"></span>
			<?php esc_html_e( 'Online now', 'turf-stats' ); ?>
		</span>
		<span class="bk-stats-box__value" id="turf-online-now-value"><?php echo esc_html( number_format_i18n( turf_get_online_now_count() ) ); ?></span>
	</div>
	<?php
}

function turf_online_now_enqueue( $hook ) {
	if ( 'toplevel_page_turf-stats' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'turf-online-now',
		TURF_URL . 'js/online-now.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-online-now', 'turfOnlineNow', array(
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'turf_online_now' ),
		'interval' => 20000, // ms
		'locale'   => get_bloginfo( 'language' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'turf_online_now_enqueue' );

function turf_ajax_online_now() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_online_now' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	ob_start();
	turf_render_online_now_pages_list();
	$pages_html = ob_get_clean();

	wp_send_json_success( array( 'count' => turf_get_online_now_count(), 'pages_html' => $pages_html ) );
}
add_action( 'wp_ajax_turf_online_now', 'turf_ajax_online_now' );
