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
		$this->http->queue_error( 'crawlers' );

		$this->plugin->daily_sync();

		$this->assertSame( 'GPTBot', $this->plugin->crawlers->match( 'GPTBot/1.2' ) );
	}

	/**
	 * Uninstall removes the plugin's own settings…
	 *
	 * @return void
	 */
	public function test_uninstall_removes_plugin_options() {
		$this->configure_delivery();
		$this->plugin->activity->record( 'GPTBot', '/a/', 'served' );
		Citecue_Plugin::activate();

		$this->run_uninstall();

		$this->assertFalse( get_option( Citecue_Settings::OPTION ) );
		$this->assertFalse( get_option( Citecue_Activity_Log::OPTION ) );
		$this->assertFalse( get_option( Citecue_Crawlers::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Citecue_Plugin::CRON_HOOK ) );
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
			define( 'WP_UNINSTALL_PLUGIN', 'citecue/citecue.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
