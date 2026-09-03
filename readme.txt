=== CiteCue AI Auto-Fix ===
Contributors: citecue
Tags: ai, ai-crawlers, gptbot, ai-seo, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Answers AI crawlers with a CiteCue-optimized version of the page they asked for, and fills only the metadata gaps your SEO plugin leaves behind.

== Description ==

CiteCue AI Auto-Fix is the WordPress end of CiteCue. It decides, per request, which version of a page WordPress returns — the optimized one to a recognised AI crawler, your normal page to everyone else — and it can do that only from inside WordPress, before the theme renders.

* **Per-request delivery to AI crawlers** — when GPTBot, ClaudeBot, PerplexityBot, ChatGPT-User or any other agent in the crawler registry requests a page, the plugin returns the CiteCue-optimized version of that URL. Human visitors always see your normal site, and optimized responses are never cached for regular traffic. Any miss, timeout or outage passes straight through to the normal page.
* **Gap-filling page metadata** — adds CiteCue's title, meta description, OpenGraph, canonical and structured-data tags to your live pages, so search engines and AI answer engines see them on the page a human sees. It fills gaps only: it reads what your theme, WordPress and your SEO plugin actually printed into `<head>` and adds only what none of them emitted, so there is never a second title or canonical.
* **Page enhancements** — where you have approved one in CiteCue, adds a short facts-and-FAQ section to the end of a page you already have, built from your own answered facts and grounded FAQ entries rather than generated. It is placed only if the page does not already carry one, and it is the only thing this plugin adds that your visitors can see.
* **llms.txt** — serves the llms.txt file CiteCue maintains for your brand at your site root, refreshed from CiteCue rather than regenerated here.
* **Content from CiteCue** — a signed endpoint through which CiteCue can push new brand-building content (content briefs, FAQ packs, gap-filling pages) into WordPress as drafts for your review.
* **WooCommerce-aware** — cart, checkout, account pages and cart-modifying links are never intercepted, while product and shop pages are served optimized. Pushed content can also create or enrich WooCommerce products (draft by default, matched by SKU with explicit consent).

This plugin requires a CiteCue account (citecue.com) and does nothing until you connect one. See "External services" below for exactly what is sent where.

= What the plugin actually does =

llms.txt is one of the four features above, and CiteCue writes that file — the plugin serves it. The rest of the code is about what happens on a live request:

* **It serves a different representation per requester, safely.** Crawler matching runs against a registry that refreshes daily, so an agent launched last week is recognised without a plugin update. A logged-in user, a cart URL, a WooCommerce endpoint or a cart-modifying link is never intercepted. A circuit breaker, a per-minute lookup budget, negative caching and a stale-while-revalidate cache mean an outage at CiteCue costs a passthrough, never a broken page or a slow one.
* **It composes with your SEO plugin rather than replacing it.** The metadata layer detects what was actually printed into `<head>` rather than looking for particular plugins, so it behaves correctly beside Yoast, Rank Math, a plugin nobody has heard of, or none at all. Every tag it adds carries a `data-citecue` attribute, so View Source tells you exactly which ones came from CiteCue.
* **Nothing from the API is trusted as markup.** Every returned tag is parsed, matched against an allowlist of shapes and rebuilt from escaped values, with structured data re-encoded so it cannot escape its own script element.
* **It never makes a visitor wait on a third party.** The render path reads cache only; a URL with nothing cached yet renders untouched and the fetch is queued to WP-Cron.
* **Content flows back in.** The signed `citecue/v1` endpoint is how CiteCue delivers new content into WordPress — as drafts, with replayed signatures rejected — so the loop from "this page is missing" to "this page exists" closes without anyone copying and pasting.

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

**Enriching a page's metadata** — in the background, on WP-Cron, for a URL a visitor has requested while enriched metadata is switched on. The requested URL and the site's project key are sent to `/api/delivery/v2/seo-head`. No visitor data — no IP address, no cookies, no personal data — is sent, and this never happens while a visitor is waiting: a page with no cached block yet is rendered untouched and the fetch is queued for afterwards.

Before a URL is cached, queued or sent, every query argument WordPress does not recognise as a query variable is removed from it, so tokens, order keys and nonces that happen to be in the address are never included. WooCommerce cart, checkout, account and order pages are skipped entirely. Responses are cached, empty answers are remembered for a minute, the same per-minute budget caps outbound calls, and a second per-minute cap limits how many refreshes a burst of traffic can queue.

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

= 1.2.0 =
Adds page enhancements: a facts-and-FAQ section CiteCue composes for pages you already have, placed on the live page. Existing connections need one reconnect before CiteCue will send them — Settings → CiteCue will ask.

= 1.1.2 =
Changes how page metadata is added to the response: no output buffer is left open, and WordPress 6.9's own template output buffer is used where there is one. Nothing to reconfigure.

= 1.1.1 =
Fixes a fatal error on sites that still have the old citecue/ folder installed alongside this plugin. Admin notices now appear only on the Plugins and CiteCue screens.

= 1.1.0 =
Adds enriched page metadata for live pages. Existing connections need one reconnect before CiteCue knows this site can do it — Settings → CiteCue will ask.

= 1.0.1 =
The plugin folder is now citecue-ai-auto-fix. If you installed 1.0.0 by uploading the zip from GitHub, delete the old citecue folder after updating — your settings and connection are stored in the database and carry over untouched.

== Changelog ==

