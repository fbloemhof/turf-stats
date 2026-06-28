# Turf

Self-hosted, cookieless page-view and click analytics for WordPress. No
Google Analytics, no Jetpack, no external network calls — everything is
tracked and stored on your own database.

## Features

- **Page views** for every public post type and taxonomy archive, detected
  automatically (no config needed when you add a new CPT or taxonomy later).
- **Visitors** (unique, deduped per rolling window) alongside raw views.
- **Device, browser, OS** — parsed from the user-agent already present on
  every request.
- **Language** — from the `Accept-Language` header.
- **Country** — from Cloudflare's `CF-IPCountry` header if the site runs
  behind Cloudflare (no GeoIP database, no extra lookups). Empty otherwise.
- **Referrer / traffic source** — direct, internal, search engine, social, or
  other, plus a "top referring sites" list.
- **UTM campaign parameters** (`utm_source`, `utm_medium`, `utm_campaign`).
- **New vs. returning visitors.**
- **Scroll depth and reading time** per page, sent once when a visitor
  leaves (via `navigator.sendBeacon`).
- **404 tracking** — which missing URLs visitors actually hit.
- **REST API views** — counts views that come through `/wp-json/wp/v2/...`
  instead of a normal page load (e.g. a companion mobile app), shown as its
  own "App / REST API (overig)" bucket in the Herkomst breakdown. Only
  single-item `GET` requests count (e.g. `/wp/v2/posts/123`) -
  list/collection requests and block-editor "edit" context requests don't.
- **Dorpsapp recognized by name** — the "Dorpsapp"/"Doarpsapp" village-app
  product (used by several Dutch local sites) doesn't use `/wp/v2/...` at
  all - its connector plugin registers its own `doarpsapp/v1` REST
  namespace. Turf recognizes that namespace's single-item endpoints
  specifically and shows them as their own "Dorpsapp" bucket, rather than
  lumping them into the generic REST bucket above.
- To find out exactly what a specific app's requests look like (so you can
  recognize it by name too), set `define( 'TURF_DEBUG_REST', true );` in
  `wp-config.php` for a while and watch your PHP error log - remove it again
  once you've got what you need.
- **Generic click tracking** for any UI element, via a `data-turf-click="<key>"`
  attribute — no extra JS or AJAX wiring needed per element.
- **Comment counts per period** and a "most discussed" table — reads
  directly from WordPress' own comments, no extra tracking needed.
- **Online now** — a live, auto-refreshing count of visitors active in the
  last 5 minutes (filterable), shown at the top of the Statistieken page.
