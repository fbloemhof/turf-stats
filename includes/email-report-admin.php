<?php
/**
 * Settings screen for the weekly/monthly email report - a plain hand-rolled
 * form + nonce, matching this plugin's style everywhere else (there's no
 * Settings API usage anywhere in the codebase, so not introducing it fresh
 * here either). Sending itself lives in includes/email-report.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_email_report_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Email report', 'turf-stats' ),
		__( 'Email report', 'turf-stats' ),
		'manage_options',
		'turf-email-report',
		'turf_email_report_render_admin_page'
	);

	add_action( 'admin_enqueue_scripts', function ( $current_hook ) use ( $hook ) {
		if ( $current_hook === $hook ) {
			turf_email_report_enqueue();
		}
	} );
}
add_action( 'admin_menu', 'turf_email_report_admin_menu' );

function turf_email_report_enqueue() {
	wp_enqueue_script( 'turf-email-report-admin', TURF_URL . 'js/email-report-admin.js', array(), TURF_VERSION, true );
	wp_localize_script( 'turf-email-report-admin', 'turfEmailReportAdmin', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'turf_send_test_email_report' ),
		'i18n'    => array(
			'sending' => __( 'Sending…', 'turf-stats' ),
			'sent'    => __( 'Sent.', 'turf-stats' ),
			'failed'  => __( 'Failed to send - check the recipient address and try again.', 'turf-stats' ),
		),
	) );
}

/**
 * Processes the settings form, if submitted. Runs on `admin_init` (guarded
 * by its own nonce check first) rather than inside the render callback -
 * standard WP practice for a hand-rolled settings form with no Settings API.
 */
