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

define( 'TURF_PER_PAGE', 5 );

function turf_admin_menu() {
	$hook = add_menu_page(
		__( 'Statistieken', 'turf-stats' ),
		__( 'Statistieken', 'turf-stats' ),
		'manage_options',
		'turf-stats',
		'turf_render_admin_page',
		'dashicons-chart-bar',
		26
	);

	add_action( "load-$hook", 'turf_views_register_metaboxes' );
}
add_action( 'admin_menu', 'turf_admin_menu' );

/**
 * Three sections, each its own context group so they can render as
 * separate areas on the page (one full-width box, a 2-up grid of compact
 * breakdowns, then the rest full-width again) while still being one
 * continuous drag/drop scope for postboxes.js: 'turf_overview' (the chart +
 * stat boxes), 'turf_compact' (the device/browser/etc. breakdowns, laid out
 * two-per-row via turf_render_postbox_grid_column()), and 'turf_wide' (peak
 * hours, then the per-post-type/taxonomy tables).
 */
function turf_views_register_metaboxes() {
	$hook = get_current_screen()->id;
	turf_register_postbox_hook( $hook );

	$days = turf_get_requested_days( 'today' );

	add_meta_box( 'turf_overview', __( 'Overzicht', 'turf-stats' ), function () use ( $days ) {
		turf_render_overview( $days );
	}, $hook, 'turf_overview' );

	$compact_boxes = array(
		array( 'turf_device', __( 'Apparaat', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'device_type', $days );
		} ),
		array( 'turf_browser', __( 'Browser', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'browser', $days );
		} ),
		array( 'turf_os', __( 'Besturingssysteem', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'os', $days );
		} ),
		array( 'turf_language', __( 'Taal', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'language', $days );
		} ),
		array( 'turf_country', __( 'Land van herkomst', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'country', $days );
		} ),
		array( 'turf_new_returning', __( 'Nieuw vs. terugkerend', 'turf-stats' ), function () use ( $days ) {
			turf_render_new_vs_returning( $days );
		} ),
		array( 'turf_referrer', __( 'Herkomst', 'turf-stats' ), function () use ( $days ) {
			turf_render_referrer_breakdown( $days );
		} ),
		array( 'turf_top_referrers', __( 'Top verwijzende sites', 'turf-stats' ), function () use ( $days ) {
			turf_render_top_referrer_hosts( $days );
		} ),
		array( 'turf_utm_source', __( 'Campagnebron (UTM)', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'utm_source', $days, true );
		} ),
		array( 'turf_utm_medium', __( 'Campagnemedium (UTM)', 'turf-stats' ), function () use ( $days ) {
			turf_render_breakdown( 'utm_medium', $days, true );
		} ),
	);

	foreach ( $compact_boxes as $box ) {
		list( $id, $title, $callback ) = $box;
		add_meta_box( $id, $title, $callback, $hook, 'turf_compact' );
	}

	add_meta_box( 'turf_peak_hours', __( 'Piekuren', 'turf-stats' ), function () use ( $days ) {
		turf_render_peak_hours( $days );
	}, $hook, 'turf_wide' );

	$post_types = turf_trackable_post_types();
	usort( $post_types, function ( $a, $b ) {
		return strnatcasecmp( turf_get_post_type_label( $a ), turf_get_post_type_label( $b ) );
	} );

	foreach ( $post_types as $post_type ) {
		add_meta_box(
			'turf_posts_' . $post_type,
			turf_get_post_type_label( $post_type ),
			function () use ( $post_type, $days ) {
				turf_render_admin_table( $post_type, $days );
			},
			$hook,
			'turf_wide'
		);
	}

	add_meta_box( 'turf_comments', __( 'Meest besproken', 'turf-stats' ), function () use ( $days ) {
		turf_render_top_commented_posts( $days );
	}, $hook, 'turf_wide' );

	$taxonomies = turf_trackable_taxonomies();
	usort( $taxonomies, function ( $a, $b ) {
		return strnatcasecmp( turf_get_taxonomy_label( $a ), turf_get_taxonomy_label( $b ) );
	} );

	foreach ( $taxonomies as $taxonomy ) {
		add_meta_box(
			'turf_terms_' . $taxonomy,
			turf_get_taxonomy_label( $taxonomy ),
			function () use ( $taxonomy, $days ) {
				turf_render_admin_terms_table( $taxonomy, $days );
			},
			$hook,
			'turf_wide'
		);
	}
}

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
 * @param int $days        Length of the range in days, or TURF_PERIOD_TODAY.
 * @param int $offset_days How many days ago the range ends (0 = ending now).
 *                          For TURF_PERIOD_TODAY specifically, 0 means
 *                          "today" (midnight to now) and 1 means "yesterday"
 *                          (midnight to midnight) - the generic "shift the
 *                          whole window back by $days" offset math below
 *                          doesn't apply to a single calendar day.
 */
