<?php
/**
 * Subtle, dismissible "leave a review" nudge shown ~3 days after the plugin
 * is activated. Shown on all admin screens except the Turf Stats pages
 * themselves (those already showcase the plugin).
 *
 * Dismissal ("Not now") is a snooze, not a permanent kill switch: the nudge
 * reappears once after a random 30-90 day gap, up to 3 times. After the third
 * snooze it stays dismissed for good. A site that already has
 * turf_review_dismissed=1 (set before this logic existed, or post-3rd-snooze)
 * never sees it again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long after activation before the nudge appears. 3 days: late enough
 * that the site owner has actually used the plugin, early enough that the
 * "fresh install" goodwill is still warm.
 */
function turf_review_delay_seconds() {
	return (int) apply_filters( 'turf_review_delay_seconds', 3 * DAY_IN_SECONDS );
}

/**
 * How many times "Not now" can be clicked before the nudge is gone for good.
 */
function turf_review_max_snoozes() {
	return (int) apply_filters( 'turf_review_max_snoozes', 3 );
}

/**
 * Random snooze window (in seconds) between 30 and 90 days.
 */
function turf_review_snooze_seconds() {
	$min = (int) apply_filters( 'turf_review_snooze_min_days', 30 );
	$max = (int) apply_filters( 'turf_review_snooze_max_days', 90 );
	if ( $max < $min ) {
		$max = $min;
	}
	return (int) mt_rand( $min, $max ) * DAY_IN_SECONDS;
}

/**
 * Stamp the activation time on first load if it isn't set yet. Using
 * admin_init (not register_activation_hook alone) means existing installs
 * also get a sensible baseline rather than never showing the nudge.
 */
function turf_maybe_record_activation() {
	if ( false === get_option( 'turf_activated_at' ) ) {
		// Backdate to "now" - the nudge will surface 3 days from this first run.
		update_option( 'turf_activated_at', time() );
	}
}
add_action( 'admin_init', 'turf_maybe_record_activation' );

/**
 * Whether the nudge should render right now.
 */
function turf_should_show_review_nudge() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	// Permanently dismissed (pre-existing flag, or after the snooze budget is spent).
	if ( '1' === get_option( 'turf_review_dismissed' ) ) {
		return false;
	}

	// Snoozed: a future show-time is set and hasn't arrived yet.
	$next = (int) get_option( 'turf_review_next_show', 0 );
	if ( $next > 0 && time() < $next ) {
		return false;
	}

	$activated = (int) get_option( 'turf_activated_at', 0 );
	if ( $activated <= 0 ) {
		return false;
	}

	if ( time() - $activated < turf_review_delay_seconds() ) {
		return false;
	}

	// Don't show on the Turf Stats pages themselves.
	if ( ! empty( $_GET['page'] ) && 0 === strpos( sanitize_key( $_GET['page'] ), 'turf-' ) ) {
		return false;
	}

	return true;
}

function turf_render_review_nudge() {
	if ( ! turf_should_show_review_nudge() ) {
		return;
	}

	$review_url = 'https://wordpress.org/support/plugin/turf-stats/reviews/#new-post';
	$dismiss_url = wp_nonce_url(
		add_query_arg( 'turf_dismiss_review', '1', admin_url() ),
		'turf_dismiss_review',
		'turf_review_nonce'
	);
	?>
	<div class="notice notice-info is-dismissible turf-review-nudge">
		<p>
			<strong><?php echo esc_html__( 'Enjoying Turf Stats?', 'turf-stats' ); ?></strong><br />
			<?php echo esc_html__( 'A quick review on WordPress.org helps more site owners discover it - and takes less than a minute. No pressure if now isn\'t the moment.', 'turf-stats' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Leave a review', 'turf-stats' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'Not now', 'turf-stats' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'turf_render_review_nudge' );

/**
 * Handle the "Not now" dismissal link (GET, nonce-protected).
 *
 * Each click is a snooze: the nudge hides for a random 30-90 days and returns
 * once, until turf_review_max_snoozes() is reached - then it is gone for good.
 */
function turf_handle_review_dismiss() {
	if ( empty( $_GET['turf_dismiss_review'] ) ) {
		return;
	}

	if ( empty( $_GET['turf_review_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['turf_review_nonce'] ), 'turf_dismiss_review' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$count = (int) get_option( 'turf_review_snooze_count', 0 );

	if ( $count >= turf_review_max_snoozes() ) {
		// Budget spent: permanent dismissal.
		update_option( 'turf_review_dismissed', '1' );
	} else {
		$count++;
		update_option( 'turf_review_snooze_count', $count );
		update_option( 'turf_review_next_show', time() + turf_review_snooze_seconds() );
	}

	// Redirect back to the same page without the dismiss params.
	wp_safe_redirect( remove_query_arg( array( 'turf_dismiss_review', 'turf_review_nonce' ) ) );
	exit;
}
add_action( 'admin_init', 'turf_handle_review_dismiss' );
