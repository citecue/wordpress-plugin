<?php
/**
 * Plain-mode language output: html lang, hreflang and cache cleaning.
 *
 * @package Citecue
 */

/**
 * Citecue_Translations_Head.
 */
class Test_Citecue_Translations_Head extends Citecue_Test_Case {

	/**
	 * Post ids whose cache was cleaned.
	 *
	 * @var int[]
	 */
	private $cleaned = array();

	/**
	 * Plain mode is the only one this class acts in.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		if ( Citecue_Languages::MODE_PLAIN !== Citecue_Languages::mode() && 'test_prints_nothing_outside_plain_mode' !== $this->getName() ) {
			$this->markTestSkipped( 'Plain mode only.' );
		}
	}

	/**
	 * A CiteCue main post (no stored language: the pre-feature shape).
	 *
	 * @param array $args Extra post args.
	 * @return int
	 */
	private function main( array $args = array() ) {
		$id = self::factory()->post->create( array_merge( array( 'post_title' => 'Main' ), $args ) );
		update_post_meta( $id, '_citecue_external_id', 'ext-' . $id );
		return $id;
	}

	/**
	 * A sibling in a language.
	 *
	 * @param int    $main   Main post id.
	 * @param string $code   Language code.
	 * @param string $status Post status.
	 * @return int
	 */
	private function sibling( $main, $code, $status = 'publish' ) {
		$id = self::factory()->post->create(
			array(
				'post_title'  => 'Sibling ' . $code,
				'post_status' => $status,
				'post_parent' => $main,
			)
		);
		update_post_meta( $id, '_citecue_translation_of', $main );
		update_post_meta( $id, '_citecue_language', $code );
		return $id;
	}

