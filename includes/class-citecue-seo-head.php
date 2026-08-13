<?php
/**
 * Injects CiteCue's enriched SEO head block into ordinary page loads.
 *
 * This is the human-facing half of the delivery channel, and the opposite of
 * Citecue_Proxy in every way that matters. The proxy replaces the whole
 * document, for detected AI crawlers only. This one adds a handful of
 * head-only tags — title, meta description, OpenGraph, canonical, JSON-LD —
 * to the page a browser (or Googlebot) already sees, leaving the visible page
 * untouched. CiteCue only serves a block for `enriched` pages in `all`
 * audience mode, which is what keeps the two apart: enriched markup is
 * content-parity and additive, so adding it is not cloaking, whereas serving a
 * rewritten document to a human would be.
 *
 * Five rules govern everything below.
 *
 * **Never emit a duplicate.** WordPress core prints `<title>` and
 * `<link rel="canonical">` on its own, and Yoast, Rank Math, AIOSEO, SEOPress,
 * The SEO Framework, Slim SEO and Jetpack each print some combination of
 * title, description, OpenGraph and JSON-LD. A second `<title>` is invalid
 * HTML and a second canonical makes Google pick one arbitrarily — so this
 * fills gaps only: it reads the rendered `<head>` and drops every CiteCue tag
 * whose slot is already taken. Detecting emitted markup rather than sniffing
 * for `WPSEO_VERSION` is what makes that correct against SEO plugins and
 * themes nobody here has heard of.
 *
 * **Never leave an output buffer open.** Reading the rendered head means
 * buffering, and a buffer a plugin opens but does not itself close is one the
 * next component's `ob_get_clean()` can take by mistake — the buffer stack
 * misaligns and somebody else's page breaks (WordPress.org plugin review).
 * Nothing here calls `ob_get_clean()`, `ob_end_flush()` or any other closing
 * function, so nothing here can take a buffer it did not open or forget one it
 * did. Since WordPress 6.9 core opens the buffer and hands the finished
 * document to a filter, and that is the entire mechanism; below 6.9 the buffer
 * is opened in the one form PHP finalizes on its own — `ob_start()` with a
 * callback — so no hook, early return or fatal can leave it dangling.
 *
 * **Never block a human.** The proxy may spend a request budget on an outbound
 * call because only a bot is waiting. Here a real visitor is, so the render
 * path reads the transient cache and nothing else: a miss injects nothing and
 * schedules a background refresh, so the next visitor gets the block. Cold
 * pages cost one un-enriched view, never one slow one.
 *
 * **Never print markup we did not build.** The block is trusted content for
 * this site's own head, but it arrives over the network and lands in a human's
 * browser. Nothing from the response is echoed: every tag is parsed, checked
 * against an allowlist of shapes, and rebuilt from escaped values. A regex
 * that merely *inspects* remote markup is one quoted attribute away from
 * authorizing something it misread (PR #10 review) — rebuilding cannot be.
 *
 * **Never let a visitor grow the queue.** The URL asked about is the request's
 * own, minus every query argument WordPress does not recognize, and scheduling
 * is capped per minute. Without both, `/?x=<random>` — which resolves to the
 * homepage — would mint an unbounded number of cache keys and cron events.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live-page SEO head injection.
 */
class Citecue_Seo_Head {

	/** How long a cached block stays fresh before a background refresh (matches the API's max-age=300). */
	const FRESH_SECONDS = 5 * MINUTE_IN_SECONDS;

	/** Cron hook for the out-of-band fetch. */
	const REFRESH_HOOK = 'citecue_refresh_seo_head';

	/** How long one URL's refresh request is suppressed, so a burst of visitors schedules one job. */
	const REFRESH_LOCK_TTL = MINUTE_IN_SECONDS;

