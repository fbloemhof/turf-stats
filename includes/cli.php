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
}

WP_CLI::add_command( 'turf-stats', 'Turf_CLI_Command' );
