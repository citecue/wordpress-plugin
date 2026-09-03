<?php
/**
 * Admin settings screen.
 *
 * The screen has two shapes, because a site that has never been connected and
 * a site that is running have almost nothing in common. Before connecting
 * there is one button and one sentence; the API key, project selector and API
 * base are folded away as the fallback they are. After connecting the setup
 * fields disappear and what is left is what an operator actually adjusts.
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
	 * User-option prefix recording a notice this user has dismissed.
	 *
	 * A user option rather than user meta, because on multisite the usermeta
	 * table is shared across the whole network while the condition these
	 * notices report on comes from per-site options. Stored as meta, one
	 * administrator dismissing the prompt on one site in a network would
	 * silence an unrelated, still-true prompt on all the others;
	 * update_user_option() prefixes the key with the current blog's, so each
	 * site gets its own answer.
	 */
	const DISMISSED_OPTION_PREFIX = 'citecue_dismissed_';

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
		add_action( 'admin_init', array( $this, 'maybe_claim_connect' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_notice' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_citecue_connect_start', array( $this, 'handle_connect_start' ) );
		add_action( 'admin_post_citecue_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_citecue_verify_install', array( $this, 'handle_verify_install' ) );
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
			__( 'CiteCue AI Auto-Fix', 'citecue-ai-auto-fix' ),
			__( 'CiteCue', 'citecue-ai-auto-fix' ),
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
		array_unshift( $links, '<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Settings', 'citecue-ai-auto-fix' ) . '</a>' );
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
	 * Whether the screen being rendered is one of the plugin's own.
	 *
	 * This plugin has no business putting messages on the comment queue, the
	 * media library or anyone's post editor, so every notice it emits is gated
	 * on this. Two screens qualify: the settings page the message is about, and
	 * the Plugins list, which is where an administrator looks when a plugin
	 * needs attention and the only screen on which some of these are actionable.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$id = (string) $screen->id;

		return 'plugins' === $id || false !== strpos( $id, 'citecue' );
	}

	/**
	 * Admin notices: action feedback plus the two conditions worth interrupting
	 * an administrator over.
	 *
	 * Confined to the plugin's own screens — see is_plugin_screen(). Nothing
	 * here is urgent enough to follow someone around their whole dashboard, and
	 * the settings screen states all of it a second time in its status card, so
	 * a dismissed or unseen notice costs no information.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}

		if ( get_option( 'citecue_auth_failed' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'CiteCue:', 'citecue-ai-auto-fix' ) . '</strong> '
				. esc_html__( 'the API key was rejected, so optimized pages are not being served to AI crawlers. Update the key in the CiteCue settings.', 'citecue-ai-auto-fix' )
				. ' <a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Open settings', 'citecue-ai-auto-fix' ) . '</a></p></div>';
		}

		$this->seo_head_reconnect_notice();

		if ( ! isset( $_GET['citecue_msg'] ) || ! isset( $_GET['page'] ) || 'citecue' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only feedback.
			return;
		}

		$messages = array(
			'connected'     => array( 'success', __( 'Connected to CiteCue.', 'citecue-ai-auto-fix' ) ),
			'auto_selected' => array( 'success', __( 'Connected to CiteCue — the project matching this site was selected automatically.', 'citecue-ai-auto-fix' ) ),
			'paired'        => array( 'success', __( 'Connected. CiteCue now knows this site’s address and can serve optimized pages to AI crawlers.', 'citecue-ai-auto-fix' ) ),
			'pair_state'    => array( 'error', __( 'That connection link did not match this WordPress session, so it was not used. Start the connection again.', 'citecue-ai-auto-fix' ) ),
			'pair_fail'     => array( 'error', __( 'The connection could not be completed.', 'citecue-ai-auto-fix' ) ),
			'disconnected'  => array( 'success', __( 'Disconnected from CiteCue. Optimized pages are no longer served.', 'citecue-ai-auto-fix' ) ),
			'verified'      => array( 'success', __( 'Verified — this site answers AI crawlers with CiteCue’s llms.txt.', 'citecue-ai-auto-fix' ) ),
			'verify_fail'   => array( 'warning', __( 'Verification failed. See the details below.', 'citecue-ai-auto-fix' ) ),
			'verify_skip'   => array( 'info', __( 'The check could not run. See the details below.', 'citecue-ai-auto-fix' ) ),
			'auth'          => array( 'error', __( 'CiteCue rejected the API key.', 'citecue-ai-auto-fix' ) ),
			'conn_fail'     => array( 'error', __( 'Could not reach CiteCue. Check your connection and try again.', 'citecue-ai-auto-fix' ) ),
			'crawlers_ok'   => array( 'success', __( 'Crawler registry refreshed.', 'citecue-ai-auto-fix' ) ),
			'crawlers_fail' => array( 'warning', __( 'Could not refresh the crawler registry; the current list stays active.', 'citecue-ai-auto-fix' ) ),
			'flushed'       => array( 'success', __( 'Delivery cache flushed.', 'citecue-ai-auto-fix' ) ),
			'secret'        => array( 'success', __( 'New ingest secret generated. Update it anywhere the old secret was used.', 'citecue-ai-auto-fix' ) ),
		);

		$code = sanitize_key( wp_unslash( $_GET['citecue_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		// The handshake failure reason is carried out-of-band rather than in the
		// URL: it is API text, not something to reflect back from a query arg.
		$detail = 'pair_fail' === $code ? get_transient( 'citecue_connect_error' ) : '';
		if ( $detail ) {
			delete_transient( 'citecue_connect_error' );
		}

		echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>'
			. esc_html( $messages[ $code ][1] )
			. ( $detail ? ' ' . esc_html( $detail ) : '' )
			. '</p></div>';
	}

	/**
	 * Asks for a reconnect when CiteCue's record of this site's SEO-head
	 * capability is out of date.
	 *
	 * CiteCue learns the capability only from the connect exchange, so a site
	 * that connected before this release injects enriched metadata while the
	 * app still reports the channel as unable to — and tells the customer their
	 * "Live" fix is not reaching human visitors. One reconnect fixes it.
	 *
	 * It is advice, not an error, so it can be turned off for good: the same
	 * state is on the settings screen's status card either way, and a message
	 * an administrator has read and decided against should not keep arriving.
	 *
	 * @return void
	 */
	private function seo_head_reconnect_notice() {
		if ( ! $this->plugin->settings->needs_seo_head_reconnect() ) {
			return;
		}
		if ( $this->notice_is_dismissed( 'seo_head_reconnect' ) ) {
			return;
		}

		switch ( $this->plugin->settings->seo_head_reconnect_reason() ) {
			case 'disabled':
				$message = __( 'enriched page metadata is switched off here, but CiteCue still expects this site to add it. Reconnect so CiteCue stops reporting metadata it is not getting.', 'citecue-ai-auto-fix' );
				break;

			case 'capabilities':
				// The upgrade case, and the common one: the metadata setting has
				// not moved, so saying anything about metadata would send an
				// administrator looking for a fault that is not there.
				$message = __( 'this version can place CiteCue page enhancements — the facts-and-FAQ sections you approve — on your pages, but CiteCue does not know that yet. Until you reconnect, it will hold on to every enhancement you approve instead of sending it here.', 'citecue-ai-auto-fix' );
				break;

			default:
				$message = __( 'this site can now add CiteCue’s enriched title, description, OpenGraph and structured data to your live pages, but CiteCue does not know that yet — until you reconnect, it will keep reporting that your fixes do not reach human visitors.', 'citecue-ai-auto-fix' );
				break;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'CiteCue:', 'citecue-ai-auto-fix' ); ?></strong>
				<?php echo esc_html( $message ); ?>
			</p>
			<p>
				<?php $this->action_button( 'citecue_connect_start', __( 'Reconnect to CiteCue', 'citecue-ai-auto-fix' ) ); ?>
				<a href="<?php echo esc_url( $this->dismiss_url( 'seo_head_reconnect' ) ); ?>"><?php esc_html_e( 'Dismiss permanently', 'citecue-ai-auto-fix' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether this user has dismissed a notice for good.
	 *
	 * Per user rather than per site: one administrator deciding they do not
	 * want to be told again is not a decision to make on their colleagues'
	 * behalf. Per site as well as per user — see DISMISSED_OPTION_PREFIX.
	 *
	 * @param string $notice Notice key.
	 * @return bool
	 */
	private function notice_is_dismissed( $notice ) {
		return (bool) get_user_option( self::DISMISSED_OPTION_PREFIX . $notice, get_current_user_id() );
	}

	/**
	 * The link that dismisses a notice for good.
	 *
	 * @param string $notice Notice key.
	 * @return string
	 */
	private function dismiss_url( $notice ) {
		return wp_nonce_url(
			add_query_arg( 'citecue_dismiss', $notice, $this->current_admin_url() ),
			'citecue_dismiss_' . $notice
		);
	}

	/**
	 * The admin URL currently being rendered, for links that come back here.
	 *
	 * @return string
	 */
	private function current_admin_url() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return ( $screen && 'plugins' === $screen->id ) ? admin_url( 'plugins.php' ) : $this->settings_url();
	}

	/**
	 * Records a dismissal and reloads the screen without the query arguments.
	 *
	 * @return void
	 */
	public function maybe_dismiss_notice() {
		if ( ! isset( $_GET['citecue_dismiss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below, once there is something to check it against.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['citecue_dismiss'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
		check_admin_referer( 'citecue_dismiss_' . $notice );

		if ( 'seo_head_reconnect' !== $notice ) {
			return;
		}

		update_user_option( get_current_user_id(), self::DISMISSED_OPTION_PREFIX . $notice, time() );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? remove_query_arg( array( 'citecue_dismiss', '_wpnonce' ), $back ) : $this->settings_url() );
		exit;
	}

	/**
	 * Completes a handshake when CiteCue redirects back with a one-time code.
	 *
	 * There is no nonce on this request and there cannot be one — the redirect
	 * originates at CiteCue. The state token minted by Citecue_Connect::start()
	 * is the CSRF defence: it lives server-side, is bound to this user, is
	 * single-use, and expires in fifteen minutes.
	 *
	 * @return void
	 */
	public function maybe_claim_connect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the state token below is the nonce.
		if ( ! isset( $_GET['page'], $_GET['citecue_code'] ) || 'citecue' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = isset( $_GET['citecue_state'] ) ? sanitize_text_field( wp_unslash( $_GET['citecue_state'] ) ) : '';
		$code  = sanitize_text_field( wp_unslash( $_GET['citecue_code'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $this->plugin->connect->verify_state( $state ) ) {
			$this->redirect_with( 'pair_state' );
		}

		$result = $this->plugin->connect->claim( $code );
		if ( is_wp_error( $result ) ) {
			set_transient( 'citecue_connect_error', $result->get_error_message(), MINUTE_IN_SECONDS );
			$this->redirect_with( 'pair_fail' );
		}

		// Answer the "did it work?" question before it is asked.
		$this->plugin->connect->verify_install();

		$this->redirect_with( 'paired' );
	}

	/**
	 * Sends the admin to CiteCue to pick the project for this site.
	 *
	 * @return void
	 */
	public function handle_connect_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
		}
		check_admin_referer( 'citecue_connect_start' );

		// Not wp_safe_redirect(): this one deliberately leaves the site, to the
		// configured CiteCue app and nowhere else.
		wp_redirect( $this->plugin->connect->start( $this->settings_url() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Forgets the CiteCue credentials.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
		}
		check_admin_referer( 'citecue_disconnect' );

		$this->plugin->connect->disconnect();
		$this->redirect_with( 'disconnected' );
	}

	/**
	 * Runs the loopback install check.
	 *
	 * @return void
	 */
	public function handle_verify_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
		}
		check_admin_referer( 'citecue_verify_install' );

		$result = $this->plugin->connect->verify_install();
		if ( ! empty( $result['skipped'] ) ) {
			$this->redirect_with( 'verify_skip' );
		}
		$this->redirect_with( $result['ok'] ? 'verified' : 'verify_fail' );
	}

	/**
	 * Save & test connection: persist the submitted settings first (so a key
	 * pasted moments ago is the one being tested), then fetch the org's
	 * projects, cache them, and auto-select the project whose domain matches
	 * this site when none is selected yet.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
		}
		check_admin_referer( 'citecue_test_connection', 'citecue_test_nonce' );

		if ( isset( $_POST[ Citecue_Settings::OPTION ] ) && is_array( $_POST[ Citecue_Settings::OPTION ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() is the sanitizer.
			$raw = wp_unslash( $_POST[ Citecue_Settings::OPTION ] );
			$this->plugin->settings->update( $this->plugin->settings->sanitize( $raw ) );
		}

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
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
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
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
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
			wp_die( esc_html__( 'You are not allowed to do that.', 'citecue-ai-auto-fix' ) );
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CiteCue AI Auto-Fix', 'citecue-ai-auto-fix' ); ?></h1>
			<?php
			if ( $this->plugin->settings->is_connected() ) {
				$this->render_connected();
			} else {
				$this->render_setup();
			}
			?>
		</div>
		<?php
	}

	/**
	 * First run: one button. The API-key route stays available underneath for
	 * installs that cannot complete a browser round-trip to CiteCue — an
	 * intranet site, a locked-down staging host — but it is no longer the
	 * thing a new customer is confronted with.
	 *
	 * @return void
	 */
	private function render_setup() {
		$settings = $this->plugin->settings;
		$host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		?>
		<p style="max-width:720px;">
			<?php esc_html_e( 'Serves CiteCue-optimized versions of your pages to AI bots and crawlers, publishes your llms.txt, and can receive new brand-building content from CiteCue as draft posts.', 'citecue-ai-auto-fix' ); ?>
		</p>

		<div class="card" style="max-width:720px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Connect this site to CiteCue', 'citecue-ai-auto-fix' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: this site's host name. */
					esc_html__( 'You will be sent to CiteCue to confirm which project covers %s, then returned here. There is nothing to copy or paste — the API key and the content-push secret are exchanged for you.', 'citecue-ai-auto-fix' ),
					'<strong>' . esc_html( $host ) . '</strong>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="citecue_connect_start" />
				<?php wp_nonce_field( 'citecue_connect_start' ); ?>
				<?php submit_button( __( 'Connect to CiteCue', 'citecue-ai-auto-fix' ), 'primary', 'submit', false ); ?>
			</form>
		</div>

		<details style="margin-top:20px;max-width:720px;">
			<summary style="cursor:pointer;"><?php esc_html_e( 'Connect with an API key instead', 'citecue-ai-auto-fix' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=citecue_test_connection' ) ); ?>">
				<?php
				wp_nonce_field( 'citecue_test_connection', 'citecue_test_nonce' );
				// This form owns only the connection fields, but sanitize()
				// reads every checkbox as "absent means off" — so the delivery
				// toggles it does not render travel as hidden inputs rather
				// than being silently switched off by a save.
				$this->preserve_toggles( array( 'serve_enabled', 'llms_txt_enabled', 'seo_head_enabled', 'ingest_enabled' ) );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="citecue_api_key"><?php esc_html_e( 'API key', 'citecue-ai-auto-fix' ); ?></label></th>
						<td>
							<input type="password" id="citecue_api_key" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="ck_live_…" />
							<p class="description"><?php esc_html_e( 'Create an organization API key in CiteCue under Settings → API keys. The project matching this site’s domain is selected automatically.', 'citecue-ai-auto-fix' ); ?></p>
						</td>
					</tr>
					<?php $this->render_api_base_row( $settings ); ?>
				</table>
				<?php submit_button( __( 'Save & test connection', 'citecue-ai-auto-fix' ), 'secondary' ); ?>
			</form>
		</details>
		<?php
	}

	/**
	 * The running site: status first, then the handful of switches an operator
	 * changes. Everything used only to establish or repair the connection is
	 * folded into "Connection details" at the bottom.
	 *
	 * @return void
	 */
	private function render_connected() {
		$settings = $this->plugin->settings;
		$projects = get_option( 'citecue_projects_cache', array() );
		$projects = is_array( $projects ) ? $projects : array();
		$secret   = $settings->ensure_ingest_secret();
		$registry = $this->plugin->crawlers->registry_info();

		$this->render_status_card();
		?>

			<form method="post" action="options.php">
				<?php settings_fields( 'citecue' ); ?>

				<h2><?php esc_html_e( 'Delivery to AI crawlers', 'citecue-ai-auto-fix' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve optimized pages', 'citecue-ai-auto-fix' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[serve_enabled]" value="1" <?php checked( (bool) $settings->get( 'serve_enabled' ) ); ?> />
								<?php esc_html_e( 'When an AI bot or crawler requests a page, serve the CiteCue-optimized version instead. Human visitors always see your normal site.', 'citecue-ai-auto-fix' ); ?>
							</label>
							<?php if ( class_exists( 'WooCommerce' ) ) : ?>
								<p class="description"><?php esc_html_e( 'WooCommerce detected: cart, checkout, account pages and cart-modifying links are never intercepted. Product and shop pages are served normally.', 'citecue-ai-auto-fix' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enrich page metadata', 'citecue-ai-auto-fix' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[seo_head_enabled]" value="1" <?php checked( (bool) $settings->get( 'seo_head_enabled' ) ); ?> />
								<?php esc_html_e( 'Add CiteCue’s title, description, OpenGraph, canonical and structured-data tags to your live pages, so search engines and AI answer engines see them too.', 'citecue-ai-auto-fix' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Only fills gaps. Any tag your theme, WordPress or your SEO plugin already outputs is left exactly as it is — CiteCue never emits a second title or canonical.', 'citecue-ai-auto-fix' ); ?>
								<?php esc_html_e( 'This also places page enhancements: where you have approved one in CiteCue, a short facts-and-FAQ section is added to the end of that page. It is the only thing here your visitors can see.', 'citecue-ai-auto-fix' ); ?>
								<?php
								$seo_plugin = self::detected_seo_plugin();
								if ( '' !== $seo_plugin ) {
									printf(
										/* translators: %s: name of the detected SEO plugin. */
										' ' . esc_html__( '%s is active, so it keeps control of the tags it manages.', 'citecue-ai-auto-fix' ),
										esc_html( $seo_plugin )
									);
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve llms.txt', 'citecue-ai-auto-fix' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[llms_txt_enabled]" value="1" <?php checked( (bool) $settings->get( 'llms_txt_enabled' ) ); ?> />
								<?php
								printf(
									/* translators: %s: llms.txt URL. */
									esc_html__( 'Publish your CiteCue llms.txt at %s.', 'citecue-ai-auto-fix' ),
									'<code>' . esc_html( home_url( '/llms.txt' ) ) . '</code>'
								);
								?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Content from CiteCue', 'citecue-ai-auto-fix' ); ?></h2>
				<p class="description" style="max-width:720px;">
					<?php esc_html_e( 'CiteCue can push new brand-building content (content briefs, FAQ packs, gap-filling pages) into this site through a signed endpoint. Pushed content is created as a draft by default so nothing goes live without review.', 'citecue-ai-auto-fix' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Accept pushed content', 'citecue-ai-auto-fix' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_enabled]" value="1" <?php checked( (bool) $settings->get( 'ingest_enabled' ) ); ?> />
								<?php esc_html_e( 'Enable the signed content endpoint.', 'citecue-ai-auto-fix' ); ?>
							</label>
							<p class="description"><code><?php echo esc_html( rest_url( 'citecue/v1/content' ) ); ?></code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_status"><?php esc_html_e( 'Maximum status', 'citecue-ai-auto-fix' ); ?></label></th>
						<td>
							<select id="citecue_ingest_status" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_post_status]">
								<?php
								$status_labels = array(
									'draft'   => __( 'Draft (recommended — review before publishing)', 'citecue-ai-auto-fix' ),
									'pending' => __( 'Pending review', 'citecue-ai-auto-fix' ),
									'publish' => __( 'Published (auto-publish)', 'citecue-ai-auto-fix' ),
								);
								foreach ( $status_labels as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings->get( 'ingest_post_status' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pushed content is never made more visible than this.', 'citecue-ai-auto-fix' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_type"><?php esc_html_e( 'Default content type', 'citecue-ai-auto-fix' ); ?></label></th>
						<td>
							<select id="citecue_ingest_type" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[ingest_post_type]">
								<option value="post" <?php selected( (string) $settings->get( 'ingest_post_type' ), 'post' ); ?>><?php esc_html_e( 'Post', 'citecue-ai-auto-fix' ); ?></option>
								<option value="page" <?php selected( (string) $settings->get( 'ingest_post_type' ), 'page' ); ?>><?php esc_html_e( 'Page', 'citecue-ai-auto-fix' ); ?></option>
								<?php if ( class_exists( 'WooCommerce' ) ) : ?>
									<option value="product" <?php selected( (string) $settings->get( 'ingest_post_type' ), 'product' ); ?>><?php esc_html_e( 'Product (WooCommerce)', 'citecue-ai-auto-fix' ); ?></option>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used when a push does not specify a type; each push may override it.', 'citecue-ai-auto-fix' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="citecue_ingest_author"><?php esc_html_e( 'Author for pushed content', 'citecue-ai-auto-fix' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'              => esc_attr( Citecue_Settings::OPTION ) . '[ingest_author]',
									'id'                => 'citecue_ingest_author',
									'selected'          => (int) $settings->get( 'ingest_author' ),
									'show_option_none'  => __( '— First administrator —', 'citecue-ai-auto-fix' ),
									'option_none_value' => 0,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shared secret', 'citecue-ai-auto-fix' ); ?></th>
						<td>
							<details>
								<summary style="cursor:pointer;"><?php esc_html_e( 'Show the signing secret', 'citecue-ai-auto-fix' ); ?></summary>
								<p><code style="user-select:all;"><?php echo esc_html( $secret ); ?></code></p>
							</details>
							<p class="description"><?php esc_html_e( 'CiteCue already holds this secret — connecting handed it over. You only need it to sign pushes from your own automation; the README documents the HMAC-SHA256 scheme.', 'citecue-ai-auto-fix' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save changes', 'citecue-ai-auto-fix' ) ); ?>

				<details style="max-width:720px;">
					<summary style="cursor:pointer;"><?php esc_html_e( 'Connection details', 'citecue-ai-auto-fix' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="citecue_public_key"><?php esc_html_e( 'Project', 'citecue-ai-auto-fix' ); ?></label></th>
							<td>
								<select id="citecue_public_key" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[public_key]">
									<option value=""><?php esc_html_e( '— Not selected —', 'citecue-ai-auto-fix' ); ?></option>
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
											<?php echo $project['enabled'] ? '' : esc_html__( '(delivery disabled in CiteCue)', 'citecue-ai-auto-fix' ); ?>
										</option>
									<?php endforeach; ?>
									<?php if ( '' !== $current_key && ! $current_found ) : ?>
										<option value="<?php echo esc_attr( $current_key ); ?>" selected>
											<?php echo esc_html( '' !== (string) $settings->get( 'project_domain' ) ? $settings->get( 'project_domain' ) : $current_key ); ?>
										</option>
									<?php endif; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Chosen when you connected. Change it only to point this site at a different CiteCue project.', 'citecue-ai-auto-fix' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="citecue_api_key"><?php esc_html_e( 'API key', 'citecue-ai-auto-fix' ); ?></label></th>
							<td>
								<input type="password" id="citecue_api_key" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Saved — leave blank to keep', 'citecue-ai-auto-fix' ); ?>" />
								<label style="margin-left:8px;"><input type="checkbox" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_key_clear]" value="1" /> <?php esc_html_e( 'Clear stored key', 'citecue-ai-auto-fix' ); ?></label>
								<p class="description"><?php esc_html_e( 'Only needed to replace a revoked key without reconnecting.', 'citecue-ai-auto-fix' ); ?></p>
							</td>
						</tr>
						<?php $this->render_api_base_row( $settings ); ?>
					</table>
					<?php
					// Reloads the project list against the stored key. Posted
					// from this same form via formaction, so a key typed just
					// above is saved and tested in one step.
					wp_nonce_field( 'citecue_test_connection', 'citecue_test_nonce' );
					?>
					<button type="submit" class="button" formmethod="post" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=citecue_test_connection' ) ); ?>">
						<?php esc_html_e( 'Save & test connection', 'citecue-ai-auto-fix' ); ?>
					</button>
				</details>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Tools', 'citecue-ai-auto-fix' ); ?></h2>
			<p>
				<?php $this->action_button( 'citecue_refresh_crawlers', __( 'Refresh crawler list', 'citecue-ai-auto-fix' ) ); ?>
				<?php $this->action_button( 'citecue_flush_cache', __( 'Flush delivery cache', 'citecue-ai-auto-fix' ) ); ?>
				<?php $this->action_button( 'citecue_regen_secret', __( 'Regenerate ingest secret', 'citecue-ai-auto-fix' ) ); ?>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: crawler count, 2: registry version. */
					esc_html__( 'Crawler registry: %1$d user-agent tokens (version %2$d)', 'citecue-ai-auto-fix' ),
					(int) $registry['count'],
					(int) $registry['version']
				);
				if ( $registry['fetched_at'] > 0 ) {
					printf(
						/* translators: %s: human time diff. */
						', ' . esc_html__( 'refreshed %s ago', 'citecue-ai-auto-fix' ),
						esc_html( human_time_diff( $registry['fetched_at'] ) )
					);
				} else {
					echo ', ' . esc_html__( 'bundled list (not refreshed yet)', 'citecue-ai-auto-fix' );
				}
				?>
			</p>

			<h2><?php esc_html_e( 'Recent AI crawler activity', 'citecue-ai-auto-fix' ); ?></h2>
			<?php $this->render_activity(); ?>
			<p class="description"><?php esc_html_e( 'Full analytics live in CiteCue → Agent Traffic. This local view exists to verify the integration is working.', 'citecue-ai-auto-fix' ); ?></p>
		<?php
	}

	/**
	 * The "is this working?" panel: which project the site is paired with, the
	 * result of the last loopback check, and the two things there are to do
	 * about it.
	 *
	 * @return void
	 */
	private function render_status_card() {
		$settings = $this->plugin->settings;
		$domain   = (string) $settings->get( 'project_domain' );
		$verified = $this->plugin->connect->last_verification();
		?>
		<div class="card" style="max-width:720px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Connected to CiteCue', 'citecue-ai-auto-fix' ); ?></h2>
			<table class="widefat striped" style="border:0;">
				<tr>
					<td style="width:34%;"><?php esc_html_e( 'Project', 'citecue-ai-auto-fix' ); ?></td>
					<td>
						<?php if ( '' !== $domain ) : ?>
							<strong><?php echo esc_html( $domain ); ?></strong>
						<?php else : ?>
							<em><?php esc_html_e( 'No project selected — pick one under Connection details.', 'citecue-ai-auto-fix' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Optimized pages', 'citecue-ai-auto-fix' ); ?></td>
					<td><?php echo $settings->get( 'serve_enabled' ) ? esc_html__( 'Served to AI crawlers', 'citecue-ai-auto-fix' ) : esc_html__( 'Off', 'citecue-ai-auto-fix' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Page metadata', 'citecue-ai-auto-fix' ); ?></td>
					<td>
						<?php if ( ! $settings->get( 'seo_head_enabled' ) ) : ?>
							<?php esc_html_e( 'Off', 'citecue-ai-auto-fix' ); ?>
						<?php elseif ( in_array( $settings->seo_head_reconnect_reason(), array( 'enabled', 'disabled' ), true ) ) : ?>
							<span style="color:#996800;">&#8212;</span>
							<?php esc_html_e( 'Enriched here, but CiteCue has not been told yet — reconnect to update it.', 'citecue-ai-auto-fix' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Enriched on live pages (gaps only)', 'citecue-ai-auto-fix' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Page enhancements', 'citecue-ai-auto-fix' ); ?></td>
					<td>
						<?php if ( ! $settings->get( 'seo_head_enabled' ) ) : ?>
							<?php esc_html_e( 'Off — they arrive with page metadata, which is switched off above.', 'citecue-ai-auto-fix' ); ?>
						<?php elseif ( '' !== $settings->seo_head_reconnect_reason() ) : ?>
							<span style="color:#996800;">&#8212;</span>
							<?php esc_html_e( 'Ready here, but CiteCue has not been told yet — reconnect to update it.', 'citecue-ai-auto-fix' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Placed on the pages you have approved', 'citecue-ai-auto-fix' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'llms.txt', 'citecue-ai-auto-fix' ); ?></td>
					<td>
						<?php if ( $settings->get( 'llms_txt_enabled' ) ) : ?>
							<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'Off', 'citecue-ai-auto-fix' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Content pushes', 'citecue-ai-auto-fix' ); ?></td>
					<td><?php echo $settings->get( 'ingest_enabled' ) ? esc_html__( 'Accepted (as drafts, unless raised below)', 'citecue-ai-auto-fix' ) : esc_html__( 'Not accepted', 'citecue-ai-auto-fix' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Last check', 'citecue-ai-auto-fix' ); ?></td>
					<td>
						<?php if ( null === $verified ) : ?>
							<em><?php esc_html_e( 'Not checked yet.', 'citecue-ai-auto-fix' ); ?></em>
						<?php elseif ( ! empty( $verified['ok'] ) ) : ?>
							<span style="color:#046b46;">&#10003;</span>
							<?php
							printf(
								/* translators: %s: human time diff. */
								esc_html__( 'Serving CiteCue’s llms.txt (checked %s ago)', 'citecue-ai-auto-fix' ),
								esc_html( human_time_diff( (int) $verified['checked_at'] ) )
							);
							?>
						<?php elseif ( ! empty( $verified['skipped'] ) ) : ?>
							<span style="color:#996800;">&#8212;</span>
							<?php echo esc_html( $verified['message'] ); ?>
						<?php else : ?>
							<span style="color:#b32d2e;">&#10007;</span>
							<?php echo esc_html( $verified['message'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p>
				<?php $this->action_button( 'citecue_verify_install', __( 'Verify installation', 'citecue-ai-auto-fix' ) ); ?>
				<?php $this->action_button( 'citecue_disconnect', __( 'Disconnect', 'citecue-ai-auto-fix' ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The name of an active SEO plugin, or '' when none is recognized.
	 *
	 * Display only. The injector never asks this question — it reads what
	 * `wp_head` actually printed, which is the only way to be right about a
	 * theme or a plugin that is not on this list. Naming the one we do
	 * recognize just turns "only fills gaps" from a claim into something the
	 * administrator can check against their own site.
	 *
	 * @return string
	 */
	private static function detected_seo_plugin() {
		$known = array(
			'WPSEO_VERSION'             => 'Yoast SEO',
			'RANK_MATH_VERSION'         => 'Rank Math',
			'AIOSEO_VERSION'            => 'All in One SEO',
			'SEOPRESS_VERSION'          => 'SEOPress',
			'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
			'SLIM_SEO_VER'              => 'Slim SEO',
		);

		foreach ( $known as $constant => $label ) {
			if ( defined( $constant ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * The API base row, or a read-only note when wp-config.php pins it.
	 *
	 * @param Citecue_Settings $settings Settings.
	 * @return void
	 */
	private function render_api_base_row( Citecue_Settings $settings ) {
		?>
		<tr>
			<th scope="row"><label for="citecue_api_base"><?php esc_html_e( 'API base URL', 'citecue-ai-auto-fix' ); ?></label></th>
			<td>
				<?php if ( $settings->api_base_is_locked() ) : ?>
					<code><?php echo esc_html( $settings->api_base() ); ?></code>
					<p class="description"><?php esc_html_e( 'Pinned by the CITECUE_API_BASE constant in wp-config.php.', 'citecue-ai-auto-fix' ); ?></p>
				<?php else : ?>
					<input type="url" id="citecue_api_base" name="<?php echo esc_attr( Citecue_Settings::OPTION ); ?>[api_base]" value="<?php echo esc_attr( $settings->api_base() ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Only change this for a self-hosted CiteCue deployment.', 'citecue-ai-auto-fix' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Emits hidden inputs carrying the current value of boolean settings this
	 * form does not render.
	 *
	 * A missing checkbox reads as "off" in sanitize() — correct for the form
	 * that owns the checkbox, destructive for one that does not. Any partial
	 * form posting the option must therefore say what it is not changing.
	 *
	 * @param array $keys Setting keys to carry through untouched.
	 * @return void
	 */
	private function preserve_toggles( array $keys ) {
		foreach ( $keys as $key ) {
			if ( ! $this->plugin->settings->get( $key ) ) {
				continue;
			}
			printf(
				'<input type="hidden" name="%s[%s]" value="1" />',
				esc_attr( Citecue_Settings::OPTION ),
				esc_attr( $key )
			);
		}
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
			echo '<p>' . esc_html__( 'No AI crawler visits recorded yet.', 'citecue-ai-auto-fix' ) . '</p>';
			return;
		}

		$outcome_labels = array(
			'served'       => __( 'Served optimized', 'citecue-ai-auto-fix' ),
			'served-stale' => __( 'Served (stale cache)', 'citecue-ai-auto-fix' ),
			'passthrough'  => __( 'Passed through', 'citecue-ai-auto-fix' ),
			'error'        => __( 'API error — passed through', 'citecue-ai-auto-fix' ),
		);
		?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'citecue-ai-auto-fix' ); ?></th>
					<th><?php esc_html_e( 'Crawler', 'citecue-ai-auto-fix' ); ?></th>
					<th><?php esc_html_e( 'Path', 'citecue-ai-auto-fix' ); ?></th>
					<th><?php esc_html_e( 'Outcome', 'citecue-ai-auto-fix' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td>
							<?php
							printf(
								/* translators: %s: human time diff. */
								esc_html__( '%s ago', 'citecue-ai-auto-fix' ),
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
