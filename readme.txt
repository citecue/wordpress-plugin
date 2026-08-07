=== CiteCue AI Auto-Fix ===
Contributors: citecue
Tags: ai, llms.txt, gptbot, ai-seo, woocommerce
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve AI-optimized versions of your pages to AI bots and crawlers, enrich your live pages' SEO metadata, publish your llms.txt, and receive draft content from CiteCue.

== Description ==

CiteCue AI Auto-Fix connects your WordPress site to CiteCue:

* **AI crawler middleware** — when an AI bot or crawler (GPTBot, ClaudeBot, PerplexityBot, ChatGPT-User and more) requests a page, the plugin serves the CiteCue-optimized version of that page. Human visitors always see your normal site. Any miss, timeout or outage passes straight through to the normal page.
* **Enriched page metadata** — adds CiteCue's title, meta description, OpenGraph, canonical and structured-data tags to your live pages, so search engines and AI answer engines see them on the page a human sees. It fills gaps only: any tag WordPress, your theme or your SEO plugin already outputs is left exactly as it is, so there is never a second title or canonical.
* **llms.txt** — publishes the llms.txt file CiteCue generates for your brand at your site root.
* **Content from CiteCue** — a signed endpoint through which CiteCue can push new brand-building content (content briefs, FAQ packs, gap-filling pages) into WordPress as drafts for your review.
* **WooCommerce-aware** — cart, checkout, account pages and cart-modifying links are never intercepted, while product and shop pages are served optimized. Pushed content can also create or enrich WooCommerce products (draft by default, matched by SKU with explicit consent).

This plugin requires a CiteCue account (citecue.com) and does nothing until you connect one. See "External services" below for exactly what is sent where.

== Installation ==

1. Install and activate the plugin from Plugins → Add New, or upload it under Plugins → Add New → Upload Plugin.
2. Go to Settings → CiteCue and click "Connect to CiteCue".
3. Confirm the project for this site in CiteCue. You are redirected back and the plugin checks itself.
4. Add and generate optimized pages on CiteCue's Auto-Fix page.

There is nothing to copy or paste: the connection brings the API key back to WordPress and hands CiteCue this site's address and content-push secret. Sites that cannot complete a browser round-trip to CiteCue can still connect with an organization API key — "Connect with an API key instead" on the settings screen.

Until you complete step 2, the plugin makes no outbound requests at all.

== External services ==

This plugin is the WordPress end of CiteCue, a hosted service at https://citecue.com that generates AI-optimized versions of your pages. The optimized pages, your llms.txt and the pushed draft content are all produced by that service, so the plugin cannot work without it. Nothing below happens until an administrator connects the site.

Terms of Service: https://citecue.com/terms
Privacy Policy: https://citecue.com/privacy

The service is reached at `https://app.citecue.com` (or the origin you pin with the `CITECUE_API_BASE` constant, for self-hosted CiteCue deployments).

**Connecting the site** — once, when an administrator clicks "Connect to CiteCue". Your browser is sent to `app.citecue.com/connect/wordpress` with this site's address so CiteCue can show you which project you are pairing. WordPress then posts to `/api/delivery/v2/connect/claim`: the one-time code from that redirect, this site's address, its REST API address, this site's content-push secret, the plugin version, whether WooCommerce is active, and whether enriched page metadata is switched on. CiteCue returns the API key it issued for this site. The API-key fallback instead sends the key you paste to `/api/delivery/v2/config`, which returns your organization's projects.

**Serving a page to an AI crawler** — on each request from a matched AI crawler, and never for a human visitor or a logged-in user. The plugin sends the requested URL, the matched crawler's User-Agent token and the site's project key to `/api/delivery/v2/page`. No visitor data — no IP address, no cookies, no personal data — is sent. CiteCue records the crawler hit so it can report it back to you. Responses are cached, misses are remembered for a minute, and a per-minute budget caps the total.

