<?php
/**
 * "Analyse" submenu page - the deeper, more diagnostic stats (search
 * behaviour, navigation routes, trends, conversions) kept separate from
 * the main Statistieken page so that page stays focused on the core
 * audience picture (who, how many, when). Peak hours stays on Statistieken
 * (it's about *when*, same as the rest of that page) - everything else
 * added in v1.8.0/v1.9.0 lives here instead.
 */

function turf_analyse_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( 'Analyse', 'turf-stats' ),
		__( 'Analyse', 'turf-stats' ),
		'manage_options',
		'turf-analyse',
		'turf_analyse_render_admin_page'
	);

	add_action( "load-$hook", 'turf_analyse_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_analyse_admin_menu' );

function turf_analyse_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	add_meta_box( 'turf_search_terms', __( 'Zoekwoorden', 'turf-stats' ), function () use ( $days ) {
		turf_search_render_top_terms( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_search_zero_results', __( 'Zoekopdrachten zonder resultaat', 'turf-stats' ), function () use ( $days ) {
		turf_search_render_zero_results( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_session_routes', __( 'Bezoekersroutes', 'turf-stats' ), function () use ( $days ) {
		turf_render_session_routes( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_trending', __( 'Trending', 'turf-stats' ), function () {
		turf_render_trending();
	}, $hook, 'normal' );

	add_meta_box( 'turf_authors', __( 'Per auteur', 'turf-stats' ), function () use ( $days ) {
		turf_render_author_breakdown( $days );
	}, $hook, 'normal' );

	add_meta_box( 'turf_forms', __( 'Formulieren', 'turf-stats' ), function () use ( $days ) {
		turf_forms_render_top_forms( $days );
	}, $hook, 'normal' );

	if ( turf_woo_active() ) {
		add_meta_box( 'turf_woo_funnel', __( 'WooCommerce-funnel', 'turf-stats' ), function () use ( $days ) {
			turf_woo_render_funnel( $days );
		}, $hook, 'normal' );
	}
}

function turf_analyse_render_admin_page() {
	turf_admin_inline_style();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Analyse', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Diepere analyse naast de hoofdcijfers op Statistieken: zoekgedrag, navigatieroutes, trends en conversies.', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-analyse' ) ); ?>

		<?php turf_render_postboxes( get_current_screen()->id ); ?>
	</div>
	<?php
}
