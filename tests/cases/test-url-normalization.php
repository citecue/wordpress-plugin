<?php
/**
 * Cache-key URL normalization.
 *
 * These rules mirror CiteCue's server-side normalizePageUrl(); if they drift,
 * the plugin caches under a different key than the API answers for and every
 * crawler request becomes a cache miss.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Cache::normalize_url
 */
class Test_Citecue_Url_Normalization extends WP_UnitTestCase {

	/**
	 * @dataProvider provide_urls
	 *
	 * @param string $input    Raw URL.
	 * @param string $expected Normalized key.
	 * @return void
	 */
	public function test_normalize_url( $input, $expected ) {
		$this->assertSame( $expected, Citecue_Cache::normalize_url( $input ) );
	}

	/**
	 * URL/expected-key pairs.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_urls() {
		return array(
			'scheme is dropped'            => array( 'https://example.org/about', 'example.org/about' ),
			'http and https agree'         => array( 'http://example.org/about', 'example.org/about' ),
			'www is dropped'               => array( 'https://www.example.org/about', 'example.org/about' ),
			'host is lowercased'           => array( 'https://EXAMPLE.org/about', 'example.org/about' ),
			'trailing slash is dropped'    => array( 'https://example.org/about/', 'example.org/about' ),
			'bare root has no path'        => array( 'https://example.org/', 'example.org' ),
			'root without slash'           => array( 'https://example.org', 'example.org' ),
			'fragment is dropped'          => array( 'https://example.org/about#team', 'example.org/about' ),
			'utm params are dropped'       => array( 'https://example.org/about?utm_source=chatgpt', 'example.org/about' ),
			'all trackers dropped'         => array( 'https://example.org/a?utm_medium=x&ref=y&fbclid=z&gclid=w', 'example.org/a' ),
			'real params are kept'         => array( 'https://example.org/shop?page=2', 'example.org/shop?page=2' ),
			'trackers stripped, rest kept' => array( 'https://example.org/shop?page=2&utm_source=x', 'example.org/shop?page=2' ),
			'unparseable input passes'     => array( 'not a url', 'not a url' ),
			'empty input passes'           => array( '', '' ),
		);
	}

	/**
	 * A spoofed crawler cannot mint unlimited cache keys by varying tracking
	 * parameters — that is what bounds the lookup budget's usefulness.
	 *
	 * @return void
	 */
	public function test_tracking_noise_collapses_to_one_key() {
		$keys = array_map(
			array( 'Citecue_Cache', 'normalize_url' ),
			array(
				'https://example.org/post/',
				'https://www.example.org/post',
				'http://EXAMPLE.ORG/post/?utm_source=a',
				'https://example.org/post?fbclid=123#top',
			)
		);

		$this->assertCount( 1, array_unique( $keys ) );
	}

	/**
	 * Different pages must not collide.
	 *
	 * @return void
	 */
	public function test_distinct_pages_get_distinct_keys() {
		$this->assertNotSame(
			Citecue_Cache::normalize_url( 'https://example.org/a' ),
			Citecue_Cache::normalize_url( 'https://example.org/b' )
		);
	}
}
