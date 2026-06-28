<?php
/**
 * Update notifications via GitHub Releases, using the bundled
 * Plugin Update Checker library (https://github.com/YahnisElsts/plugin-update-checker,
 * MIT licensed - vendored in vendor/plugin-update-checker/).
 *
 * This makes WordPress show the normal "update available" notice on the
 * Plugins page by checking https://github.com/fbloemhof/turf-stats/releases
 * for a release with a higher version number than TURF_VERSION, and
 * downloads the .zip attached to that release. No plugin/visitor data is
 * sent - this only checks "is there a newer version", the same kind of
 * request WordPress already makes to wordpress.org for every other plugin,
 * just pointed at GitHub instead.
 */

require_once TURF_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// PUC's own docs recommend instantiating this outside of any hook (or on
// plugins_loaded at the latest) - doing it only on an admin_* hook would hide
// updates from WP-CLI and other non-admin-page update checks.
$turf_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/fbloemhof/turf-stats/',
	TURF_PATH . 'turf-stats.php',
	'turf-stats'
);

// Use the .zip attached to each GitHub release (built with the correct
// turf-stats/ folder structure) instead of a raw source-tree archive.
$turf_update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
