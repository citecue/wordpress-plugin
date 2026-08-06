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
		( new Citecue_Ingest( $this ) )->register();

		if ( is_admin() ) {
			( new Citecue_Admin( $this ) )->register();
		}

		add_action( 'init', array( $this, 'on_init' ) );
		add_action( self::CRON_HOOK, array( $this, 'daily_sync' ) );
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
	}
}
