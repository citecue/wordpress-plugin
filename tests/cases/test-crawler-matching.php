<?php
/**
 * AI-crawler detection and the registry refresh.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Crawlers
 */
class Test_Citecue_Crawler_Matching extends Citecue_Test_Case {

	/**
	 * Crawler registry under test.
	 *
	 * @var Citecue_Crawlers
	 */
	private $crawlers;

	/**
	 * Sets up a registry.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->crawlers = new Citecue_Crawlers();
	}

	/**
	 * @dataProvider provide_crawler_agents
	 *
	 * @param string $user_agent User agent header.
	 * @param string $expected   Expected matched token.
	 * @return void
	 */
	public function test_matches_known_crawlers( $user_agent, $expected ) {
		$this->assertSame( $expected, $this->crawlers->match( $user_agent ) );
	}

	/**
	 * Real-world user agents for the crawlers the plugin serves.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_crawler_agents() {
		return array(
			'GPTBot'           => array( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot', 'GPTBot' ),
			'ChatGPT-User'     => array( 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)', 'ChatGPT-User' ),
			'OAI-SearchBot'    => array( 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 'OAI-SearchBot' ),
			'ClaudeBot'        => array( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot' ),
			'Claude-User'      => array( 'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'Claude-User' ),
			'PerplexityBot'    => array( 'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'PerplexityBot' ),
			'CCBot'            => array( 'CCBot/2.0 (https://commoncrawl.org/faq/)', 'CCBot' ),
			'Amazonbot'        => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Amazonbot/0.1', 'Amazonbot' ),
			'case insensitive' => array( 'mozilla/5.0 (compatible; gptbot/1.2)', 'GPTBot' ),
		);
	}

	/**
	 * @dataProvider provide_non_crawler_agents
	 *
	 * @param string $user_agent User agent header.
	 * @return void
	 */
	public function test_ignores_everything_else( $user_agent ) {
		$this->assertNull( $this->crawlers->match( $user_agent ) );
	}

	/**
	 * User agents that must never be served optimized content.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_non_crawler_agents() {
		return array(
			'Chrome'        => array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' ),
			'Safari on iOS' => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1' ),
			'Googlebot'     => array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ),
			'Bingbot'       => array( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ),
			'empty'         => array( '' ),
		);
	}

	/**
	 * Google-Extended and Applebot-Extended are robots.txt directives, not
	 * fetchers — matching them would serve optimized content to nothing.
	 *
	 * @return void
	 */
	public function test_robots_txt_only_tokens_are_not_served() {
		$this->assertNotContains( 'Google-Extended', Citecue_Crawlers::bundled_tokens() );
		$this->assertNotContains( 'Applebot-Extended', Citecue_Crawlers::bundled_tokens() );
	}

	/**
	 * When two tokens both appear in a UA the longer one must win, otherwise
	 * CiteCue is told the wrong bot fetched the page.
	 *
	 * @return void
	 */
	public function test_longest_token_wins() {
		add_filter(
			'citecue_crawler_tokens',
			static function () {
				return array( 'Claude', 'Claude-SearchBot' );
			}
		);

		$this->assertSame(
			'Claude-SearchBot',
			$this->crawlers->match( 'Mozilla/5.0 (compatible; Claude-SearchBot/1.0)' )
		);
	}

	/**
	 * @return void
	 */
	public function test_tokens_can_be_filtered() {
		add_filter(
			'citecue_crawler_tokens',
			static function ( $tokens ) {
				$tokens[] = 'MyCustomAgent';
				return $tokens;
			}
		);

		$this->assertSame( 'MyCustomAgent', $this->crawlers->match( 'MyCustomAgent/1.0' ) );
	}

	/**
	 * @return void
	 */
	public function test_match_can_be_overridden_per_request() {
		add_filter( 'citecue_matched_crawler', '__return_null' );

		$this->assertNull( $this->crawlers->match( 'GPTBot/1.2' ) );
	}

	/**
	 * A newer registry replaces the bundled list.
	 *
	 * @return void
	 */
	public function test_refresh_stores_a_newer_registry() {
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 2,
					'tokens'  => array( 'BrandNewBot' ),
				)
			)
		);

		$this->assertTrue( $this->crawlers->refresh( $this->plugin->api ) );
		$this->assertContains( 'BrandNewBot', $this->crawlers->get_tokens() );
		$this->assertSame( 2, $this->crawlers->registry_info()['version'] );
	}

	/**
	 * A truncated or downgraded feed must never be able to stop the plugin
	 * serving a crawler it already knows about.
	 *
	 * @return void
	 */
	public function test_refresh_keeps_the_bundled_tokens_as_a_floor() {
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 2,
					'tokens'  => array( 'BrandNewBot' ),
				)
			)
		);
		$this->crawlers->refresh( $this->plugin->api );

		foreach ( Citecue_Crawlers::bundled_tokens() as $token ) {
			$this->assertContains( $token, $this->crawlers->get_tokens(), "Bundled token {$token} was dropped by a refresh." );
		}
		$this->assertSame( 'GPTBot', $this->crawlers->match( 'GPTBot/1.2' ) );
	}

	/**
	 * @return void
	 */
	public function test_refresh_rejects_an_older_registry_version() {
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 0,
					'tokens'  => array( 'StaleBot' ),
				)
			)
		);

		$this->assertFalse( $this->crawlers->refresh( $this->plugin->api ) );
		$this->assertNotContains( 'StaleBot', $this->crawlers->get_tokens() );
	}

	/**
	 * @return void
	 */
	public function test_refresh_dedupes_case_insensitively() {
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 2,
					'tokens'  => array( 'gptbot', 'GPTBot' ),
				)
			)
		);
		$this->crawlers->refresh( $this->plugin->api );

		$lowercased = array_map( 'strtolower', $this->crawlers->get_tokens() );
		$this->assertSame( count( $lowercased ), count( array_unique( $lowercased ) ) );
	}

	/**
	 * A failing feed leaves the current list untouched.
	 *
	 * @return void
	 */
	public function test_refresh_survives_a_transport_error() {
		$this->http->queue_error( 'crawlers' );

		$this->assertFalse( $this->crawlers->refresh( $this->plugin->api ) );
		$this->assertSame( 'GPTBot', $this->crawlers->match( 'GPTBot/1.2' ) );
	}

	/**
	 * @return void
	 */
	public function test_refresh_rejects_a_junk_payload() {
		$this->http->queue( 'crawlers', 200, 'not json' );

		$this->assertFalse( $this->crawlers->refresh( $this->plugin->api ) );
	}

	/**
	 * The registry feed is public; sending the API key to it would leak the
	 * credential to an endpoint that does not need it.
	 *
	 * @return void
	 */
	public function test_registry_feed_is_requested_without_credentials() {
		$this->configure_delivery();
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 2,
					'tokens'  => array( 'BrandNewBot' ),
				)
			)
		);
		$this->crawlers->refresh( $this->plugin->api );

		$request = $this->http->last( 'crawlers' );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
	}
}
