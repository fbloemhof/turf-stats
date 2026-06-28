<?php
/**
 * Bot and LLM-crawler tracking - a separate concern from regular page
 * views, kept in its own table so it never mixes into the "real visitor"
 * numbers (Weergaven/Bezoekers/etc. already deliberately exclude bots).
 *
 * Most crawlers - search engines and especially LLM/AI bots - never run
 * JavaScript, so the browser-based AJAX tracking in views.php can't see
 * them at all. This hooks template_redirect directly instead (runs on
 * every front-end request regardless of whether the response is ever
 * rendered or any JS executes), at an early priority so it logs before any
 * other template_redirect hook can exit()/redirect away.
 *
 * No dedup: unlike human visitors, how often a specific bot re-hits the
 * same page is itself useful information (e.g. "GPTBot is hammering this
 * page"), not refresh noise to filter out.
 */

define( 'TURF_BOTS_DB_VERSION', '1.0' );

function turf_bots_table() {
	global $wpdb;
	return $wpdb->prefix . 'turf_bots';
}

function turf_bots_install() {
	if ( get_option( 'turf_bots_db_version' ) === TURF_BOTS_DB_VERSION ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = turf_bots_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NULL DEFAULT NULL,
		term_id BIGINT UNSIGNED NULL DEFAULT NULL,
		bot_name VARCHAR(50) NOT NULL,
		bot_category VARCHAR(20) NOT NULL,
		visited_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY post_lookup (post_id, visited_at),
		KEY term_lookup (term_id, visited_at),
		KEY category_lookup (bot_category, visited_at)
	) $charset_collate;" );

	update_option( 'turf_bots_db_version', TURF_BOTS_DB_VERSION );
}
add_action( 'init', 'turf_bots_install' );

/**
 * Known bot/crawler signatures, grouped by category. Filterable - this
 * space moves fast (new LLM crawlers show up regularly), so sites can add
 * to or override this list without waiting for a Turf update. Checked in
 * category order, so a more specific signature (e.g. "Applebot-Extended"
 * under llm) must come before a substring-overlapping general one (e.g.
 * "Applebot" under search) - it does, since llm is checked first below.
 */
function turf_bot_signatures() {
	return apply_filters( 'turf_bot_signatures', array(
		'llm'    => array(
			'GPTBot'             => 'GPTBot (OpenAI)',
			'ChatGPT-User'       => 'ChatGPT-User (OpenAI)',
			'OAI-SearchBot'      => 'OAI-SearchBot (OpenAI)',
			'ClaudeBot'          => 'ClaudeBot (Anthropic)',
			'Claude-Web'         => 'Claude-Web (Anthropic)',
			'anthropic-ai'       => 'Anthropic AI',
			'Google-Extended'    => 'Google-Extended (AI training)',
			'PerplexityBot'      => 'PerplexityBot',
			'Perplexity-User'    => 'Perplexity-User',
			'CCBot'              => 'CCBot (Common Crawl)',
			'Bytespider'         => 'Bytespider (ByteDance)',
			'Amazonbot'          => 'Amazonbot',
			'meta-externalagent' => 'Meta AI',
			'Meta-ExternalAgent' => 'Meta AI',
			'Applebot-Extended'  => 'Applebot-Extended (Apple AI)',
			'Diffbot'            => 'Diffbot',
			'YouBot'             => 'You.com',
			'cohere-ai'          => 'Cohere AI',
			'MistralAI'          => 'Mistral AI',
			'Timpibot'           => 'Timpibot',
			'ImagesiftBot'       => 'ImagesiftBot',
			'omgili'             => 'Omgili (AI data)',
		),
		'search' => array(
			'Googlebot'   => 'Googlebot',
			'GoogleOther' => 'GoogleOther',
			'bingbot'     => 'Bingbot',
			'Slurp'       => 'Yahoo Slurp',
			'DuckDuckBot' => 'DuckDuckBot',
			'Baiduspider' => 'Baiduspider',
			'YandexBot'   => 'YandexBot',
			'Sogou'       => 'Sogou',
			'Applebot'    => 'Applebot',
		),
		'social' => array(
			'facebookexternalhit' => 'Facebook (linkpreview)',
			'Twitterbot'          => 'Twitterbot / X',
			'LinkedInBot'         => 'LinkedInBot',
			'WhatsApp'            => 'WhatsApp (linkpreview)',
			'TelegramBot'         => 'TelegramBot',
			'Slackbot'            => 'Slackbot',
			'Discordbot'          => 'Discordbot',
		),
		'seo'    => array(
			'AhrefsBot'  => 'AhrefsBot',
			'SemrushBot' => 'SemrushBot',
			'MJ12bot'    => 'MJ12bot',
			'DotBot'     => 'DotBot',
			'BLEXBot'    => 'BLEXBot',
		),
	) );
}

/**
 * Returns array('name' => ..., 'category' => 'llm'|'search'|'social'|'seo'|'other')
 * or null if the user-agent doesn't look like a bot at all.
 */
function turf_classify_bot( $user_agent ) {
	if ( '' === $user_agent ) {
		return null;
	}

	foreach ( turf_bot_signatures() as $category => $bots ) {
		foreach ( $bots as $needle => $label ) {
			if ( false !== stripos( $user_agent, $needle ) ) {
				return array( 'name' => $label, 'category' => $category );
			}
		}
	}

	if ( preg_match( '/bot|crawl|spider|slurp/i', $user_agent ) ) {
		return array( 'name' => __( 'Overige bot', 'turf-stats' ), 'category' => 'other' );
	}

	return null;
}

function turf_track_bot_hit() {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$bot        = turf_classify_bot( $user_agent );

	if ( ! $bot ) {
		return;
	}

	$post_id = null;
	$term_id = null;

	if ( is_singular( turf_trackable_post_types() ) ) {
		$post_id = get_queried_object_id();
	} else {
		$queried = get_queried_object();

		if ( $queried instanceof WP_Term && in_array( $queried->taxonomy, turf_trackable_taxonomies(), true ) ) {
			$term_id = $queried->term_id;
		} else {
			return; // Not on trackable content - keep scope consistent with the rest of Turf.
		}
	}

	global $wpdb;
	$wpdb->insert(
		turf_bots_table(),
		array(
			'post_id'      => $post_id,
			'term_id'      => $term_id,
			'bot_name'     => $bot['name'],
			'bot_category' => $bot['category'],
			'visited_at'   => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s', '%s', '%s' )
	);
}
add_action( 'template_redirect', 'turf_track_bot_hit', 5 );
