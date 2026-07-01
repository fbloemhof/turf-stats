<?php
/**
 * "Klikken" submenu page - top clicked data-turf-click keys per period.
 * Reuses turf_render_pagination() from views-admin.php.
 */

function turf_clicks_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Klikken', 'turf-stats' ),
		__( 'Klikken', 'turf-stats' ),
		'manage_options',
		'turf-clicks',
		'turf_clicks_render_admin_page'
	);

	add_action( "load-$hook", 'turf_clicks_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_clicks_admin_menu' );

function turf_clicks_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	add_meta_box( 'turf_clicks_top', __( 'Top kliks', 'turf-stats' ), function () use ( $days ) {
		turf_clicks_render_top_keys( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_clicks_outbound', __( 'Uitgaande links', 'turf-stats' ), function () use ( $days ) {
		turf_clicks_render_top_outbound_links( $days );
	}, $hook, 'normal' );
}

/**
 * Excludes TURF_OUTBOUND_CLICK_KEY - outbound link clicks all share that one
 * key (the destination is in target_url instead), so lumping it in here
 * would just show one big, undifferentiated "outbound-link" row. They get
 * their own breakdown by destination host instead - see
 * turf_clicks_get_top_outbound_links() below.
 */
function turf_clicks_count_keys( $days ) {
	global $wpdb;
	$table = turf_clicks_table();

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT click_key) FROM $table WHERE click_key != %s",
			TURF_OUTBOUND_CLICK_KEY
		) );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT click_key) FROM $table WHERE click_key != %s AND clicked_at >= %s",
		TURF_OUTBOUND_CLICK_KEY,
		turf_period_start_sql_date( $days )
	) );
}

function turf_clicks_get_top_keys( $days, $page = 1 ) {
	global $wpdb;
	$table  = turf_clicks_table();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT click_key, COUNT(*) AS clicks FROM $table
			WHERE click_key != %s
			GROUP BY click_key ORDER BY clicks DESC LIMIT %d OFFSET %d",
			TURF_OUTBOUND_CLICK_KEY,
			TURF_PER_PAGE,
			$offset
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT click_key, COUNT(*) AS clicks FROM $table
		WHERE click_key != %s AND clicked_at >= %s
		GROUP BY click_key ORDER BY clicks DESC LIMIT %d OFFSET %d",
		TURF_OUTBOUND_CLICK_KEY,
		turf_period_start_sql_date( $days ),
		TURF_PER_PAGE,
		$offset
	) );
}

/**
 * Counts distinct destination-URL + source-page pairs (that's one table row),
 * so pagination lines up with what turf_clicks_get_top_outbound_links() below
 * actually lists.
 */
function turf_clicks_count_outbound_hosts( $days ) {
	global $wpdb;
	$table = turf_clicks_table();

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT target_url, context) FROM $table WHERE click_key = %s",
			TURF_OUTBOUND_CLICK_KEY
		) );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT target_url, context) FROM $table WHERE click_key = %s AND clicked_at >= %s",
		TURF_OUTBOUND_CLICK_KEY,
		turf_period_start_sql_date( $days )
	) );
}

/**
 * Top outbound links, broken down by both destination URL and the page the
 * visitor was on when they clicked (context) - so the same external link
 * clicked from two different articles shows as two rows, which is usually the
 * more useful signal than a single lumped total.
 */
function turf_clicks_get_top_outbound_links( $days, $page = 1 ) {
	global $wpdb;
	$table  = turf_clicks_table();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT target_url, context, COUNT(*) AS clicks FROM $table
			WHERE click_key = %s
			GROUP BY target_url, context ORDER BY clicks DESC LIMIT %d OFFSET %d",
			TURF_OUTBOUND_CLICK_KEY,
			TURF_PER_PAGE,
			$offset
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT target_url, context, COUNT(*) AS clicks FROM $table
		WHERE click_key = %s AND clicked_at >= %s
		GROUP BY target_url, context ORDER BY clicks DESC LIMIT %d OFFSET %d",
		TURF_OUTBOUND_CLICK_KEY,
		turf_period_start_sql_date( $days ),
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_clicks_render_top_keys( $days ) {
	$param          = 'pg';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;
	$total          = turf_clicks_count_keys( $days );
	$total_pages    = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page           = min( $requested_page, $total_pages );
	$rows           = $total ? turf_clicks_get_top_keys( $days, $page ) : array();

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Nog geen kliks geregistreerd voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
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
	<?php
}

/**
 * Own pagination param ('pg_outbound', not 'pg') so paging through this
 * box doesn't also move the separate "Top kliks" box to the same page
 * number.
 */
function turf_clicks_render_top_outbound_links( $days ) {
	$param          = 'pg_outbound';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;
	$total          = turf_clicks_count_outbound_hosts( $days );
	$total_pages    = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page           = min( $requested_page, $total_pages );
	$rows           = $total ? turf_clicks_get_top_outbound_links( $days, $page ) : array();

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Nog geen klikken op uitgaande links geregistreerd voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Klikken op links naar andere websites - automatisch herkend, geen data-turf-click nodig.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Doel', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Vanaf pagina', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Kliks', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $rows as $row ) :
				$is_url    = (bool) preg_match( '#^https?://#i', (string) $row->target_url );
				$from_path = (string) $row->context;
				$from_url  = ( '' !== $from_path ) ? home_url( $from_path ) : '';
				?>
				<tr>
					<td>
						<?php if ( $is_url ) : ?>
							<a href="<?php echo esc_url( $row->target_url ); ?>" target="_blank" rel="noopener noreferrer nofollow"><code><?php echo esc_html( $row->target_url ); ?></code></a>
						<?php else : ?>
							<code><?php echo esc_html( $row->target_url ); ?></code>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( '' === $from_path ) : ?>
							<span class="description">&mdash;</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $from_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $from_path ); ?></a>
						<?php endif; ?>
					</td>
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
	<?php
}

function turf_clicks_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Klikken', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Hoe vaak specifieke UI-elementen (bv. weergave-knoppen, filters, social-iconen) daadwerkelijk worden aangeklikt - los van paginaweergaven.', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-clicks' ) ); ?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}
