<?php
/**
 * Settings sanitation.
 *
 * Every write to the option — the settings form *and* the plugin's own
 * internal updates — is routed through this callback by register_setting(), so
 * a field it forgets is a field that silently never persists.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Settings
 */
class Test_Citecue_Settings extends Citecue_Test_Case {

	/**
	 * Settings under test.
	 *
	 * @var Citecue_Settings
	 */
	private $settings;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->settings = new Citecue_Settings();
	}

	/**
	 * Serving is on by default, ingest is not — pushing content into someone's
	 * site is opt-in.
	 *
	 * @return void
	 */
	public function test_defaults_are_safe() {
		$defaults = Citecue_Settings::defaults();

		$this->assertTrue( $defaults['serve_enabled'] );
		$this->assertTrue( $defaults['llms_txt_enabled'] );
		$this->assertFalse( $defaults['ingest_enabled'] );
		$this->assertSame( 'draft', $defaults['ingest_post_status'] );
		$this->assertSame( '', $defaults['api_key'] );
		$this->assertSame( '', $defaults['ingest_secret'] );
	}

	/**
	 * @return void
	 */
	public function test_delivery_needs_both_keys() {
		$this->assertFalse( $this->settings->is_delivery_configured() );

		$this->settings->update( array( 'api_key' => 'ck_live_x' ) );
		$this->assertFalse( $this->settings->is_delivery_configured() );

		$this->settings->update( array( 'public_key' => 'pk_x' ) );
		$this->assertTrue( $this->settings->is_delivery_configured() );
	}

	/**
	 * Re-saving the settings form posts an empty key field (the real key is
	 * never rendered), so an empty value must mean "unchanged", not "erase".
	 *
	 * @return void
	 */
	public function test_an_empty_api_key_field_keeps_the_stored_key() {
		$this->settings->update( array( 'api_key' => 'ck_live_keepme' ) );

		$out = $this->settings->sanitize( array( 'api_key' => '' ) );

		$this->assertSame( 'ck_live_keepme', $out['api_key'] );
	}

	/**
	 * Clearing it is possible, but only explicitly.
	 *
	 * @return void
	 */
	public function test_the_api_key_can_be_cleared_explicitly() {
		$this->settings->update( array( 'api_key' => 'ck_live_keepme' ) );

		$out = $this->settings->sanitize( array( 'api_key_clear' => '1' ) );

		$this->assertSame( '', $out['api_key'] );
	}

	/**
	 * A new key may fix a previous auth failure, so the back-off must lift
	 * immediately rather than locking serving out for another ten minutes.
	 *
	 * @return void
	 */
	public function test_changing_the_api_key_clears_the_auth_backoff() {
		$this->settings->update( array( 'api_key' => 'ck_live_old' ) );
		update_option( 'citecue_auth_failed', time() );
		$this->plugin->cache->trip_circuit( Citecue_Cache::AUTH_CIRCUIT_TTL );

		$this->settings->sanitize( array( 'api_key' => 'ck_live_new' ) );

		$this->assertFalse( get_option( 'citecue_auth_failed' ) );
		$this->assertFalse( $this->plugin->cache->is_circuit_open() );
	}

	/**
	 * @return void
	 */
	public function test_an_unchanged_api_key_leaves_the_backoff_alone() {
		$this->settings->update( array( 'api_key' => 'ck_live_same' ) );
		update_option( 'citecue_auth_failed', time() );

		$this->settings->sanitize( array( 'api_key' => 'ck_live_same' ) );

		$this->assertNotFalse( get_option( 'citecue_auth_failed' ) );
	}

	/**
	 * Checkboxes are absent from the POST when unticked.
	 *
	 * @return void
	 */
	public function test_absent_checkboxes_mean_off() {
		$this->settings->update(
			array(
				'serve_enabled'    => true,
				'llms_txt_enabled' => true,
				'ingest_enabled'   => true,
			)
		);

		$out = $this->settings->sanitize( array() );

		$this->assertFalse( $out['serve_enabled'] );
		$this->assertFalse( $out['llms_txt_enabled'] );
		$this->assertFalse( $out['ingest_enabled'] );
	}

	/**
	 * @return void
	 */
	public function test_an_invalid_status_cap_is_ignored() {
		$out = $this->settings->sanitize( array( 'ingest_post_status' => 'publish_everything' ) );

		$this->assertSame( 'draft', $out['ingest_post_status'] );
	}

	/**
	 * @return void
	 */
	public function test_a_valid_status_cap_is_stored() {
		$out = $this->settings->sanitize( array( 'ingest_post_status' => 'pending' ) );

		$this->assertSame( 'pending', $out['ingest_post_status'] );
	}

	/**
	 * @return void
	 */
	public function test_product_is_not_a_valid_type_without_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce (or its stub) is loaded.' );
		}

		$out = $this->settings->sanitize( array( 'ingest_post_type' => 'product' ) );

		$this->assertSame( 'post', $out['ingest_post_type'] );
	}

	/**
	 * The plugin's own writes (a regenerated ingest secret, a project domain
	 * resolved from the API) go through the same callback, so they have to
	 * survive it.
	 *
	 * @return void
	 */
	public function test_internal_fields_pass_through_sanitation() {
		$out = $this->settings->sanitize(
			array(
				'ingest_secret'  => 'cws_generated_internally',
				'project_domain' => 'example.org',
			)
		);

		$this->assertSame( 'cws_generated_internally', $out['ingest_secret'] );
		$this->assertSame( 'example.org', $out['project_domain'] );
	}

	/**
	 * Selecting a project fills in its domain from the cached project list, so
	 * the settings screen can show which site the key is pointed at.
	 *
	 * @return void
	 */
	public function test_selecting_a_project_fills_in_its_domain() {
		update_option(
			'citecue_projects_cache',
			array(
				array(
					'publicKey' => 'pk_one',
					'domain'    => 'one.example',
				),
				array(
					'publicKey' => 'pk_two',
					'domain'    => 'two.example',
				),
			)
		);

		$out = $this->settings->sanitize( array( 'public_key' => 'pk_two' ) );

		$this->assertSame( 'two.example', $out['project_domain'] );
	}

	/**
	 * @return void
	 */
	public function test_clearing_the_project_clears_its_domain() {
		$this->settings->update( array( 'project_domain' => 'one.example' ) );

		$out = $this->settings->sanitize( array( 'public_key' => '' ) );

		$this->assertSame( '', $out['project_domain'] );
	}

	/**
	 * @return void
	 */
	public function test_the_api_base_falls_back_to_the_default() {
		$out = $this->settings->sanitize( array( 'api_base' => '' ) );

		$this->assertSame( Citecue_Settings::DEFAULT_API_BASE, $out['api_base'] );
	}

	/**
	 * @return void
	 */
	public function test_the_api_base_loses_its_trailing_slash() {
		$out = $this->settings->sanitize( array( 'api_base' => 'https://staging.citecue.test/' ) );

		$this->assertSame( 'https://staging.citecue.test', $out['api_base'] );
	}

	/**
	 * A stored base with a trailing slash would produce double-slashed
	 * endpoints, so the accessor normalizes it too.
	 *
	 * @return void
	 */
	public function test_the_api_base_accessor_normalizes_a_stored_slash() {
		$this->settings->update( array( 'api_base' => 'https://staging.citecue.test/' ) );

		$this->assertSame( 'https://staging.citecue.test', $this->settings->api_base() );
	}

	/**
	 * @return void
	 */
	public function test_an_ingest_secret_is_generated_once_and_kept() {
		$first = $this->settings->ensure_ingest_secret();

		$this->assertStringStartsWith( 'cws_', $first );
		$this->assertSame( $first, $this->settings->ensure_ingest_secret() );
	}
}
