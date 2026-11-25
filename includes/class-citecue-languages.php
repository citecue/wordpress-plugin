<?php
/**
 * Multilingual detection and language-code mapping.
 *
 * The plugin talks to Polylang and WPML only through their public functions
 * and filters, and every call is guarded so the plugin keeps running (as a
 * plain single-language site) where neither exists. CiteCue's wire codes are
 * ISO 639-1, lowercase (`pl`); a site's own language slugs are whatever the
 * translation plugin calls them (`pl`, `pl-pl`, `polski`), so this class is
 * the one place that maps between the two.
 *
 * Everything here is static and reads live state: modes are decided by what is
 * loaded, not stored, so deactivating Polylang changes the answer on the very
 * next request.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language mode, language list and code mapping.
 */
class Citecue_Languages {

	const MODE_PLAIN    = 'plain';
	const MODE_POLYLANG = 'polylang';
	const MODE_WPML     = 'wpml';

	/**
	 * Whether WPML mode may declare `language_pages`. Off until the WPML path
	 * has been run against real WPML; the stub in the test suite proves the
	 * calls, not WPML's behaviour. Flip it only together with a rig run.
	 */
	const WPML_ENABLED = false;

	/**
	 * The language mode: WPML wins, then Polylang, else plain.
	 *
	 * With both plugins active the choice has to be deterministic and the same
	 * on every request, so WPML takes it.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function mode() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return self::MODE_WPML;
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			return self::MODE_POLYLANG;
		}
		return self::MODE_PLAIN;
	}

	/**
	 * The name of a translator that keeps every language on ONE post (or one
	 * site per language), where there is no separate post to push, or ''.
	 *
	 * @return string
	 */
	public static function blocked_translator() {
		return self::detect_blocker(
			function ( $name ) {
				return class_exists( $name, false );
			},
			'defined'
		);
	}

	/**
	 * The detection itself, with its two probes passed in so a test can
	 * exercise every branch without defining process-wide classes.
	 *
	 * @param callable $class_exists Takes a class name, returns bool.
	 * @param callable $defined      Takes a constant name, returns bool.
	 * @return string The translator's name, or ''.
	 */
	public static function detect_blocker( $class_exists, $defined ) {
		if ( call_user_func( $class_exists, 'TRP_Translate_Press' ) ) {
			return 'TranslatePress';
		}
		if ( call_user_func( $defined, 'WEGLOT_VERSION' ) ) {
			return 'Weglot';
		}
		if ( call_user_func( $defined, 'MULTILINGUALPRESS_LICENSE_API_URL' ) || call_user_func( $class_exists, 'Inpsyde\\MultilingualPress\\MultilingualPress' ) ) {
			return 'MultilingualPress';
		}
		return '';
	}

	/**
	 * The site's active languages in Polylang/WPML mode, in the plugin's own
	 * order.
	 *
	 * Each entry: `slug` (the vendor's identifier, what its APIs take),
	 * `locale` (`pl_PL`) and `code` (the ISO 639-1 language part, or '' when the
	 * locale has none, as with a three-letter language).
	 *
	 * @return array<int,array{slug:string,locale:string,code:string}> Empty in plain mode.
	 */
	public static function active_languages() {
		$raw = array();

		if ( self::MODE_POLYLANG === self::mode() ) {
			$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = pll_languages_list( array( 'fields' => 'locale' ) );
			foreach ( (array) $slugs as $i => $slug ) {
				$raw[ (string) $slug ] = isset( $locales[ $i ] ) ? (string) $locales[ $i ] : '';
			}
		} elseif ( self::MODE_WPML === self::mode() ) {
			$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			foreach ( is_array( $list ) ? $list : array() as $key => $row ) {
				$slug = isset( $row['language_code'] ) ? (string) $row['language_code'] : (string) $key;
				if ( '' === $slug ) {
					continue;
				}
				$raw[ $slug ] = isset( $row['default_locale'] ) ? (string) $row['default_locale'] : '';
			}
		}

		$out = array();
		foreach ( $raw as $slug => $locale ) {
			$out[] = array(
				'slug'   => (string) $slug,
				'locale' => $locale,
				'code'   => self::code_from_locale( $locale ),
			);
		}
		return $out;
	}

	/**
	 * The vendor slug of the site's default language, or '' (plain mode, or
	 * none set).
	 *
	 * @return string
	 */
	public static function default_language() {
		if ( self::MODE_POLYLANG === self::mode() ) {
			$slug = pll_default_language();
			return is_string( $slug ) ? $slug : '';
		}
		if ( self::MODE_WPML === self::mode() ) {
			$slug = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			return is_string( $slug ) ? $slug : '';
		}
		return '';
	}