function turf_get_range_site_totals( $days, $offset_days = 0 ) {
	global $wpdb;

	$table = turf_table();
	list( $join, $where, $params ) = turf_site_join_and_where();

	if ( TURF_PERIOD_TODAY === $days ) {
		$end   = ( 0 === $offset_days ) ? current_time( 'mysql', true ) : turf_local_midnight_utc( 0 );
		$start = turf_local_midnight_utc( $offset_days );
	} else {
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$offset_days} days" ) );
		$start = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $offset_days + $days ) . ' days' ) );
	}

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

	$start = turf_period_start_sql_date( $days );

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

function turf_render_stat_box( $label, $value, $change, $suffix = '' ) {
	?>
	<div class="bk-stats-box">
		<span class="bk-stats-box__label"><?php echo esc_html( $label ); ?></span>
		<span class="bk-stats-box__value"><?php echo esc_html( number_format_i18n( $value ) . $suffix ); ?></span>
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
		$totals   = turf_get_alltime_site_totals();
		$comments = turf_get_comment_totals( 0 );
		?>
		<div class="bk-stats-overview">
			<div class="bk-stats-overview__totals">
				<?php turf_render_online_now(); ?>
				<?php turf_render_stat_box( __( 'Weergaven', 'turf-stats' ), $totals['views'], false ); ?>
				<?php turf_render_stat_box( __( 'Bezoekers', 'turf-stats' ), $totals['visitors'], false ); ?>
				<?php turf_render_stat_box( __( 'Reacties', 'turf-stats' ), $comments, false ); ?>
			</div>
		</div>
		<?php
		return;
	}

	if ( TURF_PERIOD_TODAY === $days ) {
		$current           = turf_get_range_site_totals( $days, 0 );
		$previous          = turf_get_range_site_totals( $days, 1 );
		$current_comments  = turf_get_comment_totals( $days, 0 );
		$previous_comments = turf_get_comment_totals( $days, 1 );
		?>
		<div class="bk-stats-overview">
			<div class="bk-stats-overview__totals">
				<?php turf_render_online_now(); ?>
				<?php turf_render_stat_box( __( 'Weergaven', 'turf-stats' ), $current['views'], turf_pct_change( $current['views'], $previous['views'] ) ); ?>
				<?php turf_render_stat_box( __( 'Bezoekers', 'turf-stats' ), $current['visitors'], turf_pct_change( $current['visitors'], $previous['visitors'] ) ); ?>
				<?php turf_render_stat_box( __( 'Reacties', 'turf-stats' ), $current_comments, turf_pct_change( $current_comments, $previous_comments ) ); ?>
				<?php
				$bounce_rate = turf_get_bounce_rate( $days );
				if ( null !== $bounce_rate ) :
					turf_render_stat_box( __( 'Bouncepercentage', 'turf-stats' ), $bounce_rate, false, '%' );
				endif;
				?>
			</div>
			<p class="description">
				<?php esc_html_e( 'Vandaag, sinds middernacht - vergeleken met heel gisteren.', 'turf-stats' ); ?>
			</p>
		</div>
		<?php
		return;
	}

	$daily             = turf_get_daily_site_totals( $days );
	$current           = turf_get_range_site_totals( $days, 0 );
	$previous          = turf_get_range_site_totals( $days, turf_previous_period_offset( $days ) );
	$current_comments  = turf_get_comment_totals( $days, 0 );
	$previous_comments = turf_get_comment_totals( $days, turf_previous_period_offset( $days ) );
	$max               = max( 1, max( array_column( $daily, 'views' ) ) );
	?>
	<div class="bk-stats-overview">
		<div class="bk-stats-overview__totals">
			<?php turf_render_online_now(); ?>
			<?php turf_render_stat_box( __( 'Weergaven', 'turf-stats' ), $current['views'], turf_pct_change( $current['views'], $previous['views'] ) ); ?>
			<?php turf_render_stat_box( __( 'Bezoekers', 'turf-stats' ), $current['visitors'], turf_pct_change( $current['visitors'], $previous['visitors'] ) ); ?>
			<?php turf_render_stat_box( __( 'Reacties', 'turf-stats' ), $current_comments, turf_pct_change( $current_comments, $previous_comments ) ); ?>
			<?php
			$bounce_rate = turf_get_bounce_rate( $days );
			if ( null !== $bounce_rate ) :
				turf_render_stat_box( __( 'Bouncepercentage', 'turf-stats' ), $bounce_rate, false, '%' );
			endif;
			?>
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

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
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
 * Android sends `Referer: android-app://<package-name>` for links opened
 * from inside many apps - once parsed as a URL host (which is all Turf
 * stores), that shows up as a raw reverse-DNS string like
 * "com.google.android.googlequicksearchbox" in the referrer-hosts list.
 * This is a static, baked-in lookup of well-known package names (same idea
 * as turf_country_label()/turf_language_label() above) - not a live
 * lookup, so it doesn't conflict with the no-external-calls design.
 * Filterable for package names not in this (necessarily incomplete) list.
 */
