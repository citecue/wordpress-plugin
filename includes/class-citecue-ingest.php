<?php
/**
 * Content ingest: an HMAC-authenticated REST endpoint CiteCue (or any
 * automation holding the shared secret) can push generated brand content to —
 * content briefs, FAQ packs, and other pages that fill gaps where the brand
 * is missing from AI answers. Pushed content lands as a draft by default and
 * is created/updated idempotently by `external_id`.
 *
 *   POST /wp-json/citecue/v1/content
 *     Headers:
 *       Content-Type:        application/json
 *       X-Citecue-Timestamp: <unix seconds, ±300s tolerance>
 *       X-Citecue-Signature: sha256=<hex hmac_sha256("{timestamp}.{raw_body}", secret)>
 *     Body (JSON):
 *       external_id  string  required — stable id, dedupe/update key
 *       title        string  required
 *       content      string  required — post body HTML (sanitized with wp_kses_post)
 *       excerpt      string  optional — post excerpt / product short description
 *       slug         string  optional
 *       status       string  optional — draft|pending|publish, capped by the configured maximum
 *       type         string  optional — post|page|product (product requires WooCommerce; default from settings)
 *       categories   array   optional — category names, created if missing (posts: category; products: product_cat)
 *       tags         array   optional — tag names (posts: post_tag; products: product_tag)
 *       sku          string  optional — products only; also matches an existing product to adopt (with force)
 *       regular_price string optional — products only
 *       meta_description string optional — stored as _citecue_meta_description
 *       source       string  optional — provenance label (e.g. "content_brief:opp_123")
 *       force        bool    optional — overwrite even if the post was edited in WordPress
 *
 *   GET /wp-json/citecue/v1/health — public, minimal install handshake.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content ingest REST controller.
 */
class Citecue_Ingest {

	const REST_NAMESPACE   = 'citecue/v1';
	const TIMESTAMP_WINDOW = 300;

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
	 * Hooks route registration and the meta-description output.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/content',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_content' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Public install handshake: plugin presence + version, no secrets.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_health() {
		return rest_ensure_response(
			array(
				'plugin'      => 'citecue',
				'version'     => CITECUE_VERSION,
				'delivery'    => (bool) ( $this->plugin->settings->get( 'serve_enabled' ) && $this->plugin->settings->is_delivery_configured() ),
				'ingest'      => (bool) $this->plugin->settings->get( 'ingest_enabled' ),
				'woocommerce' => class_exists( 'WooCommerce' ),
			)
		);
	}

