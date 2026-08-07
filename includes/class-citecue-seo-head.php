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
 * Two rules govern everything below.
 *
 * **Never emit a duplicate.** WordPress core prints `<title>` and
 * `<link rel="canonical">` on its own, and Yoast, Rank Math, AIOSEO, SEOPress,
 * The SEO Framework, Slim SEO and Jetpack each print some combination of
 * title, description, OpenGraph and JSON-LD. A second `<title>` is invalid
 * HTML and a second canonical makes Google pick one arbitrarily — so this
 * fills gaps only: it captures what the rest of `wp_head` actually printed and
 * drops every CiteCue tag whose slot is already taken. Detecting emitted
 * markup rather than sniffing for `WPSEO_VERSION` is what makes that correct
 * against SEO plugins and themes nobody here has heard of.
 *
 * **Never block a human.** The proxy may spend a request budget on an outbound
 * call because only a bot is waiting. Here a real visitor is, so the render
 * path reads the transient cache and nothing else: a miss injects nothing and
 * schedules a background refresh, so the next visitor gets the block. Cold
 * pages cost one un-enriched view, never one slow one.
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
	 * `wp_head` priority the output capture opens at. Below every priority in
	 * practical use — core's `_wp_render_title_tag` is 1, `rel_canonical` 10,
	 * and the SEO plugins cluster around 1 — so the capture sees all of it.
	 */
	const CAPTURE_START_PRIORITY = -PHP_INT_MAX;

	/** `wp_head` priority the capture closes and injects at: after everyone. */
	const CAPTURE_END_PRIORITY = PHP_INT_MAX;

	/**
	 * `<link>` relations that may be injected. Anything else in the block is
	 * dropped: the response is trusted markup for THIS site's head, but it
	 * reaches a human's browser, so the set of things it may add is the small
	 * set it needs (`stylesheet`, `preload` and friends have no business
	 * arriving from a metadata endpoint).
	 */
	const ALLOWED_LINK_RELS = array( 'canonical', 'alternate' );

	/**
	 * Plugin container.
	 *
	 * @var Citecue_Plugin
	 */
	private $plugin;

	/**
	 * Output-buffer nesting level our capture opened at, or null when no
	 * capture is in flight.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

	/**
	 * The decision start_capture() acted on, carried to finish_capture() so the
	 * pair cannot disagree — and so one page load costs one cache read and, at
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
		add_action( 'wp_head', array( $this, 'start_capture' ), self::CAPTURE_START_PRIORITY );
		add_action( 'wp_head', array( $this, 'finish_capture' ), self::CAPTURE_END_PRIORITY );
	}

	/**
	 * Opens the capture, but only when there is something to inject — buffering
	 * a page we will not touch is pure overhead, and every reason not to inject
	 * is knowable before the first byte of `wp_head`.
	 *
	 * @return void
	 */
	public function start_capture() {
		$this->buffer_level = null;
		$this->decision     = $this->decide();

		if ( ! $this->decision['inject'] ) {
			return;
		}

		ob_start();
		$this->buffer_level = ob_get_level();
	}

	/**
	 * Closes the capture, re-emits everything the rest of `wp_head` printed,
	 * and appends the CiteCue tags that found an empty slot.
	 *
	 * @return void
	 */
	public function finish_capture() {
		$level              = $this->buffer_level;
		$decision           = $this->decision;
		$this->buffer_level = null;
		$this->decision     = null;

		if ( null === $level || null === $decision ) {
			return;
		}

		// Someone else's buffer is still open on top of ours (or ours was
		// closed for us). Either way the levels no longer line up, so take the
		// conservative exit: flush whatever we own so no output is lost or
		// reordered, and inject nothing this request. A missing block is a
		// non-event; mangled `<head>` output is not.
		if ( ob_get_level() !== $level ) {
			while ( ob_get_level() >= $level && ob_get_level() > 0 ) {
				ob_end_flush();
			}
			return;
		}

		$head = (string) ob_get_clean();
		$tags = self::merge( $head, $decision['block'] );

		// Everything the rest of wp_head printed, verbatim — this is other
		// plugins' and core's own output passing straight back through.
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! $tags ) {
			return;
		}

		echo "\n<!-- CiteCue -->\n";
		// Head-only markup generated by CiteCue for this site's own page, and
		// narrowed to an allowlist of tag shapes by self::slot_for() — output
		// verbatim by design (escaping would destroy the markup).
		echo implode( "\n", $tags ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Whether this request should be injected into, and with what — reading the
	 * cache only, never the network. The testable counterpart of the capture
	 * pair, mirroring the decide()/serve() split in Citecue_Proxy.
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

		$url = Citecue_Plugin::current_url();
		if ( '' === $url ) {
			return self::skip( 'no-url' );
		}

		/**
		 * Filters whether to inject CiteCue's SEO head into this page.
		 *
		 * @param bool   $should_inject Default true.
		 * @param string $url           Absolute request URL.
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
	 * The CiteCue tags that may be added to a head that already contains
	 * `$existing`: every tag whose slot — the title, the canonical, one meta
	 * name/property, JSON-LD as a whole — nothing else has claimed.
	 *
	 * Pure, and deliberately so: this is the whole conflict-avoidance contract
	 * with every other SEO plugin on the site, and it is worth being able to
	 * test it against a real Yoast head dump without a request in sight.
	 *
	 * @param string $existing Markup the rest of wp_head printed.
	 * @param string $block    CiteCue's head block.
	 * @return string[] Tags to append, in the order CiteCue sent them.
	 */
	public static function merge( $existing, $block ) {
		$occupied = self::slots_in( $existing );
		$tags     = array();

		foreach ( self::tags_in( $block ) as $tag ) {
			$slot = self::slot_for( $tag );
			if ( '' === $slot || isset( $occupied[ $slot ] ) ) {
				continue;
			}
			// Claim it here too, so a block that somehow carries two canonicals
			// cannot contribute both.
			$occupied[ $slot ] = true;
			$tags[]            = $tag;
		}

		/**
		 * Filters the CiteCue head tags about to be printed.
		 *
		 * The default policy is gap-filling: a tag whose slot another plugin
		 * has already filled is dropped. Use this to re-add one (having removed
		 * the other plugin's copy yourself) or to drop more.
		 *
		 * @param string[] $tags     Tags that survived the gap-fill.
		 * @param string   $block    The full block CiteCue returned.
		 * @param string   $existing Markup the rest of wp_head printed.
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
	 * The slots occupied by existing head markup.
	 *
	 * @param string $html Head markup.
	 * @return array<string,bool>
	 */
	private static function slots_in( $html ) {
		$slots = array();
		foreach ( self::tags_in( $html ) as $tag ) {
			$slot = self::slot_for( $tag, true );
			if ( '' !== $slot ) {
				$slots[ $slot ] = true;
			}
		}
		return $slots;
	}

	/**
	 * The slot one element claims, or '' for an element that claims none.
	 *
	 * Reading the same element two ways on purpose. Scanning what other plugins
	 * printed ($lenient) only asks "is this slot taken", so any `<link>` rel and
	 * any `<script>` type answers for itself. Deciding what CiteCue may PRINT
	 * applies the allowlists: only `application/ld+json` scripts, only the link
	 * relations in ALLOWED_LINK_RELS, and only meta tags that identify
	 * themselves with a name or property. Everything else returns '' and is
	 * dropped — the endpoint has never sent anything else, and the tags land in
	 * a human's browser, so "recognized shapes only" is the right posture for
	 * markup arriving over the network.
	 *
	 * @param string $tag     One element.
	 * @param bool   $lenient Whether to classify rather than authorize.
	 * @return string
	 */
	private static function slot_for( $tag, $lenient = false ) {
		if ( preg_match( '#^<title\b#i', $tag ) ) {
			return 'title';
		}

		// A plain `<script>` occupies nothing and may never be printed: only
		// structured data collides with structured data, and a metadata
		// endpoint has no business shipping executable code either way. The one
		// rule covers both readings.
		if ( preg_match( '#^<script\b#i', $tag ) ) {
			return preg_match( '#\btype\s*=\s*["\']?application/ld\+json#i', $tag ) ? 'jsonld' : '';
		}

		if ( preg_match( '#^<link\b#i', $tag ) ) {
			$rel = self::attribute( $tag, 'rel' );
			if ( '' === $rel ) {
				return '';
			}
			$rel = strtolower( $rel );
			if ( ! $lenient && ! in_array( $rel, self::ALLOWED_LINK_RELS, true ) ) {
				return '';
			}
			return 'link:' . $rel;
		}

		if ( preg_match( '#^<meta\b#i', $tag ) ) {
			$key = self::attribute( $tag, 'property' );
			if ( '' === $key ) {
				$key = self::attribute( $tag, 'name' );
			}
			if ( '' === $key || ! preg_match( '#^[A-Za-z0-9:_.-]+$#', $key ) ) {
				return '';
			}
			return 'meta:' . strtolower( $key );
		}

		return '';
	}

	/**
	 * One attribute's value, or '' when absent.
	 *
	 * @param string $tag  Element markup.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function attribute( $tag, $name ) {
		$pattern = '#\b' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i';
		if ( ! preg_match( $pattern, $tag, $match ) ) {
			return '';
		}
		foreach ( array( 1, 2, 3 ) as $group ) {
			if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
				return trim( $match[ $group ] );
			}
		}
		return '';
	}

	/**
	 * Queues one background fetch for a URL, at most once a minute however many
	 * visitors arrive in the meantime.
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	private function schedule_refresh( $url ) {
		$lock = 'citecue_shq_' . md5( Citecue_Cache::normalize_url( $url ) );
		if ( get_transient( $lock ) ) {
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
