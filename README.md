# Turf

Self-hosted, cookieless page-view and click analytics for WordPress. No
Google Analytics, no Jetpack, no external network calls — everything is
tracked and stored on your own database.

## Features

- **Page views** for every public post type and taxonomy archive, detected
  automatically (no config needed when you add a new CPT or taxonomy later).
  Every *other* real, public, non-404 page (author/date archives, search
  results, the blog index, or anything else not covered by the above)
  still counts towards the site-wide totals and breakdowns too, broken out
  by type in its own "Overige pagina's" box - just without the per-object
  detail a specific post/term gets.
- **Visitors** (unique, deduped per rolling window) alongside deduped views.
- **Raw views** — the deliberate counterpart to the deduplicated "Weergaven"
  number: a true count of every browser pageview (repeat hits from the same
  visitor included), so it can be compared apples-to-apples against tools that
  count every hit (Clicky, Jetpack). Only browser pageviews count towards it -
  REST/app fetches and redirect-time server-side tracking are excluded, since
  a JavaScript-based tool like Clicky can't see those either. Deduped views
  answer "how many distinct things got looked at"; raw views answer "how many
  page loads happened".
- **Views per visitor** and **average visit duration** — the deduped views
  divided by visitors, and the average session length (time from a visit's
  first to its last pageview plus the reading time recorded on the last page).
  Both shown as headline stat boxes.
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
  lumping them into the generic REST bucket above. "Weergaven" for this
  bucket (and the generic REST one) reflects real fetch activity, but
  "Bezoekers" doesn't - these requests come from the connector's own
  backend server (one fixed IP/user-agent, e.g. literally
  "DorpsApp-Backend/1.0"), not from individual app users' own devices, so
  they can't be told apart the way a normal page load's visitor can. Turf
  shows a note about this on the Herkomst breakdown whenever either bucket
  has data.
- To find out exactly what a specific app's requests look like (so you can
  recognize it by name too), set `define( 'TURF_DEBUG_REST', true );` in
  `wp-config.php` for a while and watch your PHP error log - remove it again
  once you've got what you need.
- **Generic click tracking** for any UI element, via a `data-turf-click="<key>"`
  attribute — no extra JS or AJAX wiring needed per element.
- **Outbound link tracking** — automatic, no markup needed anywhere in the
  theme or post content: any `<a href="...">` pointing at a different
  hostname gets tracked, recording the full destination URL together with the
  page the visitor was on when they clicked (so the report shows both "which
  external link" and "from where"). An explicit `data-turf-click` on a link
  still takes priority over this, for sites that want to label specific
  outbound links their own way.
- **Comment counts per period** and a "most discussed" table — reads
  directly from WordPress' own comments, no extra tracking needed.
- **Online now** — a live, auto-refreshing count of visitors active in the
  last 5 minutes (filterable), shown at the top of the Statistieken page.
- **Search terms** — what visitors search for on the site (WP's own front-end
  search) and how many results each query found, including a dedicated view
  of zero-result searches - a direct signal for missing content or a
  redirect worth adding.
- **Visitor routes** — consecutive pageviews by the same visitor within a
  30-minute window are chained into sessions, surfaced as the most common
  "from this page, visitors went to this page next" transitions.
- **Bounce-rate proxy** — the share of sessions that had exactly one
  pageview and never clicked onward, derived from the same session
  reconstruction (skipped for "Alles", where an unbounded history makes a
  single rate not very meaningful).
- **Peak-hours heatmap** — a 7×24 day/hour grid of when views actually
  happen, in the site's own local time.
- **Trending content** — what's rising fastest right now (last 24h vs. the
  24h before that), independent of the page's period filter.
- **Per-author statistics** — views, post count, and average reading
  time/scroll depth grouped by post author.
- **WooCommerce integration** (only when WooCommerce is active) — product
  views need no setup (products are just a public CPT, already covered),
  plus add-to-cart and completed-checkout events for a full
  views → cart → checkout funnel. No external services involved.
