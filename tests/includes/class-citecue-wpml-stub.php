<?php
/**
 * Minimal in-memory WPML stand-in.
 *
 * The plugin only ever talks to WPML through its public filters and actions,
 * so answering those from memory lets the WPML code path be tested without the
 * (commercial) WPML plugin. Like the WooCommerce stub, whether WPML "exists"
 * is a process-wide fact, so this is switched on for a whole run.
 *
 * @package Citecue
 */

/**
 * Fake WPML element/translation store.
 */
class Citecue_Wpml_Stub {

	/**
	 * Language details by element id: array( trid, language_code, source_language_code, element_type ).
	 *
	 * @var array<int,array>
	 */
	public static $elements = array();

	/**
	 * Last trid handed out.
	 *
	 * @var int
	 */
	private static $last_trid = 100;

	/**
	 * Active languages, keyed by code.
	 *
	 * @var array<string,array>
	 */
	public static $languages = array(
		'en' => array(
			'code'           => 'en',
			'language_code'  => 'en',
			'default_locale' => 'en_US',
			'native_name'    => 'English',
			'active'         => 1,
		),
		'pl' => array(
			'code'           => 'pl',
			'language_code'  => 'pl',
			'default_locale' => 'pl_PL',
			'native_name'    => 'Polski',
			'active'         => 0,
		),
	);

	/**
	 * Defines the version constant and registers the hooks. Idempotent.
	 *
	 * @return void
	 */
	public static function install() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}
		define( 'ICL_SITEPRESS_VERSION', '4.6.0' );

		add_filter( 'wpml_element_trid', array( __CLASS__, 'element_trid' ), 10, 3 );
		add_filter( 'wpml_post_language_details', array( __CLASS__, 'post_language_details' ), 10, 2 );
		add_filter( 'wpml_active_languages', array( __CLASS__, 'active_languages' ), 10, 2 );
		add_action( 'wpml_set_element_language_details', array( __CLASS__, 'set_element_language_details' ) );
	}

	/**
	 * Forgets every element (call from setUp for isolation).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$elements = array();
	}

	/**
	 * Filter `wpml_element_trid`.
	 *
	 * @param mixed  $unused       Filter default.
	 * @param int    $element_id   Element id.
	 * @param string $element_type Element type.
	 * @return int|null
	 */
	public static function element_trid( $unused, $element_id = null, $element_type = null ) {
		unset( $unused, $element_type );
		return isset( self::$elements[ (int) $element_id ] ) ? self::$elements[ (int) $element_id ]['trid'] : null;
	}

	/**
	 * Filter `wpml_post_language_details`.
	 *
	 * @param mixed $unused  Filter default.
	 * @param int   $post_id Post id.
	 * @return array|\WP_Error
	 */
	public static function post_language_details( $unused, $post_id = null ) {
		unset( $unused );
		$row = isset( self::$elements[ (int) $post_id ] ) ? self::$elements[ (int) $post_id ] : null;
		if ( null === $row ) {
			return new WP_Error( 'missing_language', 'No language set' );
		}
		return array(
			'language_code'      => $row['language_code'],
			'locale'             => isset( self::$languages[ $row['language_code'] ]['default_locale'] ) ? self::$languages[ $row['language_code'] ]['default_locale'] : '',
			'text_direction'     => false,
			'display_name'       => $row['language_code'],
			'native_name'        => $row['language_code'],
			'different_language' => false,
		);
	}

	/**
	 * Filter `wpml_active_languages`.
	 *
	 * @param mixed $unused Filter default.
	 * @return array<string,array>
	 */
	public static function active_languages( $unused ) {
		unset( $unused );
		return self::$languages;
	}

	/**
	 * Action `wpml_set_element_language_details`.
	 *
	 * @param array $args element_id, element_type, trid (false = new), language_code, source_language_code.
	 * @return void
	 */
	public static function set_element_language_details( $args ) {
		$id   = (int) $args['element_id'];
		$trid = ! empty( $args['trid'] ) ? (int) $args['trid'] : ++self::$last_trid;

		self::$elements[ $id ] = array(
			'trid'                 => $trid,
			'language_code'        => $args['language_code'],
			'source_language_code' => isset( $args['source_language_code'] ) ? $args['source_language_code'] : null,
			'element_type'         => isset( $args['element_type'] ) ? $args['element_type'] : 'post_post',
		);
	}
}
