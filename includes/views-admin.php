<?php
/**
 * "Statistieken" admin page - site-wide overview (à la Jetpack) plus top
 * viewed posts/archive pages per period, paginated.
 *
 * Note: legacy views imported via the CLI command (see post-views-cli.php)
 * have no timestamp and no visitor data, so they only show up in the
 * "Weergaven" total under "Alles" - not in the 7/30/90-day windows, and
 * never in "Bezoekers" (unique visitors), which only exist for views
 * recorded since this plugin went live.
 */

define( 'TURF_PER_PAGE', 10 );

function turf_admin_menu() {
	add_menu_page(
		__( 'Statistieken', 'turf-stats' ),
		__( 'Statistieken', 'turf-stats' ),
		'manage_options',
		'turf-stats',
		'turf_render_admin_page',
		'dashicons-chart-bar',
		26
	);
}
add_action( 'admin_menu', 'turf_admin_menu' );

/**
 * Builds a `post_type IN (%s, %s, ...)` placeholder string plus the matching
 * args array, for queries that should span all trackable post types at once.
 */
function turf_post_type_in_clause() {
	$types        = turf_trackable_post_types();
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

	return array( $placeholders, $types );
}

/**
 * Site-wide queries need to count both post views and taxonomy-archive
 * views (post_id and term_id are mutually exclusive per row - see
 * includes/views.php). This builds the shared JOIN + WHERE that
 * restricts to currently-live, trackable content on both sides, so deleted
 * posts/terms or types that were later excluded via the filters don't linger
 * in the totals.
 *
 * @return array{0: string, 1: string, 2: array} [$join_sql, $where_sql, $params]
 */
function turf_site_join_and_where() {
	global $wpdb;

	list( $post_placeholders, $post_types ) = turf_post_type_in_clause();

	$taxonomies        = turf_trackable_taxonomies();
	$tax_placeholders  = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

	$join = "LEFT JOIN $wpdb->posts p ON p.ID = v.post_id
		LEFT JOIN $wpdb->term_taxonomy tt ON tt.term_id = v.term_id";

	$where = "(
		(v.post_id IS NOT NULL AND p.post_type IN ($post_placeholders) AND p.post_status = 'publish')
		OR (v.term_id IS NOT NULL AND tt.taxonomy IN ($tax_placeholders))
	)";

	return array( $join, $where, array_merge( $post_types, $taxonomies ) );
}

/**
 * Site-wide views + unique visitors for a single date range (UTC).
 *
 * @param int $days        Length of the range in days.
 * @param int $offset_days How many days ago the range ends (0 = ending now).
 */
function turf_get_range_site_totals( $days, $offset_days = 0 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );
	$start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $offset_days + $days ) . ' days' ) );

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where
		AND v.viewed_at >= %s AND v.viewed_at < %s",
		array_merge( $params, array( $start, $end ) )
	) );

	return array( 'views' => (int) $row->views, 'visitors' => (int) $row->visitors );
}

/**
 * Site-wide views + unique visitors per day for the last $days days, zero-filled.
 */
function turf_get_daily_site_totals( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(v.viewed_at) AS day, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where
		AND v.viewed_at >= %s
		GROUP BY DATE(v.viewed_at)",
		array_merge( $params, array( $start ) )
	), OBJECT_K );

	$daily = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
		$row  = isset( $results[ $date ] ) ? $results[ $date ] : null;

		$daily[] = array(
			'date'     => $date,
			'views'    => $row ? (int) $row->views : 0,
			'visitors' => $row ? (int) $row->visitors : 0,
		);
	}

	return $daily;
}

/**
 * All-time site totals: "Weergaven" includes the imported legacy baseline
 * (postmeta running total), "Bezoekers" only reflects views recorded since
 * this plugin went live (the event table has no visitor data for imports).
 */
function turf_get_alltime_site_totals() {
	global $wpdb;

	list( $placeholders, $post_types ) = turf_post_type_in_clause();

	$post_views = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(m.meta_value + 0) FROM $wpdb->posts p
		INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
		WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'",
		array_merge( array( TURF_META_KEY ), $post_types )
	) );

	$taxonomies       = turf_trackable_taxonomies();
	$tax_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

	$term_views = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(m.meta_value + 0) FROM $wpdb->term_taxonomy tt
		INNER JOIN $wpdb->termmeta m ON m.term_id = tt.term_id AND m.meta_key = %s
		WHERE tt.taxonomy IN ($tax_placeholders)",
		array_merge( array( TURF_META_KEY ), $taxonomies )
	) );

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$visitors = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.visitor_hash) FROM $table v $join WHERE $where",
		$params
	) );

	return array( 'views' => (int) $post_views + (int) $term_views, 'visitors' => (int) $visitors );
}