function turf_referrer_app_labels() {
	return apply_filters( 'turf_referrer_app_labels', array(
		'com.google.android.googlequicksearchbox' => 'Google-app (Android)',
		'com.google.android.gms'                  => 'Google Play Services (Android)',
		'com.google.android.apps.magazines'       => 'Google Discover/News (Android)',
		'com.google.android.youtube'              => 'YouTube (Android)',
		'com.android.chrome'                      => 'Chrome (Android)',
		'com.android.vending'                     => 'Google Play Store (Android)',
		'com.sec.android.app.sbrowser'            => 'Samsung Internet (Android)',
		'com.microsoft.emmx'                       => 'Edge (Android)',
		'org.mozilla.firefox'                     => 'Firefox (Android)',
		'com.opera.browser'                       => 'Opera (Android)',
		'com.duckduckgo.mobile.android'           => 'DuckDuckGo (Android)',
		'com.facebook.katana'                     => 'Facebook (Android)',
		'com.facebook.lite'                       => 'Facebook Lite (Android)',
		'com.facebook.orca'                       => 'Messenger (Android)',
		'com.instagram.android'                   => 'Instagram (Android)',
		'com.twitter.android'                     => 'X / Twitter (Android)',
		'com.whatsapp'                             => 'WhatsApp (Android)',
		'com.linkedin.android'                    => 'LinkedIn (Android)',
		'com.pinterest'                            => 'Pinterest (Android)',
		'com.snapchat.android'                    => 'Snapchat (Android)',
		'com.apple.mobilesafari'                  => 'Safari (iOS)',
		'com.apple.SafariViewService'             => 'Safari (iOS, in-app)',
	) );
}

/**
 * Returns a friendly label for a known Android/iOS app referrer host, or
 * the raw host unchanged if it isn't recognized.
 */
