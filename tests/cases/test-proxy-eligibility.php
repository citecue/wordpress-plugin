<?php
/**
 * Which requests the middleware is allowed to intercept.
 *
 * The promise the plugin makes is that it never touches a human visitor or a
 * WordPress subsystem — everything here is a request that must reach the theme
 * untouched even when a crawler user agent is attached to it.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Proxy::decide
 */
class Test_Citecue_Proxy_Eligibility extends Citecue_Test_Case {

	/**
	 * Sets up a configured, servable site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->configure_delivery();
		$this->factory->post->create( array( 'post_name' => 'hello-world' ) );
	}

	/**
	 * A plain crawler GET is the case everything else is measured against.
	 *
	 * @return void
	 */
	public function test_a_crawler_get_is_eligible() {
		$this->fake_crawler_request();
		$this->http->queue( 'page', 200, '<html>optimized</html>' );

		$this->assertServed( '<html>optimized</html>', $this->proxy()->decide() );
	}

	/**
	 * @return void
	 */
	public function test_human_traffic_is_never_intercepted() {
		$this->fake_crawler_request( '/hello-world/', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/126.0.0.0 Safari/537.36' );

		$this->assertPassedThrough( 'not-a-crawler', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_non_get_requests_are_skipped() {
		$this->fake_crawler_request();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_logged_in_users_are_skipped() {
		$this->fake_crawler_request();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_admin_requests_are_skipped() {
		$this->fake_crawler_request();
		set_current_screen( 'edit.php' );

		$decision = $this->proxy()->decide();
		set_current_screen( 'front' );

		$this->assertPassedThrough( 'not-eligible', $decision );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_feeds_are_skipped() {
		$this->fake_crawler_request( '/feed/' );

		$this->assertTrue( is_feed(), 'Test setup failed: /feed/ did not resolve to a feed request.' );
		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_robots_txt_is_skipped() {
		$this->fake_crawler_request( '/robots.txt' );
		$GLOBALS['wp_query']->is_robots = true;

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_sitemaps_are_skipped() {
		$this->fake_crawler_request( '/wp-sitemap.xml' );
		set_query_var( 'sitemap', 'index' );

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_previews_are_skipped() {
		$this->fake_crawler_request();
		$GLOBALS['wp_query']->is_preview = true;

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_embeds_are_skipped() {
		$this->fake_crawler_request();
		$GLOBALS['wp_query']->is_embed = true;

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_ajax_requests_are_skipped() {
		$this->fake_crawler_request();
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_cron_requests_are_skipped() {
		$this->fake_crawler_request();
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_serving_can_be_switched_off_in_settings() {
		$this->configure_delivery( array( 'serve_enabled' => false ) );
		$this->fake_crawler_request();

		$this->assertPassedThrough( 'not-configured', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Without credentials there is nothing to ask the API for — and no key to
	 * send it.
	 *
	 * @return void
	 */
	public function test_unconfigured_sites_never_call_the_api() {
		$this->plugin->settings->update(
			array(
				'api_key'    => '',
				'public_key' => '',
			)
		);
		$this->fake_crawler_request();

		$this->assertPassedThrough( 'not-configured', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_a_site_can_veto_individual_requests() {
		$this->fake_crawler_request();
		add_filter( 'citecue_should_serve', '__return_false' );

		$this->assertPassedThrough( 'vetoed', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * The veto filter receives the matched crawler and the request URL so a
	 * site can make the decision per bot or per path.
	 *
	 * @return void
	 */
	public function test_veto_filter_receives_crawler_and_url() {
		$url      = $this->fake_crawler_request( '/hello-world/' );
		$captured = array();

		add_filter(
			'citecue_should_serve',
			static function ( $serve, $crawler, $request_url ) use ( &$captured ) {
				$captured = array( $crawler, $request_url );
				return false;
			},
			10,
			3
		);

		$this->proxy()->decide();

		$this->assertSame( array( 'GPTBot', $url ), $captured );
	}
}
