<?php
/**
 * PHPUnit bootstrap: boots the WordPress test library (vendored via
 * wp-phpunit/wp-phpunit, against the WordPress core in
 * vendor/roots/wordpress-no-content) with this plugin loaded as a must-use
 * plugin.
 *
 * @package Citecue
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// wp-phpunit's autoloaded __loaded.php sets WP_PHPUNIT__DIR for us.
$citecue_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $citecue_tests_dir || ! file_exists( $citecue_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test library. Run `composer install` first.' . PHP_EOL;
	exit( 1 );
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}
if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $citecue_tests_dir . '/includes/functions.php';

/**
 * Loads WooCommerce (when installed and requested) and this plugin before
 * WordPress finishes booting. WooCommerce must come first: the plugin branches
 * on `class_exists( 'WooCommerce' )` at request time.
 *
 * @return void
 */
function citecue_manually_load_plugin() {
	if ( getenv( 'CITECUE_WITH_WOOCOMMERCE' ) ) {
		$woo = dirname( __DIR__ ) . '/vendor/wpackagist-plugin/woocommerce/woocommerce.php';
		if ( ! file_exists( $woo ) ) {
			echo 'CITECUE_WITH_WOOCOMMERCE is set but WooCommerce is not installed; ' .
				'run `composer require --dev wpackagist-plugin/woocommerce`.' . PHP_EOL;
			exit( 1 );
		}
		require $woo;
	}

	require dirname( __DIR__ ) . '/citecue.php';
}
tests_add_filter( 'muplugins_loaded', 'citecue_manually_load_plugin' );

// WooCommerce needs its tables installed before the first test runs.
if ( getenv( 'CITECUE_WITH_WOOCOMMERCE' ) ) {
	tests_add_filter(
		'setup_theme',
		function () {
			if ( ! defined( 'WC_USE_TRANSACTIONS' ) ) {
				define( 'WC_USE_TRANSACTIONS', false );
			}
			WC_Install::install();
		}
	);
}

require $citecue_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-citecue-http-mock.php';
require_once __DIR__ . '/includes/class-citecue-woocommerce-stub.php';
require_once __DIR__ . '/includes/class-citecue-test-case.php';

// Whether WooCommerce "exists" is a process-wide fact (class_exists), so the
// stub is switched on for a whole run rather than per test — otherwise the
// tests that assert WooCommerce-free behaviour would depend on test ordering.
// See README → Development for the three run modes.
if ( getenv( 'CITECUE_STUB_WOOCOMMERCE' ) ) {
	Citecue_Woocommerce_Stub::install();
}
