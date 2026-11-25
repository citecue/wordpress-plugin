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

		// These cases pin the other capabilities' exact sets; the language
		// capability has its own cases (test-languages.php, below), and would
		// otherwise join every set in plain and Polylang mode.
		$this->plugin->settings->update( array( 'language_pages_enabled' => false ) );
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
	 * The key-entry fallback stores the submitted key before testing it, so a
	 * key CiteCue has just rejected is still on disk. Only CiteCue's own
	 * answer — a project, or the cached project list — proves a connection.
	 *
	 * @return void
	 */
	public function test_a_stored_key_alone_is_not_a_connection() {
		$this->settings->update( array( 'api_key' => 'ck_live_rejected' ) );

		$this->assertFalse( $this->settings->is_connected() );
	}

	/**
	 * @return void
	 */
	public function test_a_selected_project_makes_it_a_connection() {
		$this->settings->update(
			array(
				'api_key'    => 'ck_live_works',
				'public_key' => 'pk_one',
			)
		);

		$this->assertTrue( $this->settings->is_connected() );
	}

	/**
	 * A working key whose org has no project for this domain still connected —
	 * choosing the project is the next step, not a reason to start over.
	 *
	 * @return void
	 */
	public function test_a_cached_project_list_makes_it_a_connection() {
		$this->settings->update( array( 'api_key' => 'ck_live_works' ) );
		update_option( 'citecue_projects_cache', array( array( 'publicKey' => 'pk_elsewhere' ) ) );

		$this->assertTrue( $this->settings->is_connected() );
	}

	/**
	 * An empty cache is what a failed config call leaves behind, and must not
	 * read as success.
	 *
	 * @return void
	 */
	public function test_an_empty_project_list_is_not_a_connection() {
		$this->settings->update( array( 'api_key' => 'ck_live_rejected' ) );
		update_option( 'citecue_projects_cache', array() );

		$this->assertFalse( $this->settings->is_connected() );
	}

	/**
	 * @return void
	 */
	public function test_no_key_is_never_a_connection() {
		update_option( 'citecue_projects_cache', array( array( 'publicKey' => 'pk_one' ) ) );

		$this->assertFalse( $this->settings->is_connected() );
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
	 * Cached bodies, llms.txt and head blocks all key off the cache salt and
	 * the URL, and NOT off the project — so repointing the site at another
	 * CiteCue project would keep serving the previous project's content under
	 * the new one's name, onto live pages, for up to a day.
	 *
	 * @return void
	 */
	public function test_changing_the_project_flushes_every_cache() {
		$url = home_url( '/hello-world/' );
		$this->settings->update( array( 'public_key' => 'pk_old' ) );
		$this->plugin->cache->set_page( $url, '<html>old project</html>', '"v1"', 'enriched' );
		$this->plugin->cache->set_seo_head( $url, '<title>old project</title>' );
		$this->plugin->cache->set_llms_txt( '# old project', '"v1"' );

		$this->settings->sanitize( array( 'public_key' => 'pk_new' ) );

		$this->assertNull( $this->plugin->cache->get_page( $url ) );
		$this->assertNull( $this->plugin->cache->get_seo_head( $url ) );
		$this->assertNull( $this->plugin->cache->get_llms_txt() );
	}

	/**
	 * Re-saving the same project must not throw the site's whole cache away.
	 *
	 * @return void
	 */
	public function test_an_unchanged_project_keeps_the_cache() {
		$url = home_url( '/hello-world/' );
		$this->settings->update( array( 'public_key' => 'pk_same' ) );
		$this->plugin->cache->set_seo_head( $url, '<title>still valid</title>' );

		$this->settings->sanitize( array( 'public_key' => 'pk_same' ) );

		$this->assertNotNull( $this->plugin->cache->get_seo_head( $url ) );
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

	/**
	 * `publish_live` tells CiteCue it may land a push as published rather than
	 * as a draft. It is derived from the cap that enforces the same thing on
	 * this site, so it can never claim more than the ingest endpoint allows:
	 * true only when that cap really is Published.
	 *
	 * @dataProvider status_caps
	 *
	 * @param string $cap      Maximum status.
	 * @param bool   $expected Whether publish_live is declared.
	 * @return void
	 */
	public function test_publish_live_follows_the_maximum_status( $cap, $expected ) {
		$this->settings->update( array( 'ingest_post_status' => $cap ) );

		$declared = $this->settings->declared_capabilities();

		$this->assertArrayHasKey( 'publish_live', $declared );
		$this->assertSame( $expected, $declared['publish_live'] );
	}

	/**
	 * @return array
	 */
	public function status_caps() {
		return array(
			'published' => array( 'publish', true ),
			'draft'     => array( 'draft', false ),
			'pending'   => array( 'pending', false ),
		);
	}

	/**
	 * The default cap is Draft, so a fresh install never declares it.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_does_not_declare_publish_live() {
		$this->assertFalse( $this->settings->declared_capabilities()['publish_live'] );
	}

	/**
	 * It gates what CiteCue sends — the status a Calendar push asks for — so
	 * it belongs in the set the reconnect prompt compares, unlike
	 * `woocommerce`. And it does not ride `seo_head_enabled`: a site with
	 * injection switched off still accepts pushes.
	 *
	 * @return void
	 */
	public function test_publish_live_is_compared_by_the_reconnect_check() {
		$this->settings->update(
			array(
				'ingest_post_status' => 'publish',
				'seo_head_enabled'   => false,
			)
		);

		$this->assertSame( array( 'publish_live' ), $this->settings->active_delivery_capabilities() );
	}

	/**
	 * A 1.4.0 site upgrading into `language_pages` is told about language
	 * versions, not page enhancements; any other stale set stays `capabilities`.
	 *
	 * @return void
	 */
	public function test_a_stale_language_pages_capability_has_its_own_reason() {
		$this->settings->update(
			array(
				'language_pages_enabled' => true,
				'api_key'                => self::API_KEY,
				'public_key'             => self::PUBLIC_KEY,
				'seo_head_enabled'       => true,
				'seo_head_reported'      => true,
			)
		);
		$active = $this->settings->active_delivery_capabilities();
		if ( ! in_array( 'language_pages', $active, true ) ) {
			$this->markTestSkipped( 'This mode does not declare language_pages.' );
		}

		$without = array_values( array_diff( $active, array( 'language_pages' ) ) );
		$this->settings->update( array( 'capabilities_reported' => $without ) );
		$this->assertSame( 'language_pages', $this->settings->seo_head_reconnect_reason() );

		// Together with publish_live it is still the language message.
		$this->settings->update(
			array(
				'ingest_post_status'    => 'publish',
				'capabilities_reported' => array_values( array_diff( $this->settings->active_delivery_capabilities(), array( 'language_pages', 'publish_live' ) ) ),
			)
		);
		$this->assertSame( 'language_pages', $this->settings->seo_head_reconnect_reason() );

		// Another stale capability keeps the page-enhancements reason.
		$this->settings->update( array( 'capabilities_reported' => array() ) );
		$this->assertSame( 'capabilities', $this->settings->seo_head_reconnect_reason() );
	}

	/**
	 * The opt-out is on by default, and a form that does not post it leaves it
	 * alone (the API-key form owns only the connection fields).
	 *
	 * @return void
	 */
	public function test_language_pages_default_on_and_survive_a_form_that_omits_them() {
		$this->assertTrue( Citecue_Settings::defaults()['language_pages_enabled'] );
		$this->assertNull( Citecue_Settings::defaults()['translation_languages_reported'] );

		$this->settings->update( array( 'language_pages_enabled' => true ) );
		$out = $this->settings->sanitize( array( 'serve_enabled' => '1' ) );

		$this->assertTrue( $out['language_pages_enabled'] );
	}

	/**
	 * The settings checkbox posts a hidden 0 beside it, so unticking works.
	 *
	 * @return void
	 */
	public function test_unticking_language_pages_is_saved() {
		$out = $this->settings->sanitize( array( 'language_pages_enabled' => '0' ) );
		$this->assertFalse( $out['language_pages_enabled'] );

		$out = $this->settings->sanitize( array( 'language_pages_enabled' => '1' ) );
		$this->assertTrue( $out['language_pages_enabled'] );
	}

	/**
	 * The reported list is normalized to a sorted list, with null kept.
	 *
	 * @return void
	 */
	public function test_the_reported_language_list_is_sorted_and_nullable() {
		$out = $this->settings->sanitize( array( 'translation_languages_reported' => array( 'pl', 'en', '' ) ) );
		$this->assertSame( array( 'en', 'pl' ), $out['translation_languages_reported'] );

		$out = $this->settings->sanitize( array( 'translation_languages_reported' => null ) );
		$this->assertNull( $out['translation_languages_reported'] );
	}

	/**
	 * The "Language pages" checkbox sits on the connected settings screen
	 * where the language setup can honour it.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_offers_the_language_pages_checkbox() {
		if ( ! Citecue_Languages::declarable() ) {
			$this->markTestSkipped( 'Not declarable in this mode.' );
		}
		$this->configure_delivery();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new Citecue_Admin( $this->plugin ) )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Language pages', $html );
		$this->assertStringContainsString( '[language_pages_enabled]', $html );
	}
}
