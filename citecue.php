<?php
/**
 * Plugin Name:       CiteCue AI Auto-Fix
 * Plugin URI:        https://github.com/henry-mosh/citecue-wordpress-plugin
 * Description:       Serves CiteCue-optimized versions of your pages to AI bots and crawlers, publishes your llms.txt, and lets CiteCue push brand-building draft content into WordPress.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            CiteCue
 * Author URI:        https://app.citecue.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       citecue
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CITECUE_VERSION', '1.0.0' );
define( 'CITECUE_PLUGIN_FILE', __FILE__ );
define( 'CITECUE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-settings.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-crawlers.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-cache.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-activity-log.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-api-client.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-proxy.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-llms-txt.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-ingest.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-admin.php';
require_once CITECUE_PLUGIN_DIR . 'includes/class-citecue-plugin.php';

register_activation_hook( __FILE__, array( 'Citecue_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Citecue_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Citecue_Plugin', 'instance' ) );
