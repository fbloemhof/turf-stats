<?php
/**
 * Funnels: how many visitors go through a fixed sequence of steps, e.g.
 * "clicked the ticket button" -> "viewed the booking page" -> "submitted the
 * booking form". No new tracking - the steps are matched on the
 * visitor_hash that clicks, views and form submissions already share, so a
 * funnel can be defined (and redefined) after the fact and still covers the
 * data recorded so far (clicks only since they got a visitor_hash).
 *
 * Funnels are defined on the Clicks page, one per line:
 *
 *   Tickets: click event-landing-ticket-hero > view /boeken/ > form cf7 12
 *
 * Step types:
 *   click <data-turf-click key>
 *   view <path or URL>       a post/page, or an "other" page by its path
 *   form [<plugin>] <form id> plugin = cf7 or gravityforms; omit for any
 *
 * Code can add funnels in the same parsed shape through the turf_funnels
 * filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Longest gap allowed between two consecutive steps of one visitor - longer
 * than the 30-minute session gap, since filling in a booking form after
 * viewing the page can take a while.
 */
function turf_funnel_step_window() {
	return (int) apply_filters( 'turf_funnel_step_window', HOUR_IN_SECONDS );
}

/**
 * Parses the funnel definition text (see the file header for the syntax).
 *
 * @return array{funnels: array[], errors: string[]} funnels as
 *         array( 'label' => ..., 'steps' => array( array( 'type' => 'click',
 *         'key' => ... ) | array( 'type' => 'view', 'path' => ... ) |
 *         array( 'type' => 'form', 'plugin' => ''|..., 'form_id' => ... ) ) ).
 */
function turf_parse_funnels( $text ) {
	$funnels = array();
	$errors  = array();

	foreach ( preg_split( '/\R/', (string) $text ) as $number => $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		$label = '';
		if ( preg_match( '/^([^:>]+):(.*)$/', $line, $m ) && ! preg_match( '#^\s*//#', $m[2] ) ) {
			$label = trim( $m[1] );
			$line  = $m[2];
		}

		$steps = array();

		foreach ( explode( '>', $line ) as $raw_step ) {
			$parts = preg_split( '/\s+/', trim( $raw_step ) );
			$type  = strtolower( array_shift( $parts ) );
			$step  = null;

			if ( 'click' === $type && 1 === count( $parts ) && '' !== sanitize_key( $parts[0] ) ) {
				$step = array( 'type' => 'click', 'key' => sanitize_key( $parts[0] ) );
			} elseif ( 'view' === $type && 1 === count( $parts ) ) {
				$path = turf_sanitize_path( $parts[0] );
				$step = '' !== $path ? array( 'type' => 'view', 'path' => $path ) : null;
			} elseif ( 'form' === $type && in_array( count( $parts ), array( 1, 2 ), true ) ) {
				$step = array(
					'type'    => 'form',
					'plugin'  => 2 === count( $parts ) ? sanitize_key( $parts[0] ) : '',
					'form_id' => substr( sanitize_text_field( end( $parts ) ), 0, 50 ),
				);
			}

			if ( ! $step ) {
				/* translators: 1: line number, 2: the step that couldn't be read */
				$errors[] = sprintf( __( 'Line %1$d: can\'t read step "%2$s".', 'turf-stats' ), $number + 1, trim( $raw_step ) );
				continue 2;
			}

			$steps[] = $step;
		}

		if ( count( $steps ) < 2 ) {
			/* translators: %d: line number */
			$errors[] = sprintf( __( 'Line %d: a funnel needs at least two steps.', 'turf-stats' ), $number + 1 );
			continue;
		}

		/* translators: %d: funnel number */
		$funnels[] = array( 'label' => '' !== $label ? $label : sprintf( __( 'Funnel %d', 'turf-stats' ), count( $funnels ) + 1 ), 'steps' => $steps );
	}

	return array( 'funnels' => $funnels, 'errors' => $errors );
}

