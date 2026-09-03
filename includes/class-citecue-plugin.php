<?php
/**
 * Plugin container: builds the services, wires the hooks, owns the
 * activation/deactivation lifecycle and the daily sync cron.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin container.
 */
final class Citecue_Plugin {

	const CRON_HOOK = 'citecue_daily_sync';

	/**
	 * Singleton instance.
	 *
	 * @var Citecue_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings service.
	 *
	 * @var Citecue_Settings
	 */
	public $settings;

	/**
	 * Crawler registry service.
	 *
	 * @var Citecue_Crawlers
	 */
	public $crawlers;

	/**
	 * Delivery cache service.
	 *
	 * @var Citecue_Cache
	 */
	public $cache;

	/**
	 * Activity log service.
	 *
	 * @var Citecue_Activity_Log
	 */
	public $activity;

	/**
	 * API client.
	 *
	 * @var Citecue_Api_Client
	 */
	public $api;

	/**
	 * Pairing handshake with the CiteCue app.
	 *
	 * @var Citecue_Connect
	 */
	public $connect;

	/**
	 * Returns (and boots) the singleton.
	 *
	 * @return Citecue_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds services and registers hooks.
	 */
	private function __construct() {
		$this->settings = new Citecue_Settings();
		$this->crawlers = new Citecue_Crawlers();
		$this->cache    = new Citecue_Cache();
		$this->activity = new Citecue_Activity_Log();
		$this->api      = new Citecue_Api_Client( $this->settings );
		$this->connect  = new Citecue_Connect( $this );

		( new Citecue_Llms_Txt( $this ) )->register();
		( new Citecue_Proxy( $this ) )->register();
		( new Citecue_Seo_Head( $this ) )->register();
		( new Citecue_Ingest( $this ) )->register();

		if ( is_admin() ) {
			( new Citecue_Admin( $this ) )->register();
		}

		add_action( 'init', array( $this, 'on_init' ) );
		add_action( self::CRON_HOOK, array( $this, 'daily_sync' ) );
	}

	/**
	 * The absolute URL of the current request, as the delivery API should be
	 * asked about it. CiteCue normalizes it server-side (scheme/www/trailing
	 * slash/tracking params), so this only has to be faithful.
	 *
	 * Lives on the container because two callers need it — the crawler proxy
	 * and the SEO head injector — and they must agree to the character: the
	 * page cache and the head cache key off the same string, and a URL the two
	 * spell differently is two cache entries for one page.
	 *
	 * @return string
	 */
	public static function current_url() {
		if ( ! isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		// esc_url_raw(), not sanitize_text_field(): the latter deletes every
		// percent-encoded sequence it finds, so /caf%C3%A9/ would reach CiteCue
		// as /caf/ and be cached under the wrong key.
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return esc_url_raw( $scheme . '://' . $host . $uri );
	}

	/**
	 * WooCommerce requests neither delivery path may touch: cart, checkout
	 * (incl. order-pay / order-received), account pages and every other WC
	 * endpoint are session/transactional; `?add-to-cart=` GETs mutate the cart
	 * and `wc-ajax` calls are API traffic. Product and shop-archive pages
	 * remain eligible — those are the highest-value pages to optimize.
	 *
	 * Shared with the SEO head injector rather than owned by the proxy
	 * (PR #10 review). The proxy's reason for skipping these is that they are
	 * session content; the injector has a second, sharper one — their URLs
	 * carry order ids, `wc_order_*` keys and account tokens, and that path
	 * would put the URL in a cron argument and then send it to CiteCue. One
	 * copy means the two can never disagree about which those pages are.
	 *
	 * @return bool True when this request belongs to WooCommerce.
	 */
	public static function is_woocommerce_request() {
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
	 * Init: cron self-heal.
	 *
	 * There is deliberately no load_plugin_textdomain() call. Since WordPress
	 * 4.6 a plugin hosted on WordPress.org has its translations loaded for it,
	 * keyed by the slug — which is exactly what the text domain is now. The
	 * call was pointing at a languages/ directory this plugin does not ship.
	 *
	 * @return void
	 */
	public function on_init() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily sync: refresh the AI-crawler registry so new crawlers are served
	 * without a plugin update.
	 *
	 * Only for a site that has connected itself to CiteCue. Activating a
	 * plugin is not consent to talk to a third party, so an unconnected site
	 * must reach nothing on the network — and it loses nothing by staying
	 * quiet, because it is not serving crawlers either, and the bundled token
	 * list is what the registry would refresh.
	 *
	 * @return void
	 */
	public function daily_sync() {
		if ( ! $this->settings->is_connected() ) {
			return;
		}

		$this->crawlers->refresh( $this->api );

		// Consent can be withdrawn at CiteCue, and nothing tells this site when
		// it happens. Costs a request only where pushes are actually switched
		// on, which the default is not.
		$this->connect->refresh_content_push();
	}

	/**
	 * Activation: schedule the sync and pre-generate the ingest secret.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		$settings = new Citecue_Settings();
		$settings->ensure_ingest_secret();
	}

	/**
	 * Deactivation: clear the cron.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		// Queued metadata refreshes, one per URL. wp_unschedule_hook(), not
		// wp_clear_scheduled_hook(): the latter only clears events whose
		// arguments match the ones passed, and every one of these carries its
		// own URL, so an argument-less call would clear none of them.
		wp_unschedule_hook( Citecue_Seo_Head::REFRESH_HOOK );
	}
}
