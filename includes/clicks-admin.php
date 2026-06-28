<?php
/**
 * "Klikken" submenu page - top clicked data-turf-click keys per period.
 * Reuses turf_render_pagination() from post-views-admin.php.
 */

function turf_clicks_admin_menu() {
	add_submenu_page(
		'turf-stats',
		__( 'Klikken', 'turf-stats' ),
		__( 'Klikken', 'turf-stats' ),
		'manage_options',
		'turf-clicks',
		'turf_clicks_render_admin_page'
	);
}
add_action( 'admin_menu', 'turf_clicks_admin_menu' );

function turf_clicks_count_keys( $days ) {
	global $wpdb;
	$table = turf_clicks_table();

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT click_key) FROM $table" );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT click_key) FROM $table WHERE clicked_at >= %s",
		gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) )
	) );
}

function turf_clicks_get_top_keys( $days, $page = 1 ) {
	global $wpdb;
	$table  = turf_clicks_table();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT click_key, COUNT(*) AS clicks FROM $table
			GROUP BY click_key ORDER BY clicks DESC LIMIT %d OFFSET %d",
			TURF_PER_PAGE,
			$offset
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT click_key, COUNT(*) AS clicks FROM $table
		WHERE clicked_at >= %s
		GROUP BY click_key ORDER BY clicks DESC LIMIT %d OFFSET %d",
		gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) ),
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_clicks_render_admin_page() {
	$period   = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7';
	$days_map = array( '7' => 7, '30' => 30, '90' => 90, 'all' => 0 );
	$days     = isset( $days_map[ $period ] ) ? $days_map[ $period ] : 7;
	$base_url = admin_url( 'admin.php?page=turf-clicks' );

	$param          = 'pg';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;
	$total          = turf_clicks_count_keys( $days );
	$total_pages    = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page           = min( $requested_page, $total_pages );
	$rows           = $total ? turf_clicks_get_top_keys( $days, $page ) : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Klikken', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Hoe vaak specifieke UI-elementen (bv. weergave-knoppen, filters, social-iconen) daadwerkelijk worden aangeklikt - los van paginaweergaven.', 'turf-stats' ); ?>
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
			<p><?php esc_html_e( 'Nog geen kliks geregistreerd voor deze periode.', 'turf-stats' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Element', 'turf-stats' ); ?></th>
						<th><?php esc_html_e( 'Kliks', 'turf-stats' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row->click_key ); ?></code></td>
							<td><?php echo (int) $row->clicks; ?></td>
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
