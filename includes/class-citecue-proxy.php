<?php
/**
 * The AI-crawler middleware. Runs on the frontend only: when a request's
 * User-Agent matches a known AI crawler, the optimized version of the page is
 * fetched from CiteCue's delivery API and served in place of the theme output.
 * Every other request — and any miss, timeout, or error — is completely
 * untouched, so a CiteCue outage leaves the site behaving exactly as before.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves optimized pages to AI crawlers.
 */
class Citecue_Proxy {

	/**
	 * Plugin container.
	 *
	 * @var Citecue_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Citecue_Plugin $plugin Plugin container.
	 */
	public function __construct( Citecue_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the interceptor. Priority 0 so it runs before canonical redirects:
	 * crawlers get the exact URL they asked for, mirroring the CiteCue
	 * Cloudflare Worker's behavior.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Serves the optimized page when this is an AI-crawler request with
	 * servable content; returns silently otherwise.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		$decision = $this->decide();

		if ( $decision['serve'] ) {
			$this->serve( $decision['body'], $decision['mode'], $decision['stale'] );
		}
	}

	/**
	 * Decides what this request should get, without emitting anything. All of
	 * the serving logic lives here so it can be exercised (and tested) without
	 * the headers-and-exit of {@see self::serve()}.
	 *
	 * @return array{serve:bool,body:string,mode:string,stale:bool,reason:string}
	 */
	public function decide() {
		if ( ! $this->is_eligible_request() ) {
			return self::pass( 'not-eligible' );
		}

		$settings = $this->plugin->settings;
		if ( ! $settings->get( 'serve_enabled' ) || ! $settings->is_delivery_configured() ) {
			return self::pass( 'not-configured' );
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$crawler    = $this->plugin->crawlers->match( $user_agent );
		if ( null === $crawler ) {
			return self::pass( 'not-a-crawler' );
		}

		$url = Citecue_Plugin::current_url();
		if ( '' === $url ) {
			return self::pass( 'no-url' );
		}

		/**
		 * Filters whether to serve optimized content for this crawler request.
		 *
		 * @param bool   $should_serve Default true.
		 * @param string $crawler      Matched UA token.
		 * @param string $url          Absolute request URL.
		 */
		if ( ! apply_filters( 'citecue_should_serve', true, $crawler, $url ) ) {
			return self::pass( 'vetoed' );
		}

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$cache = $this->plugin->cache;

		// Recent miss for this URL: skip the API for a minute (mirrors the
		// API's own max-age=60 on the miss sentinel).
		if ( $cache->is_recent_miss( $url ) ) {
			return self::pass( 'recent-miss' );
		}

		$cached = $cache->get_page( $url );

		// Circuit open (recent timeout/auth failure): no API calls. Serve the
		// stale cached copy when we have one, otherwise pass through.
		if ( $cache->is_circuit_open() ) {
			return $cached
				? self::serve_stale( $cached, 'circuit-open' )
				: self::pass( 'circuit-open' );
		}

		// Global lookup budget: a spoofed crawler UA spraying unique URLs
		// cannot force unbounded outbound API calls. Exhausted budget degrades
		// exactly like an open circuit.
		if ( ! $cache->consume_lookup_budget() ) {
			return $cached
				? self::serve_stale( $cached, 'budget-exhausted' )
				: self::pass( 'budget-exhausted' );
		}

		$response = $this->plugin->api->get_page( $url, $crawler, $cached ? $cached['etag'] : '' );

		if ( is_wp_error( $response ) ) {
			// Timeout / connection failure: open the circuit and degrade.
			$cache->trip_circuit();
			$this->plugin->activity->record( $crawler, $path, $cached ? 'served-stale' : 'error' );
			return $cached
				? self::serve_stale( $cached, 'transport-error' )
				: self::pass( 'transport-error' );
		}

		switch ( $response['status'] ) {
			case 200:
				$cache->set_page( $url, $response['body'], $response['etag'], $response['mode'] );
				$this->plugin->activity->record( $crawler, $path, 'served' );
				return array(
					'serve'  => true,
					'body'   => $response['body'],
					'mode'   => $response['mode'],
					'stale'  => false,
					'reason' => 'fresh',
				);

			case 304:
				// Our cached body is current; CiteCue already counted this as served.
				if ( ! $cached ) {
					return self::pass( 'revalidated-without-cache' );
				}
				$cache->touch_page( $url );
				$this->plugin->activity->record( $crawler, $path, 'served' );
				return array(
					'serve'  => true,
					'body'   => $cached['body'],
					'mode'   => $cached['mode'],
					'stale'  => false,
					'reason' => 'revalidated',
				);

			case 401:
				// Bad/revoked API key: remember it for the admin notice and
				// back off for a while — retrying immediately cannot help.
				update_option( 'citecue_auth_failed', time(), false );
				$cache->trip_circuit( Citecue_Cache::AUTH_CIRCUIT_TTL );
				return self::pass( 'unauthorized' );

			case 404:
				// Miss sentinel: CiteCue recorded the passthrough hit
				// server-side. Evict any previously cached body — the page
				// was removed/unapproved, so it must not resurface through
				// the stale-on-error path.
				delete_option( 'citecue_auth_failed' );
				$cache->delete_page( $url );
				$cache->set_miss( $url );
				$this->plugin->activity->record( $crawler, $path, 'passthrough' );
				return self::pass( 'miss' );

			default:
				// Unexpected server state: brief back-off, degrade gracefully.
				$cache->trip_circuit();
				$this->plugin->activity->record( $crawler, $path, $cached ? 'served-stale' : 'error' );
				return $cached
					? self::serve_stale( $cached, 'server-error' )
					: self::pass( 'server-error' );
		}
	}

	/**
	 * A "leave this request to WordPress" decision.
	 *
	 * @param string $reason Why nothing is served (diagnostic only).
	 * @return array{serve:bool,body:string,mode:string,stale:bool,reason:string}
	 */
	private static function pass( $reason ) {
		return array(
			'serve'  => false,
			'body'   => '',
			'mode'   => '',
			'stale'  => false,
			'reason' => $reason,
		);
	}

	/**
	 * A "serve the cached body past its revalidation window" decision.
	 *
	 * @param array  $cached Cache entry.
	 * @param string $reason Why the cached copy is being used (diagnostic only).
	 * @return array{serve:bool,body:string,mode:string,stale:bool,reason:string}
	 */
	private static function serve_stale( array $cached, $reason ) {
		return array(
			'serve'  => true,
			'body'   => $cached['body'],
			'mode'   => $cached['mode'],
			'stale'  => true,
			'reason' => $reason,
		);
	}

	/**
	 * Whether this request is one the proxy may intercept: a plain frontend
	 * GET from an anonymous visitor. Everything else belongs to WordPress.
	 *
	 * @return bool
	 */
	private function is_eligible_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		if ( is_admin() || is_user_logged_in() ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_embed() ) {
			return false;
		}
		if ( is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_favicon' ) && is_favicon() ) {
			return false;
		}
		if ( '' !== (string) get_query_var( 'sitemap' ) ) {
			return false;
		}
		if ( $this->is_excluded_woocommerce_request() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		return true;
	}

	/**
	 * WooCommerce requests the proxy must never touch: cart, checkout (incl.
	 * order-pay / order-received), account pages and every other WC endpoint
	 * are session/transactional; `?add-to-cart=` GETs mutate the cart and
	 * `wc-ajax` calls are API traffic. Product and shop-archive pages remain
	 * eligible — those are the highest-value pages to serve optimized.
	 *
	 * @return bool True when this request belongs to WooCommerce.
	 */
	private function is_excluded_woocommerce_request() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return true;
		}
		if ( isset( $_GET['wc-ajax'] ) || isset( $_GET['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification.
			return true;
		}
		return false;
	}

	/**
	 * Emits the optimized document and ends the request. Stamps the
	 * `X-Citecue: served` header CiteCue's install verifier looks for, and
	 * tells full-page cache plugins not to store this bot-only response.
	 *
	 * @param string $body  Optimized HTML document.
	 * @param string $mode  Optimization mode (enriched|rewrite).
	 * @param bool   $stale Whether this body was served past its ETag window.
	 * @return void
	 */
	private function serve( $body, $mode, $stale ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the cross-plugin constant page caches look for; prefixing it would mean no cache ever sees it.
			define( 'DONOTCACHEPAGE', true );
		}

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Citecue: served' );
		if ( '' !== $mode ) {
			header( 'X-Citecue-Mode: ' . sanitize_key( $mode ) );
		}
		if ( $stale ) {
			header( 'X-Citecue-Cache: stale' );
		}

		// Full HTML document generated by CiteCue for this site's own page —
		// output verbatim by design (escaping would destroy the document).
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