/**
 * Percentage change vs. a previous value. Null means "not computable"
 * (previous was 0) - rendered as "nieuw" rather than a bogus percentage.
 */
function turf_pct_change( $current, $previous ) {
	if ( $previous <= 0 ) {
		return $current > 0 ? null : 0;
	}

	return (int) round( ( ( $current - $previous ) / $previous ) * 100 );
}

function turf_render_change_badge( $change ) {
	if ( null === $change ) {
		echo '<span class="bk-stats-box__change bk-stats-box__change--new">' . esc_html__( 'nieuw', 'turf-stats' ) . '</span>';
		return;
	}

	$class     = $change >= 0 ? 'up' : 'down';
	$direction = $change >= 0 ? '&uarr;' : '&darr;';

	printf(
		'<span class="bk-stats-box__change bk-stats-box__change--%s">%s %s%%</span>',
		esc_attr( $class ),
		$direction,
		esc_html( abs( $change ) )
	);
}

function turf_render_stat_box( $label, $value, $change ) {
	?>
	<div class="bk-stats-box">
		<span class="bk-stats-box__label"><?php echo esc_html( $label ); ?></span>
		<span class="bk-stats-box__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
		<?php if ( false !== $change ) : ?>
			<?php turf_render_change_badge( $change ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Site-wide "Afgelopen N dagen" overview: totals with %-change vs. the
 * preceding period, plus a daily views/visitors bar chart (à la Jetpack).
 * For "Alles" there's no meaningful daily resolution, so just the totals.
 * Includes both post views and taxonomy-archive views.
 */
function turf_render_overview( $days ) {
	if ( 0 === $days ) {
		$totals = turf_get_alltime_site_totals();
		?>
		<div class="bk-stats-overview">
			<div class="bk-stats-overview__totals">
				<?php turf_render_stat_box( __( 'Weergaven', 'turf-stats' ), $totals['views'], false ); ?>
				<?php turf_render_stat_box( __( 'Bezoekers', 'turf-stats' ), $totals['visitors'], false ); ?>
			</div>
		</div>
		<?php
		return;
	}

	$daily    = turf_get_daily_site_totals( $days );
	$current  = turf_get_range_site_totals( $days, 0 );
	$previous = turf_get_range_site_totals( $days, $days );
	$max      = max( 1, max( array_column( $daily, 'views' ) ) );
	?>
	<div class="bk-stats-overview">
		<div class="bk-stats-overview__totals">
			<?php turf_render_stat_box( __( 'Weergaven', 'turf-stats' ), $current['views'], turf_pct_change( $current['views'], $previous['views'] ) ); ?>
			<?php turf_render_stat_box( __( 'Bezoekers', 'turf-stats' ), $current['visitors'], turf_pct_change( $current['visitors'], $previous['visitors'] ) ); ?>
		</div>

		<div class="bk-stats-overview__legend">
			<span class="bk-stats-legend bk-stats-legend--views"><?php esc_html_e( 'Weergaven', 'turf-stats' ); ?></span>
			<span class="bk-stats-legend bk-stats-legend--visitors"><?php esc_html_e( 'Bezoekers', 'turf-stats' ); ?></span>
		</div>

		<div class="bk-stats-chart">
			<?php foreach ( $daily as $day ) : ?>
				<?php
				$views_pct    = round( ( $day['views'] / $max ) * 100 );
				$visitors_pct = round( ( $day['visitors'] / $max ) * 100 );
				$title        = sprintf(
					/* translators: 1: date, 2: number of views, 3: number of visitors */
					__( '%1$s — %2$s weergaven, %3$s bezoekers', 'turf-stats' ),
					date_i18n( 'd M', strtotime( $day['date'] ) ),
					number_format_i18n( $day['views'] ),
					number_format_i18n( $day['visitors'] )
				);
				?>
				<div class="bk-stats-chart__col" title="<?php echo esc_attr( $title ); ?>">
					<div class="bk-stats-chart__bars">
						<div class="bk-stats-chart__bar bk-stats-chart__bar--views" style="height:<?php echo (int) $views_pct; ?>%"></div>
						<div class="bk-stats-chart__bar bk-stats-chart__bar--visitors" style="height:<?php echo (int) $visitors_pct; ?>%"></div>
					</div>
					<span class="bk-stats-chart__label"><?php echo esc_html( date_i18n( 'd M', strtotime( $day['date'] ) ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Site-wide breakdown by a single simple string column (device_type, browser,
 * os, language, country, utm_source, utm_medium) for the selected period.
 * Includes both post views and taxonomy-archive views (a visitor's device/
 * language doesn't depend on what kind of page they're looking at).
 *
 * @param string $column        Whitelisted column name - can't go through $wpdb->prepare().
 * @param bool   $exclude_empty Drop the '' bucket (e.g. for UTM columns, where
 *                               almost every row has no campaign and showing
 *                               that as a giant bucket isn't useful).
 */
function turf_get_breakdown( $column, $days, $exclude_empty = false ) {
	global $wpdb;

	$allowed = array( 'device_type', 'browser', 'os', 'language', 'country', 'utm_source', 'utm_medium' );

	if ( ! in_array( $column, $allowed, true ) ) {
		return array();
	}

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	$where_date = '';

	if ( $days > 0 ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
	}

	$where_empty = $exclude_empty ? "AND v.$column != ''" : '';

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.$column AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date $where_empty
		GROUP BY v.$column
		ORDER BY views DESC",
		$params
	) );
}

function turf_breakdown_label( $column, $raw ) {
	if ( 'country' === $column && '' === $raw ) {
		return __( 'Onbekend (geen Cloudflare-landdetectie of eigen GeoIP-koppeling)', 'turf-stats' );
	}

	if ( '' === $raw ) {
		return __( 'Onbekend (van vóór deze functie)', 'turf-stats' );
	}

	if ( 'device_type' === $column ) {
		$labels = array(
			'desktop' => __( 'Desktop', 'turf-stats' ),
			'mobile'  => __( 'Mobiel', 'turf-stats' ),
			'tablet'  => __( 'Tablet', 'turf-stats' ),
		);

		return $labels[ $raw ] ?? $raw;
	}

	if ( 'country' === $column ) {
		return turf_country_label( $raw );
	}

	if ( 'language' === $column ) {
		return turf_language_label( $raw );
	}

	return $raw;
}

/**
 * Small lookup for the country/language codes realistically expected for a
 * Frisian/Dutch local-news audience - falls back to the bare code for
 * anything else rather than maintaining an exhaustive list.
 */
function turf_country_label( $code ) {
	$labels = array(
		'NL' => __( 'Nederland', 'turf-stats' ),
		'BE' => __( 'België', 'turf-stats' ),
		'DE' => __( 'Duitsland', 'turf-stats' ),
		'GB' => __( 'Verenigd Koninkrijk', 'turf-stats' ),
		'US' => __( 'Verenigde Staten', 'turf-stats' ),
		'FR' => __( 'Frankrijk', 'turf-stats' ),
		'ES' => __( 'Spanje', 'turf-stats' ),
		'IT' => __( 'Italië', 'turf-stats' ),
		'PL' => __( 'Polen', 'turf-stats' ),
		'CA' => __( 'Canada', 'turf-stats' ),
	);

	return $labels[ $code ] ?? $code;
}

function turf_language_label( $code ) {
	$labels = array(
		'nl' => __( 'Nederlands', 'turf-stats' ),
		'fy' => __( 'Frysk', 'turf-stats' ),
		'en' => __( 'Engels', 'turf-stats' ),
		'de' => __( 'Duits', 'turf-stats' ),
		'fr' => __( 'Frans', 'turf-stats' ),
	);

	return $labels[ $code ] ?? $code;
}

/**
 * SQL CASE expression that buckets a referrer_host column into a traffic-
 * source label. Keep the substring lists in sync with the PHP equivalent,
 * turf_classify_referrer() - this exists separately so the
 * grouping/COUNT(DISTINCT ...) happens in SQL (matching how device_type and
 * browser breakdowns already work), not in PHP after the fact, where
 * per-bucket distinct-visitor counts can't be reconstructed correctly.
 */
function turf_referrer_case_sql( $column = 'v.referrer_host' ) {
	$site_host = esc_sql( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	$build_likes = function ( array $needles ) use ( $column ) {
		$conditions = array();
		foreach ( $needles as $needle ) {
			// %% (not %) - this string still goes through $wpdb->prepare() by
			// the caller for the date placeholder, which treats a lone % as
			// the start of its own placeholder.
			$conditions[] = "$column LIKE '%%" . esc_sql( $needle ) . "%%'";
		}
		return implode( ' OR ', $conditions );
	};

	$search_sql = $build_likes( array( 'google.', 'bing.', 'duckduckgo.', 'yahoo.', 'ecosia.', 'startpage.' ) );
	$social_sql = $build_likes( array( 'facebook.', 'instagram.', 'x.com', 'twitter.', 'linkedin.', 'pinterest.', 't.co', 'whatsapp.' ) );

	return "CASE
		WHEN $column = '' THEN 'direct'
		WHEN $column = '$site_host' THEN 'intern'
		WHEN $search_sql THEN 'zoekmachine'
		WHEN $social_sql THEN 'social'
		ELSE 'overig'
	END";
}

function turf_get_referrer_breakdown( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();
	$case = turf_referrer_case_sql( 'v.referrer_host' );

	$where_date = '';

	if ( $days > 0 ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT $case AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		GROUP BY label
		ORDER BY views DESC",
		$params
	) );
}

function turf_referrer_bucket_label( $bucket ) {
	$labels = array(
		'direct'      => __( 'Direct', 'turf-stats' ),
		'intern'      => __( 'Intern (eigen site)', 'turf-stats' ),
		'zoekmachine' => __( 'Zoekmachines', 'turf-stats' ),
		'social'      => __( 'Social media', 'turf-stats' ),
		'overig'      => __( 'Overig', 'turf-stats' ),
	);

	return $labels[ $bucket ] ?? $bucket;
}

/**
 * Top individual referring hostnames (excluding direct/own-site traffic), for
 * anyone who wants to know *which* search engine or site specifically, beyond
 * the bucketed breakdown above.
 */
function turf_get_top_referrer_hosts( $days, $limit = 10 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	$where_date = '';

	if ( $days > 0 ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
	}

	$params[] = $site_host;
	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.referrer_host AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		AND v.referrer_host != '' AND v.referrer_host != %s
		GROUP BY v.referrer_host
		ORDER BY views DESC
		LIMIT %d",
		$params
	) );
}

/**
 * Shared renderer for any "rows with views+visitors, stacked bar per row"
 * breakdown block (device, browser, referrer bucket, top referrer hosts).
 *
 * @param string   $title          Block heading.
 * @param object[] $rows           Each with ->label, ->views, ->visitors.
 * @param callable $label_callback Maps a raw $row->label to a display label.
 */
function turf_render_breakdown_rows( $title, $rows, $label_callback ) {
	$views_list  = array_map( 'intval', wp_list_pluck( $rows, 'views' ) );
	$total_views = array_sum( $views_list );
	$max_views   = $views_list ? max( 1, max( $views_list ) ) : 1;
	?>
	<div class="bk-stats-breakdown">
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Nog geen data voor deze periode.', 'turf-stats' ); ?></p>
		<?php else : ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$views        = (int) $row->views;
				$visitors     = (int) $row->visitors;
				$views_pct    = (int) round( ( $views / $max_views ) * 100 );
				$visitors_pct = (int) round( ( $visitors / $max_views ) * 100 );
				$share        = $total_views ? (int) round( ( $views / $total_views ) * 100 ) : 0;
				?>
				<div class="bk-stats-bar-row">
					<span class="bk-stats-bar-row__label"><?php echo esc_html( call_user_func( $label_callback, $row->label ) ); ?></span>
					<span class="bk-stats-bar-row__track">
						<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--views" style="width:<?php echo $views_pct; ?>%"></span>
						<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--visitors" style="width:<?php echo $visitors_pct; ?>%"></span>
					</span>
					<span class="bk-stats-bar-row__value">
						<?php echo esc_html( sprintf(
							/* translators: 1: number of views, 2: percentage share of total views, 3: number of unique visitors */
							__( '%1$s weergaven (%2$d%%) · %3$s bezoekers', 'turf-stats' ),
							number_format_i18n( $views ),
							$share,
							number_format_i18n( $visitors )
						) ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}

function turf_render_breakdown( $title, $column, $days, $exclude_empty = false ) {
	$rows = turf_get_breakdown( $column, $days, $exclude_empty );

	turf_render_breakdown_rows( $title, $rows, function ( $raw ) use ( $column ) {
		return turf_breakdown_label( $column, $raw );
	} );
}

function turf_render_referrer_breakdown( $days ) {
	$rows = turf_get_referrer_breakdown( $days );

	turf_render_breakdown_rows( __( 'Herkomst', 'turf-stats' ), $rows, 'turf_referrer_bucket_label' );
}

function turf_render_top_referrer_hosts( $days ) {
	$rows = turf_get_top_referrer_hosts( $days );

	turf_render_breakdown_rows( __( 'Top verwijzende sites', 'turf-stats' ), $rows, function ( $raw ) {
		return $raw;
	} );
}

/**
 * New vs. returning visitors for the period: "returning" means that
 * visitor_hash has at least one row from before the period started. Already
 * possible with the existing hash, just not surfaced anywhere until now.
 */
function turf_get_new_vs_returning( $days ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	if ( $days > 0 ) {
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );
		$end   = current_time( 'mysql', true );
	} else {
		$start = '1970-01-01 00:00:00';
		$end   = current_time( 'mysql', true );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT
			COUNT(DISTINCT CASE WHEN earlier.visitor_hash IS NULL THEN v.visitor_hash END) AS new_visitors,
			COUNT(DISTINCT CASE WHEN earlier.visitor_hash IS NOT NULL THEN v.visitor_hash END) AS returning_visitors
		FROM $table v
		$join
		LEFT JOIN $table earlier ON earlier.visitor_hash = v.visitor_hash AND earlier.viewed_at < %s
		WHERE $where AND v.viewed_at >= %s AND v.viewed_at < %s",
		array_merge( array( $start ), $params, array( $start, $end ) )
	) );

	$new       = $row ? (int) $row->new_visitors : 0;
	$returning = $row ? (int) $row->returning_visitors : 0;

	return array(
		(object) array( 'label' => 'nieuw', 'views' => $new, 'visitors' => $new ),
		(object) array( 'label' => 'terugkerend', 'views' => $returning, 'visitors' => $returning ),
	);
}

function turf_render_new_vs_returning( $days ) {
	$rows = turf_get_new_vs_returning( $days );

	turf_render_breakdown_rows( __( 'Nieuw vs. terugkerend', 'turf-stats' ), $rows, function ( $raw ) {
		return 'nieuw' === $raw ? __( 'Nieuwe bezoekers', 'turf-stats' ) : __( 'Terugkerende bezoekers', 'turf-stats' );
	} );
}

function turf_admin_inline_style() {
	?>
	<style>
		.bk-stats-overview { margin: 20px 0 30px; }
		.bk-stats-overview__totals { display: flex; gap: 16px; margin-bottom: 16px; }
		.bk-stats-box { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; min-width: 140px; }
		.bk-stats-box__label { display: block; color: #646970; font-size: 13px; }
		.bk-stats-box__value { display: block; font-size: 24px; font-weight: 600; margin: 4px 0; }
		.bk-stats-box__change { font-size: 12px; font-weight: 600; }
		.bk-stats-box__change--up { color: #2e7d4f; }
		.bk-stats-box__change--down { color: #d63638; }
		.bk-stats-box__change--new { color: #646970; }
		.bk-stats-overview__legend { display: flex; gap: 16px; margin-bottom: 8px; font-size: 12px; color: #646970; }
		.bk-stats-legend::before { content: ""; display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
		.bk-stats-legend--views::before { background: #a8d5b5; }
		.bk-stats-legend--visitors::before { background: #2e7d4f; }
		.bk-stats-chart { display: flex; align-items: flex-end; gap: 8px; height: 200px; padding: 10px 0; border-bottom: 1px solid #dcdcde; }
		.bk-stats-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
		.bk-stats-chart__bars { position: relative; width: 100%; max-width: 36px; height: 160px; }
		.bk-stats-chart__bar { position: absolute; bottom: 0; left: 0; width: 100%; border-radius: 2px 2px 0 0; }
		.bk-stats-chart__bar--views { background: #a8d5b5; }
		.bk-stats-chart__bar--visitors { background: #2e7d4f; }
		.bk-stats-chart__label { margin-top: 6px; font-size: 11px; color: #646970; }
		.bk-stats-breakdowns { display: flex; gap: 24px; margin-bottom: 24px; flex-wrap: wrap; }
		.bk-stats-breakdown { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; flex: 1; min-width: 260px; }
		.bk-stats-breakdown h3 { margin-top: 0; }
		.bk-stats-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: 13px; }
		.bk-stats-bar-row__label { width: 110px; flex-shrink: 0; }
		.bk-stats-bar-row__track { position: relative; flex: 1; background: #f0f0f1; border-radius: 3px; height: 10px; overflow: hidden; }
		.bk-stats-bar-row__fill { position: absolute; top: 0; left: 0; height: 100%; border-radius: 3px; }
		.bk-stats-bar-row__fill--views { background: #a8d5b5; }
		.bk-stats-bar-row__fill--visitors { background: #2e7d4f; }
		.bk-stats-bar-row__value { width: 210px; flex-shrink: 0; text-align: right; color: #646970; }

		@media (max-width: 600px) {
			.bk-stats-overview__totals { flex-wrap: wrap; }
			.bk-stats-box { flex: 1 1 auto; }
			.bk-stats-bar-row { flex-wrap: wrap; }
			.bk-stats-bar-row__label { width: 100%; }
			.bk-stats-bar-row__track { flex-basis: 100%; order: 2; }
			.bk-stats-bar-row__value { width: 100%; order: 3; text-align: left; margin-top: 2px; }
		}
	</style>
	<?php
}

function turf_render_admin_page() {
	$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '7';
	$days   = array( '7' => 7, '30' => 30, '90' => 90, 'all' => 0 );
	$days   = isset( $days[ $period ] ) ? $days[ $period ] : 7;

	$base_url = admin_url( 'admin.php?page=turf-stats' );

	$post_types = turf_trackable_post_types();
	usort( $post_types, function ( $a, $b ) {
		return strnatcasecmp( turf_get_post_type_label( $a ), turf_get_post_type_label( $b ) );
	} );

	$taxonomies = turf_trackable_taxonomies();
	usort( $taxonomies, function ( $a, $b ) {
		return strnatcasecmp( turf_get_taxonomy_label( $a ), turf_get_taxonomy_label( $b ) );
	} );

	turf_admin_inline_style();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Statistieken', 'turf-stats' ); ?></h1>

		<ul class="subsubsub">
			<?php foreach ( array( '7' => '7 dagen', '30' => '30 dagen', '90' => '90 dagen', 'all' => 'Alles' ) as $key => $label ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'period', $key, $base_url ) ); ?>" <?php echo $period === (string) $key ? 'class="current"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a> |
				</li>
			<?php endforeach; ?>
		</ul>
		<br class="clear" />

		<?php turf_render_overview( $days ); ?>

		<div class="bk-stats-breakdowns">
			<?php turf_render_breakdown( __( 'Apparaat', 'turf-stats' ), 'device_type', $days ); ?>
			<?php turf_render_breakdown( __( 'Browser', 'turf-stats' ), 'browser', $days ); ?>
		</div>

		<div class="bk-stats-breakdowns">
			<?php turf_render_breakdown( __( 'Besturingssysteem', 'turf-stats' ), 'os', $days ); ?>
			<?php turf_render_breakdown( __( 'Taal', 'turf-stats' ), 'language', $days ); ?>
		</div>

		<div class="bk-stats-breakdowns">
			<?php turf_render_breakdown( __( 'Land van herkomst', 'turf-stats' ), 'country', $days ); ?>
			<?php turf_render_new_vs_returning( $days ); ?>
		</div>

		<div class="bk-stats-breakdowns">
			<?php turf_render_referrer_breakdown( $days ); ?>
			<?php turf_render_top_referrer_hosts( $days ); ?>
		</div>

		<div class="bk-stats-breakdowns">
			<?php turf_render_breakdown( __( 'Campagnebron (UTM)', 'turf-stats' ), 'utm_source', $days, true ); ?>
			<?php turf_render_breakdown( __( 'Campagnemedium (UTM)', 'turf-stats' ), 'utm_medium', $days, true ); ?>
		</div>

		<?php if ( 0 !== $days ) : ?>
			<p class="description">
				<?php esc_html_e( 'Geïmporteerde historische views (van vóór deze plugin) hebben geen datum en geen bezoekers-/apparaat-/herkomstgegevens. Ze tellen alleen mee bij "Alles" en alleen bij "Weergaven".', 'turf-stats' ); ?>
			</p>
		<?php endif; ?>

		<?php foreach ( $post_types as $post_type ) : ?>
			<h2 style="margin-top:30px;"><?php echo esc_html( turf_get_post_type_label( $post_type ) ); ?></h2>
			<?php turf_render_admin_table( $post_type, $days ); ?>
		<?php endforeach; ?>

		<h1 style="margin-top:40px;"><?php esc_html_e( 'Archiefpagina\'s', 'turf-stats' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Weergaven van categorie-/taxonomie-archiefpagina\'s (bv. een "Vereniging" of "Locatie" overzicht), los van de losse berichten daarbinnen.', 'turf-stats' ); ?>
		</p>

		<?php foreach ( $taxonomies as $taxonomy ) : ?>
			<h2 style="margin-top:30px;"><?php echo esc_html( turf_get_taxonomy_label( $taxonomy ) ); ?></h2>
			<?php turf_render_admin_terms_table( $taxonomy, $days ); ?>
		<?php endforeach; ?>
	</div>
	<?php
}

function turf_count_posts_for_period( $post_type, $days ) {
	global $wpdb;

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->posts p
			INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value + 0 > 0",
			TURF_META_KEY,
			$post_type
		) );
	}

	$table = turf_table();

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.post_id) FROM $table v
		INNER JOIN $wpdb->posts p ON p.ID = v.post_id
		WHERE p.post_type = %s AND p.post_status = 'publish'
		AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
		$post_type,
		$days
	) );
}

function turf_get_alltime_visitors( $post_id ) {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT visitor_hash) FROM " . turf_table() . ' WHERE post_id = %d',
		$post_id
	) );
}

/**
 * Average engagement (reading time, scroll depth) for a post or term, across
 * all rows that have an engagement signal (most won't, if the visitor left
 * before the "leave page" beacon could fire, or for views from before this
 * feature existed).
 */
function turf_get_alltime_engagement( $object_id, $type = 'post' ) {
	global $wpdb;
	$column = 'term' === $type ? 'term_id' : 'post_id';

	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT AVG(duration_seconds) AS avg_duration, AVG(scroll_depth) AS avg_scroll
		FROM " . turf_table() . "
		WHERE $column = %d AND duration_seconds IS NOT NULL",
		$object_id
	) );

	return array(
		'avg_duration' => ( $row && null !== $row->avg_duration ) ? (int) round( $row->avg_duration ) : null,
		'avg_scroll'   => ( $row && null !== $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null,
	);
}

function turf_format_duration( $seconds ) {
	if ( null === $seconds ) {
		return '—';
	}

	return floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
}

function turf_format_scroll( $pct ) {
	return null === $pct ? '—' : $pct . '%';
}

function turf_get_top_posts_for_period( $post_type, $days, $page = 1 ) {
	global $wpdb;

	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		$query = new WP_Query( array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => TURF_PER_PAGE,
			'paged'               => $page,
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'meta_key'            => TURF_META_KEY,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );

		$rows = array();
		foreach ( $query->posts as $post ) {
			$engagement = turf_get_alltime_engagement( $post->ID, 'post' );
			$rows[]     = (object) array(
				'post_id'      => $post->ID,
				'views'        => turf_get_views( $post->ID ),
				'visitors'     => turf_get_alltime_visitors( $post->ID ),
				'avg_duration' => $engagement['avg_duration'],
				'avg_scroll'   => $engagement['avg_scroll'],
			);
		}

		return $rows;
	}

	$table = turf_table();

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.post_id AS post_id, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors,
			AVG(v.duration_seconds) AS avg_duration, AVG(v.scroll_depth) AS avg_scroll
		FROM $table v
		INNER JOIN $wpdb->posts p ON p.ID = v.post_id
		WHERE p.post_type = %s AND p.post_status = 'publish'
		AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		GROUP BY v.post_id
		ORDER BY views DESC
		LIMIT %d OFFSET %d",
		$post_type,
		$days,
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_render_pagination( $param, $current_page, $total_pages ) {
	$current_url = remove_query_arg( $param );
	$separator   = ( false === strpos( $current_url, '?' ) ) ? '?' : '&';

	echo paginate_links( array(
		'base'      => $current_url . '%_%',
		'format'    => $separator . $param . '=%#%',
		'current'   => $current_page,
		'total'     => $total_pages,
		'prev_text' => '&laquo;',
		'next_text' => '&raquo;',
	) );
}

function turf_render_admin_table( $post_type, $days ) {
	$param          = 'pg_' . $post_type;
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;

	$total = turf_count_posts_for_period( $post_type, $days );

	if ( ! $total ) {
		echo '<p>' . esc_html__( 'Nog geen data voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}

	$total_pages = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page        = min( $requested_page, $total_pages );
	$rows        = turf_get_top_posts_for_period( $post_type, $days, $page );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Titel', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Weergaven', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Bezoekers', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Gem. leestijd', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Gem. scrolldiepte', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$title       = get_the_title( $row->post_id );
				$edit        = get_edit_post_link( $row->post_id );
				$avg_duration = isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : null;
				$avg_scroll   = isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null;
				?>
				<tr>
					<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ?: '#' . $row->post_id ); ?></td>
					<td><?php echo (int) $row->views; ?></td>
					<td><?php echo (int) $row->visitors; ?></td>
					<td><?php echo esc_html( turf_format_duration( $avg_duration ) ); ?></td>
					<td><?php echo esc_html( turf_format_scroll( $avg_scroll ) ); ?></td>
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

/**
 * Taxonomy-term equivalents of the post-table functions above.
 */
function turf_count_terms_for_period( $taxonomy, $days ) {
	global $wpdb;

	if ( 0 === $days ) {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->term_taxonomy tt
			INNER JOIN $wpdb->termmeta m ON m.term_id = tt.term_id AND m.meta_key = %s
			WHERE tt.taxonomy = %s AND m.meta_value + 0 > 0",
			TURF_META_KEY,
			$taxonomy
		) );
	}

	$table = turf_table();

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT v.term_id) FROM $table v
		INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = v.term_id
		WHERE tt.taxonomy = %s
		AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
		$taxonomy,
		$days
	) );
}

function turf_get_alltime_term_visitors( $term_id ) {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT visitor_hash) FROM " . turf_table() . ' WHERE term_id = %d',
		$term_id
	) );
}

