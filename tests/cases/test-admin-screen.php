<?php
/**
 * The settings screen's two shapes.
 *
 * The screen renders one thing before the site is paired and another after, so
 * these tests exist mostly to prove both templates run at all — a stray
 * undefined variable in a branch nobody exercises is the classic way an admin
 * page dies. Beyond that they pin the intent: setup is one button, and the
 * fields that only matter while connecting stay out of the way afterwards.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Admin
 */
class Test_Citecue_Admin_Screen extends Citecue_Test_Case {

	/**
	 * Admin screen under test.
	 *
	 * @var Citecue_Admin
	 */
	private $admin;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->admin = new Citecue_Admin( $this->plugin );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The rendered settings page.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		$this->admin->render_page();
		return (string) ob_get_clean();
	}

	/**
	 * @return void
	 */
	public function test_setup_leads_with_the_one_click_connect() {
		$html = $this->render();

		$this->assertStringContainsString( 'citecue_connect_start', $html );
		$this->assertStringContainsString( 'Connect to CiteCue', $html );
	}

	/**
	 * Everything an operator adjusts on a running site is noise on a site that
	 * has not been connected yet.
	 *
	 * @return void
	 */
	public function test_setup_hides_the_running_site_settings() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'ingest_post_status', $html );
		$this->assertStringNotContainsString( 'Recent AI crawler activity', $html );
	}

	/**
	 * The key route has to survive for installs that cannot bounce a browser
	 * through CiteCue, but as a fallback rather than the front door.
	 *
	 * @return void
	 */
	public function test_setup_keeps_the_api_key_route_available() {
		$html = $this->render();

		$this->assertStringContainsString( 'citecue_api_key', $html );
		$this->assertStringContainsString( 'Connect with an API key instead', $html );
	}

	/**
	 * The fallback form renders no delivery checkboxes, and sanitize() reads
	 * an absent checkbox as "off" — so submitting it would silently stop the
	 * site serving anything unless the values it does not render travel with
	 * it. Driving the rendered fields through sanitize() is the only way to
	 * catch that; asserting on the markup alone would miss a rename.
	 *
	 * @return void
	 */
	public function test_the_api_key_fallback_does_not_switch_delivery_off() {
		$this->plugin->settings->update(
			array(
				'serve_enabled'    => true,
				'llms_txt_enabled' => true,
			)
		);

		$posted            = $this->posted_fields( $this->render() );
		$posted['api_key'] = 'ck_live_pasted';
		$out               = $this->plugin->settings->sanitize( $posted );

		$this->assertTrue( $out['serve_enabled'] );
		$this->assertTrue( $out['llms_txt_enabled'] );
	}

	/**
	 * An off toggle must stay off, rather than being resurrected by a
	 * blanket hidden field.
	 *
	 * @return void
	 */
	public function test_the_api_key_fallback_does_not_switch_ingest_on() {
		$posted = $this->posted_fields( $this->render() );

		$out = $this->plugin->settings->sanitize( $posted );

		$this->assertFalse( $out['ingest_enabled'] );
	}

	/**
	 * The `citecue_settings[…]` fields a rendered form would submit, as the
	 * nested array sanitize() receives.
	 *
	 * @param string $html Rendered page.
	 * @return array
	 */
	private function posted_fields( $html ) {
		preg_match_all( '/name="citecue_settings\[(\w+)\]"[^>]*value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );

		$fields = array();
		foreach ( $matches as $match ) {
			$fields[ $match[1] ] = $match[2];
		}
		return $fields;
	}

	/**
	 * @return void
	 */
	public function test_a_connected_site_shows_its_status_and_settings() {
		$this->configure_delivery( array( 'project_domain' => 'example.org' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Connected to CiteCue', $html );
		$this->assertStringContainsString( 'example.org', $html );
		$this->assertStringContainsString( 'citecue_verify_install', $html );
		$this->assertStringContainsString( 'ingest_post_status', $html );
		$this->assertStringNotContainsString( 'citecue_connect_start', $html );
	}

	/**
	 * @return void
	 */
	public function test_a_connected_site_reports_the_last_verification() {
		$this->configure_delivery();
		$this->http->queue( 'loopback', 200, 'llms', array( 'x-citecue' => 'llms-txt' ) );
		$this->plugin->connect->verify_install();

		$html = $this->render();

		$this->assertStringContainsString( 'Serving CiteCue headers', $html );
	}

	/**
	 * @return void
	 */
	public function test_a_failed_verification_says_what_went_wrong() {
		$this->configure_delivery();
		$this->http->queue( 'loopback', 200, 'theme output' );
		$this->plugin->connect->verify_install();

		$html = $this->render();

		$this->assertStringContainsString( 'x-citecue', $html );
	}

	/**
	 * Pointing a site at a different CiteCue deployment is a wp-config
	 * decision; offering it as an editable field invites getting it wrong
	 * while pasting a key.
	 *
	 * @return void
	 */
	public function test_a_pinned_api_base_is_shown_but_not_editable() {
		add_filter( 'citecue_pinned_api_base', array( $this, 'pin_api_base' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'https://citecue.internal', $html );
		$this->assertStringNotContainsString( 'name="citecue_settings[api_base]"', $html );
	}

	/**
	 * @return void
	 */
	public function test_an_unpinned_api_base_stays_editable() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="citecue_settings[api_base]"', $html );
	}

	/**
	 * Filter callback pinning the app origin.
	 *
	 * @return string
	 */
	public function pin_api_base() {
		return 'https://citecue.internal';
	}
}
