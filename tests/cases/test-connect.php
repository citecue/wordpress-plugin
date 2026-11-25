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

		// These cases pin the other capabilities' exact sets; the language
		// capability has its own cases (test-languages.php, below), and would
		// otherwise join every set in plain and Polylang mode.
		$this->plugin->settings->update( array( 'language_pages_enabled' => false ) );
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
	 * CiteCue decides whether `seoAudience: 'all'` is a promise this channel
	 * can keep by reading the capability recorded here — and reads its absence
	 * as "cannot inject", so under-reporting costs the customer the feature.
	 *
	 * @return void
	 */
	public function test_a_claim_reports_the_seo_head_capability() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertTrue( $sent['seo_head'] );
		$this->assertTrue( $this->plugin->settings->get( 'seo_head_reported' ) );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * A site with injection switched off keeps the promise no better than a
	 * plugin that cannot inject at all, so it must say so.
	 *
	 * @return void
	 */
	public function test_a_claim_reports_injection_being_switched_off() {
		$this->plugin->settings->update( array( 'seo_head_enabled' => false ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertFalse( $sent['seo_head'] );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * A failed claim wrote nothing on CiteCue's side, so recording the
	 * capability locally would silence the reconnect prompt for something the
	 * app never learned.
	 *
	 * @return void
	 */
	public function test_a_failed_claim_reports_no_capability() {
		$this->http->queue( 'connect', 400, wp_json_encode( array( 'error' => 'invalid_code' ) ) );

		$this->connect->claim( 'nonsense' );

		$this->assertNull( $this->plugin->settings->get( 'seo_head_reported' ) );
	}

	/**
	 * The block half of the delivery channel is gated on `body_blocks`, and
	 * CiteCue reads its absence as "cannot place a block" — so a plugin that
	 * injects one and never says so is sent `body: ''` forever.
	 *
	 * Declared together with the injection that honours it, deliberately: the
	 * capability is what makes CiteCue start sending real block markup, so a
	 * release that announced it without placing it would put a block on a
	 * customer's live page that nothing renders.
	 *
	 * @return void
	 */
	public function test_a_claim_declares_the_body_block_capability() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertTrue( $sent['body_blocks'] );
		$this->assertTrue( $sent['seo_head_baseline'] );
	}

	/**
	 * All three delivery capabilities ride the one setting that governs the
	 * response they arrive in: a site that fetches nothing can place nothing,
	 * and claiming otherwise is the over-claim the capability exists to stop.
	 *
	 * @return void
	 */
	public function test_switching_injection_off_withdraws_every_delivery_capability() {
		$this->plugin->settings->update( array( 'seo_head_enabled' => false ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertFalse( $sent['seo_head'] );
		$this->assertFalse( $sent['body_blocks'] );
		$this->assertFalse( $sent['seo_head_baseline'] );
		$this->assertSame( array(), $this->plugin->settings->get( 'capabilities_reported' ) );
	}

	/**
	 * An install connected by an EARLIER build declared `seo_head` and nothing
	 * else, so its `seo_head_reported` agrees and the old check stays quiet —
	 * while CiteCue still believes the site cannot place a block and withholds
	 * every one. Without this, the feature would reach nobody who was already
	 * a customer, and nothing would say why.
	 *
	 * @return void
	 */
	public function test_a_connection_predating_the_block_capability_asks_for_a_reconnect() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'capabilities_reported' => null,
			)
		);

		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * A claim records the set it actually sent, so the same site stops being
	 * asked the moment it has reconnected.
	 *
	 * @return void
	 */
	public function test_a_claim_records_the_declared_capability_set() {
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$this->assertSame(
			array( 'body_blocks', 'seo_head', 'seo_head_baseline' ),
			$this->plugin->settings->get( 'capabilities_reported' )
		);
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * The claim carries `publish_live` as a flat boolean beside the others,
	 * and sends it as false rather than omitting it when the cap is below
	 * Published — absence and false both mean NO to CiteCue.
	 *
	 * @return void
	 */
	public function test_a_claim_reports_whether_it_may_publish_live() {
		$this->plugin->settings->update( array( 'ingest_post_status' => 'publish' ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertTrue( $sent['publish_live'] );
		$this->assertSame(
			array( 'body_blocks', 'publish_live', 'seo_head', 'seo_head_baseline' ),
			$this->plugin->settings->get( 'capabilities_reported' )
		);
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_below_published_says_it_may_not_publish_live() {
		$this->plugin->settings->update( array( 'ingest_post_status' => 'pending' ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertArrayHasKey( 'publish_live', $sent );
		$this->assertFalse( $sent['publish_live'] );
	}

	/**
	 * Raising Maximum status after connecting leaves CiteCue defaulting every
	 * Calendar push to a draft the site would have published; lowering it
	 * leaves CiteCue telling the customer the site is ready to publish live
	 * when the cap will hold each post back. CiteCue learns the flag only from
	 * the claim, so both directions must ask for a reconnect — and must say
	 * it is about publishing, not about page enhancements nothing changed.
	 *
	 * @return void
	 */
	public function test_moving_the_maximum_status_after_connecting_asks_for_a_reconnect() {
		$this->http->queue( 'connect', 200, $this->claim_payload( array( 'ingest' => true ) ) );
		$this->connect->claim( 'one-time-code' );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );

		// Raised: the settings form writes through the same sanitize callback.
		$this->plugin->settings->update( array( 'ingest_post_status' => 'publish' ) );

		$this->assertNotSame(
			$this->plugin->settings->get( 'capabilities_reported' ),
			$this->plugin->settings->active_delivery_capabilities()
		);
		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
		$this->assertSame( 'publish_live', $this->plugin->settings->seo_head_reconnect_reason() );

		// Reconnecting re-reports it and the prompt goes away.
		$this->http->queue( 'connect', 200, $this->claim_payload( array( 'ingest' => true ) ) );
		$this->connect->claim( 'one-time-code' );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );

		// Lowered: the flag must come off CiteCue's record too.
		$this->plugin->settings->update( array( 'ingest_post_status' => 'draft' ) );

		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
		$this->assertSame( 'publish_live', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * When a delivery capability has gone stale too, the page-enhancements
	 * reason wins: it is the one that withholds something from live pages, and
	 * the one reconnect fixes both.
	 *
	 * @return void
	 */
	public function test_stale_delivery_capabilities_outrank_a_stale_publish_flag() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'seo_head_enabled'      => true,
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => array( 'seo_head' ),
			)
		);

		$this->assertSame( 'capabilities', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * An install connected before capabilities were reported by name, with a
	 * cap of Published, under-claims both; it reads as the delivery case.
	 *
	 * @return void
	 */
	public function test_a_connection_predating_named_capabilities_reports_the_delivery_reason() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => null,
			)
		);

		$this->assertSame( 'capabilities', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * And with injection off, the publish flag alone is what is missing.
	 *
	 * @return void
	 */
	public function test_a_connection_predating_named_capabilities_with_only_publish_live() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => false,
				'seo_head_enabled'      => false,
				'ingest_enabled'        => true,
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => null,
			)
		);

		$this->assertSame( 'publish_live', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * A site that accepts no pushes is sent no posts, so the status CiteCue
	 * would ask for does not matter yet, and prompting over it would be
	 * nagging about nothing. The flag is still reported as Maximum status
	 * says; only the prompt waits until pushes are switched on.
	 *
	 * @return void
	 */
	public function test_a_stale_publish_flag_waits_while_pushes_are_off() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'seo_head_enabled'      => true,
				'ingest_enabled'        => false,
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => array( 'body_blocks', 'seo_head', 'seo_head_baseline' ),
			)
		);

		$this->assertTrue( $this->plugin->settings->declared_capabilities()['publish_live'] );
		$this->assertFalse( $this->plugin->settings->publish_live_is_stale() );
		$this->assertSame( '', $this->plugin->settings->seo_head_reconnect_reason() );

		// Switched on here without reconnecting: now it matters.
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );

		$this->assertTrue( $this->plugin->settings->publish_live_is_stale() );
		$this->assertSame( 'publish_live', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * The publish flag's own staleness does not depend on which reason the
	 * notice leads with, so the status card can show it even when another
	 * reason wins.
	 *
	 * @return void
	 */
	public function test_a_stale_publish_flag_is_known_beside_another_reason() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'seo_head_enabled'      => true,
				'ingest_enabled'        => true,
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => array( 'seo_head' ),
			)
		);

		$this->assertSame( 'capabilities', $this->plugin->settings->seo_head_reconnect_reason() );
		$this->assertTrue( $this->plugin->settings->publish_live_is_stale() );
	}

	/**
	 * Installing WooCommerce after connecting leaves stale information on the
	 * key, not a broken feature — it gates nothing CiteCue sends — so it must
	 * not raise a prompt that asks the customer to fix something that is not
	 * wrong.
	 *
	 * @return void
	 */
	public function test_a_presentational_capability_alone_never_asks_for_a_reconnect() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'capabilities_reported' => array( 'body_blocks', 'seo_head', 'seo_head_baseline' ),
			)
		);

		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * Disconnecting forgets the set: the next connection mints a new key with
	 * its own capabilities, and what the old one recorded says nothing about it.
	 *
	 * @return void
	 */
	public function test_disconnecting_forgets_the_declared_capability_set() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'capabilities_reported' => array( 'seo_head' ) ) );

		$this->connect->disconnect();

		$this->assertNull( $this->plugin->settings->get( 'capabilities_reported' ) );
	}

	/**
	 * The prompt has to say what actually went stale. A site upgrading into a
	 * new capability has not touched its metadata setting, and telling it that
	 * its metadata is not reaching CiteCue sends an administrator looking for a
	 * fault that is not there.
	 *
	 * @return void
	 */
	public function test_a_stale_capability_set_is_not_reported_as_a_metadata_problem() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'seo_head_enabled'      => true,
				'capabilities_reported' => array( 'seo_head' ),
			)
		);

		$this->assertSame( 'capabilities', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * A moved metadata setting still reports itself as one, in both directions.
	 *
	 * @return void
	 */
	public function test_a_moved_metadata_setting_reports_itself() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => false,
				'seo_head_enabled'      => true,
				'capabilities_reported' => array( 'body_blocks', 'seo_head', 'seo_head_baseline' ),
			)
		);
		$this->assertSame( 'enabled', $this->plugin->settings->seo_head_reconnect_reason() );

		$this->plugin->settings->update(
			array(
				'seo_head_reported' => true,
				'seo_head_enabled'  => false,
			)
		);
		$this->assertSame( 'disabled', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * Consent is never a reason to reconnect for this. Content pushes gate the
	 * ingest endpoint and nothing on the delivery path — page enhancements
	 * arrive through the same authenticated read as the metadata — so a site
	 * that has not allowed pushes is fully configured for them, and prompting
	 * it would be asking an administrator to grant write access to their site
	 * for a feature that does not use it.
	 *
	 * @return void
	 */
	public function test_withheld_content_push_consent_is_never_a_reconnect_reason() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported'     => true,
				'seo_head_enabled'      => true,
				'capabilities_reported' => array( 'body_blocks', 'seo_head', 'seo_head_baseline' ),
				'ingest_enabled'        => false,
				'ingest_secret'         => '',
			)
		);

		$this->assertSame( '', $this->plugin->settings->seo_head_reconnect_reason() );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * A project list that says CiteCue holds no signing secret for this site
	 * closes the switch here, so the screen stops promising a channel that
	 * cannot deliver.
	 *
	 * @return void
	 */
	public function test_withdrawn_consent_switches_pushes_off() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );

		$revoked = $this->plugin->connect->reconcile_content_push( $this->projects( array( 'contentPush' => false ) ) );

		$this->assertTrue( $revoked );
		$this->assertFalse( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
		$this->assertGreaterThan( 0, (int) $this->plugin->settings->get( 'content_push_revoked_at' ) );
	}

	/**
	 * A CiteCue that still holds the secret changes nothing.
	 *
	 * @return void
	 */
	public function test_held_consent_leaves_pushes_on() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );

		$this->assertFalse( $this->plugin->connect->reconcile_content_push( $this->projects( array( 'contentPush' => true ) ) ) );
		$this->assertTrue( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * The switch is never turned ON from a remote read, even though CiteCue's
	 * consent gate means `contentPush: true` now implies the customer did once
	 * tick the box. That makes the grant reading tempting and still wrong: the
	 * field reports what CiteCue holds at this instant, while the switch is the
	 * administrator's standing decision about their own site — and they may
	 * have closed it here on purpose since. Re-opening it from a remote read
	 * would hand write access back to a site whose owner had refused it.
	 *
	 * @return void
	 */
	public function test_a_remote_read_never_switches_pushes_on() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => false ) );

		$this->plugin->connect->reconcile_content_push( $this->projects( array( 'contentPush' => true ) ) );

		$this->assertFalse( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * A response from a CiteCue that predates the field must not read as a
	 * withdrawal. Defaulting the absent key to false would switch pushes off on
	 * every site at once, which is the one way this reconcile could do real
	 * damage.
	 *
	 * This test looks redundant against the current API and is not.
	 * `contentPush` is a required boolean on every project entry, so a reader
	 * checking the schema will find no way to reach the case at all. The case
	 * it covers is the deployment WITHOUT the field — a rollback, a staging
	 * origin on an older build, a self-hosted app behind on releases — which no
	 * schema describes and which arrives without warning.
	 *
	 * @return void
	 */
	public function test_an_absent_field_is_not_a_withdrawal() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );

		$this->assertFalse( $this->plugin->connect->reconcile_content_push( $this->projects() ) );
		$this->assertTrue( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * A list this site's own project is not in says nothing about this site,
	 * and acting on nothing would revoke a working connection.
	 *
	 * @return void
	 */
	public function test_a_list_without_this_project_changes_nothing() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );

		$others = array(
			array(
				'publicKey'   => 'pk_somebody_else',
				'domain'      => 'other.example',
				'contentPush' => false,
			),
		);

		$this->assertFalse( $this->plugin->connect->reconcile_content_push( $others ) );
		$this->assertTrue( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * A bad afternoon on the network is not a withdrawal of consent.
	 *
	 * @return void
	 */
	public function test_a_transport_failure_never_switches_pushes_off() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );
		$this->http->queue_error( 'config' );

		$this->assertFalse( $this->plugin->connect->refresh_content_push() );
		$this->assertTrue( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * A site with pushes switched off has nothing to reconcile and spends no
	 * request doing it — which is most sites, since off is the default.
	 *
	 * @return void
	 */
	public function test_a_site_without_pushes_spends_no_request() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => false ) );

		$this->assertFalse( $this->plugin->connect->refresh_content_push() );
		$this->assertSame( 0, $this->http->count() );
	}

	/**
	 * The daily sync is where a withdrawal made at CiteCue is noticed, so a
	 * stale switch corrects itself within a day instead of waiting for somebody
	 * to press Test connection.
	 *
	 * @return void
	 */
	public function test_the_daily_sync_reconciles_consent() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );
		$this->http->queue(
			'crawlers',
			200,
			wp_json_encode(
				array(
					'version' => 1,
					'tokens'  => array( 'GPTBot' ),
				)
			)
		);
		$this->http->queue( 'config', 200, wp_json_encode( array( 'projects' => $this->projects( array( 'contentPush' => false ) ) ) ) );

		$this->plugin->daily_sync();

		$this->assertFalse( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * The plugin's OWN claim is the one response where an omitted `ingest` is a
	 * definite "the customer did not tick the box" rather than "not mentioned":
	 * it sent a secret and asked, and the contract omits the flag rather than
	 * sending false. Leaving the switch alone would keep a site reading
	 * "Accepted" after a reconnect that withdrew consent.
	 *
	 * @return void
	 */
	public function test_a_claim_that_omits_consent_switches_pushes_off() {
		$this->plugin->settings->update( array( 'ingest_enabled' => true ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$this->assertFalse( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
	}

	/**
	 * And a claim that grants consent turns it back on, clearing the
	 * explanation left by any earlier withdrawal.
	 *
	 * @return void
	 */
	public function test_a_claim_that_grants_consent_switches_pushes_on() {
		$this->plugin->settings->update(
			array(
				'ingest_enabled'          => false,
				'content_push_revoked_at' => time() - DAY_IN_SECONDS,
			)
		);
		$this->http->queue( 'connect', 200, $this->claim_payload( array( 'ingest' => true ) ) );

		$this->connect->claim( 'one-time-code' );

		$this->assertTrue( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
		$this->assertSame( 0, (int) $this->plugin->settings->get( 'content_push_revoked_at' ) );
	}

	/**
	 * A project list carrying this site's own key, for the consent reconcile.
	 *
	 * @param array $extra Extra keys on the entry (omit contentPush entirely to
	 *                     stand in for a CiteCue that predates the field).
	 * @return array
	 */
	private function projects( array $extra = array() ) {
		return array(
			array_merge(
				array(
					'publicKey' => (string) $this->plugin->settings->get( 'public_key' ),
					'domain'    => 'example.org',
					'enabled'   => true,
				),
				$extra
			),
		);
	}

	/**
	 * An install that connected before this release injects enriched metadata
	 * while CiteCue still reports the channel as unable to. That disagreement
	 * is exactly what the admin prompt exists to catch.
	 *
	 * @return void
	 */
	public function test_a_connection_predating_the_capability_asks_for_a_reconnect() {
		$this->configure_delivery();
		$this->plugin->settings->update( array( 'seo_head_reported' => null ) );

		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * Turning injection off after connecting is the same disagreement in the
	 * other direction — over-claiming — and is worth the same prompt.
	 *
	 * @return void
	 */
	public function test_switching_injection_off_after_connecting_asks_for_a_reconnect() {
		$this->configure_delivery();
		$this->plugin->settings->update(
			array(
				'seo_head_reported' => true,
				'seo_head_enabled'  => false,
			)
		);

		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * An unconnected site has nothing to reconcile and must not be nagged.
	 *
	 * @return void
	 */
	public function test_an_unconnected_site_is_never_asked_to_reconnect() {
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
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

	/**
	 * Re-enables the capability these cases are about (set_up opts out so the
	 * older cases keep their exact sets).
	 *
	 * @return void
	 */
	private function opt_in_to_language_pages() {
		$this->plugin->settings->update( array( 'language_pages_enabled' => true ) );
	}

	/**
	 * Plugin 1.4's first connect declares `language_pages` although the local
	 * content-push switch is still off: the plugin only learns consent from the
	 * claim response, so gating on it here would make the first connect unable
	 * to declare. The server enforces consent.
	 *
	 * @return void
	 */
	public function test_the_first_connect_declares_language_pages() {
		if ( 'wpml' === Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'WPML mode does not declare until it is enabled.' );
		}
		$this->opt_in_to_language_pages();
		$this->assertFalse( (bool) $this->plugin->settings->get( 'ingest_enabled' ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertTrue( $sent['language_pages'] );
		$this->assertContains( 'language_pages', $this->plugin->settings->get( 'capabilities_reported' ) );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * The opt-out checkbox withdraws the capability from the claim.
	 *
	 * @return void
	 */
	public function test_the_opt_out_removes_language_pages() {
		$this->opt_in_to_language_pages();
		$this->plugin->settings->update( array( 'language_pages_enabled' => false ) );
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertFalse( $sent['language_pages'] );
		$this->assertArrayNotHasKey( 'translation_languages', $sent );
		$this->assertNotContains( 'language_pages', $this->plugin->settings->get( 'capabilities_reported' ) );
		$this->assertNull( $this->plugin->settings->get( 'translation_languages_reported' ) );
	}

	/**
	 * Plain mode sends no list, so CiteCue accepts any of its languages.
	 *
	 * @return void
	 */
	public function test_plain_mode_sends_no_language_list() {
		if ( 'plain' !== Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'Plain mode only.' );
		}
		$this->opt_in_to_language_pages();
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertTrue( $sent['language_pages'] );
		$this->assertArrayNotHasKey( 'translation_languages', $sent );
		$this->assertNull( $this->plugin->settings->get( 'translation_languages_reported' ) );
	}

	/**
	 * Polylang mode sends the codes that map to one language, and records them.
	 *
	 * @return void
	 */
	public function test_polylang_mode_sends_and_records_the_list() {
		if ( ! getenv( 'CITECUE_WITH_POLYLANG' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_WITH_POLYLANG=1.' );
		}
		$this->opt_in_to_language_pages();
		$this->http->queue( 'connect', 200, $this->claim_payload() );

		$this->connect->claim( 'one-time-code' );

		$sent = json_decode( $this->http->last( 'connect' )['args']['body'], true );
		$this->assertSame( array( 'en', 'pl' ), $sent['translation_languages'] );
		$this->assertSame( array( 'en', 'pl' ), $this->plugin->settings->get( 'translation_languages_reported' ) );
		$this->assertFalse( $this->plugin->settings->needs_seo_head_reconnect() );
	}

	/**
	 * A failed claim records no list.
	 *
	 * @return void
	 */
	public function test_a_failed_claim_records_no_language_list() {
		$this->opt_in_to_language_pages();
		$this->plugin->settings->update( array( 'translation_languages_reported' => null ) );
		$this->http->queue( 'connect', 400, wp_json_encode( array( 'error' => 'invalid_code' ) ) );

		$this->connect->claim( 'nonsense' );

		$this->assertNull( $this->plugin->settings->get( 'translation_languages_reported' ) );
	}

	/**
	 * Disconnecting forgets the list with the other reported state.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_the_reported_list() {
		$this->plugin->settings->update( array( 'translation_languages_reported' => array( 'en' ) ) );

		$this->connect->disconnect();

		$this->assertNull( $this->plugin->settings->get( 'translation_languages_reported' ) );
	}

	/**
	 * A site whose language list moved since connecting is asked to reconnect,
	 * and one whose list only came back in another order is not.
	 *
	 * @return void
	 */
	public function test_a_changed_list_prompts_a_reconnect_but_a_reordered_one_does_not() {
		if ( ! getenv( 'CITECUE_WITH_POLYLANG' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_WITH_POLYLANG=1.' );
		}
		$this->opt_in_to_language_pages();
		$this->http->queue( 'connect', 200, $this->claim_payload() );
		$this->connect->claim( 'one-time-code' );
		$this->assertSame( '', $this->plugin->settings->seo_head_reconnect_reason() );

		$this->plugin->settings->update( array( 'translation_languages_reported' => array( 'pl', 'en' ) ) );
		$this->assertSame( '', $this->plugin->settings->seo_head_reconnect_reason() );

		$this->plugin->settings->update( array( 'translation_languages_reported' => array( 'en' ) ) );
		$this->assertSame( 'languages', $this->plugin->settings->seo_head_reconnect_reason() );
	}

	/**
	 * Switching between plain and a translation plugin changes the list from
	 * absent to present (or back), which is a change.
	 *
	 * @return void
	 */
	public function test_a_plain_to_translator_switch_prompts_a_reconnect() {
		$this->opt_in_to_language_pages();
		$this->configure_delivery( array( 'seo_head_reported' => true ) );
		$this->plugin->settings->update( array( 'capabilities_reported' => $this->plugin->settings->active_delivery_capabilities() ) );

		if ( 'plain' === Citecue_Languages::mode() ) {
			// Connected under Polylang, now plain: reported a list, current has none.
			$this->plugin->settings->update( array( 'translation_languages_reported' => array( 'en', 'pl' ) ) );
		} else {
			// Connected plain, now under a translator: reported none.
			$this->plugin->settings->update( array( 'translation_languages_reported' => null ) );
		}
		if ( 'wpml' === Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'WPML mode does not declare yet, so it has no list.' );
		}

		$this->assertSame( 'languages', $this->plugin->settings->seo_head_reconnect_reason() );
	}
}
