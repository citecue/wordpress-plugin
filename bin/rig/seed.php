<?php
/**
 * Installs the rig's WordPress, activates the plugin and puts it in the state a
 * real connect claim would leave it in — without driving the browser redirect,
 * which is not what this rig is testing.
 *
 * @package Citecue
 */

$rig = getenv( 'RIG_DIR' );
if ( ! $rig ) {
	fwrite( STDERR, "error: RIG_DIR not set\n" );
	exit( 1 );
}

define( 'WP_INSTALLING', true );
require_once $rig . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if ( ! is_blog_installed() ) {
	wp_install( 'CiteCue Rig', 'admin', 'admin@example.test', true, '', 'rig-password' );
}

switch_theme( 'citecue-rig' );
update_option( 'permalink_structure', '/%postname%/' );
activate_plugin( 'citecue-ai-auto-fix/citecue.php' );

if ( ! is_plugin_active( 'citecue-ai-auto-fix/citecue.php' ) ) {
	fwrite( STDERR, "error: the plugin did not activate\n" );
	exit( 1 );
}

$plugin = Citecue_Plugin::instance();
$plugin->settings->update(
	array(
		'api_key'               => 'ck_live_rig',
		'public_key'            => 'pk_rig',
		'project_domain'        => '127.0.0.1',
		'seo_head_enabled'      => true,
		'seo_head_reported'     => true,
		'capabilities_reported' => $plugin->settings->active_delivery_capabilities(),
	)
);

if ( ! get_page_by_path( 'protein-guide' ) ) {
	wp_insert_post(
		array(
			'post_title'   => 'Protein guide',
			'post_name'    => 'protein-guide',
			'post_content' => '<p>Everything we know about protein bars, from sourcing to storage.</p>',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);
}

flush_rewrite_rules( true );

printf( "WordPress %s, theme citecue-rig, plugin %s\n", get_bloginfo( 'version' ), CITECUE_VERSION );
printf( "declared capabilities: %s\n", implode( ', ', $plugin->settings->active_delivery_capabilities() ) );
