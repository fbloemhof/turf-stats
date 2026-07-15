<?php
/**
 * At-a-glance Turf Stats widget on the WordPress dashboard (wp-admin/index.php).
 * Reuses the exact same stat-box markup and change badge as the Turf Stats
 * "Statistieken" overview page: people online now, today's views, today's
 * visitors - each (except the live "online now") with its % change versus
 * yesterday rendered by turf_render_change_badge(). A single "View all
 * statistics" link drops the user into the full page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_register_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'turf_dashboard_widget',
		__( 'Turf Stats', 'turf-stats' ),
		'turf_render_dashboard_widget'
	);
}
add_action( 'wp_dashboard_setup', 'turf_register_dashboard_widget' );

/**
 * The widget reuses the same .bk-stats-box markup / change badge as the Turf
 * Stats overview page, whose styling lives in turf_admin_inline_css(). That
 * CSS is normally only enqueued on the plugin's own admin pages, so we load
 * it here too - on the dashboard - by appending to the always-present
 * wp-admin stylesheet. Cheap (one inline <style>), and keeps the widget
 * visually identical to the overview cards.
 */
function turf_dashboard_enqueue( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}

	wp_add_inline_style( 'wp-admin', turf_admin_inline_css() );
}
add_action( 'admin_enqueue_scripts', 'turf_dashboard_enqueue' );

/**
 * Percentage change of $today versus $yesterday, as a signed number for
 * turf_render_change_badge(). Returns null when there is no meaningful
 * baseline (yesterday was 0) so the badge renders as "new" instead.
 *
 * @return int|null Signed percent, or null.
 */
function turf_pct_vs_yesterday( $today, $yesterday ) {
	$today     = (int) $today;
	$yesterday = (int) $yesterday;

	if ( 0 === $yesterday ) {
		return null;
	}

	return (int) round( ( ( $today - $yesterday ) / $yesterday ) * 100 );
}

function turf_render_dashboard_widget() {
	$now       = turf_get_online_now_count();
	$today     = turf_get_range_site_totals( TURF_PERIOD_TODAY, 0 );
	$yesterday = turf_get_range_site_totals( TURF_PERIOD_YESTERDAY, 0 );

	?>
	<div class="turf-dash">
		<div class="bk-stats-overview__totals">
			<?php turf_render_stat_box( __( 'Online now', 'turf-stats' ), $now, false ); ?>
			<?php turf_render_stat_box( __( 'Views today', 'turf-stats' ), $today['views'], turf_pct_vs_yesterday( $today['views'], $yesterday['views'] ) ); ?>
			<?php turf_render_stat_box( __( 'Visitors today', 'turf-stats' ), $today['visitors'], turf_pct_vs_yesterday( $today['visitors'], $yesterday['visitors'] ) ); ?>
		</div>

		<p class="turf-dash__more">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=turf-stats' ) ); ?>"><?php echo esc_html__( 'View all statistics', 'turf-stats' ); ?></a>
		</p>
	</div>
	<?php
}
