<?php
/**
 * A stand-in for app.citecue.com, for exercising the plugin's delivery paths
 * against a real HTTP round trip. Logs every request so the test can assert on
 * what the plugin actually sent, not just on what it did with the answer.
 *
 * Runs under the PHP built-in server, OUTSIDE WordPress — nothing here may
 * call a WordPress function.
 *
 * @package Citecue
 */

$log  = __DIR__ . '/requests.log';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

$headers = function_exists( 'getallheaders' ) ? getallheaders() : array();
file_put_contents(
	$log,
	citecue_rig_json(
		array(
			'path'          => $_SERVER['REQUEST_URI'],
			'method'        => $_SERVER['REQUEST_METHOD'],
			'authorization' => $headers['Authorization'] ?? ( $headers['authorization'] ?? '' ),
			'channel'       => $headers['X-Citecue-Channel'] ?? ( $headers['x-citecue-channel'] ?? '' ),
		)
	) . "\n",
	FILE_APPEND
);

/**
 * JSON for the request log. Named for what it is: WordPress is not loaded
 * here, so wp_json_encode() does not exist.
 *
 * @param mixed $value Value to encode.
 * @return string
 */
function citecue_rig_json( $value ) {
	return (string) json_encode( $value, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded in this process.
}

/**
 * The block, in the shape CiteCue composes them: a marked section of grounded
 * facts and FAQ entries, carrying its own FAQPage JSON-LD. The script element
 * is the part any "sanitize the remote HTML" reflex would destroy, so it is
 * here on purpose.
 */
function citecue_block() {
	$faq = json_encode(
		array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'What does Acme make?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Protein bars, made in Leeds.',
					),
				),
			),
		),
		JSON_UNESCAPED_SLASHES
	);

	return '<section data-citecue="page-enhancement">'
		. '<h2>About Acme</h2>'
		. '<ul><li>Founded 2019</li><li>Made in Leeds</li></ul>'
		. '<details><summary>What does Acme make?</summary><p>Protein bars, made in Leeds.</p></details>'
		. '<script type="application/ld+json" data-citecue="page-enhancement-faq">' . $faq . '</script>'
		. '</section>';
}

header( 'Cache-Control: private, max-age=300' );

if ( '/api/delivery/v2/seo-head' === $path ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'head'   => '<meta data-citecue="og" property="og:title" content="Acme — protein bars from Leeds" />',
			'body'   => citecue_block(),
			'dedupe' => array(
				'ogProperties' => array( 'og:title' ),
				'jsonLdTypes'  => array( 'FAQPage' ),
			),
		),
		JSON_UNESCAPED_SLASHES
	);
	exit;
}

if ( '/api/delivery/v2/config' === $path ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'projects' => array(
				array(
					'publicKey'    => 'pk_localtest',
					'domain'       => '127.0.0.1',
					'enabled'      => true,
					'serveLlmsTxt' => true,
					'contentPush'  => true,
				),
			),
		)
	);
	exit;
}

if ( '/api/delivery/v1/crawlers' === $path ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'version' => 1,
			'tokens'  => array( 'GPTBot', 'ClaudeBot' ),
		)
	);
	exit;
}

http_response_code( 404 );
echo 'not_optimized';
