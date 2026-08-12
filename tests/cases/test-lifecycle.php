<?php
/**
 * Activation, the daily sync cron, deactivation and uninstall.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Plugin
 */
class Test_Citecue_Lifecycle extends Citecue_Test_Case {

	/**
	 * Starts each test from a clean schedule.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( Citecue_Plugin::CRON_HOOK );
	}

	/**
	 * @return void
	 */
	public function test_activation_schedules_the_daily_sync() {
		Citecue_Plugin::activate();

		$this->assertNotFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Citecue_Plugin::CRON_HOOK ) );
	}

	/**
	 * @return void
	 */
	public function test_activation_generates_an_ingest_secret() {
		$this->plugin->settings->update( array( 'ingest_secret' => '' ) );

		Citecue_Plugin::activate();
		$this->reset_settings_cache();

		$secret = (string) $this->plugin->settings->get( 'ingest_secret' );

		$this->assertStringStartsWith( 'cws_', $secret );
		$this->assertSame( 44, strlen( $secret ) );
	}

	/**
	 * Re-activating must not rotate the secret out from under CiteCue.
	 *
	 * @return void
	 */
	public function test_reactivation_keeps_the_existing_secret() {
		Citecue_Plugin::activate();
		$this->reset_settings_cache();
		$first = $this->plugin->settings->get( 'ingest_secret' );

		Citecue_Plugin::activate();
		$this->reset_settings_cache();

		$this->assertSame( $first, $this->plugin->settings->get( 'ingest_secret' ) );
	}

	/**
	 * @return void
	 */
	public function test_activation_is_idempotent() {
		Citecue_Plugin::activate();
		$first = wp_next_scheduled( Citecue_Plugin::CRON_HOOK );

		Citecue_Plugin::activate();

		$this->assertSame( $first, wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
	}

	/**
	 * @return void
	 */
	public function test_deactivation_clears_the_cron() {
		Citecue_Plugin::activate();

		Citecue_Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
	}

	/**
	 * Queued metadata refreshes are single events carrying a URL each, so there
	 * can be many of them and none is found by the daily hook's name.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_queued_metadata_refreshes() {
		wp_schedule_single_event( time(), Citecue_Seo_Head::REFRESH_HOOK, array( home_url( '/a/' ) ) );
		wp_schedule_single_event( time(), Citecue_Seo_Head::REFRESH_HOOK, array( home_url( '/b/' ) ) );

		Citecue_Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Citecue_Seo_Head::REFRESH_HOOK, array( home_url( '/a/' ) ) ) );
		$this->assertFalse( wp_next_scheduled( Citecue_Seo_Head::REFRESH_HOOK, array( home_url( '/b/' ) ) ) );
	}

	/**
	 * A dropped schedule (a cron plugin, a botched migration) must heal itself
	 * rather than silently stop refreshing the crawler registry forever.
	 *
	 * @return void
	 */
	public function test_a_missing_cron_is_rescheduled_on_init() {
		$this->assertFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );

		$this->plugin->on_init();

		$this->assertNotFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
	}

	/**
	 * @return void
	 */
	public function test_the_daily_sync_refreshes_the_crawler_registry() {
		$this->configure_delivery();
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 3,
					'tokens'  => array( 'FutureBot' ),
				)
			)
		);

		$this->plugin->daily_sync();

		$this->assertContains( 'FutureBot', $this->plugin->crawlers->get_tokens() );
	}

	/**
	 * A failing sync must not throw — it runs unattended on cron.
	 *
	 * @return void
	 */
	public function test_the_daily_sync_survives_an_outage() {
		$this->configure_delivery();
		$this->http->queue_error( 'crawlers' );

		$this->plugin->daily_sync();

		$this->assertSame( 'GPTBot', $this->plugin->crawlers->match( 'GPTBot/1.2' ) );
	}

	/**
	 * Activating a plugin is not consent to talk to a third party. Until the
	 * site has connected itself, the cron must reach nothing — the HTTP mock
	 * throws on any unqueued call, so an outbound request fails this test.
	 *
	 * @return void
	 */
	public function test_the_daily_sync_is_silent_until_the_site_connects() {
		$this->plugin->daily_sync();

		$this->assertSame( 0, $this->http->count() );
		$this->assertSame( Citecue_Crawlers::bundled_tokens(), $this->plugin->crawlers->get_tokens() );
	}

	/**
	 * A site that installed a pre-WordPress.org release has this plugin in a
	 * citecue/ directory, and the directory's copy installs alongside it
	 * rather than over it. Loading the second copy must be inert: without the
	 * guard it redeclares every class and the site fatals.
	 *
	 * The bootstrap has already loaded the plugin, so requiring the main file
	 * again puts us in exactly the state the second copy sees.
	 *
	 * @return void
	 */
	public function test_a_second_copy_stands_down_with_a_notice() {
		$this->assertTrue( defined( 'CITECUE_VERSION' ), 'The first copy should already be loaded.' );

		$notice = $this->render_duplicate_notice_as( 'administrator' );

		$this->assertStringContainsString( 'installed twice', $notice );
		$this->assertStringContainsString( plugin_basename( CITECUE_PLUGIN_FILE ), $notice );
	}

	/**
	 * The notice names directories to delete, so it is only for someone who
	 * can act on it.
	 *
	 * @return void
	 */
	public function test_the_duplicate_notice_is_hidden_from_users_who_cannot_act() {
		$this->assertSame( '', $this->render_duplicate_notice_as( 'subscriber' ) );
	}

	/**
	 * Deleting a plugin directory happens on the Plugins screen, and the notice
	 * asks for nothing that can be done anywhere else — so that is the only
	 * screen it appears on.
	 *
	 * @return void
	 */
	public function test_the_duplicate_notice_stays_on_the_plugins_screen() {
		$this->assertSame( '', $this->render_duplicate_notice_as( 'administrator', 'dashboard' ) );
	}

	/**
	 * A network-activated copy can only be deactivated from Network Admin →
	 * Plugins, and `admin_notices` does not fire anywhere in Network Admin — so
	 * scoping to that one hook hides the warning from the only screen where a
	 * super admin can act on it.
	 *
	 * @return void
	 */
	public function test_the_duplicate_notice_reaches_network_admin() {
		$notice = $this->render_duplicate_notice_as( 'administrator', 'plugins-network', 'network_admin_notices' );

		$this->assertStringContainsString( 'installed twice', $notice );
	}

	/**
	 * @return void
	 */
	public function test_the_legacy_notice_reaches_network_admin() {
		$this->install_legacy_copy();

		$notice = $this->render_duplicate_notice_as( 'administrator', 'plugins-network', 'network_admin_notices' );

		$this->assertStringContainsString( 'An older copy in citecue/', $notice );
	}

	/**
	 * Network Admin has its own dashboard and its own settings screens, and the
	 * notice has no more business on those than on the per-site ones.
	 *
	 * @return void
	 */
	public function test_the_duplicate_notice_stays_off_the_network_dashboard() {
		$notice = $this->render_duplicate_notice_as( 'administrator', 'dashboard-network', 'network_admin_notices' );

		$this->assertSame( '', $notice );
	}

	/**
	 * The one duplicate this plugin has actually shipped, and the one the guard
	 * above cannot cover.
	 *
	 * The citecue/ directory only ever held 1.0.0, which predates the guard and
	 * so loads its classes unconditionally. WordPress sorts active_plugins and
	 * '-' sorts before
	 * '/', so citecue-ai-auto-fix/ is always included first — meaning 1.0.0 is
	 * always the copy that redeclares and fatals, and it has no guard with
	 * which to stand down. This copy has to be the one that yields.
	 *
	 * @return void
	 */
	public function test_this_copy_yields_to_a_legacy_copy_that_cannot_yield() {
		$this->install_legacy_copy();

		$notice = $this->render_duplicate_notice_as( 'administrator' );

		$this->assertStringContainsString( 'An older copy in citecue/', $notice );
	}

	/**
	 * A deleted directory can outlive its active_plugins entry. Yielding to a
	 * copy that is not there any more would leave the site running neither.
	 *
	 * @return void
	 */
	public function test_a_stale_entry_for_a_deleted_copy_is_ignored() {
		$this->install_legacy_copy( false );

		$notice = $this->render_duplicate_notice_as( 'administrator' );

		$this->assertStringNotContainsString( 'An older copy in citecue/', $notice );
	}

	/**
	 * Puts a pre-WordPress.org copy in active_plugins, optionally with the file
	 * on disk to match. Registered for removal so the plugins directory is left
	 * as it was found.
	 *
	 * @param bool $on_disk Whether the plugin file also exists.
	 * @return void
	 */
	private function install_legacy_copy( $on_disk = true ) {
		update_option( 'active_plugins', array( 'citecue/citecue.php' ) );

		if ( ! $on_disk ) {
			return;
		}

		$dir = WP_PLUGIN_DIR . '/citecue';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $dir . '/citecue.php', "<?php\n// Test double for the 1.0.0 release.\n" );

		$this->legacy_copy_path = $dir;
	}

	/**
	 * Directory created by install_legacy_copy(), to remove afterwards.
	 *
	 * @var string
	 */
	private $legacy_copy_path = '';

	/**
	 * @return void
	 */
	public function tear_down() {
		if ( '' !== $this->legacy_copy_path ) {
			@unlink( $this->legacy_copy_path . '/citecue.php' );
			@rmdir( $this->legacy_copy_path );
			$this->legacy_copy_path = '';
		}
		parent::tear_down();
	}

	/**
	 * Loads the main file a second time — which is the state the duplicate
	 * copy boots into — and renders what it hooked onto `admin_notices`.
	 *
	 * The hook is emptied first so that firing it runs only the guard's
	 * callback. Other plugins listen on `admin_notices` too, and with a real
	 * WooCommerce installed one of them reads `get_current_screen()`, which is
	 * null outside a genuine admin request. Isolating the hook keeps this a
	 * test of the guard rather than of whatever else happens to be active.
	 *
	 * @param string $role      Role of the user viewing the admin screen.
	 * @param string $screen_id Screen being viewed.
	 * @param string $hook      Notice hook the screen fires: WordPress fires
	 *                          `admin_notices` and `network_admin_notices` in
	 *                          mutually exclusive branches, never both.
	 * @return string Rendered notice markup.
	 */
	private function render_duplicate_notice_as( $role, $screen_id = 'plugins', $hook = 'admin_notices' ) {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'network_admin_notices' );

		require dirname( __DIR__, 2 ) . '/citecue.php';

		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		// On multisite `activate_plugins` maps through `manage_network_plugins`,
		// so a site administrator does not have it — which is correct, since a
		// network-activated duplicate is only a super admin's to remove. The
		// notice is gated on that capability, so the viewer has to hold it.
		if ( is_multisite() && 'administrator' === $role ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		set_current_screen( $screen_id );

		ob_start();
		do_action( $hook );
		$notice = ob_get_clean();

		$GLOBALS['current_screen'] = null;

		return $notice;
	}

	/**
	 * Uninstall removes the plugin's own settings…
	 *
	 * @return void
	 */
	public function test_uninstall_removes_plugin_options() {
		$this->configure_delivery();
		$this->plugin->activity->record( 'GPTBot', '/a/', 'served' );
		$this->http->queue( 'loopback', 200, 'llms', array( 'x-citecue' => 'llms-txt' ) );
		$this->plugin->connect->verify_install();
		Citecue_Plugin::activate();

		$this->run_uninstall();

		$this->assertFalse( get_option( Citecue_Settings::OPTION ) );
		$this->assertFalse( get_option( Citecue_Activity_Log::OPTION ) );
		$this->assertFalse( get_option( Citecue_Crawlers::OPTION ) );
		// Left behind, this outlives a reinstall and reports the previous
		// installation's result as if it were the new one's.
		$this->assertFalse( get_option( Citecue_Connect::VERIFY_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
	}

	/**
	 * Dismissals are one row per administrator, in a table nobody else cleans
	 * up — so they go with everything else rather than outliving the plugin.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_dismissed_notices() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_option( $user_id, Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', time() );

		$this->run_uninstall();

		$this->assertEmpty( get_user_option( Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', $user_id ) );
	}

	/**
	 * The usermeta table is shared by a whole network, and WordPress includes
	 * uninstall.php once. A dismissal made on any other site in the network is
	 * therefore still ours to remove — nothing else ever will, and unlike the
	 * per-site options tables, this one outlives every site that wrote to it.
	 *
	 * @group multisite
	 * @return void
	 */
	public function test_uninstall_removes_dismissals_from_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other   = self::factory()->blog->create();
		$key     = Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect';

		switch_to_blog( $other );
		update_user_option( $user_id, $key, time() );
		restore_current_blog();
		update_user_option( $user_id, $key, time() );

		$this->run_uninstall();

		switch_to_blog( $other );
		$elsewhere = get_user_option( $key, $user_id );
		restore_current_blog();

		$this->assertEmpty( $elsewhere, 'The other site’s dismissal should be gone too.' );
		$this->assertEmpty( get_user_option( $key, $user_id ) );
	}

	/**
	 * …but content pushed by CiteCue belongs to the site, so it stays.
	 *
	 * @return void
	 */
	public function test_uninstall_keeps_pushed_content() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Pushed brief' ) );
		update_post_meta( $post_id, '_citecue_external_id', 'brief-1' );

		$this->run_uninstall();

		$this->assertSame( 'Pushed brief', get_post( $post_id )->post_title );
	}

	/**
	 * Runs uninstall.php the way WordPress does.
	 *
	 * @return void
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'citecue-ai-auto-fix/citecue.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
