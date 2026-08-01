<?php
/**
 * The two mechanisms that keep the plugin from becoming a liability: the
 * circuit breaker (a CiteCue outage must not slow the site) and the outbound
 * lookup budget (a spoofed crawler user agent must not be able to turn every
 * page view into an outbound API call).
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Proxy::decide
 * @covers Citecue_Cache
 */
class Test_Citecue_Proxy_Resilience extends Citecue_Test_Case {

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
	 * With the circuit open the API is off-limits entirely — that is the whole
	 * point of opening it.
	 *
	 * @return void
	 */
	public function test_an_open_circuit_makes_no_api_calls() {
		$this->plugin->cache->trip_circuit();

		$this->assertPassedThrough( 'circuit-open', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_an_open_circuit_still_serves_cached_content() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->plugin->cache->trip_circuit();

		$this->assertServed( '<html>cached</html>', $this->proxy()->decide(), true );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * One timeout must not cost more than one slow request: the next crawler
	 * hit is answered locally.
	 *
	 * @return void
	 */
	public function test_one_timeout_protects_subsequent_requests() {
		$this->http->queue_error( 'page' );
		$this->proxy()->decide();

		$this->fake_crawler_request( '/second-page/' );
		$this->assertPassedThrough( 'circuit-open', $this->proxy()->decide() );
		$this->assertSame( 1, $this->http->count( 'page' ) );
	}

	/**
	 * A rejected key backs off for far longer than a transient error, since
	 * retrying it cannot help.
	 *
	 * @return void
	 */
	public function test_auth_failures_back_off_longer_than_transient_errors() {
		$this->assertGreaterThan( Citecue_Cache::CIRCUIT_TTL, Citecue_Cache::AUTH_CIRCUIT_TTL );
	}

	/**
	 * @return void
	 */
	public function test_the_circuit_closes_when_it_expires() {
		$this->plugin->cache->trip_circuit();
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );

		delete_transient( 'citecue_circuit' );

		$this->http->queue( 'page', 200, '<html>back</html>' );
		$this->assertServed( '<html>back</html>', $this->proxy()->decide() );
	}

	/**
	 * @return void
	 */
	public function test_flushing_the_cache_closes_the_circuit_and_drops_bodies() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->plugin->cache->trip_circuit();

		$this->plugin->cache->flush();

		$this->assertFalse( $this->plugin->cache->is_circuit_open() );
		$this->assertNull( $this->plugin->cache->get_page( $this->url ) );
	}

	/**
	 * @return void
	 */
	public function test_an_exhausted_budget_stops_api_calls() {
		$this->exhaust_lookup_budget();

		$this->assertPassedThrough( 'budget-exhausted', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Degrading on budget exhaustion must not degrade content the plugin
	 * already holds.
	 *
	 * @return void
	 */
	public function test_an_exhausted_budget_still_serves_cached_content() {
		$this->prime_page_cache( $this->url, '<html>cached</html>' );
		$this->exhaust_lookup_budget();

		$this->assertServed( '<html>cached</html>', $this->proxy()->decide(), true );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * The budget caps total outbound calls per minute regardless of how many
	 * distinct URLs a spoofed user agent asks for.
	 *
	 * @return void
	 */
	public function test_a_spoofed_crawler_cannot_spray_unbounded_lookups() {
		add_filter( 'citecue_lookup_budget', static fn() => 3 );
		$this->http->queue( 'page', 404 );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->fake_crawler_request( "/spray-{$i}/" );
			$this->proxy()->decide();
		}

		$this->assertSame( 3, $this->http->count( 'page' ) );
	}

	/**
	 * Budget spent on a URL is not spent again when the negative cache can
	 * answer — the two mechanisms compose.
	 *
	 * @return void
	 */
	public function test_the_negative_cache_conserves_budget() {
		add_filter( 'citecue_lookup_budget', static fn() => 3 );
		$this->http->queue( 'page', 404 );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->fake_crawler_request( '/same-page/' );
			$this->proxy()->decide();
		}

		$this->assertSame( 1, $this->http->count( 'page' ) );
	}

	/**
	 * Tracking-parameter noise on the same page shares one cache entry, so it
	 * cannot be used to walk around the negative cache.
	 *
	 * @return void
	 */
	public function test_tracking_parameters_do_not_multiply_lookups() {
		$this->http->queue( 'page', 404 );

		foreach ( array( '?utm_source=a', '?utm_source=b', '?fbclid=c', '' ) as $suffix ) {
			$this->fake_crawler_request( '/tracked/' . $suffix );
			$this->proxy()->decide();
		}

		$this->assertSame( 1, $this->http->count( 'page' ) );
	}

	/**
	 * @return void
	 */
	public function test_budget_is_filterable() {
		add_filter( 'citecue_lookup_budget', static fn() => 1 );
		$this->http->queue( 'page', 404 );

		$this->fake_crawler_request( '/first/' );
		$this->proxy()->decide();
		$this->fake_crawler_request( '/second/' );

		$this->assertPassedThrough( 'budget-exhausted', $this->proxy()->decide() );
	}
}
