=== Turf Stats ===
Contributors: fbloemhof
Tags: analytics, statistics, privacy, page views, cookieless
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.17.1
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
social-share links, WP-CLI import of historical Jetpack view counts) are
documented in the plugin's README on GitHub.

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

== Changelog ==

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

= 1.17.1 =
Plugin Check warning cleanup; no changes to tracking or reports.

= 1.17.0 =
Compliance/packaging release; no changes to tracking or reports.
