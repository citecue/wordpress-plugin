<?php
/**
 * Language pages over `/content`: a push carrying `translation_of` files the
 * page as a translation of a CiteCue main page.
 *
 * Request fields: `language` (ISO 639-1 code of this page), `translation_of`
 * (the main page's external_id) and `translation_of_language` (the main
 * page's ISO 639-1 code).
 *
 * The page joins the main post's translation group in whatever mode the site
 * is in at push time (Polylang, WPML, or plain, where it becomes a child page
 * of the main post at /<main-slug>/<code>/). Every post in a group carries
 * `_citecue_language` (its code) and, on a translation, `_citecue_translation_of`
 * (the main post's ID), which is what plain mode prints hreflang from and
 * what lets a later mode change re-link it.
 *
 * Pushes into one group are serialized by a lock per main page: without it two
 * pushes landing together could each miss the other's post and create a
 * duplicate, or overwrite the other's group link.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation pushes, language assignment and the per-group lock.
 */
class Citecue_Translations {

	const META_LANGUAGE       = '_citecue_language';
	const META_TRANSLATION_OF = '_citecue_translation_of';

	const LOCK_PREFIX = 'citecue_tlock_';

	/**
	 * Seconds after which a held lock counts as abandoned (its request died
	 * somewhere `finally` and `shutdown` could not reach) and is taken over.
	 */
	const LOCK_TTL = 30;

	/**
	 * Seconds a push waits for a busy group before answering `citecue_busy`.
	 */
	const LOCK_WAIT = 3;

	/**
	 * Locks this request holds: option name => owner token.
	 *
	 * @var array<string,string>
	 */
	private static $held = array();

	/**
	 * The ingest controller whose create/update rules this path reuses.
	 *
	 * @var Citecue_Ingest
	 */
	private $ingest;

	/**
	 * Constructor.
	 *
	 * @param Citecue_Ingest $ingest Ingest controller.
	 */
	public function __construct( Citecue_Ingest $ingest ) {
		$this->ingest = $ingest;
	}

	// ------------------------------------------------------------------ push

	/**
	 * Files a pushed page as a translation of its main page (steps 1 to 8 of
	 * the contract), under the group's lock.
	 *
	 * @param array  $params      Raw request payload.
	 * @param string $external_id Sanitized external id.
	 * @param string $title       Sanitized title.
	 * @param string $content     Sanitized content.
	 * @return WP_REST_Response|WP_Error
	 */
	public function push( array $params, $external_id, $title, $content ) {
		$translation_of = $this->ingest->sanitize_external_id( is_scalar( $params['translation_of'] ) ? $params['translation_of'] : '' );
		if ( '' === $translation_of ) {
			return $this->parent_missing();
		}

		/**
		 * Filters how many seconds a translation push waits for its group's
		 * lock before answering `503 citecue_busy`.
		 *
		 * @param int $seconds Default 3.
		 */
		$wait = max( 0, (int) apply_filters( 'citecue_translation_lock_wait', self::LOCK_WAIT ) );

		$self = $this;
		return self::with_lock(
			$translation_of,
			$wait,
			static function () use ( $self, $params, $external_id, $title, $content, $translation_of ) {
				return $self->push_locked( $params, $external_id, $title, $content, $translation_of );
			}
		);
	}