/**
 * The configured funnels (saved definition + turf_funnels filter).
 */
function turf_get_funnels() {
	$parsed = turf_parse_funnels( get_option( 'turf_funnels_definition', '' ) );

	return (array) apply_filters( 'turf_funnels', $parsed['funnels'] );
}

/**
 * Human-readable label for a step, for the report table.
 */
function turf_funnel_step_label( $step ) {
	switch ( $step['type'] ) {
		case 'click':
			/* translators: %s: data-turf-click key */
			return sprintf( __( 'Click: %s', 'turf-stats' ), $step['key'] );
		case 'view':
			/* translators: %s: page path */
			return sprintf( __( 'View: %s', 'turf-stats' ), $step['path'] );
		default:
			/* translators: %s: form id (optionally prefixed with the form plugin) */
			return sprintf( __( 'Form: %s', 'turf-stats' ), trim( $step['plugin'] . ' ' . $step['form_id'] ) );
	}
}

/**
 * (visitor_hash, timestamp) rows of one step within the period, capped at
 * turf_session_row_limit() like the session reconstruction. A view step
 * matches the post/page its path resolves to, or - for archives, the agenda
 * and other non-post pages - the recorded path of an "other" page view.
 *
 * @return object[] Each with ->visitor_hash and ->at (UTC 'Y-m-d H:i:s').
 */
function turf_funnel_step_rows( $step, $days ) {
	global $wpdb;

	switch ( $step['type'] ) {
		case 'click':
			$table  = turf_clicks_table();
			$column = 'clicked_at';
			$where  = 'click_key = %s';
			$params = array( $step['key'] );
			break;

		case 'view':
			$table   = turf_table();
			$column  = 'viewed_at';
			$post_id = url_to_postid( home_url( $step['path'] ) );

			if ( $post_id ) {
				$where  = 'post_id = %d';
				$params = array( $post_id );
			} else {
				$where  = 'page_type IS NOT NULL AND path = %s';
				$params = array( $step['path'] );
			}

			$where .= turf_not_suspect_sql();
			break;

		default:
			$table  = turf_forms_table();
			$column = 'submitted_at';
			$where  = 'form_id = %s';
			$params = array( $step['form_id'] );

			if ( '' !== $step['plugin'] ) {
				$where   .= ' AND plugin = %s';
				$params[] = $step['plugin'];
			}
	}

	list( $where_date, $date_params ) = turf_period_where_sql( $days, $column );

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT visitor_hash, $column AS at FROM $table
		WHERE $where AND visitor_hash != '' $where_date
		ORDER BY $column
		LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table, literal column and conditions.
		array_merge( $params, $date_params, array( turf_session_row_limit() ) )
	) );
}

/**
 * Visitors reaching each step of $funnel in the period. A visitor reaches
 * step N when they did step N within turf_funnel_step_window() after a
 * moment at which they had reached step N-1 - every earlier occurrence is
 * considered, so a first click that went nowhere doesn't hide a later one
 * that went all the way.
 *
 * @return int[] Visitor count per step, same order as $funnel['steps'].
 */
function turf_compute_funnel( $funnel, $days ) {
	return turf_stats_cached( array( 'funnel', $funnel, $days ), function () use ( $funnel, $days ) {
		$window  = turf_funnel_step_window();
		$reached = null; // visitor_hash => sorted timestamps at which the previous step was reached
		$counts  = array();

		foreach ( $funnel['steps'] as $step ) {
			$times = array();

			foreach ( turf_funnel_step_rows( $step, $days ) as $row ) {
				$times[ $row->visitor_hash ][] = strtotime( $row->at . ' UTC' );
			}

			if ( null !== $reached ) {
				foreach ( $times as $hash => $step_times ) {
					$previous = $reached[ $hash ] ?? array();
					$kept     = array();

					foreach ( $step_times as $t ) {
						foreach ( $previous as $p ) {
							if ( $p <= $t && $t - $p <= $window ) {
								$kept[] = $t;
								break;
							}
						}
					}

					if ( $kept ) {
						$times[ $hash ] = $kept;
					} else {
						unset( $times[ $hash ] );
					}
				}
			}

			$reached  = $times;
			$counts[] = count( $reached );
		}

		return $counts;
	} );
}

