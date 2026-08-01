<?php
/**
 * The /llms.txt endpoint.
 *
 * Unlike the crawler middleware this serves every visitor, so its failure
 * modes are more visible: it must never emit an empty or error body, and it
 * must stop serving as soon as CiteCue says the file is no longer published.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Llms_Txt::decide
 */
class Test_Citecue_Llms_Txt extends Citecue_Test_Case {

	const BODY = "# Example\n\n> An example site.\n";

	/**
	 * Sets up a configured site requesting /llms.txt.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->configure_delivery();
		$this->request_llms_txt();
	}

	/**
	 * Points the current request at /llms.txt.
	 *
	 * @return void
	 */
	private function request_llms_txt() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/llms.txt';
	}

	/**
	 * @return void
	 */
	public function test_200_is_served_and_cached() {
		$this->http->queue( 'llms', 200, self::BODY, array( 'etag' => '"v1"' ) );

		$decision = $this->llms_txt()->decide();

		$this->assertTrue( $decision['serve'] );
		$this->assertSame( self::BODY, $decision['body'] );
		$this->assertSame( self::BODY, $this->plugin->cache->get_llms_txt()['body'] );
	}

	/**
	 * The file is served to everyone, not only to AI crawlers.
	 *
	 * @return void
	 */
	public function test_it_is_served_to_human_visitors_too() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Chrome/126.0.0.0 Safari/537.36';
		$this->http->queue( 'llms', 200, self::BODY );

		$this->assertTrue( $this->llms_txt()->decide()['serve'] );
	}

	/**
	 * @return void
	 */
	public function test_other_urls_are_left_alone() {
		$_SERVER['REQUEST_URI'] = '/hello-world/';

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_non_get_requests_are_left_alone() {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * @return void
	 */
	public function test_it_can_be_switched_off_in_settings() {
		$this->configure_delivery( array( 'llms_txt_enabled' => false ) );

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * A fresh cached copy is served without touching the API — this endpoint
	 * is hit by every visitor, so it must not be an outbound call each time.
	 *
	 * @return void
	 */
	public function test_a_fresh_cached_copy_skips_the_api() {
		$this->plugin->cache->set_llms_txt( self::BODY, '"v1"' );

		$decision = $this->llms_txt()->decide();

		$this->assertSame( self::BODY, $decision['body'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Past the freshness window it revalidates with the stored ETag.
	 *
	 * @return void
	 */
	public function test_a_stale_cached_copy_is_revalidated() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->http->queue( 'llms', 304 );

		$decision = $this->llms_txt()->decide();

		$this->assertSame( self::BODY, $decision['body'] );
		$this->assertSame( '"v1"', $this->http->last( 'llms' )['args']['headers']['If-None-Match'] );
	}

	/**
	 * @return void
	 */
	public function test_revalidation_refreshes_the_freshness_window() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->http->queue( 'llms', 304 );
		$this->llms_txt()->decide();

		// A second request inside the window must now be answered locally.
		$this->llms_txt()->decide();

		$this->assertSame( 1, $this->http->count( 'llms' ) );
	}

	/**
	 * @return void
	 */
	public function test_404_stops_serving_and_evicts_the_cached_copy() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->http->queue( 'llms', 404 );

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertNull( $this->plugin->cache->get_llms_txt() );
	}

	/**
	 * A project with llms.txt switched off must not turn every hit on the URL
	 * into an outbound call — this endpoint answers humans, not just crawlers.
	 *
	 * @return void
	 */
	public function test_404_is_negative_cached() {
		$this->http->queue( 'llms', 404 );
		$this->llms_txt()->decide();

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		}

		$this->assertSame( 1, $this->http->count( 'llms' ) );
	}

	/**
	 * @return void
	 */
	public function test_flushing_the_cache_retries_a_disabled_project() {
		$this->http->queue( 'llms', 404 );
		$this->http->queue( 'llms', 200, self::BODY );

		$this->llms_txt()->decide();
		$this->assertTrue( $this->plugin->cache->is_recent_llms_txt_miss() );

		$this->plugin->cache->flush();

		$this->assertSame( self::BODY, $this->llms_txt()->decide()['body'] );
	}

	/**
	 * @return void
	 */
	public function test_lookups_are_capped_by_the_budget() {
		$this->exhaust_lookup_budget();

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Degrading on an exhausted budget must not cost visitors a body the
	 * plugin already holds.
	 *
	 * @return void
	 */
	public function test_an_exhausted_budget_still_serves_a_stale_copy() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->exhaust_lookup_budget();

		$this->assertSame( self::BODY, $this->llms_txt()->decide()['body'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * The budget is one ceiling for the whole site, so traffic on one path
	 * cannot be used to get around the limit on the other.
	 *
	 * @return void
	 */
	public function test_the_budget_is_shared_with_the_crawler_path() {
		add_filter( 'citecue_lookup_budget', static fn() => 1 );

		$this->http->queue( 'llms', 404 );
		$this->llms_txt()->decide();

		$this->set_permalink_structure( '/%postname%/' );
		$this->fake_crawler_request( '/hello-world/' );

		$this->assertPassedThrough( 'budget-exhausted', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count( 'page' ) );
	}

	/**
	 * A served llms.txt spends budget too, so a flood on this URL is bounded
	 * even when CiteCue is answering normally.
	 *
	 * @return void
	 */
	public function test_serving_consumes_budget() {
		add_filter( 'citecue_lookup_budget', static fn() => 2 );
		$this->http->queue( 'llms', 200, self::BODY );

		// Each pass revalidates, because the cache is aged out every time.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->stale_cache( self::BODY, '"v1"' );
			$this->llms_txt()->decide();
		}

		$this->assertSame( 2, $this->http->count( 'llms' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_timeout_serves_the_cached_copy() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->http->queue_error( 'llms' );

		$this->assertSame( self::BODY, $this->llms_txt()->decide()['body'] );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * With nothing cached there is no body to fall back to, so WordPress must
	 * answer — serving an empty llms.txt would look like a published, empty
	 * file.
	 *
	 * @return void
	 */
	public function test_a_timeout_without_a_cached_copy_passes_through() {
		$this->http->queue_error( 'llms' );

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
	}

	/**
	 * @return void
	 */
	public function test_a_500_body_is_never_served() {
		$this->http->queue( 'llms', 500, 'Internal Server Error' );

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
	}

	/**
	 * @return void
	 */
	public function test_401_records_the_auth_failure_and_backs_off() {
		$this->http->queue( 'llms', 401 );

		$this->assertFalse( $this->llms_txt()->decide()['serve'] );
		$this->assertNotFalse( get_option( 'citecue_auth_failed' ) );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * @return void
	 */
	public function test_an_open_circuit_serves_the_cached_copy_without_calling_the_api() {
		$this->stale_cache( self::BODY, '"v1"' );
		$this->plugin->cache->trip_circuit();

		$this->assertSame( self::BODY, $this->llms_txt()->decide()['body'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Caches an llms.txt body that is already past its freshness window.
	 *
	 * @param string $body llms.txt content.
	 * @param string $etag Cached ETag.
	 * @return void
	 */
	private function stale_cache( $body, $etag ) {
		// Reading through the cache first forces the lazily generated key salt
		// into existence, so the transient below lands on the key the plugin
		// will look under.
		$this->plugin->cache->get_llms_txt();

		set_transient(
			'citecue_llms_' . get_option( 'citecue_cache_salt' ),
			array(
				'body'      => $body,
				'etag'      => $etag,
				'cached_at' => time() - ( Citecue_Llms_Txt::FRESH_SECONDS + 1 ),
			),
			DAY_IN_SECONDS
		);
	}
}
