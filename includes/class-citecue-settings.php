<?php
/**
 * Plugin settings: one options row, typed accessors, sanitation.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the `citecue_settings` option.
 */
class Citecue_Settings {

	const OPTION = 'citecue_settings';

	/**
	 * Default CiteCue app origin. Overridable per-install for self-hosted /
	 * staging deployments of the CiteCue app.
	 */
	const DEFAULT_API_BASE = 'https://app.citecue.com';

	/**
	 * Cached option value for this request.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection.
			'api_base'              => self::DEFAULT_API_BASE,
			'api_key'               => '',
			'public_key'            => '',
			'project_domain'        => '',
			// Delivery.
			'serve_enabled'         => true,
			'llms_txt_enabled'      => true,
			'seo_head_enabled'      => true,
			// The value of seo_head_enabled last reported to CiteCue, or null
			// if this site has never reported one. CiteCue records the
			// capability on the API key at connect time and has no other way to
			// learn it, so this is how the settings screen knows to ask for a
			// reconnect — see needs_seo_head_reconnect().
			'seo_head_reported'     => null,
			// The capability names last reported to CiteCue, or null if this
			// site has never reported any. Kept alongside seo_head_reported
			// rather than replacing it: that one tracks a SETTING the customer
			// can toggle, this one tracks what this BUILD of the plugin is able
			// to do, and only one of them changes when the plugin updates.
			'capabilities_reported' => null,
			// Content ingest (CiteCue -> WordPress post creation).
			'ingest_enabled'        => false,
			'ingest_secret'         => '',
			'ingest_post_status'    => 'draft',
			'ingest_post_type'      => 'post',
			'ingest_author'         => 0,
		);
	}

	/**
	 * Full settings array merged over defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->values;
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist a partial update, merged over the current values.
	 *
	 * @param array $partial Key/value pairs to update.
	 * @return void
	 */
	public function update( array $partial ) {
		update_option( self::OPTION, array_merge( $this->all(), $partial ) );
		// Drop the cache rather than assume what was written: register_setting()
		// routes this through sanitize(), which may legitimately store
		// something other than what was passed in (an empty api_key means
		// "keep the stored one", not "erase it"). Re-reading is the only way to
		// hold the value that actually landed.
		$this->values = null;
	}

	/**
	 * A CiteCue origin fixed by the install rather than by the settings form,
	 * or '' when the stored value governs.
	 *
	 * Pointing a site at a different CiteCue deployment is a deployment
	 * decision — a self-hosted app, a staging origin — not something an
	 * administrator should be invited to get wrong while pasting a key. When
	 * one is pinned, api_base() ignores the stored value and the settings
	 * screen shows the origin read-only.
	 *
	 * @return string
	 */
	public function pinned_api_base() {
		$pinned = defined( 'CITECUE_API_BASE' ) ? (string) CITECUE_API_BASE : '';

		/**
		 * Filters the pinned CiteCue app origin. Defaults to the
		 * CITECUE_API_BASE constant; '' leaves the setting editable.
		 *
		 * @param string $pinned Pinned origin, or ''.
		 */
		$pinned = (string) apply_filters( 'citecue_pinned_api_base', $pinned );

		return '' !== $pinned ? untrailingslashit( $pinned ) : '';
	}

	/**
	 * The API base with no trailing slash.
	 *
	 * @return string
	 */
	public function api_base() {
		$pinned = $this->pinned_api_base();
		if ( '' !== $pinned ) {
			return $pinned;
		}

		$base = untrailingslashit( (string) $this->get( 'api_base' ) );
		return '' !== $base ? $base : self::DEFAULT_API_BASE;
	}

	/**
	 * Whether the API base is fixed by the install, so the UI must not offer
	 * to edit it.
	 *
	 * @return bool
	 */
	public function api_base_is_locked() {
		return '' !== $this->pinned_api_base();
	}

	/**
	 * Whether this site has been paired with CiteCue.
	 *
	 * Holding an API key is not evidence of a connection. The key-entry
	 * fallback saves the submitted key *before* testing it, so a key CiteCue
	 * has just rejected is still on disk — treating that as connected would
	 * replace the setup screen and its Connect button with a "Connected"
	 * panel the site has not earned. What only a successful exchange can
	 * produce is CiteCue's own answer: a selected project, or the org's
	 * project list cached from a config call.
	 *
	 * A site that connected once and whose key was later revoked stays
	 * "connected" on purpose — its settings are still worth showing, and the
	 * rejected-key notice already says what is wrong.
	 *
	 * @return bool
	 */
	public function is_connected() {
		if ( '' === (string) $this->get( 'api_key' ) ) {
			return false;
		}
		if ( '' !== (string) $this->get( 'public_key' ) ) {
			return true;
		}

		$projects = get_option( 'citecue_projects_cache', array() );
		return is_array( $projects ) && array() !== $projects;
	}

