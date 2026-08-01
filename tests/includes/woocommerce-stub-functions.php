<?php
/**
 * The fake WooCommerce surface itself. Loaded only by
 * Citecue_Woocommerce_Stub::install(), and only when no real WooCommerce is
 * present.
 *
 * @package Citecue
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Stands in for the WooCommerce main class the plugin probes with class_exists().
 */
class WooCommerce {} // phpcs:ignore Squiz.Commenting.ClassComment.SpacingAfter

/**
 * Whether the request is the cart page.
 *
 * @return bool
 */
function is_cart() {
	return Citecue_Woocommerce_Stub::$state['cart'];
}

/**
 * Whether the request is the checkout page.
 *
 * @return bool
 */
function is_checkout() {
	return Citecue_Woocommerce_Stub::$state['checkout'];
}

/**
 * Whether the request is an account page.
 *
 * @return bool
 */
function is_account_page() {
	return Citecue_Woocommerce_Stub::$state['account'];
}

/**
 * Whether the request is a WooCommerce endpoint URL.
 *
 * @param string|false $endpoint Endpoint name (ignored by the stub).
 * @return bool
 */
function is_wc_endpoint_url( $endpoint = false ) {
	unset( $endpoint );
	return Citecue_Woocommerce_Stub::$state['endpoint'];
}
