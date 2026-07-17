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
			'api_base'           => self::DEFAULT_API_BASE,
			'api_key'            => '',
			'public_key'         => '',
			'project_domain'     => '',
			// Delivery.
			'serve_enabled'      => true,
			'llms_txt_enabled'   => true,
			// Content ingest (CiteCue -> WordPress post creation).
			'ingest_enabled'     => false,
			'ingest_secret'      => '',
			'ingest_post_status' => 'draft',
			'ingest_post_type'   => 'post',
			'ingest_author'      => 0,
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
		$merged = array_merge( $this->all(), $partial );
		update_option( self::OPTION, $merged );
		$this->values = $merged;
	}

	/**
	 * The API base with no trailing slash.
	 *
	 * @return string
	 */
	public function api_base() {
		$base = untrailingslashit( (string) $this->get( 'api_base' ) );
		return '' !== $base ? $base : self::DEFAULT_API_BASE;
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
		$out['ingest_enabled']   = ! empty( $input['ingest_enabled'] );

		if ( isset( $input['ingest_post_status'] ) && in_array( $input['ingest_post_status'], array( 'draft', 'pending', 'publish' ), true ) ) {
			$out['ingest_post_status'] = $input['ingest_post_status'];
		}

		if ( isset( $input['ingest_post_type'] ) && in_array( $input['ingest_post_type'], array( 'post', 'page' ), true ) ) {
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

		// A changed key may fix a previous auth failure; let serving retry now.
		if ( $out['api_key'] !== $current['api_key'] ) {
			delete_option( 'citecue_auth_failed' );
			delete_transient( 'citecue_circuit' );
		}

		$this->values = $out;
		return $out;
	}
}