- **Form-submission tracking** for Contact Form 7 and/or Gravity Forms - a
  submission counts as a conversion event, shown with a conversion rate
  against the page the form last appeared on.
- **Social-share helper** (`turf_social_share_links()`) - ready-made
  Facebook/X/WhatsApp/LinkedIn/email share links, pre-wired into the
  existing click tracking.
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

### Optional: ready-made social-share links

```php
turf_social_share_links(); // current post, all networks
turf_social_share_links( $post_id, array( 'facebook', 'whatsapp' ) ); // specific post, specific networks
```

Outputs Facebook/X/WhatsApp/LinkedIn/email share links, each already wired
with `data-turf-click="social-share-<network>"` - no extra setup needed for
those clicks to show up on the Klikken page. Customize or add networks via
the `turf_social_share_networks` filter.

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

- **Statistieken** — the core audience picture: overview chart, the compact
  device/browser/OS/language/country/referrer/UTM breakdowns (in two
  columns), a peak-hours heatmap, and per-post-type/per-taxonomy tables
  (with average reading time/scroll depth). Defaults to "Vandaag" (today,
  since midnight, vs. yesterday for the %-change) - every other admin page
  keeps defaulting to "7 dagen". "Vandaag" is also selectable on every
  other page's own period tabs. The chart and peak-hours heatmap show the
  last 7 days for context even on "Vandaag" (a single day is too sparse
  for either to be meaningful) - only the headline stat boxes are
  strictly "today". "Vandaag" additionally shows a distinct-visitors-per-hour
  line chart (a single day of daily bars would just be one bar). Those
  headline numbers (Weergaven/Rauwe weergaven/Bezoekers/Weergaven per
  bezoeker/Reacties/Bouncepercentage/Gem. tijd per bezoek) refresh live every
  30 seconds via AJAX, same idea as "Nu online" - the chart, heatmap, and
  every breakdown/table below stay as rendered on page load.
- **Content-activiteit** — its own box right under Overzicht: how many
  posts of each trackable type were published or edited during the
  period, one row per type that actually had activity (skipped entirely
  on a quiet period). Not a visitor metric - editorial activity alongside
  the audience numbers.
- **Analyse** — the deeper, more diagnostic stats: search terms (+
  zero-result searches), visitor routes, trending content, per-author
  stats, form submissions, and the WooCommerce funnel (when applicable).
  Kept on its own page so Statistieken doesn't turn into an overwhelming
  wall of boxes.
- **Klikken** — top `data-turf-click` keys.
- **404's** — top requested-but-missing paths, 20 per page (every other
  table on every other page shows 5, with a "Toon meer"/pagination
  pattern - 404 hunting tends to need a longer list at a glance).
- **Bots & LLM's** — see above.

Every block on every Turf admin page is a real wp-admin postbox: collapsible
(click the title), draggable/reorderable (drag the title, order is remembered
per user - the same mechanism the Dashboard widgets use), and individually
hideable via "Schermopties" in the top-right corner. Colors follow whichever
admin color scheme the user has picked (Default/Light/Blue/Coffee/Ectoplasm/
Midnight/Ocean/Sunrise) instead of a fixed color. Lists longer than 5 items
collapse behind a "Toon meer" link to keep blocks compact.

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
| `turf_session_gap_seconds` | 30 minutes | How long a gap between two pageviews from the same visitor still counts as the same session |
| `turf_session_row_limit` | 20000 | Max rows pulled into PHP for session reconstruction (bounce rate, visitor routes) |
| `turf_referrer_app_labels` | see `includes/views-admin.php` | Known Android/iOS app package names ("Referer: android-app://...") mapped to a friendly label in the Top verwijzende sites list |
| `turf_social_share_networks` | Facebook/X/WhatsApp/LinkedIn/email | Customize or add networks for `turf_social_share_links()` |

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