	/**
	 * The push itself; runs only while the group's lock is held.
	 *
	 * Public for the closure in push() only.
	 *
	 * @param array  $params         Raw request payload.
	 * @param string $external_id    Sanitized external id.
	 * @param string $title          Sanitized title.
	 * @param string $content        Sanitized content.
	 * @param string $translation_of Sanitized main external id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_locked( array $params, $external_id, $title, $content, $translation_of ) {
		$mode = Citecue_Languages::mode();

		// 1. The main post: an existing, untrashed CiteCue page that is neither
		// this page nor a translation itself.
		$main_id = $this->ingest->find_by_external_id( $translation_of );
		if ( ! $main_id
			|| $translation_of === $external_id
			|| 'page' !== get_post_type( $main_id )
			|| 'trash' === get_post_status( $main_id )
			|| '' !== (string) get_post_meta( $main_id, self::META_TRANSLATION_OF, true )
		) {
			return $this->parent_missing();
		}

		// 2. Both codes are ISO 639-1, and this page's language exists here.
		$code      = self::wire_code( isset( $params['language'] ) ? $params['language'] : null );
		$main_code = self::wire_code( isset( $params['translation_of_language'] ) ? $params['translation_of_language'] : null );
		$slug      = null === $code ? null : self::slug_for( $code, $mode );
		if ( null === $code || null === $main_code || null === $slug ) {
			return new WP_Error( 'citecue_language_unavailable', __( 'This site has no language for this page.', 'citecue-ai-auto-fix' ), array( 'status' => 400 ) );
		}

		// 3. The main post is in the language the request says (by language
		// part), or has none yet. Plain mode has no vendor language to check.
		$main_slug = self::slug_for( $main_code, $mode );
		$main_has  = Citecue_Languages::MODE_PLAIN === $mode ? null : Citecue_Languages::post_language( $main_id );
		if ( Citecue_Languages::MODE_PLAIN !== $mode ) {
			if ( null === $main_slug || ( null !== $main_has && Citecue_Languages::code_for_slug( $main_has ) !== $main_code ) ) {
				return new WP_Error(
					'citecue_translation_parent_language',
					__( 'The main page is in another language on this site.', 'citecue-ai-auto-fix' ),
					array(
						'status'  => 409,
						'post_id' => $main_id,
					)
				);
			}
		}

		// 4. The group: the main post's own language, a post already holding
		// this language, or an existing post filed elsewhere.
		$stored_main  = (string) get_post_meta( $main_id, self::META_LANGUAGE, true );
		$main_is_code = Citecue_Languages::MODE_PLAIN === $mode
			? ( '' !== $stored_main ? $stored_main : $main_code )
			: ( null !== $main_has ? Citecue_Languages::code_for_slug( $main_has ) : $main_code );
		if ( $main_is_code === $code ) {
			return $this->conflict( 'group', $main_id );
		}

		$existing_id = $this->ingest->find_by_external_id( $external_id );
		if ( $existing_id ) {
			$stored_code = (string) get_post_meta( $existing_id, self::META_LANGUAGE, true );
			$stored_of   = (string) get_post_meta( $existing_id, self::META_TRANSLATION_OF, true );
			if ( ( '' !== $stored_code && $stored_code !== $code ) || ( '' !== $stored_of && (string) $main_id !== $stored_of ) ) {
				return $this->conflict( 'meta_mismatch', $existing_id );
			}
			// Linking a non-page into a page's group would be wrong in every mode.
			if ( 'page' !== get_post_type( $existing_id ) ) {
				return new WP_Error(
					'citecue_type_conflict',
					__( 'This external_id already exists with a different content type.', 'citecue-ai-auto-fix' ),
					array(
						'status'  => 409,
						'post_id' => $existing_id,
					)
				);
			}
			// Before any linking: a trashed post must not join the live group.
			if ( 'trash' === get_post_status( $existing_id ) ) {
				return new WP_Error(
					'citecue_trashed',
					__( 'A post with this external_id was trashed in WordPress; restore or delete it permanently first.', 'citecue-ai-auto-fix' ),
					array(
						'status'  => 410,
						'post_id' => $existing_id,
					)
				);
			}
		}

		$holder = $this->group_member( $main_id, $code, $slug, $mode );
		if ( $holder && $holder !== $existing_id ) {
			return $this->conflict( 'group', $holder );
		}

		$status = $this->ingest->resolve_status( isset( $params['status'] ) ? (string) $params['status'] : '' );

		// 5. Create the post if needed, always as a draft until it is linked.
		$created = ! $existing_id;
		$post_id = (int) $existing_id;
		$linked  = false;
		// One guarded block: a vendor or hook failure anywhere after the insert
		// must not leave a half-made draft behind.
		try {
			if ( $created ) {
				$postarr = array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'draft',
					'post_type'    => 'page',
					'post_author'  => $this->ingest->resolve_author(),
				);
				if ( isset( $params['excerpt'] ) ) {
					$postarr['post_excerpt'] = sanitize_textarea_field( (string) $params['excerpt'] );
				}
				if ( Citecue_Languages::MODE_PLAIN === $mode ) {
					// /<main-slug>/<code>/; WordPress de-duplicates a taken slug.
					$postarr['post_parent'] = $main_id;
					$postarr['post_name']   = $code;
				}

				/** This filter is documented in includes/class-citecue-ingest.php */
				$postarr = apply_filters( 'citecue_ingest_postarr', $postarr, $params );

