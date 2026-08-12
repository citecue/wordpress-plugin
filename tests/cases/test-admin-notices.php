<?php
/**
 * Where the plugin's admin notices are allowed to appear.
 *
 * Guideline 11 of the plugin directory asks that notices stay limited in scope,
 * and the failure mode it guards against is invisible from the code that emits
 * one: every notice looks reasonable on the screen it was written for. These
 * tests pin the boundary instead — the two screens that are the plugin's own,
 * and the whole rest of the dashboard, which is not.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Admin
 */
class Test_Citecue_Admin_Notices extends Citecue_Test_Case {

	/**
	 * Admin screen under test.
	 *
	 * @var Citecue_Admin
	 */
	private $admin;

	/**
	 * The administrator viewing the dashboard.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->admin   = new Citecue_Admin( $this->plugin );
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['current_screen'] = null;
		parent::tear_down();
	}

	/**
	 * Everything the notices print while pretending to be on one screen.
	 *
	 * @param string $screen_id Screen to render as, e.g. 'dashboard'.
	 * @return string
	 */
	private function notices_on( $screen_id ) {
		set_current_screen( $screen_id );

		ob_start();
		$this->admin->notices();
		return (string) ob_get_clean();
	}

	/**
	 * Puts the site in the state that raises the auth-failure notice.
	 *
	 * @return void
	 */
	private function reject_the_key() {
		$this->configure_delivery();
		update_option( 'citecue_auth_failed', time() );
	}

	/**
	 * Puts the site in the state that raises the reconnect notice: connected,
	 * injecting metadata, and CiteCue never told.
	 *
	 * @return void
	 */
	private function owe_a_reconnect() {
		$this->configure_delivery(
			array(
				'seo_head_enabled'  => true,
				'seo_head_reported' => false,
			)
		);
	}

	/**
	 * @return void
	 */
	public function test_the_auth_failure_reaches_the_settings_screen() {
		$this->reject_the_key();

		$this->assertStringContainsString( 'the API key was rejected', $this->notices_on( 'settings_page_citecue' ) );
	}

	/**
	 * The Plugins screen is where an administrator goes when a plugin needs
	 * attention, so it is in scope even though it is not the plugin's page.
	 *
	 * @return void
	 */
	public function test_the_auth_failure_reaches_the_plugins_screen() {
		$this->reject_the_key();

		$this->assertStringContainsString( 'the API key was rejected', $this->notices_on( 'plugins' ) );
	}

	/**
	 * The one that mattered: an error condition that persists until someone
	 * fixes it used to print on every admin screen there is.
	 *
	 * @dataProvider unrelated_screens
	 * @param string $screen_id Screen that is none of the plugin's business.
	 * @return void
	 */
	public function test_no_notice_follows_the_administrator_elsewhere( $screen_id ) {
		$this->reject_the_key();
		$this->owe_a_reconnect();

		$this->assertSame( '', $this->notices_on( $screen_id ) );
	}

	/**
	 * Screens the plugin has nothing to say on.
	 *
	 * @return array<string, array{string}>
	 */
	public function unrelated_screens() {
		return array(
			'dashboard'    => array( 'dashboard' ),
			'post editor'  => array( 'post' ),
			'media'        => array( 'upload' ),
			'comments'     => array( 'edit-comments' ),
			'users'        => array( 'users' ),
			'other plugin' => array( 'settings_page_some-other-plugin' ),
		);
	}

	/**
	 * @return void
	 */
	public function test_the_reconnect_notice_reaches_the_plugin_screens() {
		$this->owe_a_reconnect();

		$this->assertStringContainsString( 'Reconnect to CiteCue', $this->notices_on( 'plugins' ) );
	}

	/**
	 * @return void
	 */
	public function test_the_reconnect_notice_offers_a_way_out() {
		$this->owe_a_reconnect();

		$this->assertStringContainsString( 'citecue_dismiss=seo_head_reconnect', $this->notices_on( 'settings_page_citecue' ) );
	}