	/**
	 * Authenticates a push: ingest enabled, sane rate, fresh timestamp, and a
	 * valid HMAC over "{timestamp}.{raw_body}" with the shared secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function verify_request( WP_REST_Request $request ) {
		$settings = $this->plugin->settings;

		if ( ! $settings->get( 'ingest_enabled' ) ) {
			return new WP_Error( 'citecue_ingest_disabled', __( 'Content ingest is disabled in the CiteCue plugin settings.', 'citecue' ), array( 'status' => 403 ) );
		}

		$secret = (string) $settings->get( 'ingest_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'citecue_no_secret', __( 'No ingest secret is configured.', 'citecue' ), array( 'status' => 403 ) );
		}

		$timestamp = (int) $request->get_header( 'x-citecue-timestamp' );
		if ( abs( time() - $timestamp ) > self::TIMESTAMP_WINDOW ) {
			return new WP_Error( 'citecue_stale_timestamp', __( 'Missing or expired X-Citecue-Timestamp header.', 'citecue' ), array( 'status' => 401 ) );
		}

		$signature = (string) $request->get_header( 'x-citecue-signature' );
		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return new WP_Error( 'citecue_bad_signature', __( 'Missing or malformed X-Citecue-Signature header.', 'citecue' ), array( 'status' => 401 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( 'sha256=' . $expected, $signature ) ) {
			return new WP_Error( 'citecue_bad_signature', __( 'Invalid request signature.', 'citecue' ), array( 'status' => 401 ) );
		}

		// After authentication on purpose: unsigned traffic can never consume
		// the budget and lock out legitimate pushes.
		if ( ! $this->within_rate_limit() ) {
			return new WP_Error( 'citecue_rate_limited', __( 'Too many ingest requests; try again later.', 'citecue' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Sliding-hour rate limit for the ingest endpoint.
	 *
	 * @return bool Whether this request is within the limit.
	 */
	private function within_rate_limit() {
		/**
		 * Filters the maximum ingest requests accepted per hour.
		 *
		 * @param int $limit Default 120.
		 */
		$limit = max( 1, (int) apply_filters( 'citecue_ingest_rate_limit', 120 ) );
		$count = (int) get_transient( 'citecue_ingest_rate' );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( 'citecue_ingest_rate', $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Creates or updates a post from a signed CiteCue payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_content( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'citecue_bad_json', __( 'Request body must be JSON.', 'citecue' ), array( 'status' => 400 ) );
		}

		$external_id = isset( $params['external_id'] ) ? $this->sanitize_external_id( $params['external_id'] ) : '';
		$title       = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';
		$content_raw = isset( $params['content'] ) ? (string) $params['content'] : '';

		if ( '' === $external_id || '' === $title || '' === trim( $content_raw ) ) {
			return new WP_Error( 'citecue_missing_fields', __( 'external_id, title and content are required.', 'citecue' ), array( 'status' => 400 ) );
		}

		$content = wp_kses_post( $content_raw );

		$settings = $this->plugin->settings;
		$status   = $this->resolve_status( isset( $params['status'] ) ? (string) $params['status'] : '' );

		$requested_type = isset( $params['type'] ) ? (string) $params['type'] : '';
		if ( 'product' === $requested_type && ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'citecue_woocommerce_missing', __( 'This payload targets a WooCommerce product, but WooCommerce is not active on this site.', 'citecue' ), array( 'status' => 400 ) );
		}
		$allowed_types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$allowed_types[] = 'product';
		}
		$post_type = in_array( $requested_type, $allowed_types, true ) ? $requested_type : (string) $settings->get( 'ingest_post_type' );
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'post'; // Configured default is 'product' but WooCommerce got deactivated.
		}

		$existing_id = $this->find_by_external_id( $external_id );
		$force       = ! empty( $params['force'] );

		// Products can also be matched by SKU, so pushes can enrich an
		// existing catalog item. Adopting a product the plugin didn't create
		// always requires force — its description is about to be replaced.
		if ( ! $existing_id && 'product' === $post_type && isset( $params['sku'] ) && '' !== (string) $params['sku'] && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_match = (int) wc_get_product_id_by_sku( wc_clean( (string) $params['sku'] ) );
			if ( $sku_match > 0 ) {
				if ( ! $force ) {
					return new WP_Error(
						'citecue_sku_exists',
						__( 'A product with this SKU already exists; send force=true to adopt and update it.', 'citecue' ),
						array(
							'status'  => 409,
							'post_id' => $sku_match,
						)
					);
				}
				$existing_id = $sku_match;
			}
		}

		if ( $existing_id && get_post_type( $existing_id ) !== $post_type ) {
			return new WP_Error(
				'citecue_type_conflict',
				__( 'This external_id already exists with a different content type.', 'citecue' ),
				array(
					'status'  => 409,
					'post_id' => $existing_id,
				)
			);
		}

		// A trashed push stays trashed: the site owner rejected it, so it is
		// neither resurrected nor duplicated.
		if ( $existing_id && 'trash' === get_post_status( $existing_id ) ) {
			return new WP_Error(
				'citecue_trashed',
				__( 'A post with this external_id was trashed in WordPress; restore or delete it permanently first.', 'citecue' ),
				array(
					'status'  => 410,
					'post_id' => $existing_id,
				)
			);
		}

		if ( $existing_id && ! $force && $this->edited_locally( $existing_id ) ) {
			return new WP_Error(
				'citecue_edited_locally',
				__( 'This post was edited in WordPress after the last push; send force=true to overwrite.', 'citecue' ),
				array(
					'status'  => 409,
					'post_id' => $existing_id,
				)
			);
		}

		if ( 'product' === $post_type ) {
			$post_id = $this->upsert_product( $existing_id, $params, $title, $content, $status );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		} else {
			$postarr = array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_author'  => $this->resolve_author(),
			);
			if ( isset( $params['excerpt'] ) ) {
				$postarr['post_excerpt'] = sanitize_textarea_field( (string) $params['excerpt'] );
			}
			if ( isset( $params['slug'] ) && '' !== (string) $params['slug'] ) {
				$postarr['post_name'] = sanitize_title( (string) $params['slug'] );
			}
			if ( $existing_id ) {
				$postarr['ID'] = $existing_id;
			}

			/**
			 * Filters the post array before a CiteCue push is inserted/updated.
			 * Posts and pages only — products go through WooCommerce's CRUD.
			 *
			 * @param array $postarr wp_insert_post() arguments.
			 * @param array $params  Raw (unsanitized) request payload.
			 */
			$postarr = apply_filters( 'citecue_ingest_postarr', $postarr, $params );

			$result = $existing_id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 500 ) );
				return $result;
			}
			$post_id = (int) $result;
		}

		if ( 'post' === $post_type || 'product' === $post_type ) {
			$category_taxonomy = 'product' === $post_type ? 'product_cat' : 'category';
			$tag_taxonomy      = 'product' === $post_type ? 'product_tag' : 'post_tag';
			if ( isset( $params['categories'] ) && is_array( $params['categories'] ) ) {
				wp_set_post_terms( $post_id, $this->ensure_terms( $params['categories'], $category_taxonomy ), $category_taxonomy );
			}
			if ( isset( $params['tags'] ) && is_array( $params['tags'] ) ) {
				wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', array_map( 'strval', $params['tags'] ) ), $tag_taxonomy );
			}
		}

		update_post_meta( $post_id, '_citecue_external_id', $external_id );
		update_post_meta( $post_id, '_citecue_synced_at', time() );
		if ( isset( $params['source'] ) ) {
			update_post_meta( $post_id, '_citecue_source', sanitize_text_field( (string) $params['source'] ) );
		}
		if ( isset( $params['meta_description'] ) ) {
			update_post_meta( $post_id, '_citecue_meta_description', sanitize_text_field( (string) $params['meta_description'] ) );
		}

		// Hash the content as stored (after WordPress filters), so the next
		// push can tell "edited in WordPress" apart from "unchanged".
		$stored = get_post( $post_id );
		update_post_meta( $post_id, '_citecue_content_hash', md5( $stored ? $stored->post_content : '' ) );

		$response = rest_ensure_response(
			array(
				'created'   => ! $existing_id,
				'updated'   => (bool) $existing_id,
				'post_id'   => $post_id,
				'status'    => get_post_status( $post_id ),
				'permalink' => get_permalink( $post_id ),
				'edit_link' => add_query_arg(
					array(
						'post'   => $post_id,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				),
			)
		);
		$response->set_status( $existing_id ? 200 : 201 );
		return $response;
	}

	/**
	 * Restricts external ids to a safe charset and length.
	 *
	 * @param mixed $raw Raw external id.
	 * @return string
	 */
	private function sanitize_external_id( $raw ) {
		$id = preg_replace( '/[^A-Za-z0-9:_\-\.]/', '', (string) $raw );
		return substr( (string) $id, 0, 128 );
	}

	/**
	 * The effective post status: the requested one, capped by the configured
	 * maximum (draft < pending < publish). Pushed content can never be more
	 * visible than the site owner allowed.
	 *
	 * @param string $requested Requested status ('' for none).
	 * @return string
	 */
	private function resolve_status( $requested ) {
		$rank = array(
			'draft'   => 0,
			'pending' => 1,
			'publish' => 2,
		);
		$cap  = (string) $this->plugin->settings->get( 'ingest_post_status' );
		if ( ! isset( $rank[ $cap ] ) ) {
			$cap = 'draft';
		}
		if ( ! isset( $rank[ $requested ] ) ) {
			return $cap;
		}
		return $rank[ $requested ] <= $rank[ $cap ] ? $requested : $cap;
	}

	/**
	 * The author for pushed posts: the configured user when valid, otherwise
	 * the oldest administrator.
	 *
	 * @return int User ID (0 when the site has no administrator).
	 */
	private function resolve_author() {
		$configured = (int) $this->plugin->settings->get( 'ingest_author' );
		if ( $configured > 0 ) {
			$user = get_userdata( $configured );
			if ( $user && $user->has_cap( 'edit_posts' ) ) {
				return $configured;
			}
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		return $admins ? (int) $admins[0] : 0;
	}

	/**
	 * Creates or updates a WooCommerce product through WooCommerce's CRUD API
	 * (which maintains lookup tables, caches and SKU uniqueness — raw
	 * wp_insert_post would not). New products are simple products; updates
	 * keep whatever type the product already has.
	 *
	 * @param int    $existing_id Existing product ID, or 0 to create.
	 * @param array  $params      Raw request payload.
	 * @param string $title       Sanitized title.
	 * @param string $content     Sanitized description HTML.
	 * @param string $status      Effective post status.
	 * @return int|WP_Error Product ID.
	 */
	private function upsert_product( $existing_id, array $params, $title, $content, $status ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'citecue_woocommerce_missing', __( 'WooCommerce is not active on this site.', 'citecue' ), array( 'status' => 400 ) );
		}

		try {
			$product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();
			if ( ! $product ) {
				return new WP_Error( 'citecue_product_load_failed', __( 'The existing product could not be loaded.', 'citecue' ), array( 'status' => 500 ) );
			}

			$product->set_name( $title );
			$product->set_description( $content );
			$product->set_status( $status );
			if ( isset( $params['excerpt'] ) ) {
				$product->set_short_description( wp_kses_post( (string) $params['excerpt'] ) );
			}
			if ( isset( $params['slug'] ) && '' !== (string) $params['slug'] ) {
				$product->set_slug( sanitize_title( (string) $params['slug'] ) );
			}
			if ( isset( $params['sku'] ) && '' !== (string) $params['sku'] ) {
				$product->set_sku( wc_clean( (string) $params['sku'] ) );
			}
			if ( isset( $params['regular_price'] ) && '' !== (string) $params['regular_price'] ) {
				$product->set_regular_price( wc_format_decimal( (string) $params['regular_price'] ) );
			}

			$post_id = (int) $product->save();
		} catch ( WC_Data_Exception $e ) {
			// E.g. the SKU belongs to a different product.
			return new WP_Error( 'citecue_product_invalid', $e->getMessage(), array( 'status' => 409 ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'citecue_product_failed', __( 'WooCommerce rejected the product.', 'citecue' ), array( 'status' => 500 ) );
		}

		if ( $post_id <= 0 ) {
			return new WP_Error( 'citecue_product_failed', __( 'WooCommerce rejected the product.', 'citecue' ), array( 'status' => 500 ) );
		}

		// WC's CRUD does not take an author; attribute newly created products
		// like other pushed content.
		if ( ! $existing_id ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_author' => $this->resolve_author(),
				)
			);
		}

		return $post_id;
	}

	/**
	 * Finds a previously pushed post by its external id.
	 *
	 * @param string $external_id External id.
	 * @return int Post ID, or 0.
	 */
	private function find_by_external_id( $external_id ) {
		$found = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_citecue_external_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $external_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Whether the post's content changed in WordPress since our last push.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function edited_locally( $post_id ) {
		$last_hash = (string) get_post_meta( $post_id, '_citecue_content_hash', true );
		if ( '' === $last_hash ) {
			return false;
		}
		$post = get_post( $post_id );
		return $post && md5( $post->post_content ) !== $last_hash;
	}

	/**
	 * Resolves term names to ids for a hierarchical taxonomy, creating
	 * missing terms.
	 *
	 * @param array  $names    Term names.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private function ensure_terms( array $names, $taxonomy ) {
		$ids = array();
		foreach ( $names as $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$existing = term_exists( $name, $taxonomy );
			if ( $existing ) {
				$ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
				continue;
			}
			$created = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $created ) ) {
				$ids[] = (int) $created['term_id'];
			}
		}
		return $ids;
	}

	/**
	 * Prints a meta description for pushed content on singular views, unless
	 * a dedicated SEO plugin is active (those own the description tag).
	 *
	 * @return void
	 */
	public function output_meta_description() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id     = get_queried_object_id();
		$description = (string) get_post_meta( $post_id, '_citecue_meta_description', true );
		if ( '' === $description ) {
			return;
		}

		$seo_plugin_active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );

		/**
		 * Filters whether the plugin outputs the meta description tag for
		 * pushed content.
		 *
		 * @param bool $output  Default: true unless an SEO plugin is active.
		 * @param int  $post_id Queried post ID.
		 */
		if ( ! apply_filters( 'citecue_output_meta_description', ! $seo_plugin_active, $post_id ) ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
}
