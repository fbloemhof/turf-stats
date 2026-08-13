<?php
/**
 * Weekly/monthly email report - a wp_mail() summary of the same headline
 * numbers the "Statistieken" overview shows, sent on a recurring schedule.
 * Settings screen lives in includes/email-report-admin.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Next run timestamp (UTC) for the configured frequency, anchored to Monday
 * 08:00 (weekly) or the 1st of next month 08:00 (monthly), site timezone.
 * Recomputed fresh every time the report reschedules itself (see
 * turf_send_email_report()), so a frequency change picks up on the very next
 * run without needing a fixed recurring interval - which doesn't have a
 * constant length for "monthly" anyway (28-31 days).
 */
function turf_email_report_next_run_timestamp() {
	$frequency = get_option( 'turf_email_report_frequency', 'weekly' );
	$tz        = wp_timezone();

	if ( 'monthly' === $frequency ) {
		$next = new DateTime( 'first day of next month 08:00', $tz );
	} else {
		$next = new DateTime( 'next monday 08:00', $tz );
	}

	return $next->getTimestamp();
}

/**
 * The turf_get_range_site_totals()-style $days value the report's period
 * covers: 7 for weekly, 30 for monthly.
 */
function turf_email_report_period_days() {
	return 'monthly' === get_option( 'turf_email_report_frequency', 'weekly' ) ? 30 : 7;
}

/**
 * Lazily (re)creates the recurring schedule if it's missing - mirrors
 * turf_schedule_pruning() in includes/retention.php exactly. A disable, or a
 * frequency change while enabled, is instead handled explicitly at save time
 * (see includes/email-report-admin.php) since this guard only ever creates a
 * *missing* schedule, it can't correct an existing one.
 */
function turf_email_report_schedule() {
	if ( ! get_option( 'turf_email_report_enabled' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( 'turf_send_email_report' ) ) {
		wp_schedule_single_event( turf_email_report_next_run_timestamp(), 'turf_send_email_report' );
	}
}
add_action( 'init', 'turf_email_report_schedule' );

/**
 * Splits a comma-separated string of addresses (the storage/input format for
 * $recipient everywhere in this feature) into the individually-valid ones,
 * dropping anything that doesn't pass is_email(). Shared by the settings-save
 * handler, the test-send handler, and turf_send_email_report() itself, so
 * "what counts as a valid recipient" can't drift between them.
 *
 * @param string $raw Comma-separated addresses.
 * @return string[] Valid addresses only, trimmed, in the order given.
 */
function turf_email_report_valid_recipients( $raw ) {
	$candidates = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );

	return array_values( array_filter( $candidates, 'is_email' ) );
}

/**
 * Sends the report. For the real recurring cron run, the *next* run is
 * scheduled first, before the risky part (wp_mail()) - so a mid-send failure
 * doesn't break the recurring habit.
 *
 * @param string|null $recipient_override Comma-separated address(es) to send
 *                                         to instead of the saved option, and
 *                                         skip rescheduling - used by the
 *                                         "send test email now" button, which
 *                                         isn't the real cron run.
 * @param bool        $is_test             True for a manual test send.
 * @return bool Whether wp_mail() reported success.
 */
function turf_send_email_report( $recipient_override = null, $is_test = false ) {
	if ( ! $is_test ) {
		if ( ! get_option( 'turf_email_report_enabled' ) ) {
			return false;
		}

		wp_schedule_single_event( turf_email_report_next_run_timestamp(), 'turf_send_email_report' );
	}

	$to_raw = $recipient_override ? $recipient_override : ( get_option( 'turf_email_report_recipient' ) ?: get_option( 'admin_email' ) );
	$to     = turf_email_report_valid_recipients( $to_raw );

	if ( ! $to ) {
		return false;
	}

	$days = turf_email_report_period_days();

	return wp_mail(
		$to,
		turf_email_report_subject( $days ),
		turf_render_email_report_html( $days ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
}
add_action( 'turf_send_email_report', 'turf_send_email_report' );

function turf_email_report_subject( $days ) {
	$frequency_label = ( 30 === $days ) ? __( 'monthly', 'turf-stats' ) : __( 'weekly', 'turf-stats' );

	return sprintf(
		/* translators: 1: "weekly" or "monthly", 2: site name */
		__( 'Your %1$s stats for %2$s', 'turf-stats' ),
		$frequency_label,
		get_bloginfo( 'name' )
	);
}

/**
 * Simple inline-styled HTML summary - admin CSS classes don't apply in a
 * mail client, so this is genuinely separate markup, not a reuse of any
 * admin postbox template. Reuses the same getters CSV export and the admin
 * overview already use.
 */
function turf_render_email_report_html( $days ) {
	$current  = turf_get_range_site_totals( $days );
	$previous = turf_get_range_site_totals( $days, turf_previous_period_offset( $days ) );

	$views_change    = turf_pct_change( $current['views'], $previous['views'] );
	$visitors_change = turf_pct_change( $current['visitors'], $previous['visitors'] );

	$top_pages     = array_slice( turf_get_top_posts_for_period( 'post', $days ), 0, 5 );
	$top_referrers = turf_get_top_referrer_hosts( $days, 5 );

	ob_start();
	?>
	<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1d2327">
		<h2 style="margin:0 0 16px"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>

		<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px">
			<tr>
				<td style="padding:12px;border:1px solid #dcdcde;text-align:center">
					<div style="font-size:24px;font-weight:600"><?php echo esc_html( number_format_i18n( $current['views'] ) ); ?></div>
					<div style="font-size:12px;color:#646970">
						<?php esc_html_e( 'Views', 'turf-stats' ); ?>
						<?php if ( null !== $views_change ) : ?>
							(<?php echo esc_html( ( $views_change >= 0 ? '+' : '' ) . $views_change . '%' ); ?>)
						<?php endif; ?>
					</div>
				</td>
				<td style="padding:12px;border:1px solid #dcdcde;text-align:center">
					<div style="font-size:24px;font-weight:600"><?php echo esc_html( number_format_i18n( $current['visitors'] ) ); ?></div>
					<div style="font-size:12px;color:#646970">
						<?php esc_html_e( 'Visitors', 'turf-stats' ); ?>
						<?php if ( null !== $visitors_change ) : ?>
							(<?php echo esc_html( ( $visitors_change >= 0 ? '+' : '' ) . $visitors_change . '%' ); ?>)
						<?php endif; ?>
					</div>
				</td>
			</tr>
		</table>

		<?php if ( $top_pages ) : ?>
			<h3 style="font-size:14px;margin:0 0 8px"><?php esc_html_e( 'Top pages', 'turf-stats' ); ?></h3>
			<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px">
				<?php foreach ( $top_pages as $row ) : ?>
					<tr>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde"><?php echo esc_html( get_the_title( $row->post_id ) ?: ( '#' . $row->post_id ) ); ?></td>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde;text-align:right;white-space:nowrap"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php if ( $top_referrers ) : ?>
			<h3 style="font-size:14px;margin:0 0 8px"><?php esc_html_e( 'Top referrers', 'turf-stats' ); ?></h3>
			<table role="presentation" style="width:100%;border-collapse:collapse">
				<?php foreach ( $top_referrers as $row ) : ?>
					<tr>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde"><?php echo esc_html( turf_referrer_host_label( $row->label ) ); ?></td>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde;text-align:right;white-space:nowrap"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<p style="margin-top:24px;font-size:12px;color:#646970">
			<?php
			printf(
				/* translators: %s: site name */
				esc_html__( 'Sent by Turf Stats for %s.', 'turf-stats' ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>
	</div>
	<?php
	return ob_get_clean();
}
