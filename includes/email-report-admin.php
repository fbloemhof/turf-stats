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
	$recipient_raw = isset( $_POST['turf_email_report_recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['turf_email_report_recipient'] ) ) : '';
	$frequency     = ( isset( $_POST['turf_email_report_frequency'] ) && 'monthly' === $_POST['turf_email_report_frequency'] ) ? 'monthly' : 'weekly';

	$valid_recipients = turf_email_report_valid_recipients( $recipient_raw );
	$recipient        = $valid_recipients ? implode( ', ', $valid_recipients ) : get_option( 'admin_email' );

	update_option( 'turf_email_report_enabled', $enabled ? '1' : '' );
	update_option( 'turf_email_report_recipient', $recipient );
	update_option( 'turf_email_report_frequency', $frequency );

	// A disable, or a frequency change while enabled, needs the existing
	// schedule cleared and (if still enabled) recreated right away - the
	// lazy wp_next_scheduled() guard on `init` only ever creates a *missing*
	// schedule, it won't correct one that's already there with the old
	// frequency, or remove one that should no longer exist at all.
	if ( $enabled !== $enabled_before || $frequency !== $frequency_before ) {
		wp_clear_scheduled_hook( 'turf_send_email_report' );

		if ( $enabled ) {
			wp_schedule_single_event( turf_email_report_next_run_timestamp(), 'turf_send_email_report' );
		}
	}

	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'turf-stats' ) . '</p></div>';
	} );
}
add_action( 'admin_init', 'turf_email_report_maybe_save' );

/**
 * "Send test email now" - sends immediately to whatever address is currently
 * typed in the form (not necessarily the saved option), without touching the
 * schedule. wp_mail() has no other usage anywhere in this plugin, so this is
 * the way to confirm formatting/delivery without waiting for the real cron.
 */
function turf_ajax_send_test_email_report() {
	if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'turf_send_test_email_report' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$recipient_raw = isset( $_POST['recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient'] ) ) : '';

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
	$recipient = get_option( 'turf_email_report_recipient' ) ?: get_option( 'admin_email' );
	$frequency = get_option( 'turf_email_report_frequency', 'weekly' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Email report', 'turf-stats' ); ?></h1>
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
					<th scope="row"><label for="turf-email-report-recipient"><?php esc_html_e( 'Recipients', 'turf-stats' ); ?></label></th>
					<td>
						<input type="email" multiple id="turf-email-report-recipient" name="turf_email_report_recipient" value="<?php echo esc_attr( $recipient ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'One or more addresses, separated by commas.', 'turf-stats' ); ?></p>
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