= 1.2.0 =
* New: page enhancements. CiteCue composes a collapsed facts-and-FAQ section for a page you already have — built from your answered facts and grounded FAQ entries, never generated by a model — and this release places it on that page, immediately before `</body>`.
* The block is placed exactly as CiteCue composed it. It is sanitized before it is sent and capped at 32 KB on the wire, and this plugin checks the cap again before placing it; nothing is re-escaped or run through the content filters, either of which would break the markup or run a shortcode quoted inside an answer.
* Never placed twice. A page that already carries the `data-citecue="page-enhancement"` marker — because CiteCue's Worker fronts the site, or because the section is already in the post — is left exactly as it is.
* The block rides the `/seo-head` response the plugin already fetches for metadata, so an enhanced page costs no extra request, and the same rules apply: cache-only on the render path, so a visitor never waits on CiteCue, and nothing at all is fetched on a site with metadata injection switched off.
* Existing connections need one reconnect. CiteCue records what a plugin can do when it connects and withholds anything it never announced, so a site connected before this release is sent no blocks until it reconnects — Settings → CiteCue now says so, where before it only noticed a changed metadata setting.
* "Accept pushed content" now corrects itself. If CiteCue no longer holds a signing secret for this site — because you reconnected without allowing content pushes — the switch here turns off and says so, instead of reporting a channel that cannot deliver anything. It is never switched back on remotely: that stays your decision, on this screen.
* The plugin also now asks for CiteCue's site-wide identity metadata on pages that have none of their own. It is gap-filling like everything else in the head: if your theme or SEO plugin already prints structured data, CiteCue's is dropped rather than added beside it.

= 1.1.2 =
* The metadata layer no longer holds an output buffer of its own open across a request. On WordPress 6.9 and later it uses core's template enhancement output buffer, so the plugin opens no buffer at all; below that it opens one in the form PHP finalizes by itself, and closes nothing. The previous shape opened a buffer on one hook and closed it on another, which left one open on any page whose `wp_head` did not run to the end — and a buffer left open is one the next plugin's `ob_get_clean()` can take by mistake.
* The tags now go immediately before `</head>` rather than at the end of `wp_head`, and the check for what is already there reads the head only. Markup in the body — a `<title>` inside an inline SVG, a `<meta>` quoted in page content — no longer counts as a slot somebody else has filled, so pages carrying either get their metadata again.
* The capture is arranged after every other `template_redirect` callback, so a request another plugin redirects or answers itself is never buffered.

= 1.1.1 =
* Admin notices are confined to the Plugins screen and the CiteCue settings screen. The rejected-key warning and the duplicate-install warning used to print on every screen in the dashboard; neither asks for anything that can be done anywhere else, and the settings screen states both a second time in its status card.
* The reconnect prompt can now be dismissed permanently, per user and per site. It is advice rather than an error, and an administrator who has read it and decided against it should not keep being told. On multisite the dismissal is scoped to the site it was made on, since the condition it reports on is per-site while WordPress stores user metadata network-wide.
* Fixed a fatal error on a site that still has the pre-WordPress.org copy in a citecue/ folder alongside this one. That copy is 1.0.0, which predates the duplicate-install guard and loads its classes unconditionally — and WordPress always includes citecue-ai-auto-fix/ first, so 1.0.0 was always the copy that redeclared them and took the site down. This copy now stands aside for it and says which folder to delete, so the site keeps running either way.
* Both duplicate-install warnings now also appear in Network Admin → Plugins. A network-activated copy can only be removed from there, and the hook they were on does not fire anywhere in Network Admin — so on multisite the warning was missing from the one screen where a super admin could act on it.
* Uninstalling from a network now clears dismissal records for every site, not just the one running the uninstall. WordPress stores user metadata in a single table shared by the whole network, so anything left there is left for good.
* Uninstall removes the dismissal records along with everything else.
* readme: shorter summary, and the description now says plainly what this plugin does that a static llms.txt generator does not.

= 1.1.0 =
* New: enriched page metadata. CiteCue's title, meta description, OpenGraph, canonical and structured-data tags are added to your live pages, so search engines and AI answer engines see them, not just AI crawlers. Uses CiteCue's `/api/delivery/v2/seo-head` endpoint.
* Fills gaps only: anything WordPress, your theme or your SEO plugin already prints is left untouched, so the plugin never emits a second title or canonical. Detection reads the real `<head>` output rather than looking for particular plugins.
* Never delays a page: the render path reads cache only. A URL with nothing cached yet renders untouched and the fetch is queued for afterwards. Cached blocks survive a CiteCue outage and are served while a refresh runs.
* The connection now tells CiteCue whether this site injects metadata, so CiteCue stops reporting fixes as reaching human visitors when nothing puts them on the page. Sites connected before this release are asked to reconnect once.
* Nothing from the response is printed as it arrived: every tag is parsed, checked against an allowlist of shapes and rebuilt from escaped values, with structured data re-encoded so it cannot escape its own script element.
* Query arguments WordPress does not recognise are stripped from the URL before it is cached, queued or sent, and store pages are skipped — so order keys, reset tokens and nonces are never included, and a visitor cannot fill the scheduler with unique addresses for one page.
* Changing the selected CiteCue project now clears the delivery cache, instead of serving the previous project's pages, llms.txt and metadata under the new one for up to a day.
* New filters: `citecue_should_inject_seo_head` (skip a page), `citecue_seo_head_tags` (change what is printed), `citecue_seo_head_query_vars` and `citecue_seo_head_schedule_budget`.

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
