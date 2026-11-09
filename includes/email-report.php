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
 * Email addresses of everyone who can manage this site (manage_options -
 * the same capability that sees the Turf Stats screens), read fresh on every
 * send, so a new administrator starts receiving the report and one who's
 * removed stops, without anyone editing the recipient list.
 *
 * @return string[]
 */
function turf_email_report_admin_addresses() {
	$users = get_users( array(
		'capability' => 'manage_options',
		'fields'     => array( 'user_email' ),
	) );

	return array_values( array_filter( wp_list_pluck( $users, 'user_email' ), 'is_email' ) );
}

/**
 * Everyone the scheduled report goes to: the addresses typed on the settings
 * screen, plus all administrators when that option is on. Without either,
 * it falls back to the site's admin email (the behaviour before 1.33.0).
 * Duplicates are dropped case-insensitively, so an administrator who is also
 * typed in gets one mail.
 *
 * @return string[]
 */
function turf_email_report_recipients() {
	$list = turf_email_report_valid_recipients( (string) get_option( 'turf_email_report_recipient', '' ) );

	if ( get_option( 'turf_email_report_all_admins' ) ) {
		$list = array_merge( $list, turf_email_report_admin_addresses() );
	} elseif ( ! $list ) {
		$list = turf_email_report_valid_recipients( get_option( 'admin_email' ) );
	}

	$unique = array();
	foreach ( $list as $address ) {
		$unique[ strtolower( $address ) ] = $address;
	}

	return array_values( $unique );
}

/**
 * Clears the pending report and, if the report is (still) enabled, plans the
 * next one - for a change made at runtime (settings save, the opt-in notice)
 * that the lazy turf_email_report_schedule() guard can't correct.
 */
function turf_email_report_reschedule() {
	wp_clear_scheduled_hook( 'turf_send_email_report' );

	if ( get_option( 'turf_email_report_enabled' ) ) {
		wp_schedule_single_event( turf_email_report_next_run_timestamp(), 'turf_send_email_report' );
	}
}

/**
 * Sends the report. For the real recurring cron run, the *next* run is
 * scheduled first, before the risky part (wp_mail()) - so a mid-send failure
 * doesn't break the recurring habit.
 *
 * @param string|null $recipient_override Comma-separated address(es) to send
 *                                         to instead of turf_email_report_recipients(), and
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

	$to = $recipient_override ? turf_email_report_valid_recipients( $recipient_override ) : turf_email_report_recipients();

	if ( ! $to ) {
		return false;
	}

	$days = turf_email_report_period_days();

	return wp_mail(
		$to,
		turf_email_report_subject( $days ),
		turf_render_email_report_html( $days, turf_email_report_show_review_hint( $is_test ) ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
}
add_action( 'turf_send_email_report', 'turf_send_email_report' );

/**
 * Counts of the things in the report's "Needs attention" section for one
 * period window - current ($offset_days 0) or the previous one, so the mail
 * can show whether each went up or down. Same window math as
 * turf_get_range_site_totals() (turf_period_window()), so the trend arrows
 * line up with the views/visitors comparison above them.
 *
 * - not_found: 404 hits that look human (turf_404s_human_sql()) - raw 404
 *   counts are mostly scanners and would swing on bot noise alone.
 * - broken_links: distinct internal page -> missing URL pairs that got hit.
 * - zero_searches: searches that returned no results.
 *
 * Not cached: it only runs from the report cron and the test-send button.
 *
 * @param int $days        Report period length (7 or 30).
 * @param int $offset_days 0 for the current window, $days for the previous.
 * @return int[] Keyed not_found, broken_links, zero_searches.
 */
function turf_email_report_problem_counts( $days, $offset_days = 0 ) {
	global $wpdb;

	list( $start, $end ) = turf_period_window( $days, $offset_days );

	$table_404s   = turf_404s_table();
	$table_search = turf_search_table();
	$human        = turf_404s_human_sql( 'f' );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and $human are built from literals only.
	$not_found = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_404s f WHERE f.hit_at >= %s AND f.hit_at < %s AND $human",
		$start,
		$end
	) );

	$broken_links = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT referrer_path, path, query_keys) FROM $table_404s
		WHERE referrer_path != '' AND hit_at >= %s AND hit_at < %s",
		$start,
		$end
	) );

	$zero_searches = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table_search WHERE results_count = 0 AND searched_at >= %s AND searched_at < %s",
		$start,
		$end
	) );
	// phpcs:enable

	return array(
		'not_found'     => (int) $not_found,
		'broken_links'  => (int) $broken_links,
		'zero_searches' => (int) $zero_searches,
	);
}

