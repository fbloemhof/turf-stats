<?php
/**
 * "404's" submenu page - top requested-but-missing paths per period.
 */

function turf_404s_admin_menu() {
	add_submenu_page(
		'turf-stats',
		__( "404's", 'turf-stats' ),
		__( "404's", 'turf-stats' ),
		'manage_options',
		'turf-404s',
		'turf_404s_render_admin_page'
	);
}
add_action( 'admin_menu', 'turf_404s_admin_menu' );

function turf_404s_count_paths( $days ) {
	global $wpdb;
	$table = turf_404s_table();

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT path) FROM $table" );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT path) FROM $table WHERE hit_at >= %s",
		gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) )
	) );
}

function turf_404s_get_top_paths( $days, $page = 1 ) {
	global $wpdb;
	$table  = turf_404s_table();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT path, COUNT(*) AS hits, MAX(hit_at) AS last_hit FROM $table
			GROUP BY path ORDER BY hits DESC LIMIT %d OFFSET %d",
			TURF_PER_PAGE,
			$offset
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT path, COUNT(*) AS hits, MAX(hit_at) AS last_hit FROM $table
		WHERE hit_at >= %s
		GROUP BY path ORDER BY hits DESC LIMIT %d OFFSET %d",
		gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) ),
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_404s_render_admin_page() {
	$period   = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7';
	$days_map = array( '7' => 7, '30' => 30, '90' => 90, 'all' => 0 );
	$days     = isset( $days_map[ $period ] ) ? $days_map[ $period ] : 7;
	$base_url = admin_url( 'admin.php?page=turf-404s' );

	$param          = 'pg';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;
	$total          = turf_404s_count_paths( $days );
	$total_pages    = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page           = min( $requested_page, $total_pages );
	$rows           = $total ? turf_404s_get_top_paths( $days, $page ) : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( "404's", 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Welke niet-bestaande URL\'s bezoekers raken - handig om kapotte links te vinden en te fixen.', 'turf-stats' ); ?>
		</p>

		<ul class="subsubsub">
			<?php foreach ( array( '7' => '7 dagen', '30' => '30 dagen', '90' => '90 dagen', 'all' => 'Alles' ) as $key => $label ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'period', $key, $base_url ) ); ?>" <?php echo $period === (string) $key ? 'class="current"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a> |
				</li>
			<?php endforeach; ?>
		</ul>
		<br class="clear" />

		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Geen 404\'s geregistreerd voor deze periode.', 'turf-stats' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Pad', 'turf-stats' ); ?></th>
						<th><?php esc_html_e( 'Keer geraakt', 'turf-stats' ); ?></th>
						<th><?php esc_html_e( 'Laatst', 'turf-stats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row->path ); ?></code></td>
							<td><?php echo (int) $row->hits; ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $row->last_hit, 'd-m-Y H:i' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php turf_render_pagination( $param, $page, $total_pages ); ?>
				</div></div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}
