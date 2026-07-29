<?php
/**
 * What a signed content push actually does to the site.
 *
 * The invariants that matter to a site owner: pushed content never goes live
 * beyond what they allowed, never silently overwrites their own edits, and
 * never comes back once they have thrown it away.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Ingest::handle_content
 */
class Test_Citecue_Ingest_Content extends Citecue_Test_Case {

	const SECRET = 'cws_testsecret0123456789';

	/**
	 * Boots a REST server with ingest enabled.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

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
	public function test_a_push_creates_a_draft_post() {
		$response = $this->push(
			array(
				'title'   => 'Acme FAQ',
				'content' => '<h2>What is Acme?</h2><p>A company.</p>',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['created'] );

		$post = get_post( $response->get_data()['post_id'] );
		$this->assertSame( 'Acme FAQ', $post->post_title );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'post', $post->post_type );
		$this->assertStringContainsString( 'What is Acme?', $post->post_content );
	}

	/**
	 * Nothing CiteCue pushes is visible until a human says so — that is the
	 * default the plugin promises.
	 *
	 * @return void
	 */
	public function test_content_is_a_draft_even_when_publish_is_requested() {
		$response = $this->push( array( 'status' => 'publish' ) );

		$this->assertSame( 'draft', get_post_status( $response->get_data()['post_id'] ) );
	}

	/**
	 * @dataProvider provide_status_caps
	 *
	 * @param string $cap       Configured maximum status.
	 * @param string $requested Requested status.
	 * @param string $expected  Effective status.
	 * @return void
	 */
	public function test_status_is_capped_by_the_setting( $cap, $requested, $expected ) {
		$this->plugin->settings->update( array( 'ingest_post_status' => $cap ) );

		$response = $this->push( array( 'status' => $requested ) );

		$this->assertSame( $expected, get_post_status( $response->get_data()['post_id'] ) );
	}

	/**
	 * Cap/request/result combinations.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_status_caps() {
		return array(
			'draft cap holds publish'      => array( 'draft', 'publish', 'draft' ),
			'draft cap holds pending'      => array( 'draft', 'pending', 'draft' ),
			'pending cap allows pending'   => array( 'pending', 'pending', 'pending' ),
			'pending cap holds publish'    => array( 'pending', 'publish', 'pending' ),
			'pending cap allows draft'     => array( 'pending', 'draft', 'draft' ),
			'publish cap allows publish'   => array( 'publish', 'publish', 'publish' ),
			'publish cap allows draft'     => array( 'publish', 'draft', 'draft' ),
			'unknown request falls to cap' => array( 'pending', 'nonsense', 'pending' ),
			'no request falls to cap'      => array( 'pending', '', 'pending' ),
		);
	}

	/**
	 * @return void
	 */
	public function test_a_second_push_updates_the_same_post() {
		$first = $this->push( array( 'title' => 'First' ) );
		$id    = $first->get_data()['post_id'];

		$second = $this->push( array( 'title' => 'Second' ), array( 'timestamp' => time() + 1 ) );

		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['updated'] );
		$this->assertSame( $id, $second->get_data()['post_id'] );
		$this->assertSame( 'Second', get_post( $id )->post_title );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_status' => 'draft',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A different external_id is a different piece of content.
	 *
	 * @return void
	 */
	public function test_distinct_external_ids_create_distinct_posts() {
		$one = $this->push( array( 'external_id' => 'one' ) );
		$two = $this->push( array( 'external_id' => 'two' ), array( 'timestamp' => time() + 1 ) );

		$this->assertNotSame( $one->get_data()['post_id'], $two->get_data()['post_id'] );
	}

	/**
	 * Pushed HTML is untrusted input: it is stored, so it must be sanitized
	 * exactly like content from an editor without unfiltered_html.
	 *
	 * @return void
	 */
	public function test_dangerous_markup_is_stripped() {
		$response = $this->push(
			array(
				'content' => '<p>Hello</p><script>alert(1)</script><img src=x onerror="alert(2)">',
			)
		);

		$content = get_post( $response->get_data()['post_id'] )->post_content;

		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringNotContainsString( 'onerror', $content );
		$this->assertStringContainsString( '<p>Hello</p>', $content );
	}

