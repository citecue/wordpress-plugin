<?php
/**
 * Plugin Name:       CiteCue AI Auto-Fix
 * Plugin URI:        https://github.com/citecue/wordpress-plugin
 * Description:       Serves CiteCue-optimized versions of your pages to AI bots and crawlers, adds CiteCue's enriched SEO metadata to your live pages, publishes your llms.txt, and lets CiteCue push brand-building draft content into WordPress.
 * Version:           1.1.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CiteCue
 * Author URI:        https://app.citecue.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       citecue-ai-auto-fix
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Stand down for a pre-WordPress.org copy, which cannot stand down for us.
 *
 * Releases before the move to WordPress.org unpacked to citecue/, and the only
 * one that ever did is 1.0.0 — which predates the guard below and so defines
 * the constants and runs its requires unconditionally. The directory's copy
 * installs as citecue-ai-auto-fix/, so a site carrying the old one gains a
 * second plugin rather than an upgrade, and `require_once` does not save us:
 * the two copies are two paths, so the second to load redeclares every class
 * and takes the site down.
 *
 * Which of them is second is not a coin toss. activate_plugin() sorts
 * active_plugins before storing it, '-' sorts before '/', so
 * citecue-ai-auto-fix/citecue.php is always included first and citecue/ is
 * always the one that fatals. The guard below therefore never gets the chance
 * to fire in the case it was written for: by the time 1.0.0 runs, it is this
 * copy's classes it is redeclaring, and 1.0.0 has no guard to check.
 *
 * So this copy yields instead. The site keeps running — on 1.0.0, which is the
 * worse version but a working one — and the notice says which directory to
 * delete to get this one back. Deleting it is also what makes this branch stop
 * running, so the check confirms the file is really still there rather than
 * trusting a stale active_plugins entry, which would strand the site on a copy
 * that is no longer installed.
 */
$citecue_legacy_is_running = ( static function () {
	$legacy = 'citecue/citecue.php';

	if ( plugin_basename( __FILE__ ) === $legacy ) {
		return false;
	}

	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	return in_array( $legacy, $active, true ) && file_exists( WP_PLUGIN_DIR . '/' . $legacy );
} )();

if ( $citecue_legacy_is_running ) {
	$citecue_legacy_notice = static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// Duplicated in the guard below rather than shared through a helper:
		// this is the one file that can legitimately be included twice, and a
		// named function here is a redeclaration waiting to happen.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'plugins' !== $screen->id && 'plugins-network' !== $screen->id ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: the older plugin directory, e.g. citecue/. 2: this plugin's directory. */
					__( 'CiteCue AI Auto-Fix is installed twice. An older copy in %1$s is the one WordPress is running, so the copy in %2$s has not loaded. Deactivate and delete the older one to switch to this version — your settings and connection are stored in the database and carry over untouched.', 'citecue-ai-auto-fix' ),
					'citecue/',
					dirname( plugin_basename( __FILE__ ) ) . '/'
				)
			)
		);
	};

	add_action( 'admin_notices', $citecue_legacy_notice );
	add_action( 'network_admin_notices', $citecue_legacy_notice );

	unset( $citecue_legacy_is_running, $citecue_legacy_notice );
	return;
}

unset( $citecue_legacy_is_running );

/*
 * Stand down if another copy of this plugin already loaded.
 *
 * The case above is the one duplicate this plugin has actually shipped. This
 * one catches the rest: a GitHub "Download ZIP" unpacks to
 * wordpress-plugin-main/, and any directory sorting after
 * citecue-ai-auto-fix/ loads second, sees the constant and does nothing —
 * which turns a white screen into an admin notice naming the directory to
 * delete.
 *
 * The notice belongs on a Plugins screen and nowhere else: deleting a plugin
 * directory is a Plugins-screen job, and nothing about this is urgent enough to
 * follow an administrator through the rest of their dashboard. Both Plugins
 * screens count, though — a network-activated copy can only be deactivated from
 * Network Admin, and `admin_notices` does not fire there at all.
 */
if ( defined( 'CITECUE_VERSION' ) ) {
	$citecue_duplicate_notice = static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'plugins' !== $screen->id && 'plugins-network' !== $screen->id ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: plugin file that is running, e.g. citecue/citecue.php. 2: duplicate plugin file that did not load. */
					__( 'CiteCue AI Auto-Fix is installed twice. WordPress is running %1$s, so the copy in %2$s did not load. Deactivate and delete whichever of the two you do not want to keep.', 'citecue-ai-auto-fix' ),
					plugin_basename( CITECUE_PLUGIN_FILE ),
					plugin_basename( __FILE__ )
				)
			)
		);
	};

	add_action( 'admin_notices', $citecue_duplicate_notice );
	add_action( 'network_admin_notices', $citecue_duplicate_notice );

	unset( $citecue_duplicate_notice );
	return;
}

define( 'CITECUE_VERSION', '1.1.2' );
define( 'CITECUE_PLUGIN_FILE', __FILE__ );
define( 'CITECUE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-settings.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-crawlers.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-cache.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-activity-log.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-api-client.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-connect.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-proxy.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-seo-head.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-llms-txt.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-ingest.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-admin.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-plugin.php';

register_activation_hook( __FILE__, array( 'Citecue_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Citecue_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Citecue_Plugin', 'instance' ) );