/**
 * Change label for one "Needs attention" row: "+3", "-2" or "=" against the
 * previous period, plus a colour - for problems, up is bad (red) and down is
 * good (green), the opposite of views/visitors.
 *
 * @return array{0: string, 1: string} [ $label, $css_color ].
 */
function turf_email_report_problem_change( $current, $previous ) {
	$diff = $current - $previous;

	if ( 0 === $diff ) {
		return array( '=', '#646970' );
	}

	return $diff > 0
		? array( '+' . number_format_i18n( $diff ), '#b32d2e' )
		: array( '-' . number_format_i18n( abs( $diff ) ), '#008a20' );
}

/**
 * Whether this report carries the one-line "enjoying Turf Stats?" review
 * hint. Deliberately rare, and never a link (a stats mail full of links
 * trains people to click links in mail): at most once per
 * turf_email_report_review_hint_interval_days (default 90, 0 turns the hint
 * off), not before the plugin has been in use for 30 days, and never once
 * the admin review nudge has been dismissed for good (see includes/review-notice.php) - someone who
 * said "not now" three times doesn't need a fourth ask by mail.
 *
 * @param bool $is_test Test sends show the hint when it's due but don't
 *                      count as having shown it.
 */
function turf_email_report_show_review_hint( $is_test ) {
	if ( '1' === get_option( 'turf_review_dismissed' ) ) {
		return false;
	}

	$activated = (int) get_option( 'turf_activated_at', 0 );
	if ( $activated <= 0 || time() - $activated < 30 * DAY_IN_SECONDS ) {
		return false;
	}

	$interval = (int) apply_filters( 'turf_email_report_review_hint_interval_days', 90 ) * DAY_IN_SECONDS;
	$last     = (int) get_option( 'turf_email_report_review_hint_at', 0 );

	$show = $interval > 0 && time() - $last >= $interval;

	if ( $show && ! $is_test ) {
		update_option( 'turf_email_report_review_hint_at', time(), false );
	}

	return $show;
}

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
 *
 * The mail contains no links at all, on purpose: page titles, referrers,
 * paths and search terms are plain text, and where it points somewhere it
 * names the admin screen instead ("Turf Stats > 404s"). A report that never
 * links is one recipients can trust not to be phishing.
 *
 * @param int  $days        Report period length (7 or 30).
 * @param bool $review_hint Whether to add the one-line review hint (see
 *                          turf_email_report_show_review_hint()).
 */
