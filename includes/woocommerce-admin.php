<?php
/**
 * "WooCommerce-funnel" metabox - views -> add to cart -> checkout, only
 * registered when WooCommerce is active.
 */

function turf_woo_get_funnel( $days ) {
	global $wpdb;
	$woo_table = turf_woo_table();

	if ( 0 === $days ) {
		$views = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(m.meta_value + 0) FROM $wpdb->posts p
			INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = 'product' AND p.post_status = 'publish'",
			TURF_META_KEY
		) );
		$add_to_cart = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $woo_table WHERE event_type = 'add_to_cart'" );
		$checkout    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $woo_table WHERE event_type = 'checkout'" );
	} else {
		$since = turf_period_start_sql_date( $days );

		$views = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . turf_table() . " v
			INNER JOIN $wpdb->posts p ON p.ID = v.post_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			AND v.viewed_at >= %s",
			$since
		) );
		$add_to_cart = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $woo_table WHERE event_type = 'add_to_cart' AND occurred_at >= %s",
			$since
		) );
		$checkout = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $woo_table WHERE event_type = 'checkout' AND occurred_at >= %s",
			$since
		) );
	}

	return array( 'views' => $views, 'add_to_cart' => $add_to_cart, 'checkout' => $checkout );
}

function turf_woo_render_funnel( $days ) {
	$funnel = turf_woo_get_funnel( $days );
	$steps  = array(
		array( 'label' => __( 'Productweergaven', 'turf-stats' ), 'value' => $funnel['views'] ),
		array( 'label' => __( 'Toegevoegd aan winkelwagen', 'turf-stats' ), 'value' => $funnel['add_to_cart'] ),
		array( 'label' => __( 'Afgerond bij checkout', 'turf-stats' ), 'value' => $funnel['checkout'] ),
	);
	?>
	<div class="bk-stats-overview__totals">
		<?php foreach ( $steps as $i => $step ) : ?>
			<?php
			$pct = null;

			if ( $i > 0 && $steps[ $i - 1 ]['value'] > 0 ) {
				$pct = (int) round( ( $step['value'] / $steps[ $i - 1 ]['value'] ) * 100 );
			}
			?>
			<div class="bk-stats-box">
				<span class="bk-stats-box__label"><?php echo esc_html( $step['label'] ); ?></span>
				<span class="bk-stats-box__value"><?php echo esc_html( number_format_i18n( $step['value'] ) ); ?></span>
				<?php if ( null !== $pct ) : ?>
					<span class="bk-stats-box__change">
						<?php
						echo esc_html( sprintf(
							/* translators: %d: percentage of the previous funnel step */
							__( '%d%% van vorige stap', 'turf-stats' ),
							$pct
						) );
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