function turf_get_top_terms_for_period( $taxonomy, $days, $page = 1 ) {
	global $wpdb;

	$offset = ( max( 1, $page ) - 1 ) * TURF_PER_PAGE;

	if ( 0 === $days ) {
		$query = new WP_Term_Query( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => TURF_PER_PAGE,
			'offset'     => $offset,
			'orderby'    => 'meta_value_num',
			'order'      => 'DESC',
			'meta_key'   => TURF_META_KEY,
		) );

		$rows = array();
		foreach ( $query->get_terms() as $term ) {
			$engagement = turf_get_alltime_engagement( $term->term_id, 'term' );
			$rows[]     = (object) array(
				'term_id'      => $term->term_id,
				'views'        => turf_get_views( $term->term_id, 'term' ),
				'visitors'     => turf_get_alltime_term_visitors( $term->term_id ),
				'avg_duration' => $engagement['avg_duration'],
				'avg_scroll'   => $engagement['avg_scroll'],
			);
		}

		return $rows;
	}

	$table = turf_table();

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.term_id AS term_id, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors,
			AVG(v.duration_seconds) AS avg_duration, AVG(v.scroll_depth) AS avg_scroll
		FROM $table v
		INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = v.term_id
		WHERE tt.taxonomy = %s
		AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		GROUP BY v.term_id
		ORDER BY views DESC
		LIMIT %d OFFSET %d",
		$taxonomy,
		$days,
		TURF_PER_PAGE,
		$offset
	) );
}

