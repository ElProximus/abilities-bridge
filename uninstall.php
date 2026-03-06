<?php
/**
 * Uninstall script.
 *
 * @package Abilities_Bridge
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Validate table name against allowlist.
 *
 * Security: Ensures only plugin tables can be dropped during uninstall.
 * Table names are checked against a whitelist to prevent accidental data loss.
 *
 * @param string $table_name Full table name including prefix.
 * @return string|false Validated table name or false if invalid.
 */
function abilities_bridge_validate_table_name( $table_name ) {
	global $wpdb;

	// Only allow alphanumeric characters and underscores.
	if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
		return false;
	}

	// Allowlist of plugin tables.
	$allowed_tables = array(
		'abilities_bridge_conversations',
		'abilities_bridge_messages',
		'abilities_bridge_logs',
		'abilities_bridge_ability_permissions',
		'abilities_bridge_oauth_clients',
		'abilities_bridge_oauth_authorization_codes',
		'abilities_bridge_oauth_access_tokens',
		'abilities_bridge_activity_log',
		'abilities_bridge_memories',
	);

	// Remove prefix and check against allowlist.
	$base_name = str_replace( $wpdb->prefix, '', $table_name );

	return in_array( $base_name, $allowed_tables, true ) ? $table_name : false;
}

// Delete plugin options.
delete_option( 'abilities_bridge_api_key' );
delete_option( 'abilities_bridge_openai_api_key' );
delete_option( 'abilities_bridge_ai_provider' );
delete_option( 'abilities_bridge_system_prompt' );
delete_option( 'abilities_bridge_cache_stats' );
delete_option( 'abilities_bridge_enable_memory' );
delete_option( 'abilities_bridge_memory_consent' );
delete_option( 'abilities_bridge_db_version' );
delete_option( 'abilities_bridge_needs_ability_registration' );

// Delete OAuth options.
delete_option( 'abilities_bridge_mcp_oauth' );
delete_option( 'abilities_bridge_oauth_rate_limits' );
delete_option( 'abilities_bridge_oauth_lockouts' );
delete_option( 'abilities_bridge_oauth_codes' );

// Delete tool enable/disable options.
delete_option( 'abilities_bridge_enable_memory' );
delete_option( 'abilities_bridge_enable_abilities_api' );

// Delete user meta for model preferences.
delete_metadata( 'user', 0, 'abilities_bridge_selected_model', '', true );
delete_metadata( 'user', 0, 'abilities_bridge_selected_model_anthropic', '', true );
delete_metadata( 'user', 0, 'abilities_bridge_selected_model_openai', '', true );
delete_metadata( 'user', 0, 'abilities_bridge_selected_provider', '', true );

// Drop database tables with validation.
$abilities_bridge_tables = array(
	'abilities_bridge_conversations',
	'abilities_bridge_messages',
	'abilities_bridge_logs',
	'abilities_bridge_ability_permissions',
	'abilities_bridge_oauth_clients',
	'abilities_bridge_oauth_authorization_codes',
	'abilities_bridge_oauth_access_tokens',
	'abilities_bridge_activity_log',
	'abilities_bridge_memories',
);

foreach ( $abilities_bridge_tables as $abilities_bridge_table_base ) {
	$abilities_bridge_table_full      = $wpdb->prefix . $abilities_bridge_table_base;
	$abilities_bridge_table_validated = abilities_bridge_validate_table_name( $abilities_bridge_table_full );

	if ( $abilities_bridge_table_validated ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $abilities_bridge_table_validated ) );
	}
}

// Delete known transients using WordPress API.
delete_transient( 'abilities_bridge_activation_redirect' );
delete_transient( 'abilities_bridge_new_credentials' );

// Unschedule any cron jobs.
wp_clear_scheduled_hook( 'abilities_bridge_oauth_cleanup' );
wp_clear_scheduled_hook( 'abilities_bridge_log_cleanup' );