function turf_render_email_report_html( $days, $review_hint = false ) {
	$current  = turf_get_range_site_totals( $days );
	$previous = turf_get_range_site_totals( $days, turf_previous_period_offset( $days ) );

	$views_change    = turf_pct_change( $current['views'], $previous['views'] );
	$visitors_change = turf_pct_change( $current['visitors'], $previous['visitors'] );

	$top_pages     = array_slice( turf_get_top_posts_for_period( 'post', $days ), 0, 5 );
	$top_referrers = turf_get_top_referrer_hosts( $days, 5 );
	$top_searches  = turf_search_get_top_terms( $days, 5 );

	$problems_now  = turf_email_report_problem_counts( $days );
	$problems_prev = turf_email_report_problem_counts( $days, turf_previous_period_offset( $days ) );
	$has_problems  = array_sum( $problems_now ) + array_sum( $problems_prev ) > 0;

	$problem_rows = array(
		'not_found'     => __( '404 errors seen by visitors', 'turf-stats' ),
		'broken_links'  => __( 'Broken internal links', 'turf-stats' ),
		'zero_searches' => __( 'Searches without results', 'turf-stats' ),
	);

	$broken_links  = $problems_now['broken_links'] ? array_slice( turf_404s_get_broken_links( $days ), 0, 3 ) : array();
	$missing_paths = $problems_now['not_found'] ? array_slice( turf_404s_get_top_paths( $days, true ), 0, 3 ) : array();
	$zero_terms    = $problems_now['zero_searches'] ? turf_search_get_zero_result_terms( $days, 3 ) : array();

	list( $period_start, $period_end ) = turf_period_window( $days );
	$date_format = get_option( 'date_format' );
	$period_text = sprintf(
		/* translators: 1: first day of the report period, 2: last day */
		__( '%1$s to %2$s, compared with the period before', 'turf-stats' ),
		wp_date( $date_format, strtotime( $period_start . ' UTC' ) ),
		wp_date( $date_format, strtotime( $period_end . ' UTC' ) - 1 )
	);

	$cell      = 'padding:6px 0;border-bottom:1px solid #dcdcde';
	$cell_num  = $cell . ';text-align:right;white-space:nowrap';
	$cell_note = 'padding:2px 0 2px 12px;font-size:13px;color:#50575e';

	ob_start();
	?>
	<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:600px;margin:0 auto;color:#1d2327">
		<h2 style="margin:0 0 4px"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h2>
		<p style="margin:0 0 16px;font-size:13px;color:#646970"><?php echo esc_html( $period_text ); ?></p>

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

		<?php if ( $has_problems ) : ?>
			<h3 style="font-size:14px;margin:0 0 8px"><?php esc_html_e( 'Needs attention', 'turf-stats' ); ?></h3>
			<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:8px">
				<?php foreach ( $problem_rows as $key => $label ) : ?>
					<?php list( $change_label, $change_color ) = turf_email_report_problem_change( $problems_now[ $key ], $problems_prev[ $key ] ); ?>
					<tr>
						<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $label ); ?></td>
						<td style="<?php echo esc_attr( $cell_num ); ?>"><?php echo esc_html( number_format_i18n( $problems_now[ $key ] ) ); ?></td>
						<td style="<?php echo esc_attr( $cell_num ); ?>;width:56px;color:<?php echo esc_attr( $change_color ); ?>"><?php echo esc_html( $change_label ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php if ( $missing_paths ) : ?>
				<p style="margin:12px 0 4px;font-size:13px"><?php esc_html_e( 'Most-hit missing URLs:', 'turf-stats' ); ?></p>
				<table role="presentation" style="width:100%;border-collapse:collapse">
					<?php foreach ( $missing_paths as $row ) : ?>
						<tr>
							<td style="<?php echo esc_attr( $cell_note ); ?>;word-break:break-all"><?php echo esc_html( turf_404s_format_url( $row ) ); ?></td>
							<td style="<?php echo esc_attr( $cell_note ); ?>;text-align:right;white-space:nowrap"><?php echo esc_html( number_format_i18n( $row->humans ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( $broken_links ) : ?>
				<p style="margin:12px 0 4px;font-size:13px"><?php esc_html_e( 'Pages linking to a missing URL:', 'turf-stats' ); ?></p>
				<table role="presentation" style="width:100%;border-collapse:collapse">
					<?php foreach ( $broken_links as $row ) : ?>
						<tr>
							<td style="<?php echo esc_attr( $cell_note ); ?>;word-break:break-all">
								<?php
								printf(
									/* translators: 1: path of the page containing the link, 2: the missing URL it links to */
									esc_html__( '%1$s links to %2$s', 'turf-stats' ),
									esc_html( $row->referrer_path ),
									esc_html( turf_404s_format_url( $row ) )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<?php if ( $zero_terms ) : ?>
				<p style="margin:12px 0 4px;font-size:13px"><?php esc_html_e( 'Searched for, but nothing found:', 'turf-stats' ); ?></p>
				<table role="presentation" style="width:100%;border-collapse:collapse">
					<?php foreach ( $zero_terms as $row ) : ?>
						<tr>
							<td style="<?php echo esc_attr( $cell_note ); ?>"><?php echo esc_html( $row->search_term ); ?></td>
							<td style="<?php echo esc_attr( $cell_note ); ?>;text-align:right;white-space:nowrap"><?php echo esc_html( number_format_i18n( $row->searches ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<p style="margin:8px 0 24px;font-size:12px;color:#646970"><?php esc_html_e( 'Details in your dashboard under Turf Stats > 404s and Turf Stats > Analysis.', 'turf-stats' ); ?></p>
		<?php endif; ?>

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
			<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px">
				<?php foreach ( $top_referrers as $row ) : ?>
					<tr>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde"><?php echo esc_html( turf_referrer_host_label( $row->label ) ); ?></td>
						<td style="padding:6px 0;border-bottom:1px solid #dcdcde;text-align:right;white-space:nowrap"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php if ( $top_searches ) : ?>
			<h3 style="font-size:14px;margin:0 0 8px"><?php esc_html_e( 'Top search terms', 'turf-stats' ); ?></h3>
			<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px">
				<?php foreach ( $top_searches as $row ) : ?>
					<tr>
						<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $row->search_term ); ?></td>
						<td style="<?php echo esc_attr( $cell_num ); ?>"><?php echo esc_html( number_format_i18n( $row->searches ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<?php if ( $review_hint ) : ?>
			<p style="margin:0;padding:12px;background:#f6f7f7;font-size:13px;color:#50575e">
				<?php esc_html_e( 'Enjoying Turf Stats? A short review on WordPress.org helps other site owners find it. Look up "Turf Stats" in the plugin directory - no link here, on purpose.', 'turf-stats' ); ?>
			</p>
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
