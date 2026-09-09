<?php
/**
 * "Analyse" submenu page - the deeper, more diagnostic stats (search
 * behaviour, navigation routes, trends, conversions) kept separate from
 * the main Statistieken page so that page stays focused on the core
 * audience picture (who, how many, when). Peak hours stays on Statistieken
 * (it's about *when*, same as the rest of that page) - everything else
 * added in v1.8.0/v1.9.0 lives here instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_analyse_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Analysis', 'turf-stats' ),
		__( 'Analysis', 'turf-stats' ),
		'manage_options',
		'turf-analyse',
		'turf_analyse_render_admin_page'
	);

	add_action( "load-$hook", 'turf_analyse_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_analyse_admin_menu' );

function turf_analyse_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	turf_maybe_add_meta_box( 'turf_load_time_trend', __( 'Load time trend', 'turf-stats' ), function () use ( $days ) {
		turf_render_trend_chart( turf_get_daily_load_time_series( $days ), 'turf_format_load_time' );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_time_per_visit_trend', __( 'Time/visit trend', 'turf-stats' ), function () use ( $days ) {
		turf_render_trend_chart( turf_get_daily_session_duration_series( $days ), 'turf_format_duration' );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_landing_pages', __( 'Landing pages', 'turf-stats' ), function () use ( $days ) {
		turf_render_landing_pages( $days );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_search_terms', __( 'Search terms', 'turf-stats' ), function () use ( $days ) {
		turf_search_render_top_terms( $days );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_search_zero_results', __( 'Zero-result searches', 'turf-stats' ), function () use ( $days ) {
		turf_search_render_zero_results( $days );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_session_routes', __( 'Visitor routes', 'turf-stats' ), function () use ( $days ) {
		turf_render_session_routes( $days );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_trending', __( 'Trending', 'turf-stats' ), function () {
		turf_render_trending();
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_authors', __( 'By author', 'turf-stats' ), function () use ( $days ) {
		turf_render_author_breakdown( $days );
	}, $hook, 'normal' );

	turf_maybe_add_meta_box( 'turf_forms', __( 'Forms', 'turf-stats' ), function () use ( $days ) {
		turf_forms_render_top_forms( $days );
	}, $hook, 'normal' );

	if ( turf_woo_active() ) {
		turf_maybe_add_meta_box( 'turf_woo_funnel', __( 'WooCommerce funnel', 'turf-stats' ), function () use ( $days ) {
			turf_woo_render_funnel( $days );
		}, $hook, 'normal' );
	}
}

function turf_analyse_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Analysis', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Deeper analysis alongside the headline numbers on Statistics: search behavior, navigation routes, trends and conversions.', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-analyse' ) ); ?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}

/**
 * Inline SVG line chart for a single daily metric (load time, time/visit) -
 * same pure-markup approach as turf_render_hourly_visitors_chart() in
 * views-admin.php (viewBox-scaled, non-scaling strokes, <title> tooltips, no
 * chart library/JS), but for a Y-m-d date series instead of a fixed 0-23 hour
 * axis, and min/max-scaled instead of zero-based (a load time/duration trend
 * has no meaningful zero floor to anchor to).
 *
 * @param array[]  $series       turf_get_daily_load_time_series() /
 *                                turf_get_daily_session_duration_series()'s
 *                                return shape: array('date' => 'Y-m-d',
 *                                'value' => int|null), oldest first.
 * @param callable $format_value Formats one raw value for its tooltip/axis
 *                                label (turf_format_load_time() /
 *                                turf_format_duration()).
 */
