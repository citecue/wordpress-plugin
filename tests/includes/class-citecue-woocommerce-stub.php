<?php
/**
 * Minimal WooCommerce stand-in.
 *
 * `Citecue_Proxy::is_excluded_woocommerce_request()` only ever asks
 * WooCommerce five questions: does the `WooCommerce` class exist, and is this
 * the cart / checkout / account page / a WC endpoint URL. Stubbing exactly
 * those lets the exclusion rules be tested on their own, without a WooCommerce
 * install — the branches under test belong to this plugin, not to WooCommerce.
 *
 * When a real WooCommerce is loaded (the `CITECUE_WITH_WOOCOMMERCE` CI job)
 * the stub stands down and `is_active()` returns false, so tests relying on it
 * skip in favour of the real-plugin coverage.
 *
 * @package Citecue
 */

/**
 * Controls the fake WooCommerce page state.
 */
class Citecue_Woocommerce_Stub {

	/**
	 * Which page the current request is pretending to be.
	 *
	 * @var array<string,bool>
	 */
	public static $state = array(
		'cart'     => false,
		'checkout' => false,
		'account'  => false,
		'endpoint' => false,
	);

	/**
	 * Whether the stub (rather than a real WooCommerce) is providing the API.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Declares the stub class/functions if WooCommerce itself is absent.
	 *
	 * @return void
	 */
	public static function install() {
		if ( class_exists( 'WooCommerce' ) || function_exists( 'is_cart' ) ) {
			return;
		}

		require_once __DIR__ . '/woocommerce-stub-functions.php';

		self::$active = true;
	}

	/**
	 * Whether the stub is in charge (i.e. no real WooCommerce is loaded).
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/**
	 * Pretends the current request is a given WooCommerce page.
	 *
	 * @param string $page One of cart|checkout|account|endpoint.
	 * @return void
	 */
	public static function pretend( $page ) {
		self::reset();
		self::$state[ $page ] = true;
	}

	/**
	 * Clears the pretend state.
	 *
	 * @return void
	 */
	public static function reset() {
		foreach ( array_keys( self::$state ) as $key ) {
			self::$state[ $key ] = false;
		}
	}
}
