<?php
/**
 * OAuth Token Validator
 * Handles OAuth token validation and permission checks
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Token Validator class.
 *
 * Validates OAuth access tokens.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_OAuth_Token_Validator {

	/**
	 * OAuth option name
	 */
	const OPTION_NAME = 'abilities_bridge_mcp_oauth';

	/**
	 * Validate OAuth token
	 *
	 * Supports both old long-lived tokens and new short-lived access tokens.
	 *
	 * @param string $token OAuth access token.
	 * @return bool|WP_Error True if valid, error otherwise
	 */
	public static function validate_oauth_token( $token ) {
		// Get stored OAuth tokens.
		$oauth_data = get_option( self::OPTION_NAME, array() );

		// First, check new short-lived access tokens (OAuth 2.0).
		if ( ! empty( $oauth_data['access_tokens'] ) ) {
			foreach ( $oauth_data['access_tokens'] as $stored_token ) {
				if ( ! isset( $stored_token['access_token'] ) ) {
					continue;
				}

				// Decrypt stored token before comparing.
				$decrypted_token = Abilities_Bridge_Token_Encryption::decrypt( $stored_token['access_token'] );

				// Skip if decryption failed.
				if ( is_wp_error( $decrypted_token ) ) {
					continue;
				}

				// Compare tokens (use hash_equals for timing-safe comparison).
				if ( hash_equals( $decrypted_token, $token ) ) {
					// Check expiration.
					if ( isset( $stored_token['expires_at'] ) && time() > $stored_token['expires_at'] ) {
						return new WP_Error(
							'token_expired',
							__( 'Access token has expired. Use refresh_token to get a new one.', 'abilities-bridge' ),
							array( 'status' => 401 )
						);
					}

					// BACKWARD COMPATIBLE: Validate scope if present, otherwise grant default scope.
					$scope = '';
					if ( ! empty( $stored_token['scope'] ) ) {
						// Validate scope format if provided.
						if ( ! Abilities_Bridge_OAuth_Scopes::is_valid_scope( $stored_token['scope'] ) ) {
							return new WP_Error(
								'invalid_token',
								__( 'Token has invalid scope. Please re-authorize to generate a new token.', 'abilities-bridge' ),
								array( 'status' => 401 )
							);
						}
						$scope = $stored_token['scope'];
					} else {
						// For backward compatibility: tokens without scope get default scope.
						$scope = Abilities_Bridge_OAuth_Scopes::get_default_scope();
					}

					// Store OAuth context in globals (DO NOT set current user globally).
					// This prevents OAuth tokens from gaining full WordPress user privileges.
					if ( isset( $stored_token['user_id'] ) ) {
						$GLOBALS['abilities_bridge_oauth_user_id'] = $stored_token['user_id'];
					}
					$GLOBALS['abilities_bridge_oauth_scope']     = $scope;
					$GLOBALS['abilities_bridge_oauth_client_id'] = $stored_token['client_id'] ?? '';

					return true;
				}
			}
		}

		// REMOVED: No longer support old long-lived tokens without proper scope.
		// Old tokens are invalidated - users must re-authorize.

		// No tokens configured or token not found.
		if ( empty( $oauth_data['access_tokens'] ) ) {
			return new WP_Error(
				'invalid_token',
				__( 'No OAuth tokens configured.', 'abilities-bridge' ),
				array( 'status' => 401 )
			);
		}

		return new WP_Error(
			'invalid_token',
			__( 'Invalid OAuth token.', 'abilities-bridge' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Check MCP permission (supports both OAuth and Application Password)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, error otherwise
	 */
	public static function check_permission( $request ) {
		// Try OAuth Bearer token first.
		$auth_header = $request->get_header( 'authorization' );

		if ( $auth_header && strpos( $auth_header, 'Bearer ' ) === 0 ) {
			$token  = substr( $auth_header, 7 );
			$result = self::validate_oauth_token( $token );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// OAuth token validated - check if the associated user has required capabilities.
			if ( isset( $GLOBALS['abilities_bridge_oauth_user_id'] ) ) {
				$user = get_userdata( $GLOBALS['abilities_bridge_oauth_user_id'] );

				if ( ! $user ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'OAuth token references invalid user.', 'abilities-bridge' ),
						array( 'status' => 403 )
					);
				}

				// Check if user has manage_options capability (admin).
				if ( ! user_can( $user, 'manage_options' ) ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'Your account does not have sufficient permissions.', 'abilities-bridge' ),
						array( 'status' => 403 )
					);
				}

				return true;
			}

			return new WP_Error(
				'rest_forbidden',
				__( 'OAuth token validation failed.', 'abilities-bridge' ),
				array( 'status' => 403 )
			);
		}

		// Fall back to WordPress Application Password authentication.
		// This is handled automatically by WordPress REST API.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Authentication required. Use OAuth Bearer token or WordPress Application Password.', 'abilities-bridge' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Restrict OAuth tokens to plugin endpoints only
	 *
	 * This prevents OAuth tokens from accessing WordPress core REST API endpoints
	 * like /wp/v2/posts, /wp/v2/users, etc. OAuth tokens should only be able to
	 * access abilities-bridge plugin endpoints for security.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public static function restrict_oauth_to_plugin_endpoints( $result, $server, $request ) {
		// Check if this request is using OAuth authentication.
		// OAuth sets these globals during validate_oauth_token().
		if ( ! isset( $GLOBALS['abilities_bridge_oauth_scope'] ) ) {
			return $result; // Not OAuth - allow normal WordPress authentication.
		}

		// Get the requested route.
		$route = $request->get_route();

		// Whitelist: Only allow plugin namespaces for OAuth tokens.
		$allowed_namespaces = array(
			'/abilities-bridge-mcp/v1',  // Main plugin namespace.
		);

		foreach ( $allowed_namespaces as $namespace ) {
			if ( strpos( $route, $namespace ) === 0 ) {
				return $result; // Allowed - this is a plugin endpoint.
			}
		}

		// Block all other endpoints for OAuth tokens.
		return new WP_Error(
			'rest_forbidden',
			sprintf(
				/* translators: %s: requested route */
				__( 'OAuth tokens can only access Abilities Bridge endpoints. Attempted access to: %s', 'abilities-bridge' ),
				$route
			),
			array( 'status' => 403 )
		);
	}
}
