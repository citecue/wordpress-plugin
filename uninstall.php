<?php
/**
 * Uninstall cleanup. Removes plugin options, transients and the cron event.
 * Content pushed by CiteCue is regular WordPress content owned by the site —
 * it is deliberately NOT deleted.
 *
 * @package Citecue
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'citecue_settings' );
delete_option( 'citecue_crawlers' );
delete_option( 'citecue_activity' );
delete_option( 'citecue_cache_salt' );
delete_option( 'citecue_auth_failed' );
delete_option( 'citecue_projects_cache' );
delete_option( 'citecue_last_config_at' );
delete_option( 'citecue_install_verified' );

// One row per administrator who dismissed the reconnect notice. Stored through
// update_user_option(), so the key carries this site's table prefix — which is
// also what scopes the delete to this site on a multisite network, matching
// what the delete_option() calls above reach.
global $wpdb;
delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'citecue_dismissed_seo_head_reconnect', '', true );

delete_transient( 'citecue_circuit' );
delete_transient( 'citecue_ingest_rate' );
delete_transient( 'citecue_connect_state' );
// Page/llms.txt/SEO-head transients are salt-keyed and expire on their own
// within a day.

wp_clear_scheduled_hook( 'citecue_daily_sync' );
// Single events, one per URL awaiting a metadata refresh. wp_unschedule_hook()
// rather than wp_clear_scheduled_hook(), which only clears events whose
// arguments match the ones passed — each of these carries its own URL.
wp_unschedule_hook( 'citecue_refresh_seo_head' );