	/**
	 * Whether the delivery proxy has everything it needs to serve.
	 *
	 * @return bool
	 */
	public function is_delivery_configured() {
		return '' !== (string) $this->get( 'api_key' ) && '' !== (string) $this->get( 'public_key' );
	}

	/**
	 * Whether CiteCue's record of this site's SEO-head capability disagrees
	 * with what the site is actually doing, so a reconnect is worth asking for.
	 *
	 * CiteCue stores the capability on the API key, written only by the connect
	 * exchange, and reads its absence as "cannot inject" — deliberately, so a
	 * plugin built before the endpoint existed can never make the app promise
	 * markup nothing puts on the page. That fail-closed default is also what
	 * makes this comparison simple: never-reported and reported-false mean the
	 * same thing to CiteCue.
	 *
	 * The disagreement is worth surfacing in both directions. Under-claiming
	 * loses the customer a feature they are paying for and shows "this channel
	 * can't inject" against a plugin that now can; over-claiming is the failure
	 * the capability exists to prevent.
	 *
	 * @return bool
	 */
	public function needs_seo_head_reconnect() {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$reported = $this->get( 'seo_head_reported' );
		$known    = null === $reported ? false : (bool) $reported;

		if ( (bool) $this->get( 'seo_head_enabled' ) !== $known ) {
			return true;
		}

		$declared = $this->get( 'capabilities_reported' );

		// Never reported a set at all: an install connected before this plugin
		// declared capabilities by name. It is under-claiming everything it can
		// now do, so it needs a reconnect the moment there is anything to
		// claim — and needs no reconnect when there is not.
		if ( ! is_array( $declared ) ) {
			return array() !== $this->active_delivery_capabilities();
		}

		return $this->active_delivery_capabilities() !== $declared;
	}

	/**
	 * The capability flags sent to CiteCue on the connect claim.
	 *
	 * One definition, read by the claim that reports them and by the drift
	 * check above that notices when they have gone stale. Two lists would
	 * disagree eventually, and the failure would be silent in the worst
	 * direction: a capability announced and then never re-announced reads to
	 * CiteCue as one the plugin still has.
	 *
	 * The three delivery capabilities all ride `seo_head_enabled`, because they
	 * all arrive in the one `/seo-head` response that setting governs — a site
	 * with injection switched off fetches nothing, so it can place neither a
	 * head tag nor a body block, and claiming otherwise is exactly the
	 * over-claim the capability exists to prevent.
	 *
	 * `seo_head_baseline` is safe for this plugin to ask for: it prints no
	 * `Organization` node of its own anywhere, and the head merge drops
	 * CiteCue's JSON-LD outright when the page already carries any, so the
	 * site-wide identity block can never become a second competing one.
	 *
	 * @return array<string,bool>
	 */
	public function declared_capabilities() {
		$seo_head = (bool) $this->get( 'seo_head_enabled' );

		return array(
			'woocommerce'       => class_exists( 'WooCommerce' ),
			'seo_head'          => $seo_head,
			'body_blocks'       => $seo_head,
			'seo_head_baseline' => $seo_head,
		);
	}

