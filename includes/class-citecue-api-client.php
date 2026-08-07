<?php
/**
 * HTTP client for the CiteCue delivery API.
 *
 * Consumes the authenticated v2 delivery channel
 * (`Authorization: Bearer ck_live_…` + `X-Citecue-Channel: wordpress`):
 *   GET /api/delivery/v2/config    — org projects (connection test / selection)
 *   GET /api/delivery/v2/page      — optimized page for an AI crawler (ETag/304)
 *   GET /api/delivery/v2/seo-head  — enriched head block for one URL (humans)
 *   GET /api/delivery/v2/llms.txt  — llms.txt body (ETag/304)
 * the public keyless registry feed:
 *   GET /api/delivery/v1/crawlers  — AI crawler UA token registry
 * and the code-gated pairing exchange:
 *   POST /api/delivery/v2/connect/claim — one-time code → this site's key
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
	 * Performs a JSON POST and normalizes the response.
	 *
	 * @param string $url     Full URL.
	 * @param array  $payload Body, JSON-encoded.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error {status:int, body:string}
	 */
	private function post_json( $url, array $payload, $timeout ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'user-agent'  => 'CiteCue-WordPress/' . CITECUE_VERSION . ' (+' . home_url( '/' ) . ')',
				'headers'     => array(
					'Content-Type'      => 'application/json',
					'X-Citecue-Channel' => 'wordpress',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * POST /api/delivery/v2/connect/claim — spends a one-time connect code.
	 *
	 * Unauthenticated by design: the code *is* the credential, which is why it
	 * is single-use, short-lived and bound server-side to the site URL sent
	 * here. Redirection is disabled — a redirect would be a chance to replay
	 * the ingest secret at somewhere other than the configured API base.
	 *
	 * @param string $code One-time code from the connect redirect.
	 * @param array  $site {site_url, rest_url, ingest_secret, plugin_version, woocommerce, seo_head}.
	 * @return array|WP_Error {apiKey, publicKey, domain, ingest?}
	 */
	public function claim_connect_code( $code, array $site ) {
		$result = $this->post_json(
			$this->settings->api_base() . '/api/delivery/v2/connect/claim',
			array_merge( array( 'code' => (string) $code ), $site ),
			15
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['body'], true );
		$data = is_array( $data ) ? $data : array();

		if ( 200 !== $result['status'] ) {
			$reasons  = array(
				'invalid_code'  => __( 'That connection link is not valid. Start the connection again from WordPress.', 'citecue-ai-auto-fix' ),
				'code_used'     => __( 'That connection link has already been used. Start the connection again from WordPress.', 'citecue-ai-auto-fix' ),
				'code_expired'  => __( 'That connection link expired. Start the connection again from WordPress.', 'citecue-ai-auto-fix' ),
				'site_mismatch' => __( 'CiteCue issued that link for a different site address than this one.', 'citecue-ai-auto-fix' ),
			);
			$code_key = isset( $data['error'] ) ? (string) $data['error'] : '';

			if ( isset( $reasons[ $code_key ] ) ) {
				return new WP_Error( 'citecue_connect_' . $code_key, $reasons[ $code_key ] );
			}
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'citecue_http_error', sprintf( __( 'Unexpected response from CiteCue (HTTP %d).', 'citecue-ai-auto-fix' ), $result['status'] ) );
		}

		if ( empty( $data['apiKey'] ) || empty( $data['publicKey'] ) ) {
			return new WP_Error( 'citecue_bad_payload', __( 'CiteCue returned an unexpected payload.', 'citecue-ai-auto-fix' ) );
		}

		$connection = array(
			'apiKey'    => sanitize_text_field( (string) $data['apiKey'] ),
			'publicKey' => sanitize_text_field( (string) $data['publicKey'] ),
			'domain'    => sanitize_text_field( (string) ( isset( $data['domain'] ) ? $data['domain'] : '' ) ),
		);

		// Absent, not false, when CiteCue says nothing about content pushes —
		// the caller must be able to tell "denied" from "not mentioned".
		if ( isset( $data['ingest'] ) ) {
			$connection['ingest'] = (bool) $data['ingest'];
		}

		return $connection;
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
			return new WP_Error( 'citecue_invalid_key', __( 'CiteCue rejected the API key. Check it under CiteCue → Settings → API keys.', 'citecue-ai-auto-fix' ) );
		}
		if ( 200 !== $result['status'] ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'citecue_http_error', sprintf( __( 'Unexpected response from CiteCue (HTTP %d).', 'citecue-ai-auto-fix' ), $result['status'] ) );
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['projects'] ) || ! is_array( $data['projects'] ) ) {
			return new WP_Error( 'citecue_bad_payload', __( 'CiteCue returned an unexpected payload.', 'citecue-ai-auto-fix' ) );
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
	 * GET /api/delivery/v2/seo-head — the enriched head block for one URL.
	 *
	 * CiteCue has three distinct empty answers here and they are not
	 * interchangeable, so they are passed up untouched rather than collapsed:
	 * 204 means the project is fine but there is nothing to inject right now
	 * (audience not `all`, or no enriched page for this URL), 404 is the same
	 * "unknown key / disabled project / bad input" sentinel the page endpoint
	 * uses, and 401 is a rejected key. A 204 carries no body at all.
	 *
	 * @param string $url Absolute URL of the page being rendered.
	 * @return array|WP_Error {status:int, head:string}
	 */
	public function get_seo_head( $url ) {
		$endpoint = add_query_arg(
			array(
				'k' => rawurlencode( (string) $this->settings->get( 'public_key' ) ),
				'u' => rawurlencode( (string) $url ),
			),
			$this->settings->api_base() . '/api/delivery/v2/seo-head'
		);

		$result = $this->get( $endpoint, $this->auth_headers(), $this->serve_timeout() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$head = '';
		if ( 200 === $result['status'] ) {
			$data = json_decode( $result['body'], true );
			if ( is_array( $data ) && isset( $data['head'] ) && is_string( $data['head'] ) ) {
				$head = $data['head'];
			}
		}

		return array(
			'status' => $result['status'],
			'head'   => $head,
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
			return new WP_Error( 'citecue_http_error', sprintf( __( 'Unexpected response from CiteCue (HTTP %d).', 'citecue-ai-auto-fix' ), $result['status'] ) );
		}

		$data = json_decode( $result['body'], true );
		if ( ! is_array( $data ) || empty( $data['tokens'] ) || ! is_array( $data['tokens'] ) ) {
			return new WP_Error( 'citecue_bad_payload', __( 'CiteCue returned an unexpected payload.', 'citecue-ai-auto-fix' ) );
		}

		return $data;
	}
}
