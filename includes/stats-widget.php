<?php
/**
 * `[turf_stats]` shortcode - a front-end "at a glance" table (online now /
 * today / yesterday / last 7 days / last 28 days), for dropping into a
 * sidebar Text/HTML widget or Shortcode block. Reuses the exact same data
 * functions as the wp-admin dashboard widget (dashboard.php) and the
 * "Statistieken" overview page.
 *
 * Default layout is a zebra-striped <table> with a right-aligned value
 * column - legible with zero theme support, unlike an unstyled list.
 * `layout="list"` switches to a <ul> for themes that want to style it
 * themselves. Either way a small always-on inline stylesheet is registered
 * via a dummy handle (same pattern as turf_admin_inline_css()) so the
 * default table looks right out of the box; a theme's own CSS can still
 * override .turf-stats-widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_stats_widget_default_items() {
	return array( 'online', 'today', 'yesterday', 'week', 'month' );
}

/**
 * Resolves a single row's label + value for turf_stats_widget_shortcode().
 *
 * @param string $item   One of turf_stats_widget_default_items().
 * @param string $metric 'views' or 'visitors' (ignored for 'online').
 * @return array{0: string, 1: int}|null [ $label, $value ], or null for an unknown item.
 */
function turf_stats_widget_row( $item, $metric ) {
	switch ( $item ) {
		case 'online':
			return array( __( 'Online now', 'turf-stats' ), turf_get_online_now_count() );

		case 'today':
			$totals = turf_get_range_site_totals( TURF_PERIOD_TODAY, 0 );
			return array( __( 'Today', 'turf-stats' ), $totals[ $metric ] );

		case 'yesterday':
			$totals = turf_get_range_site_totals( TURF_PERIOD_YESTERDAY, 0 );
			return array( __( 'Yesterday', 'turf-stats' ), $totals[ $metric ] );

		case 'week':
			$totals = turf_get_range_site_totals( 7, 0 );
			return array( __( 'Last 7 days', 'turf-stats' ), $totals[ $metric ] );

		case 'month':
			$totals = turf_get_range_site_totals( 28, 0 );
			return array( __( 'Last 28 days', 'turf-stats' ), $totals[ $metric ] );

		default:
			return null;
	}
}

/**
 * [turf_stats metric="views|visitors" layout="table|list" items="online,today,yesterday,week,month"]
 */
function turf_stats_widget_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'metric' => 'views',
			'layout' => 'table',
			'items'  => implode( ',', turf_stats_widget_default_items() ),
		),
		$atts,
		'turf_stats'
	);

	$metric = 'visitors' === $atts['metric'] ? 'visitors' : 'views';
	$layout = 'list' === $atts['layout'] ? 'list' : 'table';
	$items  = array_filter( array_map( 'trim', explode( ',', $atts['items'] ) ) );

	$rows = array();
	foreach ( $items as $item ) {
		$row = turf_stats_widget_row( $item, $metric );
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}

	if ( ! $rows ) {
		return '';
	}

	$class = 'turf-stats-widget turf-stats-widget--' . $layout;

	ob_start();
	if ( 'list' === $layout ) :
		?>
		<ul class="<?php echo esc_attr( $class ); ?>">
			<?php foreach ( $rows as list( $label, $value ) ) : ?>
				<li class="turf-stats-widget__row">
					<span class="turf-stats-widget__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
					<span class="turf-stats-widget__label"><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	else :
		?>
		<table class="<?php echo esc_attr( $class ); ?>">
			<?php foreach ( $rows as list( $label, $value ) ) : ?>
				<tr class="turf-stats-widget__row">
					<td class="turf-stats-widget__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></td>
					<td class="turf-stats-widget__label"><?php echo esc_html( $label ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	endif;
	return ob_get_clean();
}
add_shortcode( 'turf_stats', 'turf_stats_widget_shortcode' );

function turf_stats_widget_inline_css() {
	return <<<'CSS'
		.turf-stats-widget--table { border-collapse: collapse; }
		.turf-stats-widget__row:nth-child(odd) { background: rgba(0, 0, 0, .035); }
		.turf-stats-widget--table .turf-stats-widget__value, .turf-stats-widget--table .turf-stats-widget__label { padding: 5px 8px; text-align: left; }
		.turf-stats-widget--table .turf-stats-widget__value { text-align: right; }
		.turf-stats-widget--list { list-style: none; margin: 0; padding: 0; }
		.turf-stats-widget--list .turf-stats-widget__row { display: flex; justify-content: space-between; gap: 8px; padding: 5px 8px; }
		.turf-stats-widget__value { font-weight: 600; white-space: nowrap; }
	CSS;
}

function turf_stats_widget_enqueue_style() {
	wp_register_style( 'turf-stats-widget', false, array(), TURF_VERSION );
	wp_enqueue_style( 'turf-stats-widget' );
	wp_add_inline_style( 'turf-stats-widget', turf_stats_widget_inline_css() );
}
add_action( 'wp_enqueue_scripts', 'turf_stats_widget_enqueue_style' );