**Enriching a page's metadata** — in the background, on WP-Cron, for a URL a visitor has requested while enriched metadata is switched on. The requested URL and the site's project key are sent to `/api/delivery/v2/seo-head`. No visitor data — no IP address, no cookies, no personal data — is sent, and this never happens while a visitor is waiting: a page with no cached block yet is rendered untouched and the fetch is queued for afterwards. Responses are cached, empty answers are remembered for a minute, and the same per-minute budget caps the total.

**Serving llms.txt** — when `/llms.txt` is requested and the feature is on. The site's project key is sent to `/api/delivery/v2/llms.txt`. The response is cached.

**Refreshing the AI-crawler list** — once a day, on WP-Cron, for a connected site only. An unauthenticated request to `/api/delivery/v1/crawlers` fetches the current list of AI crawler User-Agent tokens, so newly launched crawlers are recognised without a plugin update.

**Verifying the installation** — when you connect, and whenever you click "Verify installation". The plugin requests your own site's `/llms.txt` over HTTP, identifying itself as an AI crawler, to confirm the plugin answers rather than a cache or CDN. This request goes to your site, not to CiteCue.

Every outbound request identifies itself with a `CiteCue-WordPress/<version> (+<your site URL>)` User-Agent.

In the other direction: when content pushes are enabled, CiteCue sends new content to this site's `citecue/v1` REST route. Each request is signed with the shared secret exchanged during connection, replayed signatures are rejected, and the content is created as a draft unless you raise that limit yourself.

== Frequently Asked Questions ==

= Do I need to create an API key by hand? =

No. Clicking "Connect to CiteCue" issues a key for this site and stores it for you. The manual route stays available for installs that cannot redirect through CiteCue.

= How do I check it is working? =

Settings → CiteCue → "Verify installation" requests your own llms.txt as an AI crawler and confirms the plugin answered. It runs automatically right after you connect. The usual cause of a failure is a full-page cache or CDN answering before WordPress loads.

To check a page by hand, request one you have optimized on CiteCue's Auto-Fix page and look for the "x-citecue: served" header:

`curl -si -A GPTBot https://your-site.com/a-page-you-optimized/ | grep -i x-citecue`

Use curl or a logged-out browser — logged-in users always get the normal site.

= My llms.txt works, but pages are not being served. Why? =

Almost always because CiteCue has no optimized version of that URL yet. Connecting sets up delivery; it does not generate pages. Add and generate them on CiteCue's Auto-Fix page, then re-test — waiting a minute first, because a miss is remembered for 60 seconds and an immediate retry will not call CiteCue at all.

Settings → CiteCue → "Recent AI crawler activity" tells you which it is. A "passthrough" row for the URL means the plugin ran and CiteCue reported no optimized page. An "error" row means the call to CiteCue failed. No row at all can mean either that the plugin declined before calling CiteCue (a recent miss, a backed-off connection, serving switched off) or that a full-page cache or CDN answered before WordPress ran.

= Will human visitors ever see the optimized version? =

No. Only requests whose User-Agent matches the AI-crawler registry are served the optimized *page*, and those responses are never cached for regular traffic. Enriched metadata is different and deliberately so: those are head-only tags describing the page a visitor is already looking at, so they are added for everyone, including Google. The visible page is never altered.

= Will this conflict with Yoast SEO, Rank Math, All in One SEO or SEOPress? =

No. CiteCue reads what your theme, WordPress and your SEO plugin actually printed into `<head>`, and adds only the tags none of them emitted — checking the output rather than looking for a particular plugin, so it is equally correct with an SEO plugin nobody has heard of. A site where Yoast already handles the title, description, canonical, OpenGraph and schema gets nothing added, which is the right answer. A site where it handles the basics but emits no OpenGraph gets the OpenGraph tags.

CiteCue's tags carry a `data-citecue` attribute, so View Source tells you exactly which ones it added.

