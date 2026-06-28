<?php
/**
 * Plugin Name: Turf
 * Plugin URI: https://github.com/fbloemhof/turf-stats
 * Description: Self-hosted, cookieless page-view and click analytics for WordPress - no Google Analytics, no Jetpack, no external calls for tracking. Tracks views, archive pages, referrers, UTM campaigns, scroll depth/reading time, 404s, and arbitrary UI clicks.
 * Version: 1.9.0
 * Author: fbloemhof
 * Author URI: https://github.com/fbloemhof/turf-stats
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turf-stats
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TURF_VERSION', '1.9.0' );
define( 'TURF_PATH', plugin_dir_path( __FILE__ ) );
define( 'TURF_URL', plugin_dir_url( __FILE__ ) );

require_once TURF_PATH . 'includes/updater.php';
require_once TURF_PATH . 'includes/views.php';
require_once TURF_PATH . 'includes/postboxes.php';
require_once TURF_PATH . 'includes/views-admin.php';
require_once TURF_PATH . 'includes/rest.php';
require_once TURF_PATH . 'includes/comments.php';
require_once TURF_PATH . 'includes/online-now.php';
require_once TURF_PATH . 'includes/clicks.php';
require_once TURF_PATH . 'includes/clicks-admin.php';
require_once TURF_PATH . 'includes/404s.php';
require_once TURF_PATH . 'includes/404s-admin.php';
require_once TURF_PATH . 'includes/bots.php';
require_once TURF_PATH . 'includes/bots-admin.php';
require_once TURF_PATH . 'includes/search.php';
require_once TURF_PATH . 'includes/search-admin.php';
require_once TURF_PATH . 'includes/sessions.php';
require_once TURF_PATH . 'includes/sessions-admin.php';
require_once TURF_PATH . 'includes/analytics.php';
require_once TURF_PATH . 'includes/analytics-admin.php';
require_once TURF_PATH . 'includes/authors-admin.php';
require_once TURF_PATH . 'includes/woocommerce.php';
require_once TURF_PATH . 'includes/woocommerce-admin.php';
require_once TURF_PATH . 'includes/forms.php';
require_once TURF_PATH . 'includes/forms-admin.php';
require_once TURF_PATH . 'includes/social-share.php';
require_once TURF_PATH . 'includes/retention.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TURF_PATH . 'includes/cli.php';
}

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'turf_prune_old_events' );
} );
