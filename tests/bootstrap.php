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

	// Polylang also loads before the plugin, which branches on its functions at
	// request time. Its activation hook never runs under the test library, so
	// the options it needs are seeded explicitly in citecue_seed_polylang().
	if ( getenv( 'CITECUE_WITH_POLYLANG' ) ) {
		$polylang = dirname( __DIR__ ) . '/vendor/wpackagist-plugin/polylang/polylang.php';
		if ( ! file_exists( $polylang ) ) {
			echo 'CITECUE_WITH_POLYLANG is set but Polylang is not installed; ' .
				'run `composer install`.' . PHP_EOL;
			exit( 1 );
		}
		require $polylang;
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

// Polylang languages are terms, so they are created once (autocommit, outside
// any test transaction) and survive every per-test rollback. They must exist
// before Polylang's own `plugins_loaded` (priority 1) decides which context to
// boot, hence priority 0. The test library installs the database in a
// separate WP_INSTALLING process that has no tables yet, so that run skips.
if ( getenv( 'CITECUE_WITH_POLYLANG' ) ) {
	tests_add_filter( 'plugins_loaded', 'citecue_seed_polylang', 0 );

	// The plugin's endpoints are REST requests, and Polylang boots its REST
	// context for those: it assigns the default language to every new post.
	// PHPUnit is not a REST request, so without this Polylang boots its
	// front-end context, new posts stay language-less and Polylang's language
	// filter then hides them from the plugin's own lookups.
	tests_add_filter(
		'pll_context',
		function () {
			return 'PLL_REST_Request';
		}
	);
}

require $citecue_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-citecue-http-mock.php';
require_once __DIR__ . '/includes/class-citecue-woocommerce-stub.php';
require_once __DIR__ . '/includes/class-citecue-wpml-stub.php';
require_once __DIR__ . '/includes/class-citecue-test-case.php';

// Whether WooCommerce "exists" is a process-wide fact (class_exists), so the
// stub is switched on for a whole run rather than per test — otherwise the
// tests that assert WooCommerce-free behaviour would depend on test ordering.
// See README → Development for the three run modes.
if ( getenv( 'CITECUE_STUB_WOOCOMMERCE' ) ) {
	Citecue_Woocommerce_Stub::install();
}

// Same reasoning for the WPML stand-in: `ICL_SITEPRESS_VERSION` is a
// process-wide fact. It is installed after WordPress booted, which is early
// enough because the plugin only checks for WPML at request time.
if ( getenv( 'CITECUE_STUB_WPML' ) ) {
	Citecue_Wpml_Stub::install();
}

/**
 * The languages every Polylang run works with.
 *
 * @return array<string,array>
 */
function citecue_polylang_languages() {
	return array(
		'en' => array(
			'name'       => 'English',
			'slug'       => 'en',
			'locale'     => 'en_US',
			'rtl'        => 0,
			'term_group' => 0,
		),
		'pl' => array(
			'name'       => 'Polski',
			'slug'       => 'pl',
			'locale'     => 'pl_PL',
			'rtl'        => 0,
			'term_group' => 1,
		),
	);
}

/**
 * Creates the `en` (default) and `pl` languages before Polylang boots.
 *
 * @return void
 */
function citecue_seed_polylang() {
	if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
		return;
	}

	Polylang::define_constants();
	add_action( 'pll_init_options_for_blog', array( \WP_Syntex\Polylang\Options\Registry::class, 'register' ) );
	$model = new PLL_Model( new \WP_Syntex\Polylang\Options\Options() );

	foreach ( citecue_polylang_languages() as $slug => $args ) {
		if ( ! term_exists( $slug, 'language' ) ) {
			$result = $model->add_language( $args );
			if ( is_wp_error( $result ) ) {
				echo 'Could not create the Polylang language ' . $slug . ': ' . $result->get_error_message() . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput
				exit( 1 );
			}
		}
	}

	$model->clean_languages_cache();
}

/**
 * Recreates the languages if the database no longer has them.
 *
 * WP_UnitTestCase wipes every term (including Polylang's language terms) after
 * each test class, while Polylang's cached language list survives in a
 * transient, so a later class would see languages that point at nothing.
 * Citecue_Test_Case::set_up() calls this, so every test starts with `en` and
 * `pl` present. A no-op outside `test:polylang` runs.
 *
 * @return void
 */
function citecue_ensure_polylang_languages() {
	if ( ! getenv( 'CITECUE_WITH_POLYLANG' ) || ! function_exists( 'PLL' ) ) {
		return;
	}

	$missing = false;
	foreach ( array_keys( citecue_polylang_languages() ) as $slug ) {
		if ( ! term_exists( $slug, 'language' ) ) {
			$missing = true;
		}
	}
	if ( ! $missing ) {
		return;
	}

	// Drop the stale list first: add_language() validates against it and would
	// refuse the "duplicate" codes.
	PLL()->model->clean_languages_cache();
	foreach ( citecue_polylang_languages() as $slug => $args ) {
		if ( ! term_exists( $slug, 'language' ) ) {
			PLL()->model->add_language( $args );
		}
	}
	PLL()->model->clean_languages_cache();
}
