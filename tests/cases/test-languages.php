<?php
/**
 * Language detection, code mapping and the `language_pages` declaration.
 *
 * The mode is a process-wide fact (a plugin is loaded or it is not), so the
 * Polylang and WPML cases skip unless their run mode is on, the same way the
 * WooCommerce cases do. Run with `composer run test:polylang` / `test:wpml`.
 *
 * @package Citecue
 */

/**
 * Citecue_Languages and the capability it feeds.
 */
class Test_Citecue_Languages extends Citecue_Test_Case {

	/**
	 * Undo steps for process-wide state a test changed.
	 *
	 * @var callable[]
	 */
	private $undo = array();

	/**
	 * Runs the undo steps.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( array_reverse( $this->undo ) as $step ) {
			call_user_func( $step );
		}
		$this->undo = array();
		parent::tear_down();
	}

	/**
	 * Skips unless Polylang is the loaded translator.
	 *
	 * @return void
	 */
	private function require_polylang() {
		if ( ! getenv( 'CITECUE_WITH_POLYLANG' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_WITH_POLYLANG=1 (composer run test:polylang).' );
		}
	}

	/**
	 * Skips unless the WPML stub is installed.
	 *
	 * @return void
	 */
	private function require_wpml() {
		if ( ! getenv( 'CITECUE_STUB_WPML' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_STUB_WPML=1 (composer run test:wpml).' );
		}
	}

	/**
	 * Makes WPML's default language answer, as real WPML does.
	 *
	 * @param string $slug Default language slug.
	 * @return void
	 */
	private function wpml_default( $slug ) {
		add_filter(
			'wpml_default_language',
			function () use ( $slug ) {
				return $slug;
			}
		);
	}

	/**
	 * Replaces the stub's active languages for one test.
	 *
	 * @param array $languages code => default_locale.
	 * @return void
	 */
	private function wpml_languages( array $languages ) {
		$saved        = Citecue_Wpml_Stub::$languages;
		$this->undo[] = function () use ( $saved ) {
			Citecue_Wpml_Stub::$languages = $saved;
		};

		Citecue_Wpml_Stub::$languages = array();
		foreach ( $languages as $slug => $locale ) {
			Citecue_Wpml_Stub::$languages[ $slug ] = array(
				'code'           => $slug,
				'language_code'  => $slug,
				'default_locale' => $locale,
				'active'         => 1,
			);
		}
	}

	/**
	 * Plain mode: nothing loaded, the capability is declared and no list exists.
	 *
	 * @return void
	 */
	public function test_plain_mode_declares_with_no_list() {
		if ( getenv( 'CITECUE_WITH_POLYLANG' ) || getenv( 'CITECUE_STUB_WPML' ) ) {
			$this->markTestSkipped( 'Plain mode only exists when no translator is loaded.' );
		}

		$this->assertSame( 'plain', Citecue_Languages::mode() );
		$this->assertNull( Citecue_Languages::declared_codes() );
		$this->assertNull( Citecue_Languages::map_code( 'pl' ) );
		$this->assertTrue( Citecue_Languages::declarable() );
		$this->assertTrue( $this->plugin->settings->declared_capabilities()['language_pages'] );
		$this->assertNull( $this->plugin->settings->translation_languages() );
	}

	/**
	 * The site's own language is the main post's language in plain mode.
	 *
	 * @return void
	 */
	public function test_plain_mode_reads_the_site_locale() {
		if ( getenv( 'CITECUE_WITH_POLYLANG' ) || getenv( 'CITECUE_STUB_WPML' ) ) {
			$this->markTestSkipped( 'Plain mode only exists when no translator is loaded.' );
		}

		add_filter(
			'locale',
			function () {
				return 'pl_PL';
			}
		);

		$this->assertSame( 'pl', Citecue_Languages::site_language_code() );
		$this->assertSame( 'pl', Citecue_Languages::post_language( self::factory()->post->create() ) );
	}

	/**
	 * Every translator that keeps all languages on one post, or one site per
	 * language, blocks the capability.
	 *
	 * @return void
	 */
	public function test_each_blocked_translator_is_detected() {
		$none = function () {
			return false;
		};

		$this->assertSame( '', Citecue_Languages::detect_blocker( $none, $none ) );

		$this->assertSame(
			'TranslatePress',
			Citecue_Languages::detect_blocker(
				function ( $name ) {
					return 'TRP_Translate_Press' === $name;
				},
				$none
			)
		);
		$this->assertSame(
			'Weglot',
			Citecue_Languages::detect_blocker(
				$none,
				function ( $name ) {
					return 'WEGLOT_VERSION' === $name;
				}
			)
		);
		$this->assertSame(
			'MultilingualPress',
			Citecue_Languages::detect_blocker(
				$none,
				function ( $name ) {
					return 'MULTILINGUALPRESS_LICENSE_API_URL' === $name;
				}
			)
		);
		$this->assertSame(
			'MultilingualPress',
			Citecue_Languages::detect_blocker(
				function ( $name ) {
					return 'Inpsyde\\MultilingualPress\\MultilingualPress' === $name;
				},
				$none
			)
		);
		$this->assertSame( '', Citecue_Languages::blocked_translator() );
	}

	/**
	 * Polylang's en and pl map to their codes, in a sorted declared list.
	 *
	 * @return void
	 */
	public function test_polylang_maps_and_declares_its_languages() {
		$this->require_polylang();

		$this->assertSame( 'polylang', Citecue_Languages::mode() );
		$this->assertSame( 'en', Citecue_Languages::map_code( 'en' ) );
		$this->assertSame( 'pl', Citecue_Languages::map_code( 'PL' ) );
		$this->assertNull( Citecue_Languages::map_code( 'de' ) );
		$this->assertSame( array( 'en', 'pl' ), Citecue_Languages::declared_codes() );
		$this->assertSame( 'pl', Citecue_Languages::code_for_slug( 'pl' ) );
		$this->assertTrue( Citecue_Languages::page_translatable() );
		$this->assertTrue( Citecue_Languages::declarable() );
		$this->assertSame( array( 'en', 'pl' ), $this->plugin->settings->translation_languages() );
	}

	/**
	 * The main post's language is the Polylang language it carries.
	 *
	 * @return void
	 */
	public function test_polylang_reads_a_posts_language() {
		$this->require_polylang();

		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		pll_set_post_language( $post_id, 'pl' );

		$this->assertSame( 'pl', Citecue_Languages::post_language( $post_id ) );
	}

	/**
	 * Two active languages share `en`: the default wins over a slug match.
	 *
	 * @return void
	 */
	public function test_ambiguous_code_prefers_the_default_language() {
		$this->require_polylang();

		$this->add_polylang_language( 'English (UK)', 'gb', 'en_GB' );

		$this->assertSame( 'en', Citecue_Languages::map_code( 'en' ) );
		$this->assertSame( array( 'en', 'pl' ), Citecue_Languages::declared_codes() );
	}

	/**
	 * With neither the default nor a slug match, the code is undeclared rather
	 * than guessed.
	 *
	 * @return void
	 */
	public function test_ambiguous_code_with_no_tie_break_is_undeclared() {
		$this->require_polylang();

		$this->add_polylang_language( 'Deutsch', 'ger', 'de_DE' );
		$this->add_polylang_language( 'Deutsch (AT)', 'aut', 'de_AT' );

		// Neither is the default (en), and neither slug is `de`.
		$this->assertNull( Citecue_Languages::map_code( 'de' ) );
		$this->assertSame( array( 'en', 'pl' ), Citecue_Languages::declared_codes() );
	}

	/**
	 * A slug equal to the code breaks the tie when the default is elsewhere.
	 *
	 * @return void
	 */
	public function test_ambiguous_code_falls_back_to_the_slug_equal_to_it() {
		$this->require_polylang();

		$this->add_polylang_language( 'Deutsch', 'de', 'de_DE' );
		$this->add_polylang_language( 'Deutsch (AT)', 'aut', 'de_AT' );

		$this->assertSame( 'de', Citecue_Languages::map_code( 'de' ) );
		$this->assertSame( array( 'de', 'en', 'pl' ), Citecue_Languages::declared_codes() );
	}

	/**
	 * Adds a Polylang language for the test and removes it afterwards.
	 *
	 * @param string $name   Display name.
	 * @param string $slug   Language slug.
	 * @param string $locale Locale.
	 * @return void
	 */
	private function add_polylang_language( $name, $slug, $locale ) {
		$default = PLL()->model->options['default_lang'];

		PLL()->model->clean_languages_cache();
		$result = PLL()->model->add_language(
			array(
				'name'       => $name,
				'slug'       => $slug,
				'locale'     => $locale,
				'rtl'        => 0,
				'term_group' => 5,
				'flag'       => 'gb',
			)
		);
		$this->assertNotWPError( $result );

		// The rows roll back with the test; the cached list and the default do not.
		$this->undo[] = function () use ( $default ) {
			PLL()->model->options['default_lang'] = $default;
			PLL()->model->clean_languages_cache();
		};
	}

	/**
	 * WPML mode maps through its filters, and never declares while disabled.
	 *
	 * @return void
	 */
	public function test_wpml_mode_maps_but_does_not_declare() {
		$this->require_wpml();
		$this->wpml_default( 'en' );

		$this->assertSame( 'wpml', Citecue_Languages::mode() );
		$this->assertSame( 'pl', Citecue_Languages::map_code( 'pl' ) );
		$this->assertSame( array( 'en', 'pl' ), Citecue_Languages::declared_codes() );
		$this->assertFalse( Citecue_Languages::WPML_ENABLED );
		$this->assertFalse( Citecue_Languages::declarable() );
		$this->assertFalse( $this->plugin->settings->declared_capabilities()['language_pages'] );
	}

	/**
	 * With both plugins active, WPML wins and the site stays undeclared.
	 *
	 * @return void
	 */
	public function test_wpml_wins_when_polylang_is_also_active() {
		$this->require_wpml();

		if ( ! function_exists( 'pll_languages_list' ) ) {
			// Process-wide, like the stub itself; this run is WPML-only.
			eval( 'function pll_languages_list( $args = array() ) { return array( "en" ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stands in for a second translator, which cannot be unloaded.
		}

		$this->assertSame( 'wpml', Citecue_Languages::mode() );
		$this->assertFalse( $this->plugin->settings->declared_capabilities()['language_pages'] );
	}

	/**
	 * WPML's code mapping uses the same tie-breaks as Polylang's.
	 *
	 * @return void
	 */
	public function test_wpml_ambiguity_uses_the_default_then_the_slug() {
		$this->require_wpml();
		$this->wpml_languages(
			array(
				'en'    => 'en_US',
				'en-gb' => 'en_GB',
			)
		);
		$this->wpml_default( 'en-gb' );

		$this->assertSame( 'en-gb', Citecue_Languages::map_code( 'en' ) );
		$this->assertSame( 'en', Citecue_Languages::code_for_slug( 'en-gb' ) );
	}

	/**
	 * WPML reads a post's language from its filter.
	 *
	 * @return void
	 */
	public function test_wpml_reads_a_posts_language() {
		$this->require_wpml();

		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $post_id,
				'element_type'  => 'post_page',
				'trid'          => false,
				'language_code' => 'pl',
			)
		);

		$this->assertSame( 'pl', Citecue_Languages::post_language( $post_id ) );
		$this->assertNull( Citecue_Languages::post_language( self::factory()->post->create() ) );
	}

	/**
	 * A language with no ISO 639-1 part never reaches the list.
	 *
	 * @return void
	 */
	public function test_three_letter_languages_are_left_out() {
		$this->require_wpml();
		$this->wpml_languages(
			array(
				'en'  => 'en_US',
				'ast' => 'ast',
			)
		);

		$this->assertSame( array( 'en' ), Citecue_Languages::declared_codes() );
	}
}
