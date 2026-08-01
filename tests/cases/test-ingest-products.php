<?php
/**
 * Product pushes, which go through WooCommerce's CRUD rather than
 * wp_insert_post().
 *
 * These need a real WooCommerce — the stub used by the store-exclusion tests
 * deliberately does not fake WC_Product. They run in the WooCommerce CI job
 * (CITECUE_WITH_WOOCOMMERCE=1) and skip everywhere else.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Ingest::handle_content
 */
class Test_Citecue_Ingest_Products extends Citecue_Test_Case {

	const SECRET = 'cws_testsecret0123456789';

	/**
	 * Boots a REST server with ingest enabled, or skips.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'Requires a real WooCommerce (CITECUE_WITH_WOOCOMMERCE=1).' );
		}

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->plugin->settings->update(
			array(
				'ingest_enabled' => true,
				'ingest_secret'  => self::SECRET,
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_a_product_push_creates_a_draft_simple_product() {
		$response = $this->push(
			array(
				'type'          => 'product',
				'title'         => 'Acme Widget',
				'content'       => '<p>A very good widget.</p>',
				'excerpt'       => 'A good widget.',
				'sku'           => 'ACME-1',
				'regular_price' => '19.99',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$product = wc_get_product( $response->get_data()['post_id'] );

		$this->assertInstanceOf( 'WC_Product_Simple', $product );
		$this->assertSame( 'Acme Widget', $product->get_name() );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( 'ACME-1', $product->get_sku() );
		$this->assertSame( '19.99', $product->get_regular_price() );
		$this->assertStringContainsString( 'very good widget', $product->get_description() );
		$this->assertSame( 'A good widget.', $product->get_short_description() );
	}

	/**
	 * @return void
	 */
	public function test_product_terms_use_the_product_taxonomies() {
		$response = $this->push(
			array(
				'type'       => 'product',
				'categories' => array( 'Widgets' ),
				'tags'       => array( 'metal' ),
			)
		);
		$id       = $response->get_data()['post_id'];

		$this->assertSame( array( 'Widgets' ), wp_list_pluck( get_the_terms( $id, 'product_cat' ), 'name' ) );
		$this->assertSame( array( 'metal' ), wp_list_pluck( get_the_terms( $id, 'product_tag' ), 'name' ) );
	}

	/**
	 * The status cap applies to products exactly as it does to posts.
	 *
	 * @return void
	 */
	public function test_products_respect_the_status_cap() {
		$response = $this->push(
			array(
				'type'   => 'product',
				'status' => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $response->get_data()['post_id'] ) );
	}

	/**
	 * Adopting a product the plugin did not create replaces its description,
	 * so it must never happen by accident.
	 *
	 * @return void
	 */
	public function test_an_existing_sku_is_not_adopted_without_force() {
		$existing = new WC_Product_Simple();
		$existing->set_name( 'Hand-written Widget' );
		$existing->set_description( '<p>Written by the store owner.</p>' );
		$existing->set_sku( 'ACME-1' );
		$existing->save();

		$response = $this->push(
			array(
				'type' => 'product',
				'sku'  => 'ACME-1',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'citecue_sku_exists', $response->get_data()['code'] );
		$this->assertStringContainsString(
			'store owner',
			wc_get_product( $existing->get_id() )->get_description()
		);
	}

	/**
	 * With force, the adoption goes through and the product becomes a normal
	 * push target from then on.
	 *
	 * @return void
	 */
	public function test_force_adopts_an_existing_product_by_sku() {
		$existing = new WC_Product_Simple();
		$existing->set_name( 'Hand-written Widget' );
		$existing->set_sku( 'ACME-1' );
		$existing->save();

		$adopted = $this->push(
			array(
				'type'    => 'product',
				'sku'     => 'ACME-1',
				'content' => '<p>Optimized copy.</p>',
				'force'   => true,
			)
		);

		$this->assertSame( 200, $adopted->get_status() );
		$this->assertSame( $existing->get_id(), $adopted->get_data()['post_id'] );

		// Subsequent pushes match on external_id, no SKU or force needed.
		$update = $this->push(
			array(
				'type'    => 'product',
				'content' => '<p>Newer copy.</p>',
			),
			array( 'timestamp' => time() + 1 )
		);

		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( $existing->get_id(), $update->get_data()['post_id'] );
	}

	/**
	 * @return void
	 */
	public function test_products_are_attributed_to_an_administrator() {
		$response = $this->push( array( 'type' => 'product' ) );

		$author = (int) get_post( $response->get_data()['post_id'] )->post_author;

		$this->assertTrue( user_can( $author, 'edit_posts' ) );
	}

	/**
	 * @return void
	 */
	public function test_the_health_endpoint_reports_woocommerce() {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/citecue/v1/health' ) );

		$this->assertTrue( $response->get_data()['woocommerce'] );
	}

	/**
	 * Sends a signed push.
	 *
	 * @param array $payload Body fields merged over a valid default.
	 * @param array $signing Optional 'timestamp' override.
	 * @return WP_REST_Response
	 */
	private function push( array $payload = array(), array $signing = array() ) {
		$body = wp_json_encode(
			array_merge(
				array(
					'external_id' => 'product-1',
					'title'       => 'Acme Widget',
					'content'     => '<p>A very good widget.</p>',
				),
				$payload
			)
		);

		$timestamp = isset( $signing['timestamp'] ) ? $signing['timestamp'] : time();

		$request = new WP_REST_Request( 'POST', '/citecue/v1/content' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-citecue-timestamp', (string) $timestamp );
		$request->set_header( 'x-citecue-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ) );
		$request->set_body( $body );

		return rest_do_request( $request );
	}
}
