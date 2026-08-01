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
		$limit = max( 1, (int) apply_filters( 'citecue_lookup_budget', 120 ) );
		$key   = 'citecue_budget_' . (int) floor( time() / MINUTE_IN_SECONDS );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
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
