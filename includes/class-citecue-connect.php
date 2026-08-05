<?php
/**
 * One-click connect: the pairing handshake that replaces copy-pasting an API
 * key into WordPress and a shared secret back into CiteCue.
 *
 * The exchange is deliberately split in two, because only one half may travel
 * through the customer's browser:
 *
 *   1. `start()` mints a single-use state token and sends the admin to
 *      `{app}/connect/wordpress?site=…&state=…&return=…`. CiteCue already
 *      knows who they are, so it can show "connect example.com to project X"
 *      and, on confirm, bounce back to `return` with a one-time code.
 *   2. `claim()` spends that code server-to-server. The code is bearer-grade —
 *      whoever presents it receives an API key — so it is single-use, short
 *      lived, and CiteCue binds it to the site URL presented here. The ingest
 *      secret rides in this request body and never appears in a redirect.
 *
 * `claim()` and `verify_install()` return their result rather than emitting
 * anything, mirroring the decide()/serve() split in Citecue_Proxy: the
 * handlers in Citecue_Admin do the redirecting, these do the work the tests
 * can drive.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pairing handshake with the CiteCue app.
 */
class Citecue_Connect {

	/**
	 * Transient holding the in-flight handshake's state token.
	 */
	const STATE_TRANSIENT = 'citecue_connect_state';

	/**
	 * How long an unfinished handshake stays claimable.
	 */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Option caching the last loopback verification result.
	 */
	const VERIFY_OPTION = 'citecue_install_verified';

	/**
	 * A crawler UA the plugin's own registry matches, used to make the
	 * loopback request look like the traffic being verified.
	 */
	const VERIFY_USER_AGENT = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';

	/**
	 * The exact X-Citecue value Citecue_Llms_Txt::serve() emits. Nothing else
	 * counts: when llms.txt falls through — switched off here, or 404 from
	 * CiteCue — the crawler proxy is next on `template_redirect` and may
	 * answer the same URL with `X-Citecue: served`. Accepting any marker
	 * would read that as proof llms.txt works.
	 */
	const VERIFY_MARKER = 'llms-txt';

	/**
	 * Plugin container.
	 *
	 * @var Citecue_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Citecue_Plugin $plugin Plugin container.
	 */
	public function __construct( Citecue_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Mints the handshake state and returns the CiteCue URL to send the admin
	 * to. The state is bound to the current user so a code redirected into a
	 * different admin's browser cannot be claimed.
	 *
	 * @param string $return_url Admin URL CiteCue should redirect back to.
	 * @return string
	 */
	public function start( $return_url ) {
		$state = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::STATE_TRANSIENT,
			array(
				'state'   => $state,
				'user_id' => get_current_user_id(),
			),
			self::STATE_TTL
		);

		return add_query_arg(
			array(
				'site'   => rawurlencode( home_url( '/' ) ),
				'state'  => rawurlencode( $state ),
				'return' => rawurlencode( $return_url ),
				'v'      => rawurlencode( CITECUE_VERSION ),
			),
			$this->plugin->settings->api_base() . '/connect/wordpress'
		);
	}

	/**
	 * Checks the state token echoed back by CiteCue and consumes it.
	 *
	 * Consumed on failure too: a state that has been guessed at once is burnt,
	 * so a wrong guess cannot be retried against the same handshake.
	 *
	 * @param string $state State echoed back in the redirect.
	 * @return bool
	 */
	public function verify_state( $state ) {
		$stored = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
			return false;
		}
		if ( get_current_user_id() !== (int) $stored['user_id'] ) {
			return false;
		}

