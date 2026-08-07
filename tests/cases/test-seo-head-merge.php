<?php
/**
 * The conflict-avoidance contract with every other SEO plugin on the site.
 *
 * Citecue_Seo_Head::merge() is pure, so these are the cheapest tests in the
 * suite and the ones that matter most: a regression here does not break
 * CiteCue, it breaks the customer's `<head>` — two titles, two canonicals, and
 * an SEO plugin they paid for quietly fighting with ours.
 *
 * @package Citecue
 */

/**
 * Gap-filling merge.
 */
class Test_Citecue_Seo_Head_Merge extends Citecue_Test_Case {

	/**
	 * A CiteCue block of the shape the delivery API actually returns: the
	 * `data-citecue`-marked head elements of an enriched page.
	 *
	 * @return string
	 */
	private function block() {
		return implode(
			"\n",
			array(
				'<title data-citecue="title">Acme Widgets — Industrial fasteners</title>',
				'<meta data-citecue="description" name="description" content="Acme makes fasteners." />',
				'<link data-citecue="canonical" rel="canonical" href="https://example.org/" />',
				'<meta data-citecue="og" property="og:title" content="Acme Widgets" />',
				'<meta data-citecue="og" property="og:description" content="Acme makes fasteners." />',
				'<meta data-citecue="twitter" name="twitter:card" content="summary" />',
				'<script data-citecue="jsonld" type="application/ld+json">{"@type":"Organization"}</script>',
			)
		);
	}

	/**
	 * An empty head takes the whole block.
	 *
	 * @return void
	 */
	public function test_empty_head_takes_every_tag() {
		$tags = Citecue_Seo_Head::merge( '', $this->block() );

		$this->assertCount( 7, $tags );
		$this->assertStringContainsString( 'Acme Widgets — Industrial fasteners', $tags[0] );
	}

	/**
	 * WordPress alone — no SEO plugin at all — already prints a title and a
	 * canonical, so those two are the baseline conflict on every site.
	 *
	 * @return void
	 */
	public function test_core_title_and_canonical_are_never_duplicated() {
		$existing = '<title>Hello world — Example</title>' . "\n"
			. '<link rel="canonical" href="https://example.org/hello-world/" />';

		$tags = Citecue_Seo_Head::merge( $existing, $this->block() );
		$all  = implode( "\n", $tags );

		$this->assertStringNotContainsString( '<title', $all );
		$this->assertStringNotContainsString( 'rel="canonical"', $all );
		// Everything core does not emit still comes through.
		$this->assertStringContainsString( 'name="description"', $all );
		$this->assertStringContainsString( 'og:title', $all );
	}

	/**
	 * A Yoast-shaped head: title, description, canonical, OpenGraph, Twitter
	 * and a JSON-LD graph. Nothing of ours may survive it.
	 *
	 * @return void
	 */
	public function test_yoast_shaped_head_leaves_nothing_to_add() {
		$existing = implode(
			"\n",
			array(
				'<title>Hello world - Example</title>',
				'<meta name="description" content="Yoast wrote this." />',
				'<link rel="canonical" href="https://example.org/hello-world/" />',
				'<meta property="og:title" content="Hello world" />',
				'<meta property="og:description" content="Yoast wrote this." />',
				'<meta name="twitter:card" content="summary_large_image" />',
				'<script type="application/ld+json" class="yoast-schema-graph">{"@graph":[]}</script>',
			)
		);

		$this->assertSame( array(), Citecue_Seo_Head::merge( $existing, $this->block() ) );
	}

