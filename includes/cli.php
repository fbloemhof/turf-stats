<?php
/**
 * WP-CLI command for the legacy-views import - see includes/legacy-import.php
 * for the shared logic also used by the "Import" admin page.
 *
 * Usage:
 *   wp turf-stats import-legacy-views --source=jetpack
 *   wp turf-stats import-legacy-views --source=entry-views
 *   wp turf-stats import-legacy-views --source=all [--force] [--dry-run]
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
	 * @when after_wp_load
	 */
	public function import_legacy_views( $args, $assoc_args ) {
		$source  = WP_CLI\Utils\get_flag_value( $assoc_args, 'source', 'all' );
		$force   = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$sources = 'all' === $source ? turf_legacy_import_sources() : array( $source );
		$post_ids = turf_legacy_import_post_ids();

		foreach ( $sources as $one_source ) {
			if ( 'jetpack' === $one_source && ! function_exists( 'stats_get_csv' ) ) {
				WP_CLI::warning( 'stats_get_csv() not available - Jetpack Stats must be active to import from Jetpack. Skipping.' );
				continue;
			}

			$counts = turf_legacy_import_batch( $post_ids, $one_source, $force, $dry_run );

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