	/**
	 * `template_redirect` priority the capture is arranged at: last, after
	 * every other callback on the action. The crawler proxy and the llms.txt
	 * handler own priority 0 and both `exit`, core's `redirect_canonical` runs
	 * at 10, and a membership or maintenance plugin redirects here too — so
	 * running last means a request somebody else answers never arranges a
	 * capture at all.
	 */
	const CAPTURE_PRIORITY = PHP_INT_MAX;

	/**
	 * `<link>` relations that may be injected, and `<meta>` values are escaped
	 * into a tag we build ourselves. Anything else in the block is dropped:
	 * `stylesheet`, `preload` and friends have no business arriving from a
	 * metadata endpoint.
	 */
	const ALLOWED_LINK_RELS = array( 'canonical', 'alternate' );

	/**
	 * Query arguments kept when there is no `wp` object to ask. Only the
	 * built-ins that actually select content on a plain-permalink install —
	 * enough that such a site still gets its pages enriched.
	 */
	const FALLBACK_QUERY_VARS = array( 'p', 'page_id', 'cat', 'tag', 'name', 'pagename', 'post_type', 'paged', 'page', 'author_name', 'category_name', 'year', 'monthnum', 'day' );

	/**
	 * Plugin container.
	 *
	 * @var Citecue_Plugin
	 */
	private $plugin;

	/**
	 * The decision start_capture() acted on, carried to the injection so the
	 * two cannot disagree — and so one page load costs one cache read and, at
	 * most, one scheduled refresh.
	 *
	 * @var array|null
	 */
	private $decision = null;