	/**
	 * Dismissed means dismissed: the condition is still true, and the notice
	 * still does not come back.
	 *
	 * @return void
	 */
	public function test_a_dismissed_reconnect_notice_stays_dismissed() {
		$this->owe_a_reconnect();
		update_user_option( $this->user_id, Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', time() );

		$html = $this->notices_on( 'settings_page_citecue' );

		$this->assertTrue( $this->plugin->settings->needs_seo_head_reconnect() );
		$this->assertStringNotContainsString( 'Reconnect to CiteCue', $html );
	}

	/**
	 * One administrator's decision is not made on their colleagues' behalf.
	 *
	 * @return void
	 */
	public function test_a_dismissal_belongs_to_the_user_who_made_it() {
		$this->owe_a_reconnect();
		update_user_option( $this->user_id, Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', time() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( 'Reconnect to CiteCue', $this->notices_on( 'settings_page_citecue' ) );
	}

	/**
	 * A subscriber who reaches an admin screen is told nothing.
	 *
	 * @return void
	 */
	public function test_a_user_who_cannot_manage_options_sees_nothing() {
		$this->reject_the_key();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->notices_on( 'settings_page_citecue' ) );
	}

	/**
	 * The dismissal is a state change, so it is behind a nonce.
	 *
	 * @return void
	 */
	public function test_the_dismissal_link_carries_a_nonce() {
		$this->owe_a_reconnect();

		$this->assertStringContainsString( '_wpnonce', $this->notices_on( 'plugins' ) );
	}

	/**
	 * Requests a dismissal the way the link does.
	 *
	 * The handler redirects and exits, so the redirect is turned into an
	 * exception: what happened before it is the whole subject of these tests.
	 * A refusal throws too — wp_die() does, under the test suite — and that one
	 * is the caller's to see, so it is passed straight back out.
	 *
	 * @param string $notice Notice key to dismiss.
	 * @param string $nonce  Nonce to present.
	 * @return void
	 * @throws WPDieException When the request is refused.
	 */
	private function request_dismissal( $notice, $nonce ) {
		$_GET['citecue_dismiss'] = $notice;
		$_REQUEST['_wpnonce']    = $nonce;

		add_filter(
			'wp_redirect',
			static function () {
				throw new Exception( 'redirected' );
			}
		);

		try {
			$this->admin->maybe_dismiss_notice();
		} catch ( Exception $e ) {
			if ( $e instanceof WPDieException ) {
				throw $e;
			}
		} finally {
			unset( $_GET['citecue_dismiss'], $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * @return void
	 */
	public function test_a_signed_dismissal_is_recorded() {
		$this->owe_a_reconnect();

		$this->request_dismissal( 'seo_head_reconnect', wp_create_nonce( 'citecue_dismiss_seo_head_reconnect' ) );

		$this->assertNotEmpty( get_user_option( Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', $this->user_id ) );
	}

	/**
	 * The usermeta table is shared across a whole multisite network, while the
	 * condition this notice reports comes from a per-site option — so the key
	 * has to carry this site's prefix, or dismissing it on one site in a
	 * network silences a still-true prompt on all the others.
	 *
	 * Asserting on the stored key rather than switching blogs, because that is
	 * the part a refactor back to update_user_meta() would quietly undo, and it
	 * is checkable on a single-site install.
	 *
	 * @return void
	 */
	public function test_a_dismissal_is_scoped_to_this_site() {
		global $wpdb;
		$this->owe_a_reconnect();

		$this->request_dismissal( 'seo_head_reconnect', wp_create_nonce( 'citecue_dismiss_seo_head_reconnect' ) );

		$this->assertNotEmpty(
			get_user_meta( $this->user_id, $wpdb->get_blog_prefix() . Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', true ),
			'The dismissal should be stored under this blog’s prefix.'
		);
		$this->assertEmpty(
			get_user_meta( $this->user_id, Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', true ),
			'Nothing should be stored under a network-wide key.'
		);
	}

	/**
	 * Without a valid nonce this is a link someone else can put in front of an
	 * administrator, so it has to die rather than write anything.
	 *
	 * @return void
	 */
	public function test_an_unsigned_dismissal_is_refused() {
		$this->owe_a_reconnect();

		$this->expectException( 'WPDieException' );
		try {
			$this->request_dismissal( 'seo_head_reconnect', 'not-a-nonce' );
		} finally {
			$this->assertEmpty( get_user_option( Citecue_Admin::DISMISSED_OPTION_PREFIX . 'seo_head_reconnect', $this->user_id ) );
		}
	}

	/**
	 * The notice key is part of the nonce action, but it also reaches
	 * update_user_meta() — so only keys the plugin actually issues are honoured.
	 *
	 * @return void
	 */
	public function test_an_unknown_notice_key_writes_nothing() {
		$this->request_dismissal( 'made_up', wp_create_nonce( 'citecue_dismiss_made_up' ) );

		$this->assertEmpty( get_user_option( Citecue_Admin::DISMISSED_OPTION_PREFIX . 'made_up', $this->user_id ) );
	}
}
