<?php
/**
 * "Import" submenu page - a UI on top of the same legacy-views import as
 * `wp turf-stats import-legacy-views` (see includes/legacy-import.php).
 *
 * Runs as small AJAX batches rather than one request, because the Jetpack
 * source is slow on purpose (a network call plus a 200ms sleep per post, see
 * turf_legacy_get_views()) - a site with a few hundred posts would blow past
 * a typical hosting PHP time limit in a single synchronous request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function turf_import_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Import', 'turf-stats' ),
		__( 'Import', 'turf-stats' ),
		'manage_options',
		'turf-import',
		'turf_import_render_admin_page'
	);

	add_action( 'admin_enqueue_scripts', function ( $current_hook ) use ( $hook ) {
		if ( $current_hook === $hook ) {
			turf_import_enqueue();
		}
	} );
}
add_action( 'admin_menu', 'turf_import_admin_menu' );

function turf_import_enqueue() {
	wp_enqueue_script(
		'turf-import-legacy-views',
		TURF_URL . 'js/import-legacy-views.js',
		array(),
		TURF_VERSION,
		true
	);

	wp_localize_script( 'turf-import-legacy-views', 'turfImportLegacy', array(
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( 'turf_import_legacy' ),
		'postIds'       => turf_legacy_import_post_ids(),
		'jetpackActive' => function_exists( 'stats_get_csv' ),
		'i18n'          => array(
			/* translators: 1: source label (e.g. "jetpack"), 2: number of posts processed so far, 3: total number of posts */
			'progress' => __( '%1$s: %2$d / %3$d posts…', 'turf-stats' ),
			/* translators: 1: source label, 2: number imported, 3: number skipped, 4: number with no legacy views */
			'result'   => __( '%1$s — imported: %2$d, skipped (already had a value): %3$d, no/zero legacy views: %4$d', 'turf-stats' ),
			'dryRun'   => __( ' (dry run, nothing written)', 'turf-stats' ),
			'done'     => __( 'Done.', 'turf-stats' ),
			'running'  => __( 'Importing…', 'turf-stats' ),
			'error'    => __( 'Something went wrong - check the browser console and try again.', 'turf-stats' ),
		),
	) );
}

/**
 * Handles one batch (a chunk of post IDs for one source) from the admin
 * page's JS. Mirrors turf_legacy_import_batch()'s tally so results add up
 * exactly the same way the WP-CLI command reports them.
 */
function turf_ajax_import_legacy_batch() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'turf_import_legacy', 'nonce', false ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$source  = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
	$force   = ! empty( $_POST['force'] );
	$dry_run = ! empty( $_POST['dry_run'] );
	$post_ids = array_map( 'absint', (array) ( $_POST['post_ids'] ?? array() ) );
	$post_ids = array_filter( $post_ids );

	if ( ! in_array( $source, turf_legacy_import_sources(), true ) || ! $post_ids ) {
		wp_send_json_error( 'bad request', 400 );
	}

	if ( 'jetpack' === $source && ! function_exists( 'stats_get_csv' ) ) {
		wp_send_json_error( 'jetpack stats not active', 400 );
	}

	wp_send_json_success( turf_legacy_import_batch( $post_ids, $source, $force, $dry_run ) );
}
add_action( 'wp_ajax_turf_import_legacy_batch', 'turf_ajax_import_legacy_batch' );

/**
 * One-shot "top posts" pre-pass for --source=jetpack - see
 * turf_legacy_import_jetpack_top_posts() (includes/legacy-import.php). Runs
 * once before the JS starts its normal per-post batches, over the whole
 * eligible post list (not a client-supplied chunk - there's only ever one of
 * these per import run), so it returns both the tally and which post IDs it
 * already handled, letting the JS exclude them from the slower batches that
 * follow instead of fetching them twice.
 */
function turf_ajax_import_legacy_bulk_prepass() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'turf_import_legacy', 'nonce', false ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$force   = ! empty( $_POST['force'] );
	$dry_run = ! empty( $_POST['dry_run'] );

	if ( ! function_exists( 'stats_get_csv' ) ) {
		wp_send_json_error( 'jetpack stats not active', 400 );
	}

	$result = turf_legacy_import_jetpack_top_posts( turf_legacy_import_post_ids(), $force, $dry_run );

	wp_send_json_success( array(
		'counts'          => $result['counts'],
		'handledPostIds'  => $result['handled_post_ids'],
	) );
}
add_action( 'wp_ajax_turf_import_legacy_bulk_prepass', 'turf_ajax_import_legacy_bulk_prepass' );

function turf_import_render_admin_page() {
	$jetpack_active = function_exists( 'stats_get_csv' );
	$post_count     = count( turf_legacy_import_post_ids() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Import legacy views', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'One-time import of historical view counts from Jetpack Stats and/or the old entry-views plugin, so switching to Turf doesn\'t lose them. Imported numbers have no timestamp or visitor data, so they only ever show up in the "Views" total under "All" - never in the 7/30/90-day windows or in "Visitors".', 'turf-stats' ); ?>
		</p>

		<?php if ( ! $jetpack_active ) : ?>
			<p class="description">
				<?php esc_html_e( 'Jetpack Stats isn\'t active on this site, so the "Jetpack" source will be skipped if selected. It only works where Jetpack is actually connected - typically production, not a local/staging copy.', 'turf-stats' ); ?>
			</p>
		<?php endif; ?>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of posts */
				esc_html__( '%d published post(s) are eligible for import.', 'turf-stats' ),
				(int) $post_count
			);
			?>
		</p>

		<form id="turf-import-form" onsubmit="return false;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="turf-import-source"><?php esc_html_e( 'Import source', 'turf-stats' ); ?></label></th>
					<td>
						<select id="turf-import-source" name="source">
							<option value="all"><?php esc_html_e( 'All', 'turf-stats' ); ?></option>
							<option value="jetpack"><?php esc_html_e( 'Jetpack Stats', 'turf-stats' ); ?></option>
							<option value="entry-views"><?php esc_html_e( 'entry-views plugin', 'turf-stats' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'turf-stats' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="turf-import-force" />
							<?php esc_html_e( 'Overwrite posts that already have a value for the new counter', 'turf-stats' ); ?>
						</label>
						<br />
						<label>
							<input type="checkbox" id="turf-import-dry-run" />
							<?php esc_html_e( 'Dry run - report what would happen without writing anything', 'turf-stats' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="turf-import-start" class="button button-primary" <?php disabled( 0 === $post_count ); ?>>
					<?php esc_html_e( 'Start import', 'turf-stats' ); ?>
				</button>
			</p>
		</form>

		<div id="turf-import-progress" style="display:none;max-width:600px;">
			<progress id="turf-import-progress-bar" style="width:100%;"></progress>
			<p id="turf-import-progress-text"></p>
		</div>

		<div id="turf-import-results"></div>
	</div>
	<?php
}