	/**
	 * The names of the declared capabilities that gate what CiteCue SENDS,
	 * sorted, for comparison against what was last reported.
	 *
	 * `woocommerce` is deliberately not among them. It gates nothing on the
	 * delivery path — CiteCue records it for reporting only — so a customer who
	 * installs WooCommerce after connecting has stale information on their key,
	 * not a broken feature, and prompting them to reconnect over it would be
	 * nagging about nothing.
	 *
	 * @return string[]
	 */
	public function active_delivery_capabilities() {
		$declared = $this->declared_capabilities();
		$names    = array();

		foreach ( array( 'seo_head', 'body_blocks', 'seo_head_baseline' ) as $name ) {
			if ( ! empty( $declared[ $name ] ) ) {
				$names[] = $name;
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Ensures the ingest shared secret exists, generating one if missing.
	 *
	 * @return string
	 */
	public function ensure_ingest_secret() {
		$secret = (string) $this->get( 'ingest_secret' );
		if ( '' === $secret ) {
			$secret = 'cws_' . bin2hex( random_bytes( 20 ) );
			$this->update( array( 'ingest_secret' => $secret ) );
		}
		return $secret;
	}

	/**
	 * Sanitize callback for register_setting(). Empty API key input keeps the
	 * stored key so re-saving the form never wipes credentials.
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$current = $this->all();
		$input   = is_array( $input ) ? $input : array();
		$out     = $current;

		if ( isset( $input['api_base'] ) ) {
			$base            = esc_url_raw( trim( (string) $input['api_base'] ) );
			$out['api_base'] = '' !== $base ? untrailingslashit( $base ) : self::DEFAULT_API_BASE;
		}

		if ( ! empty( $input['api_key_clear'] ) ) {
			$out['api_key'] = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( trim( (string) $input['api_key'] ) );
		}

		if ( isset( $input['public_key'] ) ) {
			$public_key        = sanitize_text_field( trim( (string) $input['public_key'] ) );
			$out['public_key'] = $public_key;
			// Keep the displayed project domain in sync with the chosen key.
			$projects = get_option( 'citecue_projects_cache', array() );
			if ( is_array( $projects ) ) {
				foreach ( $projects as $project ) {
					if ( isset( $project['publicKey'], $project['domain'] ) && $project['publicKey'] === $public_key ) {
						$out['project_domain'] = (string) $project['domain'];
					}
				}
			}
			if ( '' === $public_key ) {
				$out['project_domain'] = '';
			}
		}

		$out['serve_enabled']    = ! empty( $input['serve_enabled'] );
		$out['llms_txt_enabled'] = ! empty( $input['llms_txt_enabled'] );
		$out['seo_head_enabled'] = ! empty( $input['seo_head_enabled'] );
		$out['ingest_enabled']   = ! empty( $input['ingest_enabled'] );

		if ( isset( $input['ingest_post_status'] ) && in_array( $input['ingest_post_status'], array( 'draft', 'pending', 'publish' ), true ) ) {
			$out['ingest_post_status'] = $input['ingest_post_status'];
		}

		$allowed_types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$allowed_types[] = 'product';
		}
		if ( isset( $input['ingest_post_type'] ) && in_array( $input['ingest_post_type'], $allowed_types, true ) ) {
			$out['ingest_post_type'] = $input['ingest_post_type'];
		}

		if ( isset( $input['ingest_author'] ) ) {
			$out['ingest_author'] = absint( $input['ingest_author'] );
		}

		// Internal-only fields. register_setting() routes EVERY
		// update_option() for this option through this callback (via the
		// sanitize_option_* filter), including the plugin's own update()
		// calls — so these must pass through here or internal writes (e.g. a
		// regenerated ingest secret) would be silently dropped. The settings
		// form never posts these names.
		if ( isset( $input['ingest_secret'] ) && is_string( $input['ingest_secret'] ) ) {
			$out['ingest_secret'] = sanitize_text_field( $input['ingest_secret'] );
		}
		if ( isset( $input['project_domain'] ) && is_string( $input['project_domain'] ) ) {
			$out['project_domain'] = sanitize_text_field( $input['project_domain'] );
		}
		// Tri-state: null means "never reported", which is what an install that
		// predates the capability must keep reading as until it reconnects.
		if ( array_key_exists( 'seo_head_reported', $input ) ) {
			$out['seo_head_reported'] = null === $input['seo_head_reported'] ? null : (bool) $input['seo_head_reported'];
		}
		// Same tri-state, and normalized to a sorted list of names so that the
		// comparison in needs_seo_head_reconnect() cannot be tripped by key
		// order alone.
		if ( array_key_exists( 'capabilities_reported', $input ) ) {
			$reported = $input['capabilities_reported'];
			if ( null === $reported ) {
				$out['capabilities_reported'] = null;
			} else {
				$names = array_values( array_filter( array_map( 'strval', (array) $reported ), 'strlen' ) );
				sort( $names );
				$out['capabilities_reported'] = $names;
			}
		}

		// A changed key may fix a previous auth failure; let serving retry now.
		if ( $out['api_key'] !== $current['api_key'] ) {
			delete_option( 'citecue_auth_failed' );
			delete_transient( 'citecue_circuit' );
		}

		// A changed project invalidates every cached body, llms.txt and head
		// block on the site (PR #10 review). All three key off the cache salt
		// and the URL and NOT off the project, so without this an administrator
		// who repoints the site at another CiteCue project keeps being served
		// the previous project's content under the new one's name — its
		// optimized pages to crawlers, and its title, canonical and structured
		// data onto live pages, for up to a day. Disconnecting already flushes;
		// switching project is the same event by another route.
		if ( $out['public_key'] !== $current['public_key'] ) {
			( new Citecue_Cache() )->flush();
		}

		$this->values = $out;
		return $out;
	}
}