	/**
	 * Maps a CiteCue ISO 639-1 code to the site's language slug.
	 *
	 * Matches on the language part of each active locale. One match wins. With
	 * several (`en_US` and `en_GB` both match `en`), the site's default
	 * language wins if it is one of them, else the one whose slug equals the
	 * code, else the code is not available: guessing would put a page in a
	 * language the customer did not choose.
	 *
	 * @param string $code ISO 639-1 code, any case.
	 * @return string|null The vendor language slug, or null when the code has no
	 *                     unambiguous language, and always in plain mode (there
	 *                     is no vendor language there; see site_language_code()).
	 */
	public static function map_code( $code ) {
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code ) {
			return null;
		}

		$matches = array();
		foreach ( self::active_languages() as $language ) {
			if ( $language['code'] === $code ) {
				$matches[] = $language['slug'];
			}
		}

		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}
		if ( array() === $matches ) {
			return null;
		}

		$default = self::default_language();
		if ( '' !== $default && in_array( $default, $matches, true ) ) {
			return $default;
		}
		return in_array( $code, $matches, true ) ? $code : null;
	}

	/**
	 * The ISO 639-1 code of a vendor language slug, or null when unknown.
	 *
	 * @param string $slug Vendor language slug.
	 * @return string|null
	 */
	public static function code_for_slug( $slug ) {
		foreach ( self::active_languages() as $language ) {
			if ( $language['slug'] === (string) $slug ) {
				return '' !== $language['code'] ? $language['code'] : null;
			}
		}
		return null;
	}

	/**
	 * The codes this site can receive pages in: sorted, de-duplicated, and each
	 * one resolving to exactly one language after the tie-breaks.
	 *
	 * @return string[]|null Null in plain mode, where no list is sent.
	 */
	public static function declared_codes() {
		if ( self::MODE_PLAIN === self::mode() ) {
			return null;
		}

		$codes = array();
		foreach ( self::active_languages() as $language ) {
			if ( '' !== $language['code'] && null !== self::map_code( $language['code'] ) ) {
				$codes[ $language['code'] ] = true;
			}
		}

		$codes = array_keys( $codes );
		sort( $codes );
		return $codes;
	}

	/**
	 * Whether `page`, the type answer pages are pushed as, is one the language
	 * plugin translates. Always true in plain mode: no plugin, no restriction.
	 *
	 * @return bool
	 */
	public static function page_translatable() {
		if ( self::MODE_POLYLANG === self::mode() ) {
			return function_exists( 'pll_is_translated_post_type' ) && (bool) pll_is_translated_post_type( 'page' );
		}
		if ( self::MODE_WPML === self::mode() ) {
			return (bool) apply_filters( 'wpml_is_translated_post_type', false, 'page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
		}
		return true;
	}

	/**
	 * Whether this site can honestly declare the `language_pages` capability,
	 * before the customer's own opt-out is considered.
	 *
	 * Deliberately NOT gated on local content-push consent: the plugin only
	 * learns consent from the claim response, so requiring it here would mean a
	 * first connect could never declare. The server enforces consent.
	 *
	 * @return bool
	 */
	public static function declarable() {
		if ( '' !== self::blocked_translator() ) {
			return false;
		}
		if ( ! self::page_translatable() ) {
			return false;
		}

		switch ( self::mode() ) {
			case self::MODE_WPML:
				return self::WPML_ENABLED && array() !== self::declared_codes();
			case self::MODE_POLYLANG:
				return array() !== self::declared_codes();
			default:
				return true;
		}
	}

	/**
	 * The vendor language slug a post is in, or null when it has none.
	 *
	 * In plain mode there is one language for the whole site, so this is the
	 * site's own ISO code (see site_language_code()).
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public static function post_language( $post_id ) {
		$post_id = (int) $post_id;

		if ( self::MODE_POLYLANG === self::mode() ) {
			$slug = pll_get_post_language( $post_id );
			return is_string( $slug ) && '' !== $slug ? $slug : null;
		}
		if ( self::MODE_WPML === self::mode() ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return (string) $details['language_code'];
			}
			return null;
		}

		return self::site_language_code();
	}

	/**
	 * The ISO 639-1 code of the site's locale (`pl` for `pl_PL`), or null when
	 * the locale has none.
	 *
	 * @return string|null
	 */
	public static function site_language_code() {
		$code = self::code_from_locale( (string) get_locale() );
		return '' !== $code ? $code : null;
	}

	/**
	 * The two-letter language part of a locale, lowercase, or ''.
	 *
	 * @param string $locale For example `pl_PL`, `de_DE_formal`, `en-GB`.
	 * @return string
	 */
	private static function code_from_locale( $locale ) {
		$part = strtolower( (string) preg_replace( '/[_-].*$/', '', trim( (string) $locale ) ) );
		return 1 === preg_match( '/^[a-z]{2}$/', $part ) ? $part : '';
	}
}
