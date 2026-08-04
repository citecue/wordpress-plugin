=== CiteCue AI Auto-Fix ===
Contributors: citecue
Tags: ai, llms.txt, gptbot, ai-seo, woocommerce
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve AI-optimized versions of your pages to AI bots and crawlers, publish your llms.txt, and receive brand-building draft content from CiteCue.

== Description ==

CiteCue AI Auto-Fix connects your WordPress site to CiteCue:

* **AI crawler middleware** — when an AI bot or crawler (GPTBot, ClaudeBot, PerplexityBot, ChatGPT-User and more) requests a page, the plugin serves the CiteCue-optimized version of that page. Human visitors always see your normal site. Any miss, timeout or outage passes straight through to the normal page.
* **llms.txt** — publishes the llms.txt file CiteCue generates for your brand at your site root.
* **Content from CiteCue** — a signed endpoint through which CiteCue can push new brand-building content (content briefs, FAQ packs, gap-filling pages) into WordPress as drafts for your review.
* **WooCommerce-aware** — cart, checkout, account pages and cart-modifying links are never intercepted, while product and shop pages are served optimized. Pushed content can also create or enrich WooCommerce products (draft by default, matched by SKU with explicit consent).

Requires a CiteCue account.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Go to Settings → CiteCue and click "Connect to CiteCue".
3. Confirm the project for this site in CiteCue. You are redirected back and the plugin checks itself.
4. Add and generate optimized pages on CiteCue's Auto-Fix page.

There is nothing to copy or paste: the connection brings the API key back to WordPress and hands CiteCue this site's address and content-push secret. Sites that cannot complete a browser round-trip to CiteCue can still connect with an organization API key — "Connect with an API key instead" on the settings screen.

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

No. Only requests whose User-Agent matches the AI-crawler registry are served optimized content, and those responses are never cached for regular traffic.

= What happens if CiteCue is down? =

Nothing visible: a circuit breaker stops API calls for a minute and every crawler request falls through to your normal page (or a cached optimized copy).

= Does pushed content go live automatically? =

By default it is created as a draft. You can raise the cap to "Pending review" or "Published" in the settings.

= Is WooCommerce supported? =

Yes. Store pages (cart, checkout, account, all WooCommerce endpoints) are never intercepted, and product/shop pages are served optimized like any other page. With WooCommerce active, pushed content may also create draft products or enrich existing ones (matched by SKU, only with an explicit force flag).

== Changelog ==

= 1.0.0 =
* Initial release: AI-crawler delivery middleware (CiteCue delivery API v2), llms.txt serving, signed content-ingest endpoint, daily crawler-registry refresh, admin settings screen.
* WooCommerce support: store-page exclusions for the middleware; product create/enrich through the ingest endpoint.
* Hardening: single-use ingest signatures (replay rejection), per-minute delivery lookup budget, CiteCue-compatible cache-key URL normalization, cache eviction on delivery misses, and crawler-registry downgrade rejection with the bundled token floor.
* One-click connect: a pairing handshake sets up the site without copying an API key in or a signing secret out, with a built-in "Verify installation" check. Connecting with an API key remains available as a fallback.
