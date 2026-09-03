<?php
/**
 * Transient-backed caches for delivered content, plus the failure circuit
 * breaker that keeps a CiteCue outage invisible to the site.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery cache.
 */
class Citecue_Cache {

	/** Cached optimized bodies are kept a day so they can be served stale on API errors. */
	const BODY_TTL = DAY_IN_SECONDS;

	/** Negative (miss) cache TTL — mirrors the API's `max-age=60` on the miss sentinel. */
	const MISS_TTL = MINUTE_IN_SECONDS;

	/** How long the circuit stays open after a timeout/server error. */
	const CIRCUIT_TTL = MINUTE_IN_SECONDS;

	/** Longer circuit for auth failures — retrying with a bad key can't help quickly. */
	const AUTH_CIRCUIT_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Cache-key salt; rotating it is our "flush" (old transients simply expire).
	 *
	 * @return string
	 */
	private function salt() {
		$salt = get_option( 'citecue_cache_salt', '' );
		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = substr( md5( (string) wp_rand() . microtime() ), 0, 8 );
			update_option( 'citecue_cache_salt', $salt );
		}
		return $salt;
	}

	/**
	 * Normalizes a URL for cache keying, mirroring CiteCue's server-side
	 * normalizePageUrl(): lowercase host without www., no scheme, no fragment,
	 * tracking params (utm_*, ref, fbclid, gclid) dropped, no trailing slash.
	 * Spoofed-UA requests that only vary by tracking noise therefore share one
	 * cache/miss entry instead of each minting a fresh key. Unparseable input
	 * falls back to the raw string (still a stable key).
	 *
	 * @param string $url Absolute page URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return (string) $url;
		}

		$host = strtolower( $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( strlen( $path ) > 1 ) {
			$path = untrailingslashit( $path );
		}

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$pairs = array();
			parse_str( $parts['query'], $pairs );
			foreach ( array_keys( $pairs ) as $param ) {
				if ( preg_match( '/^(utm_\w+|ref|fbclid|gclid)$/i', (string) $param ) ) {
					unset( $pairs[ $param ] );
				}
			}
			if ( $pairs ) {
				$query = '?' . http_build_query( $pairs );
			}
		}

		return $host . ( '/' === $path ? '' : $path ) . $query;
	}

	/**
	 * Transient key for a page URL.
	 *
	 * @param string $url Absolute page URL.
	 * @return string
	 */
	private function page_key( $url ) {
		return 'citecue_pg_' . md5( $this->salt() . '|' . self::normalize_url( $url ) );
	}

	/**
	 * Cached optimized page, or null.
	 *
	 * @param string $url Absolute page URL.
	 * @return array{body:string,etag:string,mode:string,cached_at:int}|null
	 */
	public function get_page( $url ) {
		$hit = get_transient( $this->page_key( $url ) );
		return ( is_array( $hit ) && isset( $hit['body'] ) ) ? $hit : null;
	}

	/**
	 * Stores an optimized page body.
	 *
	 * @param string $url  Absolute page URL.
	 * @param string $body Optimized HTML.
	 * @param string $etag Response ETag.
	 * @param string $mode Optimization mode (enriched|rewrite).
	 * @return void
	 */
	public function set_page( $url, $body, $etag, $mode ) {
		set_transient(
			$this->page_key( $url ),
			array(
				'body'      => $body,
				'etag'      => $etag,
				'mode'      => $mode,
				'cached_at' => time(),
			),
			self::BODY_TTL
		);
	}

	/**
	 * Refreshes the stored timestamp after a 304 revalidation.
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	public function touch_page( $url ) {
		$hit = $this->get_page( $url );
		if ( $hit ) {
			$hit['cached_at'] = time();
			set_transient( $this->page_key( $url ), $hit, self::BODY_TTL );
		}
	}

	/**
	 * Removes a cached page. Called when the API returns the miss sentinel for
	 * a URL that used to be optimized (page removed/unapproved in CiteCue) —
	 * the stale body must not resurface via the stale-on-error path.
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	public function delete_page( $url ) {
		delete_transient( $this->page_key( $url ) );
	}

	/**
	 * Whether this URL recently returned the miss sentinel.
	 *
	 * @param string $url Absolute page URL.
	 * @return bool
	 */
	public function is_recent_miss( $url ) {
		return (bool) get_transient( 'citecue_ms_' . md5( $this->salt() . '|' . self::normalize_url( $url ) ) );
	}

	/**
	 * Records a miss so repeated crawler hits on the same unoptimized URL
	 * skip the API for a minute.
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	public function set_miss( $url ) {
		set_transient( 'citecue_ms_' . md5( $this->salt() . '|' . self::normalize_url( $url ) ), 1, self::MISS_TTL );
	}

	/**
	 * Consumes one unit of the per-minute outbound-lookup budget, shared by
	 * every path that calls the delivery API. Bounds the requests an anonymous
	 * visitor can trigger — by spoofing a crawler User-Agent across unique
	 * URLs, or by hammering llms.txt — to a known ceiling per site. Beyond it
	 * the plugin degrades exactly as it does with an open circuit.
	 *
	 * @return bool Whether an API lookup may be made.
	 */
	public function consume_lookup_budget() {
		/**
		 * Filters the maximum delivery API lookups per minute.
		 *
		 * @param int $limit Default 120.
		 */
		return $this->consume_minute_budget( 'citecue_budget_', apply_filters( 'citecue_lookup_budget', 120 ) );
	}

	/**
	 * Consumes one unit of a per-minute counter, as close to atomically as the
	 * site's object cache allows.
	 *
	 * Read-then-write is a race (PR #10 review): concurrent requests all read
	 * the same count, all find themselves under the limit, and all proceed, so
	 * the ceiling can be overshot by roughly the concurrency. `wp_cache_incr()`
	 * is a single operation on the backing store, so where a persistent object
	 * cache exists the count is exact.
	 *
	 * Without one there is nothing to be exact with — the options table has no
	 * atomic increment reachable through the transient API — so that path stays
	 * best-effort by necessity, and both callers are rate limits whose failure
	 * mode is a bounded overshoot rather than an unbounded one. It is also
	 * where a failed increment lands, so no failure of the fast path can ever
	 * answer "allowed" without counting something. Shared by both budgets so
	 * they cannot drift apart on this.
	 *
	 * @param string $prefix Transient/cache key prefix, including the trailing separator.
	 * @param int    $limit  Units allowed in one minute.
	 * @return bool Whether a unit was available.
	 */
	private function consume_minute_budget( $prefix, $limit ) {
		$limit = max( 1, (int) $limit );
		$key   = $prefix . (int) floor( time() / MINUTE_IN_SECONDS );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'citecue', 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, 'citecue' );
			if ( false !== $count ) {
				return $count <= $limit;
			}
			// The increment failed: the entry was evicted between the add and
			// the increment, or the backend is not answering. Fall through to
			// the counter below rather than reading it as a fresh bucket
			// (CodeRabbit review) — a backend failing every increment would
			// then return "allowed" every time and remove the ceiling
			// altogether, which is the one outcome a rate limit may not have.
		}

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Transient key for a URL's SEO head block. Keyed separately from the page
	 * body: the two have different lifetimes and different eviction triggers —
	 * a page can stop being injectable (audience switched off) while its
	 * optimized body is still perfectly servable to crawlers.
	 *
	 * @param string $url Absolute page URL.
	 * @return string
	 */
	private function seo_head_key( $url ) {
		return 'citecue_sh_' . md5( $this->salt() . '|' . self::normalize_url( $url ) );
	}

	/**
	 * Cached SEO head block and page-enhancement block for a URL, or null.
	 *
	 * `body` is defaulted rather than required, because an entry written by an
	 * earlier version of the plugin carries only `block` — and those entries
	 * outlive the upgrade by up to BODY_TTL. Treating a missing key as "no
	 * block" keeps them readable instead of discarding a day of warm cache on
	 * every site the moment it updates.
	 *
	 * @param string $url Absolute page URL.
	 * @return array{block:string,body:string,cached_at:int}|null
	 */
	public function get_seo_head( $url ) {
		$hit = get_transient( $this->seo_head_key( $url ) );
		if ( ! is_array( $hit ) || ! isset( $hit['block'] ) ) {
			return null;
		}
		if ( ! isset( $hit['body'] ) ) {
			$hit['body'] = '';
		}
		return $hit;
	}

	/**
	 * Stores a URL's SEO head block and page-enhancement block.
	 *
	 * Both halves share one entry because they arrive in one response and are
	 * evicted by the same events: keying them apart would let a page keep a
	 * block from before its audience was switched off, which is the exact
	 * failure delete_seo_head() exists to prevent.
	 *
	 * @param string $url   Absolute page URL.
	 * @param string $block Head markup.
	 * @param string $body  Page-enhancement markup for before `</body>`.
	 * @return void
	 */
	public function set_seo_head( $url, $block, $body = '' ) {
		set_transient(
			$this->seo_head_key( $url ),
			array(
				'block'     => (string) $block,
				'body'      => (string) $body,
				'cached_at' => time(),
			),
			self::BODY_TTL
		);
	}

	/**
	 * Removes a cached SEO head block. Called whenever CiteCue says it has
	 * nothing for the URL, so a block from before the audience switch was
	 * flipped (or before the page was unapproved) cannot keep being printed on
	 * a live page for the rest of the day.
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	public function delete_seo_head( $url ) {
		delete_transient( $this->seo_head_key( $url ) );
	}

	/**
	 * Whether CiteCue recently said it has no head block for this URL.
	 *
	 * @param string $url Absolute page URL.
	 * @return bool
	 */
	public function is_recent_seo_head_miss( $url ) {
		return (bool) get_transient( 'citecue_shm_' . md5( $this->salt() . '|' . self::normalize_url( $url ) ) );
	}

	/**
	 * Records that CiteCue has no head block for this URL. Mirrors the API's
	 * `max-age=60` on both of its empty answers (204 and the 404 sentinel).
	 *
	 * @param string $url Absolute page URL.
	 * @return void
	 */
	public function set_seo_head_miss( $url ) {
		set_transient( 'citecue_shm_' . md5( $this->salt() . '|' . self::normalize_url( $url ) ), 1, self::MISS_TTL );
	}

	/**
	 * Consumes one unit of the per-minute budget for QUEUEING a metadata
	 * refresh, which is a different ceiling from the outbound-call one above
	 * and needs to be (PR #10 review).
	 *
	 * `consume_lookup_budget()` is spent when a job RUNS, so it bounds what
	 * reaches CiteCue and nothing else. Scheduling happens on the render path,
	 * where an anonymous visitor decides how many distinct URLs to ask about —
	 * and every scheduled event is a row in WordPress's serialized `cron`
	 * option, which is rewritten in full on every change. Unbounded, that turns
	 * page views into an ever more expensive database write. This caps it.
	 *
	 * @return bool Whether a refresh may be queued.
	 */
	public function consume_seo_head_schedule_budget() {
		/**
		 * Filters the maximum SEO head refreshes queued per minute.
		 *
		 * @param int $limit Default 20.
		 */
		return $this->consume_minute_budget( 'citecue_shb_', apply_filters( 'citecue_seo_head_schedule_budget', 20 ) );
	}

	/**
	 * Cached llms.txt, or null.
	 *
	 * @return array{body:string,etag:string,cached_at:int}|null
	 */
	public function get_llms_txt() {
		$hit = get_transient( 'citecue_llms_' . $this->salt() );
		return ( is_array( $hit ) && isset( $hit['body'] ) ) ? $hit : null;
	}

	/**
	 * Removes the cached llms.txt (serving got disabled on CiteCue).
	 *
	 * @return void
	 */
	public function delete_llms_txt() {
		delete_transient( 'citecue_llms_' . $this->salt() );
	}

	/**
	 * Whether CiteCue recently reported that this project publishes no
	 * llms.txt. Unlike the page path, /llms.txt is served to every visitor, so
	 * without this a project with llms.txt switched off would turn each hit on
	 * the URL into an outbound API call.
	 *
	 * @return bool
	 */
	public function is_recent_llms_txt_miss() {
		return (bool) get_transient( 'citecue_llms_ms_' . $this->salt() );
	}

	/**
	 * Records that CiteCue has no llms.txt for this project.
	 *
	 * @return void
	 */
	public function set_llms_txt_miss() {
		set_transient( 'citecue_llms_ms_' . $this->salt(), 1, self::MISS_TTL );
	}

	/**
	 * Stores the llms.txt body.
	 *
	 * @param string $body llms.txt content.
	 * @param string $etag Response ETag.
	 * @return void
	 */
	public function set_llms_txt( $body, $etag ) {
		set_transient(
			'citecue_llms_' . $this->salt(),
			array(
				'body'      => $body,
				'etag'      => $etag,
				'cached_at' => time(),
			),
			self::BODY_TTL
		);
	}

	/**
	 * Age-based freshness check used to decide when to revalidate llms.txt.
	 *
	 * @param array $hit         Cache entry.
	 * @param int   $max_age_sec Freshness window.
	 * @return bool
	 */
	public function is_fresh( $hit, $max_age_sec ) {
		return isset( $hit['cached_at'] ) && ( time() - (int) $hit['cached_at'] ) < $max_age_sec;
	}

	/**
	 * Opens the circuit: no API calls until it expires. Serving falls back to
	 * stale cache or plain pass-through, so an outage never slows the site.
	 *
	 * @param int $ttl Seconds to keep the circuit open.
	 * @return void
	 */
	public function trip_circuit( $ttl = self::CIRCUIT_TTL ) {
		set_transient( 'citecue_circuit', time() + $ttl, $ttl );
	}

	/**
	 * Whether the circuit is open (API temporarily off-limits).
	 *
	 * @return bool
	 */
	public function is_circuit_open() {
		return (bool) get_transient( 'citecue_circuit' );
	}

	/**
	 * Flush all delivery caches by rotating the key salt.
	 *
	 * @return void
	 */
	public function flush() {
		update_option( 'citecue_cache_salt', substr( md5( (string) wp_rand() . microtime() ), 0, 8 ) );
		delete_transient( 'citecue_circuit' );
	}
}
