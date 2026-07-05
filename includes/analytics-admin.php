<?php
/**
 * Renderers for the read-only analytics in includes/analytics.php -
 * "Piekuren" and "Trending" metaboxes on the Statistieken page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_render_peak_hours( $days ) {
	$grid = turf_get_peak_hours( $days );
	$max  = 1;

	foreach ( $grid as $row ) {
		$max = max( $max, max( $row ) );
	}

	$weekdays = array(
		__( 'Mon', 'turf-stats' ),
		__( 'Tue', 'turf-stats' ),
		__( 'Wed', 'turf-stats' ),
		__( 'Thu', 'turf-stats' ),
		__( 'Fri', 'turf-stats' ),
		__( 'Sat', 'turf-stats' ),
		__( 'Sun', 'turf-stats' ),
	);
	?>
	<table class="bk-stats-heatmap">
		<thead>
			<tr>
				<th></th>
				<?php for ( $hour = 0; $hour < 24; $hour++ ) : ?>
					<th><?php echo ( 0 === $hour % 3 ) ? esc_html( $hour ) : ''; ?></th>
				<?php endfor; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $weekdays as $weekday_index => $weekday_label ) : ?>
				<tr>
					<th><?php echo esc_html( $weekday_label ); ?></th>
					<?php foreach ( $grid[ $weekday_index ] as $hour => $views ) : ?>
						<?php
						$intensity = $max ? $views / $max : 0;
						$title     = sprintf(
							/* translators: 1: weekday, 2: hour, 3: number of views */
							__( '%1$s %2$d:00 — %3$s views', 'turf-stats' ),
							$weekday_label,
							$hour,
							number_format_i18n( $views )
						);
						?>
						<td title="<?php echo esc_attr( $title ); ?>" style="background-color:color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) <?php echo (int) round( $intensity * 80 ); ?>%, #fff);"></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function turf_render_trending() {
	$rows = turf_get_trending();

	if ( ! $rows ) {
		return; // No output, so turf_maybe_add_meta_box() drops the box.
	}
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Views (24h)', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Change', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				if ( 'post' === $row['type'] ) {
					$title = get_the_title( $row['id'] );
					$link  = get_edit_post_link( $row['id'] );
				} else {
					$term  = get_term( $row['id'] );
					$title = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
					$link  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : false;
					$link  = ( $link && ! is_wp_error( $link ) ) ? $link : false;
				}

				$title  = $title ?: ( '#' . $row['id'] );
				$change = turf_pct_change( $row['recent'], $row['previous'] );
				?>
				<tr>
					<td><?php echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ); ?></td>
					<td><?php echo (int) $row['recent']; ?></td>
					<td><?php turf_render_change_badge( $change ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
