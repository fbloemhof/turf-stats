<?php
/**
 * Shared one-time import logic for legacy view counts, so switching away
 * from Jetpack Stats / the old entry-views plugin doesn't lose historical
 * numbers. Used by both the WP-CLI command (includes/cli.php) and the
 * "Import" admin page (includes/import-admin.php) - the CLI runs this in one
 * pass with no time limit, the admin page runs it in small AJAX batches
 * since a web request does have one (the Jetpack source in particular is
 * slow on purpose, see turf_legacy_get_views() below).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TURF_LEGACY_ENTRY_VIEWS_META_KEY', 'Views' );

function turf_legacy_import_sources() {
	return array( 'jetpack', 'entry-views' );
}

/**
 * All post IDs eligible for import - every trackable, published post. Same
 * query for both the CLI (one pass) and the admin page (fetched once,
 * then processed client-side in batches).
 */
function turf_legacy_import_post_ids() {
	// Memoized - the admin page calls this once when enqueuing (to pass the
	// ID list to JS) and again while rendering the page itself.
	static $post_ids = null;

	if ( null !== $post_ids ) {
		return $post_ids;
	}

	$query = new WP_Query( array(
		'post_type'              => turf_trackable_post_types(),
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );

	$post_ids = $query->posts;

	return $post_ids;
}

/**
 * --source=jetpack requires Jetpack's Stats module to still be active (it
 * calls Jetpack's own stats_get_csv()), so it only works against an
 * environment where Jetpack is actually connected - typically production,
 * not a local/staging copy where Jetpack is deactivated. The 200ms sleep
 * after every call is deliberate, to avoid hammering Jetpack's API.
 *
 * --source=entry-views just reads the old entry-views plugin's postmeta
 * ('Views'), which is still in the database even though that plugin is
 * deactivated, so it can run anywhere - and is effectively instant.
 */
function turf_legacy_get_views( $post_id, $source ) {
	if ( 'jetpack' === $source ) {
		if ( ! function_exists( 'stats_get_csv' ) ) {
			return null;
		}

		$result = stats_get_csv( 'postviews', array(
			'post_id' => $post_id,
			'days'    => -1,
			'limit'   => 1,
		) );

		usleep( 200000 );

		return ( ! empty( $result ) && isset( $result[0]['views'] ) ) ? (int) $result[0]['views'] : null;
	}

	if ( 'entry-views' === $source ) {
		$views = get_post_meta( $post_id, TURF_LEGACY_ENTRY_VIEWS_META_KEY, true );
		return '' === $views ? null : (int) $views;
	}

	return null;
}

/**
 * Imports one post from one source, returning which bucket it landed in
 * so the caller (CLI or AJAX batch handler) can tally totals the same way.
 *
 * @return string 'imported' | 'skipped' | 'empty'
 */
function turf_legacy_import_post( $post_id, $source, $force, $dry_run ) {
	$has_existing = '' !== get_post_meta( $post_id, TURF_META_KEY, true );

	if ( $has_existing && ! $force ) {
		return 'skipped';
	}

	$views = turf_legacy_get_views( $post_id, $source );

	if ( null === $views || $views <= 0 ) {
		return 'empty';
	}

	if ( ! $dry_run ) {
		update_post_meta( $post_id, TURF_META_KEY, $views );
	}

	return 'imported';
}

/**
 * Runs turf_legacy_import_post() over a whole list of post IDs, tallying
 * the result - the one-pass shape the CLI command uses directly, and each
 * AJAX batch from the admin page uses per chunk of post IDs.
 *
 * @return array{imported: int, skipped: int, empty: int}
 */
function turf_legacy_import_batch( array $post_ids, $source, $force, $dry_run ) {
	$counts = array(
		'imported' => 0,
		'skipped'  => 0,
		'empty'    => 0,
	);

	foreach ( $post_ids as $post_id ) {
		$counts[ turf_legacy_import_post( $post_id, $source, $force, $dry_run ) ]++;
	}

	return $counts;
}
