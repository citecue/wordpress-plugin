<?php
/**
 * Shared setup for the CiteCue test suite: a clean plugin state, a fake
 * delivery API, and the small amount of request faking the middleware needs.
 *
 * @package Citecue
 */

/**
 * Base class for CiteCue tests.
 */
abstract class Citecue_Test_Case extends WP_UnitTestCase {

	const API_KEY    = 'ck_live_testkey';
	const PUBLIC_KEY = 'pk_test_project';

	/**
	 * Fake delivery API.
	 *
	 * @var Citecue_Http_Mock
	 */
	protected $http;

	/**
	 * Plugin container under test.
	 *
	 * @var Citecue_Plugin
	 */
	protected $plugin;

	/**
	 * Server superglobal as it was before the test.
	 *
	 * @var array
	 */
	private $server_backup;

	/**
	 * Boots a clean plugin state for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->server_backup = $_SERVER;
		$this->plugin        = Citecue_Plugin::instance();

		// WP_UnitTestCase rolls the database back between tests, but the
		// settings object caches its values for the request and transients may
		// live in a persistent object cache — both would leak across tests.
		$this->reset_settings_cache();
		wp_cache_flush();

		$this->http = new Citecue_Http_Mock();
		$this->http->enable();

		Citecue_Woocommerce_Stub::reset();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/';
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Tears the fakes back down.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->http->disable();
		Citecue_Woocommerce_Stub::reset();
		$_SERVER = $this->server_backup;

		parent::tear_down();
	}

	/**
	 * Drops the per-request settings cache so the next read sees the database.
	 *
	 * @return void
	 */
	protected function reset_settings_cache() {
		$values = new ReflectionProperty( 'Citecue_Settings', 'values' );
		$values->setAccessible( true );
		$values->setValue( $this->plugin->settings, null );
	}

	/**
	 * Gives the plugin a working delivery configuration.
	 *
	 * @param array $overrides Settings to override.
	 * @return void
	 */
	protected function configure_delivery( array $overrides = array() ) {
		$this->plugin->settings->update(
			array_merge(
				array(
					'api_key'          => self::API_KEY,
					'public_key'       => self::PUBLIC_KEY,
					'serve_enabled'    => true,
					'llms_txt_enabled' => true,
				),
				$overrides
			)
		);
	}

	/**
	 * Turns the current request into one from an AI crawler.
	 *
	 * @param string $path       Request path.
	 * @param string $user_agent Crawler user agent.
	 * @return string The absolute URL of the faked request.
	 */
	protected function fake_crawler_request( $path = '/hello-world/', $user_agent = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)' ) {
		// go_to() runs the main query, so the conditional tags the eligibility
		// check relies on (is_feed(), is_robots(), …) answer truthfully.
		$this->go_to( home_url( $path ) );

		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		return home_url( $path );
	}

	/**
	 * Spends the whole per-minute outbound lookup budget.
	 *
	 * @param int $limit Budget in force for the test.
	 * @return void
	 */
	protected function exhaust_lookup_budget( $limit = 120 ) {
		set_transient( 'citecue_budget_' . (int) floor( time() / MINUTE_IN_SECONDS ), $limit, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Primes the page cache for a URL without going through the API.
	 *
	 * @param string $url  Absolute page URL.
	 * @param string $body Cached body.
	 * @param string $etag Cached ETag.
	 * @param string $mode Optimization mode.
	 * @return void
	 */
	protected function prime_page_cache( $url, $body, $etag = '"v1"', $mode = 'enriched' ) {
		$this->plugin->cache->set_page( $url, $body, $etag, $mode );
	}

	/**
	 * A proxy bound to the plugin under test.
	 *
	 * @return Citecue_Proxy
	 */
	protected function proxy() {
		return new Citecue_Proxy( $this->plugin );
	}

	/**
	 * An llms.txt handler bound to the plugin under test.
	 *
	 * @return Citecue_Llms_Txt
	 */
	protected function llms_txt() {
		return new Citecue_Llms_Txt( $this->plugin );
	}

	/**
	 * Asserts the proxy left the request to WordPress, for the stated reason.
	 *
	 * @param string $reason   Expected decision reason.
	 * @param array  $decision Decision under test.
	 * @return void
	 */
	protected function assertPassedThrough( $reason, array $decision ) {
		$this->assertFalse( $decision['serve'], "Expected pass-through ({$reason}), got a served response." );
		$this->assertSame( $reason, $decision['reason'] );
	}

	/**
	 * Asserts the proxy served a body.
	 *
	 * @param string $body     Expected body.
	 * @param array  $decision Decision under test.
	 * @param bool   $stale    Whether the body is expected to be flagged stale.
	 * @return void
	 */
	protected function assertServed( $body, array $decision, $stale = false ) {
		$this->assertTrue( $decision['serve'], "Expected a served response, got pass-through ({$decision['reason']})." );
		$this->assertSame( $body, $decision['body'] );
		if ( isset( $decision['stale'] ) ) {
			$this->assertSame( $stale, $decision['stale'] );
		}
	}
}
