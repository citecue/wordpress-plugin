<?php
/**
 * Sanity checks for the language-plugin test modes (Polylang / WPML stub).
 *
 * @package Citecue
 */

/**
 * Proves each translator run mode actually boots what later tests rely on.
 */
class Test_Language_Boot extends Citecue_Test_Case {

	/**
	 * Polylang is really loaded and the en/pl languages exist.
	 *
	 * @return void
	 */
	public function test_polylang_has_en_and_pl() {
		if ( ! getenv( 'CITECUE_WITH_POLYLANG' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_WITH_POLYLANG=1 (composer run test:polylang).' );
		}

		$this->assertTrue( function_exists( 'pll_set_post_language' ) );
		$this->assertEqualsCanonicalizing( array( 'en', 'pl' ), pll_languages_list() );

		$post_id = self::factory()->post->create();
		pll_set_post_language( $post_id, 'pl' );
		$this->assertSame( 'pl', pll_get_post_language( $post_id ) );
	}

	/**
	 * The WPML stub answers the trid / language filters in memory.
	 *
	 * @return void
	 */
	public function test_wpml_stub_trid_round_trip() {
		if ( ! getenv( 'CITECUE_STUB_WPML' ) ) {
			$this->markTestSkipped( 'Needs CITECUE_STUB_WPML=1 (composer run test:wpml).' );
		}

		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ) );

		$source = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$other  = self::factory()->post->create( array( 'post_type' => 'page' ) );

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'    => $source,
				'element_type'  => 'post_page',
				'trid'          => false,
				'language_code' => 'en',
			)
		);
		$trid = apply_filters( 'wpml_element_trid', null, $source, 'post_page' );
		$this->assertNotEmpty( $trid );

		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $other,
				'element_type'         => 'post_page',
				'trid'                 => $trid,
				'language_code'        => 'pl',
				'source_language_code' => 'en',
			)
		);

		$this->assertSame( $trid, apply_filters( 'wpml_element_trid', null, $other, 'post_page' ) );
		$details = apply_filters( 'wpml_post_language_details', null, $other );
		$this->assertSame( 'pl', $details['language_code'] );
		$this->assertArrayHasKey( 'en', apply_filters( 'wpml_active_languages', null ) );
	}
}
