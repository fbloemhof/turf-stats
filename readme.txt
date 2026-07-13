=== Turf Stats ===
Contributors: freakstar
Tags: analytics, statistics, privacy, page views, cookieless
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.19.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted, cookieless analytics: page views, visitors, clicks, searches, 404s and bots - all stored in your own database, no external services.

== Description ==

Turf is a self-hosted, cookieless analytics plugin. No Google Analytics, no
Jetpack, no external network calls - everything is measured and stored in your
own WordPress database.

**Why Turf**

* **Cookieless.** Visitor deduplication uses a one-way hash of IP + user-agent,
  never a cookie and never the raw IP address.
* **Self-hosted.** All data lives in your own database; nothing is sent to any
  third party.
* **Zero configuration.** Every public post type and taxonomy is tracked
  automatically, including ones you add later.

**What it tracks**

* Page views (deduplicated *and* raw), unique visitors, views per visitor, and
  average visit duration
* Device, browser, operating system, language, and country (via Cloudflare's
  header when available - no GeoIP lookups)
* Referrers / traffic sources, UTM campaign parameters, and automatic outbound
  link tracking (full destination URL + the page clicked from)
* New vs. returning visitors, scroll depth, and reading time
* On-site search terms, including zero-result searches
* Visitor routes (page-to-page transitions), landing pages, and a bounce-rate
  proxy - all reconstructed from sessions
* Cache offload: how much traffic is served by a page cache (SiteGround,
  Cloudflare, ...) instead of hitting WordPress
* 404 hits, comments per period, editorial content activity, per-author stats
* Bots & LLM crawlers (Googlebot, GPTBot, ClaudeBot, ...) - tracked server-side
  and kept separate from human visitor numbers
* WooCommerce funnel (views, add to cart, checkout) and form submissions
  (Contact Form 7, Gravity Forms) when those plugins are active

The admin pages use native WordPress postboxes: collapsible, draggable,
hideable via Screen Options, and styled by your admin color scheme. The
interface is English and ships with a full Dutch (nl_NL) translation.

== Installation ==

1. Install and activate the plugin.
2. That's it - views start counting immediately for every public post type and
   taxonomy. Find the reports under the "Statistics" menu in wp-admin.

Optional extras (a visible view counter, click tracking on specific elements,
social-share links, importing historical Jetpack/entry-views view counts via
the "Statistics > Import" admin page or WP-CLI) are documented in the plugin's
README on GitHub.

== Frequently Asked Questions ==

= Does Turf set cookies or require a consent banner? =

Turf sets no cookies and sends no data to any external service. Deduplication
uses a one-way hash of IP + user-agent; the raw IP is never stored. It is
designed to fit a "legitimate interest" basis for first-party, aggregate
analytics - but that is not legal advice: check with whoever maintains your
privacy policy.

= What data does Turf collect, and where does it go? =

Per page view Turf stores, in your own database only: a one-way visitor hash,
timestamp, the page viewed, device type / browser / OS family (parsed from the
user-agent), language, country code (only if Cloudflare provides it), referrer
hostname, UTM parameters, scroll depth, and reading time. Raw event rows are
pruned automatically after 18 months (filterable); aggregate totals are kept.
Nothing is ever transmitted off your server.

= Why are Turf's numbers lower than Jetpack's or a raw hit counter's? =

"Views" is deduplicated: a repeat view of the same page by the same visitor
within 30 minutes doesn't count again (the same default Google Analytics and
Matomo use). The separate "Raw views" number counts every page load, for an
apples-to-apples comparison with hit-counting tools.

= Does it work with page caching? =

Yes. Tracking runs client-side, so cached pages are still counted. Turf even
reports how much of your traffic is served from cache.

= Where is the country data coming from? =

From Cloudflare's CF-IPCountry request header, if your site is behind
Cloudflare. Turf deliberately does not call any geolocation API - that would
mean sending visitor IPs to a third party. Without Cloudflare, country stays
empty unless you hook up your own local database via the turf_visitor_country
filter.

= Does uninstalling remove my statistics? =

By default, no - analytics history is precious, so the data survives an
uninstall/reinstall. To remove everything on uninstall, add
`define( 'TURF_REMOVE_DATA_ON_UNINSTALL', true );` to wp-config.php (or set
the `turf_remove_data_on_uninstall` option to `1`) before deleting the plugin.

= Where do I suggest a feature or report a bug? =

On the plugin's GitHub repository: https://github.com/fbloemhof/turf-stats/issues

== Screenshots ==

1. Statistics overview - views, visitors, device/browser/OS breakdowns, cache
   offload, peak hours, and top pages and posts for the selected period.
