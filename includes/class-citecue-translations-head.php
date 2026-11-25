<?php
/**
 * Plain-mode language output: `<html lang>`, hreflang, and page-cache hygiene
 * for a group of CiteCue translations.
 *
 * Polylang and WPML print this themselves, so every hook here does nothing
 * outside plain mode. In plain mode a group is a main post plus the pages whose
 * `_citecue_translation_of` holds the main post's id; a member's language is
 * `_citecue_language`, and the main post falls back to the site locale's
 * language when it has none stored (every post pushed before this feature).
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the language signals of a plain-mode translation group.
 */
class Citecue_Translations_Head {

	const META_TRANSLATION_OF = '_citecue_translation_of';
	const META_LANGUAGE       = '_citecue_language';
	const META_EXTERNAL_ID    = '_citecue_external_id';

	/**
	 * Upper bound on siblings read. A group holds at most one post per language
	 * and a project has at most 5, so this is slack, not a limit anyone meets.
	 */
	const MAX_MEMBERS = 20;

	/**
	 * Whether a cache clean is already running, so the clean cannot re-enter
	 * itself through a hook another plugin hangs on `clean_post_cache`.
	 *
	 * @var bool
	 */
	private static $cleaning = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ) );
		add_action( 'wp_head', array( $this, 'print_hreflang' ) );

		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_language_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_language_meta' ), 10, 3 );
	}

	/**
	 * Sets `lang` on a CiteCue post that has a stored language.
	 *
	 * @param string $output The attributes WordPress built.
	 * @return string
	 */
	public function filter_language_attributes( $output ) {
		if ( Citecue_Languages::MODE_PLAIN !== Citecue_Languages::mode() || ! is_singular() ) {
			return $output;
		}

		$post_id = (int) get_queried_object_id();
		$code    = $post_id > 0 ? (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) : '';
		if ( '' === $code ) {
			return $output;
		}

		$attribute = 'lang="' . esc_attr( $code ) . '"';
		if ( preg_match( '/\blang=("[^"]*"|\'[^\']*\')/', (string) $output ) ) {
			return (string) preg_replace_callback(
				'/\blang=("[^"]*"|\'[^\']*\')/',
				static function () use ( $attribute ) {
					return $attribute;
				},
				(string) $output,
				1
			);
		}
		return trim( $output . ' ' . $attribute );
	}

	/**
	 * Prints one alternate link per published member, plus `x-default`.
	 *
	 * @return void
	 */
	public function print_hreflang() {
		if ( Citecue_Languages::MODE_PLAIN !== Citecue_Languages::mode() || ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$main_id = $this->main_id( $post_id );
		if ( $main_id < 1 ) {
			return;
		}

		$links = array();
		$seen  = array();
		foreach ( $this->members( $main_id, 'publish' ) as $member_id ) {
			$code = $this->language_of( $member_id, $main_id );
			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$url           = get_permalink( $member_id );
			if ( ! $url ) {
				continue;
			}
			$links[] = array( $code, $url, $member_id );
		}

		// A lone page has nothing to alternate with.
		if ( count( $links ) < 2 ) {
			return;
		}

		foreach ( $links as $link ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $link[0] ) . '" href="' . esc_url( $link[1] ) . '" />' . "\n";
			if ( $link[2] === $main_id ) {
				echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $link[1] ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Cleans the group's caches when a member's status changes.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post ) {
			return;
		}
		$this->clean_group( (int) $post->ID );
	}

	/**
	 * Cleans the group's caches when a member's slug changes.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 * @return void
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || $after->post_name === $before->post_name ) {
			return;
		}
		$this->clean_group( (int) $post_id );
	}

	/**
	 * Cleans the group's caches when a member's stored language changes.
	 *
	 * @param int    $meta_id  Meta id (unused).
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return void
	 */
	public function on_language_meta( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );
		if ( self::META_LANGUAGE !== $meta_key ) {
			return;
		}
		$this->clean_group( (int) $post_id );
	}

	/**
	 * Calls `clean_post_cache` on every member of the post's group.
	 *
	 * Some full-page caches purge only the post that changed, so the main
	 * post's cached HTML would miss a sibling's hreflang once it goes live.
	 *
	 * @param int $post_id Any member.
	 * @return void
	 */
	private function clean_group( $post_id ) {
		if ( self::$cleaning || Citecue_Languages::MODE_PLAIN !== Citecue_Languages::mode() ) {
			return;
		}

		$main_id = $this->main_id( $post_id );
		if ( $main_id < 1 ) {
			return;
		}

		self::$cleaning = true;
		try {
			$ids = array_unique( array_merge( array( $main_id, $post_id ), $this->members( $main_id, 'any' ) ) );
			foreach ( $ids as $id ) {
				clean_post_cache( (int) $id );
			}
		} finally {
			self::$cleaning = false;
		}
	}

	/**
	 * The main post id of the group a post belongs to, or 0 when the post is not
	 * a CiteCue post.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function main_id( $post_id ) {
		if ( $post_id < 1 ) {
			return 0;
		}
		$of = (int) get_post_meta( $post_id, self::META_TRANSLATION_OF, true );
		if ( $of > 0 ) {
			return $of;
		}
		return '' !== (string) get_post_meta( $post_id, self::META_EXTERNAL_ID, true ) ? $post_id : 0;
	}

	/**
	 * The group's member ids: the main post, then its siblings. One bounded
	 * meta query, by the indexed `meta_key`, filtered on `meta_value`.
	 *
	 * @param int    $main_id Main post id.
	 * @param string $status  A post status, or 'any'.
	 * @return int[]
	 */
	private function members( $main_id, $status ) {
		$ids = array();
		if ( 'any' === $status || 'publish' === get_post_status( $main_id ) ) {
			$ids[] = $main_id;
		}

		$siblings = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => $status,
				'posts_per_page'         => self::MAX_MEMBERS,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_key'               => self::META_TRANSLATION_OF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => (string) $main_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $siblings as $id ) {
			if ( (int) $id !== $main_id ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/**
	 * A member's language code: its stored one, else (main post only) the
	 * site locale's.
	 *
	 * @param int $post_id Member id.
	 * @param int $main_id Main post id.
	 * @return string
	 */
	private function language_of( $post_id, $main_id ) {
		$code = strtolower( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
		if ( '' === $code && $post_id === $main_id ) {
			$code = (string) Citecue_Languages::site_language_code();
		}
		return $code;
	}
}