function turf_render_trend_chart( $series, $format_value ) {
	$values = array_filter( array_column( $series, 'value' ), function ( $v ) {
		return null !== $v;
	} );

	if ( ! $values ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}

	$min = min( $values );
	$max = max( $values );

	// A flat line (or a single real data point) needs artificial headroom, or
	// it would sit exactly on the plot's top/bottom edge with nothing to
	// distinguish "flat" from "no data".
	if ( $min === $max ) {
		$min = max( 0, $min - 1 );
		$max = $max + 1;
	}

	$count = count( $series );

	// viewBox coordinate space; CSS scales it to 100% width - same dimensions
	// as the hourly chart, so the two chart types read as one visual family.
	$w       = 720;
	$h       = 140;
	$pad_top = 10;
	$pad_bot = 18;
	$plot_h  = $h - $pad_top - $pad_bot;

	$points = array();
	foreach ( array_values( $series ) as $i => $day ) {
		if ( null === $day['value'] ) {
			$points[] = null;
			continue;
		}

		$points[] = array(
			'x'     => ( $count > 1 ) ? round( ( $i / ( $count - 1 ) ) * $w, 1 ) : $w / 2,
			'y'     => round( $pad_top + ( 1 - ( $day['value'] - $min ) / ( $max - $min ) ) * $plot_h, 1 ),
			'date'  => $day['date'],
			'value' => $day['value'],
		);
	}

	// Break the line at any gap (a day with no data) instead of connecting
	// across it - a missing day shouldn't visually pretend to interpolate.
	$segments = array();
	$current  = array();

	foreach ( $points as $point ) {
		if ( null === $point ) {
			if ( $current ) {
				$segments[] = $current;
				$current    = array();
			}
			continue;
		}

		$current[] = $point;
	}

	if ( $current ) {
		$segments[] = $current;
	}

	// Same density thresholds as turf_render_daily_chart() (views-admin.php) -
	// see there for why: custom ranges run up to a year, and labelling every
	// single day past ~45 of them just smears neighbouring labels together.
	if ( $count <= 14 ) {
		$label_every = 1;
	} elseif ( $count <= 45 ) {
		$label_every = 2;
	} elseif ( $count <= 120 ) {
		$label_every = 7;
	} elseif ( $count <= 200 ) {
		$label_every = 14;
	} else {
		$label_every = 30;
	}

	$baseline = $pad_top + $plot_h;
	?>
	<svg class="bk-trend-chart__svg" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" role="img" aria-label="<?php echo esc_attr( call_user_func( $format_value, $min ) . ' – ' . call_user_func( $format_value, $max ) ); ?>">
		<line class="bk-trend-chart__grid" x1="0" y1="<?php echo esc_attr( $baseline ); ?>" x2="<?php echo (int) $w; ?>" y2="<?php echo esc_attr( $baseline ); ?>" vector-effect="non-scaling-stroke" />

		<text class="bk-trend-chart__axis bk-trend-chart__axis--y" x="4" y="<?php echo (int) ( $pad_top + 9 ); ?>"><?php echo esc_html( call_user_func( $format_value, $max ) ); ?></text>
		<text class="bk-trend-chart__axis bk-trend-chart__axis--y" x="4" y="<?php echo (int) ( $baseline - 4 ); ?>"><?php echo esc_html( call_user_func( $format_value, $min ) ); ?></text>

		<?php foreach ( $segments as $segment ) : ?>
			<?php
			$path = '';
			foreach ( $segment as $i => $p ) {
				$path .= ( 0 === $i ? 'M' : 'L' ) . $p['x'] . ' ' . $p['y'] . ' ';
			}
			$path = trim( $path );
			?>
			<path class="bk-trend-chart__line" d="<?php echo esc_attr( $path ); ?>" vector-effect="non-scaling-stroke" />
			<?php foreach ( $segment as $p ) : ?>
				<circle class="bk-trend-chart__dot" cx="<?php echo esc_attr( $p['x'] ); ?>" cy="<?php echo esc_attr( $p['y'] ); ?>" r="1.5" vector-effect="non-scaling-stroke">
					<title>
						<?php
						printf(
							/* translators: 1: date, 2: already-formatted value (e.g. "120ms", "2m 45s") */
							esc_html__( '%1$s — %2$s', 'turf-stats' ),
							esc_html( date_i18n( 'j M', strtotime( $p['date'] ) ) ),
							esc_html( call_user_func( $format_value, $p['value'] ) )
						);
						?>
					</title>
				</circle>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php foreach ( array_values( $series ) as $i => $day ) : ?>
			<?php
			if ( 0 !== ( $count - 1 - $i ) % $label_every ) {
				continue;
			}

			$x      = ( $count > 1 ) ? round( ( $i / ( $count - 1 ) ) * $w, 1 ) : $w / 2;
			$anchor = ( 0 === $i ) ? 'start' : ( ( $count - 1 === $i ) ? 'end' : 'middle' );
			?>
			<text class="bk-trend-chart__axis" x="<?php echo esc_attr( $x ); ?>" y="<?php echo (int) $h; ?>" text-anchor="<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( date_i18n( 'd M', strtotime( $day['date'] ) ) ); ?></text>
		<?php endforeach; ?>
	</svg>
	<?php
}
