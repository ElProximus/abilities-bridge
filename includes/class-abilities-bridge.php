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

	const DB_RETRY_AFTER_OPTION = 'abilities_bridge_db_upgrade_retry_after';
	const DB_ERROR_OPTION       = 'abilities_bridge_db_upgrade_error';

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
			require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-integrations-page.php';
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
		$this->maybe_upgrade_database();

		// Initialize MCP components.
		$mcp_rest_api = new Abilities_Bridge_MCP_REST_API();
		$mcp_rest_api->init();

		$mcp_oauth = new Abilities_Bridge_MCP_OAuth();
		$mcp_oauth->init();

		$chat_runner = new Abilities_Bridge_Chat_Job_Runner();
		$chat_runner->register();

		// Initialize admin pages.
		if ( is_admin() ) {
			$admin_page = new Abilities_Bridge_Admin_Page();
			$admin_page->init();

			$settings_page = new Abilities_Bridge_Settings_Page();
			$settings_page->init();

			Abilities_Bridge_Activity_Log_Page::init();

			Abilities_Bridge_Integrations_Page::init();

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
	 * Run schema DDL only on a safe admin/CLI request with an hourly backoff.
	 *
	 * @return void
	 */
	private function maybe_upgrade_database() {
		$db_version = get_option( 'abilities_bridge_db_version', '0' );
		if ( ! version_compare( $db_version, ABILITIES_BRIDGE_DB_VERSION, '<' ) ) {
			return;
		}

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_database_upgrade_notice' ) );
		}

		$is_cli             = defined( 'WP_CLI' ) && WP_CLI;
		$safe_admin_request = is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
		$retry_after        = (int) get_option( self::DB_RETRY_AFTER_OPTION, 0 );
		if ( ( ! $safe_admin_request && ! $is_cli ) || $retry_after > time() ) {
			return;
		}

		// Publish the next eligible attempt before DDL starts so overlapping
		// requests and a failed ALTER cannot create a per-request migration loop.
		update_option( self::DB_RETRY_AFTER_OPTION, time() + HOUR_IN_SECONDS, false );
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-database.php';
		if ( Abilities_Bridge_Database::create_tables() ) {
			update_option( 'abilities_bridge_db_version', ABILITIES_BRIDGE_DB_VERSION );
			delete_option( self::DB_RETRY_AFTER_OPTION );
			delete_option( self::DB_ERROR_OPTION );
			return;
		}

		update_option( self::DB_ERROR_OPTION, time(), false );
		error_log( 'Abilities Bridge database upgrade did not complete; the next attempt is delayed for one hour.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Surface a failed/pending schema upgrade without repeating DDL.
	 *
	 * @return void
	 */
	public function render_database_upgrade_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! version_compare( get_option( 'abilities_bridge_db_version', '0' ), ABILITIES_BRIDGE_DB_VERSION, '<' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Abilities Bridge could not finish its database upgrade. Background chat is unavailable until the database account can create and alter the plugin tables. The plugin will retry no more than once per hour.', 'abilities-bridge' ); ?></p>
		</div>
		<?php
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
