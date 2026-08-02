<?php
/**
 * The one-click pairing handshake.
 *
 * The handshake replaces two secrets being hand-carried between CiteCue and
 * WordPress, so these tests care most about the things that make that safe:
 * the state token is single-use and bound to its admin, the ingest secret
 * leaves only in the server-to-server claim, and a claim that fails leaves the
 * site exactly as unconnected as it was.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Connect
 */
class Test_Citecue_Connect extends Citecue_Test_Case {

	/**
	 * Handshake under test.
	 *
	 * @var Citecue_Connect
	 */
	private $connect;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->connect = new Citecue_Connect( $this->plugin );
	}

	/**
	 * A successful claim response.
	 *
	 * @param array $overrides Payload overrides.
	 * @return string
	 */
	private function claim_payload( array $overrides = array() ) {
		return wp_json_encode(
			array_merge(
				array(
					'apiKey'    => 'ck_live_frompairing',
					'publicKey' => 'pk_paired',
					'domain'    => 'example.org',
				),
				$overrides
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_the_start_url_points_at_the_configured_app() {
		$url = $this->connect->start( admin_url( 'options-general.php?page=citecue' ) );

		$this->assertStringStartsWith( Citecue_Settings::DEFAULT_API_BASE . '/connect/wordpress', $url );
	}

	/**
	 * CiteCue needs to know which site is asking and where to send the admin
	 * back to, and the plugin needs to recognise its own handshake on return.
	 *
	 * @return void
	 */
	public function test_the_start_url_carries_the_site_return_and_state() {
		$return = admin_url( 'options-general.php?page=citecue' );

		$url = $this->connect->start( $return );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( home_url( '/' ), $query['site'] );
		$this->assertSame( $return, $query['return'] );
		$this->assertNotEmpty( $query['state'] );
		$this->assertTrue( $this->connect->verify_state( $query['state'] ) );
	}

	/**
	 * @return void
	 */
	public function test_a_wrong_state_is_rejected() {
		$this->connect->start( admin_url( 'options-general.php?page=citecue' ) );

		$this->assertFalse( $this->connect->verify_state( 'not-the-state' ) );
	}

	/**
	 * The state is the handshake's only CSRF defence, so a code cannot be
	 * replayed against a second visit to the return URL.
	 *
	 * @return void
	 */
	public function test_a_state_can_only_be_spent_once() {
		$url = $this->connect->start( admin_url( 'options-general.php?page=citecue' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertTrue( $this->connect->verify_state( $query['state'] ) );
		$this->assertFalse( $this->connect->verify_state( $query['state'] ) );
	}

	/**
	 * A guess burns the handshake too — otherwise the token could be brute
	 * forced against a still-open window.
	 *
	 * @return void
	 */
	public function test_a_failed_guess_burns_the_handshake() {
		$url = $this->connect->start( admin_url( 'options-general.php?page=citecue' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->connect->verify_state( 'wrong' );

		$this->assertFalse( $this->connect->verify_state( $query['state'] ) );
	}

	/**
	 * A code redirected into a different administrator's browser must not
	 * connect the site on their behalf.
	 *
	 * @return void
	 */
	public function test_the_state_is_bound_to_the_admin_who_started_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$url = $this->connect->start( admin_url( 'options-general.php?page=citecue' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->connect->verify_state( $query['state'] ) );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_stores_the_credentials_it_receives() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$result = $this->connect->claim( 'one-time-code' );

		$this->assertNotWPError( $result );
		$this->reset_settings_cache();
		$this->assertSame( 'ck_live_frompairing', $this->plugin->settings->get( 'api_key' ) );
		$this->assertSame( 'pk_paired', $this->plugin->settings->get( 'public_key' ) );
		$this->assertSame( 'example.org', $this->plugin->settings->get( 'project_domain' ) );
		$this->assertTrue( $this->plugin->settings->is_delivery_configured() );
	}

	/**
	 * The whole point of the handshake: CiteCue is handed the ingest secret
	 * and this site's addresses instead of the customer copying them over.
	 *
	 * @return void
	 */
	public function test_a_claim_hands_citecue_the_ingest_secret_and_site_urls() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );
		$secret = $this->plugin->settings->ensure_ingest_secret();

		$this->connect->claim( 'one-time-code' );

		$request = $this->http->last( 'connect' );
		$sent    = json_decode( $request['args']['body'], true );

		$this->assertSame( 'one-time-code', $sent['code'] );
		$this->assertSame( $secret, $sent['ingest_secret'] );
		$this->assertSame( home_url( '/' ), $sent['site_url'] );
		$this->assertSame( rest_url( 'citecue/v1/' ), $sent['rest_url'] );
		$this->assertSame( CITECUE_VERSION, $sent['plugin_version'] );
	}

	/**
	 * The secret must never ride in the browser redirect, only in the
	 * server-to-server exchange — and a redirect there would be a chance to
	 * replay it somewhere other than the configured API base.
	 *
	 * @return void
	 */
	public function test_the_claim_never_follows_a_redirect() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$request = $this->http->last( 'connect' );
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	/**
	 * Accepting content pushes is the customer's decision, taken on CiteCue's
	 * connect screen where it is spelled out. Silence is not consent.
	 *
	 * @return void
	 */
	public function test_ingest_stays_off_when_the_response_says_nothing_about_it() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$this->reset_settings_cache();
		$this->assertFalse( $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * @return void
	 */
	public function test_ingest_is_enabled_when_the_connect_screen_granted_it() {
		$this->http->queue( 'connect', 200, $this->claim_payload( array( 'ingest' => true ) ) );

		$this->connect->claim( 'one-time-code' );

		$this->reset_settings_cache();
		$this->assertTrue( $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_lifts_an_earlier_auth_failure() {
		update_option( 'citecue_auth_failed', time() );
		$this->plugin->cache->trip_circuit( Citecue_Cache::AUTH_CIRCUIT_TTL );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$this->assertFalse( get_option( 'citecue_auth_failed' ) );
		$this->assertFalse( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * @return void
	 */
	public function test_an_expired_code_is_reported_as_such() {
		$this->http->queue( 'connect', 410, wp_json_encode( array( 'error' => 'code_expired' ) ) );

		$result = $this->connect->claim( 'stale-code' );

		$this->assertWPError( $result );
		$this->assertSame( 'citecue_connect_code_expired', $result->get_error_code() );
	}

	/**
	 * @return void
	 */
	public function test_a_used_code_is_reported_as_such() {
		$this->http->queue( 'connect', 409, wp_json_encode( array( 'error' => 'code_used' ) ) );

		$result = $this->connect->claim( 'spent-code' );

		$this->assertWPError( $result );
		$this->assertSame( 'citecue_connect_code_used', $result->get_error_code() );
	}

	/**
	 * A half-finished handshake must not leave the site believing it is
	 * connected — that would silently stop serving with no way to tell why.
	 *
	 * @return void
	 */
	public function test_a_failed_claim_leaves_the_site_unconnected() {
		$this->http->queue( 'connect', 400, wp_json_encode( array( 'error' => 'invalid_code' ) ) );

		$this->connect->claim( 'nonsense' );

		$this->reset_settings_cache();
		$this->assertFalse( $this->plugin->settings->is_connected() );
		$this->assertSame( '', $this->plugin->settings->get( 'api_key' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_transport_failure_is_an_error_not_a_connection() {
		$this->http->queue_error( 'connect', 'Operation timed out' );

		$result = $this->connect->claim( 'one-time-code' );

		$this->assertWPError( $result );
		$this->reset_settings_cache();
		$this->assertFalse( $this->plugin->settings->is_connected() );
	}

	/**
	 * @return void
	 */
	public function test_a_payload_without_credentials_is_rejected() {
		$this->http->queue( 'connect', 200, wp_json_encode( array( 'ok' => true ) ) );

		$result = $this->connect->claim( 'one-time-code' );

		$this->assertWPError( $result );
		$this->assertSame( 'citecue_bad_payload', $result->get_error_code() );
	}

	/**
	 * @return void
	 */
	public function test_disconnecting_forgets_the_credentials() {
		$this->configure_delivery();

		$this->connect->disconnect();

		$this->reset_settings_cache();
		$this->assertFalse( $this->plugin->settings->is_connected() );
		$this->assertSame( '', $this->plugin->settings->get( 'public_key' ) );
		$this->assertFalse( $this->plugin->settings->is_delivery_configured() );
	}

	/**
	 * Reconnecting the same site should not invalidate a secret CiteCue may
	 * still be holding, so rotating it stays a separate, deliberate action.
	 *
	 * @return void
	 */
	public function test_disconnecting_keeps_the_ingest_secret() {
		$this->configure_delivery();
		$secret = $this->plugin->settings->ensure_ingest_secret();

		$this->connect->disconnect();

		$this->reset_settings_cache();
		$this->assertSame( $secret, $this->plugin->settings->get( 'ingest_secret' ) );
	}

	/**
	 * The check that used to be a curl command in the README: ask this site
	 * for its own llms.txt as a crawler and look for the marker header.
	 *
	 * @return void
	 */
	public function test_verification_passes_when_the_marker_header_comes_back() {
		$this->http->queue( 'loopback', 200, 'llms', array( 'x-citecue' => 'llms-txt' ) );

		$result = $this->connect->verify_install();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'llms-txt', $result['marker'] );
	}

	/**
	 * A 200 without the header is the full-page-cache misconfiguration — the
	 * failure most worth naming, and the one a curl command hid in a terminal.
	 *
	 * @return void
	 */
	public function test_verification_fails_when_something_else_answered() {
		$this->http->queue( 'loopback', 200, 'theme output' );

		$result = $this->connect->verify_install();

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * When llms.txt falls through — no llms.txt for the project upstream —
	 * the crawler proxy is next on template_redirect and can answer the same
	 * URL with `X-Citecue: served`. Accepting any marker would read that as
	 * proof llms.txt works, which is precisely what it disproves.
	 *
	 * @return void
	 */
	public function test_verification_rejects_the_proxys_marker() {
		$this->http->queue( 'loopback', 200, '<html>optimized page</html>', array( 'x-citecue' => 'served' ) );

		$result = $this->connect->verify_install();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'served', $result['message'] );
	}

	/**
	 * With llms.txt switched off the check can prove nothing, so it reports
	 * that rather than a failure the site did not have.
	 *
	 * @return void
	 */
	public function test_verification_is_skipped_when_llms_txt_is_off() {
		$this->plugin->settings->update( array( 'llms_txt_enabled' => false ) );

		$result = $this->connect->verify_install();

		$this->assertTrue( $result['skipped'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $this->http->count( 'loopback' ) );
	}

	/**
	 * @return void
	 */
	public function test_verification_survives_an_unreachable_site() {
		$this->http->queue_error( 'loopback', 'Connection refused' );

		$result = $this->connect->verify_install();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
	}

	/**
	 * @return void
	 */
	public function test_the_last_verification_is_remembered() {
		$this->assertNull( $this->connect->last_verification() );
		$this->http->queue( 'loopback', 200, 'llms', array( 'x-citecue' => 'llms-txt' ) );

		$this->connect->verify_install();

		$stored = $this->connect->last_verification();
		$this->assertTrue( $stored['ok'] );
		$this->assertGreaterThan( 0, $stored['checked_at'] );
	}

	/**
	 * The loopback has to look like the traffic being verified, or it proves
	 * nothing about what a crawler would receive.
	 *
	 * @return void
	 */
	public function test_verification_requests_as_a_crawler() {
		$this->http->queue( 'loopback', 200, 'llms', array( 'x-citecue' => 'llms-txt' ) );

		$this->connect->verify_install();

		$request = $this->http->last( 'loopback' );
		$this->assertSame( home_url( '/llms.txt' ), $request['url'] );
		$this->assertNotEmpty( $this->plugin->crawlers->match( $request['args']['user-agent'] ) );
	}
}
