<?php
/**
 * HTTP client for the CiteCue delivery API.
 *
 * Consumes the authenticated v2 delivery channel
 * (`Authorization: Bearer ck_live_…` + `X-Citecue-Channel: wordpress`):
 *   GET /api/delivery/v2/config    — org projects (connection test / selection)
 *   GET /api/delivery/v2/page      — optimized page for an AI crawler (ETag/304)
 *   GET /api/delivery/v2/llms.txt  — llms.txt body (ETag/304)
 * and the public keyless registry feed:
 *   GET /api/delivery/v1/crawlers  — AI crawler UA token registry
 *
 * The v2 page endpoint records the crawler hit server-side (served on
 * 200/304, passthrough on 404), so one request both serves and reports.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery API client.
 */
class Citecue_Api_Client {

	/**
	 * Settings.
	 *
	 * @var Citecue_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Citecue_Settings $settings Settings.
	 */
	public function __construct( Citecue_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Common headers for authenticated v2 requests.
	 *
	 * @return array
	 */
	private function auth_headers() {
		return array(
			'Authorization'     => 'Bearer ' . (string) $this->settings->get( 'api_key' ),
			'X-Citecue-Channel' => 'wordpress',
		);
	}

	/**
	 * Request timeout for the crawler-serving hot path, in seconds. Kept short:
	 * a slow CiteCue response only ever delays an AI bot, never a human, but
	 * there is no reason to hold a PHP worker longer than this.
	 *
	 * @return int
	 */
	private function serve_timeout() {
		/**
		 * Filters the delivery request timeout (seconds) on the serving path.
		 *
		 * @param int $timeout Seconds.
		 */
		return max( 1, (int) apply_filters( 'citecue_serve_timeout', 3 ) );
	}

	/**
	 * Performs a GET and normalizes the response.
	 *
	 * @param string $url     Full URL.
	 * @param array  $headers Request headers.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error {status:int, body:string, headers:CaseInsensitiveDictionary|array}
	 */
	private function get( $url, array $headers, $timeout ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 2,
				'user-agent'  => 'CiteCue-WordPress/' . CITECUE_VERSION . ' (+' . home_url( '/' ) . ')',
				'headers'     => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * GET /api/delivery/v2/config — the API key's org projects.
	 *
	 * @return array|WP_Error List of projects ({publicKey, domain, enabled, serveLlmsTxt}).
	 */
	public function get_config() {
		$result = $this->get( $this->settings->api_base() . '/api/delivery/v2/config', $this->auth_headers(), 10 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 401 === $result['status'] ) {
			return new WP_Error( 'citecue_invalid_key', __( 'CiteCue rejected the API key. Check it under CiteCue → Settings → API keys.', 'citecue' ) );
		}
		if ( 200 !== $result['status'] ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'citecue_http_error', sprintf( __( 'Unexpected response from CiteCue (HTTP %d).', 'citecue' ), $result['status'] ) );
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['projects'] ) || ! is_array( $data['projects'] ) ) {
			return new WP_Error( 'citecue_bad_payload', __( 'CiteCue returned an unexpected payload.', 'citecue' ) );
		}

		return $data['projects'];
	}

	/**
	 * GET /api/delivery/v2/page — the optimized page for a crawler request.
	 * A 304 means "your cached body is still current" and is still counted as
	 * a served hit by CiteCue; 404 is the pass-through sentinel.
	 *
	 * @param string $url           Absolute URL of the page being requested.
	 * @param string $crawler_token Matched UA token (server renormalizes it).
	 * @param string $etag          Cached ETag for If-None-Match, or ''.
	 * @return array|WP_Error {status, body, etag, mode}
	 */
	public function get_page( $url, $crawler_token, $etag = '' ) {
		$endpoint = add_query_arg(
			array(
				'k' => rawurlencode( (string) $this->settings->get( 'public_key' ) ),
				'u' => rawurlencode( $url ),
				'b' => rawurlencode( $crawler_token ),
			),
			$this->settings->api_base() . '/api/delivery/v2/page'
		);

		$headers = $this->auth_headers();
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$result = $this->get( $endpoint, $headers, $this->serve_timeout() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response_headers = $result['headers'];
		return array(
			'status' => $result['status'],
			'body'   => $result['body'],
			'etag'   => isset( $response_headers['etag'] ) ? (string) $response_headers['etag'] : '',
			'mode'   => isset( $response_headers['x-citecue-mode'] ) ? (string) $response_headers['x-citecue-mode'] : '',
		);
	}

	/**
	 * GET /api/delivery/v2/llms.txt — the project's llms.txt.
	 *
	 * @param string $etag Cached ETag for If-None-Match, or ''.
	 * @return array|WP_Error {status, body, etag}
	 */
	public function get_llms_txt( $etag = '' ) {
		$endpoint = add_query_arg(
			array( 'k' => rawurlencode( (string) $this->settings->get( 'public_key' ) ) ),
			$this->settings->api_base() . '/api/delivery/v2/llms.txt'
		);

		$headers = $this->auth_headers();
		if ( '' !== $etag ) {
			$headers['If-None-Match'] = $etag;
		}

		$result = $this->get( $endpoint, $headers, $this->serve_timeout() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response_headers = $result['headers'];
		return array(
			'status' => $result['status'],
			'body'   => $result['body'],
			'etag'   => isset( $response_headers['etag'] ) ? (string) $response_headers['etag'] : '',
		);
	}

	/**
	 * GET /api/delivery/v1/crawlers — public crawler registry feed.
	 *
	 * @return array|WP_Error {version:int, tokens:string[]}
	 */
	public function get_crawler_registry() {
		$result = $this->get( $this->settings->api_base() . '/api/delivery/v1/crawlers', array(), 10 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['status'] ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'citecue_http_error', sprintf( __( 'Unexpected response from CiteCue (HTTP %d).', 'citecue' ), $result['status'] ) );
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || empty( $data['tokens'] ) || ! is_array( $data['tokens'] ) ) {
			return new WP_Error( 'citecue_bad_payload', __( 'CiteCue returned an unexpected payload.', 'citecue' ) );
		}

		return $data;
	}
}
