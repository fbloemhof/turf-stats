<?php
/**
 * "Bots & LLM's" submenu page - how often crawlers visit, which ones, and
 * what they look at. Deliberately separate from the main Statistieken
 * page/queries, since bot data has no meaningful "unique visitors"
 * dimension the way human traffic does.
 */

function turf_bots_admin_menu() {
	$hook = add_submenu_page(
		'turf-stats',
		__( "Bots & LLM's", 'turf-stats' ),
		__( "Bots & LLM's", 'turf-stats' ),
		'manage_options',
		'turf-bots',
		'turf_bots_render_admin_page'
	);

	add_action( "load-$hook", 'turf_bots_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_bots_admin_menu' );

function turf_bots_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days();

	add_meta_box( 'turf_bots_overview', __( 'Overzicht', 'turf-stats' ), function () use ( $days ) {
		turf_bots_render_overview( $days );
	}, $hook, 'turf_bots_overview' );

	add_meta_box( 'turf_bots_category', __( 'Categorie', 'turf-stats' ), function () use ( $days ) {
		turf_bots_render_simple_breakdown( turf_bots_get_category_breakdown( $days ), 'turf_bots_category_label' );
	}, $hook, 'turf_bots_compact' );

	add_meta_box( 'turf_bots_specific', __( 'Specifieke bots', 'turf-stats' ), function () use ( $days ) {
		turf_bots_render_simple_breakdown( turf_bots_get_top_bots( $days ), function ( $raw ) {
			return $raw;
		} );
	}, $hook, 'turf_bots_compact' );

	add_meta_box( 'turf_bots_pages', __( "Meest gecrawlde pagina's", 'turf-stats' ), function () use ( $days ) {
		turf_bots_render_top_crawled_pages( $days );
	}, $hook, 'turf_bots_wide' );
}

function turf_bots_category_label( $category ) {
	$labels = array(
		'llm'    => __( "LLM's / AI", 'turf-stats' ),
		'search' => __( 'Zoekmachines', 'turf-stats' ),
		'social' => __( 'Social media', 'turf-stats' ),
		'seo'    => __( 'SEO-tools', 'turf-stats' ),
		'other'  => __( 'Overige bots', 'turf-stats' ),
	);

	return $labels[ $category ] ?? $category;
}

function turf_bots_get_range_totals( $days, $offset_days = 0 ) {
	global $wpdb;
	$table = turf_bots_table();

	if ( 0 === $days ) {
		$hits = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$pages = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM $table" )
			+ (int) $wpdb->get_var( "SELECT COUNT(DISTINCT term_id) FROM $table" );

		return array( 'hits' => $hits, 'pages' => $pages );
	}

	if ( TURF_PERIOD_TODAY === $days ) {
		$end   = ( 0 === $offset_days ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d 00:00:00' );
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$offset_days} days" ) );
	} else {
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );
		$start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $offset_days + $days ) . ' days' ) );
	}

	$hits = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $table WHERE visited_at >= %s AND visited_at < %s",
		$start,
		$end
	) );

	$pages = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT post_id) FROM $table WHERE visited_at >= %s AND visited_at < %s",
		$start,
		$end
	) ) + (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT term_id) FROM $table WHERE visited_at >= %s AND visited_at < %s",
		$start,
		$end
	) );

	return array( 'hits' => $hits, 'pages' => $pages );
}

function turf_bots_render_overview( $days ) {
	if ( 0 === $days ) {
		$totals = turf_bots_get_range_totals( 0 );
		?>
		<div class="bk-stats-overview__totals">
			<?php turf_render_stat_box( __( 'Bot-bezoeken', 'turf-stats' ), $totals['hits'], false ); ?>
			<?php turf_render_stat_box( __( "Pagina's gecrawld", 'turf-stats' ), $totals['pages'], false ); ?>
		</div>
		<?php
		return;
	}

	$current  = turf_bots_get_range_totals( $days, 0 );
	$previous = turf_bots_get_range_totals( $days, turf_previous_period_offset( $days ) );
	?>
	<div class="bk-stats-overview__totals">
		<?php turf_render_stat_box( __( 'Bot-bezoeken', 'turf-stats' ), $current['hits'], turf_pct_change( $current['hits'], $previous['hits'] ) ); ?>
		<?php turf_render_stat_box( __( "Pagina's gecrawld", 'turf-stats' ), $current['pages'], turf_pct_change( $current['pages'], $previous['pages'] ) ); ?>
	</div>
	<?php
}

function turf_bots_get_category_breakdown( $days ) {
	global $wpdb;
	$table = turf_bots_table();

	if ( 0 === $days ) {
		return $wpdb->get_results( "SELECT bot_category AS label, COUNT(*) AS total FROM $table GROUP BY bot_category ORDER BY total DESC" );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_category AS label, COUNT(*) AS total FROM $table WHERE visited_at >= %s GROUP BY bot_category ORDER BY total DESC",
		turf_period_start_sql_date( $days )
	) );
}

