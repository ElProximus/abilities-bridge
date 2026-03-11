<?php
/**
 * OAuth Router
 * Handles WordPress hook registration and REST API routing
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Router class.
 *
 * Handles WordPress hook registration and REST API routing.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_OAuth_Router {

	/**
	 * Initialize OAuth router
	 */
	public static function init() {
		// Register OAuth endpoints if needed.
		add_action( 'rest_api_init', array( __CLASS__, 'register_oauth_routes' ) );

		// Add rewrite rule for /authorize redirect (Claude Desktop compatibility).
		add_action( 'init', array( 'Abilities_Bridge_OAuth_Redirect_Handler', 'add_authorize_rewrite' ) );

		// Handle /authorize template redirect.
		add_action( 'template_redirect', array( 'Abilities_Bridge_OAuth_Redirect_Handler', 'handle_authorize_redirect' ) );

		// Handle /.well-known/ template redirect for discovery endpoints.
		add_action( 'template_redirect', array( 'Abilities_Bridge_OAuth_Redirect_Handler', 'handle_wellknown_redirect' ) );

		// Register OAuth admin page for login redirect support.
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );

		// Enable cookie authentication for our REST API endpoints.
		// This allows form POSTs from wp-admin pages to authenticate via cookies.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_cookie_authentication' ), 100 );

		// Block OAuth tokens from accessing WordPress core endpoints.
		// OAuth tokens should only access plugin endpoints for security.
		add_filter( 'rest_pre_dispatch', array( 'Abilities_Bridge_OAuth_Token_Validator', 'restrict_oauth_to_plugin_endpoints' ), 10, 3 );
	}

	/**
	 * Register OAuth admin page
	 *
	 * Creates an admin page for OAuth authorization that handles login redirects.
	 * This page is not shown in the menu but is accessible via direct URL.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			null, // No parent = hidden from menu.
			__( 'OAuth Authorization', 'abilities-bridge' ),
			__( 'OAuth Authorization', 'abilities-bridge' ),
			'read', // All authenticated users.
			'oauth-authorize',
			array( 'Abilities_Bridge_OAuth_Authorization_Handler', 'render_admin_authorize_page' )
		);
	}

	/**
	 * Allow cookie authentication for our REST API endpoints
	 *
	 * By default, WordPress REST API disables cookie authentication for security.
	 * We need to explicitly enable it for our OAuth form submissions.
	 *
	 * @param WP_Error|mixed $result Authentication result.
	 * @return WP_Error|mixed
	 */
	public static function allow_cookie_authentication( $result ) {
		// Only check for our authorize endpoint to avoid noise.
		$request_uri           = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$is_authorize_endpoint = strpos( $request_uri, '/wp-json/abilities-bridge-mcp/v1/authorize' ) !== false;

		// CRITICAL FIX: Even if another filter authenticated, we MUST set the current user.
		// because WordPress REST API authentication doesn't automatically set the user.
		if ( $is_authorize_endpoint ) {
			// Check if user is already set.
			$current_user_id = get_current_user_id();

			if ( ! $current_user_id ) {
				// User not set yet - try to set from cookies.
				// Determine the current user from cookies.
				$user_id = wp_validate_auth_cookie( '', 'logged_in' );

				if ( $user_id ) {
					// Set the current user so is_user_logged_in() will work.
					wp_set_current_user( $user_id );

					// Return true to indicate successful authentication.
					return true;
				}
			}
			// Else: User already set by another filter - continue.
		}

		// Return the original result if we're not on our endpoint or if WP_Error.
		return $result;
	}

	/**
	 * Register OAuth routes
	 */
	public static function register_oauth_routes() {
		// OAuth Discovery - RFC 8414 Authorization Server Metadata.
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Abilities_Bridge_OAuth_Discovery_Handler', 'handle_metadata_request' ),
				'permission_callback' => '__return_true',
			)
		);

		// OAuth Protected Resource Metadata.
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Abilities_Bridge_OAuth_Discovery_Handler', 'handle_protected_resource_request' ),
				'permission_callback' => '__return_true',
			)
		);

		// MCP discovery metadata.
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/.well-known/mcp',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Abilities_Bridge_OAuth_Discovery_Handler', 'handle_mcp_discovery' ),
				'permission_callback' => '__return_true',
			)
		);

		// OAuth authorization endpoint (GET - display consent screen).
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/authorize',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'Abilities_Bridge_OAuth_Authorization_Handler', 'handle_authorize_request' ),
				'permission_callback' => '__return_true', // Auth checked inside handler.
			)
		);

		// OAuth authorization endpoint (POST - process consent).
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/authorize',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Abilities_Bridge_OAuth_Authorization_Handler', 'handle_authorize_approval' ),
				'permission_callback' => '__return_true', // Auth checked inside handler.
			)
		);

		// OAuth token endpoint.
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Abilities_Bridge_OAuth_Token_Handler', 'handle_token_request' ),
				'permission_callback' => '__return_true', // Public endpoint.
			)
		);

		// OAuth token endpoint (OPTIONS - CORS preflight).
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/oauth/token',
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( 'Abilities_Bridge_OAuth_Discovery_Handler', 'handle_preflight' ),
				'permission_callback' => '__return_true', // Public endpoint.
			)
		);

		// OAuth revoke endpoint.
		register_rest_route(
			'abilities-bridge-mcp/v1',
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Abilities_Bridge_OAuth_Token_Handler', 'handle_revoke_request' ),
				'permission_callback' => '__return_true', // Public endpoint (requires valid token).
			)
		);
	}
}

