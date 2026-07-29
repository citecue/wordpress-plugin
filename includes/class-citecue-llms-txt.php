<?php
/**
 * Serves /llms.txt (llmstxt.org convention) from CiteCue's delivery API for
 * every visitor. If CiteCue has llms.txt serving disabled (or delivery off),
 * the request falls through to whatever WordPress would normally do.
 *
 * A physical llms.txt file in the web root is always served by the web server
 * before WordPress loads, so a hand-maintained file automatically wins.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * llms.txt endpoint.
 */
class Citecue_Llms_Txt {

	/** How long a fetched llms.txt stays fresh before we revalidate (matches the API's max-age=300). */
	const FRESH_SECONDS = 5 * MINUTE_IN_SECONDS;

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
	 * Hooks the handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Serves llms.txt when this request targets it and CiteCue has a body.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		$decision = $this->decide();

		if ( $decision['serve'] ) {
			$this->serve( $decision['body'] );
		}
	}

	/**
	 * Decides what this request should get, without emitting anything — the
	 * testable counterpart of {@see self::serve()}, which ends the request.
	 *
	 * @return array{serve:bool,body:string,reason:string}
	 */
	public function decide() {
		if ( ! $this->is_llms_txt_request() ) {
			return self::pass( 'not-llms-txt' );
		}

		$settings = $this->plugin->settings;
		if ( ! $settings->get( 'llms_txt_enabled' ) || ! $settings->is_delivery_configured() ) {
			return self::pass( 'not-configured' );
		}

		$cache  = $this->plugin->cache;
		$cached = $cache->get_llms_txt();

		// Fresh cached copy or open circuit: serve locally, no API call.
		if ( $cached && ( $cache->is_fresh( $cached, self::FRESH_SECONDS ) || $cache->is_circuit_open() ) ) {
			return self::body( $cached['body'], 'cached' );
		}
		if ( $cache->is_circuit_open() ) {
			return self::pass( 'circuit-open' );
		}

		$response = $this->plugin->api->get_llms_txt( $cached ? $cached['etag'] : '' );

		if ( is_wp_error( $response ) ) {
			$cache->trip_circuit();
			return $cached ? self::body( $cached['body'], 'transport-error' ) : self::pass( 'transport-error' );
		}

		switch ( $response['status'] ) {
			case 200:
				$cache->set_llms_txt( $response['body'], $response['etag'] );
				return self::body( $response['body'], 'fresh' );

			case 304:
				if ( ! $cached ) {
					return self::pass( 'revalidated-without-cache' );
				}
				$cache->set_llms_txt( $cached['body'], $cached['etag'] );
				return self::body( $cached['body'], 'revalidated' );

			case 401:
				update_option( 'citecue_auth_failed', time(), false );
				$cache->trip_circuit( Citecue_Cache::AUTH_CIRCUIT_TTL );
				return self::pass( 'unauthorized' );

			case 404:
				// llms.txt serving disabled on CiteCue: evict the cached copy
				// (so it cannot resurface stale) and fall through to WordPress.
				$cache->delete_llms_txt();
				return self::pass( 'disabled-upstream' );

			default:
				return self::pass( 'server-error' );
		}
	}

	/**
	 * A "leave this request to WordPress" decision.
	 *
	 * @param string $reason Why nothing is served (diagnostic only).
	 * @return array{serve:bool,body:string,reason:string}
	 */
	private static function pass( $reason ) {
		return array(
			'serve'  => false,
			'body'   => '',
			'reason' => $reason,
		);
	}

	/**
	 * A "serve this llms.txt body" decision.
	 *
	 * @param string $body   llms.txt content.
	 * @param string $reason Where the body came from (diagnostic only).
	 * @return array{serve:bool,body:string,reason:string}
	 */
	private static function body( $body, $reason ) {
		return array(
			'serve'  => true,
			'body'   => $body,
			'reason' => $reason,
		);
	}

	/**
	 * Whether the current request targets llms.txt at the site root
	 * (or the WordPress subdirectory root for subdirectory installs).
	 *
	 * @return bool
	 */
	private function is_llms_txt_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}

		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$target    = untrailingslashit( $home_path ) . '/llms.txt';

		return $path === $target;
	}

	/**
	 * Emits llms.txt and ends the request. The `X-Citecue: llms-txt` header is
	 * what CiteCue's install verification probes for.
	 *
	 * @param string $body llms.txt content.
	 * @return void
	 */
	private function serve( $body ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Citecue: llms-txt' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text body served verbatim.
		exit;
	}
}
