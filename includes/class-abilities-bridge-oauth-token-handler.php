<?php
/**
 * OAuth Token Handler
 * Handles OAuth token issuance, refresh, and revocation
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Token Handler class.
 *
 * Handles OAuth token issuance, refresh, and revocation.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_OAuth_Token_Handler {

	/**
	 * OAuth option name
	 */
	const OPTION_NAME = 'abilities_bridge_mcp_oauth';

	/**
	 * Handle OAuth token request
	 *
	 * Implements OAuth 2.0 token endpoint with multiple grant types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public static function handle_token_request( $request ) {
		// Add CORS headers for remote MCP OAuth.
		Abilities_Bridge_OAuth_Discovery_Handler::add_cors_headers();

		// Apply rate limiting.
		$rate_limiter = new Abilities_Bridge_OAuth_Rate_Limiter();
		$rate_check   = $rate_limiter->check_rate_limit( 'token' );
		if ( is_wp_error( $rate_check ) ) {
			return self::jsonrpc_error_response( $rate_check );
		}

		$grant_type    = $request->get_param( 'grant_type' );
		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );
		$refresh_token = $request->get_param( 'refresh_token' );
		$code          = $request->get_param( 'code' );
		$redirect_uri  = $request->get_param( 'redirect_uri' );
		$code_verifier = $request->get_param( 'code_verifier' );

		// Validate grant type.
		if ( empty( $grant_type ) ) {
			$error = new WP_Error(
				'invalid_request',
				__( 'Missing grant_type parameter.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
			return self::jsonrpc_error_response( $error );
		}

		// Handle authorization_code grant.
		if ( 'authorization_code' === $grant_type ) {
			$result = self::handle_authorization_code( $code, $client_id, $redirect_uri, $code_verifier );

			if ( is_wp_error( $result ) ) {
				$rate_limiter->record_failed_attempt( $client_id );
				return self::jsonrpc_error_response( $result );
			}

			$rate_limiter->reset_failed_attempts( $client_id );
			return $result;
		}

		// Handle client_credentials grant.
		if ( 'client_credentials' === $grant_type ) {
			$result = self::handle_client_credentials( $client_id, $client_secret );
			if ( is_wp_error( $result ) ) {
				$rate_limiter->record_failed_attempt( $client_id );
				return self::jsonrpc_error_response( $result );
			}
			$rate_limiter->reset_failed_attempts( $client_id );
			return $result;
		}

		// Handle refresh_token grant.
		if ( 'refresh_token' === $grant_type ) {
			$result = self::handle_refresh_token( $refresh_token );
			if ( is_wp_error( $result ) ) {
				return self::jsonrpc_error_response( $result );
			}
			return $result;
		}

		// Unsupported grant type.
		$error = new WP_Error(
			'unsupported_grant_type',
			__( 'The authorization grant type is not supported. Supported types: authorization_code, client_credentials, refresh_token.', 'abilities-bridge' ),
			array( 'status' => 400 )
		);
		return self::jsonrpc_error_response( $error );
	}

	/**
	 * Handle authorization_code grant type
	 *
	 * @param string $code Authorization code.
	 * @param string $client_id Client ID.
	 * @param string $redirect_uri Redirect URI.
	 * @param string $code_verifier PKCE code verifier.
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	private static function handle_authorization_code( $code, $client_id, $redirect_uri, $code_verifier ) {
		$logger = new Abilities_Bridge_OAuth_Logger();

		// Validate required parameters.
		if ( empty( $code ) || empty( $client_id ) || empty( $redirect_uri ) || empty( $code_verifier ) ) {
			$logger->log_auth_failure(
				'missing_parameters',
				array(
					'grant_type' => 'authorization_code',
					'client_id'  => $client_id,
				)
			);

			return new WP_Error(
				'invalid_request',
				__( 'Missing required parameters: code, client_id, redirect_uri, and code_verifier are required.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Validate and consume authorization code.
		$code_manager = new Abilities_Bridge_OAuth_Authorization_Code();
		$code_data    = $code_manager->validate_and_consume( $code, $client_id, $redirect_uri, $code_verifier );

		if ( is_wp_error( $code_data ) ) {
			$logger->log_auth_failure(
				'invalid_authorization_code',
				array(
					'client_id' => $client_id,
					'error'     => $code_data->get_error_message(),
				)
			);

			return $code_data;
		}

		// Get client data.
		$client = Abilities_Bridge_OAuth_Client_Manager::get_client( $client_id );

		if ( ! $client ) {
			$logger->log_auth_failure(
				'client_not_found',
				array(
					'client_id' => $client_id,
				)
			);

			return new WP_Error(
				'invalid_client',
				__( 'Client not found.', 'abilities-bridge' ),
				array( 'status' => 401 )
			);
		}

		// Generate tokens.
		$tokens = self::generate_access_and_refresh_tokens( $client_id, $code_data['user_id'], $code_data['scope'] );

		// Check if token generation failed.
		if ( is_wp_error( $tokens ) ) {
			$logger->log_auth_failure(
				'token_generation_failed',
				array(
					'client_id' => $client_id,
					'error'     => $tokens->get_error_message(),
				)
			);
			return $tokens;
		}

		// Log successful token issuance.
		$logger->log_token_issued( 'authorization_code', $client_id, 'access_token' );
		$logger->log_token_issued( 'authorization_code', $client_id, 'refresh_token' );

		// Return OAuth 2.0 response.
		return rest_ensure_response( $tokens );
	}

	/**
	 * Handle client_credentials grant type
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret.
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	private static function handle_client_credentials( $client_id, $client_secret ) {
		// Validate client credentials.
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing client_id or client_secret.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Verify credentials.
		$client = Abilities_Bridge_OAuth_Client_Manager::verify_client_credentials( $client_id, $client_secret );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Generate tokens with default scope.
		$tokens = self::generate_access_and_refresh_tokens(
			$client_id,
			$client['user_id'],
			Abilities_Bridge_OAuth_Scopes::get_default_scope()
		);

		// Check if token generation failed.
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		// Return OAuth 2.0 response.
		return rest_ensure_response( $tokens );
	}

	/**
	 * Handle refresh_token grant type
	 *
	 * @param string $refresh_token Refresh token.
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	private static function handle_refresh_token( $refresh_token ) {
		if ( empty( $refresh_token ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing refresh_token parameter.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Get stored OAuth data.
		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( empty( $oauth_data['refresh_tokens'] ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid refresh token.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Find and validate refresh token.
		$token_data = null;
		foreach ( $oauth_data['refresh_tokens'] as $stored_token ) {
			// Decrypt stored refresh token before comparing.
			$decrypted_token = Abilities_Bridge_Token_Encryption::decrypt( $stored_token['refresh_token'] );

			// Skip if decryption failed.
			if ( is_wp_error( $decrypted_token ) ) {
				continue;
			}

			if ( hash_equals( $decrypted_token, $refresh_token ) ) {
				$token_data = $stored_token;
				break;
			}
		}

		if ( ! $token_data ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid refresh token.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Check if refresh token is expired.
		if ( time() > $token_data['expires_at'] ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Refresh token has expired.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		// Generate new access token.
		$access_token = wp_generate_password( 64, true, true );
		$expires_in   = HOUR_IN_SECONDS;
		$expires_at   = time() + $expires_in;

		// Encrypt access token before storing.
		$encrypted_access_token = Abilities_Bridge_Token_Encryption::encrypt( $access_token );

		// Check for encryption errors - FAIL instead of falling back to plaintext.
		if ( is_wp_error( $encrypted_access_token ) ) {
			return new WP_Error(
				'token_generation_failed',
				__( 'Failed to encrypt new access token. Please check system configuration.', 'abilities-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Store access token (encrypted).
		if ( ! isset( $oauth_data['access_tokens'] ) ) {
			$oauth_data['access_tokens'] = array();
		}

		$oauth_data['access_tokens'][] = array(
			'access_token' => $encrypted_access_token,
			'client_id'    => $token_data['client_id'],
			'user_id'      => $token_data['user_id'],
			'expires_at'   => $expires_at,
			'created_at'   => time(),
		);

		update_option( self::OPTION_NAME, $oauth_data );

		// Return OAuth 2.0 response with plaintext token.
		return rest_ensure_response(
			array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => $expires_in,
				'refresh_token' => $refresh_token, // Return same refresh token.
			)
		);
	}

	/**
	 * Generate access and refresh tokens
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id User ID.
	 * @param string $scope OAuth scope.
	 * @return array Token response
	 */
	private static function generate_access_and_refresh_tokens( $client_id, $user_id, $scope ) {
		// Generate new access token (1 hour expiration).
		$access_token = wp_generate_password( 64, true, true );
		$expires_in   = HOUR_IN_SECONDS;
		$expires_at   = time() + $expires_in;

		// Generate refresh token (30 days expiration).
		$refresh_token   = wp_generate_password( 64, true, true );
		$refresh_expires = time() + ( 30 * DAY_IN_SECONDS );

		// Encrypt tokens before storing.
		$encrypted_access_token  = Abilities_Bridge_Token_Encryption::encrypt( $access_token );
		$encrypted_refresh_token = Abilities_Bridge_Token_Encryption::encrypt( $refresh_token );

		// Check for encryption errors - FAIL instead of falling back to plaintext.
		if ( is_wp_error( $encrypted_access_token ) ) {
			return new WP_Error(
				'token_generation_failed',
				__( 'Failed to generate access token. Please check system configuration.', 'abilities-bridge' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $encrypted_refresh_token ) ) {
			return new WP_Error(
				'token_generation_failed',
				__( 'Failed to generate refresh token. Please check system configuration.', 'abilities-bridge' ),
				array( 'status' => 500 )
			);
		}

		// Get OAuth data.
		$oauth_data = get_option( self::OPTION_NAME, array() );

		// Store access token (encrypted).
		if ( ! isset( $oauth_data['access_tokens'] ) ) {
			$oauth_data['access_tokens'] = array();
		}

		$oauth_data['access_tokens'][] = array(
			'access_token' => $encrypted_access_token,
			'client_id'    => $client_id,
			'user_id'      => $user_id,
			'expires_at'   => $expires_at,
			'created_at'   => time(),
			'scope'        => $scope,
		);

		// Store refresh token (encrypted).
		if ( ! isset( $oauth_data['refresh_tokens'] ) ) {
			$oauth_data['refresh_tokens'] = array();
		}

		$oauth_data['refresh_tokens'][] = array(
			'refresh_token' => $encrypted_refresh_token,
			'client_id'     => $client_id,
			'user_id'       => $user_id,
			'expires_at'    => $refresh_expires,
			'created_at'    => time(),
		);

		update_option( self::OPTION_NAME, $oauth_data );

		// Return plaintext tokens to the client.
		return array(
			'access_token'  => $access_token,
			'token_type'    => 'Bearer',
			'expires_in'    => $expires_in,
			'refresh_token' => $refresh_token,
		);
	}

	/**
	 * Handle token revocation request
	 *
	 * Revokes an access token or refresh token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error
	 */
	public static function handle_revoke_request( $request ) {
		$token      = $request->get_param( 'token' );
		$token_type = $request->get_param( 'token_type_hint' ); // Optional: 'access_token' or 'refresh_token'.

		if ( empty( $token ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing token parameter.', 'abilities-bridge' ),
				array( 'status' => 400 )
			);
		}

		$oauth_data = get_option( self::OPTION_NAME, array() );
		$revoked    = false;

		// Try to revoke access token.
		if ( ! isset( $token_type ) || 'access_token' === $token_type ) {
			if ( ! empty( $oauth_data['access_tokens'] ) ) {
				$oauth_data['access_tokens'] = array_filter(
					$oauth_data['access_tokens'],
					function ( $stored_token ) use ( $token, &$revoked ) {
						if ( isset( $stored_token['access_token'] ) ) {
							// Decrypt stored token before comparing.
							$decrypted_token = Abilities_Bridge_Token_Encryption::decrypt( $stored_token['access_token'] );

							if ( ! is_wp_error( $decrypted_token ) && hash_equals( $decrypted_token, $token ) ) {
								$revoked = true;
								return false; // Remove this token.
							}
						}
						return true; // Keep this token.
					}
				);
			}
		}

		// Try to revoke refresh token.
		if ( ! $revoked && ( ! isset( $token_type ) || 'refresh_token' === $token_type ) ) {
			if ( ! empty( $oauth_data['refresh_tokens'] ) ) {
				$oauth_data['refresh_tokens'] = array_filter(
					$oauth_data['refresh_tokens'],
					function ( $stored_token ) use ( $token, &$revoked ) {
						if ( isset( $stored_token['refresh_token'] ) ) {
							// Decrypt stored token before comparing.
							$decrypted_token = Abilities_Bridge_Token_Encryption::decrypt( $stored_token['refresh_token'] );

							if ( ! is_wp_error( $decrypted_token ) && hash_equals( $decrypted_token, $token ) ) {
								$revoked = true;
								return false; // Remove this token.
							}
						}
						return true; // Keep this token.
					}
				);
			}
		}

		if ( $revoked ) {
			update_option( self::OPTION_NAME, $oauth_data );
		}

		// OAuth 2.0 revocation always returns 200 OK (even if token wasn't found).
		return rest_ensure_response( array( 'revoked' => true ) );
	}

	/**
	 * Format error as JSON-RPC 2.0 response
	 *
	 * @param WP_Error $error WordPress error object.
	 * @param int|null $id Request ID (null for notifications).
	 * @return WP_REST_Response
	 */
	private static function jsonrpc_error_response( $error, $id = null ) {
		$error_code    = $error->get_error_code();
		$error_message = $error->get_error_message();

		// Map WordPress error codes to JSON-RPC error codes.
		$jsonrpc_code_map = array(
			'invalid_client'    => -32000,
			'invalid_grant'     => -32001,
			'unauthorized'      => -32002,
			'invalid_request'   => -32600,
			'invalid_token'     => -32002,
			'rest_forbidden'    => -32002,
			'unsupported_grant' => -32600,
		);

		$jsonrpc_code = isset( $jsonrpc_code_map[ $error_code ] )
			? $jsonrpc_code_map[ $error_code ]
			: -32000;

		$response_data = array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => $jsonrpc_code,
				'message' => $error_message,
				'data'    => array(
					'wp_error_code' => $error_code,
				),
			),
		);

		if ( null !== $id ) {
			$response_data['id'] = $id;
		}

		return new WP_REST_Response( $response_data, 200 );
	}
}
