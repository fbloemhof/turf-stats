<?php
/**
 * One-time import of legacy view counts, so switching away from Jetpack
 * Stats / the old entry-views plugin doesn't lose historical numbers.
 *
 * Usage:
 *   wp turf-stats import-legacy-views --source=jetpack
 *   wp turf-stats import-legacy-views --source=entry-views
 *   wp turf-stats import-legacy-views --source=all [--force] [--dry-run]
 *
 * --source=jetpack requires Jetpack's Stats module to still be active (it
 * calls Jetpack's own stats_get_csv()), so it only works against an
 * environment where Jetpack is actually connected - typically production,
 * not a local/staging copy where Jetpack is deactivated.
 *
 * --source=entry-views just reads the old entry-views plugin's postmeta
 * ('Views'), which is still in the database even though that plugin is
 * deactivated, so it can run anywhere.
 */

define( 'TURF_LEGACY_ENTRY_VIEWS_META_KEY', 'Views' );

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

		if ( in_array( $source, array( 'all', 'jetpack' ), true ) ) {
			$this->import_jetpack( $force, $dry_run );
		}

		if ( in_array( $source, array( 'all', 'entry-views' ), true ) ) {
			$this->import_entry_views( $force, $dry_run );
		}
	}

	private function import_jetpack( $force, $dry_run ) {
		if ( ! function_exists( 'stats_get_csv' ) ) {
			WP_CLI::warning( 'stats_get_csv() not available - Jetpack Stats must be active to import from Jetpack. Skipping.' );
			return;
		}

		$this->import_from_callback( 'jetpack', turf_trackable_post_types(), $force, $dry_run, function ( $post_id ) {
			$result = stats_get_csv( 'postviews', array(
				'post_id' => $post_id,
				'days'    => -1,
				'limit'   => 1,
			) );

			usleep( 200000 );

			if ( ! empty( $result ) && isset( $result[0]['views'] ) ) {
				return (int) $result[0]['views'];
			}

			return null;
		} );
	}

	private function import_entry_views( $force, $dry_run ) {
		$this->import_from_callback( 'entry-views', turf_trackable_post_types(), $force, $dry_run, function ( $post_id ) {
			$views = get_post_meta( $post_id, TURF_LEGACY_ENTRY_VIEWS_META_KEY, true );
			return '' === $views ? null : (int) $views;
		} );
	}

	private function import_from_callback( $label, $post_types, $force, $dry_run, $get_legacy_views ) {
		$imported = 0;
		$skipped  = 0;
		$empty    = 0;

		$query = new WP_Query( array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		foreach ( $query->posts as $post_id ) {
			$has_existing = '' !== get_post_meta( $post_id, TURF_META_KEY, true );

			if ( $has_existing && ! $force ) {
				$skipped++;
				continue;
			}

			$views = $get_legacy_views( $post_id );

			if ( null === $views || $views <= 0 ) {
				$empty++;
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $post_id, TURF_META_KEY, $views );
			}

			$imported++;
		}

		WP_CLI::log( sprintf(
			'[%s] imported: %d, skipped (already had a value): %d, no/zero legacy views: %d%s',
			$label,
			$imported,
			$skipped,
			$empty,
			$dry_run ? ' (dry run, nothing written)' : ''
		) );
	}
}

WP_CLI::add_command( 'turf-stats', 'Turf_CLI_Command' );
