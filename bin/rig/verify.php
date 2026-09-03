<?php
/**
 * Asserts, against a real rendered page, the things the unit suite cannot see:
 * that the block reaches the browser at all, through this WordPress's own
 * output-buffer mechanism, and lands in the right half of the document.
 *
 * @package Citecue
 */

$rig  = getenv( 'RIG_DIR' );
$port = getenv( 'WP_PORT' );
$url  = "http://127.0.0.1:$port/protein-guide/";

require_once $rig . '/wp/wp-load.php';

$failures = array();

/**
 * Records one assertion.
 *
 * @param string $name What is being asserted.
 * @param bool   $ok   Whether it held.
 * @param string $note Detail printed either way.
 */
function check( $name, $ok, $note = '' ) {
	global $failures;
	printf( "  %s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, '' !== $note ? "  ($note)" : '' );
	if ( ! $ok ) {
		$failures[] = $name;
	}
}

/**
 * Fetches the rendered page.
 *
 * @param string $url Page to fetch.
 * @return string
 */
function render( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
		)
	);
	$html = curl_exec( $ch );
	curl_close( $ch );
	return (string) $html;
}

/**
 * Runs the plugin's due background jobs; the rig disables real WP-Cron.
 *
 * The cache drop is load-bearing. This process bootstrapped WordPress before
 * the cold page view scheduled anything, so its options cache still holds the
 * cron array from before — and reading that would find no jobs and silently
 * verify nothing. The page view happened in the server's process, not this one.
 */
function run_jobs() {
	wp_cache_delete( 'cron', 'options' );
	wp_cache_delete( 'alloptions', 'options' );

	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			if ( 0 !== strpos( $hook, 'citecue' ) ) {
				continue;
			}
			foreach ( $events as $event ) {
				do_action_ref_array( $hook, (array) $event['args'] );
				wp_unschedule_event( $ts, $hook, (array) $event['args'] );
			}
		}
	}
}

echo "Verifying against $url\n";

// A visitor never waits on CiteCue: the first view of an uncached URL injects
// nothing and queues the fetch instead.
$cold = render( $url );
check( 'cold view injects nothing', false === strpos( $cold, 'data-citecue="page-enhancement"' ) );

run_jobs();

$warm     = render( $url );
$sections = preg_match_all( '#<section[^>]*data-citecue="page-enhancement"#i', $warm );
check( 'warm view injects the block exactly once', 1 === $sections, "found $sections" );

$head_end = stripos( $warm, '</head>' );
$body_end = strripos( $warm, '</body>' );
$at       = stripos( $warm, '<section data-citecue="page-enhancement"' );
check( 'block is in the body, before </body>', $at > $head_end && $at < $body_end );
// Conditional on what the server actually sent. The two halves are
// independent, and a project with a block but no enriched page is a legitimate
// answer — `head: ''` with a populated `body` is exactly what the endpoint
// returns then. Asserting a head unconditionally would fail the plugin for the
// server's correct behaviour.
$cached    = Citecue_Plugin::instance()->cache->get_seo_head( $url );
$sent_head = is_array( $cached ) ? (string) $cached['block'] : '';
if ( '' === $sent_head ) {
	echo "  SKIP head half (this fixture's project has no enriched page, so the server sent head: '')\n";
} else {
	check( 'head half injected too', false !== strpos( $warm, 'og:title' ) );
}

// The FAQ payload is the GEO point of the block, and the first casualty of any
// reflex to sanitize the response.
preg_match( '#<script type="application/ld\+json" data-citecue="page-enhancement-faq">(.*?)</script>#s', $warm, $m );
$ld = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
check( 'FAQPage JSON-LD survives intact', is_array( $ld ) && 'FAQPage' === ( $ld['@type'] ?? '' ) );

// Repeat views must not accumulate copies.
$again = preg_match_all( '#<section[^>]*data-citecue="page-enhancement"#i', render( $url ) );
check( 'a second view does not add a second copy', 1 === $again, "found $again" );

// A page that already carries the section keeps exactly the one it has.
$page = get_page_by_path( 'protein-guide' );
$kept = $page->post_content;
wp_update_post(
	array(
		'ID'           => $page->ID,
		'post_content' => '<p>Ours.</p><section data-citecue="page-enhancement"><h2>Already here</h2></section>',
	)
);
$owned = render( $url );
check(
	'a page that already has the block is left alone',
	1 === preg_match_all( '#<section[^>]*data-citecue="page-enhancement"#i', $owned )
		&& false !== strpos( $owned, 'Already here' )
		&& false === strpos( $owned, 'About Acme' ),
	// Guards against passing vacuously: this only means anything while the
	// block is cached and would otherwise have been placed.
	'cached block present: ' . ( false !== strpos( $warm, 'About Acme' ) ? 'yes' : 'NO — check is vacuous' )
);

// A page that merely QUOTES the marker has not been given a block, and must
// still get one.
wp_update_post(
	array(
		'ID'           => $page->ID,
		'post_content' => '<p>Add <code>&lt;section data-citecue="page-enhancement"&gt;</code> to your template.</p>',
	)
);
$quoted = render( $url );
check(
	'a page quoting the marker still gets its block',
	1 === preg_match_all( '#<section[^>]*data-citecue="page-enhancement"#i', $quoted )
		&& false !== strpos( $quoted, 'About Acme' )
);

wp_update_post(
	array(
		'ID'           => $page->ID,
		'post_content' => $kept,
	)
);

// The request the plugin actually made, not just what it did with the answer.
// Only the stub keeps a log for us, so these are skipped rather than failed
// when the rig is pointed at a real CiteCue.
if ( getenv( 'RIG_API_BASE' ) ) {
	echo "  SKIP request-log checks (real CiteCue keeps no log for us)\n";
} else {
	$log = @file_get_contents( $rig . '/fake-api/requests.log' );
	check( 'delivery read carried the org key as a Bearer token', false !== strpos( (string) $log, 'Bearer ck_live_rig' ) );
	// Built with strtolower() rather than written as a literal. The channel is a
	// protocol value the plugin sends lowercased, and phpcbf's prose sniff
	// rewrites a bare "WordPress" literal into "WordPress" — which silently
	// turns this into an assertion that fails against correct behaviour.
	$expected_channel = strtolower( 'WordPress' );
	$sent_channel     = '';
	foreach ( explode( "\n", trim( (string) $log ) ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && false !== strpos( (string) ( $entry['path'] ?? '' ), '/seo-head' ) ) {
			$sent_channel = (string) ( $entry['channel'] ?? '' );
		}
	}
	check( 'delivery read identified the channel', $expected_channel === $sent_channel, "sent '$sent_channel'" );
}

echo "\n";
if ( $failures ) {
	printf( "%d check(s) failed: %s\n", count( $failures ), implode( '; ', $failures ) );
	exit( 1 );
}
echo "all checks passed\n";
