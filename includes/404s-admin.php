<?php
/**
 * "404's" submenu page - top requested-but-missing paths per period.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_404s_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( '404s', 'turf-stats' ),
		__( '404s', 'turf-stats' ),
		'manage_options',
		'turf-404s',
		'turf_404s_render_admin_page'
	);

	add_action( "load-$hook", 'turf_404s_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_404s_admin_menu' );

function turf_404s_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	add_meta_box( 'turf_404s_top', __( 'Most-hit URLs', 'turf-stats' ), function () use ( $days ) {
		turf_404s_render_top_paths( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_404s_broken_links', __( 'Broken internal links', 'turf-stats' ), function () use ( $days ) {
		turf_404s_render_broken_links( $days );
	}, $hook, 'normal' );
}

/**
 * Whether the 404 list is narrowed to hits that look human (?who=human).
 */
function turf_404s_humans_only() {
	return isset( $_GET['who'] ) && 'human' === sanitize_key( $_GET['who'] );
}

/**
 * SQL expression (1/0) for "this 404 hit was most likely a person": either the
 * request itself looked like a browser (turf_404s_looks_human()), or the same
 * visitor_hash ran the tracking JS on a normal page within a day of the hit -
 * bots that fire the JS are the exception, so that's the strongest signal
 * available without any extra tracking. Rows from before the 404 table had
 * these columns have neither signal and count as not human.
 */
function turf_404s_human_sql( $alias = 'f' ) {
	$views = turf_table();

	return "( $alias.likely_human = 1 OR ( $alias.visitor_hash != '' AND EXISTS (
		SELECT 1 FROM $views hv
		WHERE hv.visitor_hash = $alias.visitor_hash AND hv.suspect = 0
		AND hv.viewed_at BETWEEN $alias.hit_at - INTERVAL 1 DAY AND $alias.hit_at + INTERVAL 1 DAY
	) ) )";
}

/**
 * Initial rows shown before the "Show more" toggle on the 404s list -
 * deliberately higher than the default 5 (404-hunting wants a longer list at a
 * glance), passed to the list via data-turf-visible below.
 */
define( 'TURF_404S_VISIBLE', 20 );

/**
 * Top 404 URLs - path plus parameter names, so e.g. "/" and
 * "/?status&transaction_id" (a payment return) are separate rows - with how
 * many of the hits look human. $humans_only drops rows without any human hit
 * and ranks by human hits instead of total hits.
 */
function turf_404s_get_top_paths( $days, $humans_only = false ) {
	return turf_stats_cached( array( '404s_top_paths', $days, $humans_only ), function () use ( $days, $humans_only ) {
		global $wpdb;
		$table = turf_404s_table();
		$human = turf_404s_human_sql( 'f' );

		list( $where_sql, $date_params ) = turf_period_where_sql( $days, 'f.hit_at' );

		$having   = $humans_only ? 'HAVING humans > 0' : '';
		$order_by = $humans_only ? 'humans DESC, hits DESC' : 'hits DESC';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT f.path, f.query_keys, COUNT(*) AS hits, SUM( $human ) AS humans, MAX(f.hit_at) AS last_hit
			FROM $table f
			WHERE 1=1 $where_sql
			GROUP BY f.path, f.query_keys
			$having
			ORDER BY $order_by
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $human/$having/$order_by are built from literals only.
			array_merge( $date_params, array( turf_list_max() ) )
		) );
	} );
}

/**
 * Internal pages that link to a missing URL - the referrer_path column is only
 * filled for the site's own pages, so every row here is a link that can be
 * fixed on this site.
 */
function turf_404s_get_broken_links( $days ) {
	return turf_stats_cached( array( '404s_broken_links', $days ), function () use ( $days ) {
		global $wpdb;
		$table = turf_404s_table();

		list( $where_sql, $date_params ) = turf_period_where_sql( $days, 'hit_at' );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT referrer_path, path, query_keys, COUNT(*) AS hits, MAX(hit_at) AS last_hit
			FROM $table
			WHERE referrer_path != '' $where_sql
			GROUP BY referrer_path, path, query_keys
			ORDER BY hits DESC
			LIMIT %d",
			array_merge( $date_params, array( turf_list_max() ) )
		) );
	} );
}

/**
 * Path plus its recorded parameter names, as shown in the 404 lists
 * ("/?status&transaction_id").
 */
function turf_404s_format_url( $row ) {
	return '' === (string) $row->query_keys ? $row->path : $row->path . '?' . $row->query_keys;
}

function turf_404s_render_who_toggle() {
	$humans_only = turf_404s_humans_only();
	$links       = array(
		''      => __( 'All hits', 'turf-stats' ),
		'human' => __( 'Humans only', 'turf-stats' ),
	);

	echo '<ul class="subsubsub">';
	$i = 0;
	foreach ( $links as $who => $label ) {
		$url     = '' === $who ? remove_query_arg( 'who' ) : add_query_arg( 'who', $who );
		$current = ( 'human' === $who ) === $humans_only;
		echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( $url ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul><br class="clear">';
}

function turf_404s_render_top_paths( $days ) {
	$humans_only = turf_404s_humans_only();
	$rows        = turf_404s_get_top_paths( $days, $humans_only );

	turf_404s_render_who_toggle();

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No 404s recorded for this period.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Parameter names are shown, never their values. "Humans" counts hits from a browser-like request or from a visitor who also viewed a normal page; 404s recorded before this was tracked count as not human.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped" data-turf-visible="<?php echo (int) TURF_404S_VISIBLE; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Path', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times hit', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Humans', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Last', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( turf_404s_format_url( $row ) ); ?></code></td>
					<td><?php echo (int) $row->hits; ?></td>
					<td><?php echo (int) $row->humans; ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $row->last_hit, 'd-m-Y H:i' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	turf_render_export_link( 'turf_export_404s', $humans_only ? array( 'who' => 'human' ) : array() );
}

function turf_404s_render_broken_links( $days ) {
	$rows = turf_404s_get_broken_links( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No broken internal links recorded for this period.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Pages on this site that link to a missing URL - fix the link on the "From page".', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped turf-table--label-label-num" data-turf-visible="<?php echo (int) TURF_404S_VISIBLE; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'From page', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Missing URL', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Times hit', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( home_url( $row->referrer_path ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->referrer_path ); ?></a></td>
					<td><code><?php echo esc_html( turf_404s_format_url( $row ) ); ?></code></td>
					<td><?php echo (int) $row->hits; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function turf_404s_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( '404s', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Which non-existent URLs visitors hit - handy for finding and fixing broken links.', 'turf-stats' ); ?>
		</p>

		<?php
		// Keep the humans-only filter when switching periods.
		$base_url = admin_url( 'admin.php?page=turf-404s' );
		turf_render_period_tabs( turf_404s_humans_only() ? add_query_arg( 'who', 'human', $base_url ) : $base_url );
		?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}