function turf_render_funnels( $days ) {
	$funnels = turf_get_funnels();

	if ( ! $funnels ) {
		echo '<p>' . esc_html__( 'No funnels defined yet - add one below.', 'turf-stats' ) . '</p>';
	}

	foreach ( $funnels as $funnel ) {
		$counts = turf_compute_funnel( $funnel, $days );
		$first  = $counts ? max( 1, $counts[0] ) : 1;
		?>
		<h3><?php echo esc_html( $funnel['label'] ); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Step', 'turf-stats' ); ?></th>
					<th><?php esc_html_e( 'Visitors', 'turf-stats' ); ?></th>
					<th><?php esc_html_e( 'Of first step', 'turf-stats' ); ?></th>
					<th><?php esc_html_e( 'Of previous step', 'turf-stats' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $funnel['steps'] as $i => $step ) : ?>
					<tr>
						<td><code><?php echo esc_html( turf_funnel_step_label( $step ) ); ?></code></td>
						<td><?php echo esc_html( number_format_i18n( $counts[ $i ] ) ); ?></td>
						<td><?php echo esc_html( (int) round( $counts[ $i ] / $first * 100 ) . '%' ); ?></td>
						<td><?php echo esc_html( $i ? (int) round( $counts[ $i ] / max( 1, $counts[ $i - 1 ] ) * 100 ) . '%' : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	turf_render_funnels_form();
}

function turf_render_funnels_form() {
	$definition = get_option( 'turf_funnels_definition', '' );
	?>
	<details class="turf-funnels-edit" <?php echo '' === trim( $definition ) ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'Edit funnels', 'turf-stats' ); ?></summary>
		<form method="post">
			<?php wp_nonce_field( 'turf_funnels_settings', 'turf_funnels_nonce' ); ?>
			<p class="description">
				<?php esc_html_e( 'One funnel per line: a name, a colon, then the steps separated by ">". Steps: "click <key>" (a data-turf-click key), "view <path>" (a page on this site), "form <id>" or "form <plugin> <id>" (cf7 or gravityforms). Steps of one visitor count when each follows the previous within an hour. Clicks only count since they record the visitor (Turf Stats 1.30).', 'turf-stats' ); ?>
			</p>
			<textarea name="turf_funnels_definition" rows="4" class="large-text code" placeholder="Tickets: click event-landing-ticket-hero > view /boeken/ > form cf7 12"><?php echo esc_textarea( $definition ); ?></textarea>
			<?php submit_button( __( 'Save funnels', 'turf-stats' ), 'secondary' ); ?>
		</form>
	</details>
	<?php
}

/**
 * Saves the funnel definition from the Clicks page. Lines that can't be read
 * are still saved (so nothing typed gets lost) but reported back.
 */
function turf_funnels_maybe_save() {
	if ( ! isset( $_POST['turf_funnels_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_key( $_POST['turf_funnels_nonce'] ), 'turf_funnels_settings' ) ) {
		return;
	}

	$definition = isset( $_POST['turf_funnels_definition'] ) ? sanitize_textarea_field( wp_unslash( $_POST['turf_funnels_definition'] ) ) : '';
	update_option( 'turf_funnels_definition', $definition, false );

	$errors = turf_parse_funnels( $definition )['errors'];

	add_action( 'admin_notices', function () use ( $errors ) {
		if ( $errors ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Funnels saved, but some lines were skipped:', 'turf-stats' ) . '</p><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Funnels saved.', 'turf-stats' ) . '</p></div>';
	} );
}
add_action( 'admin_init', 'turf_funnels_maybe_save' );
