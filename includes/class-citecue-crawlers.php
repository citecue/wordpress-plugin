<?php
/**
 * AI crawler registry: bundled user-agent tokens plus a daily refresh from
 * CiteCue's public registry feed, and the UA matching rule.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects AI crawlers by User-Agent token.
 */
class Citecue_Crawlers {

	const OPTION = 'citecue_crawlers';

	/**
	 * Version of the bundled token list. Mirrors CiteCue's
	 * DELIVERY_CRAWLER_REGISTRY_VERSION; the live feed wins when newer.
	 */
	const BUNDLED_VERSION = 1;

	/**
	 * Bundled serve-able UA tokens (crawlers that actually fetch pages).
	 * Mirrors CiteCue's delivery crawler registry; robots.txt-only tokens
	 * (Google-Extended, Applebot-Extended) are deliberately absent — they
	 * never send HTTP requests.
	 *
	 * @return string[]
	 */
	public static function bundled_tokens() {
		return array(
			// OpenAI.
			'GPTBot',
			'OAI-SearchBot',
			'ChatGPT-User',
			// Anthropic.
			'ClaudeBot',
			'Claude-User',
			'Claude-SearchBot',
			'anthropic-ai',
			// Perplexity.
			'PerplexityBot',
			'Perplexity-User',
			// Google.
			'GoogleOther',
			// Others.
			'Bytespider',
			'CCBot',
			'cohere-ai',
			'meta-externalagent',
			'meta-externalfetcher',
			'Amazonbot',
			'DuckAssistBot',
			'MistralAI-User',
		);
	}

	/**
	 * The active token list: refreshed registry when available, bundled
	 * fallback otherwise.
	 *
	 * @return string[]
	 */
	public function get_tokens() {
		$stored = get_option( self::OPTION, array() );
		$tokens = ( is_array( $stored ) && ! empty( $stored['tokens'] ) && is_array( $stored['tokens'] ) )
			? $stored['tokens']
			: self::bundled_tokens();

		/**
		 * Filters the AI crawler UA tokens the plugin serves optimized content to.
		 *
		 * @param string[] $tokens Case-insensitive substring tokens.
		 */
		$tokens = apply_filters( 'citecue_crawler_tokens', $tokens );

		return is_array( $tokens ) ? array_values( array_filter( array_map( 'strval', $tokens ) ) ) : array();
	}

	/**
	 * Registry state for the admin screen.
	 *
	 * @return array{version:int,count:int,fetched_at:int}
	 */
	public function registry_info() {
		$stored = get_option( self::OPTION, array() );
		return array(
			'version'    => isset( $stored['version'] ) ? (int) $stored['version'] : self::BUNDLED_VERSION,
			'count'      => count( $this->get_tokens() ),
			'fetched_at' => isset( $stored['fetched_at'] ) ? (int) $stored['fetched_at'] : 0,
		);
	}

	/**
	 * Matches a User-Agent against the token list. Case-insensitive substring
	 * match; when several tokens match, the longest wins (so "ChatGPT-User"
	 * is never shadowed by a shorter overlapping token). Mirrors CiteCue's
	 * matchDeliveryCrawler().
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string|null The matched token, or null for a non-crawler UA.
	 */
	public function match( $user_agent ) {
		$user_agent = strtolower( (string) $user_agent );
		if ( '' === $user_agent ) {
			return null;
		}

		$best = null;
		foreach ( $this->get_tokens() as $token ) {
			if ( false !== strpos( $user_agent, strtolower( $token ) ) ) {
				if ( null === $best || strlen( $token ) > strlen( $best ) ) {
					$best = $token;
				}
			}
		}

		/**
		 * Filters the matched crawler token for a request.
		 *
		 * @param string|null $best       Matched token or null.
		 * @param string      $user_agent Lowercased User-Agent.
		 */
		return apply_filters( 'citecue_matched_crawler', $best, $user_agent );
	}

	/**
	 * Refreshes the token list from CiteCue's public registry feed
	 * (`GET /api/delivery/v1/crawlers`, keyless). Any failure keeps the
	 * current list — the bundled tokens always remain as a floor.
	 *
	 * @param Citecue_Api_Client $api API client.
	 * @return bool Whether a fresh registry was stored.
	 */
	public function refresh( Citecue_Api_Client $api ) {
		$data = $api->get_crawler_registry();
		if ( is_wp_error( $data ) || empty( $data['tokens'] ) || ! is_array( $data['tokens'] ) ) {
			return false;
		}

		update_option(
			self::OPTION,
			array(
				'version'    => isset( $data['version'] ) ? (int) $data['version'] : self::BUNDLED_VERSION,
				'tokens'     => array_values( array_filter( array_map( 'sanitize_text_field', $data['tokens'] ) ) ),
				'fetched_at' => time(),
			)
		);
		return true;
	}
}