function turf_referrer_host_label( $host ) {
	$labels = turf_referrer_app_labels();

	return $labels[ $host ] ?? $host;
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

	$rest_marker     = esc_sql( TURF_REST_SOURCE_MARKER );
	$dorpsapp_marker = esc_sql( TURF_DORPSAPP_SOURCE_MARKER );

	return "CASE
		WHEN $column = '' THEN 'direct'
		WHEN $column = '$dorpsapp_marker' THEN 'dorpsapp'
		WHEN $column = '$rest_marker' THEN 'app'
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

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
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
		'dorpsapp'    => __( 'Dorpsapp', 'turf-stats' ),
		'app'         => __( 'App / REST API (overig)', 'turf-stats' ),
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

	if ( 0 !== $days ) {
		$where_date = 'AND v.viewed_at >= %s';
		$params[]   = turf_period_start_sql_date( $days );
	}

	$params[] = $site_host;
	$params[] = TURF_REST_SOURCE_MARKER;
	$params[] = TURF_DORPSAPP_SOURCE_MARKER;
	$params[] = $limit;

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT v.referrer_host AS label, COUNT(*) AS views, COUNT(DISTINCT v.visitor_hash) AS visitors
		FROM $table v
		$join
		WHERE $where $where_date
		AND v.referrer_host != '' AND v.referrer_host != %s AND v.referrer_host != %s AND v.referrer_host != %s
		GROUP BY v.referrer_host
		ORDER BY views DESC
		LIMIT %d",
		$params
	) );
}

/**
 * Shared renderer for any "rows with views+visitors, stacked bar per row"
 * breakdown block (device, browser, referrer bucket, top referrer hosts).
 * The block's heading comes from the metabox title it's rendered inside of -
 * this only outputs the bar rows themselves.
 *
 * @param object[] $rows           Each with ->label, ->views, ->visitors.
 * @param callable $label_callback Maps a raw $row->label to a display label.
 */
function turf_render_breakdown_rows( $rows, $label_callback ) {
	$views_list  = array_map( 'intval', wp_list_pluck( $rows, 'views' ) );
	$total_views = array_sum( $views_list );
	$max_views   = $views_list ? max( 1, max( $views_list ) ) : 1;
	?>
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
			<?php
			$value_text = sprintf(
				/* translators: 1: number of views, 2: percentage share of total views, 3: number of unique visitors */
				__( '%1$s weergaven (%2$d%%) · %3$s bezoekers', 'turf-stats' ),
				number_format_i18n( $views ),
				$share,
				number_format_i18n( $visitors )
			);
			?>
			<div class="bk-stats-bar-row" title="<?php echo esc_attr( $value_text ); ?>">
				<span class="bk-stats-bar-row__label"><?php echo esc_html( call_user_func( $label_callback, $row->label ) ); ?></span>
				<span class="bk-stats-bar-row__track">
					<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--views" style="width:<?php echo $views_pct; ?>%"></span>
					<span class="bk-stats-bar-row__fill bk-stats-bar-row__fill--visitors" style="width:<?php echo $visitors_pct; ?>%"></span>
				</span>
				<span class="bk-stats-bar-row__value"><?php echo esc_html( $value_text ); ?></span>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
	<?php
}

function turf_render_breakdown( $column, $days, $exclude_empty = false ) {
	$rows = turf_get_breakdown( $column, $days, $exclude_empty );

	turf_render_breakdown_rows( $rows, function ( $raw ) use ( $column ) {
		return turf_breakdown_label( $column, $raw );
	} );
}

function turf_render_referrer_breakdown( $days ) {
	$rows = turf_get_referrer_breakdown( $days );

	turf_render_breakdown_rows( $rows, 'turf_referrer_bucket_label' );
}