	/**
	 * Constructor.
	 *
	 * @param Citecue_Plugin $plugin Plugin container.
	 */
	public function __construct( Citecue_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the capture and the background refresh worker.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::REFRESH_HOOK, array( $this, 'refresh' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'start_capture' ), self::CAPTURE_PRIORITY );
	}

	/**
	 * Arranges the capture, but only when there is something to inject —
	 * buffering a page we will not touch is pure overhead, and every reason not
	 * to inject is knowable before the theme renders a byte.
	 *
	 * Two mechanisms, one behaviour. WordPress 6.9 added a template output
	 * buffer of its own, opened only when a plugin has registered a
	 * `wp_template_enhancement_output_buffer` filter and closed by core, which
	 * hands the finished document to that filter. Where it exists this class
	 * opens no buffer at all and just asks for the document. Below 6.9 it opens
	 * the same buffer core does, in the same form: `ob_start()` with a
	 * *callback* and without PHP_OUTPUT_HANDLER_FLUSHABLE, so the callback is
	 * invoked exactly once with the whole response.
	 *
	 * The callback form is the point (WordPress.org plugin review). A buffer
	 * opened here has to close after the theme has rendered, which is a
	 * different function by definition — and the shape this replaces, an
	 * `ob_start()` on `template_redirect` paired with an `ob_get_clean()` on
	 * `wp_head`, was left open by every way `wp_head` can fail to reach its
	 * last callback: a template that never calls `wp_head()`, a plugin that
	 * `exit`s inside it, a fatal, or simply another buffer opened in the head
	 * and not closed, which made the pairing unsafe to complete. A callback has
	 * nothing to pair and nothing to leave open — PHP invokes it when the
	 * buffer ends, and ends the buffer itself at the end of the request if
	 * nothing ended it sooner, so the response goes out whatever happens.
	 *
	 * Buffering the response rather than just the head is the cost of that, and
	 * it is the trade core made in 6.9 too. It is bounded on the only axis that
	 * matters here: the buffer is opened solely when a cached block is already
	 * in hand, so a page CiteCue has nothing for streams exactly as it did.
	 *
	 * @return void
	 */
	public function start_capture() {
		$this->decision = $this->decide();

		if ( ! $this->decision['inject'] ) {
			return;
		}

		// WordPress 6.9+. Registered here rather than at `init` because core
		// decides whether to buffer at all by looking for this filter when the
		// template is included, which is after this action — so registering it
		// only on a page there is something to inject into means CiteCue never
		// makes core buffer a response it would have streamed.
		if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'enhance' ) );
			return;
		}

		ob_start(
			array( $this, 'finish_capture' ),
			0, // No chunking: the injection needs the whole response to find the head in it.
			PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
		);
	}

	/**
	 * The output-buffer callback, on WordPress below 6.9 only. PHP calls this
	 * when the buffer ends — which it always does, at the end of the request if
	 * nothing ended it sooner — and sends what it returns.
	 *
	 * @param string $output Everything rendered since the buffer opened.
	 * @param int    $phase  PHP output handler phase bitmask.
	 * @return string What is sent to the browser.
	 */
	public function finish_capture( $output, $phase ) {
		// Ended by a clean rather than a flush, and PHP discards what a handler
		// returns in that phase — the caller gets the raw bytes. So either the
		// response is being thrown away, or something that buffered the whole
		// page is taking it with ob_get_clean(); in both cases nothing returned
		// here can reach a browser, and the page goes out un-enriched. Core's
		// own template enhancement filter is skipped on exactly the same
		// requests, for exactly this reason, and makes exactly this check.
		if ( 0 !== ( (int) $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			return (string) $output;
		}

		return $this->enhance( $output );
	}

	/**
	 * The rendered document with CiteCue's tags added to its head — the one
	 * place the injection happens, shared by both mechanisms above.
	 *
	 * Everything before `</head>` is what the slot check reads, and the tags go
	 * immediately before it. Scoping both to the head is not tidiness: an
	 * inline SVG in the body carries a `<title>`, `<meta itemprop>` is legal in
	 * body content, and a page that quotes markup in a code sample contains
	 * whatever it quotes — so a document-wide scan would read slots as occupied
	 * that no browser or crawler ever reads as page metadata, and CiteCue would
	 * silently stop filling them.
	 *
	 * A response with no `</head>` is returned exactly as it arrived: a JSON or
	 * CSV export served from a page URL, a fragment, a document another plugin
	 * replaced wholesale. There is no head to fill gaps in, and guessing where
	 * one would have gone is how a plugin corrupts a response it did not
	 * understand.
	 *
	 * @param string $html Rendered document.
	 * @return string
	 */
	public function enhance( $html ) {
		$decision = $this->decision;

		// One capture, one injection: whatever else calls this — a filter
		// applied twice, a buffer finalized more than once — must not append
		// the block again.
		$this->decision = null;
		$html           = (string) $html;

		if ( null === $decision || ! $decision['inject'] ) {
			return $html;
		}

		if ( ! preg_match( '#</head\s*>#i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$at   = (int) $match[0][1];
		$tags = self::merge( substr( $html, 0, $at ), $decision['block'] );

		if ( ! $tags ) {
			return $html;
		}

		// The tags are built by self::rebuild_tag() out of escaped values,
		// never a string from the response, so what is spliced in here is our
		// own markup rather than remote markup.
		return substr( $html, 0, $at )
			. "\n<!-- CiteCue -->\n" . implode( "\n", $tags ) . "\n"
			. substr( $html, $at );
	}

	/**
	 * Whether this request should be injected into, and with what — reading the
	 * cache only, never the network. The testable counterpart of the capture,
	 * mirroring the decide()/serve() split in Citecue_Proxy.
	 *
	 * @return array{inject:bool,block:string,reason:string}
	 */
	public function decide() {
		$settings = $this->plugin->settings;
		if ( ! $settings->get( 'seo_head_enabled' ) || ! $settings->is_delivery_configured() ) {
			return self::skip( 'not-configured' );
		}
		if ( ! $this->is_eligible_request() ) {
			return self::skip( 'not-eligible' );
		}

		$url = self::lookup_url();
		if ( '' === $url ) {
			return self::skip( 'no-url' );
		}

		/**
		 * Filters whether to inject CiteCue's SEO head into this page.
		 *
		 * @param bool   $should_inject Default true.
		 * @param string $url           URL the block is looked up by.
		 */
		if ( ! apply_filters( 'citecue_should_inject_seo_head', true, $url ) ) {
			return self::skip( 'vetoed' );
		}

		$cache  = $this->plugin->cache;
		$cached = $cache->get_seo_head( $url );

		if ( $cached && $cache->is_fresh( $cached, self::FRESH_SECONDS ) ) {
			return self::block( $cached['block'], 'cached' );
		}

		// CiteCue recently said it has nothing for this URL. Unlike the crawler
		// path this one runs on every human page view, so without the negative
		// cache an un-enriched site would schedule a refresh job per visitor.
		if ( $cache->is_recent_seo_head_miss( $url ) ) {
			return self::skip( 'recent-miss' );
		}

		$this->schedule_refresh( $url );

		// Stale-while-revalidate: a day-old block is still this page's own
		// metadata, and withholding it while the refresh runs would blank the
		// tags on every page during a CiteCue outage.
		return $cached ? self::block( $cached['block'], 'stale' ) : self::skip( 'no-cache' );
	}

	/**
	 * Fetches one URL's block out of band. Runs on cron, never on a page
	 * render, so this is the only place here that may take a network round
	 * trip — and it shares the circuit breaker and the per-minute lookup budget
	 * with the crawler path, so the site's total outbound calls stay bounded
	 * however traffic is distributed between the two.
	 *
	 * @param string $url Absolute page URL.
	 * @return string Outcome (diagnostic only, and what the tests assert on).
	 */
	public function refresh( $url ) {
		$url      = (string) $url;
		$settings = $this->plugin->settings;

		if ( ! $settings->get( 'seo_head_enabled' ) || ! $settings->is_delivery_configured() ) {
			return 'not-configured';
		}

		$cache = $this->plugin->cache;
		if ( $cache->is_circuit_open() ) {
			return 'circuit-open';
		}
		if ( ! $cache->consume_lookup_budget() ) {
			return 'budget-exhausted';
		}

		$response = $this->plugin->api->get_seo_head( $url );

		if ( is_wp_error( $response ) ) {
			$cache->trip_circuit();
			return 'transport-error';
		}

		switch ( $response['status'] ) {
			case 200:
				if ( '' === $response['head'] ) {
					// A 200 carrying no block is a payload we do not understand.
					// Treat it as "nothing to inject" rather than caching an
					// empty string that would read as a valid block.
					$cache->delete_seo_head( $url );
					$cache->set_seo_head_miss( $url );
					return 'empty';
				}
				$cache->set_seo_head( $url, $response['head'] );
				return 'fresh';

			case 204:
				// Valid project, nothing to inject right now — the audience is
				// not `all`, or this URL has no enriched page. Evict, so a block
				// from before the switch was flipped cannot keep appearing.
				$cache->delete_seo_head( $url );
				$cache->set_seo_head_miss( $url );
				return 'nothing-to-inject';

			case 404:
				$cache->delete_seo_head( $url );
				$cache->set_seo_head_miss( $url );
				return 'not-optimized';

			case 401:
				update_option( 'citecue_auth_failed', time(), false );
				$cache->trip_circuit( Citecue_Cache::AUTH_CIRCUIT_TTL );
				return 'unauthorized';

			default:
				$cache->trip_circuit();
				return 'server-error';
		}
	}

	/**
	 * The URL this request's block is looked up by: the request's own, minus
	 * every query argument WordPress does not recognize as a query variable.
	 *
	 * Dropping the rest is not tidiness (PR #10 review). `/?x=<random>` still
	 * resolves to the homepage, so keeping the raw query string would let an
	 * anonymous visitor mint unlimited distinct cache keys and cron arguments
	 * for one page; and a query string can carry things that must never be
	 * sent to a third party — a password-reset token, a WooCommerce order key,
	 * a nonce. What survives is the set that actually selects content, which is
	 * exactly what CiteCue could have an enriched page for.
	 *
	 * @return string
	 */
	public static function lookup_url() {
		$url = Citecue_Plugin::current_url();
		if ( '' === $url ) {
			return '';
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return $url;
		}

		// Split the query string by hand rather than with parse_str(), and keep
		// what is allowed rather than removing what is not (PR #10 review).
		// parse_str() rewrites `.` and space in a parameter name to `_`, so it
		// reports `x_1` for a parameter literally named `x.1` — and a removal
		// list built from those names asks remove_query_arg() to drop something
		// the URL does not contain, leaving the real parameter in place. That
		// is the whole bypass this function exists to prevent, so it is built
		// the other way round: an unrecognized name is not removed, it is
		// simply never copied across, and a name this code cannot parse can
		// therefore never survive.
		$allowed = self::allowed_query_vars();
		$keep    = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = urldecode( explode( '=', $pair, 2 )[0] );
			if ( in_array( $name, $allowed, true ) ) {
				$keep[] = $pair;
			}
		}

		$base = explode( '?', $url, 2 )[0];

		return $keep ? $base . '?' . implode( '&', $keep ) : $base;
	}

	/**
	 * Query variable names that may survive into the lookup URL.
	 *
	 * Taken from the live `WP` object, so a custom post type or a plugin that
	 * registers its own public query variable keeps working without this
	 * needing to know about it. Falls back to the built-ins when there is no
	 * `WP` object (WP-Cron, WP-CLI, a unit test calling this directly).
	 *
	 * @return string[]
	 */
	private static function allowed_query_vars() {
		$wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;

		$vars = ( $wp instanceof WP && ! empty( $wp->public_query_vars ) )
			? $wp->public_query_vars
			: self::FALLBACK_QUERY_VARS;

		/**
		 * Filters the query variables kept in the SEO head lookup URL.
		 *
		 * Anything not listed is stripped before the URL is cached, scheduled
		 * or sent to CiteCue. Add to it only for a variable that genuinely
		 * selects different content, and never for one that carries a token.
		 *
		 * @param string[] $vars Allowed query variable names.
		 */
		$vars = apply_filters( 'citecue_seo_head_query_vars', $vars );

		return is_array( $vars ) ? array_map( 'strval', $vars ) : array();
	}

	/**
	 * The CiteCue tags that may be added to a head that already contains
	 * `$existing`: every tag whose slot — the title, the canonical, one meta
	 * name/property, JSON-LD as a whole — nothing else has claimed.
	 *
	 * Pure, and deliberately so: this is the whole conflict-avoidance contract
	 * with every other SEO plugin on the site, and it is worth being able to
	 * test it against a real Yoast head dump without a request in sight.
	 *
	 * @param string $existing Rendered head markup.
	 * @param string $block    CiteCue's head block.
	 * @return string[] Rebuilt tags to append, in the order CiteCue sent them.
	 */
	public static function merge( $existing, $block ) {
		$occupied = self::slots_in( $existing );
		$tags     = array();

		foreach ( self::tags_in( $block ) as $tag ) {
			$rebuilt = self::rebuild_tag( $tag );
			if ( null === $rebuilt ) {
				continue;
			}
			list( $slot, $html ) = $rebuilt;
			if ( isset( $occupied[ $slot ] ) ) {
				continue;
			}
			// Claim it here too, so a block that somehow carries two canonicals
			// cannot contribute both.
			$occupied[ $slot ] = true;
			$tags[]            = $html;
		}

		/**
		 * Filters the CiteCue head tags about to be printed.
		 *
		 * The default policy is gap-filling: a tag whose slot another plugin
		 * has already filled is dropped. Use this to re-add one (having removed
		 * the other plugin's copy yourself) or to drop more. Whatever is
		 * returned is spliced into the head unescaped, so a filter that adds
		 * markup owns escaping it.
		 *
		 * This runs inside an output buffer callback. A callback here must not
		 * print anything (PHP silently drops it before 8.5 and deprecates it
		 * after) and must not call `ob_start()`, which is a fatal error in that
		 * context. Return the tags; do not emit them.
		 *
		 * @param string[] $tags     Tags that survived the gap-fill.
		 * @param string   $block    The full block CiteCue returned.
		 * @param string   $existing Rendered head markup.
		 */
		$tags = apply_filters( 'citecue_seo_head_tags', $tags, $block, $existing );

		return is_array( $tags ) ? array_values( array_filter( $tags, 'is_string' ) ) : array();
	}

	/**
	 * Splits head markup into the individual elements this class reasons about.
	 *
	 * @param string $html Head markup.
	 * @return string[]
	 */
	private static function tags_in( $html ) {
		$pattern = '#<title\b[^>]*>.*?</title>|<script\b[^>]*>.*?</script>|<(?:meta|link)\b[^>]*>#is';
		return preg_match_all( $pattern, (string) $html, $matches ) ? $matches[0] : array();
	}

	/**
	 * The slots occupied by markup already in the head.
	 *
	 * @param string $html Head markup.
	 * @return array<string,bool>
	 */
	private static function slots_in( $html ) {
		$slots = array();
		foreach ( self::tags_in( $html ) as $tag ) {
			$slot = self::slot_for( $tag );
			if ( '' !== $slot ) {
				$slots[ $slot ] = true;
			}
		}
		return $slots;
	}

	/**
	 * The slot one existing element claims, or '' for one that claims none.
	 *
	 * Only asks "is this slot taken", so any `<link>` relation counts and any
	 * `<script>` that is not structured data counts for nothing — a site with
	 * an analytics snippet in its head must not lose CiteCue's schema to it.
	 * Deciding what may be PRINTED is a different and much stricter question,
	 * answered by {@see self::rebuild_tag()}.
	 *
	 * @param string $tag One element.
	 * @return string
	 */
	private static function slot_for( $tag ) {
		if ( preg_match( '#^<title\b#i', $tag ) ) {
			return 'title';
		}

		$attributes = self::attributes( $tag );

		if ( preg_match( '#^<script\b#i', $tag ) ) {
			$type = isset( $attributes['type'] ) ? strtolower( $attributes['type'] ) : '';
			return 'application/ld+json' === $type ? 'jsonld' : '';
		}

		if ( preg_match( '#^<link\b#i', $tag ) ) {
			$rel = isset( $attributes['rel'] ) ? strtolower( trim( $attributes['rel'] ) ) : '';
			return '' !== $rel ? 'link:' . $rel : '';
		}

		if ( preg_match( '#^<meta\b#i', $tag ) ) {
			$key = '';
			if ( isset( $attributes['property'] ) && '' !== $attributes['property'] ) {
				$key = $attributes['property'];
			} elseif ( isset( $attributes['name'] ) ) {
				$key = $attributes['name'];
			}
			$key = trim( $key );
			return '' !== $key ? 'meta:' . strtolower( $key ) : '';
		}

		return '';
	}

	/**
	 * One CiteCue tag, rebuilt from parsed and escaped values, as
	 * `array(slot, html)` — or null when it is not a shape we print.
	 *
	 * Rebuilding rather than passing the response's own markup through is the
	 * point (PR #10 review). Inspecting remote markup with a regex and then
	 * echoing it means one misread attribute is an executable tag on a real
	 * visitor's page: `<link foo="a rel=canonical" rel="stylesheet"
	 * onload="…">` reads as a canonical to a pattern searching for `rel=`, and
	 * as a stylesheet with an event handler to the browser. Nothing survives
	 * this function that it did not itself write, so there is no such gap to
	 * find — every attribute other than the ones named below is discarded, and
	 * `href` is limited to http/https.
	 *
	 * @param string $tag One element from CiteCue's block.
	 * @return array{0:string,1:string}|null
	 */
	private static function rebuild_tag( $tag ) {
		if ( preg_match( '#^<title\b[^>]*>(.*)</title>$#is', $tag, $match ) ) {
			$text = self::text( $match[1] );
			return '' === $text ? null : array( 'title', '<title data-citecue="title">' . esc_html( $text ) . '</title>' );
		}

		$attributes = self::attributes( $tag );

		if ( preg_match( '#^<script\b#i', $tag ) ) {
			$type = isset( $attributes['type'] ) ? strtolower( trim( $attributes['type'] ) ) : '';
			if ( 'application/ld+json' !== $type || ! preg_match( '#^<script\b[^>]*>(.*)</script>$#is', $tag, $match ) ) {
				return null;
			}
			$data = json_decode( trim( $match[1] ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				return null;
			}
			// JSON_HEX_TAG is what makes this safe inside a script element: it
			// renders every angle bracket as a < / > escape, so a
			// closing script tag buried in a string value cannot end the
			// element early. Re-encoding from the decoded data, rather than
			// passing the original text through, is what guarantees there is
			// nothing else in there either.
			$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
			return ( false === $json || '' === $json )
				? null
				: array( 'jsonld', '<script data-citecue="jsonld" type="application/ld+json">' . $json . '</script>' );
		}

		if ( preg_match( '#^<link\b#i', $tag ) ) {
			$rel = isset( $attributes['rel'] ) ? strtolower( trim( $attributes['rel'] ) ) : '';
			if ( ! in_array( $rel, self::ALLOWED_LINK_RELS, true ) ) {
				return null;
			}
			$href = isset( $attributes['href'] ) ? esc_url_raw( self::text( $attributes['href'] ), array( 'http', 'https' ) ) : '';
			if ( '' === $href ) {
				return null;
			}
			$html = '<link data-citecue="' . esc_attr( $rel ) . '" rel="' . esc_attr( $rel ) . '" href="' . esc_url( $href ) . '"';
			// The one extra attribute an `alternate` link needs to mean
			// anything, and only in a shape that cannot be anything else.
			if ( isset( $attributes['hreflang'] ) && preg_match( '#^[A-Za-z]{2,8}(-[A-Za-z0-9]{2,8})*$#', trim( $attributes['hreflang'] ) ) ) {
				$html .= ' hreflang="' . esc_attr( trim( $attributes['hreflang'] ) ) . '"';
			}
			return array( 'link:' . $rel, $html . ' />' );
		}

		if ( preg_match( '#^<meta\b#i', $tag ) ) {
			$is_property = isset( $attributes['property'] ) && '' !== trim( $attributes['property'] );
			$key         = $is_property ? trim( $attributes['property'] ) : trim( isset( $attributes['name'] ) ? $attributes['name'] : '' );
			if ( '' === $key || ! preg_match( '#^[A-Za-z0-9:_.-]+$#', $key ) ) {
				return null;
			}
			$content = self::text( isset( $attributes['content'] ) ? $attributes['content'] : '' );
			if ( '' === $content ) {
				return null;
			}
			$html = '<meta data-citecue="' . esc_attr( strtolower( $key ) ) . '" '
				. ( $is_property ? 'property' : 'name' ) . '="' . esc_attr( $key ) . '"'
				. ' content="' . esc_attr( $content ) . '" />';
			return array( 'meta:' . strtolower( $key ), $html );
		}

		return null;
	}

	/**
	 * An attribute value or element body as plain text, ready to be escaped
	 * back out. Decoded first: the value arrives HTML-encoded, so escaping it
	 * as-is would turn `&amp;` into `&amp;amp;` on the page.
	 *
	 * @param string $raw Encoded value.
	 * @return string
	 */
	private static function text( $raw ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * An element's attributes, lowercased names to unquoted values.
	 *
	 * Walks name/value pairs left to right rather than searching for one
	 * attribute at a time, which is the difference between reading markup and
	 * guessing at it: a search for `rel=` finds it inside `foo="a rel=x"`,
	 * whereas a scan that consumes each quoted value as part of its own pair
	 * cannot. First occurrence wins, matching how browsers resolve a repeated
	 * attribute.
	 *
	 * @param string $tag One element.
	 * @return array<string,string>
	 */
	private static function attributes( $tag ) {
		$out = array();

		if ( ! preg_match( '#^<[a-zA-Z][a-zA-Z0-9]*([^>]*)>#', (string) $tag, $match ) ) {
			return $out;
		}

		$pattern = '#([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#';
		if ( ! preg_match_all( $pattern, $match[1], $found, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $found as $pair ) {
			$name = strtolower( $pair[1] );
			if ( isset( $out[ $name ] ) ) {
				continue;
			}
			$value = isset( $pair[2] ) ? $pair[2] : '';
			if ( '' !== $value && ( '"' === $value[0] || "'" === $value[0] ) ) {
				$value = substr( $value, 1, -1 );
			}
			$out[ $name ] = $value;
		}

		return $out;
	}

	/**
	 * Queues one background fetch for a URL, at most once a minute per URL and
	 * within a site-wide per-minute ceiling.
	 *
	 * The per-URL lock alone does not bound anything (PR #10 review): distinct
	 * URLs take distinct locks, and the outbound lookup budget is only spent
	 * when a job RUNS, so without a ceiling here an anonymous visitor could
	 * push unlimited events into WordPress's serialized cron option and make
	 * every subsequent write more expensive. `lookup_url()` removes most of the
	 * ways to mint a distinct URL; this bounds what is left.
	 *
	 * @param string $url Lookup URL.
	 * @return void
	 */
	private function schedule_refresh( $url ) {
		$lock = 'citecue_shq_' . md5( Citecue_Cache::normalize_url( $url ) );
		if ( get_transient( $lock ) ) {
			return;
		}

		if ( ! $this->plugin->cache->consume_seo_head_schedule_budget() ) {
			return;
		}

		set_transient( $lock, 1, self::REFRESH_LOCK_TTL );

		wp_schedule_single_event( time(), self::REFRESH_HOOK, array( $url ) );
	}

	/**
	 * Whether this request is one to inject into: a frontend GET rendering a
	 * real page. Logged-in users are deliberately included — the tags are the
	 * page's own public metadata, and an administrator checking View Source is
	 * exactly who needs to see them.
	 *
	 * @return bool
	 */
	private function is_eligible_request() {
		if ( is_admin() ) {
			return false;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_embed() ) {
			return false;
		}
		if ( is_customize_preview() ) {
			return false;
		}
		// A 404 or a search results page has no CiteCue counterpart, and a
		// canonical pointing anywhere from either would be actively wrong.
		if ( is_404() || is_search() ) {
			return false;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return false;
		}
		// The same store pages the crawler proxy refuses to touch, for a reason
		// that applies twice over here (PR #10 review): those URLs carry order
		// ids, `wc_order_*` keys and account tokens, and this path would put
		// the URL in a cron argument and then send it to CiteCue.
		if ( Citecue_Plugin::is_woocommerce_request() ) {
			return false;
		}
		// Content behind a password is not content CiteCue has, and its
		// metadata should not describe what a visitor cannot read.
		if ( is_singular() && post_password_required() ) {
			return false;
		}
		return true;
	}

	/**
	 * A "leave this head alone" decision.
	 *
	 * @param string $reason Why nothing is injected (diagnostic only).
	 * @return array{inject:bool,block:string,reason:string}
	 */
	private static function skip( $reason ) {
		return array(
			'inject' => false,
			'block'  => '',
			'reason' => $reason,
		);
	}

	/**
	 * An "inject this block" decision.
	 *
	 * @param string $block  CiteCue head block.
	 * @param string $reason Where the block came from (diagnostic only).
	 * @return array{inject:bool,block:string,reason:string}
	 */
	private static function block( $block, $reason ) {
		return array(
			'inject' => true,
			'block'  => (string) $block,
			'reason' => $reason,
		);
	}
}
