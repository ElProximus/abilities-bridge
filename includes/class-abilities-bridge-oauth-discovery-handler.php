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
	 * Add CORS headers for remote MCP OAuth
	 *
	 * Allows Claude.ai to make cross-origin requests to OAuth endpoints.
	 * Required for the "Add custom connector" flow in Claude Desktop.
	 */
	public static function add_cors_headers() {
		// Allow requests from claude.ai for remote MCP OAuth.
		header( 'Access-Control-Allow-Origin: https://claude.ai' );
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
		$site_url = trailingslashit( get_site_url() );
		$base_url = $site_url . 'wp-json/abilities-bridge-mcp/v1';

		$metadata = array(
			'issuer'                                     => $site_url,
			'authorization_endpoint'                     => $base_url . '/authorize',
			'token_endpoint'                             => $base_url . '/oauth/token',
			'revocation_endpoint'                        => $base_url . '/oauth/revoke',
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array(
				'authorization_code',
				'client_credentials',
				'refresh_token',
			),
			'code_challenge_methods_supported'           => array( 'S256', 'plain' ),
			'token_endpoint_auth_methods_supported'      => array(
				'client_secret_post',
				'client_secret_basic',
			),
			'revocation_endpoint_auth_methods_supported' => array(
				'client_secret_post',
				'client_secret_basic',
			),
			'service_documentation'                      => ABILITIES_BRIDGE_PLUGIN_URL . 'docs/OAUTH2-IMPLEMENTATION-COMPLETE.md',
		);

		// Add CORS headers for discovery.
		return rest_ensure_response( $metadata );
	}

	/**
	 * Handle MCP Discovery Request
	 *
	 * Returns MCP server metadata for Claude Desktop remote connector discovery.
	 * This endpoint tells Claude Desktop where to find OAuth endpoints.
	 *
	 * @return WP_REST_Response MCP server metadata
	 */
	public static function handle_mcp_discovery() {
		// Add CORS headers for Claude Desktop.
		self::add_cors_headers();

		$site_url = trailingslashit( get_site_url() );
		$base_url = trailingslashit( rest_url( 'abilities-bridge-mcp/v1' ) );

		$discovery = array(
			'name'          => 'Abilities Bridge',
			'version'       => ABILITIES_BRIDGE_VERSION,
			'description'   => 'WordPress MCP Server for Claude Desktop',
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
					'scopesSupported'       => array( 'claudeai', 'memory', 'abilities', 'admin' ),
				),
			),
			'documentation' => ABILITIES_BRIDGE_PLUGIN_URL . 'README.md',
		);

		return rest_ensure_response( $discovery );
	}
}