function turf_render_top_referrer_hosts( $days ) {
	$rows = turf_get_top_referrer_hosts( $days );

	turf_render_breakdown_rows( $rows, 'turf_referrer_host_label' );
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

	if ( 0 !== $days ) {
		$start = turf_period_start_sql_date( $days );
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

	turf_render_breakdown_rows( $rows, function ( $raw ) {
		return 'nieuw' === $raw ? __( 'Nieuwe bezoekers', 'turf-stats' ) : __( 'Terugkerende bezoekers', 'turf-stats' );
	} );
}

/**
 * All accent colors below use the WP admin's own --wp-admin-theme-color
 * custom property (set by core per active color scheme - Default/Light/
 * Blue/Coffee/Ectoplasm/Midnight/Ocean/Sunrise) instead of a fixed hex
 * value, so Turf's charts/bars match whatever scheme the user picked
 * rather than always being green. The box chrome itself (border, header,
 * collapse arrow) needs no custom CSS at all - that's core's own .postbox
 * styling, already scheme-aware.
 */
function turf_admin_inline_style() {
	?>
	<style>
		.bk-stats-overview__totals { display: flex; gap: 24px; margin-bottom: 16px; flex-wrap: wrap; }
		.bk-stats-box { min-width: 120px; }
		.bk-stats-box__label { display: block; color: #646970; font-size: 13px; }
		.bk-stats-box__value { display: block; font-size: 24px; font-weight: 600; margin: 4px 0; }
		.bk-stats-box__change { font-size: 12px; font-weight: 600; }
		.bk-stats-box__change--up { color: var(--wp-admin-theme-color, #2271b1); }
		.bk-stats-box__change--down { color: #d63638; }
		.bk-stats-box__change--new { color: #646970; }
		.bk-stats-overview__legend { display: flex; gap: 16px; margin-bottom: 8px; font-size: 12px; color: #646970; }
		.bk-stats-legend::before { content: ""; display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: middle; }
		.bk-stats-legend--views::before { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-legend--visitors::before { background: var(--wp-admin-theme-color, #2271b1); }
		.bk-stats-chart { display: flex; align-items: flex-end; gap: 8px; height: 200px; padding: 10px 0; border-bottom: 1px solid #dcdcde; }
		.bk-stats-chart__col { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
		.bk-stats-chart__bars { position: relative; width: 100%; max-width: 36px; height: 160px; }
		.bk-stats-chart__bar { position: absolute; bottom: 0; left: 0; width: 100%; border-radius: 2px 2px 0 0; }
		.bk-stats-chart__bar--views { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-chart__bar--visitors { background: var(--wp-admin-theme-color, #2271b1); }
		.bk-stats-chart__label { margin-top: 6px; font-size: 11px; color: #646970; }
		.bk-stats-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; min-width: 0; max-width: 100%; }
		.bk-stats-bar-row__label { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.bk-stats-bar-row__track { position: relative; width: 150px; flex-shrink: 0; background: #f0f0f1; border-radius: 3px; height: 10px; overflow: hidden; }
		.bk-stats-bar-row__fill { position: absolute; top: 0; left: 0; height: 100%; border-radius: 3px; }
		.bk-stats-bar-row__fill--views { background: color-mix(in srgb, var(--wp-admin-theme-color, #2271b1) 35%, #fff); }
		.bk-stats-bar-row__fill--visitors { background: var(--wp-admin-theme-color, #2271b1); }
		/* A *fixed* width, not shrink-to-content - rows with shorter text
		   (e.g. "17 weergaven (5%) · 10 bezoekers" vs. "247 weergaven (80%)
		   · 167 bezoekers") would otherwise each end at a different X
		   position, making the bars/labels above them drift row to row
		   instead of lining up. overflow+ellipsis is the backstop for an
		   edge-case number wider than this. */
		.bk-stats-bar-row__value { flex-shrink: 0; width: 220px; overflow: hidden; text-align: right; color: #646970; white-space: nowrap; text-overflow: ellipsis; }
		.bk-stats-more-link { display: block; margin: 2px 0 4px; background: none; border: none; padding: 0; color: var(--wp-admin-theme-color, #2271b1); cursor: pointer; font-size: 12px; text-decoration: underline; }
		.bk-stats-more-link:hover { text-decoration: none; }
		.turf-postbox-grid .meta-box-sortables {
			display: grid;
			grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
			gap: 20px;
			align-items: start;
			margin-bottom: 20px;
		}
		.turf-postbox-grid .postbox { margin: 0; }

		@media (max-width: 900px) {
			.turf-postbox-grid .meta-box-sortables { grid-template-columns: 1fr; }
		}
		@media (max-width: 600px) {
			.bk-stats-overview__totals { flex-wrap: wrap; }
			.bk-stats-box { flex: 1 1 auto; }
			.bk-stats-bar-row__track { width: 50px; }
			.bk-stats-bar-row__value { width: 110px; font-size: 11px; }
		}
		/* WP core adds .ui-sortable-handle to the draggable box header and
		   sets touch-action:none on it unconditionally (wp-admin/css/
		   common.css) - that disables native touch scrolling the instant a
		   finger lands on a box title, with no actual touch-drag to show
		   for it (jQuery UI Sortable here only handles mouse events). Lets
		   vertical scrolling through the handle again; doesn't affect
		   mouse-based dragging at all. */
		.postbox .postbox-header, .postbox .hndle, .postbox .handle-actions {
			touch-action: pan-y !important;
		}
		.bk-stats-online-now__dot {
			display: inline-block; flex-shrink: 0; width: 7px; height: 7px; border-radius: 50%;
			background: var(--wp-admin-theme-color, #2271b1);
			color: var(--wp-admin-theme-color, #2271b1);
			margin-right: 5px;
			animation: bk-stats-pulse 2s infinite;
		}
		@keyframes bk-stats-pulse {
			0% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 50%, transparent); }
			70% { box-shadow: 0 0 0 6px color-mix(in srgb, currentColor 0%, transparent); }
			100% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 0%, transparent); }
		}
		.bk-stats-heatmap { border-collapse: collapse; width: 100%; }
		.bk-stats-heatmap th { font-size: 10px; color: #646970; font-weight: 400; text-align: center; padding: 2px; }
		.bk-stats-heatmap td { height: 18px; border: 1px solid #fff; }
	</style>
	<?php
}

function turf_render_admin_page() {
	$days = turf_get_requested_days( 'today' );

	turf_admin_inline_style();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Statistieken', 'turf-stats' ); ?></h1>

		<?php turf_render_period_tabs( admin_url( 'admin.php?page=turf-stats' ), 'today' ); ?>

		<p class="description">
			<?php esc_html_e( 'Elk blok hieronder is inklapbaar en kan verplaatst worden door het vast te pakken aan de titel. Onder "Schermopties" rechtsboven kun je blokken tijdelijk verbergen.', 'turf-stats' ); ?>
		</p>
		<?php if ( 0 !== $days ) : ?>
			<p class="description">
				<?php esc_html_e( 'Geïmporteerde historische views (van vóór deze plugin) hebben geen datum en geen bezoekers-/apparaat-/herkomstgegevens. Ze tellen alleen mee bij "Alles" en alleen bij "Weergaven".', 'turf-stats' ); ?>
			</p>
		<?php endif; ?>

		<?php
		$hook = get_current_screen()->id;
		?>
		<div id="poststuff">
			<?php turf_render_postbox_column( $hook, 'turf_overview' ); ?>
			<?php turf_render_postbox_grid_column( $hook, 'turf_compact' ); ?>
			<?php turf_render_postbox_column( $hook, 'turf_wide' ); ?>
		</div>
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
		AND v.viewed_at >= %s",
		$post_type,
		turf_period_start_sql_date( $days )
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
		AND v.viewed_at >= %s
		GROUP BY v.post_id
		ORDER BY views DESC
		LIMIT %d OFFSET %d",
		$post_type,
		turf_period_start_sql_date( $days ),
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
		AND v.viewed_at >= %s",
		$taxonomy,
		turf_period_start_sql_date( $days )
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
		AND v.viewed_at >= %s
		GROUP BY v.term_id
		ORDER BY views DESC
		LIMIT %d OFFSET %d",
		$taxonomy,
		turf_period_start_sql_date( $days ),
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
