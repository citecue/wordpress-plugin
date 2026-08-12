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

/*
 * One row per administrator who dismissed the reconnect notice, stored through
 * update_user_option() — so the key carries the site's table prefix.
 *
 * Every site's prefix, not just this one's. WordPress includes this file once,
 * for the site uninstalling the plugin, which is the right scope for the
 * options above: those live in per-site tables that go away with the site.
 * usermeta is one shared table for the whole network, so a key left behind
 * there is left behind for good, on a table that outlives every site that ever
 * wrote to it.
 */
global $wpdb;

$citecue_dismissal_key = 'citecue_dismissed_seo_head_reconnect';
$citecue_site_ids      = array( null );

if ( is_multisite() ) {
	// One indexed query returning ids. Uninstall happens once, so paying for
	// the full list here is cheaper than orphaning rows nothing else cleans up.
	$citecue_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
}

foreach ( $citecue_site_ids as $citecue_site_id ) {
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix( $citecue_site_id ) . $citecue_dismissal_key, '', true );
}

unset( $citecue_dismissal_key, $citecue_site_ids, $citecue_site_id );

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
