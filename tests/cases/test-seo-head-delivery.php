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
	 * The budget hands out exactly its limit and then refuses. Worth pinning
	 * directly: both per-minute ceilings share this counter, and a rate limit
	 * that answers "allowed" one time too many is a rate limit that can answer
	 * it every time.
	 *
	 * @return void
	 */
	public function test_the_schedule_budget_stops_at_its_limit() {
		add_filter( 'citecue_seo_head_schedule_budget', static fn() => 3 );

		$granted = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			if ( $this->plugin->cache->consume_seo_head_schedule_budget() ) {
				++$granted;
			}
		}

		$this->assertSame( 3, $granted );
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
		// The half this test was named for but did not check: without the miss,
		// every subsequent page view would queue the fetch again.
		$this->assertTrue( $this->plugin->cache->is_recent_seo_head_miss( $url ) );
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
	 * The full render: everything the theme and every other plugin printed
	 * comes back untouched, with only the gaps added before `</head>`.
	 *
	 * @return void
	 */
	public function test_the_render_adds_only_the_gaps() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head(
			$url,
			'<title data-citecue="title">CiteCue title</title>'
				. '<meta data-citecue="og" property="og:title" content="Acme" />'
		);

		$output = $this->render(
			$this->seo_head(),
			$this->document( '<title>The theme wrote this</title>' )
		);

		$this->assertStringContainsString( '<title>The theme wrote this</title>', $output );
		$this->assertStringNotContainsString( 'CiteCue title', $output );
		$this->assertStringContainsString( 'og:title', $output );
		$this->assertSame( 1, substr_count( $output, '<title' ) );
		$this->assertStringContainsString( '<!-- CiteCue -->', $output );
	}

	/**
	 * A theme that prints its own `<title>` in header.php does so before
	 * `wp_head` runs. The capture spans the whole response precisely so that
	 * markup is still seen — scoped to the action, this would add a second
	 * title.
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

		$output = $this->render(
			$this->seo_head(),
			$this->document( '<title>Printed by header.php</title>' )
		);

		$this->assertSame( 1, substr_count( $output, '<title' ) );
		$this->assertStringNotContainsString( 'CiteCue title', $output );
		$this->assertStringContainsString( 'og:title', $output );
	}

	/**
	 * The tags go inside the head, before its closing tag, rather than wherever
	 * the render happened to stop.
	 *
	 * @return void
	 */
	public function test_the_tags_land_inside_the_head() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$output = $this->render( $this->seo_head(), $this->document() );

		$this->assertLessThan(
			strpos( $output, '</head>' ),
			strpos( $output, 'og:title' ),
			'The block belongs before the head closes.'
		);
	}

	/**
	 * Markup in the body never claims a head slot. An inline SVG carries a
	 * `<title>` and a page that quotes markup contains whatever it quotes, so a
	 * document-wide scan would read slots as occupied that no crawler reads as
	 * page metadata — and CiteCue would quietly stop filling them.
	 *
	 * @return void
	 */
	public function test_body_markup_never_claims_a_head_slot() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, '<title data-citecue="title">CiteCue title</title>' );

		$output = $this->render(
			$this->seo_head(),
			$this->document( '', '<svg><title>An icon</title></svg>' )
		);

		$this->assertStringContainsString( 'CiteCue title', $output );
	}

	/**
	 * A response with no head is handed back byte for byte: a JSON or CSV
	 * export served from a page URL, a fragment, a document another plugin
	 * replaced wholesale. There is nothing to fill a gap in, and guessing where
	 * a head would have gone is how a plugin corrupts a response.
	 *
	 * @return void
	 */
	public function test_a_response_without_a_head_is_untouched() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$csv = "sku,price\nCC-1,9.99\n";

		$this->assertSame( $csv, $this->render( $this->seo_head(), $csv ) );
	}

	/**
	 * Nothing to inject means nothing arranged: no buffer of the plugin's own,
	 * no filter registered for core's, and every byte where the theme put it.
	 *
	 * @return void
	 */
	public function test_nothing_is_arranged_without_a_block() {
		$this->configure_delivery();
		$this->fake_visitor_request();

		$injector = $this->seo_head();
		$level    = ob_get_level();

		$injector->start_capture();

		$this->assertSame( $level, ob_get_level(), 'A page with nothing to inject must not be buffered.' );
		$this->assertFalse( has_filter( 'wp_template_enhancement_output_buffer' ) );
		$this->assertSame( $this->document(), $injector->enhance( $this->document() ) );
	}

	/**
	 * The buffer discipline the WordPress.org review asked for, stated as the
	 * property that matters: a render leaves the buffer stack exactly as it
	 * found it, even though `wp_head` never runs here — which is the case the
	 * old `ob_start()`/`ob_get_clean()` pairing across two actions could not
	 * survive. Every render in this file goes through the same helper and
	 * asserts the same thing.
	 *
	 * @return void
	 */
	public function test_a_render_that_never_reaches_wp_head_leaves_no_buffer_behind() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$level = ob_get_level();

		$this->assertStringContainsString( 'og:title', $this->render( $this->seo_head(), $this->document() ) );
		$this->assertSame( $level, ob_get_level() );
		$this->assertFalse( has_action( 'wp_head', array( $this->seo_head(), 'finish_capture' ) ) );
	}

	/**
	 * Which mechanism runs is decided by what WordPress provides, and the two
	 * are exclusive: from 6.9 the plugin opens nothing at all and asks core for
	 * the finished document; below it the plugin opens the buffer, and opens it
	 * with a callback — the form PHP finalizes on its own — so there is no
	 * closing call to be bypassed and nothing left open if one is.
	 *
	 * Both halves are asserted, and CI runs both: the matrix pins WordPress
	 * 5.9 and 6.5 alongside the current release.
	 *
	 * @return void
	 */
	public function test_the_capture_uses_core_s_buffer_where_there_is_one() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$injector = $this->seo_head();
		$level    = ob_get_level();

		$injector->start_capture();

		$opened = ob_get_level() - $level;
		$status = ob_get_status( true );
		$mine   = $opened ? end( $status ) : array();

		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}

		if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
			$this->assertSame( 0, $opened, 'From WordPress 6.9 core owns the buffer.' );
			$this->assertNotFalse( has_filter( 'wp_template_enhancement_output_buffer', array( $injector, 'enhance' ) ) );
			return;
		}

		$this->assertSame( 1, $opened, 'Below WordPress 6.9 the plugin opens its own.' );
		$this->assertFalse( has_filter( 'wp_template_enhancement_output_buffer' ) );
		$this->assertSame( 'Citecue_Seo_Head::finish_capture', $mine['name'], 'The buffer must be the self-finalizing callback form.' );
		$this->assertSame( 0, $mine['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE, 'A flushable buffer would hand the callback a fragment.' );
	}

	/**
	 * A buffer somebody else opens inside the head and never closes is neither
	 * taken nor unwound: PHP ends it first and its bytes land in ours. The old
	 * pairing had to detect this case and give up on the tags; there is nothing
	 * to detect now.
	 *
	 * Below WordPress 6.9 this exercises the plugin's own callback buffer; from
	 * 6.9 core owns the buffer and the same nesting applies to it.
	 *
	 * @return void
	 */
	public function test_a_foreign_buffer_left_open_is_neither_taken_nor_lost() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$injector = $this->seo_head();
		$level    = ob_get_level();

		ob_start();
		$injector->start_capture();

		echo '<!DOCTYPE html><html><head><title>Theme</title>';
		ob_start(); // Somebody else's minifier, still open when the response ends.
		echo '<meta name="generator" content="a minifier" />';
		echo '</head><body>hi</body></html>';

		// The end of the request: PHP flushes every open buffer, innermost
		// first, which is what invokes the plugin's callback.
		while ( ob_get_level() > $level + 1 ) {
			ob_end_flush();
		}
		$output = (string) ob_get_clean();

		$this->assertSame( $level, ob_get_level(), 'The plugin must not take, or leave, a buffer.' );
		$this->assertStringContainsString( 'a minifier', $output, 'The foreign buffer\'s bytes must survive.' );
		$this->assertStringContainsString( '<title>Theme</title>', $output );
	}

	/**
	 * A buffer ended by a clean rather than a flush is handed back untouched:
	 * PHP discards what the callback returns in that phase, so there is nothing
	 * to gain by enriching it. The decision survives for the response that
	 * replaces it.
	 *
	 * @return void
	 */
	public function test_a_discarded_response_is_not_enhanced() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$injector = $this->seo_head();
		$this->arrange_capture( $injector );

		$document = $this->document();

		$this->assertSame( $document, $injector->finish_capture( $document, PHP_OUTPUT_HANDLER_CLEAN ) );
		$this->assertStringContainsString( 'og:title', $injector->enhance( $document ) );
	}

	/**
	 * One capture, one injection. Whatever calls the injection twice — a filter
	 * applied again, a buffer finalized in pieces — must not append the block a
	 * second time.
	 *
	 * @return void
	 */
	public function test_the_block_is_injected_once() {
		$this->configure_delivery();
		$url = $this->fake_visitor_request();
		$this->plugin->cache->set_seo_head( $url, self::BLOCK );

		$injector = $this->seo_head();
		$this->arrange_capture( $injector );

		$this->assertStringContainsString( 'og:title', $injector->enhance( $this->document() ) );
		$this->assertSame( $this->document(), $injector->enhance( $this->document() ) );
	}

	/**
	 * A rendered page, as the injection receives it.
	 *
	 * @param string $head Extra head markup.
	 * @param string $body Body markup.
	 * @return string
	 */
	private function document( $head = '', $body = '<p>Hello</p>' ) {
		return '<!DOCTYPE html><html><head><meta charset="utf-8" />' . $head . '</head><body>' . $body . '</body></html>';
	}

	/**
	 * One page render, end to end, through whichever mechanism this WordPress
	 * provides: core's template enhancement buffer from 6.9, the plugin's own
	 * callback buffer below it. Returns what the visitor receives, and fails
	 * the test unless the render left the output buffer stack as it found it.
	 *
	 * @param Citecue_Seo_Head $injector Injector under test.
	 * @param string           $document What the theme renders.
	 * @return string
	 */
	private function render( Citecue_Seo_Head $injector, $document ) {
		$level = ob_get_level();

		ob_start(); // Stands in for everything below the plugin on the stack.
		$injector->start_capture();

		if ( ob_get_level() === $level + 1 ) {
			// The plugin opened nothing: either there is nothing to inject, or
			// core owns the buffer and applies the filter to the response.
			$delivered = has_filter( 'wp_template_enhancement_output_buffer' )
				? (string) apply_filters( 'wp_template_enhancement_output_buffer', $document, $document )
				: $document;
			ob_end_clean();
		} else {
			echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// The end of the request, where PHP flushes what is still open.
			while ( ob_get_level() > $level + 1 ) {
				ob_end_flush();
			}
			$delivered = (string) ob_get_clean();
		}

		$this->assertSame( $level, ob_get_level(), 'A render must leave the buffer stack as it found it.' );

		return $delivered;
	}

	/**
	 * Runs the arrangement `template_redirect` runs and takes back whatever
	 * buffer it opened, so a test can call the injection directly without one
	 * outliving it. Ending the buffer this way discards it, which the plugin's
	 * callback treats as a response being thrown away — so the decision it is
	 * holding survives for the test to act on.
	 *
	 * @param Citecue_Seo_Head $injector Injector under test.
	 * @return void
	 */
	private function arrange_capture( Citecue_Seo_Head $injector ) {
		$level = ob_get_level();

		$injector->start_capture();

		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
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
