<?php
/**
 * OAuth Redirect Handler
 * Handles URL redirects and rewrite rules for OAuth endpoints
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Redirect Handler class.
 *
 * Handles URL redirects and rewrite rules for OAuth endpoints.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_OAuth_Redirect_Handler {

	/**
	 * Add rewrite rule for /authorize endpoint and /.well-known/ discovery endpoints
	 */
	public static function add_authorize_rewrite() {
		add_rewrite_rule(
			'^authorize/?$',
			'index.php?abilities_bridge_oauth_authorize=1',
			'top'
		);

		// Add rewrite rules for .well-known endpoints (WordPress REST API routing).
		add_rewrite_rule(
			'^\.well-known/mcp$',
			'index.php?rest_route=/abilities-bridge-mcp/v1/.well-known/mcp',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server$',
			'index.php?rest_route=/abilities-bridge-mcp/v1/.well-known/oauth-authorization-server',
			'top'
		);

		// Add query var.
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'abilities_bridge_oauth_authorize';
				return $vars;
			}
		);
	}

	/**
	 * Handle /authorize redirect
	 *
	 * Redirects /authorize to the admin page using transient storage for OAuth parameters.
	 * This prevents parameters from being stripped during WordPress login redirects.
	 */
	public static function handle_authorize_redirect() {
		if ( get_query_var( 'abilities_bridge_oauth_authorize' ) ) {
			// Parse OAuth parameters from query string.
			$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
			parse_str( $query_string, $oauth_params );

			// Generate cryptographically secure transient key.
			$transient_key = 'abilities_bridge_oauth_params_' . bin2hex( random_bytes( 16 ) );

			// Store OAuth parameters in transient (10 minute expiry).
			set_transient( $transient_key, $oauth_params, 600 );

			// Build admin page URL with only the transient key.
			$admin_url = admin_url( 'admin.php?page=oauth-authorize&key=' . $transient_key );

			// Redirect to admin page.
			wp_safe_redirect( $admin_url );
			exit;
		}
	}

	/**
	 * Handle /.well-known/ endpoint redirects
	 *
	 * Intercepts requests to /.well-known/mcp and /.well-known/oauth-authorization-server
	 * and serves them directly, bypassing WordPress REST API routing issues with empty namespaces.
	 *
	 * @since 1.2.0
	 */
	public static function handle_wellknown_redirect() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Handle /.well-known/mcp discovery endpoint.
		if ( strpos( $request_uri, '/.well-known/mcp' ) !== false ) {
			$response = Abilities_Bridge_OAuth_Discovery_Handler::handle_mcp_discovery();
			$data     = $response->get_data();

			// Set proper headers.
			header( 'Content-Type: application/json' );
			status_header( 200 );

			echo wp_json_encode( $data );
			exit;
		}

		// Handle /.well-known/oauth-authorization-server metadata endpoint.
		if ( strpos( $request_uri, '/.well-known/oauth-authorization-server' ) !== false ) {
			$response = Abilities_Bridge_OAuth_Discovery_Handler::handle_metadata_request();
			$data     = $response->get_data();

			// Set proper headers.
			header( 'Content-Type: application/json' );
			status_header( 200 );

			echo wp_json_encode( $data );
			exit;
		}
	}
}