function turf_email_report_maybe_save() {
	if ( ! isset( $_POST['turf_email_report_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_key( $_POST['turf_email_report_nonce'] ), 'turf_email_report_settings' ) ) {
		return;
	}

	$enabled_before   = (bool) get_option( 'turf_email_report_enabled' );
	$frequency_before = get_option( 'turf_email_report_frequency', 'weekly' );

	$enabled       = ! empty( $_POST['turf_email_report_enabled'] );
	$all_admins    = ! empty( $_POST['turf_email_report_all_admins'] );
	$recipient_raw = isset( $_POST['turf_email_report_recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['turf_email_report_recipient'] ) ) : '';
	$frequency     = ( isset( $_POST['turf_email_report_frequency'] ) && 'monthly' === $_POST['turf_email_report_frequency'] ) ? 'monthly' : 'weekly';

	$valid_recipients = turf_email_report_valid_recipients( $recipient_raw );
	// With "all administrators" on, the typed addresses are extras and may be
	// empty; without it, an empty field falls back to the site admin email.
	$recipient        = $valid_recipients ? implode( ', ', $valid_recipients ) : ( $all_admins ? '' : get_option( 'admin_email' ) );

	update_option( 'turf_email_report_enabled', $enabled ? '1' : '' );
	update_option( 'turf_email_report_recipient', $recipient );
	update_option( 'turf_email_report_all_admins', $all_admins ? '1' : '' );
	update_option( 'turf_email_report_frequency', $frequency );

	// A disable, or a frequency change while enabled, needs the existing
	// schedule cleared and (if still enabled) recreated right away - the
	// lazy wp_next_scheduled() guard on `init` only ever creates a *missing*
	// schedule, it won't correct one that's already there with the old
	// frequency, or remove one that should no longer exist at all.
	if ( $enabled !== $enabled_before || $frequency !== $frequency_before ) {
		turf_email_report_reschedule();
	}

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'turf-stats' ) . '</p></div>';
	} );
}
add_action( 'admin_init', 'turf_email_report_maybe_save' );

/**
 * "Send test email now" - sends immediately to whatever address is currently
 * typed in the form (not necessarily the saved option), or to the current
 * user when the field is empty, without touching the
 * schedule. wp_mail() has no other usage anywhere in this plugin, so this is
 * the way to confirm formatting/delivery without waiting for the real cron.
 */
function turf_ajax_send_test_email_report() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_send_test_email_report' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$recipient_raw = isset( $_POST['recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient'] ) ) : '';

	// An empty field is valid when "all administrators" is on - the test
	// then goes to the person clicking the button, not to every admin.
	if ( '' === trim( $recipient_raw ) ) {
		$recipient_raw = wp_get_current_user()->user_email;
	}

	if ( ! turf_email_report_valid_recipients( $recipient_raw ) ) {
		wp_send_json_error( 'invalid email', 400 );
	}

	if ( ! turf_send_email_report( $recipient_raw, true ) ) {
		wp_send_json_error( 'send failed', 500 );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_turf_send_test_email_report', 'turf_ajax_send_test_email_report' );

function turf_email_report_render_admin_page() {
	$enabled   = (bool) get_option( 'turf_email_report_enabled' );
	$all_admins = (bool) get_option( 'turf_email_report_all_admins' );
	$recipient  = get_option( 'turf_email_report_recipient' ) ?: ( $all_admins ? '' : get_option( 'admin_email' ) );
	$frequency  = get_option( 'turf_email_report_frequency', 'weekly' );
	$admins     = turf_email_report_admin_addresses();
	$next_run   = wp_next_scheduled( 'turf_send_email_report' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Email report', 'turf-stats' ); ?></h1>
		<?php if ( ! empty( $_GET['turf_offer_enabled'] ) && $enabled && $next_run ) : ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: %s: date and time of the first report */
					esc_html__( 'The email report is on. The first one arrives around %s.', 'turf-stats' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) )
				);
				?>
			</p></div>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Get a summary of your site\'s stats by email on a recurring schedule.', 'turf-stats' ); ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'turf_email_report_settings', 'turf_email_report_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'turf-stats' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="turf_email_report_enabled" value="1" <?php checked( $enabled ); ?> />
							<?php esc_html_e( 'Send the email report on a recurring schedule', 'turf-stats' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Administrators', 'turf-stats' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="turf_email_report_all_admins" value="1" <?php checked( $all_admins ); ?> />
							<?php esc_html_e( 'Send to all administrators of this site', 'turf-stats' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: comma-separated email addresses */
								esc_html__( 'Kept up to date automatically: new administrators get the report, removed ones stop getting it. Now: %s', 'turf-stats' ),
								esc_html( $admins ? implode( ', ', $admins ) : '-' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="turf-email-report-recipient"><?php esc_html_e( 'Recipients', 'turf-stats' ); ?></label></th>
					<td>
						<input type="email" multiple id="turf-email-report-recipient" name="turf_email_report_recipient" value="<?php echo esc_attr( $recipient ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'One or more addresses, separated by commas. With "all administrators" on, these are extra and may be left empty.', 'turf-stats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="turf-email-report-frequency"><?php esc_html_e( 'Frequency', 'turf-stats' ); ?></label></th>
					<td>
						<select id="turf-email-report-frequency" name="turf_email_report_frequency">
							<option value="weekly" <?php selected( 'weekly', $frequency ); ?>><?php esc_html_e( 'Weekly', 'turf-stats' ); ?></option>
							<option value="monthly" <?php selected( 'monthly', $frequency ); ?>><?php esc_html_e( 'Monthly', 'turf-stats' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<p class="description">
				<?php esc_html_e( 'Relies on WP-Cron, which only runs on a page request - a site with very little traffic may see the email arrive a few hours late. If email in general doesn\'t reliably arrive from this site, an SMTP plugin fixes that independently of Turf Stats.', 'turf-stats' ); ?>
			</p>

			<?php submit_button( __( 'Save changes', 'turf-stats' ) ); ?>
		</form>

		<p>
			<button type="button" class="button" id="turf-send-test-email-report"><?php esc_html_e( 'Send test email now', 'turf-stats' ); ?></button>
			<span id="turf-send-test-email-report-status"></span>
		</p>
	</div>
	<?php
}

/**
 * One-time offer to turn the report on, instead of switching it on by
 * default: an update shouldn't start mailing people who never asked for it.
 * Shown to administrators on the Dashboard and the Statistics page only,
 * once the plugin has been in use for a week (so the first report has
 * something to say), and only on sites where the report settings were never
 * saved - someone who already chose on or off isn't asked again. It also
 * waits while the review nudge is up, so the two never stack.
 */
function turf_email_report_should_offer() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	if ( false !== get_option( 'turf_email_report_enabled', false ) || get_option( 'turf_email_report_offer_done' ) ) {
		return false;
	}

	$activated = (int) get_option( 'turf_activated_at', 0 );
	if ( $activated <= 0 || time() - $activated < 7 * DAY_IN_SECONDS ) {
		return false;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'toplevel_page_turf-stats' ), true ) ) {
		return false;
	}

	return ! turf_should_show_review_nudge();
}

function turf_email_report_render_offer() {
	if ( ! turf_email_report_should_offer() ) {
		return;
	}

	$url = function ( $answer ) {
		return wp_nonce_url( add_query_arg( 'turf_email_offer', $answer ), 'turf_email_report_offer', 'turf_email_offer_nonce' );
	};
	?>
	<div class="notice notice-info turf-email-report-offer">
		<p>
			<strong><?php esc_html_e( 'Weekly stats by email?', 'turf-stats' ); ?></strong><br />
			<?php esc_html_e( 'Turf Stats can send you a short summary every Monday morning: views, visitors, top pages and what needs attention, such as broken links. The email contains no links. Change or stop it any time under Statistics > Email report.', 'turf-stats' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $url( 'on' ) ); ?>"><?php esc_html_e( 'Yes, send it to me', 'turf-stats' ); ?></a>
			<a class="button-link" href="<?php echo esc_url( $url( 'off' ) ); ?>"><?php esc_html_e( 'No thanks', 'turf-stats' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'turf_email_report_render_offer' );

/**
 * Handles the offer's two buttons (GET, nonce-protected). Either answer
 * ends the offer for good. "Yes" turns on the weekly report for the person
 * who clicked - not for all administrators; that stays an explicit choice
 * on the settings screen - and opens that screen so they see what they
 * signed up for.
 */
function turf_email_report_handle_offer() {
	if ( empty( $_GET['turf_email_offer'] ) || empty( $_GET['turf_email_offer_nonce'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_GET['turf_email_offer_nonce'] ), 'turf_email_report_offer' ) ) {
		return;
	}

	update_option( 'turf_email_report_offer_done', '1', false );

	if ( 'on' !== sanitize_key( $_GET['turf_email_offer'] ) ) {
		wp_safe_redirect( remove_query_arg( array( 'turf_email_offer', 'turf_email_offer_nonce' ) ) );
		exit;
	}

	$email = wp_get_current_user()->user_email;

	update_option( 'turf_email_report_enabled', '1' );
	update_option( 'turf_email_report_frequency', 'weekly' );
	update_option( 'turf_email_report_recipient', is_email( $email ) ? $email : get_option( 'admin_email' ) );
	turf_email_report_reschedule();

	wp_safe_redirect( admin_url( 'admin.php?page=turf-email-report&turf_offer_enabled=1' ) );
	exit;
}
add_action( 'admin_init', 'turf_email_report_handle_offer' );
