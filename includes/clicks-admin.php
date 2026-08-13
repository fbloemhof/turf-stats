<?php
/**
 * "Clicks" submenu page - top clicked data-turf-click keys per period, plus an
 * outbound-links breakdown. Lists cap at turf_list_max() and collapse behind
 * the shared "Show more" toggle (js/postbox-more.js).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_clicks_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Clicks', 'turf-stats' ),
		__( 'Clicks', 'turf-stats' ),
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

	add_meta_box( 'turf_clicks_top', __( 'Top clicks', 'turf-stats' ), function () use ( $days ) {
		turf_clicks_render_top_keys( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_clicks_outbound', __( 'Outbound links', 'turf-stats' ), function () use ( $days ) {
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
function turf_clicks_get_top_keys( $days ) {
	global $wpdb;
	$table = turf_clicks_table();

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT click_key, COUNT(*) AS clicks FROM $table
			WHERE click_key != %s
			GROUP BY click_key ORDER BY clicks DESC LIMIT %d",
			TURF_OUTBOUND_CLICK_KEY,
			turf_list_max()
		) );
	}

	list( $where_sql, $date_params ) = turf_period_where_sql( $days, 'clicked_at' );

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT click_key, COUNT(*) AS clicks FROM $table
		WHERE click_key != %s $where_sql
		GROUP BY click_key ORDER BY clicks DESC LIMIT %d",
		array_merge( array( TURF_OUTBOUND_CLICK_KEY ), $date_params, array( turf_list_max() ) )
	) );
}

/**
 * Top outbound links, broken down by both destination URL and the page the
 * visitor was on when they clicked (context) - so the same external link
 * clicked from two different articles shows as two rows, which is usually the
 * more useful signal than a single lumped total.
 */
function turf_clicks_get_top_outbound_links( $days ) {
	global $wpdb;
	$table = turf_clicks_table();

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT target_url, context, COUNT(*) AS clicks FROM $table
			WHERE click_key = %s
			GROUP BY target_url, context ORDER BY clicks DESC LIMIT %d",
			TURF_OUTBOUND_CLICK_KEY,
			turf_list_max()
		) );
	}

	list( $where_sql, $date_params ) = turf_period_where_sql( $days, 'clicked_at' );

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT target_url, context, COUNT(*) AS clicks FROM $table
		WHERE click_key = %s $where_sql
		GROUP BY target_url, context ORDER BY clicks DESC LIMIT %d",
		array_merge( array( TURF_OUTBOUND_CLICK_KEY ), $date_params, array( turf_list_max() ) )
	) );
}

function turf_clicks_render_top_keys( $days ) {
	$rows = turf_clicks_get_top_keys( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No clicks recorded yet for this period.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Element', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'turf-stats' ); ?></th>
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
	<?php
}

function turf_clicks_render_top_outbound_links( $days ) {
	$rows = turf_clicks_get_top_outbound_links( $days );

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No outbound link clicks recorded yet for this period.', 'turf-stats' ) . '</p>';
		return;
	}
	?>
	<p class="description">
		<?php esc_html_e( 'Clicks on links to other websites - detected automatically, no data-turf-click needed.', 'turf-stats' ); ?>
	</p>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Destination', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'From page', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Clicks', 'turf-stats' ); ?></th>
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
	<?php
}

function turf_clicks_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Clicks', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'How often specific UI elements (e.g. view buttons, filters, social icons) are actually clicked - separate from page views.', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-clicks' ) ); ?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}