function turf_bots_get_top_bots( $days, $limit = 10 ) {
	global $wpdb;
	$table = turf_bots_table();

	if ( 0 === $days ) {
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT bot_name AS label, COUNT(*) AS total FROM $table GROUP BY bot_name ORDER BY total DESC LIMIT %d",
			$limit
		) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name AS label, COUNT(*) AS total FROM $table WHERE visited_at >= %s GROUP BY bot_name ORDER BY total DESC LIMIT %d",
		turf_period_start_sql_date( $days ),
		$limit
	) );
}

/**
 * Single-bar (not the dual views/visitors style) breakdown renderer - bot
 * data has no meaningful "unique visitors" dimension. The block's heading
 * comes from the metabox title it's rendered inside of.
 */
function turf_bots_render_simple_breakdown( $rows, $label_callback ) {
	$values = array_map( 'intval', wp_list_pluck( $rows, 'total' ) );
	$total  = array_sum( $values );
	$max    = $values ? max( 1, max( $values ) ) : 1;
	?>
	<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'Nog geen data voor deze periode.', 'turf-stats' ); ?></p>
	<?php else : ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$count = (int) $row->total;
			$pct   = $max ? (int) round( ( $count / $max ) * 100 ) : 0;
			$share = $total ? (int) round( ( $count / $total ) * 100 ) : 0;
			?>
			<?php
			$value_text = sprintf(
				/* translators: 1: number of hits, 2: percentage share of total */
				__( '%1$s keer (%2$d%%)', 'turf-stats' ),
				number_format_i18n( $count ),
				$share
			);
			?>
			<div class="bk-stats-bar-row" title="<?php echo esc_attr( $value_text ); ?>">
				<span class="bk-stats-bar-row__label"><?php echo esc_html( call_user_func( $label_callback, $row->label ) ); ?></span>
				<span class="bk-stats-bar-row__track">
					<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--views" style="width:<?php echo $pct; ?>%"></span>
				</span>
				<span class="bk-stats-bar-row__value"><?php echo esc_html( $value_text ); ?></span>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php
}

function turf_bots_count_crawled_pages( $days ) {
	global $wpdb;
	$table = turf_bots_table();

	$where_date = 0 !== $days ? $wpdb->prepare( 'AND visited_at >= %s', turf_period_start_sql_date( $days ) ) : '';

	$posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM $table WHERE post_id IS NOT NULL $where_date" );
	$terms = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT term_id) FROM $table WHERE term_id IS NOT NULL $where_date" );

	return $posts + $terms;
}

function turf_bots_get_top_crawled_pages( $days, $page = 1 ) {
	global $wpdb;
	$table  = turf_bots_table();
	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	$where_date = 0 !== $days ? $wpdb->prepare( 'AND visited_at >= %s', turf_period_start_sql_date( $days ) ) : '';

	return $wpdb->get_results( $wpdb->prepare(
		"(SELECT 'post' AS kind, post_id AS object_id, COUNT(*) AS hits FROM $table WHERE post_id IS NOT NULL $where_date GROUP BY post_id)
		UNION ALL
		(SELECT 'term' AS kind, term_id AS object_id, COUNT(*) AS hits FROM $table WHERE term_id IS NOT NULL $where_date GROUP BY term_id)
		ORDER BY hits DESC
		LIMIT %d OFFSET %d",
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_bots_render_top_crawled_pages( $days ) {
	$param          = 'pg_bots';
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;

	$total = turf_bots_count_crawled_pages( $days );

	if ( ! $total ) {
		echo '<p>' . esc_html__( 'Nog geen bot-bezoeken voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}

	$total_pages = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page        = min( $requested_page, $total_pages );
	$rows        = turf_bots_get_top_crawled_pages( $days, $page );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Titel', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Type', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Bot-bezoeken', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				if ( 'post' === $row->kind ) {
					$title = get_the_title( $row->object_id );
					$edit  = get_edit_post_link( $row->object_id );
					$type  = turf_get_post_type_label( (string) get_post_type( $row->object_id ) );
				} else {
					$term  = get_term( $row->object_id );
					$title = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $row->object_id;
					$link  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : false;
					$edit  = ( $link && ! is_wp_error( $link ) ) ? $link : false;
					$type  = ( $term && ! is_wp_error( $term ) ) ? turf_get_taxonomy_label( $term->taxonomy ) : '';
				}
				?>
				<tr>
					<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ?: '#' . $row->object_id ); ?></td>
					<td><?php echo esc_html( $type ); ?></td>
					<td><?php echo (int) $row->hits; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php turf_render_pagination( $param, $page, $total_pages ); ?>
		</div></div>
	<?php endif; ?>
	<?php
}

function turf_bots_render_admin_page() {
	turf_admin_inline_style();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( "Bots & LLM's", 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Crawlers en AI-bots draaien meestal geen JavaScript, dus dit telt apart, server-side - los van de gewone bezoekersstatistieken (die bots juist bewust uitsluiten).', 'turf-stats' ); ?>
		</p>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-bots' ) ); ?>

		<?php $hook = get_current_screen()->id; ?>
		<div id="poststuff">
			<?php turf_render_postbox_column( $hook, 'turf_bots_overview' ); ?>
			<?php turf_render_postbox_grid_column( $hook, 'turf_bots_compact' ); ?>
			<?php turf_render_postbox_column( $hook, 'turf_bots_wide' ); ?>
		</div>
	</div>
	<?php
}