		return hash_equals( (string) $stored['state'], (string) $state );
	}

	/**
	 * Spends a one-time connect code: sends CiteCue everything it needs to
	 * push content back (site URL, REST base, ingest secret) and stores the
	 * credentials it returns.
	 *
	 * @param string $code One-time code from the redirect.
	 * @return array|WP_Error {publicKey, domain, ingest} on success.
	 */
	public function claim( $code ) {
		$settings = $this->plugin->settings;

		$result = $this->plugin->api->claim_connect_code(
			$code,
			array(
				'site_url'       => home_url( '/' ),
				'rest_url'       => rest_url( 'citecue/v1/' ),
				'ingest_secret'  => $settings->ensure_ingest_secret(),
				'plugin_version' => CITECUE_VERSION,
				'woocommerce'    => class_exists( 'WooCommerce' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$update = array(
			'api_key'        => $result['apiKey'],
			'public_key'     => $result['publicKey'],
			'project_domain' => $result['domain'],
		);

		// CiteCue's connect screen is where the customer is told that content
		// can be pushed into their site, so it is the only place that may turn
		// ingest on. A response that omits the flag leaves the opt-in exactly
		// as it was — silence never grants write access.
		if ( isset( $result['ingest'] ) ) {
			$update['ingest_enabled'] = (bool) $result['ingest'];
		}

		$settings->update( $update );

		// A fresh key retires any earlier rejection and the back-off it opened.
		delete_option( 'citecue_auth_failed' );
		delete_transient( 'citecue_circuit' );
		update_option( 'citecue_last_config_at', time(), false );

		return $result;
	}

	/**
	 * Undoes a connection locally: forgets the credentials so the settings
	 * screen returns to its "not connected" state.
	 *
	 * The ingest secret is deliberately kept — regenerating it is a separate,
	 * explicit action, and keeping it means reconnecting the same site does
	 * not invalidate a secret CiteCue may still hold.
	 *
	 * @return void
	 */
	public function disconnect() {
		$this->plugin->settings->update(
			array(
				// Both spellings on purpose. An empty api_key means "keep the
				// stored one" to sanitize(), so erasing it needs the explicit
				// flag — but sanitize() only runs once register_setting() has,
				// and the empty value is what a write that bypasses the filter
				// has to see. Either path must end up with no key.
				'api_key'        => '',
				'api_key_clear'  => 1,
				'public_key'     => '',
				'project_domain' => '',
				'ingest_enabled' => false,
			)
		);

		delete_option( 'citecue_projects_cache' );
		delete_option( 'citecue_auth_failed' );
		delete_option( self::VERIFY_OPTION );
		$this->plugin->cache->flush();
	}

	/**
	 * Runs the check the README used to ask customers to run by hand: request
	 * this site's own llms.txt as an AI crawler and look for the marker header
	 * the plugin sets.
	 *
	 * A failure here is nearly always a full-page cache or CDN answering ahead
	 * of PHP, which is exactly the misconfiguration worth surfacing in the
	 * admin rather than in a terminal.
	 *
	 * @return array {ok:bool, skipped:bool, status:int, marker:string, message:string, checked_at:int}
	 */
	public function verify_install() {
		$result               = $this->run_verification();
		$result['checked_at'] = time();
		update_option( self::VERIFY_OPTION, $result, false );

		return $result;
	}

	/**
	 * The check itself, without the bookkeeping.
	 *
	 * @return array {ok:bool, skipped:bool, status:int, marker:string, message:string}
	 */
	private function run_verification() {
		// The only thing this check can prove is that llms.txt is served, so
		// with llms.txt switched off it proves nothing. Say that rather than
		// reporting a failure the site did not have.
		if ( ! $this->plugin->settings->get( 'llms_txt_enabled' ) ) {
			return self::verdict(
				false,
				0,
				'',
				__( 'This check asks for the site’s llms.txt, which is switched off below. Turn on “Serve llms.txt” to run it.', 'citecue-ai-auto-fix' ),
				true
			);
		}

		$response = wp_remote_get(
			home_url( '/llms.txt' ),
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'user-agent'  => self::VERIFY_USER_AGENT,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				// Loopback requests hit whatever certificate the site presents
				// to itself, which on staging is routinely self-signed. Core's
				// own loopback checks (WP_Site_Health) make the same trade:
				// nothing secret is sent and nothing but a header is read.
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::verdict( false, 0, '', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$marker = (string) wp_remote_retrieve_header( $response, 'x-citecue' );

		if ( 200 === $status && self::VERIFY_MARKER === $marker ) {
			return self::verdict( true, $status, $marker, '' );
		}

		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code. */
			return self::verdict( false, $status, $marker, sprintf( __( 'The site answered with HTTP %d.', 'citecue-ai-auto-fix' ), $status ) );
		}

		if ( '' === $marker ) {
			return self::verdict(
				false,
				$status,
				$marker,
				__( 'The response did not carry the “x-citecue” header. A full-page cache or CDN in front of PHP is the usual cause — exclude AI-crawler user agents from it, or use CiteCue’s Cloudflare Worker instead.', 'citecue-ai-auto-fix' )
			);
		}

		return self::verdict(
			false,
			$status,
			$marker,
			sprintf(
				/* translators: 1: header value received, 2: header value expected. */
				__( 'Something other than the llms.txt handler answered — the response carried “x-citecue: %1$s” rather than “%2$s”. CiteCue most likely has no llms.txt for this project yet.', 'citecue-ai-auto-fix' ),
				$marker,
				self::VERIFY_MARKER
			)
		);
	}

	/**
	 * One verification result.
	 *
	 * @param bool   $ok      Whether the site answered as it should.
	 * @param int    $status  HTTP status, 0 when no response was obtained.
	 * @param string $marker  X-Citecue header value received.
	 * @param string $message Explanation, '' when ok.
	 * @param bool   $skipped Whether the check could not run at all.
	 * @return array
	 */
	private static function verdict( $ok, $status, $marker, $message, $skipped = false ) {
		return array(
			'ok'      => $ok,
			'skipped' => $skipped,
			'status'  => $status,
			'marker'  => $marker,
			'message' => $message,
		);
	}

	/**
	 * The last verification result, or null if the check has never run.
	 *
	 * @return array|null
	 */
	public function last_verification() {
		$stored = get_option( self::VERIFY_OPTION );
		return is_array( $stored ) ? $stored : null;
	}
}
