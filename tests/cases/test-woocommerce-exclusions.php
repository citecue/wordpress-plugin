<?php
/**
 * WooCommerce store pages the middleware must never intercept.
 *
 * Cart, checkout and account pages are session-bound and transactional;
 * serving a CiteCue-rendered copy of one would be a live-store bug. Product
 * and shop pages, by contrast, are exactly what should be served.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Proxy::decide
 */
class Test_Citecue_Woocommerce_Exclusions extends Citecue_Test_Case {

	/**
	 * Sets up a configured store.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->configure_delivery();
		$this->factory->post->create( array( 'post_name' => 'a-product' ) );
	}

	/**
	 * @dataProvider provide_store_pages
	 *
	 * @param string $page Stubbed WooCommerce page.
	 * @return void
	 */
	public function test_store_pages_are_never_intercepted( $page ) {
		$this->requires_stub();

		$this->fake_crawler_request( '/checkout/' );
		Citecue_Woocommerce_Stub::pretend( $page );

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * The WooCommerce pages that are off-limits.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_store_pages() {
		return array(
			'cart'        => array( 'cart' ),
			'checkout'    => array( 'checkout' ),
			'account'     => array( 'account' ),
			'WC endpoint' => array( 'endpoint' ),
		);
	}

	/**
	 * `?add-to-cart=` mutates the cart, so it is not a page view at all.
	 *
	 * @return void
	 */
	public function test_add_to_cart_links_are_skipped() {
		$this->requires_stub();

		$this->fake_crawler_request( '/a-product/?add-to-cart=42' );
		$_GET['add-to-cart'] = '42';

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );

		unset( $_GET['add-to-cart'] );
	}

	/**
	 * @return void
	 */
	public function test_wc_ajax_calls_are_skipped() {
		$this->requires_stub();

		$this->fake_crawler_request( '/?wc-ajax=get_refreshed_fragments' );
		$_GET['wc-ajax'] = 'get_refreshed_fragments';

		$this->assertPassedThrough( 'not-eligible', $this->proxy()->decide() );
		$this->assertSame( 0, $this->http->count() );

		unset( $_GET['wc-ajax'] );
	}

	/**
	 * Product pages are the highest-value AI-crawler target on a store — the
	 * exclusions must not swallow them.
	 *
	 * @return void
	 */
	public function test_product_pages_are_still_served() {
		$this->requires_stub();

		$this->fake_crawler_request( '/a-product/' );
		$this->http->queue( 'page', 200, '<html>optimized product</html>' );

		$this->assertServed( '<html>optimized product</html>', $this->proxy()->decide() );
	}

	/**
	 * Without WooCommerce the exclusions are inert — a store URL on a
	 * non-store site is just a page.
	 *
	 * @return void
	 */
	public function test_exclusions_do_nothing_without_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce (or its stub) is loaded.' );
		}

		$this->fake_crawler_request( '/cart/' );
		$this->http->queue( 'page', 200, '<html>just a page</html>' );

		$this->assertServed( '<html>just a page</html>', $this->proxy()->decide() );
	}

	/**
	 * These tests drive WooCommerce's conditional tags directly. With a real
	 * WooCommerce loaded that would mean building carts and sessions, which
	 * tests WooCommerce rather than this plugin — the dedicated WooCommerce CI
	 * job covers the real integration through the ingest tests instead.
	 *
	 * @return void
	 */
	private function requires_stub() {
		if ( ! Citecue_Woocommerce_Stub::is_active() ) {
			$this->markTestSkipped( 'Run with CITECUE_STUB_WOOCOMMERCE=1 (or without a real WooCommerce loaded).' );
		}
	}
}
