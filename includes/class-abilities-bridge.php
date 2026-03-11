<?php
/**
 * Main plugin class.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class using singleton pattern.
 */
class Abilities_Bridge {

	/**
	 * Single instance of the class.
	 *
	 * @var Abilities_Bridge
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Abilities_Bridge
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required dependencies.
	 */
	private function load_dependencies() {
		// Core classes are autoloaded via spl_autoload_register in main plugin file.

		// Ensure critical classes are loaded.
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-memory-functions.php';

		// Load MCP classes.
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-rest-api.php';
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-server.php';
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-oauth-scopes.php';
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-oauth.php';

		// Load admin classes.
		if ( is_admin() ) {
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-admin-page.php';
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-settings-page.php';
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-activity-log-page.php';
			if ( defined( 'ABILITIES_BRIDGE_DEV' ) && ABILITIES_BRIDGE_DEV ) {
				require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-chatgpt-mcp-test-page.php';
				require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-wp-ai-client-test-page.php';
			}
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Only run database upgrades in admin context, not during API requests.
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-database.php';

			// Check if upgrade needed (version-based).
			$db_version = get_option( 'abilities_bridge_db_version', '0' );
			if ( version_compare( $db_version, ABILITIES_BRIDGE_VERSION, '<' ) ) {
				Abilities_Bridge_Database::upgrade_database();
				update_option( 'abilities_bridge_db_version', ABILITIES_BRIDGE_VERSION );
			}
		}

		// Initialize MCP components.
		$mcp_rest_api = new Abilities_Bridge_MCP_REST_API();
		$mcp_rest_api->init();

		$mcp_oauth = new Abilities_Bridge_MCP_OAuth();
		$mcp_oauth->init();

		// Initialize admin pages.
		if ( is_admin() ) {
			$admin_page = new Abilities_Bridge_Admin_Page();
			$admin_page->init();

			$settings_page = new Abilities_Bridge_Settings_Page();
			$settings_page->init();

			Abilities_Bridge_Activity_Log_Page::init();

			if ( defined( 'ABILITIES_BRIDGE_DEV' ) && ABILITIES_BRIDGE_DEV ) {
				$chatgpt_mcp_test_page = new Abilities_Bridge_ChatGPT_MCP_Test_Page();
				$chatgpt_mcp_test_page->init();

				$wp_ai_client_test_page = new Abilities_Bridge_WP_AI_Client_Test_Page();
				$wp_ai_client_test_page->init();
			}
		}

		if ( rest_sanitize_boolean( get_option( 'abilities_bridge_enable_chat_bubble', false ) ) ) {
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-admin-bubble.php';

			$bubble = new Abilities_Bridge_Admin_Bubble();
			$bubble->init();
		}

		// Add settings link to plugins page.
		add_filter( 'plugin_action_links_' . ABILITIES_BRIDGE_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Add action links to plugin list.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=abilities-bridge' ) . '">' . __( 'Dashboard', 'abilities-bridge' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=abilities-bridge-settings' ) . '">' . __( 'Settings', 'abilities-bridge' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}
}



