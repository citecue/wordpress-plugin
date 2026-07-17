<?php
/**
 * Tiny local ring buffer of recent AI-crawler events, so admins can verify
 * serving is working without leaving WordPress. CiteCue's Agent Traffic
 * dashboard remains the source of truth for analytics.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recent crawler activity.
 */
class Citecue_Activity_Log {

	const OPTION      = 'citecue_activity';
	const MAX_ENTRIES = 20;

	/**
	 * Records one crawler event.
	 *
	 * @param string $crawler Matched UA token.
	 * @param string $path    Request path.
	 * @param string $outcome served|served-stale|passthrough|error.
	 * @return void
	 */
	public function record( $crawler, $path, $outcome ) {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'crawler' => sanitize_text_field( $crawler ),
				'path'    => substr( sanitize_text_field( $path ), 0, 200 ),
				'outcome' => sanitize_key( $outcome ),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Recent entries, newest first.
	 *
	 * @return array[]
	 */
	public function entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}
}
