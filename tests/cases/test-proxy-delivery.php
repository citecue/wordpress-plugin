<?php
/**
 * What the middleware does with each delivery API outcome.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Proxy::decide
 */
class Test_Citecue_Proxy_Delivery extends Citecue_Test_Case {

	/**
	 * URL of the faked crawler request.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Sets up a configured site with a crawler request in flight.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->configure_delivery();
		$this->factory->post->create( array( 'post_name' => 'hello-world' ) );
		$this->url = $this->fake_crawler_request( '/hello-world/' );
	}

	/**
	 * @return void
	 */
	public function test_200_is_served_and_cached() {
		$this->http->queue(
			'page',
			200,
			'<html>optimized</html>',
			array(
				'etag'           => '"v1"',
				'x-citecue-mode' => 'enriched',
			)
		);

		$decision = $this->proxy()->decide();

		$this->assertServed( '<html>optimized</html>', $decision );
		$this->assertSame( 'enriched', $decision['mode'] );

		$cached = $this->plugin->cache->get_page( $this->url );
		$this->assertSame( '<html>optimized</html>', $cached['body'] );
		$this->assertSame( '"v1"', $cached['etag'] );
		$this->assertSame( 'enriched', $cached['mode'] );
	}

	/**
	 * The request carries the credentials, the channel marker and the crawler
	 * token — CiteCue attributes the hit from these.
	 *
	 * @return void
	 */
	public function test_delivery_request_is_addressed_correctly() {
		$this->http->queue( 'page', 200, '<html>optimized</html>' );
		$this->proxy()->decide();

		$request = $this->http->last( 'page' );

		$this->assertStringContainsString( 'k=' . self::PUBLIC_KEY, $request['url'] );
		$this->assertStringContainsString( 'b=GPTBot', $request['url'] );
		$this->assertStringContainsString( rawurlencode( $this->url ), $request['url'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $request['args']['headers']['Authorization'] );
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the header value is literally lowercase.
		$this->assertSame( 'wordpress', $request['args']['headers']['X-Citecue-Channel'] );
	}

	/**
	 * A second hit revalidates with the stored ETag instead of re-downloading.
	 *
	 * @return void
	 */
	public function test_304_serves_the_cached_body() {
		$this->prime_page_cache( $this->url, '<html>cached</html>', '"v1"', 'rewrite' );
		$this->http->queue( 'page', 304 );

		$decision = $this->proxy()->decide();

		$this->assertServed( '<html>cached</html>', $decision );
		$this->assertSame( 'rewrite', $decision['mode'] );
		$this->assertSame( '"v1"', $this->http->last( 'page' )['args']['headers']['If-None-Match'] );
	}

	/**
	 * A 304 is a served hit, not a stale one — CiteCue already counted it.
	 *
	 * @return void
	 */
	public function test_304_is_not_flagged_stale() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->http->queue( 'page', 304 );

		$this->assertFalse( $this->proxy()->decide()['stale'] );
	}

	/**
	 * A 304 with nothing cached (evicted mid-flight) has no body to serve, so
	 * the theme must answer rather than the plugin emitting an empty page.
	 *
	 * @return void
	 */
	public function test_304_without_a_cached_body_passes_through() {
		$this->http->queue( 'page', 304 );

		$this->assertPassedThrough( 'revalidated-without-cache', $this->proxy()->decide() );
	}

	/**
	 * @return void
	 */
	public function test_404_passes_through_and_negative_caches() {
		$this->http->queue( 'page', 404 );

		$this->assertPassedThrough( 'miss', $this->proxy()->decide() );
		$this->assertTrue( $this->plugin->cache->is_recent_miss( $this->url ) );
	}

	/**
	 * A negative-cached URL must not hit the API again for a minute.
	 *
	 * @return void
	 */
	public function test_a_recent_miss_short_circuits_the_api() {
		$this->http->queue( 'page', 404 );
		$this->proxy()->decide();

		$this->assertPassedThrough( 'recent-miss', $this->proxy()->decide() );
		$this->assertSame( 1, $this->http->count( 'page' ) );
	}

	/**
	 * A page that was optimized and then removed in CiteCue must disappear
	 * here too — otherwise the stale-on-error path would resurrect content the
	 * site owner has already withdrawn.
	 *
	 * @return void
	 */
	public function test_404_evicts_a_previously_cached_body() {
		$this->prime_page_cache( $this->url, '<html>withdrawn</html>' );
		$this->http->queue( 'page', 404 );

		$this->proxy()->decide();

		$this->assertNull( $this->plugin->cache->get_page( $this->url ) );
	}