	/**
	 * @return void
	 */
	public function test_the_title_is_stored_as_plain_text() {
		$response = $this->push( array( 'title' => 'Acme <script>alert(1)</script> FAQ' ) );

		$this->assertStringNotContainsString( '<script', get_post( $response->get_data()['post_id'] )->post_title );
	}

	/**
	 * @dataProvider provide_incomplete_payloads
	 *
	 * @param array $payload Payload missing a required field.
	 * @return void
	 */
	public function test_incomplete_payloads_are_rejected( array $payload ) {
		$response = $this->push( $payload );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'citecue_missing_fields', $response->get_data()['code'] );
	}

	/**
	 * Payloads that must not create anything.
	 *
	 * @return array<string,array{0:array}>
	 */
	public function provide_incomplete_payloads() {
		return array(
			'no external_id'       => array( array( 'external_id' => '' ) ),
			'no title'             => array( array( 'title' => '' ) ),
			'no content'           => array( array( 'content' => '' ) ),
			'whitespace content'   => array( array( 'content' => "   \n\t " ) ),
			'unusable external_id' => array( array( 'external_id' => '!!!/@@@' ) ),
		);
	}

	/**
	 * External ids end up in a meta_value lookup, so they are reduced to a
	 * known-safe charset rather than rejected outright.
	 *
	 * @return void
	 */
	public function test_external_ids_are_sanitized() {
		$response = $this->push( array( 'external_id' => '../../etc/passwd brief:1' ) );

		$this->assertSame(
			'....etcpasswdbrief:1',
			get_post_meta( $response->get_data()['post_id'], '_citecue_external_id', true )
		);
	}

	/**
	 * @return void
	 */
	public function test_external_ids_are_length_capped() {
		$response = $this->push( array( 'external_id' => str_repeat( 'a', 200 ) ) );

		$this->assertSame(
			128,
			strlen( get_post_meta( $response->get_data()['post_id'], '_citecue_external_id', true ) )
		);
	}

	/**
	 * @return void
	 */
	public function test_a_non_json_body_is_rejected() {
		$body      = 'this is not json';
		$timestamp = time();

		$request = new WP_REST_Request( 'POST', '/citecue/v1/content' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-citecue-timestamp', (string) $timestamp );
		$request->set_header( 'x-citecue-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ) );
		$request->set_body( $body );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * If the site owner has edited a pushed post, the next push must not
	 * silently discard their work.
	 *
	 * @return void
	 */
	public function test_a_locally_edited_post_is_not_overwritten() {
		$id = $this->push()->get_data()['post_id'];

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<p>Rewritten by the site owner.</p>',
			)
		);

		$response = $this->push( array( 'content' => '<p>Pushed again.</p>' ), array( 'timestamp' => time() + 1 ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'citecue_edited_locally', $response->get_data()['code'] );
		$this->assertStringContainsString( 'site owner', get_post( $id )->post_content );
	}

	/**
	 * …but an explicit force overrides it.
	 *
	 * @return void
	 */
	public function test_force_overwrites_a_locally_edited_post() {
		$id = $this->push()->get_data()['post_id'];
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<p>Local edit.</p>',
			)
		);

		$response = $this->push(
			array(
				'content' => '<p>Pushed again.</p>',
				'force'   => true,
			),
			array( 'timestamp' => time() + 1 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Pushed again.', get_post( $id )->post_content );
	}

	/**
	 * An unchanged post is not an edited one — pushes must keep flowing.
	 *
	 * @return void
	 */
	public function test_an_untouched_post_keeps_accepting_pushes() {
		$this->push();

		$response = $this->push( array( 'content' => '<p>Updated copy.</p>' ), array( 'timestamp' => time() + 1 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Throwing a pushed draft away is a decision: it must not be resurrected,
	 * and it must not come back as a duplicate either.
	 *
	 * @return void
	 */
	public function test_a_trashed_push_stays_trashed() {
		$id = $this->push()->get_data()['post_id'];
		wp_trash_post( $id );

		$response = $this->push( array(), array( 'timestamp' => time() + 1 ) );

		$this->assertSame( 410, $response->get_status() );
		$this->assertSame( 'citecue_trashed', $response->get_data()['code'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_status' => 'draft',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_changing_the_type_of_an_existing_push_is_refused() {
		$this->push( array( 'type' => 'post' ) );

		$response = $this->push( array( 'type' => 'page' ), array( 'timestamp' => time() + 1 ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'citecue_type_conflict', $response->get_data()['code'] );
	}

	/**
	 * @return void
	 */
	public function test_pages_can_be_pushed() {
		$response = $this->push( array( 'type' => 'page' ) );

		$this->assertSame( 'page', get_post_type( $response->get_data()['post_id'] ) );
	}

	/**
	 * @return void
	 */
	public function test_an_unknown_type_falls_back_to_the_configured_default() {
		$this->plugin->settings->update( array( 'ingest_post_type' => 'page' ) );

		$response = $this->push( array( 'type' => 'attachment' ) );

		$this->assertSame( 'page', get_post_type( $response->get_data()['post_id'] ) );
	}

	/**
	 * @return void
	 */
	public function test_products_are_refused_without_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded in this run.' );
		}

		$response = $this->push( array( 'type' => 'product' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'citecue_woocommerce_missing', $response->get_data()['code'] );
	}

	/**
	 * A configured product default must degrade to a post if WooCommerce is
	 * deactivated, rather than failing every push.
	 *
	 * @return void
	 */
	public function test_a_stale_product_default_degrades_to_post() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded in this run.' );
		}

		update_option(
			'citecue_settings',
			array_merge( Citecue_Settings::defaults(), array( 'ingest_post_type' => 'product' ) )
		);
		$this->reset_settings_cache();
		$this->plugin->settings->update(
			array(
				'ingest_enabled' => true,
				'ingest_secret'  => self::SECRET,
			)
		);

		$response = $this->push();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'post', get_post_type( $response->get_data()['post_id'] ) );
	}

	/**
	 * @return void
	 */
	public function test_categories_and_tags_are_created_and_assigned() {
		$response = $this->push(
			array(
				'categories' => array( 'AI Search' ),
				'tags'       => array( 'llms', 'faq' ),
			)
		);
		$id       = $response->get_data()['post_id'];

		$this->assertSame( array( 'AI Search' ), wp_list_pluck( get_the_category( $id ), 'name' ) );
		$this->assertEqualSets( array( 'llms', 'faq' ), wp_list_pluck( wp_get_post_tags( $id ), 'name' ) );
	}

	/**
	 * @return void
	 */
	public function test_an_existing_category_is_reused_not_duplicated() {
		$existing = self::factory()->category->create( array( 'name' => 'AI Search' ) );

		$response = $this->push( array( 'categories' => array( 'AI Search' ) ) );

		$this->assertSame(
			array( $existing ),
			wp_list_pluck( get_the_category( $response->get_data()['post_id'] ), 'term_id' )
		);
	}

	/**
	 * @return void
	 */
	public function test_optional_fields_are_stored() {
		$response = $this->push(
			array(
				'excerpt'          => 'A short summary.',
				'slug'             => 'acme-faq',
				'source'           => 'faq_pack:opp_42',
				'meta_description' => 'Everything about Acme.',
			)
		);
		$id       = $response->get_data()['post_id'];

		$this->assertSame( 'A short summary.', get_post( $id )->post_excerpt );
		$this->assertSame( 'acme-faq', get_post( $id )->post_name );
		$this->assertSame( 'faq_pack:opp_42', get_post_meta( $id, '_citecue_source', true ) );
		$this->assertSame( 'Everything about Acme.', get_post_meta( $id, '_citecue_meta_description', true ) );
		$this->assertSame( 'faq-pack-1', get_post_meta( $id, '_citecue_external_id', true ) );
	}

	/**
	 * @return void
	 */
	public function test_pushed_posts_are_attributed_to_an_administrator() {
		self::factory()->user->create( array( 'role' => 'administrator' ) );

		$response = $this->push();
		$author   = (int) get_post( $response->get_data()['post_id'] )->post_author;

		$this->assertSame( $this->oldest_administrator(), $author );
		$this->assertTrue( user_can( $author, 'edit_posts' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_configured_author_is_used_when_it_can_edit_posts() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->plugin->settings->update( array( 'ingest_author' => $editor ) );

		$response = $this->push();

		$this->assertSame( $editor, (int) get_post( $response->get_data()['post_id'] )->post_author );
	}

	/**
	 * @return void
	 */
	public function test_a_configured_author_without_edit_rights_is_ignored() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->plugin->settings->update( array( 'ingest_author' => $subscriber ) );

		$response = $this->push();

		$this->assertSame(
			$this->oldest_administrator(),
			(int) get_post( $response->get_data()['post_id'] )->post_author
		);
	}

	/**
	 * The fallback author the plugin picks: the site's oldest administrator.
	 *
	 * @return int
	 */
	private function oldest_administrator() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return (int) $admins[0];
	}

	/**
	 * @return void
	 */
	public function test_the_postarr_filter_can_adjust_the_push() {
		add_filter(
			'citecue_ingest_postarr',
			static function ( $postarr ) {
				$postarr['post_title'] = 'Filtered title';
				return $postarr;
			}
		);

		$response = $this->push();

		$this->assertSame( 'Filtered title', get_post( $response->get_data()['post_id'] )->post_title );
	}

	/**
	 * @return void
	 */
	public function test_the_response_describes_where_the_content_landed() {
		$data = $this->push()->get_data();

		$this->assertSame( 'draft', $data['status'] );
		$this->assertStringContainsString( 'post.php', $data['edit_link'] );
		$this->assertStringContainsString( (string) $data['post_id'], $data['edit_link'] );
		$this->assertNotEmpty( $data['permalink'] );
	}

	/**
	 * The meta description is printed for pushed content…
	 *
	 * @return void
	 */
	public function test_the_meta_description_is_printed_on_the_post() {
		$id = $this->push( array( 'meta_description' => 'Everything about Acme.' ) )->get_data()['post_id'];
		wp_publish_post( $id );
		$this->go_to( get_permalink( $id ) );

		$this->assertStringContainsString(
			'<meta name="description" content="Everything about Acme." />',
			$this->render_head()
		);
	}

	/**
	 * …and can be turned off, e.g. when an SEO plugin owns the tag.
	 *
	 * @return void
	 */
	public function test_the_meta_description_can_be_suppressed() {
		$id = $this->push( array( 'meta_description' => 'Everything about Acme.' ) )->get_data()['post_id'];
		wp_publish_post( $id );
		$this->go_to( get_permalink( $id ) );

		add_filter( 'citecue_output_meta_description', '__return_false' );

		$this->assertStringNotContainsString( '<meta name="description"', $this->render_head() );
	}

	/**
	 * @return void
	 */
	public function test_no_meta_description_is_printed_for_ordinary_posts() {
		$id = self::factory()->post->create();
		$this->go_to( get_permalink( $id ) );

		$this->assertStringNotContainsString( '<meta name="description"', $this->render_head() );
	}

	/**
	 * Captures what the plugin adds to wp_head.
	 *
	 * @return string
	 */
	private function render_head() {
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
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
					'external_id' => 'faq-pack-1',
					'title'       => 'Acme FAQ',
					'content'     => '<h2>What is Acme?</h2><p>A company.</p>',
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
