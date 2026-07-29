<?php
/**
 * Configuration for the WordPress PHPUnit test library.
 *
 * Everything is environment-driven so the same file works locally and in CI;
 * the defaults match a plain `mysql`/`mariadb` on localhost. WordPress core
 * itself comes from Composer (roots/wordpress-no-content), so ABSPATH is
 * resolved from the vendor directory rather than a separately downloaded copy.
 *
 * @package Citecue
 */

/**
 * Reads a test environment variable, falling back to a default.
 *
 * @param string $name     Variable name.
 * @param string $fallback Value to use when the variable is unset or empty.
 * @return string
 */
function citecue_test_env( $name, $fallback ) {
	$value = getenv( $name );
	return ( false === $value || '' === $value ) ? $fallback : $value;
}

define( 'DB_NAME', citecue_test_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', citecue_test_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', citecue_test_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'citecue-tests' );
define( 'SECURE_AUTH_KEY', 'citecue-tests' );
define( 'LOGGED_IN_KEY', 'citecue-tests' );
define( 'NONCE_KEY', 'citecue-tests' );
define( 'AUTH_SALT', 'citecue-tests' );
define( 'SECURE_AUTH_SALT', 'citecue-tests' );
define( 'LOGGED_IN_SALT', 'citecue-tests' );
define( 'NONCE_SALT', 'citecue-tests' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'CiteCue Test Site' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEFAULT_THEME', 'default' );

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

$table_prefix = 'wptests_';