function turf_render_admin_terms_table( $taxonomy, $days ) {
	$param          = 'pgt_' . $taxonomy;
	$requested_page = isset( $_GET[ $param ] ) ? max( 1, absint( $_GET[ $param ] ) ) : 1;

	$total = turf_count_terms_for_period( $taxonomy, $days );

	if ( ! $total ) {
		echo '<p>' . esc_html__( 'Nog geen data voor deze periode.', 'turf-stats' ) . '</p>';
		return;
	}

	$total_pages = max( 1, (int) ceil( $total / TURF_PER_PAGE ) );
	$page        = min( $requested_page, $total_pages );
	$rows        = turf_get_top_terms_for_period( $taxonomy, $days, $page );
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Naam', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Weergaven', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Bezoekers', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Gem. leestijd', 'turf-stats' ); ?></th>
				<th><?php esc_html_e( 'Gem. scrolldiepte', 'turf-stats' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$term         = get_term( $row->term_id );
				$name         = $term && ! is_wp_error( $term ) ? $term->name : '#' . $row->term_id;
				$link         = $term && ! is_wp_error( $term ) ? get_term_link( $term ) : false;
				$avg_duration = isset( $row->avg_duration ) && is_numeric( $row->avg_duration ) ? (int) round( $row->avg_duration ) : null;
				$avg_scroll   = isset( $row->avg_scroll ) && is_numeric( $row->avg_scroll ) ? (int) round( $row->avg_scroll ) : null;
				?>
				<tr>
					<td><?php echo ( $link && ! is_wp_error( $link ) ) ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ); ?></td>
					<td><?php echo (int) $row->views; ?></td>
					<td><?php echo (int) $row->visitors; ?></td>
					<td><?php echo esc_html( turf_format_duration( $avg_duration ) ); ?></td>
					<td><?php echo esc_html( turf_format_scroll( $avg_scroll ) ); ?></td>
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
