<?php
/**
 * OAuth Discovery Handler
 * Handles OAuth and MCP discovery endpoints
 *
 * @package Abilities_Bridge
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Discovery Handler class.
 *
 * Handles OAuth discovery endpoint responses.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_OAuth_Discovery_Handler {

	/**
	 * Get allowed CORS origins for MCP OAuth endpoints.
	 *
	 * @return array
	 */
	private static function get_allowed_origins() {
		return array(
			'https://claude.ai',
			'https://chatgpt.com',
			'https://chat.openai.com',
		);
	}

	/**
	 * Get the requested MCP profile from query params.
	 *
	 * @return string
	 */
	private static function get_request_profile() {
		$profile = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';

		return Abilities_Bridge_OAuth_Client_Manager::normalize_profile( $profile );
	}

	/**
	 * Get the OAuth/MCP base URL for a profile.
	 *
	 * @param string $profile MCP profile.
	 * @return string
	 */
	private static function get_profile_base_url( $profile ) {
		return trailingslashit( rest_url( 'abilities-bridge-mcp/v1' ) );
	}

	/**
	 * Get supported PKCE methods for a profile.
	 *
	 * @param string $profile MCP profile.
	 * @return array
	 */
	private static function get_code_challenge_methods( $profile ) {
		$profile = Abilities_Bridge_OAuth_Client_Manager::normalize_profile( $profile );

		if ( Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT === $profile ) {
			return array( 'S256' );
		}

		return array( 'S256', 'plain' );
	}

	/**
	 * Get profile-specific metadata labels.
	 *
	 * @param string $profile MCP profile.
	 * @return array
	 */
	private static function get_profile_details( $profile ) {
		$profile = Abilities_Bridge_OAuth_Client_Manager::normalize_profile( $profile );

		if ( Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT === $profile ) {
			return array(
				'name'        => 'Abilities Bridge ChatGPT MCP',
				'description' => 'WordPress MCP server metadata for direct ChatGPT developer mode connections.',
			);
		}

		return array(
			'name'        => 'Abilities Bridge Anthropic MCP',
			'description' => 'WordPress MCP server metadata for Anthropic MCP clients such as Claude Desktop.',
		);
	}

	/**
	 * Add CORS headers for remote MCP OAuth
	 *
	 * Allows approved remote MCP clients to make cross-origin requests to OAuth endpoints.
	 */
	public static function add_cors_headers() {
		$origin          = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed_origins = self::get_allowed_origins();

		if ( $origin && in_array( $origin, $allowed_origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Vary: Origin' );
		}

		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
		header( 'Access-Control-Allow-Credentials: true' );
	}

	/**
	 * Handle OPTIONS preflight requests for CORS
	 *
	 * Browsers send OPTIONS requests before POST requests with custom headers.
	 * This handler responds with appropriate CORS headers.
	 *
	 * @return WP_REST_Response Empty 200 response with CORS headers
	 */
	public static function handle_preflight() {
		self::add_cors_headers();
		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Handle OAuth Authorization Server Metadata request (RFC 8414)
	 *
	 * Returns discovery metadata about OAuth endpoints and capabilities.
	 *
	 * @return WP_REST_Response Metadata response
	 */
	public static function handle_metadata_request() {
		$profile  = self::get_request_profile();
		$site_url = trailingslashit( get_site_url() );
		$base_url = untrailingslashit( self::get_profile_base_url( $profile ) );

		$metadata = array(
			'issuer'                                     => $site_url,
			'authorization_endpoint'                     => $base_url . '/authorize',
			'token_endpoint'                             => $base_url . '/oauth/token',
			'revocation_endpoint'                        => $base_url . '/oauth/revoke',
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array(
				'authorization_code',
				'refresh_token',
			),
			'code_challenge_methods_supported'           => self::get_code_challenge_methods( $profile ),
			'token_endpoint_auth_methods_supported'      => array(
				'client_secret_post',
				'client_secret_basic',
			),
			'revocation_endpoint_auth_methods_supported' => array(
				'client_secret_post',
				'client_secret_basic',
			),
			'resource'                                   => $base_url . '/mcp',
			'scopes_supported'                           => array( 'mcp', 'memory', 'abilities', 'admin' ),
			'service_documentation'                      => ABILITIES_BRIDGE_PLUGIN_URL . 'docs/OAUTH2-IMPLEMENTATION-COMPLETE.md',
		);

		self::add_cors_headers();
		return rest_ensure_response( $metadata );
	}

	/**
	 * Handle OAuth protected resource metadata request.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_protected_resource_request() {
		$profile  = self::get_request_profile();
		$base_url = untrailingslashit( self::get_profile_base_url( $profile ) );
		$site_url = trailingslashit( get_site_url() );

		self::add_cors_headers();

		return rest_ensure_response(
			array(
				'resource'                 => $base_url . '/mcp',
				'authorization_servers'    => array(
					$site_url . '.well-known/oauth-authorization-server?profile=' . rawurlencode( $profile ),
				),
				'scopes_supported'         => array( 'mcp', 'memory', 'abilities', 'admin' ),
				'bearer_methods_supported' => array( 'header' ),
			)
		);
	}

	/**
	 * Handle MCP Discovery Request
	 *
	 * Returns MCP server metadata for direct MCP client discovery.
	 * This endpoint tells compatible MCP clients where to find OAuth endpoints.
	 *
	 * @return WP_REST_Response MCP server metadata
	 */
	public static function handle_mcp_discovery() {
		self::add_cors_headers();

		$profile  = self::get_request_profile();
		$details  = self::get_profile_details( $profile );
		$base_url = trailingslashit( self::get_profile_base_url( $profile ) );

		$discovery = array(
			'name'          => $details['name'],
			'version'       => ABILITIES_BRIDGE_VERSION,
			'description'   => $details['description'],
			'vendor'        => get_bloginfo( 'name' ),
			'capabilities'  => array(
				'tools'     => true,
				'resources' => true,
				'prompts'   => true,
			),
			'experimental'  => array(
				'oauth' => array(
					'authorizationEndpoint' => $base_url . 'authorize',
					'tokenEndpoint'         => $base_url . 'oauth/token',
					'grantTypes'            => array( 'authorization_code', 'refresh_token' ),
					'pkceRequired'          => true,
					'scopesSupported'       => array( 'mcp', 'memory', 'abilities', 'admin' ),
					'protectedResource'     => untrailingslashit( $base_url ) . '/.well-known/oauth-protected-resource?profile=' . rawurlencode( $profile ),
				),
			),
			'documentation' => ABILITIES_BRIDGE_PLUGIN_URL . 'README.md',
		);

		return rest_ensure_response( $discovery );
	}
}