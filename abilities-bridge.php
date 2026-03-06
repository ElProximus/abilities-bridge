<?php
/**
 * Plugin Name: Abilities Bridge
 * Plugin URI: https://aisystemadmin.com/abilities-bridge/
 * Description: MCP server for WordPress with admin interface. Connect Claude AI or OpenAI to execute WordPress Abilities with configurable permissions, activity monitoring, memory storage, and OAuth 2.0 authentication.
 * Version: 1.1.0
 * Author: Joe Campbell
 * Author URI: https://aisystemadmin.com.
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html.
 * Text Domain: abilities-bridge
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'ABILITIES_BRIDGE_VERSION', '1.1.0' );
define( 'ABILITIES_BRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABILITIES_BRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ABILITIES_BRIDGE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ABILITIES_BRIDGE_CONTENT_DIR', wp_upload_dir()['basedir'] . '/abilities-bridge/' );

// Autoload classes.
spl_autoload_register(
	function ( $class_name ) {
		$prefix   = 'Abilities_Bridge_';
		$base_dir = ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		// WPCS naming: class-abilities-bridge-{rest-of-class-name}.php.
		$file = $base_dir . 'class-abilities-bridge-' . str_replace( '_', '-', strtolower( $relative_class ) ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Plugin activation hook.
 *
 * @return void
 */
function abilities_bridge_activate() {
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-database.php';
	Abilities_Bridge_Database::create_tables();
	Abilities_Bridge_Database::create_website_md();

	// Load memory functions class (database-based storage).
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-memory-functions.php';

	// Register default read-only abilities.
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-ability-permissions.php';
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-integration.php';

	// Register abilities on next request (after activation).
	update_option( 'abilities_bridge_needs_ability_registration', true );

	// Set activation redirect transient (30 second expiry).
	set_transient( 'abilities_bridge_activation_redirect', true, 30 );

	// Initialize OAuth handler to register rewrite rules.
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-oauth-scopes.php';
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-oauth.php';
	$oauth = new Abilities_Bridge_MCP_OAuth();
	$oauth->add_authorize_rewrite();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'abilities_bridge_activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function abilities_bridge_deactivate() {
	// Unschedule cleanup cron job.
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-log-cleanup.php';
	Abilities_Bridge_Log_Cleanup::unschedule();

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'abilities_bridge_deactivate' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function abilities_bridge_init() {
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge.php';
	Abilities_Bridge::get_instance();

	// Initialize log cleanup cron job.
	require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-log-cleanup.php';

	// Schedule OAuth cleanup (runs daily).
	if ( ! wp_next_scheduled( 'abilities_bridge_oauth_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'abilities_bridge_oauth_cleanup' );
	}

	// Hook OAuth cleanup tasks.
	add_action( 'abilities_bridge_oauth_cleanup', 'abilities_bridge_run_oauth_cleanup' );

	// Initialize MCP integration if MCP Adapter is available.
	if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-integration.php';
	}

	// Initialize admin interface.
	if ( is_admin() ) {
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-welcome-wizard.php';
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-ability-permissions-admin.php';
		require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-activity-log-page.php';
	}

	// Register default abilities if flag is set.
	if ( get_option( 'abilities_bridge_needs_ability_registration' ) ) {
		add_action(
			'admin_init',
			function () {
				if ( current_user_can( 'manage_options' ) ) {
					require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-integration.php';
					Abilities_Bridge_MCP_Integration::register_default_abilities();
					delete_option( 'abilities_bridge_needs_ability_registration' );
				}
			}
		);
	}
}
add_action( 'plugins_loaded', 'abilities_bridge_init' );

/**
 * Run OAuth cleanup tasks.
 *
 * @return void
 */
function abilities_bridge_run_oauth_cleanup() {
	// Cleanup expired authorization codes.
	$code_manager = new Abilities_Bridge_OAuth_Authorization_Code();
	$code_manager->cleanup_expired_codes();

	// Cleanup rate limit data.
	$rate_limiter = new Abilities_Bridge_OAuth_Rate_Limiter();
	$rate_limiter->cleanup_expired_data();

	// Cleanup old OAuth logs.
	$logger = new Abilities_Bridge_OAuth_Logger();
	$logger->cleanup_old_logs();
}
