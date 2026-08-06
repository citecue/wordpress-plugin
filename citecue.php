<?php
/**
 * Plugin Name:       CiteCue AI Auto-Fix
 * Plugin URI:        https://github.com/citecue/wordpress-plugin
 * Description:       Serves CiteCue-optimized versions of your pages to AI bots and crawlers, publishes your llms.txt, and lets CiteCue push brand-building draft content into WordPress.
 * Version:           1.0.3
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
 * Stand down if another copy of this plugin already loaded.
 *
 * Releases before the move to WordPress.org shipped an archive that unpacked
 * to citecue/. The directory's copy installs as citecue-ai-auto-fix/, so a
 * site carrying the old one gains a second plugin rather than an upgrade —
 * and `require_once` does not save us, because the two copies are two paths.
 * Both would run their requires, the second would redeclare every class, and
 * the site would go down with a fatal error on the next request.
 *
 * The copy that loses the race does nothing and says so, which turns a white
 * screen into an admin notice naming the directory to delete.
 */
if ( defined( 'CITECUE_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
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
		}
	);

	return;
}

define( 'CITECUE_VERSION', '1.0.3' );
define( 'CITECUE_PLUGIN_FILE', __FILE__ );
define( 'CITECUE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-settings.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-crawlers.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-cache.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-activity-log.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-api-client.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-connect.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-proxy.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-llms-txt.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-ingest.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-admin.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-plugin.php';

register_activation_hook( __FILE__, array( 'Citecue_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Citecue_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Citecue_Plugin', 'instance' ) );
