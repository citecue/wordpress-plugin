<?php
/**
 * How the SEO head injector behaves on a page render and on its background
 * refresh: cache-only on the hot path, every empty answer from CiteCue
 * respected, and a degraded CiteCue never visible to a visitor.
 *
 * @package Citecue
 */

/**
 * SEO head delivery.
 */
class Test_Citecue_Seo_Head_Delivery extends Citecue_Test_Case {

	const BLOCK = '<meta data-citecue="og" property="og:title" content="Acme" />';

	/**
	 * A JSON body of the shape the delivery endpoint returns.
	 *
	 * @param string $block Head block.
	 * @return string
	 */
	private function payload( $block = self::BLOCK ) {
		return wp_json_encode( array( 'head' => $block ) );
	}

	/**
	 * A render never waits on CiteCue. A cold URL injects nothing and queues
	 * the fetch instead — the visitor pays no network round trip, and the next
	 * one gets the block.
	 *
	 * @return void
	 */
	public function test_cold_url_injects_nothing_and_schedules_a_refresh() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();

		$decision = $this->seo_head()->decide();

		$this->assertFalse( $decision['inject'] );
		$this->assertSame( 'no-cache', $decision['reason'] );
		$this->assertSame( 0, $this->http->count(), 'The render path must not call the API.' );
		$this->assertNotFalse( wp_next_scheduled( Citecue_Seo_Head::REFRESH_HOOK, array( $url ) ) );
	}

	/**
	 * A burst of visitors on a cold URL queues one job, not one per visitor.
	 *
	 * @return void
	 */
	public function test_repeated_views_schedule_one_refresh() {
		$this->configure_delivery();
		$this->fake_visitor_request();

		$injector = $this->seo_head();
		$injector->decide();
		$injector->decide();
		$injector->decide();

		$scheduled = _get_cron_array();
		$jobs      = 0;
		foreach ( (array) $scheduled as $events ) {
			if ( isset( $events[ Citecue_Seo_Head::REFRESH_HOOK ] ) ) {
				$jobs += count( $events[ Citecue_Seo_Head::REFRESH_HOOK ] );
			}
		}

		$this->assertSame( 1, $jobs );
	}

	/**
	 * The warm path: a fresh cached block is injected without touching the
	 * network or the cron queue.
	 *
	 * @return void
	 */
	public function test_fresh_cache_is_injected() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$decision = $this->seo_head()->decide();

		$this->assertTrue( $decision['inject'] );
		$this->assertSame( 'cached', $decision['reason'] );
		$this->assertSame( self::BLOCK, $decision['block'] );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * Stale-while-revalidate: a day-old block is still this page's own
	 * metadata. Withholding it during a CiteCue outage would blank the tags
	 * across the whole site rather than let them age.
	 *
	 * @return void
	 */
	public function test_stale_cache_is_served_while_the_refresh_is_queued() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );
		$this->age_cached_block( $url, Citecue_Seo_Head::FRESH_SECONDS + 60 );

		$decision = $this->seo_head()->decide();

		$this->assertTrue( $decision['inject'] );
		$this->assertSame( 'stale', $decision['reason'] );
		$this->assertNotFalse( wp_next_scheduled( Citecue_Seo_Head::REFRESH_HOOK, array( $url ) ) );
	}

	/**
	 * Once CiteCue has said it has nothing for a URL, the render path stops
	 * queueing work for it. Without this every human page view on an
	 * un-enriched site would schedule a job.
	 *
	 * @return void
	 */
	public function test_recent_miss_stops_scheduling() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head_miss( $url );

		$decision = $this->seo_head()->decide();

		$this->assertFalse( $decision['inject'] );
		$this->assertSame( 'recent-miss', $decision['reason'] );
		$this->assertFalse( wp_next_scheduled( Citecue_Seo_Head::REFRESH_HOOK, array( $url ) ) );
	}

	/**
	 * The setting is the switch, and it governs before anything else happens.
	 *
	 * @return void
	 */
	public function test_disabled_setting_injects_nothing() {
		$this->configure_delivery( array( 'seo_head_enabled' => false ) );
		$this->fake_visitor_request();

		$this->assertSame( 'not-configured', $this->seo_head()->decide()['reason'] );
	}

	/**
	 * A 404 and a search page have no CiteCue counterpart, and a canonical
	 * pointing anywhere from either would be actively wrong.
	 *
	 * @return void
	 */
	public function test_404_and_search_are_not_eligible() {
		$this->configure_delivery();

		// A post id nothing owns. The suite runs on plain permalinks, so a
		// pretty path would resolve to the blog index rather than a 404.
		$this->go_to( home_url( '/?p=999999' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertTrue( is_404() );
		$this->assertSame( 'not-eligible', $this->seo_head()->decide()['reason'] );

		$this->go_to( home_url( '/?s=widgets' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertTrue( is_search() );
		$this->assertSame( 'not-eligible', $this->seo_head()->decide()['reason'] );
	}

	/**
	 * The filter a site can veto individual pages with.
	 *
	 * @return void
	 */
	public function test_filter_can_veto_a_page() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		add_filter( 'citecue_should_inject_seo_head', '__return_false' );

		$this->assertSame( 'vetoed', $this->seo_head()->decide()['reason'] );
	}

	/**
	 * `/?x=<random>` still renders the homepage, so a raw request URL would let
	 * an anonymous visitor mint unlimited cache keys and cron arguments for one
	 * page. Unrecognized query arguments are stripped before any of that.
	 *
	 * @return void
	 */
	public function test_unknown_query_arguments_never_reach_the_lookup_url() {
		$this->configure_delivery();
		$this->fake_visitor_request( '/?x=abcdef&utm_source=news' );

		$url = Citecue_Seo_Head::lookup_url();

		$this->assertStringNotContainsString( 'x=abcdef', $url );
		$this->assertStringNotContainsString( 'utm_source', $url );
	}

	/**
	 * A dot or a space in the parameter name is the bypass this had to survive:
	 * parse_str() reports `x.1` as `x_1`, so a strip built on removing the
	 * names it reports would ask for a name the URL does not contain and leave
	 * the real one in place — unbounded cache keys again.
	 *
	 * @return void
	 */
	public function test_query_names_that_parse_str_would_rewrite_are_still_stripped() {
		$this->configure_delivery();
		$this->fake_visitor_request( '/?x.1=abcdef&y%20z=1' );

		$url = Citecue_Seo_Head::lookup_url();

		$this->assertStringNotContainsString( 'abcdef', $url );
		$this->assertStringNotContainsString( 'x.1', $url );
		$this->assertStringNotContainsString( 'y%20z', $url );
		$this->assertStringNotContainsString( '?', $url );
	}

	/**
	 * Stripping must not break a plain-permalink site, where the query string
	 * is how a page is addressed at all.
	 *
	 * @return void
	 */
	public function test_recognized_query_arguments_survive() {
		$this->configure_delivery();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->fake_visitor_request( '/?p=' . $post_id . '&sessiontoken=secret' );

		$url = Citecue_Seo_Head::lookup_url();

		$this->assertStringContainsString( 'p=' . $post_id, $url );
		$this->assertStringNotContainsString( 'sessiontoken', $url );
	}

	/**
	 * A burst of unique URLs cannot push unlimited events into WordPress's
	 * serialized cron option: the per-URL lock does not bound distinct URLs,
	 * and the outbound budget is only spent when a job runs.
	 *
	 * @return void
	 */
	public function test_scheduling_is_capped_per_minute() {
		$this->configure_delivery();
		add_filter( 'citecue_seo_head_schedule_budget', static fn() => 3 );

		// Real posts, each a distinct eligible URL: a nonexistent id is a 404,
		// which the injector declines before it ever reaches scheduling.
		$injector = $this->seo_head();
		foreach ( self::factory()->post->create_many( 8, array( 'post_status' => 'publish' ) ) as $post_id ) {
			$this->fake_visitor_request( '/?p=' . $post_id );
			$injector->decide();
		}

		$jobs = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ Citecue_Seo_Head::REFRESH_HOOK ] ) ) {
				$jobs += count( $events[ Citecue_Seo_Head::REFRESH_HOOK ] );
			}
		}

		$this->assertSame( 3, $jobs );
	}

	/**
	 * The background worker stores what CiteCue returns.
	 *
	 * @return void
	 */
	public function test_refresh_caches_the_block() {
		$this->configure_delivery();
		$url = home_url( '/hello-world/' );
		$this->http->queue( 'seo_head', 200, $this->payload() );

		$this->assertSame( 'fresh', $this->seo_head()->refresh( $url ) );

		$cached = $this->plugin->cache->get_seo_head( $url );
		$this->assertSame( self::BLOCK, $cached['block'] );
	}

	/**
	 * The request carries the project key, the URL and the Bearer key — the v2
	 * channel's contract.
	 *
	 * @return void
	 */
	public function test_refresh_sends_the_authenticated_v2_request() {
		$this->configure_delivery();
		$this->http->queue( 'seo_head', 200, $this->payload() );

		$this->seo_head()->refresh( home_url( '/hello-world/' ) );

		$request = $this->http->last( 'seo_head' );
		$this->assertStringContainsString( 'k=' . self::PUBLIC_KEY, $request['url'] );
		$this->assertStringContainsString( rawurlencode( home_url( '/hello-world/' ) ), $request['url'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $request['args']['headers']['Authorization'] );
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- the header value is literally lowercase.
		$this->assertSame( 'wordpress', $request['args']['headers']['X-Citecue-Channel'] );
	}

	/**
	 * 204 is "valid project, nothing to inject right now" — the audience is not
	 * `all`, or this URL has no enriched page. A block cached before the switch
	 * was flipped must not survive it.
	 *
	 * @return void
	 */
	public function test_204_evicts_a_previously_cached_block() {
		$this->configure_delivery();
		$url = home_url( '/hello-world/' );
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );
		$this->http->queue( 'seo_head', 204 );

		$this->assertSame( 'nothing-to-inject', $this->seo_head()->refresh( $url ) );

		$this->assertNull( $this->plugin->cache->get_seo_head( $url ) );
		$this->assertTrue( $this->plugin->cache->is_recent_seo_head_miss( $url ) );
	}

	/**
	 * 404 is the same "unknown key / disabled project" sentinel the page
	 * endpoint uses, and is handled the same way.
	 *
	 * @return void
	 */
	public function test_404_evicts_and_records_a_miss() {
		$this->configure_delivery();
		$url = home_url( '/hello-world/' );
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );
		$this->http->queue( 'seo_head', 404, 'not_optimized' );

		$this->assertSame( 'not-optimized', $this->seo_head()->refresh( $url ) );

		$this->assertNull( $this->plugin->cache->get_seo_head( $url ) );
	}

	/**
	 * A rejected key opens the long circuit and raises the admin notice, as
	 * everywhere else in the plugin.
	 *
	 * @return void
	 */
	public function test_401_trips_the_auth_circuit() {
		$this->configure_delivery();
		$this->http->queue( 'seo_head', 401, '{"error":"invalid_key"}' );

		$this->assertSame( 'unauthorized', $this->seo_head()->refresh( home_url( '/hello-world/' ) ) );

		$this->assertNotEmpty( get_option( 'citecue_auth_failed' ) );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * A timeout opens the circuit and keeps whatever we already had.
	 *
	 * @return void
	 */
	public function test_transport_error_keeps_the_cached_block() {
		$this->configure_delivery();
		$url = home_url( '/hello-world/' );
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );
		$this->http->queue_error( 'seo_head' );

		$this->assertSame( 'transport-error', $this->seo_head()->refresh( $url ) );

		$this->assertSame( self::BLOCK, $this->plugin->cache->get_seo_head( $url )['block'] );
		$this->assertTrue( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * The refresh honours the circuit and the shared lookup budget, so the two
	 * delivery paths cannot between them exceed the site's outbound ceiling.
	 *
	 * @return void
	 */
	public function test_refresh_respects_the_circuit_and_the_budget() {
		$this->configure_delivery();

		$this->plugin->cache->trip_circuit();
		$this->assertSame( 'circuit-open', $this->seo_head()->refresh( home_url( '/a/' ) ) );

		delete_transient( 'citecue_circuit' );
		$this->exhaust_lookup_budget();
		$this->assertSame( 'budget-exhausted', $this->seo_head()->refresh( home_url( '/a/' ) ) );

		$this->assertSame( 0, $this->http->count( 'seo_head' ) );
	}

	/**
	 * The full render: what the rest of wp_head printed comes back untouched,
	 * with only the gaps appended after it.
	 *
	 * @return void
	 */
	public function test_capture_appends_only_the_gaps() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head(
			$url,
			'<title data-citecue="title">CiteCue title</title>'
				. '<meta data-citecue="og" property="og:title" content="Acme" />'
		);

		$injector = $this->seo_head();

		ob_start();
		$injector->start_capture();
		echo '<title>The theme wrote this</title>';
		$injector->finish_capture();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<title>The theme wrote this</title>', $output );
		$this->assertStringNotContainsString( 'CiteCue title', $output );
		$this->assertStringContainsString( 'og:title', $output );
		$this->assertSame( 1, substr_count( $output, '<title' ) );
	}

	/**
	 * A theme that prints its own `<title>` in header.php does so before
	 * `wp_head` runs. The capture opens at `template_redirect` precisely so
	 * that markup is still seen — scoped to the action, this would append a
	 * second title.
	 *
	 * @return void
	 */
	public function test_theme_markup_printed_before_wp_head_still_claims_its_slot() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head(
			$url,
			'<title data-citecue="title">CiteCue title</title>'
				. '<meta data-citecue="og" property="og:title" content="Acme" />'
		);

		$injector = $this->seo_head();

		ob_start();
		$injector->start_capture();
		echo '<!DOCTYPE html><html><head><title>Printed by header.php</title>';
		$injector->finish_capture();
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, '<title' ) );
		$this->assertStringNotContainsString( 'CiteCue title', $output );
		$this->assertStringContainsString( 'og:title', $output );
	}

	/**
	 * Another plugin's buffer left open across the end of wp_head must be left
	 * exactly where it is. Unwinding to reach ours would close a buffer this
	 * class did not create, and its owner's later ob_get_clean() would then
	 * take an unrelated one.
	 *
	 * @return void
	 */
	public function test_a_foreign_buffer_is_never_unwound() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$injector = $this->seo_head();

		ob_start();
		$injector->start_capture();
		echo '<title>Theme</title>';

		// Somebody else opens one and does not close it before wp_head ends.
		ob_start();
		$foreign_level = ob_get_level();
		echo 'foreign';

		$injector->finish_capture();

		$this->assertSame( $foreign_level, ob_get_level(), 'finish_capture() must not close a buffer it did not open.' );
		$this->assertSame( 'foreign', ob_get_clean() );

		$output = ob_get_clean();
		$this->assertSame( '<title>Theme</title>', $output );
		$this->assertStringNotContainsString( 'og:title', $output );

		ob_end_clean();
	}

	/**
	 * Nothing to inject means nothing touched: no buffer, no marker comment,
	 * and every byte of the head exactly where the theme put it.
	 *
	 * @return void
	 */
	public function test_capture_is_a_no_op_without_a_block() {
		$this->configure_delivery();
		$this->fake_visitor_request();

		$injector = $this->seo_head();

		ob_start();
		$injector->start_capture();
		echo '<title>The theme wrote this</title>';
		$injector->finish_capture();
		$output = ob_get_clean();

		$this->assertSame( '<title>The theme wrote this</title>', $output );
	}

	/**
	 * Ages a cached block so the freshness window has passed.
	 *
	 * @param string $url     Absolute page URL.
	 * @param int    $seconds How far to backdate it.
	 * @return void
	 */
	private function age_cached_block( $url, $seconds ) {
		$key = new ReflectionMethod( 'Citecue_Cache', 'seo_head_key' );
		$key->setAccessible( true );

		$transient        = $key->invoke( $this->plugin->cache, $url );
		$hit              = get_transient( $transient );
		$hit['cached_at'] = time() - $seconds;
		set_transient( $transient, $hit, Citecue_Cache::BODY_TTL );
	}
}
