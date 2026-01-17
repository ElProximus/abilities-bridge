<?php
/**
 * OAuth Scopes Definition and Validation.
 *
 * Defines OAuth 2.0 scopes for the Abilities Bridge MCP plugin and provides
 * utilities for scope validation and tool access control.
 *
 * @package Abilities_Bridge
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Scopes class for managing scope definitions and validation.
 */
class Abilities_Bridge_OAuth_Scopes {

	/**
	 * Scope constants.
	 */
	const SCOPE_MEMORY    = 'memory';
	const SCOPE_ABILITIES = 'abilities';
	const SCOPE_ADMIN     = 'admin';
	const SCOPE_CLAUDEAI  = 'claudeai';

	/**
	 * Get all scope definitions with descriptions and tool mappings.
	 *
	 * @return array Scope definitions.
	 */
	public static function get_scope_definitions() {
		return array(
			self::SCOPE_MEMORY    => array(
				'name'        => __( 'Memory Access', 'abilities-bridge' ),
				'description' => __( 'Read and write persistent memories', 'abilities-bridge' ),
				'tools'       => array( 'memory' ),
			),
			self::SCOPE_ABILITIES => array(
				'name'        => __( 'Abilities Access', 'abilities-bridge' ),
				'description' => __( 'Execute approved WordPress abilities only', 'abilities-bridge' ),
				'tools'       => array( 'ability_*' ), // Wildcard for all ability_* tools.
			),
			self::SCOPE_ADMIN     => array(
				'name'        => __( 'Admin Access', 'abilities-bridge' ),
				'description' => __( 'Full administrative access to all tools', 'abilities-bridge' ),
				'tools'       => array( '*' ), // Wildcard = all tools.
			),
			self::SCOPE_CLAUDEAI  => array(
				'name'        => __( 'Claude AI Access', 'abilities-bridge' ),
				'description' => __( 'Full MCP access for Claude Desktop integration', 'abilities-bridge' ),
				'tools'       => array( '*' ), // Grant all tools for Claude Desktop.
			),
		);
	}

	/**
	 * Get default scope for MCP Claude Desktop integration.
	 *
	 * @return string Default scope string.
	 */
	public static function get_default_scope() {
		// For Claude Desktop MCP, grant memory + abilities by default.
		return implode(
			' ',
			array(
				self::SCOPE_MEMORY,
				self::SCOPE_ABILITIES,
			)
		);
	}

	/**
	 * Validate scope string format.
	 *
	 * @param string $scope_string Space-separated scope string.
	 * @return bool True if valid.
	 */
	public static function is_valid_scope( $scope_string ) {
		if ( empty( $scope_string ) || ! is_string( $scope_string ) ) {
			return false;
		}

		$requested_scopes = explode( ' ', $scope_string );
		$valid_scopes     = array_keys( self::get_scope_definitions() );

		foreach ( $requested_scopes as $scope ) {
			if ( ! in_array( $scope, $valid_scopes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if granted scopes allow access to a specific tool.
	 *
	 * @param string $granted_scopes Space-separated granted scopes.
	 * @param string $tool_name      Tool name to check.
	 * @return bool True if access granted.
	 */
	public static function can_access_tool( $granted_scopes, $tool_name ) {
		if ( empty( $granted_scopes ) || empty( $tool_name ) ) {
			return false;
		}

		$granted_scope_array = explode( ' ', $granted_scopes );
		$definitions         = self::get_scope_definitions();

		foreach ( $granted_scope_array as $scope ) {
			if ( ! isset( $definitions[ $scope ] ) ) {
				continue;
			}

			$scope_def = $definitions[ $scope ];

			// Check for wildcard (admin scope grants all).
			if ( in_array( '*', $scope_def['tools'], true ) ) {
				return true;
			}

			// Check if tool is in this scope's tool list.
			if ( in_array( $tool_name, $scope_def['tools'], true ) ) {
				return true;
			}

			// Check for pattern wildcards (e.g., 'ability_*' matches 'ability_core_create_post').
			foreach ( $scope_def['tools'] as $tool_pattern ) {
				if ( strpos( $tool_pattern, '*' ) !== false ) {
					// Convert wildcard pattern to regex.
					$pattern = '/^' . str_replace( '*', '.*', preg_quote( $tool_pattern, '/' ) ) . '$/';
					if ( preg_match( $pattern, $tool_name ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get required scope for a specific tool.
	 *
	 * @param string $tool_name Tool name.
	 * @return string|null Required scope name, or null if tool not found.
	 */
	public static function get_required_scope_for_tool( $tool_name ) {
		$definitions = self::get_scope_definitions();

		foreach ( $definitions as $scope => $def ) {
			if ( in_array( $tool_name, $def['tools'], true ) ) {
				return $scope;
			}
		}

		return null;
	}

	/**
	 * Parse and validate scope from request.
	 *
	 * @param string $requested_scope Requested scope string.
	 * @return array Array of validated scope strings.
	 */
	public static function parse_scope( $requested_scope ) {
		if ( empty( $requested_scope ) ) {
			return array();
		}

		$scopes       = explode( ' ', $requested_scope );
		$valid_scopes = array_keys( self::get_scope_definitions() );
		$result       = array();

		foreach ( $scopes as $scope ) {
			$scope = trim( $scope );
			if ( in_array( $scope, $valid_scopes, true ) ) {
				$result[] = $scope;
			}
		}

		return $result;
	}

	/**
	 * Get human-readable description of granted scopes.
	 *
	 * @param string $granted_scopes Space-separated granted scopes.
	 * @return array Array of scope descriptions.
	 */
	public static function get_scope_descriptions( $granted_scopes ) {
		$scopes       = explode( ' ', $granted_scopes );
		$definitions  = self::get_scope_definitions();
		$descriptions = array();

		foreach ( $scopes as $scope ) {
			if ( isset( $definitions[ $scope ] ) ) {
				$descriptions[] = array(
					'scope'       => $scope,
					'name'        => $definitions[ $scope ]['name'],
					'description' => $definitions[ $scope ]['description'],
				);
			}
		}

		return $descriptions;
	}
}
