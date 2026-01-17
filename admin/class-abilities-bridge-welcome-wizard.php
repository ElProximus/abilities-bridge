<?php
/**
 * Welcome Wizard and Consent Management
 *
 * Handles first-time setup, consent screens, and re-consent for updates
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Welcome Wizard class.
 *
 * Handles first-time setup, consent screens, and re-consent for updates.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Welcome_Wizard {

	/**
	 * Initialize welcome wizard
	 */
	public static function init() {
		// Check for activation redirect.
		add_action( 'admin_init', array( __CLASS__, 'check_activation_redirect' ) );

		// Check for version-based re-consent.
		add_action( 'admin_init', array( __CLASS__, 'check_consent_version' ) );

		// Register admin page.
		add_action( 'admin_menu', array( __CLASS__, 'register_wizard_page' ) );

		// Handle consent submission.
		add_action( 'admin_post_abilities_bridge_submit_consent', array( __CLASS__, 'handle_consent_submission' ) );

		// Enqueue assets.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue CSS and JavaScript for welcome wizard
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on welcome wizard page.
		if ( 'admin_page_abilities-bridge-welcome' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'abilities-bridge-settings-page',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/settings-page.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-settings-page',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/settings-page.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-settings-page',
			'abilitiesBridgeSettings',
			array(
				'i18n' => array(
					'copied'     => __( 'Copied!', 'abilities-bridge' ),
					'copyFailed' => __( 'Failed to copy to clipboard', 'abilities-bridge' ),
				),
			)
		);
	}

	/**
	 * Verify the page navigation nonce.
	 *
	 * Returns true if nonce is valid or not present (first page load).
	 * Dies with error if nonce is present but invalid.
	 *
	 * @return bool True if nonce is valid, false if no nonce present.
	 */
	private static function verify_page_nonce() {
		$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// No nonce present = first page load or redirect, allow with defaults.
		if ( empty( $nonce ) ) {
			return false;
		}

		// Nonce present - verify it.
		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_welcome_nav' ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Please use the navigation links provided.', 'abilities-bridge' ),
				esc_html__( 'Security Check Failed', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get the current admin page parameter for redirect checks.
	 *
	 * This is used internally for redirect logic only - it does not require
	 * nonce verification because it only checks the current page name
	 * (a WordPress-controlled parameter) for routing purposes.
	 *
	 * @return string The current page parameter value, or empty string.
	 */
	private static function get_current_page() {
		$value = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ! empty( $value ) ? sanitize_key( $value ) : '';
	}

	/**
	 * Get a sanitized GET parameter for display/routing purposes.
	 *
	 * Verifies nonce before accessing parameters. If no nonce is present
	 * (first page load), returns the default value.
	 *
	 * @param string $key     Parameter key.
	 * @param string $default Default value.
	 * @return string Sanitized value.
	 */
	private static function get_url_param( $key, $default = '' ) {
		// Verify nonce - returns false if no nonce (first load).
		if ( ! self::verify_page_nonce() ) {
			return $default;
		}

		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ! empty( $value ) ? sanitize_key( $value ) : $default;
	}

	/**
	 * Check for activation redirect transient
	 */
	public static function check_activation_redirect() {
		// Skip if setup already completed.
		if ( self::is_setup_complete() ) {
			// Delete transient only after setup is complete.
			if ( get_transient( 'abilities_bridge_activation_redirect' ) !== false ) {
				delete_transient( 'abilities_bridge_activation_redirect' );
			}
			return;
		}

		// Skip if doing AJAX or cron.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Skip if already on wizard page - use get_current_page() for redirect checks
		// because current screen is not yet initialized on admin_init for hidden admin pages.
		$page = self::get_current_page();
		if ( 'abilities-bridge-welcome' === $page ) {
			return;
		}

		// Check for first-time activation redirect (transient exists).
		$activation_redirect = get_transient( 'abilities_bridge_activation_redirect' );
		if ( false !== $activation_redirect ) {
			// Delete transient (redirect only once on first admin page load).
			delete_transient( 'abilities_bridge_activation_redirect' );

			// Redirect to welcome wizard on first load after activation.
			wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-welcome' ) );
			exit;
		}

		// Only redirect if trying to access Abilities Bridge pages.
		// This prevents hijacking the entire WordPress admin.
		$is_ai_admin_page = strpos( $page, 'abilities-bridge' ) === 0;

		if ( ! $is_ai_admin_page ) {
			// Not on an Abilities Bridge page, allow access.
			return;
		}

		// User is trying to access Abilities Bridge pages without completing setup.
		// Redirect to welcome wizard.
		wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-welcome' ) );
		exit;
	}

	/**
	 * Check if plugin version requires re-consent
	 */
	public static function check_consent_version() {
		// Skip if not setup yet.
		if ( ! self::is_setup_complete() ) {
			return;
		}

		$current_version = ABILITIES_BRIDGE_VERSION;
		$consent_version = get_option( 'abilities_bridge_consent_version', '0' );

		// Define versions that require re-consent (major updates).
		$versions_requiring_reconsent = array( '2.0.0', '3.0.0' );

		// Check if current version needs re-consent.
		if ( in_array( $current_version, $versions_requiring_reconsent, true ) &&
			version_compare( $consent_version, $current_version, '<' ) ) {

			// Disable functionality until re-consented.
			update_option( 'abilities_bridge_functionality_disabled', true );

			// Show admin notice.
			add_action( 'admin_notices', array( __CLASS__, 'show_reconsent_notice' ) );
		}
	}

	/**
	 * Show re-consent notice
	 */
	public static function show_reconsent_notice() {
		$reconsent_url = wp_nonce_url(
			admin_url( 'admin.php?page=abilities-bridge-welcome&reconsent=1' ),
			'abilities_bridge_welcome_nav'
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Abilities Bridge:', 'abilities-bridge' ); ?></strong>
				<?php esc_html_e( 'This plugin has been updated and requires you to review and consent to updated terms.', 'abilities-bridge' ); ?>
				<a href="<?php echo esc_url( $reconsent_url ); ?>" class="button button-primary" style="margin-left: 10px;">
					<?php esc_html_e( 'Review Changes & Update Consent', 'abilities-bridge' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Register wizard page
	 */
	public static function register_wizard_page() {
		add_submenu_page(
			null, // Hidden from menu.
			__( 'Abilities Bridge Setup', 'abilities-bridge' ),
			__( 'Setup', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-welcome',
			array( __CLASS__, 'render_wizard_page' )
		);
	}

	/**
	 * Render wizard page
	 */
	public static function render_wizard_page() {
		$is_reconsent = '1' === self::get_url_param( 'reconsent' );
		$changelog    = $is_reconsent ? self::get_version_changelog( ABILITIES_BRIDGE_VERSION ) : '';

		// Suppress third-party admin notices to keep consent form focused and professional.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );

		include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/partials/welcome-wizard.php';
	}

	/**
	 * Get changelog for a specific version
	 *
	 * @param string $version Version number.
	 * @return string Changelog HTML.
	 */
	public static function get_version_changelog( $version ) {
		$changelogs = array(
			'2.0.0' => array(
				'new'      => array(
					'New built-in tool: execute_wp_cli - Execute WP-CLI commands (read-only)',
					'New ability: ability_core/update-plugin - Update WordPress plugins',
					'Enhanced logging: IP addresses now included in activity logs',
				),
				'changes'  => array(
					'Memory tool now supports up to 100MB total storage (was 50MB)',
					'Database queries now limited to 30 second timeout',
					'MCP integration now requires OAuth 2.0 authentication',
				),
				'security' => array(
					'Added rate limiting: 100 requests per hour default',
					'New permission gate: requires_two_factor_auth for sensitive abilities',
				),
			),
		);

		if ( ! isset( $changelogs[ $version ] ) ) {
			return '';
		}

		$changelog = $changelogs[ $version ];
		$html      = '';

		if ( ! empty( $changelog['new'] ) ) {
			$html .= '<h4>' . esc_html__( 'NEW CAPABILITIES:', 'abilities-bridge' ) . '</h4><ul>';
			foreach ( $changelog['new'] as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $changelog['changes'] ) ) {
			$html .= '<h4>' . esc_html__( 'CHANGES:', 'abilities-bridge' ) . '</h4><ul>';
			foreach ( $changelog['changes'] as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		if ( ! empty( $changelog['security'] ) ) {
			$html .= '<h4>' . esc_html__( 'SECURITY UPDATES:', 'abilities-bridge' ) . '</h4><ul>';
			foreach ( $changelog['security'] as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Handle consent form submission
	 */
	public static function handle_consent_submission() {
		// Verify nonce.
		if ( ! isset( $_POST['abilities_bridge_consent_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['abilities_bridge_consent_nonce'] ) ), 'abilities_bridge_consent' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'abilities-bridge' ) );
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'abilities-bridge' ) );
		}

		// Verify all checkboxes checked.
		$consent_permissions   = isset( $_POST['consent_permissions'] ) && sanitize_text_field( wp_unslash( $_POST['consent_permissions'] ) ) === '1';
		$consent_billing       = isset( $_POST['consent_billing'] ) && sanitize_text_field( wp_unslash( $_POST['consent_billing'] ) ) === '1';
		$consent_understanding = isset( $_POST['consent_understanding'] ) && sanitize_text_field( wp_unslash( $_POST['consent_understanding'] ) ) === '1';

		if ( ! $consent_permissions || ! $consent_billing || ! $consent_understanding ) {
			wp_die( esc_html__( 'You must check all consent boxes to continue.', 'abilities-bridge' ) );
		}

		// Store consent.
		update_option( 'abilities_bridge_consent_given', true );
		update_option( 'abilities_bridge_consent_version', ABILITIES_BRIDGE_VERSION );
		update_option( 'abilities_bridge_consent_date', current_time( 'mysql' ) );
		update_option( 'abilities_bridge_consent_user_id', get_current_user_id() );
		update_option( 'abilities_bridge_setup_completed', true );

		// Log consent event for audit trail.
		$current_user      = wp_get_current_user();
		$is_reconsent      = '1' === self::get_url_param( 'reconsent' );
		$remote_addr       = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		$consent_log_entry = array(
			'timestamp'     => current_time( 'mysql' ),
			'user_id'       => get_current_user_id(),
			'user_email'    => $current_user->user_email,
			'ip_address'    => $remote_addr ? sanitize_text_field( $remote_addr ) : '',
			'event'         => $is_reconsent ? 'reconsent_v' . ABILITIES_BRIDGE_VERSION : 'initial_consent',
			'version'       => ABILITIES_BRIDGE_VERSION,
			'consent_items' => array(
				'permissions'   => $consent_permissions,
				'billing'       => $consent_billing,
				'understanding' => $consent_understanding,
			),
		);

		// Append to consent log (maintain audit trail).
		$consent_log = get_option( 'abilities_bridge_consent_log', array() );
		if ( ! is_array( $consent_log ) ) {
			$consent_log = array();
		}
		$consent_log[] = $consent_log_entry;
		update_option( 'abilities_bridge_consent_log', $consent_log );

		// Re-enable functionality if it was disabled.
		delete_option( 'abilities_bridge_functionality_disabled' );

		// Register default abilities.
		Abilities_Bridge_MCP_Integration::register_default_abilities();

		// Redirect to settings page.
		wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-settings&setup=complete' ) );
		exit;
	}

	/**
	 * Check if setup is complete
	 *
	 * @return bool True if setup complete.
	 */
	public static function is_setup_complete() {
		return get_option( 'abilities_bridge_setup_completed', false ) &&
			get_option( 'abilities_bridge_consent_given', false );
	}

	/**
	 * Check if functionality is currently disabled
	 *
	 * @return bool True if disabled.
	 */
	public static function is_functionality_disabled() {
		return (bool) get_option( 'abilities_bridge_functionality_disabled', false );
	}
}

// Initialize.
Abilities_Bridge_Welcome_Wizard::init();
