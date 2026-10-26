<?php
/**
 * WP-CLI command for the legacy-views import - see includes/legacy-import.php
 * for the shared logic also used by the "Import" admin page.
 *
 * Usage:
 *   wp turf-stats import-legacy-views --source=jetpack
 *   wp turf-stats import-legacy-views --source=entry-views
 *   wp turf-stats import-legacy-views --source=all [--force] [--dry-run]
 *
 * --offset/--limit slice the post list, so a large --source=jetpack import
 * (one remote call per post beyond the top-posts pre-pass - see
 * turf_legacy_import_jetpack_top_posts()) can be manually sharded across
 * several parallel terminal invocations for extra throughput, e.g.:
 *   wp turf-stats import-legacy-views --source=jetpack --offset=0    --limit=5000 &
 *   wp turf-stats import-legacy-views --source=jetpack --offset=5000 --limit=5000 &
 *   wp turf-stats import-legacy-views --source=jetpack --offset=10000               &
 *
 * Keep the shard count modest (2-3): each shard multiplies the request rate
 * against Jetpack's API, and a throttled stats_get_csv() call returns empty -
 * indistinguishable from "post has no views" - so over-parallelizing silently
 * imports nothing for the throttled posts instead of failing loudly.
 *
 * Behavior-based bot flagging (see includes/suspect.php) over existing
 * history - review with --dry-run first, undo with unflag-bots:
 *   wp turf-stats flag-bots --dry-run
 *   wp turf-stats flag-bots [--from=2026-07-01] [--to=2026-10-25]
 *   wp turf-stats unflag-bots [--from=...] [--to=...]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Turf_CLI_Command {

	/**
	 * Import legacy view counts as the starting baseline for the new counter.
	 *
	 * @subcommand import-legacy-views
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Which legacy source to import from.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - jetpack
	 *   - entry-views
	 * ---
	 *
	 * [--force]
	 * : Overwrite posts that already have a value for the new counter.
	 *
	 * [--dry-run]
	 * : Report what would happen without writing anything.
	 *
	 * [--offset=<offset>]
	 * : Skip this many posts from the start of the eligible list - for
	 * manually sharding a large import across several parallel invocations.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Only process this many posts (after --offset). Omit for "the rest".
	 *
	 * @when after_wp_load
	 */
	public function import_legacy_views( $args, $assoc_args ) {
		$source  = WP_CLI\Utils\get_flag_value( $assoc_args, 'source', 'all' );
		$force   = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$offset  = WP_CLI\Utils\get_flag_value( $assoc_args, 'offset', 0 );
		$limit   = WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', null );

		// Reject rather than coerce: a negative offset would silently slice
		// from the END of the list (array_slice semantics) and a non-numeric
		// or zero limit would cast to 0 and "successfully" process nothing -
		// either way a sharding typo would look like a clean empty run.
		if ( ! is_numeric( $offset ) || (int) $offset < 0 ) {
			WP_CLI::error( '--offset must be zero or a positive integer.' );
		}

		if ( null !== $limit && ( ! is_numeric( $limit ) || (int) $limit <= 0 ) ) {
			WP_CLI::error( '--limit must be a positive integer.' );
		}

		$offset = (int) $offset;

		$sources  = 'all' === $source ? turf_legacy_import_sources() : array( $source );
		$post_ids = turf_legacy_import_post_ids();
		$post_ids = ( $offset > 0 || null !== $limit )
			? array_slice( $post_ids, $offset, null === $limit ? null : (int) $limit )
			: $post_ids;

		foreach ( $sources as $one_source ) {
			if ( 'jetpack' === $one_source && ! function_exists( 'stats_get_csv' ) ) {
				WP_CLI::warning( 'stats_get_csv() not available - Jetpack Stats must be active to import from Jetpack. Skipping.' );
				continue;
			}

			$remaining = $post_ids;
			$counts    = array( 'imported' => 0, 'skipped' => 0, 'empty' => 0 );

			// One bulk request covers Jetpack's top ~100 posts before the slow
			// per-post loop runs for the rest - see turf_legacy_import_jetpack_top_posts().
			if ( 'jetpack' === $one_source ) {
				$prepass   = turf_legacy_import_jetpack_top_posts( $remaining, $force, $dry_run );
				$counts    = $prepass['counts'];
				$remaining = array_values( array_diff( $remaining, $prepass['handled_post_ids'] ) );

				if ( $prepass['handled_post_ids'] ) {
					WP_CLI::log( sprintf( '[jetpack] top-posts pre-pass covered %d post(s) in a single request.', count( $prepass['handled_post_ids'] ) ) );
				}
			}

			$batch_counts = turf_legacy_import_batch( $remaining, $one_source, $force, $dry_run );
			foreach ( $batch_counts as $key => $value ) {
				$counts[ $key ] += $value;
			}

			WP_CLI::log( sprintf(
				'[%s] imported: %d, skipped (already had a value): %d, no/zero legacy views: %d%s',
				$one_source,
				$counts['imported'],
				$counts['skipped'],
				$counts['empty'],
				$dry_run ? ' (dry run, nothing written)' : ''
			) );
		}
	}

	/**
	 * Run the daily "many views, no engagement" bot check over past days.
	 *
	 * Flags matching views (excluded from every report) and subtracts them
	 * from the posts' view counts. Days without a single engagement signal
	 * site-wide are skipped. Undo with `wp turf-stats unflag-bots`.
	 *
	 * @subcommand flag-bots
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : First UTC day (YYYY-MM-DD). Defaults to the oldest recorded view.
	 *
	 * [--to=<date>]
	 * : Last UTC day (YYYY-MM-DD). Defaults to yesterday.
	 *
	 * [--dry-run]
	 * : Report what would be flagged, per country and browser, without
	 * changing anything.
	 *
	 * @when after_wp_load
	 */
	public function flag_bots( $args, $assoc_args ) {
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		list( $from, $to ) = $this->day_range( $assoc_args );

		$per_group = array();
		$visitors  = 0;
		$views     = 0;
		$skipped   = array();

		for ( $day = $from; $day <= $to; $day = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) ) {
			$result = turf_flag_suspect_visitors( $day . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) ) . ' 00:00:00', $dry_run );

			if ( $result['skipped'] ) {
				$skipped[] = $day;
				continue;
			}

			$visitors += $result['visitors'];
			$views    += $result['views'];

			foreach ( $result['rows'] as $row ) {
				$group = ( '' === $row->country ? '?' : $row->country ) . ' · ' . $row->browser . ' / ' . $row->os;

				if ( ! isset( $per_group[ $group ] ) ) {
					$per_group[ $group ] = array( 'group' => $group, 'visitor-days' => 0, 'views' => 0 );
				}

				++$per_group[ $group ]['visitor-days'];
				$per_group[ $group ]['views'] += (int) $row->views;
			}
		}

		if ( $skipped ) {
			WP_CLI::log( sprintf( 'Skipped %d day(s) without any engagement data (%s .. %s).', count( $skipped ), reset( $skipped ), end( $skipped ) ) );
		}

		if ( $per_group ) {
			usort( $per_group, function ( $a, $b ) {
				return $b['views'] - $a['views'];
			} );
			WP_CLI\Utils\format_items( 'table', $per_group, array( 'group', 'visitor-days', 'views' ) );
		}

		// visitor-days, not visitors: the same bot on 5 days counts 5 times.
		WP_CLI::success( sprintf(
			'%s %d view(s) from %d visitor-day(s), %s .. %s.',
			$dry_run ? 'Would flag' : 'Flagged',
			$views,
			$visitors,
			$from,
			$to
		) );
	}

	/**
	 * Undo bot flagging: flagged views count as normal views again.
	 *
	 * Covers both kinds of flag (too fast, and no engagement) and adds the
	 * views back to the posts' view counts.
	 *
	 * @subcommand unflag-bots
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : First UTC day (YYYY-MM-DD). Defaults to the oldest recorded view.
	 *
	 * [--to=<date>]
	 * : Last UTC day (YYYY-MM-DD). Defaults to yesterday.
	 *
	 * @when after_wp_load
	 */
	public function unflag_bots( $args, $assoc_args ) {
		list( $from, $to ) = $this->day_range( $assoc_args );

		$count = turf_unflag_suspect_views( $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );

		WP_CLI::success( sprintf( 'Unflagged %d view(s), %s .. %s.', $count, $from, $to ) );
	}

	/**
	 * --from/--to as validated YYYY-MM-DD strings, defaulting to the oldest
	 * recorded view and yesterday (UTC).
	 */
	private function day_range( $assoc_args ) {
		global $wpdb;

		$from = WP_CLI\Utils\get_flag_value( $assoc_args, 'from', '' );
		$to   = WP_CLI\Utils\get_flag_value( $assoc_args, 'to', gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );

		if ( '' === $from ) {
			$oldest = $wpdb->get_var( 'SELECT MIN(viewed_at) FROM ' . turf_table() );

			if ( ! $oldest ) {
				WP_CLI::error( 'No views recorded yet.' );
			}

			$from = substr( $oldest, 0, 10 );
		}

		foreach ( array( '--from' => $from, '--to' => $to ) as $flag => $value ) {
			if ( ! turf_is_valid_ymd( $value ) ) {
				WP_CLI::error( "$flag must be a date in YYYY-MM-DD format." );
			}
		}

		if ( $from > $to ) {
			WP_CLI::error( '--from must not be after --to.' );
		}

		return array( $from, $to );
	}
}

WP_CLI::add_command( 'turf-stats', 'Turf_CLI_Command' );
