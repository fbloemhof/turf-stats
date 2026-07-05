# Turf

Self-hosted, cookieless page-view and click analytics for WordPress. No Google
Analytics, no Jetpack, no external network calls for tracking — everything is
measured and stored in your own database.

The plugin's interface is English by default and ships with a Dutch (`nl_NL`)
translation; on a Dutch site the admin pages show up in Dutch automatically.

---

## Why Turf

- **Cookieless.** Deduplication uses a one-way hash of IP + user-agent, never a
  cookie and never the raw IP. Designed to fit a "legitimate interest" basis
  (no consent banner) — see [GDPR](#gdpr).
- **Self-hosted.** All data lives in your own database; nothing is sent to a
  third party.
- **No external calls for tracking.** The only network request Turf itself makes
  is the periodic "is there a newer version" update check against GitHub — the
  same thing WordPress already does for wordpress.org plugins.
- **Zero configuration.** Every public post type and taxonomy is tracked
  automatically, including ones you add later.

---

## Installation

1. Copy this folder into `wp-content/plugins/turf` (or `turf-stats`) and activate
   it like any other plugin.
2. That's it — views start counting immediately for every public post type and
   taxonomy.

WordPress 6.0+, PHP 7.4+. No other plugins required.

---

## What it tracks

### Audience

- **Page views** for every public post type and taxonomy archive, detected
  automatically. Every *other* real, public, non-404 page (author/date archives,
  search results, the blog index, …) still counts toward the site-wide totals,
  broken out by type in its own "Other pages" box.
- **Deduplicated views** ("Views") — one per visitor per page per rolling window —
  alongside **raw views**, a true count of every browser pageview (repeat hits
  included). Raw views compare apples-to-apples with hit-counting tools like
  Clicky or Jetpack; see [Deduplicated vs. raw views](#deduplicated-vs-raw-views).
- **Visitors** (unique, deduped), **views per visitor**, and **average visit
  duration** (first-to-last pageview plus reading time on the last page).
- **Cache offload** — how much traffic is served from cache instead of running
  PHP. A cached page never reaches WordPress, but its tracking JS still fires,
  so `raw pageviews − origin renders = served from cache`. Shown as a combined
  percentage (it can't be split per layer from the origin) plus badges for the
  caching layers Turf can detect (Cloudflare, SiteGround Optimizer).
- **Device, browser, OS** — parsed from the user-agent already on every request.
- **Language** (from `Accept-Language`) and **country** (from Cloudflare's
  `CF-IPCountry` header when present — no GeoIP database, no extra lookups; see
  [Country detection without Cloudflare](#country-detection-without-cloudflare)).
- **New vs. returning visitors.**
- **Scroll depth and reading time** per page, sent once when a visitor leaves
  (via `navigator.sendBeacon`).

### Acquisition

- **Referrer / traffic source** — direct, internal, search engine, social, or
  other, plus a "top referring sites" list.
- **UTM campaign parameters** (`utm_source`, `utm_medium`, `utm_campaign`).
- **Outbound link tracking** — automatic, no markup needed anywhere: any
  `<a href="…">` pointing at a different hostname is recorded with the full
  destination URL *and* the page the visitor clicked from. An explicit
  `data-turf-click` on a link still takes priority, for sites that want to label
  specific links their own way.
- **REST API views** — views that come in through `/wp-json/wp/v2/…` instead of
  a normal page load (e.g. a companion app), shown as their own "App / REST API"
  bucket. Only single-item `GET` requests count.
- **Dorpsapp** — the "Dorpsapp"/"Doarpsapp" village-app product (used by several
  Dutch local sites) registers its own `doarpsapp/v1` REST namespace; Turf
  recognizes it and shows it as its own bucket rather than the generic REST one.
  For both REST buckets, "Views" reflects real fetch activity but "Visitors"
  does not — these requests come from the connector's own backend server (one
  fixed IP/user-agent), not from individual devices, so Turf shows a note about
  this on the Source breakdown whenever either bucket has data.

> **Recognizing another app by name:** set `define( 'TURF_DEBUG_REST', true );`
> in `wp-config.php` for a while and watch your PHP error log to see exactly what
> a specific integration's requests look like. Remove it again afterwards.

### Behavior

- **Search terms** — what visitors search for on the site's own front-end search,
  and how many results each query found, including a dedicated **zero-result**
  view (a direct signal for missing content or a redirect worth adding).
- **Visitor routes** — consecutive pageviews by the same visitor within a
  30-minute window are chained into sessions and surfaced as the most common
  "from this page, visitors went here next" transitions.
- **Bounce-rate proxy** — the share of sessions with exactly one pageview that
  never clicked onward (skipped for "All", where an unbounded history makes a
  single rate meaningless).
- **Landing pages** — where visits begin (the first pageview of each session),
  with a per-landing bounce rate. Reconstructed from the same sessions as
  visitor routes (post/taxonomy landings only).
- **Peak-hours heatmap** — a 7×24 day/hour grid of when views actually happen, in
  the site's own local time.
- **Trending content** — what's rising fastest right now (last 24h vs. the 24h
  before), independent of the page's period filter.
- **Generic click tracking** for any UI element via a `data-turf-click="<key>"`
  attribute — no extra JS or AJAX wiring per element.
- **Online now** — a live, auto-refreshing count of visitors active in the last
  5 minutes (filterable).

### Content & commerce

- **Per-author statistics** — views, post count, and average reading
  time/scroll depth grouped by post author.
- **Comment counts per period** and a "most discussed" table — read straight
  from WordPress' own comments, no extra tracking needed.
- **Content activity** — how many posts of each type were published or edited
  during the period (editorial activity alongside the audience numbers).
- **WooCommerce funnel** (only when WooCommerce is active) — product views need
  no setup (products are a public CPT already), plus add-to-cart and completed-
  checkout events for a full views → cart → checkout funnel.
- **Form-submission tracking** for Contact Form 7 and/or Gravity Forms — a
  submission counts as a conversion, shown with a conversion rate against the
  page the form last appeared on.
- **Social-share helper** (`turf_social_share_links()`) — ready-made
  Facebook/X/WhatsApp/LinkedIn/email share links, pre-wired into click tracking.

### Bots & LLMs

A separate page tracking how often crawlers visit and what they look at: search
engines (Googlebot, Bingbot, …), AI/LLM crawlers (GPTBot, ClaudeBot,
Google-Extended, PerplexityBot, CCBot, …), social link-preview bots, and SEO
tools. Tracked server-side on every request — most bots, especially LLM
crawlers, never run JavaScript, so the regular AJAX-based tracking can't see
them — and kept completely separate from the human-visitor numbers (which
already deliberately exclude bots). The signature list is filterable
(`turf_bot_signatures`).

### Privacy & retention

- **No cookies.** Deduplication uses a one-way hash of IP + user-agent, never the
  raw IP. The real visitor IP is read from Cloudflare's `CF-Connecting-IP` header
  when present, falling back to `REMOTE_ADDR`.
- **Automatic retention limit.** Raw event rows are pruned after 18 months
  (filterable) — aggregate totals are unaffected and kept forever.

---

## Admin pages

| Page | What's on it |
|---|---|
| **Statistics** | The core audience picture: overview chart, headline stat boxes, the compact device/browser/OS/language/country/referrer/UTM breakdowns (two columns), a cache-offload box, a peak-hours heatmap, and per-post-type/per-taxonomy tables. Defaults to "Today". |
| **Analysis** | The deeper, diagnostic stats: landing pages, search terms (+ zero-result), visitor routes, trending content, per-author stats, form submissions, and the WooCommerce funnel. Kept separate so Statistics doesn't become a wall of boxes. |
| **Clicks** | Top `data-turf-click` keys, plus an "Outbound links" breakdown (full destination URL + source page). |
| **404s** | Top requested-but-missing paths. |
| **Bots & LLMs** | See above. |

Blocks with no data for the selected period are hidden entirely on the
Statistics and Analysis pages, rather than shown as empty placeholders.

**Statistics — "Today" specifics.** The headline boxes are strictly "today"
(since local midnight, vs. yesterday for the %-change), while the daily chart and
peak-hours heatmap show the last 7 days for context (a single day is too sparse
for either). "Today" also adds a distinct-visitors-per-hour line chart. The
headline numbers (Views / Raw views / Visitors / Views per visitor / Comments /
Bounce rate / Avg. time per visit) refresh live every 30 seconds via AJAX, same
idea as "Online now"; everything below stays as rendered on page load.

Every block on every Turf admin page is a real wp-admin postbox: collapsible,
draggable/reorderable (order remembered per user), and individually hideable via
"Screen Options". Colors follow the user's chosen admin color scheme. Lists
collapse behind a "Show more" / "Show less" toggle that expands in place — no
page-reload pagination anywhere. Each list shows 5 rows by default (the 404s
list shows 20) and caps at `turf_list_max` (50) rows total.

---

## Optional setup

### A visible "X views" counter

Add a placeholder to any template where you want a visible counter:

```php
<span id="post-views"></span>
```

Turf's JS fills it in once it knows the count. Without this element the page is
still tracked — it just won't show a number anywhere.

### Click tracking on specific elements

```php
<a href="…" data-turf-click="homepage-cta-button">…</a>
```

### Ready-made social-share links

```php
turf_social_share_links(); // current post, all networks
turf_social_share_links( $post_id, array( 'facebook', 'whatsapp' ) ); // specific post/networks
```

Each link is wired with `data-turf-click="social-share-<network>"`, so those
clicks show up on the Clicks page automatically. Customize networks via the
`turf_social_share_networks` filter.

### Importing historical view counts

```
wp turf-stats import-legacy-views --source=jetpack
wp turf-stats import-legacy-views --source=entry-views
wp turf-stats import-legacy-views --source=all [--force] [--dry-run]
```

`--source=jetpack` needs Jetpack's Stats module still active (it calls Jetpack's
own `stats_get_csv()`), so run it before disconnecting Jetpack if you want to
keep that history.

---

## Deduplicated vs. raw views

Turf reports two view numbers on purpose:

- **Views** is deduplicated — a repeat view of the same page by the same visitor
  within the dedup window (30 minutes by default) doesn't count again. This is
  the closest equivalent to Google Analytics' "unique pageviews".
- **Raw views** counts every browser pageview, repeat hits included — the
  equivalent of a raw hit counter (Clicky, Jetpack).

Only browser pageviews count toward raw views; REST/app fetches and
redirect-time server-side tracking are excluded, since a JavaScript-based tool
like Clicky can't see those either — so the two numbers stay comparable. Raw
views are backed by a small per-hour aggregate table, because deduplication
happens *before* a row is written and can't be reconstructed from the event log
after the fact (so the number only becomes meaningful from the moment you
upgrade to the version that introduced it).

Comparing **Raw views ÷ Views** tells you how much of any gap with another tool
is *method* (deduplication) versus real *loss* (ad-blockers, etc.).

---

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `turf_trackable_post_types` | all public post types except `attachment` | Which post types get tracked |
| `turf_trackable_taxonomies` | all public taxonomies except `post_format` | Which taxonomy archives get tracked |
| `turf_dedup_window` | 30 minutes | How long before a repeat view from the same visitor counts again |
| `turf_retention_months` | 18 | How long raw event rows are kept before pruning (0 disables pruning) |
| `turf_clicks_allowed_keys` | none (any key allowed) | Optional strict allow-list for `data-turf-click` keys |
| `turf_online_now_window` | 5 minutes | How recent a view has to be to count toward "online now" |
| `turf_dorpsapp_route_patterns` | `doarpsapp/v1/{posts,events,info}/…` | Route patterns recognized as Dorpsapp single-item requests |
| `turf_bot_signatures` | see `includes/bots.php` | Known bot/LLM user-agent signatures, grouped by category |
| `turf_visitor_country` | `''` | Supply a country code when Cloudflare's `CF-IPCountry` header isn't present |
| `turf_session_gap_seconds` | 30 minutes | Gap between two pageviews that still counts as the same session |
| `turf_session_row_limit` | 20000 | Max rows pulled into PHP for session reconstruction (bounce rate, routes, landing pages) |
| `turf_list_max` | 50 | Max rows any in-box list fetches (the "Show more" toggle expands up to this) |
| `turf_cache_environment` | Cloudflare / SiteGround auto-detection | Add/override the caching layers shown as detected on the cache-offload box |
| `turf_referrer_app_labels` | see `includes/views-admin.php` | Android/iOS app package names mapped to friendly labels in the referrer list |
| `turf_social_share_networks` | Facebook/X/WhatsApp/LinkedIn/email | Customize or add networks for `turf_social_share_links()` |

---

## Country detection without Cloudflare

Country detection is free (no extra lookup) on sites behind Cloudflare. On sites
that aren't, `turf_get_country()` returns `''` unless you hook
`turf_visitor_country` with your own **local** lookup. Turf deliberately doesn't
call a live geolocation API itself — that would mean sending visitor IPs to a
third party, defeating the point of a no-external-calls plugin. Bring your own
local database instead, e.g.
[MaxMind's GeoLite2 PHP API](https://github.com/maxmind/GeoIP2-php) or a
CSV-based dataset like [DB-IP's country-lite](https://db-ip.com/db/lite.php):

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

---

## Translations

Source strings are English. A Dutch (`nl_NL`) translation ships in
`languages/turf-stats-nl_NL.po` / `.mo` and loads automatically on Dutch sites;
English is the fallback everywhere else. To add another language, translate the
`.po` against text domain `turf-stats` and drop the compiled `.mo` in
`languages/` (or in `wp-content/languages/plugins/`).

---

## Updates

Turf isn't on the WordPress.org directory, so it bundles
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(MIT, vendored in `vendor/plugin-update-checker/`) pointed at this repo's
[releases](https://github.com/fbloemhof/turf-stats/releases). Install once and
you'll get the normal "update available" notice whenever a new release is
published here.

To cut a release: bump the `Version` header in `turf-stats.php` (and
`TURF_VERSION`), tag it (`vX.Y.Z`), and attach a zip of the plugin folder (with
the `turf-stats/turf-stats.php` structure, not the files at the zip root) to a
GitHub release. Pre-releases are ignored.

This update check is the only network request Turf makes that isn't part of
tracking a visitor. No visitor or site data is included.

---

## GDPR

Turf is designed to fit a "legitimate interest" basis (no consent banner) for
first-party, aggregate analytics: no cookies, no cross-site tracking, no raw IP
storage, automatic data-retention limits. That's not legal advice — check with
whoever maintains your privacy policy, especially before enabling country
detection or anything else that touches visitor-identifiable data.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
