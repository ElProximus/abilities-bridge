<?php
/**
 * MCP Adapter Integration
 *
 * Registers Abilities Bridge abilities with Abilities API for MCP exposure.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Integration class.
 *
 * Registers Abilities Bridge abilities with Abilities API for MCP exposure.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_MCP_Integration {

	/**
	 * Initialize MCP integration
	 *
	 * Note: Abilities Bridge provides its own MCP server implementation
	 * via class-mcp-server.php, class-mcp-oauth.php, and class-mcp-rest-api.php.
	 * No external bridge ability is required.
	 */
	public static function init() {
		// Reserved for future MCP integration hooks if needed.
	}

	/**
	 * Register default read-only abilities for AI assistance
	 */
	public static function register_default_abilities() {
		// Only proceed if Permission Manager is loaded.
		if ( ! class_exists( 'Abilities_Bridge_Ability_Permissions' ) ) {
			return;
		}

		$default_abilities = array(
			array(
				'name'                    => 'core/get-site-info',
				'enabled'                 => 1,
				'max_per_day'             => 1000,
				'max_per_hour'            => 100,
				'risk_level'              => 'low',
				'requires_user_approval'  => 0,
				'requires_admin_approval' => 0,
				'min_capability'          => 'read',
				'description'             => 'Get WordPress site information (name, URL, version, etc.)',
				'reason_for_approval'     => 'Read-only operation, safe for site analysis',
			),
			array(
				'name'                    => 'core/get-user-info',
				'enabled'                 => 1,
				'max_per_day'             => 1000,
				'max_per_hour'            => 100,
				'risk_level'              => 'low',
				'requires_user_approval'  => 0,
				'requires_admin_approval' => 0,
				'min_capability'          => 'read',
				'description'             => 'Get current user information (name, role, capabilities)',
				'reason_for_approval'     => 'Read-only operation, helps Claude understand user context',
			),
			array(
				'name'                    => 'core/get-environment-info',
				'enabled'                 => 1,
				'max_per_day'             => 1000,
				'max_per_hour'            => 100,
				'risk_level'              => 'low',
				'requires_user_approval'  => 0,
				'requires_admin_approval' => 0,
				'min_capability'          => 'read',
				'description'             => 'Get server environment information (PHP version, server type, etc.)',
				'reason_for_approval'     => 'Read-only operation, helps with troubleshooting',
			),
		);

		foreach ( $default_abilities as $ability ) {
			// Check if already registered.
			$existing       = Abilities_Bridge_Ability_Permissions::get_all_permissions( false );
			$already_exists = false;

			foreach ( $existing as $existing_ability ) {
				if ( $existing_ability['ability_name'] === $ability['name'] ) {
					$already_exists = true;
					break;
				}
			}

			// Only register if not already registered.
			if ( ! $already_exists ) {
				Abilities_Bridge_Ability_Permissions::register_ability(
					$ability['name'],
					array(
						'enabled'                 => $ability['enabled'],
						'max_per_day'             => $ability['max_per_day'],
						'max_per_hour'            => $ability['max_per_hour'],
						'risk_level'              => $ability['risk_level'],
						'requires_user_approval'  => $ability['requires_user_approval'],
						'requires_admin_approval' => $ability['requires_admin_approval'],
						'min_capability'          => $ability['min_capability'],
						'description'             => $ability['description'],
						'reason_for_approval'     => $ability['reason_for_approval'],
					)
				);
			}
		}
	}
}

// Initialize MCP integration.
Abilities_Bridge_MCP_Integration::init();