- **Bots & LLM's** — a separate "Bots & LLM's" page tracking how often
  crawlers visit and what they look at: search engines (Googlebot, Bingbot,
  ...), AI/LLM crawlers (GPTBot, ClaudeBot, Google-Extended, PerplexityBot,
  CCBot, ...), social link-preview bots, and SEO tools. Tracked server-side
  on every request (most bots, especially LLM crawlers, never run
  JavaScript, so the regular AJAX-based tracking can't see them at all) and
  kept completely separate from the human-visitor numbers, which already
  deliberately exclude bots. The signature list is filterable
  (`turf_bot_signatures`) since new crawlers show up regularly.
- **No cookies.** Deduplication uses a one-way hash of IP + user-agent, never
  the raw IP. The real visitor IP is read from Cloudflare's
  `CF-Connecting-IP` header when present, falling back to `REMOTE_ADDR`.
- **Data retention**: raw event rows are pruned automatically after 18 months
  (filterable, see below) — aggregate totals are unaffected and kept forever.
- **WP-CLI import** for one-time backfilling of historical view counts from
  Jetpack Stats and/or the old "Entry Views" plugin, so switching to Turf
  doesn't lose history.

## Installation

1. Copy this folder into `wp-content/plugins/turf` (or `turf-stats`) and
   activate it like any other plugin.
2. That's it — views start counting immediately for every public post type
   and taxonomy.

### Optional: a visible "X views" counter

Add a placeholder to any template where you want a visible counter:

```php
<span id="post-views"></span>
```

Turf's JS fills it in once it knows the count. If you don't add this
element, the page is still tracked — it just won't show a number anywhere.

### Optional: click tracking on specific elements

Add the attribute to anything you want to measure:

```php
<a href="..." data-turf-click="homepage-cta-button">...</a>
```

### Optional: importing historical view counts

```
wp turf-stats import-legacy-views --source=jetpack
wp turf-stats import-legacy-views --source=entry-views
wp turf-stats import-legacy-views --source=all [--force] [--dry-run]
```

`--source=jetpack` needs Jetpack's Stats module to still be active (it calls
Jetpack's own `stats_get_csv()`), so run it before disconnecting Jetpack if
you want to keep that history.

## Admin pages

- **Statistieken** — overview chart, device/browser/OS/language/country
  breakdowns, referrers, UTM campaigns, new vs. returning, and per-post-type
  and per-taxonomy tables (with average reading time/scroll depth).
- **Klikken** — top `data-turf-click` keys.
- **404's** — top requested-but-missing paths.
- **Bots & LLM's** — see above.

Every block on every Turf admin page is a real wp-admin postbox: collapsible
(click the title), draggable/reorderable (drag the title, order is remembered
per user - the same mechanism the Dashboard widgets use), and individually
hideable via "Schermopties" in the top-right corner. Colors follow whichever
admin color scheme the user has picked (Default/Light/Blue/Coffee/Ectoplasm/
Midnight/Ocean/Sunrise) instead of a fixed color.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `turf_trackable_post_types` | all public post types except `attachment` | Which post types get tracked |
| `turf_trackable_taxonomies` | all public taxonomies except `post_format` | Which taxonomy archives get tracked |
| `turf_dedup_window` | 30 minutes | How long before a repeat view from the same visitor counts again |
| `turf_retention_months` | 18 | How long raw event rows are kept before pruning (0 disables pruning) |
| `turf_clicks_allowed_keys` | none (any key allowed) | Optional strict allow-list for `data-turf-click` keys |
| `turf_online_now_window` | 5 minutes | How recent a view has to be to count towards "online now" |
| `turf_dorpsapp_route_patterns` | `doarpsapp/v1/{posts,events,info}/...` | Route patterns recognized as Dorpsapp single-item requests |
| `turf_bot_signatures` | see `includes/bots.php` | Known bot/LLM user-agent signatures, grouped by category |
| `turf_visitor_country` | `''` | Supply a country code when Cloudflare's `CF-IPCountry` header isn't present |

### Country detection without Cloudflare

Country detection is free (no extra lookup) on sites behind Cloudflare. On
sites that aren't, `turf_get_country()` returns `''` unless you hook
`turf_visitor_country` with your own **local** lookup. Turf deliberately
doesn't call a live geolocation API itself - that would mean sending visitor
IPs to a third party, which defeats the point of a no-external-calls
analytics plugin. Bring your own local database instead, for example with
[MaxMind's GeoLite2 PHP API](https://github.com/maxmind/GeoIP2-php) (free
account required, update the database periodically) or a CSV-based dataset
like [DB-IP's country-lite](https://db-ip.com/db/lite.php) (CC BY 4.0, no
account needed):

```php
add_filter( 'turf_visitor_country', function ( $country, $ip ) {
	if ( '' !== $country ) {
		return $country; // Cloudflare already supplied one.
	}

	// Your own local lookup against a database you maintain, e.g.:
	// $reader = new \GeoIp2\Database\Reader( '/path/to/GeoLite2-Country.mmdb' );
	// return $reader->country( $ip )->country->isoCode;

	return $country;
}, 10, 2 );
```

## Updates

Turf isn't on the WordPress.org plugin directory, so it bundles
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(MIT licensed, vendored in `vendor/plugin-update-checker/`) pointed at this
repo's [releases](https://github.com/fbloemhof/turf-stats/releases). Install
once, and you'll get the normal "update available" notice on the Plugins page
whenever a new release is published here - same as a wordpress.org-hosted
plugin.

To cut a release: bump the `Version` header in `turf-stats.php` (and
`TURF_VERSION`), tag it (`vX.Y.Z`), and attach a zip of the plugin folder
(with that exact folder structure - `turf-stats/turf-stats.php`, not the
files at the zip root) to a GitHub release. Pre-releases are ignored.

This is the only network request Turf itself makes that isn't part of
tracking a visitor - it's a periodic "is there a newer version" check, the
same thing WordPress already does for every wordpress.org-hosted plugin,
just pointed at GitHub instead. No visitor or site data is included.

## Requirements

WordPress 6.0+, PHP 7.4+. No other plugins required.

## GDPR

Turf is designed to fit a "legitimate interest" basis (no consent banner
needed) for first-party, aggregate analytics: no cookies, no cross-site
tracking, no raw IP storage, automatic data retention limits. That's not
legal advice — check with whoever maintains your privacy policy, especially
before enabling country detection or any other feature that touches
visitor-identifiable data.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
