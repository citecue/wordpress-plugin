<?php
/**
 * Admin settings screen: connection to CiteCue, project selection, delivery
 * toggles, content-ingest configuration, and a recent-activity view.
 *
 * @package Citecue
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings UI.
 */
class Citecue_Admin {

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
	 * Hooks the admin surface.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_citecue_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_citecue_refresh_crawlers', array( $this, 'handle_refresh_crawlers' ) );
		add_action( 'admin_post_citecue_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_action( 'admin_post_citecue_regen_secret', array( $this, 'handle_regen_secret' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CITECUE_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the Settings → CiteCue page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'CiteCue AI Auto-Fix', 'citecue' ),
			__( 'CiteCue', 'citecue' ),
			'manage_options',
			'citecue',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the settings group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'citecue',
			Citecue_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->plugin->settings, 'sanitize' ),
			)
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Settings', 'citecue' ) . '</a>' );
		return $links;
	}

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'options-general.php?page=citecue' );
	}

	/**
	 * Redirect back to the settings page with a message code.
	 *
	 * @param string $code Message code.
	 * @return void
	 */
	private function redirect_with( $code ) {
		wp_safe_redirect( add_query_arg( 'citecue_msg', $code, $this->settings_url() ) );
		exit;
	}

	/**
	 * Admin notices: action feedback plus a persistent auth-failure warning.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( 'citecue_auth_failed' ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'CiteCue:', 'citecue' ) . '</strong> '
				. esc_html__( 'the API key was rejected, so optimized pages are not being served to AI crawlers. Update the key in the CiteCue settings.', 'citecue' )
				. ' <a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Open settings', 'citecue' ) . '</a></p></div>';
		}

		if ( ! isset( $_GET['citecue_msg'] ) || ! isset( $_GET['page'] ) || 'citecue' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only feedback.
			return;
		}

		$messages = array(
			'connected'     => array( 'success', __( 'Connected to CiteCue.', 'citecue' ) ),
			'auto_selected' => array( 'success', __( 'Connected to CiteCue — the project matching this site was selected automatically.', 'citecue' ) ),
			'auth'          => array( 'error', __( 'CiteCue rejected the API key.', 'citecue' ) ),
			'conn_fail'     => array( 'error', __( 'Could not reach CiteCue. Check the API base URL and try again.', 'citecue' ) ),
			'crawlers_ok'   => array( 'success', __( 'Crawler registry refreshed.', 'citecue' ) ),
			'crawlers_fail' => array( 'warning', __( 'Could not refresh the crawler registry; the current list stays active.', 'citecue' ) ),
			'flushed'       => array( 'success', __( 'Delivery cache flushed.', 'citecue' ) ),
			'secret'        => array( 'success', __( 'New ingest secret generated. Update it anywhere the old secret was used.', 'citecue' ) ),
		);

		$code = sanitize_key( wp_unslash( $_GET['citecue_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
	}

	/**
	 * Test connection: fetch the org's projects, cache them, auto-select the
	 * project whose domain matches this site when none is selected yet.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue' ) );
		}
		check_admin_referer( 'citecue_test_connection' );

		$projects = $this->plugin->api->get_config();
		if ( is_wp_error( $projects ) ) {
			if ( 'citecue_invalid_key' === $projects->get_error_code() ) {
				update_option( 'citecue_auth_failed', time(), false );
				$this->redirect_with( 'auth' );
			}
			$this->redirect_with( 'conn_fail' );
		}

		delete_option( 'citecue_auth_failed' );
		delete_transient( 'citecue_circuit' );

		$clean = array();
		foreach ( $projects as $project ) {
			if ( ! is_array( $project ) || empty( $project['publicKey'] ) ) {
				continue;
			}
			$clean[] = array(
				'publicKey'    => sanitize_text_field( (string) $project['publicKey'] ),
				'domain'       => sanitize_text_field( (string) ( isset( $project['domain'] ) ? $project['domain'] : '' ) ),
				'enabled'      => ! empty( $project['enabled'] ),
				'serveLlmsTxt' => ! empty( $project['serveLlmsTxt'] ),
			);
		}
		update_option( 'citecue_projects_cache', $clean, false );
		update_option( 'citecue_last_config_at', time(), false );

		// Auto-select by host when no project is chosen yet.
		$settings = $this->plugin->settings;
		if ( '' === (string) $settings->get( 'public_key' ) ) {
			$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$site_host = preg_replace( '/^www\./', '', $site_host );
			foreach ( $clean as $project ) {
				$domain = strtolower( preg_replace( '/^www\./', '', $project['domain'] ) );
				if ( '' !== $domain && $domain === $site_host ) {
					$settings->update(
						array(
							'public_key'     => $project['publicKey'],
							'project_domain' => $project['domain'],
						)
					);
					$this->redirect_with( 'auto_selected' );
				}
			}
		}

		$this->redirect_with( 'connected' );
	}

	/**
	 * Manual crawler-registry refresh.
	 *
	 * @return void
	 */
	public function handle_refresh_crawlers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue' ) );
		}
		check_admin_referer( 'citecue_refresh_crawlers' );

		$ok = $this->plugin->crawlers->refresh( $this->plugin->api );
		$this->redirect_with( $ok ? 'crawlers_ok' : 'crawlers_fail' );
	}

	/**
	 * Flush the delivery cache.
	 *
	 * @return void
	 */
	public function handle_flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue' ) );
		}
		check_admin_referer( 'citecue_flush_cache' );

		$this->plugin->cache->flush();
		$this->redirect_with( 'flushed' );
	}

	/**
	 * Rotate the ingest secret.
	 *
	 * @return void
	 */
	public function handle_regen_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue' ) );
		}
		check_admin_referer( 'citecue_regen_secret' );

		$this->plugin->settings->update( array( 'ingest_secret' => 'cws_' . bin2hex( random_bytes( 20 ) ) ) );
		$this->redirect_with( 'secret' );
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->plugin->settings;
		$projects = get_option( 'citecue_projects_cache', array() );
		$projects = is_array( $projects ) ? $projects : array();
		$secret   = $settings->ensure_ingest_secret();
		$registry = $this->plugin->crawlers->registry_info();
		$api_key  = (string) $settings->get( 'api_key' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CiteCue AI Auto-Fix', 'citecue' ); ?></h1>
			<p>
				<?php esc_html_e( 'Serves CiteCue-optimized versions of your pages to AI bots and crawlers, publishes your llms.txt, and can receive new brand-building content from CiteCue as draft posts.', 'citecue' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'citecue' ); ?>

				<h2><?php esc_html_e( '1. Connection', 'citecue' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="citecue_api_key"><?php esc_html_e( 'API key', 'citecue' ); ?></label></th>
						<td>
							<input type="password" id="citecue_api_key" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( '' !== $api_key ? __( 'Saved — leave blank to keep', 'citecue' ) : 'ck_live_…' ); ?>" />
							<?php if ( '' !== $api_key ) : ?>
								<label style="margin-left:8px;"><input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_key_clear]" value="1" /> <?php esc_html_e( 'Clear stored key', 'citecue' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Create an organization API key in CiteCue under Settings → API keys (starts with ck_live_).', 'citecue' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_public_key"><?php esc_html_e( 'Project', 'citecue' ); ?></label></th>
						<td>
							<select id="citecue_public_key" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[public_key]">
								<option value=""><?php esc_html_e( '— Not selected —', 'citecue' ); ?></option>
								<?php
								$current_key   = (string) $settings->get( 'public_key' );
								$current_found = false;
								foreach ( $projects as $project ) :
									if ( $project['publicKey'] === $current_key ) {
										$current_found = true;
									}
									?>
									<option value="<?php echo esc_attr( $project['publicKey'] ); ?>" <?php selected( $current_key, $project['publicKey'] ); ?>>
										<?php echo esc_html( $project['domain'] ); ?>
										<?php echo $project['enabled'] ? '' : esc_html__( '(delivery disabled in CiteCue)', 'citecue' ); ?>
									</option>
								<?php endforeach; ?>
								<?php if ( '' !== $current_key && ! $current_found ) : ?>
									<option value="<?php echo esc_attr( $current_key ); ?>" selected>
										<?php echo esc_html( '' !== (string) $settings->get( 'project_domain' ) ? $settings->get( 'project_domain' ) : $current_key ); ?>
									</option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Use “Test connection” to load your CiteCue projects. The project matching this site’s domain is selected automatically.', 'citecue' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_api_base"><?php esc_html_e( 'API base URL', 'citecue' ); ?></label></th>
						<td>
							<input type="url" id="citecue_api_base" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_base]" value="<?php echo esc_attr( $settings->api_base() ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Only change this for a self-hosted CiteCue deployment.', 'citecue' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '2. Delivery to AI crawlers', 'citecue' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve optimized pages', 'citecue' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[serve_enabled]" value="1" <?php checked( (bool) $settings->get( 'serve_enabled' ) ); ?> />
								<?php esc_html_e( 'When an AI bot or crawler requests a page, serve the CiteCue-optimized version instead. Human visitors always see your normal site.', 'citecue' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve llms.txt', 'citecue' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[llms_txt_enabled]" value="1" <?php checked( (bool) $settings->get( 'llms_txt_enabled' ) ); ?> />
								<?php
								printf(
									/* translators: %s: llms.txt URL. */
									esc_html__( 'Publish your CiteCue llms.txt at %s.', 'citecue' ),
									'<code>' . esc_html( home_url( '/llms.txt' ) ) . '</code>'
								);
								?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '3. Content from CiteCue', 'citecue' ); ?></h2>
				<p class="description" style="max-width:720px;">
					<?php esc_html_e( 'CiteCue can push new brand-building content (content briefs, FAQ packs, gap-filling pages) into this site through a signed endpoint. Pushed content is created as a draft by default so nothing goes live without review.', 'citecue' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Accept pushed content', 'citecue' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_enabled]" value="1" <?php checked( (bool) $settings->get( 'ingest_enabled' ) ); ?> />
								<?php esc_html_e( 'Enable the signed content endpoint.', 'citecue' ); ?>
							</label>
							<p class="description"><code><?php echo esc_html( rest_url( 'citecue/v1/content' ) ); ?></code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_status"><?php esc_html_e( 'Maximum status', 'citecue' ); ?></label></th>
						<td>
							<select id="citecue_ingest_status" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_post_status]">
								<?php
								$status_labels = array(
									'draft'   => __( 'Draft (recommended — review before publishing)', 'citecue' ),
									'pending' => __( 'Pending review', 'citecue' ),
									'publish' => __( 'Published (auto-publish)', 'citecue' ),
								);
								foreach ( $status_labels as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings->get( 'ingest_post_status' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pushed content is never made more visible than this.', 'citecue' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_type"><?php esc_html_e( 'Default content type', 'citecue' ); ?></label></th>
						<td>
							<select id="citecue_ingest_type" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_post_type]">
								<option value="post" <?php selected( (string) $settings->get( 'ingest_post_type' ), 'post' ); ?>><?php esc_html_e( 'Post', 'citecue' ); ?></option>
								<option value="page" <?php selected( (string) $settings->get( 'ingest_post_type' ), 'page' ); ?>><?php esc_html_e( 'Page', 'citecue' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_author"><?php esc_html_e( 'Author for pushed content', 'citecue' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'              => esc_attr( Citecue_Settings::OPTION ) . '[ingest_author]',
									'id'                => 'citecue_ingest_author',
									'selected'          => (int) $settings->get( 'ingest_author' ),
									'show_option_none'  => __( '— First administrator —', 'citecue' ),
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shared secret', 'citecue' ); ?></th>
						<td>
							<code style="user-select:all;"><?php echo esc_html( $secret ); ?></code>
							<p class="description"><?php esc_html_e( 'Paste this into CiteCue (or your automation) to sign pushes. Requests are authenticated with an HMAC-SHA256 signature — see the plugin README for the exact scheme.', 'citecue' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Tools', 'citecue' ); ?></h2>
			<p>
				<?php $this->action_button( 'citecue_test_connection', __( 'Test connection', 'citecue' ) ); ?>
				<?php $this->action_button( 'citecue_refresh_crawlers', __( 'Refresh crawler list', 'citecue' ) ); ?>
				<?php $this->action_button( 'citecue_flush_cache', __( 'Flush delivery cache', 'citecue' ) ); ?>
				<?php $this->action_button( 'citecue_regen_secret', __( 'Regenerate ingest secret', 'citecue' ) ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: crawler count, 2: registry version. */
					esc_html__( 'Crawler registry: %1$d user-agent tokens (version %2$d)', 'citecue' ),
					(int) $registry['count'],
					(int) $registry['version']
				);
				if ( $registry['fetched_at'] > 0 ) {
					printf(
						/* translators: %s: human time diff. */
						', ' . esc_html__( 'refreshed %s ago', 'citecue' ),
						esc_html( human_time_diff( $registry['fetched_at'] ) )
					);
				} else {
					echo ', ' . esc_html__( 'bundled list (not refreshed yet)', 'citecue' );
				}
				?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: curl command. */
					esc_html__( 'Verify serving from a terminal: %s — the response should include the “x-citecue” header.', 'citecue' ),
					'<code>curl -si -A GPTBot ' . esc_html( home_url( '/llms.txt' ) ) . '</code>'
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Recent AI crawler activity', 'citecue' ); ?></h2>
			<?php $this->render_activity(); ?>
			<p class="description"><?php esc_html_e( 'Full analytics live in CiteCue → Agent Traffic. This local view exists to verify the integration is working.', 'citecue' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders a small admin-post action button.
	 *
	 * @param string $action admin_post action name (also the nonce action).
	 * @param string $label  Button label.
	 * @return void
	 */
	private function action_button( $action, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the recent-activity table.
	 *
	 * @return void
	 */
	private function render_activity() {
		$entries = $this->plugin->activity->entries();
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No AI crawler visits recorded yet.', 'citecue' ) . '</p>';
			return;
		}

		$outcome_labels = array(
			'served'       => __( 'Served optimized', 'citecue' ),
			'served-stale' => __( 'Served (stale cache)', 'citecue' ),
			'passthrough'  => __( 'Passed through', 'citecue' ),
			'error'        => __( 'API error — passed through', 'citecue' ),
		);
		?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'citecue' ); ?></th>
					<th><?php esc_html_e( 'Crawler', 'citecue' ); ?></th>
					<th><?php esc_html_e( 'Path', 'citecue' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'citecue' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td>
							<?php
							printf(
								/* translators: %s: human time diff. */
								esc_html__( '%s ago', 'citecue' ),
								esc_html( human_time_diff( (int) $entry['time'] ) )
							);
							?>
						</td>
						<td><?php echo esc_html( $entry['crawler'] ); ?></td>
						<td><code><?php echo esc_html( $entry['path'] ); ?></code></td>
						<td><?php echo esc_html( isset( $outcome_labels[ $entry['outcome'] ] ) ? $outcome_labels[ $entry['outcome'] ] : $entry['outcome'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