				$result = wp_insert_post( $postarr, true );
				if ( is_wp_error( $result ) ) {
					$result->add_data( array( 'status' => 500 ) );
					return $result;
				}
				$post_id = (int) $result;
				// Polylang/WPML give a new post the site default on insert; the right
				// language goes on before anything else can read the wrong one.
				if ( Citecue_Languages::MODE_PLAIN !== $mode ) {
					self::set_vendor_language( $post_id, $slug, $mode );
				}
				update_post_meta( $post_id, '_citecue_external_id', $external_id );
			} else {
				$post_id = $existing_id;
			}

			// 6. Link it, before any content check, so a sibling a translator
			// edited is linked even when its content is refused below.
			$linked = $this->link( $post_id, $main_id, $code, $slug, $main_code, $main_slug, $mode );
		} catch ( Throwable $e ) {
			$linked = false;
		}
		if ( ! $linked ) {
			if ( $created && $post_id ) {
				wp_delete_post( $post_id, true );
			}
			return new WP_Error( 'citecue_translation_link_failed', __( 'The page could not be linked to its main page.', 'citecue-ai-auto-fix' ), array( 'status' => 500 ) );
		}

		// 7. An existing post's content, under today's rules.
		if ( ! $created ) {
			if ( empty( $params['force'] ) && $this->ingest->edited_locally( $post_id ) ) {
				return new WP_Error(
					'citecue_edited_locally',
					__( 'This post was edited in WordPress after the last push; send force=true to overwrite.', 'citecue-ai-auto-fix' ),
					array(
						'status'  => 409,
						'post_id' => $post_id,
					)
				);
			}

			$postarr = array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => 'page',
			);
			if ( isset( $params['excerpt'] ) ) {
				$postarr['post_excerpt'] = sanitize_textarea_field( (string) $params['excerpt'] );
			}

			/** This filter is documented in includes/class-citecue-ingest.php */
			$postarr = apply_filters( 'citecue_ingest_postarr', $postarr, $params );

			$result = self::update_in_place( $postarr );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 500 ) );
				return $result;
			}
		} elseif ( 'draft' !== $status ) {
			// A linked draft is a valid landing: if the move fails, the answer
			// says draft and the server records a draft.
			self::update_in_place(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);
		}

		// 8. Answer as today, plus the language.
		return $this->ingest->finish_push( $post_id, $params, $created );
	}

	/**
	 * Runs wp_update_post() keeping the post where it is.
	 *
	 * Polylang moves a page's parent to the parent's translation in the page's
	 * own language. For a sibling that is a plain-mode child of its main post
	 * (a site that switched to Polylang since) that translation is the sibling
	 * itself, so an update would re-parent it and change its URL.
	 *
	 * @param array $postarr wp_update_post() arguments, with `ID`.
	 * @return int|WP_Error
	 */
	private static function update_in_place( array $postarr ) {
		$post_id = (int) $postarr['ID'];
		$parent  = (int) wp_get_post_parent_id( $post_id );
		$keep    = static function ( $post_parent, $id ) use ( $post_id, $parent ) {
			return (int) $id === $post_id ? $parent : $post_parent;
		};

		add_filter( 'wp_insert_post_parent', $keep, 20, 2 );
		try {
			return wp_update_post( $postarr, true );
		} finally {
			remove_filter( 'wp_insert_post_parent', $keep, 20 );
		}
	}

	/**
	 * Step 6 by mode: meta on the page, then the vendor group.
	 *
	 * @param int    $post_id   This page.
	 * @param int    $main_id   The main post.
	 * @param string $code      This page's code.
	 * @param string $slug      This page's vendor slug (the code in plain mode).
	 * @param string $main_code The main post's code from the request.
	 * @param string $main_slug The main post's vendor slug.
	 * @param string $mode      Language mode.
	 * @return bool Whether the page is now in the group.
	 */
	private function link( $post_id, $main_id, $code, $slug, $main_code, $main_slug, $mode ) {
		update_post_meta( $post_id, self::META_LANGUAGE, $code );
		update_post_meta( $post_id, self::META_TRANSLATION_OF, (string) $main_id );

		if ( Citecue_Languages::MODE_POLYLANG === $mode ) {
			if ( null === Citecue_Languages::post_language( $main_id ) ) {
				pll_set_post_language( $main_id, $main_slug );
			}
			pll_set_post_language( $post_id, $slug );

			$group = pll_get_post_translations( $main_id );
			$group = is_array( $group ) ? $group : array();
			// The main post's own entry, in case it had no group until now.
			$group[ (string) pll_get_post_language( $main_id ) ] = $main_id;
			$group[ $slug ]                                      = $post_id;
			pll_save_post_translations( $group );

			$after = pll_get_post_translations( $main_id );
			return isset( $after[ $slug ] ) && (int) $after[ $slug ] === (int) $post_id;
		}

		if ( Citecue_Languages::MODE_WPML === $mode ) {
			$main_lang = Citecue_Languages::post_language( $main_id );
			if ( null === $main_lang ) {
				self::set_vendor_language( $main_id, $main_slug, $mode );
				$main_lang = $main_slug;
			}
			$trid = apply_filters( 'wpml_element_trid', null, $main_id, 'post_page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			if ( empty( $trid ) ) {
				return false;
			}
			do_action(
				'wpml_set_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
				array(
					'element_id'           => $post_id,
					'element_type'         => 'post_page',
					'trid'                 => $trid,
					'language_code'        => $slug,
					'source_language_code' => $main_lang,
				)
			);
			$linked_trid = (int) apply_filters( 'wpml_element_trid', null, $post_id, 'post_page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			return $linked_trid === (int) $trid;
		}

		// Plain: the meta is the group. The main post's language is recorded
		// only when it has none: it is never changed.
		if ( '' === (string) get_post_meta( $main_id, self::META_LANGUAGE, true ) ) {
			update_post_meta( $main_id, self::META_LANGUAGE, $main_code );
		}
		$linked_to = (string) get_post_meta( $post_id, self::META_TRANSLATION_OF, true );
		return $linked_to === (string) $main_id;
	}

	/**
	 * The post already holding `$code` in the main post's group, or 0: the
	 * vendor's group in Polylang/WPML, plus CiteCue's own meta in every mode
	 * (the only record in plain mode, and what a mode change leaves behind).
	 *
	 * @param int    $main_id Main post id.
	 * @param string $code    ISO code.
	 * @param string $slug    Vendor slug.
	 * @param string $mode    Language mode.
	 * @return int
	 */
	private function group_member( $main_id, $code, $slug, $mode ) {
		if ( Citecue_Languages::MODE_POLYLANG === $mode ) {
			$group = pll_get_post_translations( $main_id );
			if ( is_array( $group ) && ! empty( $group[ $slug ] ) && (int) $group[ $slug ] !== (int) $main_id ) {
				return (int) $group[ $slug ];
			}
		} elseif ( Citecue_Languages::MODE_WPML === $mode ) {
			$trid  = apply_filters( 'wpml_element_trid', null, $main_id, 'post_page' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			$group = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_page' ) : null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			if ( is_array( $group ) && isset( $group[ $slug ] ) ) {
				$member = is_object( $group[ $slug ] ) && isset( $group[ $slug ]->element_id ) ? (int) $group[ $slug ]->element_id : 0;
				if ( $member > 0 && $member !== (int) $main_id ) {
					return $member;
				}
			}
		}

		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'lang'           => '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded: a group holds a handful of posts.
				'meta_query'     => array(
					array(
						'key'   => self::META_TRANSLATION_OF,
						'value' => (string) $main_id,
					),
					array(
						'key'   => self::META_LANGUAGE,
						'value' => $code,
					),
				),
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * `409 citecue_translation_conflict`.
	 *
	 * @param string $reason  `group` (a language already taken) or
	 *                        `meta_mismatch` (a post filed under another main
	 *                        page or language, which the server never sends).
	 * @param int    $post_id The conflicting post.
	 * @return WP_Error
	 */
	private function conflict( $reason, $post_id ) {
		return new WP_Error(
			'citecue_translation_conflict',
			'group' === $reason
				? __( 'The main page already has a page in this language.', 'citecue-ai-auto-fix' )
				: __( 'This page is filed under another main page or language.', 'citecue-ai-auto-fix' ),
			array(
				'status'  => 409,
				'reason'  => $reason,
				'post_id' => (int) $post_id,
			)
		);
	}

	/**
	 * `409 citecue_translation_parent_missing`.
	 *
	 * @return WP_Error
	 */
	private function parent_missing() {
		return new WP_Error( 'citecue_translation_parent_missing', __( 'The main page of this translation is not on this site.', 'citecue-ai-auto-fix' ), array( 'status' => 409 ) );
	}

	// ------------------------------------------------------- languages

	/**
	 * A request's language code, if it is ISO 639-1 (lowercase two letters).
	 *
	 * @param mixed $raw Raw request value.
	 * @return string|null
	 */
	public static function wire_code( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		return 1 === preg_match( '/^[a-z]{2}$/', $raw ) ? $raw : null;
	}

	/**
	 * The vendor slug for a code: any well-formed code maps in plain mode.
	 *
	 * @param string $code ISO code.
	 * @param string $mode Language mode.
	 * @return string|null
	 */
	private static function slug_for( $code, $mode ) {
		return Citecue_Languages::MODE_PLAIN === $mode ? $code : Citecue_Languages::map_code( $code );
	}

	/**
	 * Applies a primary push's `language`. Never refuses: a code that is
	 * malformed or does not map is ignored, and the post keeps whatever the
	 * site gave it.
	 *
	 * A created post gets it unconditionally (the translation plugin has just
	 * given it the site default); an existing post only when it had none, so
	 * a language set in WordPress is never changed.
	 *
	 * @param int   $post_id  Post id.
	 * @param mixed $raw      Raw `language` from the request.
	 * @param bool  $settable Whether the push created the post, or the post had
	 *                        no language before the push touched it. The
	 *                        caller reads that before its update (see
	 *                        has_language()): Polylang gives an updated post
	 *                        with no language the default one.
	 * @return void
	 */
	public static function apply_primary_language( $post_id, $raw, $settable ) {
		$code = self::wire_code( $raw );
		if ( null === $code || ! $settable ) {
			return;
		}

		$mode = Citecue_Languages::mode();
		if ( Citecue_Languages::MODE_PLAIN === $mode ) {
			update_post_meta( $post_id, self::META_LANGUAGE, $code );
			return;
		}

		$slug = Citecue_Languages::map_code( $code );
		if ( null !== $slug ) {
			self::set_vendor_language( $post_id, $slug, $mode );
		}
	}

	/**
	 * Whether a post already has a language: stored meta in plain mode, the
	 * vendor's language otherwise.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function has_language( $post_id ) {
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			return '' !== (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
		}
		return null !== Citecue_Languages::post_language( $post_id );
	}

	/**
	 * The ISO code a post now carries, for the response's `language`.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public static function response_language( $post_id ) {
		if ( Citecue_Languages::MODE_PLAIN === Citecue_Languages::mode() ) {
			$stored = (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
			return '' !== $stored ? $stored : Citecue_Languages::site_language_code();
		}
		$slug = Citecue_Languages::post_language( $post_id );
		return null === $slug ? null : Citecue_Languages::code_for_slug( $slug );
	}

	/**
	 * Sets a post's Polylang/WPML language, keeping any WPML group it is in.
	 *
	 * @param int    $post_id Post id.
	 * @param string $slug    Vendor language slug.
	 * @param string $mode    Language mode.
	 * @return void
	 */
	private static function set_vendor_language( $post_id, $slug, $mode ) {
		if ( Citecue_Languages::MODE_POLYLANG === $mode ) {
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $post_id, $slug );
			}
			return;
		}
		if ( Citecue_Languages::MODE_WPML === $mode ) {
			$type = 'post_' . get_post_type( $post_id );
			$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
			do_action(
				'wpml_set_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.
				array(
					'element_id'           => $post_id,
					'element_type'         => $type,
					'trid'                 => $trid ? $trid : false,
					'language_code'        => $slug,
					'source_language_code' => null,
				)
			);
		}
	}

	// ------------------------------------------------------------------ lock

	/**
	 * The options row a group's lock lives in.
	 *
	 * @param string $key The main page's external id.
	 * @return string
	 */
	public static function lock_name( $key ) {
		return self::LOCK_PREFIX . $key;
	}

	/**
	 * Runs `$callback` holding the lock for `$key`, releasing it however the
	 * callback ends.
	 *
	 * @param string   $key      The main page's external id.
	 * @param int      $wait     Seconds to wait for a busy lock.
	 * @param callable $callback Work to run.
	 * @return mixed The callback's result, or `503 citecue_busy`.
	 */
	public static function with_lock( $key, $wait, $callback ) {
		if ( ! self::acquire_lock( $key, $wait ) ) {
			return new WP_Error( 'citecue_busy', __( 'Another push for this page is running; send it again.', 'citecue-ai-auto-fix' ), array( 'status' => 503 ) );
		}
		try {
			return call_user_func( $callback );
		} finally {
			self::release_lock( $key );
		}
	}

	/**
	 * Takes the lock for `$key`: an options row inserted with INSERT IGNORE
	 * (the WP_Upgrader::create_lock pattern), so exactly one request wins on
	 * any database WordPress runs on. The raw query bypasses any persistent
	 * object cache, which could otherwise answer from a stale copy.
	 *
	 * @param string $key  The main page's external id.
	 * @param int    $wait Seconds to keep trying.
	 * @return bool
	 */
	public static function acquire_lock( $key, $wait ) {
		global $wpdb;

		$name     = self::lock_name( $key );
		$deadline = microtime( true ) + max( 0, (int) $wait );

		while ( true ) {
			$token = time() . ':' . wp_generate_password( 12, false );

			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $inserted ) {
				self::hold( $name, $token );
				return true;
			}

			// The value starts with the time it was taken: a lock older than
			// the TTL belongs to a request that died, and is taken over. The
			// update names the old value, so of two requests taking over the
			// same stale lock only one succeeds.
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null !== $current && (int) $current < time() - self::LOCK_TTL ) {
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $taken ) {
					self::hold( $name, $token );
					return true;
				}
			}

			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 100000 );
		}
	}

	/**
	 * Releases the lock for `$key`, if this request still owns it.
	 *
	 * @param string $key The main page's external id.
	 * @return void
	 */
	public static function release_lock( $key ) {
		global $wpdb;

		$name = self::lock_name( $key );
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, self::$held[ $name ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		unset( self::$held[ $name ] );
	}

	/**
	 * Releases every lock this request still holds. Runs on `shutdown`, which
	 * a fatal error reaches where `finally` does not.
	 *
	 * @return void
	 */
	public static function release_held_locks() {
		foreach ( array_keys( self::$held ) as $name ) {
			self::release_lock( substr( $name, strlen( self::LOCK_PREFIX ) ) );
		}
	}

	/**
	 * Records a lock as held and makes sure shutdown releases it.
	 *
	 * @param string $name  Option name.
	 * @param string $token Owner token.
	 * @return void
	 */
	private static function hold( $name, $token ) {
		self::$held[ $name ] = $token;
		if ( false === has_action( 'shutdown', array( __CLASS__, 'release_held_locks' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'release_held_locks' ) );
		}
	}
}
