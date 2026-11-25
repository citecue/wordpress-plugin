<?php
/**
 * Language pages over `/content`: a push carrying `translation_of` files the
 * page as a translation of a main page, in whatever mode the site is in.
 *
 * Runs in all three modes: core (plain), `test:polylang` (real Polylang, en
 * default + pl) and `test:wpml` (the in-memory WPML stand-in, en + pl). Tests
 * that only make sense in one family of modes skip in the others.
 *
 * @package Citecue
 */

/**
 * @covers Citecue_Translations
 * @covers Citecue_Ingest::handle_content
 */
class Test_Citecue_Ingest_Translations extends Citecue_Test_Case {

	const SECRET = 'cws_testsecret0123456789';

	/**
	 * Signing clock offset, so every push in a test gets a fresh signature.
	 *
	 * @var int
	 */
	private $tick = 0;

	/**
	 * WPML stub languages as they were before the test.
	 *
	 * @var array
	 */
	private $wpml_languages;

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

		$this->wpml_languages = Citecue_Wpml_Stub::$languages;
		if ( getenv( 'CITECUE_STUB_WPML' ) ) {
			// WPML's group read. The shared stub does not answer it, so it is
			// answered here from the stub's own element store.
			add_filter(
				'wpml_get_element_translations',
				static function ( $unused, $trid ) {
					unset( $unused );
					$out = array();
					foreach ( Citecue_Wpml_Stub::$elements as $id => $row ) {
						if ( (int) $row['trid'] === (int) $trid ) {
							$out[ $row['language_code'] ] = (object) array(
								'element_id'    => $id,
								'language_code' => $row['language_code'],
							);
						}
					}
					return $out;
				},
				10,
				2
			);
		}
	}

	/**
	 * Restores the stub languages and drops any lock left behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		Citecue_Wpml_Stub::$languages = $this->wpml_languages;
		Citecue_Translations::release_held_locks();
		parent::tear_down();
	}

	// ---------------------------------------------------------------- linking

	/**
	 * @return void
	 */
	public function test_a_sibling_is_created_as_a_linked_translation() {
		$main = $this->push_main( 'main-1' );

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( $data['created'] );
		$this->assertSame( 'pl', $data['language'] );
		$this->assertSame( 'draft', $data['status'] );

		$post = get_post( $data['post_id'] );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'pl', get_post_meta( $post->ID, '_citecue_language', true ) );
		$this->assertSame( (string) $main, get_post_meta( $post->ID, '_citecue_translation_of', true ) );
		$this->assertSame( 'sib-1', get_post_meta( $post->ID, '_citecue_external_id', true ) );
		$this->assertLinked( $main, $post->ID );
	}

	/**
	 * Plain mode: a child page of the main page, slugged by its language code.
	 *
	 * @return void
	 */
	public function test_plain_mode_makes_a_child_page_at_the_language_code() {
		$this->only_plain();
		$main = $this->push_main( 'main-1', array( 'slug' => 'acme-faq' ) );

		$post = get_post( $this->push_sibling( 'sib-1', 'main-1', array( 'slug' => 'ignored' ) )->get_data()['post_id'] );

		$this->assertSame( $main, (int) $post->post_parent );
		$this->assertSame( 'pl', $post->post_name );
	}

	/**
	 * @return void
	 */
	public function test_a_repeat_push_updates_the_same_post() {
		$this->push_main( 'main-1' );
		$first = $this->push_sibling( 'sib-1', 'main-1' );

		$second = $this->push_sibling( 'sib-1', 'main-1', array( 'title' => 'Second' ) );

		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['updated'] );
		$this->assertSame( $first->get_data()['post_id'], $second->get_data()['post_id'] );
		$this->assertSame( 'pl', $second->get_data()['language'] );
		$this->assertSame( 'Second', get_post( $first->get_data()['post_id'] )->post_title );
	}

	/**
	 * The status cap still applies, and a created sibling reaches it after
	 * being linked as a draft.
	 *
	 * @return void
	 */
	public function test_a_created_sibling_moves_to_the_capped_status() {
		$this->plugin->settings->update( array( 'ingest_post_status' => 'publish' ) );
		$this->push_main( 'main-1' );

		$response = $this->push_sibling( 'sib-1', 'main-1', array( 'status' => 'publish' ) );

		$this->assertSame( 'publish', $response->get_data()['status'] );
		$this->assertSame( 'publish', get_post_status( $response->get_data()['post_id'] ) );
	}

	/**
	 * @return void
	 */
	public function test_a_failed_status_move_still_answers_success_as_a_draft() {
		$this->plugin->settings->update( array( 'ingest_post_status' => 'publish' ) );
		$main = $this->push_main( 'main-1' );
		add_filter(
			'wp_insert_post_empty_content',
			static function ( $maybe_empty, $postarr ) {
				return isset( $postarr['post_status'] ) && 'publish' === $postarr['post_status'] ? true : $maybe_empty;
			},
			10,
			2
		);

		$response = $this->push_sibling( 'sib-1', 'main-1', array( 'status' => 'publish' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertLinked( $main, $response->get_data()['post_id'] );
	}

	/**
	 * The main post's language is filled in only when it has none.
	 *
	 * @return void
	 */
	public function test_the_main_language_is_set_only_when_absent() {
		$main = $this->push_main( 'main-1', array( 'language' => null ) );
		$this->strip_language( $main );

		$this->assertSame( 201, $this->push_sibling( 'sib-1', 'main-1' )->get_status() );

		$this->assertSame( 'en', $this->language_code_of( $main ) );
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			$this->assertSame( 'en', get_post_meta( $main, '_citecue_language', true ) );
		}
	}

	/**
	 * Plain mode: a main post with a stored language keeps it, and the stored
	 * language (not `translation_of_language`) is what step 4 compares.
	 *
	 * @return void
	 */
	public function test_plain_mode_keeps_the_main_posts_stored_language() {
		$this->only_plain();
		$main = $this->push_main( 'main-1', array( 'language' => 'de' ) );

		$this->assertSame( 201, $this->push_sibling( 'sib-1', 'main-1', array( 'translation_of_language' => 'en' ) )->get_status() );
		$this->assertSame( 'de', get_post_meta( $main, '_citecue_language', true ) );

		$conflict = $this->push_sibling( 'sib-de', 'main-1', array( 'language' => 'de' ) );
		$this->assertConflict( 'group', $conflict );
	}

	/**
	 * Plain mode accepts any well-formed code: there is no language list.
	 *
	 * @return void
	 */
	public function test_plain_mode_accepts_any_well_formed_code() {
		$this->only_plain();
		$this->push_main( 'main-1' );

		$response = $this->push_sibling( 'sib-de', 'main-1', array( 'language' => 'de' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'de', $response->get_data()['language'] );
	}

	/**
	 * WPML/Polylang compare by language part: a main post in an `en_GB`
	 * language matches `translation_of_language: en`.
	 *
	 * @return void
	 */
	public function test_the_main_language_matches_by_language_part() {
		if ( ! getenv( 'CITECUE_STUB_WPML' ) ) {
			$this->markTestSkipped( 'Needs a site language whose slug is not the code (WPML stub run).' );
		}
		Citecue_Wpml_Stub::$languages = array(
			'en-gb' => array(
				'code'           => 'en-gb',
				'language_code'  => 'en-gb',
				'default_locale' => 'en_GB',
			),
			'pl'    => $this->wpml_languages['pl'],
		);
		$main                         = $this->push_main( 'main-1' );
		$this->assertSame( 'en-gb', Citecue_Languages::post_language( $main ) );

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertLinked( $main, $response->get_data()['post_id'] );
	}

	/**
	 * A sibling a translator edited in WordPress keeps its content, but is
	 * still linked: linking runs before the local-edit check.
	 *
	 * @return void
	 */
	public function test_a_locally_edited_sibling_is_still_linked() {
		$main = $this->push_main( 'main-1' );
		$id   = $this->unlinked_citecue_page( 'sib-1' );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<p>Edited by a translator.</p>',
			)
		);

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertRefused( 409, 'citecue_edited_locally', $response );
		$this->assertStringContainsString( 'Edited by a translator.', get_post( $id )->post_content );
		$this->assertSame( 'pl', get_post_meta( $id, '_citecue_language', true ) );
		$this->assertSame( (string) $main, get_post_meta( $id, '_citecue_translation_of', true ) );
		$this->assertLinked( $main, $id );
	}

	/**
	 * A CiteCue page with no translation meta (a rolled-back plugin pushed it)
	 * is recovered by linking it, not refused.
	 *
	 * @return void
	 */
	public function test_a_post_with_absent_meta_is_linked_not_refused() {
		$main = $this->push_main( 'main-1' );
		$id   = $this->unlinked_citecue_page( 'sib-1' );

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $id, $response->get_data()['post_id'] );
		$this->assertLinked( $main, $id );
	}

	/**
	 * A trashed sibling stays trashed (as for any push), after linking.
	 *
	 * @return void
	 */
	public function test_a_trashed_sibling_stays_trashed() {
		$this->push_main( 'main-1' );
		$id = $this->push_sibling( 'sib-1', 'main-1' )->get_data()['post_id'];
		wp_trash_post( $id );

		$this->assertRefused( 410, 'citecue_trashed', $this->push_sibling( 'sib-1', 'main-1' ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	/**
	 * A trashed, not yet linked sibling is refused before it is linked: the
	 * link would put a trashed page into the live group.
	 *
	 * @return void
	 */
	public function test_a_trashed_unlinked_sibling_is_not_linked() {
		$main = $this->push_main( 'main-1' );
		$id   = $this->unlinked_citecue_page(
			'sib-1',
			array(
				'post_parent' => $main,
				'post_name'   => 'pl',
			)
		);
		wp_trash_post( $id );

		$this->assertRefused( 410, 'citecue_trashed', $this->push_sibling( 'sib-1', 'main-1' ) );

		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_citecue_translation_of', true ) );
		switch ( Citecue_Languages::mode() ) {
			case Citecue_Languages::MODE_POLYLANG:
				$this->assertNotContains( $id, array_map( 'intval', pll_get_post_translations( $main ) ) );
				break;
			case Citecue_Languages::MODE_WPML:
				$this->assertNotSame(
					apply_filters( 'wpml_element_trid', null, $main, 'post_page' ),
					apply_filters( 'wpml_element_trid', null, $id, 'post_page' )
				);
				break;
		}
	}

	/**
	 * A site that switched from plain mode to a translation plugin links the
	 * existing sibling into the group, without moving or renaming it.
	 *
	 * @return void
	 */
	public function test_a_mode_change_relinks_without_moving() {
		$this->only_vendor();
		$main = $this->push_main( 'main-1' );
		// What a plain-mode push left behind: a child page slugged `pl`, with
		// the meta but no vendor language or group.
		$id = $this->unlinked_citecue_page(
			'sib-1',
			array(
				'post_parent' => $main,
				'post_name'   => 'pl',
			)
		);
		update_post_meta( $id, '_citecue_language', 'pl' );
		update_post_meta( $id, '_citecue_translation_of', (string) $main );

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertLinked( $main, $id );
		$this->assertSame( 'pl', get_post( $id )->post_name );
		$this->assertSame( $main, (int) get_post( $id )->post_parent );
	}

	// ------------------------------------------------------------- refusals

	/**
	 * @dataProvider provide_missing_parents
	 *
	 * @param string $bad_parent Which bad parent to set up.
	 * @return void
	 */
	public function test_a_bad_main_post_is_refused( $bad_parent ) {
		$translation_of = 'main-1';
		$external_id    = 'sib-1';

		switch ( $bad_parent ) {
			case 'missing':
				break;
			case 'not a page':
				$this->push_main( 'main-1', array( 'type' => 'post' ) );
				break;
			case 'trashed':
				wp_trash_post( $this->push_main( 'main-1' ) );
				break;
			case 'itself':
				$this->push_main( 'main-1' );
				$this->push_sibling( 'sib-1', 'main-1' );
				$translation_of = 'sib-1';
				break;
			case 'a translation':
				$this->push_main( 'main-1' );
				$this->push_sibling( 'sib-1', 'main-1' );
				$translation_of = 'sib-1';
				$external_id    = 'sib-nested';
				break;
			case 'empty':
				$translation_of = '!!!';
				break;
		}

		$response = $this->push_sibling( $external_id, $translation_of );

		$this->assertRefused( 409, 'citecue_translation_parent_missing', $response );
		if ( 'itself' !== $bad_parent ) {
			$this->assertSame( 0, $this->post_id_for( $external_id ), 'No post is created on a refusal.' );
		}
	}

	/**
	 * Bad parents.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_missing_parents() {
		return array(
			'missing'              => array( 'missing' ),
			'not a page'           => array( 'not a page' ),
			'trashed'              => array( 'trashed' ),
			'the post itself'      => array( 'itself' ),
			'a translation itself' => array( 'a translation' ),
			'sanitized to nothing' => array( 'empty' ),
		);
	}

	/**
	 * @dataProvider provide_malformed_codes
	 *
	 * @param array $fields Overrides.
	 * @return void
	 */
	public function test_a_malformed_code_is_refused( array $fields ) {
		$this->push_main( 'main-1' );

		$this->assertRefused( 400, 'citecue_language_unavailable', $this->push_sibling( 'sib-1', 'main-1', $fields ) );
		$this->assertSame( 0, $this->post_id_for( 'sib-1' ) );
	}

	/**
	 * Codes that are not ISO 639-1.
	 *
	 * @return array<string,array{0:array}>
	 */
	public function provide_malformed_codes() {
		return array(
			'three letters'           => array( array( 'language' => 'pol' ) ),
			'a locale'                => array( array( 'language' => 'pl_PL' ) ),
			'markup'                  => array( array( 'language' => '"><' ) ),
			'missing language'        => array( array( 'language' => null ) ),
			'malformed main language' => array( array( 'translation_of_language' => 'eng' ) ),
			'missing main language'   => array( array( 'translation_of_language' => null ) ),
			'non-string language'     => array( array( 'language' => array( 'pl' ) ) ),
		);
	}

	/**
	 * Polylang/WPML: a well-formed code the site has no language for.
	 *
	 * @return void
	 */
	public function test_a_code_the_site_lacks_is_refused() {
		$this->only_vendor();
		$this->push_main( 'main-1' );

		$this->assertRefused( 400, 'citecue_language_unavailable', $this->push_sibling( 'sib-de', 'main-1', array( 'language' => 'de' ) ) );
	}

	/**
	 * Polylang/WPML: a main post in another language than the request says.
	 *
	 * @return void
	 */
	public function test_a_main_post_in_another_language_is_refused() {
		$this->only_vendor();
		$main = $this->push_main( 'main-1', array( 'language' => 'pl' ) );

		$response = $this->push_sibling(
			'sib-en',
			'main-1',
			array(
				'language'                => 'en',
				'translation_of_language' => 'en',
			)
		);

		$this->assertRefused( 409, 'citecue_translation_parent_language', $response );
		$this->assertSame( 'pl', $this->language_code_of( $main ), 'The main language is never changed.' );
	}

	/**
	 * Polylang/WPML: a `translation_of_language` the site has no language for.
	 *
	 * @return void
	 */
	public function test_an_unmapped_main_language_is_refused() {
		$this->only_vendor();
		$this->push_main( 'main-1' );

		$this->assertRefused( 409, 'citecue_translation_parent_language', $this->push_sibling( 'sib-1', 'main-1', array( 'translation_of_language' => 'de' ) ) );
	}

	/**
	 * @return void
	 */
	public function test_the_main_posts_own_language_is_a_group_conflict() {
		$this->push_main( 'main-1' );

		$this->assertConflict( 'group', $this->push_sibling( 'sib-en', 'main-1', array( 'language' => 'en' ) ) );
		$this->assertSame( 0, $this->post_id_for( 'sib-en' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_second_post_in_one_language_is_a_group_conflict() {
		$this->push_main( 'main-1' );
		$this->assertSame( 201, $this->push_sibling( 'sib-1', 'main-1' )->get_status() );

		$this->assertConflict( 'group', $this->push_sibling( 'sib-2', 'main-1' ) );
		$this->assertSame( 0, $this->post_id_for( 'sib-2' ) );
	}

	/**
	 * A translation the customer made is never overwritten or unlinked.
	 *
	 * @return void
	 */
	public function test_a_customer_translation_is_never_overwritten() {
		$this->only_vendor();
		$main     = $this->push_main( 'main-1' );
		$customer = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Customer copy',
			)
		);
		$this->link_customer_translation( $main, $customer );

		$this->assertConflict( 'group', $this->push_sibling( 'sib-1', 'main-1' ) );

		$this->assertSame( 0, $this->post_id_for( 'sib-1' ) );
		$this->assertSame( 'Customer copy', get_post( $customer )->post_content );
		$this->assertLinked( $main, $customer );
	}

	/**
	 * @return void
	 */
	public function test_a_sibling_re_pushed_under_another_main_is_a_meta_mismatch() {
		$this->push_main( 'main-1' );
		$this->push_main( 'main-2' );
		$this->push_sibling( 'sib-1', 'main-1' );

		$this->assertConflict( 'meta_mismatch', $this->push_sibling( 'sib-1', 'main-2' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_stored_language_that_differs_is_a_meta_mismatch() {
		$this->push_main( 'main-1' );
		$id = $this->unlinked_citecue_page( 'sib-1' );
		update_post_meta( $id, '_citecue_language', 'de' );

		$this->assertConflict( 'meta_mismatch', $this->push_sibling( 'sib-1', 'main-1' ) );
	}

	/**
	 * A link failure after a create leaves no unlinked page behind.
	 *
	 * @return void
	 */
	public function test_a_link_failure_deletes_the_new_post() {
		$this->push_main( 'main-1' );
		add_action(
			'added_post_meta',
			static function ( $meta_id, $object_id, $meta_key ) {
				unset( $meta_id, $object_id );
				if ( '_citecue_translation_of' === $meta_key ) {
					throw new RuntimeException( 'link failed' );
				}
			},
			10,
			3
		);

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertRefused( 500, 'citecue_translation_link_failed', $response );
		$this->assertSame( 0, $this->post_id_for( 'sib-1' ) );
		$this->assertSame( 0, (int) $this->count_pages_titled( 'Acme FAQ (pl)' ) );
		$this->assertNull( $this->lock_row( 'main-1' ), 'The lock is released.' );
	}

	/**
	 * Anything thrown between the insert and the link (a vendor call, a hook)
	 * also leaves no draft behind.
	 *
	 * @return void
	 */
	public function test_a_throw_after_the_insert_deletes_the_new_post() {
		$this->push_main( 'main-1' );
		add_action(
			'added_post_meta',
			static function ( $meta_id, $object_id, $meta_key ) {
				unset( $meta_id, $object_id );
				if ( '_citecue_external_id' === $meta_key ) {
					throw new RuntimeException( 'vendor failed' );
				}
			},
			10,
			3
		);

		$response = $this->push_sibling( 'sib-1', 'main-1' );

		$this->assertRefused( 500, 'citecue_translation_link_failed', $response );
		$this->assertSame( 0, (int) $this->count_pages_titled( 'Acme FAQ (pl)' ) );
		$this->assertNull( $this->lock_row( 'main-1' ), 'The lock is released.' );
	}

	// ----------------------------------------------------------- concurrency

	/**
	 * A push into a group another request is working on answers busy, for the
	 * same external_id and for another language alike; another group is not
	 * held up, and once the lock is free both pushes land linked.
	 *
	 * @return void
	 */
	public function test_contention_answers_busy_and_then_both_land() {
		add_filter( 'citecue_translation_lock_wait', '__return_zero' );
		$main  = $this->push_main( 'main-1' );
		$other = $this->push_main( 'main-2' );

		global $wpdb;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => Citecue_Translations::lock_name( 'main-1' ),
				'option_value' => time() . ':other-request',
				'autoload'     => 'no',
			)
		);

		$this->assertRefused( 503, 'citecue_busy', $this->push_sibling( 'sib-1', 'main-1' ) );
		$this->assertRefused( 503, 'citecue_busy', $this->push_sibling( 'sib-1', 'main-1' ) );
		$this->assertRefused( 503, 'citecue_busy', $this->push_sibling( 'sib-de', 'main-1', array( 'language' => 'de' ) ) );
		$this->assertSame( 201, $this->push_sibling( 'sib-other', 'main-2' )->get_status() );
		$this->assertSame( 0, $this->post_id_for( 'sib-1' ) );

		$wpdb->delete( $wpdb->options, array( 'option_name' => Citecue_Translations::lock_name( 'main-1' ) ) );

		$this->assertSame( 201, $this->push_sibling( 'sib-1', 'main-1' )->get_status() );
		$this->assertSame( 200, $this->push_sibling( 'sib-1', 'main-1' )->get_status() );
		$this->assertCount( 1, $this->posts_for( 'sib-1' ) );
		$this->assertLinked( $main, $this->post_id_for( 'sib-1' ) );
		$this->assertLinked( $other, $this->post_id_for( 'sib-other' ) );

		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			// Plain mode has a third language to offer: both siblings linked.
			$this->assertSame( 201, $this->push_sibling( 'sib-de', 'main-1', array( 'language' => 'de' ) )->get_status() );
			$this->assertLinked( $main, $this->post_id_for( 'sib-de' ) );
			$this->assertLinked( $main, $this->post_id_for( 'sib-1' ) );
		}
	}

	// ------------------------------------------------------- primary pushes

	/**
	 * A new primary gets the sent language, not the site default.
	 *
	 * @return void
	 */
	public function test_a_new_primary_gets_the_sent_language() {
		$response = $this->push( $this->main_body( 'main-1', array( 'language' => 'pl' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pl', $response->get_data()['language'] );
		$this->assertSame( 'pl', $this->language_code_of( $response->get_data()['post_id'] ) );
	}

	/**
	 * Plain mode stores the language on the main post itself.
	 *
	 * @return void
	 */
	public function test_a_plain_primary_stores_its_language() {
		$this->only_plain();

		$id = $this->push_main( 'main-1', array( 'language' => 'pl' ) );

		$this->assertSame( 'pl', get_post_meta( $id, '_citecue_language', true ) );
	}

	/**
	 * An existing primary's language is never changed.
	 *
	 * @return void
	 */
	public function test_an_existing_primarys_language_is_kept() {
		$id = $this->push_main( 'main-1' );

		$response = $this->push( $this->main_body( 'main-1', array( 'language' => 'pl' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'en', $response->get_data()['language'] );
		$this->assertSame( 'en', $this->language_code_of( $id ) );
	}

	/**
	 * An existing primary with no language gets the sent one.
	 *
	 * @return void
	 */
	public function test_an_existing_primary_without_a_language_gets_one() {
		$id = $this->push_main( 'main-1', array( 'language' => null ) );
		$this->strip_language( $id );

		$response = $this->push( $this->main_body( 'main-1', array( 'language' => 'pl' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pl', $this->language_code_of( $id ) );
	}

	/**
	 * A primary push is never refused over `language`.
	 *
	 * @dataProvider provide_unusable_primary_languages
	 *
	 * @param mixed $language Sent language.
	 * @return void
	 */
	public function test_a_primary_with_an_unusable_language_still_lands( $language ) {
		$response = $this->push( $this->main_body( 'main-1', array( 'language' => $language ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '', get_post_meta( $response->get_data()['post_id'], '_citecue_language', true ) );
		// Whatever the site gave it, as today: the default in Polylang and plain
		// mode; nothing under the WPML stub, which assigns no default on insert.
		$this->assertSame( $this->language_code_of( $response->get_data()['post_id'] ), $response->get_data()['language'] );
		$this->assertSame( Citecue_Languages::MODE_WPML === Citecue_Languages::mode() ? null : 'en', $response->get_data()['language'] );
	}

	/**
	 * Languages a primary push ignores.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function provide_unusable_primary_languages() {
		$cases = array(
			'malformed' => array( 'pol' ),
			'markup'    => array( '"><' ),
			'an array'  => array( array( 'pl' ) ),
		);
		if ( getenv( 'CITECUE_WITH_POLYLANG' ) || getenv( 'CITECUE_STUB_WPML' ) ) {
			$cases['not a site language'] = array( 'de' );
		}
		return $cases;
	}

	// -------------------------------------------------------------- helpers

	/**
	 * Skips unless the run is plain mode.
	 *
	 * @return void
	 */
	private function only_plain() {
		if ( Citecue_Languages::MODE_PLAIN !== Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'Plain mode only (composer run test:core).' );
		}
	}

	/**
	 * Skips unless the run has a translation plugin.
	 *
	 * @return void
	 */
	private function only_vendor() {
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'Polylang/WPML only (composer run test:polylang / test:wpml).' );
		}
	}

	/**
	 * A main-page body.
	 *
	 * @param string $external_id External id.
	 * @param array  $overrides   Fields; a null value drops the field.
	 * @return array
	 */
	private function main_body( $external_id, array $overrides = array() ) {
		return array_filter(
			array_merge(
				array(
					'external_id' => $external_id,
					'title'       => 'Acme FAQ',
					'content'     => '<p>What is Acme?</p>',
					'type'        => 'page',
					'language'    => 'en',
				),
				$overrides
			),
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Pushes a main page.
	 *
	 * @param string $external_id External id.
	 * @param array  $overrides   Fields; a null value drops the field.
	 * @return int The post id.
	 */
	private function push_main( $external_id, array $overrides = array() ) {
		$response = $this->push( $this->main_body( $external_id, $overrides ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()['post_id'];
	}

	/**
	 * Pushes a language page of `$translation_of` (Polish of an English main
	 * by default, and never a `slug`).
	 *
	 * @param string $external_id    External id.
	 * @param string $translation_of The main page's external id.
	 * @param array  $overrides      Fields; a null value drops the field.
	 * @return WP_REST_Response
	 */
	private function push_sibling( $external_id, $translation_of, array $overrides = array() ) {
		return $this->push(
			array_filter(
				array_merge(
					array(
						'external_id'             => $external_id,
						'title'                   => 'Acme FAQ (pl)',
						'content'                 => '<p>Czym jest Acme?</p>',
						'type'                    => 'page',
						'language'                => 'pl',
						'translation_of'          => $translation_of,
						'translation_of_language' => 'en',
					),
					$overrides
				),
				static function ( $value ) {
					return null !== $value;
				}
			)
		);
	}

	/**
	 * Sends a signed push.
	 *
	 * @param array $body Body.
	 * @return WP_REST_Response
	 */
	private function push( array $body ) {
		$body      = wp_json_encode( $body );
		$timestamp = time() + ( $this->tick++ % 250 );

		$request = new WP_REST_Request( 'POST', '/citecue/v1/content' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-citecue-timestamp', (string) $timestamp );
		$request->set_header( 'x-citecue-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ) );
		$request->set_body( $body );

		return rest_do_request( $request );
	}

	/**
	 * A CiteCue page that carries an external id but no translation meta and
	 * no vendor link: what a rolled-back plugin's push would have left.
	 *
	 * @param string $external_id External id.
	 * @param array  $postarr     Extra post fields.
	 * @return int
	 */
	private function unlinked_citecue_page( $external_id, array $postarr = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_title'   => 'Acme FAQ (pl)',
					'post_content' => '<p>Czym jest Acme?</p>',
				),
				$postarr
			)
		);
		update_post_meta( $id, '_citecue_external_id', $external_id );
		update_post_meta( $id, '_citecue_content_hash', md5( get_post( $id )->post_content ) );
		$this->strip_language( $id );
		return $id;
	}

	/**
	 * Removes any language a post has, in every mode.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function strip_language( $post_id ) {
		delete_post_meta( $post_id, '_citecue_language' );
		if ( function_exists( 'pll_set_post_language' ) ) {
			wp_delete_object_term_relationships( $post_id, array( 'language', 'post_translations' ) );
			clean_object_term_cache( $post_id, get_post_type( $post_id ) );
		}
		unset( Citecue_Wpml_Stub::$elements[ (int) $post_id ] );
	}

	/**
	 * Links a customer's own page as the main post's Polish translation.
	 *
	 * @param int $main     Main post id.
	 * @param int $customer Customer page id.
	 * @return void
	 */
	private function link_customer_translation( $main, $customer ) {
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $customer, 'pl' );
			pll_save_post_translations(
				array(
					'en' => $main,
					'pl' => $customer,
				)
			);
			return;
		}
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $customer,
				'element_type'         => 'post_page',
				'trid'                 => apply_filters( 'wpml_element_trid', null, $main, 'post_page' ),
				'language_code'        => 'pl',
				'source_language_code' => 'en',
			)
		);
	}

	/**
	 * The ISO code a post carries in the current mode.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	private function language_code_of( $post_id ) {
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			$stored = (string) get_post_meta( $post_id, '_citecue_language', true );
			return '' !== $stored ? $stored : Citecue_Languages::site_language_code();
		}
		$slug = Citecue_Languages::post_language( $post_id );
		return null === $slug ? null : Citecue_Languages::code_for_slug( $slug );
	}

	/**
	 * Asserts `$sibling` is in `$main`'s translation group in this mode.
	 *
	 * @param int $main    Main post id.
	 * @param int $sibling Sibling post id.
	 * @return void
	 */
	private function assertLinked( $main, $sibling ) {
		$main    = (int) $main;
		$sibling = (int) $sibling;
		$this->assertGreaterThan( 0, $sibling );

		switch ( Citecue_Languages::mode() ) {
			case Citecue_Languages::MODE_POLYLANG:
				$group = pll_get_post_translations( $main );
				$this->assertContains( $sibling, array_map( 'intval', $group ), 'Polylang group: ' . wp_json_encode( $group ) );
				$this->assertSame( 'en', pll_get_post_language( $main ) );
				break;
			case Citecue_Languages::MODE_WPML:
				$trid = apply_filters( 'wpml_element_trid', null, $main, 'post_page' );
				$this->assertNotEmpty( $trid );
				$this->assertSame( $trid, apply_filters( 'wpml_element_trid', null, $sibling, 'post_page' ) );
				break;
			default:
				$this->assertSame( (string) $main, get_post_meta( $sibling, '_citecue_translation_of', true ) );
		}
	}

	/**
	 * @param int    $status   Expected HTTP status.
	 * @param string $code     Expected error code.
	 * @param mixed  $response Response.
	 * @return void
	 */
	private function assertRefused( $status, $code, $response ) {
		$data = $response->get_data();
		$this->assertSame(
			array(
				'status' => $status,
				'code'   => $code,
			),
			array(
				'status' => $response->get_status(),
				'code'   => isset( $data['code'] ) ? $data['code'] : null,
			),
			wp_json_encode( $data )
		);
	}

	/**
	 * @param string           $reason   Expected `data.reason`.
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	private function assertConflict( $reason, $response ) {
		$this->assertRefused( 409, 'citecue_translation_conflict', $response );
		$this->assertSame( $reason, $response->get_data()['data']['reason'] );
	}

	/**
	 * Every post carrying an external id, in any status and language.
	 *
	 * @param string $external_id External id.
	 * @return int[]
	 */
	private function posts_for( $external_id ) {
		return get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_key'       => '_citecue_external_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * @param string $external_id External id.
	 * @return int The first post id, or 0.
	 */
	private function post_id_for( $external_id ) {
		$ids = $this->posts_for( $external_id );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @param string $title Title.
	 * @return int
	 */
	private function count_pages_titled( $title ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s AND post_type = 'page'", $title ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param string $key Lock key.
	 * @return string|null
	 */
	private function lock_row( $key ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Citecue_Translations::lock_name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