	/**
	 * The realistic middle case: an SEO plugin that manages the basics but
	 * emits no OpenGraph and no structured data. Those are exactly the gaps
	 * CiteCue exists to fill.
	 *
	 * @return void
	 */
	public function test_partial_seo_plugin_gets_only_its_gaps_filled() {
		$existing = implode(
			"\n",
			array(
				'<title>Hello world</title>',
				'<meta name="description" content="Theirs." />',
				'<link rel="canonical" href="https://example.org/hello-world/" />',
			)
		);

		$tags = Citecue_Seo_Head::merge( $existing, $this->block() );
		$all  = implode( "\n", $tags );

		$this->assertCount( 4, $tags );
		$this->assertStringContainsString( 'og:title', $all );
		$this->assertStringContainsString( 'og:description', $all );
		$this->assertStringContainsString( 'twitter:card', $all );
		$this->assertStringContainsString( 'application/ld+json', $all );
	}

	/**
	 * Attribute quoting and casing vary wildly across themes and plugins, and a
	 * slot missed because of a single quote is a duplicate tag shipped.
	 *
	 * @return void
	 */
	public function test_slot_detection_survives_quoting_and_casing() {
		$existing = "<TITLE>Hi</TITLE>\n<LINK REL=canonical HREF='https://example.org/'>\n<meta name='description' content='x'>";

		$tags = Citecue_Seo_Head::merge( $existing, $this->block() );
		$all  = implode( "\n", $tags );

		$this->assertStringNotContainsString( '<title', $all );
		$this->assertStringNotContainsString( 'rel="canonical"', $all );
		$this->assertStringNotContainsString( 'name="description"', $all );
	}

	/**
	 * A plain `<script>` is not structured data, so it must not stand in for
	 * the JSON-LD slot — a site with an analytics snippet in its head would
	 * otherwise silently lose CiteCue's schema.
	 *
	 * @return void
	 */
	public function test_plain_script_does_not_occupy_the_jsonld_slot() {
		$existing = '<script src="https://example.org/analytics.js"></script>';

		$all = implode( "\n", Citecue_Seo_Head::merge( $existing, $this->block() ) );

		$this->assertStringContainsString( 'application/ld+json', $all );
	}

	/**
	 * The response is trusted markup for this site's own head, but it arrives
	 * over the network and lands in a human's browser, so only recognized tag
	 * shapes may be printed.
	 *
	 * @return void
	 */
	public function test_unexpected_markup_in_the_block_is_dropped() {
		$block = implode(
			"\n",
			array(
				'<script>alert(1)</script>',
				'<script type="text/javascript" src="https://evil.example/x.js"></script>',
				'<link rel="stylesheet" href="https://evil.example/x.css" />',
				'<meta http-equiv="refresh" content="0;url=https://evil.example/" />',
				'<meta data-citecue="og" property="og:title" content="Acme" />',
			)
		);

		$tags = Citecue_Seo_Head::merge( '', $block );

		$this->assertCount( 1, $tags );
		$this->assertStringContainsString( 'og:title', $tags[0] );
	}

	/**
	 * A block that somehow carries the same slot twice contributes it once.
	 *
	 * @return void
	 */
	public function test_block_internal_duplicates_collapse() {
		$block = '<link data-citecue="canonical" rel="canonical" href="https://example.org/a" />'
			. '<link data-citecue="canonical" rel="canonical" href="https://example.org/b" />';

		$tags = Citecue_Seo_Head::merge( '', $block );

		$this->assertCount( 1, $tags );
		$this->assertStringContainsString( 'example.org/a', $tags[0] );
	}

	/**
	 * The escape hatch for a site that wants CiteCue to win: remove the other
	 * plugin's tag yourself, then re-add ours through the filter.
	 *
	 * @return void
	 */
	public function test_filter_can_override_the_gap_fill() {
		$existing = '<title>Theirs</title>';

		add_filter(
			'citecue_seo_head_tags',
			static function ( $tags, $block ) {
				$tags[] = '<meta name="citecue-test" content="1" />';
				unset( $block );
				return $tags;
			},
			10,
			2
		);

		$all = implode( "\n", Citecue_Seo_Head::merge( $existing, $this->block() ) );

		$this->assertStringContainsString( 'citecue-test', $all );
	}
}