	/**
	 * The wp_head output of a post's own page.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function head_of( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		do_action( 'wp_head' );
		return (string) ob_get_clean();
	}

	/**
	 * Under Polylang or WPML (the other runs) the plugin prints and cleans
	 * nothing; the plugin of that mode owns it.
	 *
	 * @return void
	 */
	public function test_prints_nothing_outside_plain_mode() {
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			$this->markTestSkipped( 'Needs the polylang or wpml run.' );
		}

		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );
		$this->assertStringNotContainsString( 'hreflang', $this->head_of( $pl ) );
		$this->assertStringNotContainsString( 'lang="pl"', get_language_attributes() );

		add_action( 'clean_post_cache', array( $this, 'record_clean' ) );
		update_post_meta( $pl, '_citecue_language', 'cs' );
		remove_action( 'clean_post_cache', array( $this, 'record_clean' ) );
		$this->assertNotContains( $main, $this->cleaned );
	}

	/**
	 * Main and two siblings print the set, x-default on the main post.
	 *
	 * @return void
	 */
	public function test_prints_full_set_with_x_default_and_main_fallback_language() {
		update_option( 'WPLANG', '' );
		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );
		$de   = $this->sibling( $main, 'de' );

		foreach ( array( $main, $pl, $de ) as $viewing ) {
			$head = $this->head_of( $viewing );
			$this->assertStringContainsString( 'hreflang="en" href="' . esc_url( get_permalink( $main ) ) . '"', $head );
			$this->assertStringContainsString( 'hreflang="pl" href="' . esc_url( get_permalink( $pl ) ) . '"', $head );
			$this->assertStringContainsString( 'hreflang="de" href="' . esc_url( get_permalink( $de ) ) . '"', $head );
			$this->assertStringContainsString( 'hreflang="x-default" href="' . esc_url( get_permalink( $main ) ) . '"', $head );
			$this->assertSame( 4, substr_count( $head, 'hreflang=' ) );
		}
	}

	/**
	 * A draft member is left out.
	 *
	 * @return void
	 */
	public function test_draft_members_are_omitted() {
		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );
		$this->sibling( $main, 'de', 'draft' );

		$head = $this->head_of( $pl );

		$this->assertStringContainsString( 'hreflang="pl"', $head );
		$this->assertStringNotContainsString( 'hreflang="de"', $head );
	}

	/**
	 * The x-default link follows the main post only while it is published.
	 *
	 * @return void
	 */
	public function test_no_x_default_while_main_is_a_draft() {
		$main = $this->main( array( 'post_status' => 'draft' ) );
		$pl   = $this->sibling( $main, 'pl' );
		$de   = $this->sibling( $main, 'de' );

		$head = $this->head_of( $pl );

		$this->assertStringContainsString( 'hreflang="de"', $head );
		$this->assertStringNotContainsString( 'x-default', $head );
		$this->assertStringNotContainsString( get_permalink( $main ), $head );
		unset( $de );
	}

	/**
	 * Fewer than two published members prints nothing.
	 *
	 * @return void
	 */
	public function test_single_member_group_prints_nothing() {
		$main = $this->main();
		$this->sibling( $main, 'pl', 'draft' );

		$this->assertStringNotContainsString( 'hreflang', $this->head_of( $main ) );
	}

	/**
	 * A post that is not a CiteCue post is left alone.
	 *
	 * @return void
	 */
	public function test_ordinary_post_prints_nothing() {
		$post = self::factory()->post->create();

		$this->assertStringNotContainsString( 'hreflang', $this->head_of( $post ) );
	}

	/**
	 * Every printed value is escaped.
	 *
	 * @return void
	 */
	public function test_output_is_escaped() {
		$main = $this->main();
		$evil = $this->sibling( $main, 'pl"><script>alert(1)</script>' );

		$head = $this->head_of( $evil );

		$this->assertStringNotContainsString( '<script>', $head );
	}

	/**
	 * The group costs one query on the postmeta table, not one per member.
	 *
	 * @return void
	 */
	public function test_group_is_one_bounded_meta_query() {
		$main = $this->main();
		$this->sibling( $main, 'pl' );
		$this->sibling( $main, 'de' );
		$this->go_to( get_permalink( $main ) );

		$queries = array();
		$filter  = static function ( $sql ) use ( &$queries ) {
			if ( false !== strpos( $sql, '_citecue_translation_of' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		ob_start();
		do_action( 'wp_head' );
		ob_end_clean();
		remove_filter( 'query', $filter );

		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'LIMIT', $queries[0] );
	}

	/**
	 * Printing the group costs the same number of queries however many
	 * members it has: member meta and posts are primed in bulk, not read one
	 * by one.
	 *
	 * @return void
	 */
	public function test_hreflang_query_count_does_not_grow_with_the_group() {
		$small = $this->main();
		$this->sibling( $small, 'pl' );
		$this->sibling( $small, 'de' );
		$large = $this->main();
		foreach ( array( 'pl', 'de', 'fr', 'es', 'it' ) as $code ) {
			$this->sibling( $large, $code );
		}

		$small_count = $this->queries_printing_head( $small );
		$large_count = $this->queries_printing_head( $large );

		$this->assertSame( $small_count, $large_count, 'Queries must not grow with the group.' );
		$this->assertLessThan( 12, $large_count );
	}

	/**
	 * Total queries to print a post's wp_head with cold caches.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function queries_printing_head( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		// One-off option bootstrap (salts, theme mods) is not the group's cost:
		// print (and flush) twice to settle it, then count a cold-cache print.
		for ( $warm = 0; $warm < 2; $warm++ ) {
			ob_start();
			do_action( 'wp_head' );
			ob_end_clean();
			wp_cache_flush();
		}
		$count  = 0;
		$filter = static function ( $sql ) use ( &$count ) {
			++$count;
			return $sql;
		};
		add_filter( 'query', $filter );
		ob_start();
		do_action( 'wp_head' );
		ob_end_clean();
		remove_filter( 'query', $filter );
		return $count;
	}

	/**
	 * The html lang is replaced on a post with a stored language only.
	 *
	 * @return void
	 */
	public function test_language_attributes_use_the_stored_language() {
		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );

		$this->go_to( get_permalink( $pl ) );
		$this->assertStringContainsString( 'lang="pl"', get_language_attributes() );
		$this->assertStringNotContainsString( 'en-US', get_language_attributes() );

		$this->go_to( get_permalink( $main ) );
		$this->assertStringContainsString( 'lang="en-US"', get_language_attributes() );
	}

	/**
	 * Escapes the code.
	 *
	 * @return void
	 */
	public function test_language_attribute_is_escaped() {
		$main = $this->main();
		$evil = $this->sibling( $main, 'x" onload="alert(1)' );
		$this->go_to( get_permalink( $evil ) );

		$this->assertStringNotContainsString( 'onload="', get_language_attributes() );
	}

	/**
	 * Records a clean.
	 *
	 * @param int $id Post id.
	 * @return void
	 */
	public function record_clean( $id ) {
		$this->cleaned[] = (int) $id;
	}

	/**
	 * Publishing a sibling cleans every member's cache.
	 *
	 * @return void
	 */
	public function test_status_change_cleans_every_member() {
		$main  = $this->main();
		$pl    = $this->sibling( $main, 'pl', 'draft' );
		$de    = $this->sibling( $main, 'de' );
		$other = $this->main();

		add_action( 'clean_post_cache', array( $this, 'record_clean' ) );
		wp_update_post(
			array(
				'ID'          => $pl,
				'post_status' => 'publish',
			)
		);
		remove_action( 'clean_post_cache', array( $this, 'record_clean' ) );

		foreach ( array( $main, $pl, $de ) as $id ) {
			$this->assertContains( $id, $this->cleaned );
		}
		$this->assertNotContains( $other, $this->cleaned );
	}

	/**
	 * A slug change cleans the group.
	 *
	 * @return void
	 */
	public function test_slug_change_cleans_every_member() {
		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );

		add_action( 'clean_post_cache', array( $this, 'record_clean' ) );
		wp_update_post(
			array(
				'ID'        => $pl,
				'post_name' => 'nowy-slug',
			)
		);
		remove_action( 'clean_post_cache', array( $this, 'record_clean' ) );

		$this->assertContains( $main, $this->cleaned );
	}

	/**
	 * A language change cleans the group.
	 *
	 * @return void
	 */
	public function test_language_meta_change_cleans_every_member() {
		$main = $this->main();
		$pl   = $this->sibling( $main, 'pl' );

		add_action( 'clean_post_cache', array( $this, 'record_clean' ) );
		update_post_meta( $pl, '_citecue_language', 'cs' );
		remove_action( 'clean_post_cache', array( $this, 'record_clean' ) );

		$this->assertContains( $main, $this->cleaned );
		$this->assertContains( $pl, $this->cleaned );
	}

	/**
	 * A hook that itself changes a member during the clean cannot loop.
	 *
	 * @return void
	 */
	public function test_cleaning_does_not_recurse() {
		$main  = $this->main();
		$pl    = $this->sibling( $main, 'pl' );
		$count = 0;
		$cb    = static function () use ( &$count, $pl ) {
			++$count;
			if ( $count < 50 ) {
				update_post_meta( $pl, '_citecue_language', 'x' . $count );
			}
		};

		add_action( 'clean_post_cache', $cb );
		update_post_meta( $pl, '_citecue_language', 'cs' );
		remove_action( 'clean_post_cache', $cb );

		$this->assertLessThan( 20, $count );
	}
}
