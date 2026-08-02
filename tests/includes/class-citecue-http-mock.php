<?php
/**
 * Fake CiteCue delivery API.
 *
 * Every outbound call the plugin makes goes through `wp_remote_get()`, so a
 * single `pre_http_request` filter is enough to stand in for the whole API —
 * no HTTP server, no fixtures on disk. Responses are queued per endpoint and
 * consumed in order (the last queued response repeats once the queue drains,
 * which keeps "same response every time" tests to one line).
 *
 * An outbound request with nothing queued for it is a bug in the code under
 * test — the mock throws so it surfaces as a failure at the exact call site
 * instead of a confusing downstream assertion.
 *
 * @package Citecue
 */

/**
 * Queued fake for the CiteCue delivery API.
 */
class Citecue_Http_Mock {

	/**
	 * Queued responses, keyed by endpoint name.
	 *
	 * @var array<string,array>
	 */
	private $queues = array();

	/**
	 * Recorded outbound requests.
	 *
	 * @var array<int,array{endpoint:string,url:string,args:array}>
	 */
	private $requests = array();

	/**
	 * Whether the filter is currently attached.
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Attaches the intercept.
	 *
	 * @return void
	 */
	public function enable() {
		if ( ! $this->enabled ) {
			add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
			$this->enabled = true;
		}
	}

	/**
	 * Detaches the intercept and drops all state.
	 *
	 * @return void
	 */
	public function disable() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		$this->enabled  = false;
		$this->queues   = array();
		$this->requests = array();
	}

	/**
	 * Queues a response for an endpoint.
	 *
	 * @param string $endpoint One of page|llms|config|crawlers.
	 * @param int    $status   HTTP status code.
	 * @param string $body     Response body.
	 * @param array  $headers  Response headers (case-insensitive).
	 * @return $this
	 */
	public function queue( $endpoint, $status, $body = '', array $headers = array() ) {
		$this->queues[ $endpoint ][] = array(
			'status'  => (int) $status,
			'body'    => (string) $body,
			'headers' => $headers,
		);
		return $this;
	}

	/**
	 * Queues a transport failure (timeout, DNS error, refused connection).
	 *
	 * @param string $endpoint One of page|llms|config|crawlers.
	 * @param string $message  Error message.
	 * @return $this
	 */
	public function queue_error( $endpoint, $message = 'Operation timed out' ) {
		$this->queues[ $endpoint ][] = new WP_Error( 'http_request_failed', $message );
		return $this;
	}

	/**
	 * The recorded requests, optionally filtered to one endpoint.
	 *
	 * @param string|null $endpoint Endpoint name, or null for all.
	 * @return array<int,array{endpoint:string,url:string,args:array}>
	 */
	public function requests( $endpoint = null ) {
		if ( null === $endpoint ) {
			return $this->requests;
		}
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $endpoint ) {
					return $request['endpoint'] === $endpoint;
				}
			)
		);
	}

	/**
	 * How many requests were made (optionally to one endpoint).
	 *
	 * @param string|null $endpoint Endpoint name, or null for all.
	 * @return int
	 */
	public function count( $endpoint = null ) {
		return count( $this->requests( $endpoint ) );
	}

	/**
	 * The most recent request to an endpoint, or null.
	 *
	 * @param string $endpoint Endpoint name.
	 * @return array|null
	 */
	public function last( $endpoint ) {
		$matching = $this->requests( $endpoint );
		return $matching ? end( $matching ) : null;
	}

	/**
	 * Intercepts `wp_remote_*` and answers from the queue.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|WP_Error
	 * @throws RuntimeException When the code under test makes an unexpected call.
	 */
	public function intercept( $preempt, $args, $url ) {
		$endpoint = self::classify( $url );

		$this->requests[] = array(
			'endpoint' => $endpoint,
			'url'      => $url,
			'args'     => $args,
		);

		if ( empty( $this->queues[ $endpoint ] ) ) {
			throw new RuntimeException( "Unexpected outbound request to [{$endpoint}] {$url}" );
		}

		// Keep the final queued response in place so repeated calls keep
		// getting it; earlier entries are consumed one at a time.
		$response = count( $this->queues[ $endpoint ] ) > 1
			? array_shift( $this->queues[ $endpoint ] )
			: $this->queues[ $endpoint ][0];

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'headers'  => self::headers( $response['headers'] ),
			'body'     => $response['body'],
			'response' => array(
				'code'    => $response['status'],
				'message' => get_status_header_desc( $response['status'] ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Maps a request URL to the endpoint it belongs to.
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	private static function classify( $url ) {
		if ( false !== strpos( $url, '/api/delivery/v2/connect/claim' ) ) {
			return 'connect';
		}
		if ( false !== strpos( $url, '/api/delivery/v2/page' ) ) {
			return 'page';
		}
		if ( false !== strpos( $url, '/api/delivery/v2/llms.txt' ) ) {
			return 'llms';
		}
		if ( false !== strpos( $url, '/api/delivery/v2/config' ) ) {
			return 'config';
		}
		if ( false !== strpos( $url, '/api/delivery/v1/crawlers' ) ) {
			return 'crawlers';
		}
		// The install check requests this very site, so a loopback is a
		// distinct endpoint rather than an unexpected call.
		if ( 0 === strpos( $url, home_url( '/' ) ) ) {
			return 'loopback';
		}
		return 'other';
	}

	/**
	 * Wraps headers the way the HTTP API really does, so header lookups in the
	 * code under test are exercised case-insensitively.
	 *
	 * @param array $headers Header map.
	 * @return \WpOrg\Requests\Utility\CaseInsensitiveDictionary|array
	 */
	private static function headers( array $headers ) {
		if ( class_exists( '\WpOrg\Requests\Utility\CaseInsensitiveDictionary' ) ) {
			return new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers );
		}
		return $headers;
	}
}
