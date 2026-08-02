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

delete_transient( 'citecue_circuit' );
delete_transient( 'citecue_ingest_rate' );
delete_transient( 'citecue_connect_state' );
// Page/llms.txt transients are salt-keyed and expire on their own within a day.

wp_clear_scheduled_hook( 'citecue_daily_sync' );
