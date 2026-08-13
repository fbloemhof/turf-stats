<?php
/**
 * Per-user saved date ranges - quick return to a previously-picked custom
 * range (e.g. "Q1 2026", "Black Friday week") without re-picking the dates
 * each time. Stored in user meta since these are a personal shortcut list,
 * not a site-wide setting - two admins on the same site keep their own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TURF_SAVED_RANGES_META_KEY', 'turf_saved_ranges' );

/**
 * Maximum saved ranges kept per user - the oldest is dropped once a new save
 * would exceed this. Filterable for sites that want more.
 */
function turf_saved_ranges_max() {
	return (int) apply_filters( 'turf_saved_ranges_max', 20 );
}

/**
 * @return array<int, array{id:string,name:string,start:string,end:string}>
 */
function turf_get_saved_ranges() {
	$ranges = get_user_meta( get_current_user_id(), TURF_SAVED_RANGES_META_KEY, true );

	if ( ! is_array( $ranges ) ) {
		return array();
	}

	// Defensive re-validation - user meta isn't guaranteed to only ever have
	// been written by turf_save_range() below.
	$valid = array();
	foreach ( $ranges as $range ) {
		if ( is_array( $range ) && ! empty( $range['id'] ) && ! empty( $range['name'] )
			&& turf_is_valid_ymd( $range['start'] ?? '' ) && turf_is_valid_ymd( $range['end'] ?? '' ) ) {
			$valid[] = array(
				'id'    => (string) $range['id'],
				'name'  => (string) $range['name'],
				'start' => (string) $range['start'],
				'end'   => (string) $range['end'],
			);
		}
	}

	return $valid;
}

/**
 * Validates and saves a named range for the current user. Saving the same
 * [start, end] pair again renames the existing entry rather than duplicating
 * it - there's no separate "rename" UI (an accepted v1 cut: delete + re-save
 * is an adequate workaround), so this is the only way a name changes.
 *
 * @return array|false The updated list of ranges, or false if $name/$start/$end
 *                      didn't validate.
 */
function turf_save_range( $name, $start, $end ) {
	$name = trim( sanitize_text_field( $name ) );

	if ( '' === $name || ! turf_is_valid_ymd( $start ) || ! turf_is_valid_ymd( $end ) || strtotime( $end ) < strtotime( $start ) ) {
		return false;
	}

	$ranges = turf_get_saved_ranges();

	foreach ( $ranges as $i => $range ) {
		if ( $range['start'] === $start && $range['end'] === $end ) {
			$ranges[ $i ]['name'] = $name;
			update_user_meta( get_current_user_id(), TURF_SAVED_RANGES_META_KEY, $ranges );

			return $ranges;
		}
	}

	$ranges[] = array(
		'id'    => wp_generate_uuid4(),
		'name'  => $name,
		'start' => $start,
		'end'   => $end,
	);

	$max = turf_saved_ranges_max();
	if ( count( $ranges ) > $max ) {
		$ranges = array_slice( $ranges, count( $ranges ) - $max );
	}

	update_user_meta( get_current_user_id(), TURF_SAVED_RANGES_META_KEY, $ranges );

	return $ranges;
}

function turf_delete_saved_range( $id ) {
	$ranges = turf_get_saved_ranges();
	$ranges = array_values( array_filter( $ranges, function ( $range ) use ( $id ) {
		return $range['id'] !== $id;
	} ) );

	update_user_meta( get_current_user_id(), TURF_SAVED_RANGES_META_KEY, $ranges );

	return $ranges;
}

function turf_ajax_save_range() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_saved_ranges' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$start = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
	$end   = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '';

	$ranges = turf_save_range( $name, $start, $end );

	if ( false === $ranges ) {
		wp_send_json_error( 'invalid', 400 );
	}

	wp_send_json_success( array( 'ranges' => $ranges ) );
}
add_action( 'wp_ajax_turf_save_range', 'turf_ajax_save_range' );

function turf_ajax_delete_saved_range() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_saved_ranges' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

	if ( '' === $id ) {
		wp_send_json_error( 'invalid', 400 );
	}

	wp_send_json_success( array( 'ranges' => turf_delete_saved_range( $id ) ) );
}
add_action( 'wp_ajax_turf_delete_saved_range', 'turf_ajax_delete_saved_range' );

/**
 * Renders the saved-ranges <select> (jump) + "Save this range" control, next
 * to the period tabs. Called from turf_render_period_tabs(); a no-op when
 * there's nothing to show (no saved ranges yet, and not currently viewing a
 * valid custom range to offer saving).
 *
 * @param string $base_url    Admin page URL (query args stripped), same $base_url
 *                             turf_render_period_tabs() itself received.
 * @param string $period      Currently-resolved `period` URL param.
 * @param string $range_start Current `range_start` (already validated), '' if none.
 * @param string $range_end   Current `range_end` (already validated), '' if none.
 */
function turf_render_saved_ranges_ui( $base_url, $period, $range_start, $range_end ) {
	$ranges     = turf_get_saved_ranges();
	$can_save   = ( 'custom' === $period && $range_start && $range_end );

	if ( ! $ranges && ! $can_save ) {
		return;
	}
	?>
	<div class="turf-saved-ranges" id="turf-saved-ranges"
		data-base-url="<?php echo esc_url( $base_url ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'turf_saved_ranges' ) ); ?>"
		data-current-start="<?php echo esc_attr( $range_start ); ?>"
		data-current-end="<?php echo esc_attr( $range_end ); ?>">
		<?php if ( $ranges ) : ?>
			<select id="turf-saved-ranges-select">
				<option value=""><?php esc_html_e( 'Saved ranges…', 'turf-stats' ); ?></option>
				<?php foreach ( $ranges as $range ) : ?>
					<option
						value="<?php echo esc_attr( $range['id'] ); ?>"
						data-start="<?php echo esc_attr( $range['start'] ); ?>"
						data-end="<?php echo esc_attr( $range['end'] ); ?>"
						<?php selected( $range['start'] === $range_start && $range['end'] === $range_end ); ?>
					>
						<?php echo esc_html( $range['name'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<?php if ( $can_save ) : ?>
			<button type="button" class="button button-small" id="turf-save-range"><?php esc_html_e( 'Save this range', 'turf-stats' ); ?></button>
		<?php endif; ?>

		<?php if ( $ranges ) : ?>
			<ul class="turf-saved-range-manage">
				<?php foreach ( $ranges as $range ) : ?>
					<li data-id="<?php echo esc_attr( $range['id'] ); ?>">
						<?php echo esc_html( $range['name'] ); ?>
						<button type="button" class="turf-saved-range-delete" data-id="<?php echo esc_attr( $range['id'] ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'turf-stats' ); ?>">&times;</button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