	/**
	 * @return void
	 */
	public function test_401_passes_through_and_records_the_auth_failure() {
		$this->http->queue( 'page', 401 );

		$this->assertPassedThrough( 'unauthorized', $this->proxy()->decide() );
		$this->assertNotFalse( get_option( 'citecue_auth_failed' ) );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * A rejected key must not be retried on every crawler hit.
	 *
	 * @return void
	 */
	public function test_401_stops_further_api_calls() {
		$this->http->queue( 'page', 401 );
		$this->proxy()->decide();

		$this->fake_crawler_request( '/another-page/' );
		$this->proxy()->decide();

		$this->assertSame( 1, $this->http->count( 'page' ) );
	}

	/**
	 * A recovered key clears the failure flag.
	 *
	 * @return void
	 */
	public function test_a_later_miss_clears_the_auth_failure_flag() {
		update_option( 'citecue_auth_failed', time() );
		$this->http->queue( 'page', 404 );

		$this->proxy()->decide();

		$this->assertFalse( get_option( 'citecue_auth_failed' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_timeout_passes_through_and_opens_the_circuit() {
		$this->http->queue_error( 'page', 'cURL error 28: Operation timed out' );

		$this->assertPassedThrough( 'transport-error', $this->proxy()->decide() );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * A CiteCue outage must not cost the crawler the page it already had.
	 *
	 * @return void
	 */
	public function test_a_timeout_serves_the_stale_cached_body() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->http->queue_error( 'page' );

		$this->assertServed( '<html>cached</html>', $this->proxy()->decide(), true );
	}

	/**
	 * @return void
	 */
	public function test_a_500_passes_through_and_opens_the_circuit() {
		$this->http->queue( 'page', 500, 'upstream exploded' );

		$this->assertPassedThrough( 'server-error', $this->proxy()->decide() );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * An error body must never reach a crawler as if it were the page.
	 *
	 * @return void
	 */
	public function test_a_500_body_is_never_served() {
		$this->http->queue( 'page', 500, '<html>Internal Server Error</html>' );

		$this->assertFalse( $this->proxy()->decide()['serve'] );
	}

	/**
	 * @return void
	 */
	public function test_a_500_serves_the_stale_cached_body() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->http->queue( 'page', 500 );

		$this->assertServed( '<html>cached</html>', $this->proxy()->decide(), true );
	}

	/**
	 * @dataProvider provide_outcomes
	 *
	 * @param int    $status  Delivery API status.
	 * @param string $outcome Expected activity-log outcome.
	 * @return void
	 */
	public function test_outcomes_are_logged( $status, $outcome ) {
		$this->http->queue( 'page', $status );
		$this->proxy()->decide();

		$entries = $this->plugin->activity->entries();

		$this->assertNotEmpty( $entries, "No activity entry recorded for a {$status}." );
		$this->assertSame( $outcome, $entries[0]['outcome'] );
		$this->assertSame( 'GPTBot', $entries[0]['crawler'] );
		$this->assertSame( '/hello-world/', $entries[0]['path'] );
	}

	/**
	 * Status/outcome pairs.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public function provide_outcomes() {
		return array(
			'hit'          => array( 200, 'served' ),
			'miss'         => array( 404, 'passthrough' ),
			'server error' => array( 500, 'error' ),
		);
	}

	/**
	 * @return void
	 */
	public function test_stale_serving_is_logged_separately() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->http->queue_error( 'page' );

		$this->proxy()->decide();

		$this->assertSame( 'served-stale', $this->plugin->activity->entries()[0]['outcome'] );
	}

	/**
	 * A 401 is an install problem, not crawler activity — it belongs in the
	 * admin notice, not in the traffic log.
	 *
	 * @return void
	 */
	public function test_auth_failures_are_not_logged_as_traffic() {
		$this->http->queue( 'page', 401 );
		$this->proxy()->decide();

		$this->assertSame( array(), $this->plugin->activity->entries() );
	}

	/**
	 * The serving timeout stays short: a slow CiteCue must not hold a PHP
	 * worker open.
	 *
	 * @return void
	 */
	public function test_serve_timeout_defaults_to_three_seconds_and_is_filterable() {
		$this->http->queue( 'page', 200, '<html>a</html>' );
		$this->proxy()->decide();
		$this->assertSame( 3, $this->http->last( 'page' )['args']['timeout'] );

		add_filter( 'citecue_serve_timeout', static fn() => 1 );
		$this->fake_crawler_request( '/another/' );
		$this->proxy()->decide();
		$this->assertSame( 1, $this->http->last( 'page' )['args']['timeout'] );
	}
}