2. Analysis - landing pages with bounce rate, on-site search terms
   (including zero-result searches), trending content, and per-author stats.
3. Bots & LLMs - crawler and AI-bot visits (Googlebot, GPTBot, ClaudeBot, ...)
   tracked separately from human visitors, with the pages they crawl most.

== Changelog ==

= 1.19.0 =
* New "Screen resolution" breakdown box: visitor screen sizes (in CSS
  pixels) are recorded with each view. The new database columns are added
  automatically on update.
* New "Avg. load time" stat box: page load times are measured in the
  visitor's browser via the Navigation Timing API and averaged per period.
* The "Online now" box gains a live "Currently viewed" list of the pages
  current visitors are on, refreshed on the same 20-second poll.
* Hourly visitors chart reworked: a fixed 24-hour axis with a dashed
  "yesterday" line for direct comparison against the previous day.
* Daily views/visitors bars now show their values on the chart, with
  density-based label sizing on the 30- and 90-day views.
* The Caching box now explains itself when a cache layer is detected but
  is serving (almost) none of the tracked page views.
* Much faster legacy view-count import: a Jetpack top-posts pre-pass
  covers the most-viewed posts in one request, admin-page batches run
  concurrently, and the WP-CLI command gains --offset/--limit flags.
* Dutch (nl_NL) translation updated for all new strings.

= 1.18.1 =
* Added wordpress.org listing screenshots (Statistics overview, Analysis,
  Bots & LLMs) and the corresponding == Screenshots == section. No
  functional or tracking changes.

= 1.18.0 =
* The GitHub-based update checker is removed entirely (updater.php and its
  vendored library) - releases now deploy straight to WordPress.org via a
  GitHub Action, and updates are handled by wordpress.org like any other
  hosted plugin.
* Fixed postbox drag-order and collapse/expand state not actually being
  saved: the admin pages were missing the nonce fields WordPress core's own
  postbox AJAX handlers look for, so those requests silently failed. Order
  and collapsed state now persist correctly per user.
* The site-specific "Dorpsapp" companion-app integration is now a generic,
  opt-in connector-app mechanism (`turf_connector_app_route_patterns` /
  `turf_connector_app_label` filters) - no behavior change unless you were
  using the old `turf_dorpsapp_route_patterns` filter, which is renamed.
* Added a GitHub issues link (bug reports / feature suggestions) to the FAQ.
* The legacy view-count import (Jetpack Stats / the old entry-views plugin) is
  now also available as a "Statistics > Import" admin page, alongside the
  existing `wp turf-stats import-legacy-views` WP-CLI command - same import,
  run in small AJAX batches with a progress bar instead of the command line.

= 1.17.2 =
* Fixed a WordPress.org submission error: the Plugin URI and Author URI
  headers pointed at the same URL. Author URI now points at the author's
  GitHub profile instead of the plugin repo.

= 1.17.1 =
* Cleared the remaining WordPress.org Plugin Check warnings: dropped the
  now-redundant load_plugin_textdomain() call (translations for wp.org-hosted
  plugins load automatically since WP 4.6) and annotated the opt-in REST debug
  logger. No functional or tracking changes.

= 1.17.0 =
* WordPress.org compliance release: readme.txt, uninstall.php with opt-in data
  removal, direct-access guards on all files, and the GitHub update checker is
  no longer part of the WordPress.org build (updates come from wordpress.org).

= 1.16.1 =
* Long lists are now collapsed before first paint (CSS), eliminating the
  load-time jump introduced in 1.16.0.

= 1.16.0 =
* Cache offload insight (served-from-cache % with layer detection).
* Landing pages report with per-landing bounce rate.
* Blocks with no data for the selected period are hidden entirely.
* All lists use one in-place Show more / Show less toggle; server-side
  pagination removed.

= 1.15.0 =
* Bilingual: English source strings with a bundled Dutch (nl_NL) translation.

= 1.14.0 =
* Raw (non-deduplicated) view counting, views per visitor, average visit
  duration, an hourly visitors chart for "Today", and full-URL outbound link
  tracking including the source page.

= Earlier =
* See https://github.com/fbloemhof/turf-stats/releases for the full history.

== Upgrade Notice ==

= 1.18.1 =
Listing/packaging update only (screenshots); no functional changes.

= 1.18.0 =
Updates now come from wordpress.org instead of GitHub; postbox order/collapse
state now actually persists. Rename turf_dorpsapp_route_patterns to
turf_connector_app_route_patterns if you used it.

= 1.17.2 =
Packaging fix only (duplicate Plugin/Author URI headers); no functional changes.

= 1.17.1 =
Plugin Check warning cleanup; no changes to tracking or reports.

= 1.17.0 =
Compliance/packaging release; no changes to tracking or reports.
