<?php
/**
 * Authentication for the content-push endpoint.
 *
 * This is the plugin's only writable, unauthenticated-by-WordPress surface —
 * anything that gets past these checks can create posts on the site.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Ingest::verify_request
 */
class Test_Citecue_Ingest_Auth extends Citecue_Test_Case {

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
	public function test_a_correctly_signed_push_is_accepted() {
		$response = rest_do_request( $this->signed_push() );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * @return void
	 */
	public function test_ingest_is_off_by_default() {
		$this->plugin->settings->update( array( 'ingest_enabled' => false ) );

		$this->assertPushRejected( 403, 'citecue_ingest_disabled', $this->signed_push() );
	}

	/**
	 * @return void
	 */
	public function test_a_missing_secret_is_rejected() {
		$this->plugin->settings->update( array( 'ingest_secret' => '' ) );

		$this->assertPushRejected( 403, 'citecue_no_secret', $this->signed_push() );
	}

	/**
	 * @return void
	 */
	public function test_an_unsigned_push_is_rejected() {
		$request = $this->signed_push();
		$request->set_header( 'x-citecue-signature', '' );

		$this->assertPushRejected( 401, 'citecue_bad_signature', $request );
	}

	/**
	 * @return void
	 */
	public function test_a_signature_without_the_algorithm_prefix_is_rejected() {
		$request = $this->signed_push();
		$request->set_header( 'x-citecue-signature', hash_hmac( 'sha256', 'anything', self::SECRET ) );

		$this->assertPushRejected( 401, 'citecue_bad_signature', $request );
	}

	/**
	 * @return void
	 */
	public function test_a_signature_from_the_wrong_secret_is_rejected() {
		$request = $this->signed_push( array(), array( 'secret' => 'cws_the_wrong_secret' ) );

		$this->assertPushRejected( 401, 'citecue_bad_signature', $request );
	}

	/**
	 * The signature covers the body, so an attacker cannot swap the payload of
	 * an otherwise valid request.
	 *
	 * @return void
	 */
	public function test_a_tampered_body_is_rejected() {
		$request = $this->signed_push();
		$request->set_body(
			wp_json_encode(
				array(
					'external_id' => 'evil',
					'title'       => 'Evil',
					'content'     => '<p>evil</p>',
				)
			)
		);

		$this->assertPushRejected( 401, 'citecue_bad_signature', $request );
	}

	/**
	 * The signature also covers the timestamp, so a captured request cannot be
	 * re-dated to make it fresh again.
	 *
	 * @return void
	 */
	public function test_a_re_dated_signature_is_rejected() {
		$request = $this->signed_push();
		$request->set_header( 'x-citecue-timestamp', (string) ( time() + 1 ) );

		$this->assertPushRejected( 401, 'citecue_bad_signature', $request );
	}

	/**
	 * @dataProvider provide_stale_timestamps
	 *
	 * @param int $offset Seconds away from now.
	 * @return void
	 */
	public function test_timestamps_outside_the_window_are_rejected( $offset ) {
		$request = $this->signed_push( array(), array( 'timestamp' => time() + $offset ) );

		$this->assertPushRejected( 401, 'citecue_stale_timestamp', $request );
	}

	/**
	 * Timestamps that must fail the ±300s window.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function provide_stale_timestamps() {
		return array(
			'far in the past'   => array( -3600 ),
			'just too old'      => array( -301 ),
			'just too far off'  => array( 301 ),
			'far in the future' => array( 3600 ),
			'missing entirely'  => array( -( time() ) ),
		);
	}

	/**
	 * @dataProvider provide_fresh_timestamps
	 *
	 * @param int $offset Seconds away from now.
	 * @return void
	 */
	public function test_timestamps_inside_the_window_are_accepted( $offset ) {
		$request = $this->signed_push(
			array( 'external_id' => 'window-' . $offset ),
			array( 'timestamp' => time() + $offset )
		);

		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}

	/**
	 * Clock skew inside the tolerated window.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function provide_fresh_timestamps() {
		return array(
			'now'             => array( 0 ),
			'slightly behind' => array( -299 ),
			'slightly ahead'  => array( 299 ),
		);
	}

	/**
	 * A captured request replayed inside the freshness window must not be able
	 * to re-apply its effects — a replayed `force: true` push would silently
	 * overwrite an edit the site owner had just made.
	 *
	 * @return void
	 */
	public function test_a_replayed_signature_is_rejected() {
		$request = $this->signed_push();
		$this->assertSame( 201, rest_do_request( $request )->get_status() );

		$this->assertPushRejected( 401, 'citecue_replayed', $this->replay_of( $request ) );
	}

	/**
	 * Legitimate retries recompute the timestamp, so they keep working.
	 *
	 * @return void
	 */
	public function test_a_freshly_signed_retry_is_accepted() {
		$this->assertSame( 201, rest_do_request( $this->signed_push() )->get_status() );

		// Same payload, signed again a second later.
		$retry = $this->signed_push( array(), array( 'timestamp' => time() + 1 ) );
		$this->assertSame( 200, rest_do_request( $retry )->get_status() );
	}

	/**
	 * @return void
	 */
	public function test_pushes_are_rate_limited() {
		add_filter( 'citecue_ingest_rate_limit', static fn() => 2 );

		$this->assertSame( 201, rest_do_request( $this->signed_push( array( 'external_id' => 'one' ) ) )->get_status() );
		$this->assertSame( 201, rest_do_request( $this->signed_push( array( 'external_id' => 'two' ) ) )->get_status() );

		$this->assertPushRejected( 429, 'citecue_rate_limited', $this->signed_push( array( 'external_id' => 'three' ) ) );
	}

	/**
	 * The rate limit is checked after authentication on purpose: otherwise
	 * anyone could spend the budget with junk requests and lock CiteCue out.
	 *
	 * @return void
	 */
	public function test_unsigned_traffic_cannot_exhaust_the_rate_limit() {
		add_filter( 'citecue_ingest_rate_limit', static fn() => 1 );

		for ( $i = 0; $i < 20; $i++ ) {
			$junk = $this->signed_push( array( 'external_id' => "junk-{$i}" ), array( 'secret' => 'wrong' ) );
			$this->assertSame( 401, rest_do_request( $junk )->get_status() );
		}

		$this->assertSame( 201, rest_do_request( $this->signed_push() )->get_status() );
	}

	/**
	 * The health endpoint is the install handshake, so it stays public — and
	 * must therefore never expose the shared secret or the API key.
	 *
	 * @return void
	 */
	public function test_health_is_public_and_leaks_nothing() {
		$this->configure_delivery();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/citecue/v1/health' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'citecue', $data['plugin'] );
		$this->assertTrue( $data['ingest'] );
		$this->assertTrue( $data['delivery'] );

		$encoded = wp_json_encode( $data );
		$this->assertStringNotContainsString( self::SECRET, $encoded );
		$this->assertStringNotContainsString( self::API_KEY, $encoded );
	}

	/**
	 * Builds a signed push request.
	 *
	 * @param array $payload   Body fields merged over a valid default.
	 * @param array $signing   Optional 'secret' and 'timestamp' overrides.
	 * @return WP_REST_Request
	 */
	private function signed_push( array $payload = array(), array $signing = array() ) {
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

		$secret    = isset( $signing['secret'] ) ? $signing['secret'] : self::SECRET;
		$timestamp = isset( $signing['timestamp'] ) ? $signing['timestamp'] : time();

		$request = new WP_REST_Request( 'POST', '/citecue/v1/content' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-citecue-timestamp', (string) $timestamp );
		$request->set_header( 'x-citecue-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ) );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * An identical copy of a request, as a captured replay would be.
	 *
	 * @param WP_REST_Request $request Original request.
	 * @return WP_REST_Request
	 */
	private function replay_of( WP_REST_Request $request ) {
		$replay = new WP_REST_Request( 'POST', '/citecue/v1/content' );
		$replay->set_headers( $request->get_headers() );
		$replay->set_body( $request->get_body() );

		return $replay;
	}

	/**
	 * Asserts a push was refused with a specific status and error code.
	 *
	 * @param int             $status  Expected HTTP status.
	 * @param string          $code    Expected WP_Error code.
	 * @param WP_REST_Request $request Request to send.
	 * @return void
	 */
	private function assertPushRejected( $status, $code, WP_REST_Request $request ) {
		$response = rest_do_request( $request );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}
}