To hand a slot back to CiteCue, remove your SEO plugin's copy of that tag and re-add CiteCue's through the `citecue_seo_head_tags` filter. To switch the whole thing off, untick "Enrich page metadata" under Settings → CiteCue.

= What happens if CiteCue is down? =

Nothing visible: a circuit breaker stops API calls for a minute and every crawler request falls through to your normal page (or a cached optimized copy).

= Does pushed content go live automatically? =

By default it is created as a draft. You can raise the cap to "Pending review" or "Published" in the settings.

= Is WooCommerce supported? =

Yes. Store pages (cart, checkout, account, all WooCommerce endpoints) are never intercepted, and product/shop pages are served optimized like any other page. With WooCommerce active, pushed content may also create draft products or enrich existing ones (matched by SKU, only with an explicit force flag).

== Upgrade Notice ==

= 1.1.0 =
Adds enriched page metadata for live pages. Existing connections need one reconnect before CiteCue knows this site can do it — Settings → CiteCue will ask.

= 1.0.1 =
The plugin folder is now citecue-ai-auto-fix. If you installed 1.0.0 by uploading the zip from GitHub, delete the old citecue folder after updating — your settings and connection are stored in the database and carry over untouched.

== Changelog ==

= 1.1.0 =
* New: enriched page metadata. CiteCue's title, meta description, OpenGraph, canonical and structured-data tags are added to your live pages, so search engines and AI answer engines see them, not just AI crawlers. Uses CiteCue's `/api/delivery/v2/seo-head` endpoint.
* Fills gaps only: anything WordPress, your theme or your SEO plugin already prints is left untouched, so the plugin never emits a second title or canonical. Detection reads the real `<head>` output rather than looking for particular plugins.
* Never delays a page: the render path reads cache only. A URL with nothing cached yet renders untouched and the fetch is queued for afterwards. Cached blocks survive a CiteCue outage and are served while a refresh runs.
* The connection now tells CiteCue whether this site injects metadata, so CiteCue stops reporting fixes as reaching human visitors when nothing puts them on the page. Sites connected before this release are asked to reconnect once.
* New filters: `citecue_should_inject_seo_head` (skip a page) and `citecue_seo_head_tags` (change what is printed).

= 1.0.3 =
* No functional change. Annotates the DONOTCACHEPAGE definitions so code-quality tooling stops reporting a naming-convention violation the constant cannot avoid — page caches look for that exact name.

= 1.0.2 =
* Translations no longer depend on the plugin loading them by hand; WordPress.org supplies them for the plugin slug.
* The requested URL is sanitized on the way to CiteCue in a way that preserves percent-encoding, so pages with accented or non-Latin characters in the address are served and cached under the address they actually have.

= 1.0.1 =
* First release in the WordPress.org plugin directory.
* An install that has not been connected to CiteCue now makes no outbound requests of any kind: the daily crawler-registry refresh waits for a connection.
* The plugin folder and text domain are now citecue-ai-auto-fix, matching the WordPress.org slug, so translations from translate.wordpress.org load. Settings and the connection are unaffected.
* A second copy of the plugin installed in another folder stands down with an admin notice instead of taking the site down.
* readme.txt now documents every request exchanged with CiteCue, and links the terms and privacy policy.

= 1.0.0 =
* Initial release: AI-crawler delivery middleware (CiteCue delivery API v2), llms.txt serving, signed content-ingest endpoint, daily crawler-registry refresh, admin settings screen.
* WooCommerce support: store-page exclusions for the middleware; product create/enrich through the ingest endpoint.
* Hardening: single-use ingest signatures (replay rejection), per-minute delivery lookup budget, CiteCue-compatible cache-key URL normalization, cache eviction on delivery misses, and crawler-registry downgrade rejection with the bundled token floor.
* One-click connect: a pairing handshake sets up the site without copying an API key in or a signing secret out, with a built-in "Verify installation" check. Connecting with an API key remains available as a fallback.
