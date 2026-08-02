# CiteCue AI Auto-Fix — WordPress plugin

Middleware between your WordPress site and the AI web. The plugin:

1. **Serves AI-optimized pages to AI bots and crawlers.** When GPTBot, ClaudeBot, PerplexityBot, ChatGPT-User and friends request a page, the plugin fetches the CiteCue-optimized version of that page from the [CiteCue app](https://github.com/henry-mosh/citecue_app) delivery API and serves it instead of the theme output. Human visitors always get your normal site, and any miss, timeout or CiteCue outage falls straight through to the normal page — the integration can never break your site.
2. **Publishes your `llms.txt`** ([llmstxt.org](https://llmstxt.org) convention) at `https://your-site.com/llms.txt`, generated and kept current by CiteCue.
3. **Accepts new content pushed by CiteCue** — content briefs, FAQ packs and other gap-filling pages that promote your brand where AI answers currently miss it — through a signed REST endpoint. Pushed content lands as a **draft** by default so nothing goes live without review.

## How it works

```
AI crawler (GPTBot, ClaudeBot, …)                Human visitor
        │                                              │
        ▼                                              ▼
┌─────────────────────────── WordPress ───────────────────────────┐
│  CiteCue plugin (template_redirect)                             │
│  UA matches AI-crawler registry?  ──no──►  normal theme output  │
│        │yes                                                     │
│        ▼                                                        │
│  GET {app}/api/delivery/v2/page?k=…&u=…&b=…                     │
│  Authorization: Bearer ck_live_…   X-Citecue-Channel: wordpress │
│        │200/304: serve optimized HTML (x-citecue: served)       │
│        │404 miss / timeout / error: normal theme output         │
└──────────────────────────────────────────────────────────────────┘
```

- **One request serves and reports.** The v2 delivery endpoint records the crawler hit server-side (`served` for 200/304, `passthrough` for a miss), so CiteCue's Agent Traffic dashboard stays accurate with no extra beacon.
- **Conditional revalidation.** Optimized bodies are cached locally with their ETag; revalidation is a cheap 304 round-trip. Misses are negative-cached for 60 s (mirroring the API's `max-age=60` miss sentinel).
- **Circuit breaker.** A timeout or 5xx opens a 60 s circuit (10 min on a rejected key): no API calls, stale cache served when available, plain pass-through otherwise. A CiteCue outage never slows human traffic — the API is only ever called for AI-crawler requests in the first place.
- **Abuse-bounded.** Cache keys use CiteCue-compatible URL normalization (tracking params, `www.`, trailing slashes deduped), and outbound lookups are capped by a per-minute budget shared across the crawler and llms.txt paths (default 120, filterable) — neither a spoofed crawler UA spraying unique URLs nor a flood on `/llms.txt` can force unbounded API calls. When CiteCue reports a page is no longer optimized, its cached copy is evicted immediately.
- **Crawler registry.** A bundled token list ships with the plugin and refreshes daily from the public `GET /api/delivery/v1/crawlers` feed, so newly added crawlers are served without a plugin update.
- **Verification-compatible.** Served pages carry `X-Citecue: served` and llms.txt carries `X-Citecue: llms-txt` — the headers CiteCue's *Verify installation* button probes for.

## Setup

1. Install and activate the plugin (upload this repo as a zip or drop it into `wp-content/plugins/`).
2. Open **Settings → CiteCue** and click **Connect to CiteCue**.
3. Confirm the project for this site in CiteCue. You are redirected back, and the plugin checks itself.

That is the whole setup. Nothing is copied or pasted in either direction: the handshake brings the API key and project back to WordPress, and hands CiteCue this site's address and content-push secret on the way through.

Then add or generate optimized pages on CiteCue's Auto-Fix page.

### How the handshake works

```
WordPress: [Connect to CiteCue]
        │  browser redirect
        ▼
{app}/connect/wordpress?site=…&state=…&return=…&v=…
        │  admin confirms the project; CiteCue mints a per-site key
        ▼  redirect back to `return` with ?citecue_code=…&citecue_state=…
WordPress verifies the state, then server-to-server:
        POST {app}/api/delivery/v2/connect/claim
             { code, site_url, rest_url, ingest_secret, plugin_version, woocommerce }
          →  { apiKey, publicKey, domain, ingest }
```

- **`state`** is minted by the plugin, stored server-side, bound to the administrator who started the handshake, single-use and valid for 15 minutes. It is the CSRF defence on the return leg, which cannot carry a WordPress nonce because it originates at CiteCue.
- **The code is bearer-grade** — whoever presents it receives an API key — so it is single-use, short-lived, and CiteCue binds it to the `site_url` presented at claim time.
- **The ingest secret only ever travels in the claim body**, server-to-server, never through a browser redirect. That request does not follow redirects, so a redirect cannot replay it at another host.
- **Ingest stays off unless CiteCue's connect screen says otherwise** (`ingest: true`). A response that omits the field never grants write access — silence is not consent.

The full server-side contract is in [`docs/connect-handshake.md`](docs/connect-handshake.md).

### Verifying

**Settings → CiteCue → Verify installation** requests this site's own `/llms.txt` as GPTBot and requires exactly `x-citecue: llms-txt` back. The result is shown on the settings screen and re-checked automatically right after connecting.

The strictness matters: when llms.txt falls through — switched off here, or no llms.txt for the project on CiteCue — the crawler proxy is next on `template_redirect` and can answer the same URL with `x-citecue: served`. Accepting any marker would read that as proof llms.txt works, which is what it disproves. With `Serve llms.txt` switched off the check reports that it could not run, rather than a failure the site did not have.

By hand:

```bash
curl -si -A GPTBot https://your-site.com/llms.txt        # expect: x-citecue: llms-txt
curl -si -A GPTBot https://your-site.com/optimized-page/ # expect: x-citecue: served
```

### Connecting with an API key instead

An install that cannot bounce a browser through CiteCue — an intranet site, a locked-down staging host — can still connect the original way: **Connect with an API key instead** on the settings screen takes a `ck_live_…` organization key (CiteCue → Settings → API keys) and selects the project whose domain matches the site. CiteCue does not learn the ingest secret this way, so content pushes need it copied over from **Connection details → Shared secret**.

To pin the CiteCue origin for a self-hosted deployment, define it in `wp-config.php` — the settings field then shows it read-only rather than inviting an edit:

```php
define( 'CITECUE_API_BASE', 'https://citecue.example.com' );
```

## Content push API (create posts)

`POST {site}/wp-json/citecue/v1/content` with an HMAC-SHA256 signature. Enable it and copy the shared secret under **Settings → CiteCue → Content from CiteCue**.

**Authentication headers**

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Citecue-Timestamp` | Unix seconds; rejected when more than ±300 s off |
| `X-Citecue-Signature` | `sha256=` + hex `HMAC_SHA256("{timestamp}.{raw_body}", secret)` |

Signatures are **single-use**: replaying a captured request is rejected with `401 citecue_replayed`. Retries must recompute the timestamp (and therefore the signature).

**Body**

| Field | Type | Notes |
|---|---|---|
| `external_id` | string, required | Stable id; pushes with the same id update the same post |
| `title` | string, required | |
| `content` | string, required | Post body HTML (sanitized with `wp_kses_post`) |
| `excerpt` | string | |
| `slug` | string | |
| `status` | `draft`\|`pending`\|`publish` | Capped by the "Maximum status" setting (default `draft`) |
| `type` | `post`\|`page`\|`product` | Default from settings; `product` requires WooCommerce |
| `categories` | string[] | Term names, created if missing (posts → `category`, products → `product_cat`) |
| `tags` | string[] | Term names (posts → `post_tag`, products → `product_tag`) |
| `sku` | string | Products only; also matches an existing product to adopt (see below) |
| `regular_price` | string | Products only |
| `meta_description` | string | Stored as `_citecue_meta_description`; printed as `<meta name="description">` unless an SEO plugin is active |
| `source` | string | Provenance label, e.g. `content_brief:opp_123` |
| `force` | bool | Overwrite even if the post was edited in WordPress since the last push (otherwise → `409 citecue_edited_locally`) |

**Example**

```bash
SECRET='cws_…'   # from Settings → CiteCue
BODY='{"external_id":"faq-pack-1","title":"Acme FAQ","content":"<h2>What is Acme?</h2><p>…</p>","source":"faq_pack:opp_42"}'
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)

curl -X POST https://your-site.com/wp-json/citecue/v1/content \
  -H "Content-Type: application/json" \
  -H "X-Citecue-Timestamp: $TS" \
  -H "X-Citecue-Signature: sha256=$SIG" \
  -d "$BODY"
```

Responses: `201` created / `200` updated (`{created, updated, post_id, status, permalink, edit_link}`), `400` product push without WooCommerce, `401` bad signature or stale timestamp, `403` ingest disabled, `409` edited locally / SKU exists without `force` / type conflict, `410` push was trashed in WordPress, `429` rate-limited (120/hour, filterable).

There is also a public handshake endpoint: `GET /wp-json/citecue/v1/health` → `{plugin, version, delivery, ingest, woocommerce}`.

## WooCommerce

With WooCommerce active:

- **Store pages are protected.** The middleware never intercepts cart, checkout (including order-pay/order-received), account pages or any other WooCommerce endpoint, and skips `?add-to-cart=` links and `wc-ajax` calls. Product pages, the shop archive and category pages are served optimized like any other page — they are the highest-value AI-crawler targets.
- **Products can be pushed.** `type: "product"` creates a draft simple product through WooCommerce's CRUD API (`title` → name, `content` → description, `excerpt` → short description, plus `sku`, `regular_price`, `product_cat`/`product_tag` terms). The same status cap applies.
- **Existing products can be enriched.** When a push's `sku` matches an existing product not previously pushed, the plugin refuses with `409 citecue_sku_exists` unless `force: true` is sent — adopting a product deliberately requires an explicit opt-in because its description gets replaced. After adoption, updates flow by `external_id` like any other push.

## CiteCue API surface consumed

| Endpoint | Auth | Used for |
|---|---|---|
| `POST /api/delivery/v2/connect/claim` | one-time code | Pairing handshake: code → this site's API key + project |
| `GET /api/delivery/v2/config` | `Bearer ck_live_…` | Connection test + project auto-selection by domain |
| `GET /api/delivery/v2/page?k&u&b` | `Bearer ck_live_…` + `X-Citecue-Channel: wordpress` | Optimized page for a crawler request (ETag/304; 404 = pass through; hit recorded server-side) |
| `GET /api/delivery/v2/llms.txt?k` | `Bearer ck_live_…` | llms.txt body (ETag/304) |
| `GET /api/delivery/v1/crawlers` | none (public) | Daily AI-crawler UA token refresh |

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `citecue_pinned_api_base` | filter | Fix the CiteCue app origin from code (defaults to the `CITECUE_API_BASE` constant); a non-empty value makes the settings field read-only |
| `citecue_crawler_tokens` | filter | Add/remove AI-crawler UA tokens |
| `citecue_matched_crawler` | filter | Override per-request crawler matching |
| `citecue_should_serve` | filter | Veto serving for a specific request |
| `citecue_serve_timeout` | filter | Delivery API timeout on the serving path (default 3 s) |
| `citecue_lookup_budget` | filter | Max delivery API lookups per minute across the crawler and llms.txt paths combined (default 120); beyond it, requests are answered from cache or passed through |
| `citecue_ingest_postarr` | filter | Adjust the post array before insert/update |
| `citecue_ingest_rate_limit` | filter | Ingest requests allowed per hour (default 120) |
| `citecue_output_meta_description` | filter | Control the meta-description tag for pushed content |

## Performance

The delivery API is only ever called for an AI-crawler request or for `/llms.txt`. A human page view does no HTTP, no extra database query and no cache lookup — the middleware returns as soon as the User-Agent fails to match, having done nothing but a substring scan over the crawler tokens (which travel in an autoloaded option WordPress has already read).

For a crawler request the cost is one API call, with a 3 s timeout, and only when the local cache cannot answer: optimized bodies are cached for 24 h and revalidated with an ETag, misses are negative-cached for 60 s, and llms.txt is treated as fresh for 5 minutes. A repeat crawl of a cached page is a 304, not a re-download.

- **A persistent object cache is recommended.** Cached bodies are transients. With Redis or Memcached they never touch the database. Without one they are rows in `wp_options` — full HTML documents, one per crawled URL, for up to 24 h. They are not autoloaded, so they cost nothing per request, but a heavily crawled site can hold tens of megabytes there until WordPress's twice-daily transient cleanup runs.
- **The outbound-call ceiling is per site, not per path.** The 120/minute budget covers crawler lookups and llms.txt together, so no mix of traffic can exceed it.

## What happens if CiteCue is unavailable

Nothing that a visitor can see. The first failing request waits at most 3 s and then opens the circuit for 60 s (10 minutes if the API key was rejected). While it is open the plugin makes no API calls at all: it serves the cached body if it has one — marked `X-Citecue-Cache: stale` — and otherwise falls straight through to normal theme output. Because bodies are kept for 24 h, crawlers keep receiving optimized pages through a short outage.

The worst case for an AI crawler is one 3 s wait per minute. For a human visitor there is no worst case: the API is never consulted on their behalf.

## Notes & caveats

- **Full-page caches / CDNs:** a page cache that serves HTML before WordPress loads will answer AI crawlers with the cached human version. Exclude the AI-crawler user agents from your page cache, or rely on CiteCue's Cloudflare Worker install instead of this plugin when your cache sits in front of PHP. Responses served by this plugin set `DONOTCACHEPAGE` and `Cache-Control: private, no-store` so they are never stored for humans.
- **Physical `llms.txt`:** a real file in the web root is served by the web server before WordPress runs and therefore wins over the plugin.
- **Subdirectory installs:** llms.txt is served at the WordPress root (e.g. `/blog/llms.txt`); the domain-root convention requires a root install (or the Cloudflare Worker).
- **Uninstall** removes plugin options and scheduled events; content pushed by CiteCue is your content and is kept.

## Development

Plain PHP ≥ 7.4, no build step. Repo root is the plugin root, so the checkout can be symlinked straight into `wp-content/plugins/`.

### Tests

The suite is WordPress integration tests: real options, transients, REST requests and query conditionals, with the CiteCue API faked at the `wp_remote_get` layer (`tests/includes/class-citecue-http-mock.php`) so no test ever touches the network. WordPress core and its test library both come from Composer — there is nothing to download by hand.

```bash
composer install
mysqladmin create wordpress_test -uroot          # any empty database will do
composer test
```

Point it at a different database with `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`, `WP_TESTS_DB_HOST` (see `tests/wp-tests-config.php`).

`composer test` runs the suite twice, because whether WooCommerce exists is a process-wide fact rather than something a single test can toggle:

| Command | Covers |
|---|---|
| `composer test:core` | Everything, with no WooCommerce present |
| `composer test:woocommerce` | Adds a minimal WooCommerce stand-in so the store-page exclusion rules are exercised |
| `CITECUE_WITH_WOOCOMMERCE=1 composer test:core` | Product pushes against a real WooCommerce — needs `composer require --dev wpackagist-plugin/woocommerce` |

Other commands:

```bash
composer lint     # php -l over every file
composer phpcs    # WordPress coding standards + PHP 7.4 compatibility
composer phpcbf   # auto-fix what phpcs can
```

CI runs the static checks plus the suite on PHP 7.4/8.2/8.4 against current WordPress, on PHP 7.4/8.3 against WordPress 5.9/6.5, and a separate job against a real WooCommerce. WordPress 5.9 is the oldest version the automated suite can cover — WordPress's own test library only supports PHPUnit 9 from 5.9 onwards — so the declared 5.8 floor rests on the PHPCompatibility checks and manual verification.

### Testing an install by hand

```bash
curl -si -A GPTBot https://your-site.com/llms.txt        # expect: x-citecue: llms-txt
curl -si -A GPTBot https://your-site.com/optimized-page/ # expect: x-citecue: served
curl -s https://your-site.com/wp-json/citecue/v1/health  # plugin/version/delivery/ingest/woocommerce
```

### Structure notes

`Citecue_Proxy` and `Citecue_Llms_Txt` each split into a `decide()` that returns what should happen and a `serve()` that emits headers and calls `exit`. All the branching lives in `decide()`, which is what the tests drive; `serve()` stays deliberately trivial because nothing can assert against a request that has already ended.
