<?php
/**
 * OAuth Authorization Code Manager
 *
 * Handles authorization code generation, validation, and lifecycle management
 * for OAuth 2.0 Authorization Code flow with PKCE (RFC 7636).
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Authorization Code class.
 *
 * Handles authorization code generation, validation, and lifecycle management.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_OAuth_Authorization_Code {

	/**
	 * Option name for storing authorization codes
	 */
	const OPTION_NAME = 'abilities_bridge_oauth_codes';

	/**
	 * Authorization code lifetime (10 minutes per OAuth 2.0 spec)
	 */
	const CODE_LIFETIME = 600;

	/**
	 * Supported PKCE code challenge methods
	 */
	const SUPPORTED_CHALLENGE_METHODS = array( 'S256', 'plain' );

	/**
	 * Generate a new authorization code
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $user_id WordPress user ID.
	 * @param string $redirect_uri Redirect URI for validation.
	 * @param string $code_challenge PKCE code challenge.
	 * @param string $code_challenge_method PKCE challenge method (S256 or plain).
	 * @param string $scope Requested scope.
	 * @param string $state CSRF state parameter.
	 * @return string|WP_Error Authorization code or error
	 */
	public function generate_code( $client_id, $user_id, $redirect_uri, $code_challenge, $code_challenge_method, $scope = '', $state = '' ) {
		// Validate inputs.
		if ( empty( $client_id ) || empty( $user_id ) || empty( $redirect_uri ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing required parameters for authorization code generation.', 'abilities-bridge' )
			);
		}

		// Validate PKCE challenge method.
		if ( ! in_array( $code_challenge_method, self::SUPPORTED_CHALLENGE_METHODS, true ) ) {
			return new WP_Error(
				'invalid_request',
				sprintf(
					/* translators: %s: supported methods */
					__( 'Invalid code_challenge_method. Supported methods: %s', 'abilities-bridge' ),
					implode( ', ', self::SUPPORTED_CHALLENGE_METHODS )
				)
			);
		}

		// Validate PKCE challenge (required for security).
		if ( empty( $code_challenge ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'code_challenge is required for PKCE flow.', 'abilities-bridge' )
			);
		}

		// Generate cryptographically secure authorization code.
		// Use 43 characters (256 bits base64url) per RFC 6749.
		$code = $this->generate_secure_token( 43 );

		// Store code with metadata.
		$codes = get_option( self::OPTION_NAME, array() );

		$codes[ $code ] = array(
			'client_id'             => $client_id,
			'user_id'               => (int) $user_id,
			'redirect_uri'          => $redirect_uri,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'scope'                 => $scope,
			'state'                 => $state,
			'created_at'            => time(),
			'expires_at'            => time() + self::CODE_LIFETIME,
			'used'                  => false,
		);

		update_option( self::OPTION_NAME, $codes );

		// Log code generation for audit trail.
		$this->log_event(
			'authorization_code_generated',
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
				'scope'     => $scope,
			)
		);

		return $code;
	}

	/**
	 * Validate and consume an authorization code
	 *
	 * @param string $code Authorization code.
	 * @param string $client_id Client identifier.
	 * @param string $redirect_uri Redirect URI for validation.
	 * @param string $code_verifier PKCE code verifier.
	 * @return array|WP_Error Code metadata or error
	 */
	public function validate_and_consume( $code, $client_id, $redirect_uri, $code_verifier ) {
		// Validate inputs.
		if ( empty( $code ) || empty( $client_id ) || empty( $redirect_uri ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Missing required parameters for code validation.', 'abilities-bridge' )
			);
		}

		// Retrieve stored codes.
		$codes = get_option( self::OPTION_NAME, array() );

		// Check if code exists.
		if ( ! isset( $codes[ $code ] ) ) {
			$this->log_event(
				'authorization_code_invalid',
				array(
					'client_id' => $client_id,
					'reason'    => 'code_not_found',
				)
			);

			return new WP_Error(
				'invalid_grant',
				__( 'Invalid authorization code.', 'abilities-bridge' )
			);
		}

		$code_data = $codes[ $code ];

		// Check if code has already been used (replay attack protection).
		if ( $code_data['used'] ) {
			$this->log_event(
				'authorization_code_reused',
				array(
					'client_id' => $client_id,
					'code'      => substr( $code, 0, 8 ) . '...',
				)
			);

			// SECURITY: Delete all tokens for this client (per OAuth 2.0 spec).
			$this->revoke_all_tokens_for_client( $client_id );

			return new WP_Error(
				'invalid_grant',
				__( 'Authorization code has already been used. All tokens have been revoked for security.', 'abilities-bridge' )
			);
		}

		// Check if code has expired.
		if ( time() > $code_data['expires_at'] ) {
			// Clean up expired code.
			unset( $codes[ $code ] );
			update_option( self::OPTION_NAME, $codes );

			$this->log_event(
				'authorization_code_expired',
				array(
					'client_id' => $client_id,
				)
			);

			return new WP_Error(
				'invalid_grant',
				__( 'Authorization code has expired.', 'abilities-bridge' )
			);
		}

		// Validate client_id matches.
		if ( $code_data['client_id'] !== $client_id ) {
			$this->log_event(
				'authorization_code_client_mismatch',
				array(
					'expected' => $code_data['client_id'],
					'provided' => $client_id,
				)
			);

			return new WP_Error(
				'invalid_grant',
				__( 'Client identifier mismatch.', 'abilities-bridge' )
			);
		}

		// Validate redirect_uri matches (exact match required per spec).
		if ( $code_data['redirect_uri'] !== $redirect_uri ) {
			$this->log_event(
				'authorization_code_redirect_mismatch',
				array(
					'expected' => $code_data['redirect_uri'],
					'provided' => $redirect_uri,
				)
			);

			return new WP_Error(
				'invalid_grant',
				__( 'Redirect URI mismatch.', 'abilities-bridge' )
			);
		}

		// Validate PKCE code_verifier.
		$pkce_valid = $this->validate_pkce( $code_verifier, $code_data['code_challenge'], $code_data['code_challenge_method'] );

		if ( is_wp_error( $pkce_valid ) ) {
			$this->log_event(
				'authorization_code_pkce_failed',
				array(
					'client_id' => $client_id,
					'method'    => $code_data['code_challenge_method'],
				)
			);

			return $pkce_valid;
		}

		// Mark code as used (one-time use enforcement).
		$codes[ $code ]['used'] = true;
		update_option( self::OPTION_NAME, $codes );

		// Schedule cleanup of used code (async for performance).
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'abilities_bridge_cleanup_used_codes' );

		$this->log_event(
			'authorization_code_consumed',
			array(
				'client_id' => $client_id,
				'user_id'   => $code_data['user_id'],
			)
		);

		// Return code metadata for token generation.
		return array(
			'client_id' => $code_data['client_id'],
			'user_id'   => $code_data['user_id'],
			'scope'     => $code_data['scope'],
		);
	}

	/**
	 * Validate PKCE code_verifier against stored challenge
	 *
	 * @param string $code_verifier PKCE code verifier.
	 * @param string $code_challenge Stored code challenge.
	 * @param string $code_challenge_method Challenge method (S256 or plain).
	 * @return true|WP_Error True if valid, error otherwise
	 */
	private function validate_pkce( $code_verifier, $code_challenge, $code_challenge_method ) {
		// Validate code_verifier format (RFC 7636: 43-128 characters, [A-Z] [a-z] [0-9] - . _ ~).
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $code_verifier ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'Invalid code_verifier format.', 'abilities-bridge' )
			);
		}

		// Compute challenge from verifier based on method.
		if ( 'S256' === $code_challenge_method ) {
			// S256: BASE64URL(SHA256(ASCII(code_verifier))).
			$computed_challenge = $this->base64url_encode( hash( 'sha256', $code_verifier, true ) );
		} else {
			// plain: code_verifier.
			$computed_challenge = $code_verifier;
		}

		// Timing-safe comparison.
		if ( ! hash_equals( $code_challenge, $computed_challenge ) ) {
			return new WP_Error(
				'invalid_grant',
				__( 'PKCE validation failed. Code verifier does not match challenge.', 'abilities-bridge' )
			);
		}

		return true;
	}

	/**
	 * Clean up expired and used authorization codes
	 *
	 * @return int Number of codes cleaned up
	 */
	public function cleanup_expired_codes() {
		$codes        = get_option( self::OPTION_NAME, array() );
		$current_time = time();
		$cleaned      = 0;

		foreach ( $codes as $code => $data ) {
			// Remove if expired or used.
			if ( $data['used'] || $current_time > $data['expires_at'] ) {
				unset( $codes[ $code ] );
				++$cleaned;
			}
		}

		if ( $cleaned > 0 ) {
			update_option( self::OPTION_NAME, $codes );

			$this->log_event(
				'authorization_codes_cleaned',
				array(
					'count' => $cleaned,
				)
			);
		}

		return $cleaned;
	}

	/**
	 * Revoke all tokens for a client (replay attack mitigation)
	 *
	 * @param string $client_id Client identifier.
	 * @return void
	 */
	private function revoke_all_tokens_for_client( $client_id ) {
		$oauth_data = get_option( Abilities_Bridge_MCP_OAuth::OPTION_NAME, array() );

		// Revoke access tokens.
		if ( isset( $oauth_data['access_tokens'] ) ) {
			foreach ( $oauth_data['access_tokens'] as $key => $token ) {
				if ( $token['client_id'] === $client_id ) {
					unset( $oauth_data['access_tokens'][ $key ] );
				}
			}
		}

		// Revoke refresh tokens.
		if ( isset( $oauth_data['refresh_tokens'] ) ) {
			foreach ( $oauth_data['refresh_tokens'] as $key => $token ) {
				if ( $token['client_id'] === $client_id ) {
					unset( $oauth_data['refresh_tokens'][ $key ] );
				}
			}
		}

		update_option( Abilities_Bridge_MCP_OAuth::OPTION_NAME, $oauth_data );

		$this->log_event(
			'all_tokens_revoked',
			array(
				'client_id' => $client_id,
				'reason'    => 'authorization_code_replay_attack',
			)
		);
	}

	/**
	 * Generate cryptographically secure random token
	 *
	 * @param int $length Token length.
	 * @return string Base64url-encoded token
	 */
	private function generate_secure_token( $length = 43 ) {
		// Generate random bytes (256 bits = 32 bytes).
		$bytes = random_bytes( 32 );

		// Base64url encode and trim to desired length.
		return substr( $this->base64url_encode( $bytes ), 0, $length );
	}

	/**
	 * Base64url encode (URL-safe base64)
	 *
	 * @param string $data Data to encode.
	 * @return string Base64url-encoded string
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Log OAuth event for audit trail
	 *
	 * @param string $event Event name.
	 * @param array  $context Event context.
	 * @return void
	 */
	private function log_event( $event, $context = array() ) {
		// Hook for external logging systems.
		do_action( 'abilities_bridge_oauth_event', $event, $context );
	}
}
