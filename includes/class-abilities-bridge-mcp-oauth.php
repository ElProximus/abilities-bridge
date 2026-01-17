<?php
/**
 * MCP OAuth Handler - OAuth 2.0 and Application Password Authentication
 *
 * Handles authentication for MCP connector:
 * - OAuth 2.0 for production (after Anthropic review)
 * - Application Password for testing (manual config)
 *
 * This is now a thin facade that delegates to specialized handlers.
 *
 * @package Abilities_Bridge
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP OAuth class.
 *
 * Handles OAuth 2.0 and Application Password authentication for MCP connector.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_MCP_OAuth {

	/**
	 * OAuth option name
	 */
	const OPTION_NAME = 'abilities_bridge_mcp_oauth';

	/**
	 * Initialize OAuth handler
	 */
	public function init() {
		Abilities_Bridge_OAuth_Router::init();
	}

	/**
	 * Register OAuth admin page
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Router::register_admin_page()
	 */
	public function register_admin_page() {
		Abilities_Bridge_OAuth_Router::register_admin_page();
	}

	/**
	 * Allow cookie authentication for REST API endpoints
	 *
	 * @param WP_Error|mixed $result Authentication result.
	 * @return WP_Error|mixed
	 * @deprecated Use Abilities_Bridge_OAuth_Router::allow_cookie_authentication()
	 */
	public function allow_cookie_authentication( $result ) {
		return Abilities_Bridge_OAuth_Router::allow_cookie_authentication( $result );
	}

	/**
	 * Add CORS headers for remote MCP OAuth
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Discovery_Handler::add_cors_headers()
	 */
	private function add_cors_headers() {
		Abilities_Bridge_OAuth_Discovery_Handler::add_cors_headers();
	}

	/**
	 * Handle OPTIONS preflight requests
	 *
	 * @return WP_REST_Response
	 * @deprecated Use Abilities_Bridge_OAuth_Discovery_Handler::handle_preflight()
	 */
	public function handle_preflight() {
		return Abilities_Bridge_OAuth_Discovery_Handler::handle_preflight();
	}

	/**
	 * Restrict OAuth tokens to plugin endpoints only
	 *
	 * @param mixed           $result  Response.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 * @deprecated Use Abilities_Bridge_OAuth_Token_Validator::restrict_oauth_to_plugin_endpoints()
	 */
	public function restrict_oauth_to_plugin_endpoints( $result, $server, $request ) {
		return Abilities_Bridge_OAuth_Token_Validator::restrict_oauth_to_plugin_endpoints( $result, $server, $request );
	}

	/**
	 * Add rewrite rule for /authorize endpoint
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Redirect_Handler::add_authorize_rewrite()
	 */
	public function add_authorize_rewrite() {
		Abilities_Bridge_OAuth_Redirect_Handler::add_authorize_rewrite();
	}

	/**
	 * Handle /authorize redirect
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Redirect_Handler::handle_authorize_redirect()
	 */
	public function handle_authorize_redirect() {
		Abilities_Bridge_OAuth_Redirect_Handler::handle_authorize_redirect();
	}

	/**
	 * Handle /.well-known/ endpoint redirects
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Redirect_Handler::handle_wellknown_redirect()
	 */
	public function handle_wellknown_redirect() {
		Abilities_Bridge_OAuth_Redirect_Handler::handle_wellknown_redirect();
	}

	/**
	 * Render OAuth admin authorization page
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Authorization_Handler::render_admin_authorize_page()
	 */
	public function render_admin_authorize_page() {
		Abilities_Bridge_OAuth_Authorization_Handler::render_admin_authorize_page();
	}

	/**
	 * Register OAuth routes
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Router::register_oauth_routes()
	 */
	public function register_oauth_routes() {
		Abilities_Bridge_OAuth_Router::register_oauth_routes();
	}

	/**
	 * Handle OAuth metadata request
	 *
	 * @return WP_REST_Response
	 * @deprecated Use Abilities_Bridge_OAuth_Discovery_Handler::handle_metadata_request()
	 */
	public function handle_metadata_request() {
		return Abilities_Bridge_OAuth_Discovery_Handler::handle_metadata_request();
	}

	/**
	 * Handle MCP discovery request
	 *
	 * @return WP_REST_Response
	 * @deprecated Use Abilities_Bridge_OAuth_Discovery_Handler::handle_mcp_discovery()
	 */
	public function handle_mcp_discovery() {
		return Abilities_Bridge_OAuth_Discovery_Handler::handle_mcp_discovery();
	}

	/**
	 * Check MCP permission
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 * @deprecated Use Abilities_Bridge_OAuth_Token_Validator::check_permission()
	 */
	public function check_permission( $request ) {
		return Abilities_Bridge_OAuth_Token_Validator::check_permission( $request );
	}

	/**
	 * Validate OAuth token
	 *
	 * @param string $token OAuth access token.
	 * @return bool|WP_Error
	 * @deprecated Use Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token()
	 */
	private function validate_oauth_token( $token ) {
		return Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $token );
	}

	/**
	 * Handle OAuth token request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 * @deprecated Use Abilities_Bridge_OAuth_Token_Handler::handle_token_request()
	 */
	public function handle_token_request( $request ) {
		return Abilities_Bridge_OAuth_Token_Handler::handle_token_request( $request );
	}

	/**
	 * Handle /authorize endpoint (GET)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void
	 * @deprecated Use Abilities_Bridge_OAuth_Authorization_Handler::handle_authorize_request()
	 */
	public function handle_authorize_request( $request ) {
		Abilities_Bridge_OAuth_Authorization_Handler::handle_authorize_request( $request );
	}

	/**
	 * Handle /authorize endpoint (POST)
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return void
	 * @deprecated Use Abilities_Bridge_OAuth_Authorization_Handler::handle_authorize_approval()
	 */
	public function handle_authorize_approval( $request ) {
		Abilities_Bridge_OAuth_Authorization_Handler::handle_authorize_approval( $request );
	}

	/**
	 * Handle token revocation request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 * @deprecated Use Abilities_Bridge_OAuth_Token_Handler::handle_revoke_request()
	 */
	public function handle_revoke_request( $request ) {
		return Abilities_Bridge_OAuth_Token_Handler::handle_revoke_request( $request );
	}

	/**
	 * Generate OAuth credentials
	 *
	 * @param int|null $user_id User ID.
	 * @return array
	 * @deprecated Use Abilities_Bridge_OAuth_Client_Manager::generate_credentials()
	 */
	public function generate_credentials( $user_id = null ) {
		return Abilities_Bridge_OAuth_Client_Manager::generate_credentials( $user_id );
	}

	/**
	 * Revoke OAuth credentials
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 * @deprecated Use Abilities_Bridge_OAuth_Client_Manager::revoke_credentials()
	 */
	public function revoke_credentials( $client_id ) {
		return Abilities_Bridge_OAuth_Client_Manager::revoke_credentials( $client_id );
	}

	/**
	 * Revoke client
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 * @deprecated Use Abilities_Bridge_OAuth_Client_Manager::revoke_client()
	 */
	public function revoke_client( $client_id ) {
		return Abilities_Bridge_OAuth_Client_Manager::revoke_client( $client_id );
	}

	/**
	 * Get all OAuth clients for current user
	 *
	 * @return array
	 * @deprecated Use Abilities_Bridge_OAuth_Client_Manager::get_user_clients()
	 */
	public function get_user_clients() {
		return Abilities_Bridge_OAuth_Client_Manager::get_user_clients();
	}

	/**
	 * Clean up expired tokens
	 *
	 * @deprecated Use Abilities_Bridge_OAuth_Client_Manager::cleanup_expired_tokens()
	 */
	public function cleanup_expired_tokens() {
		Abilities_Bridge_OAuth_Client_Manager::cleanup_expired_tokens();
	}
}
