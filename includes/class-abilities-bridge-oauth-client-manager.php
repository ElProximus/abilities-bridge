<?php
/**
 * OAuth Client Management
 * Handles OAuth client credential creation, revocation, and queries
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Client Manager class.
 *
 * Handles OAuth client credential creation, revocation, and queries.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_OAuth_Client_Manager {

	/**
	 * OAuth option name
	 */
	const OPTION_NAME = 'abilities_bridge_mcp_oauth';

	/**
	 * Generate OAuth credentials
	 *
	 * Creates a new OAuth client ID and secret for the MCP connector.
	 * For OAuth 2.0 flow, access tokens are obtained via /oauth/token endpoint.
	 *
	 * @param int|null $user_id User ID to associate with credentials.
	 * @return array OAuth credentials (client_id and client_secret)
	 */
	public static function generate_credentials( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		// Generate client ID and secret.
		$client_id     = 'abilities_bridge_client_' . wp_generate_password( 16, false );
		$client_secret = wp_generate_password( 32, true, true );

		// Store OAuth data.
		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( ! isset( $oauth_data['clients'] ) ) {
			$oauth_data['clients'] = array();
		}

		// Store client credentials.
		$oauth_data['clients'][ $client_id ] = array(
			'client_id'     => $client_id,
			'client_secret' => wp_hash_password( $client_secret ), // Store hashed.
			'user_id'       => $user_id,
			'created_at'    => time(),
		);

		update_option( self::OPTION_NAME, $oauth_data );

		// Return credentials (only time client_secret is shown in plain text).
		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret, // Show once, then hashed.
		);
	}

	/**
	 * Revoke OAuth credentials
	 *
	 * @param string $client_id Client ID to revoke.
	 * @return bool True if revoked, false otherwise
	 */
	public static function revoke_credentials( $client_id ) {
		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( ! isset( $oauth_data['clients'][ $client_id ] ) ) {
			return false;
		}

		// Remove client.
		unset( $oauth_data['clients'][ $client_id ] );

		// Remove associated old long-lived tokens.
		if ( isset( $oauth_data['tokens'] ) ) {
			$oauth_data['tokens'] = array_filter(
				$oauth_data['tokens'],
				function ( $token ) use ( $client_id ) {
					return $token['client_id'] !== $client_id;
				}
			);
		}

		// Remove associated access tokens.
		if ( isset( $oauth_data['access_tokens'] ) ) {
			$oauth_data['access_tokens'] = array_filter(
				$oauth_data['access_tokens'],
				function ( $token ) use ( $client_id ) {
					return $token['client_id'] !== $client_id;
				}
			);
		}

		// Remove associated refresh tokens.
		if ( isset( $oauth_data['refresh_tokens'] ) ) {
			$oauth_data['refresh_tokens'] = array_filter(
				$oauth_data['refresh_tokens'],
				function ( $token ) use ( $client_id ) {
					return $token['client_id'] !== $client_id;
				}
			);
		}

		update_option( self::OPTION_NAME, $oauth_data );

		return true;
	}

	/**
	 * Revoke client (alias for revoke_credentials)
	 *
	 * @param string $client_id Client ID to revoke.
	 * @return bool True if revoked, false otherwise
	 */
	public static function revoke_client( $client_id ) {
		return self::revoke_credentials( $client_id );
	}

	/**
	 * Get all OAuth clients for current user
	 *
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return array OAuth clients
	 */
	public static function get_user_clients( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( empty( $oauth_data['clients'] ) ) {
			return array();
		}

		// Filter clients for current user.
		$user_clients = array_filter(
			$oauth_data['clients'],
			function ( $client ) use ( $user_id ) {
				return isset( $client['user_id'] ) && $client['user_id'] === $user_id;
			}
		);

		// Remove sensitive data before returning.
		foreach ( $user_clients as &$client ) {
			unset( $client['client_secret'] ); // Never return hashed secret.
		}

		return array_values( $user_clients );
	}

	/**
	 * Get client by ID
	 *
	 * @param string $client_id Client ID.
	 * @return array|null Client data or null if not found
	 */
	public static function get_client( $client_id ) {
		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( empty( $oauth_data['clients'][ $client_id ] ) ) {
			return null;
		}

		return $oauth_data['clients'][ $client_id ];
	}

	/**
	 * Verify client credentials
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Client secret (plain text).
	 * @return array|WP_Error Client data or error
	 */
	public static function verify_client_credentials( $client_id, $client_secret ) {
		$client = self::get_client( $client_id );

		if ( ! $client ) {
			return new WP_Error(
				'invalid_client',
				__( 'Client authentication failed. Invalid client_id.', 'abilities-bridge' ),
				array( 'status' => 401 )
			);
		}

		// Verify client secret (timing-safe comparison).
		if ( ! wp_check_password( $client_secret, $client['client_secret'] ) ) {
			return new WP_Error(
				'invalid_client',
				__( 'Client authentication failed. Invalid client_secret.', 'abilities-bridge' ),
				array( 'status' => 401 )
			);
		}

		return $client;
	}

	/**
	 * Clean up expired tokens
	 */
	public static function cleanup_expired_tokens() {
		$oauth_data = get_option( self::OPTION_NAME, array() );

		if ( empty( $oauth_data['tokens'] ) ) {
			return;
		}

		// Remove expired tokens.
		$oauth_data['tokens'] = array_filter(
			$oauth_data['tokens'],
			function ( $token ) {
				return ! isset( $token['expires_at'] ) || time() < $token['expires_at'];
			}
		);

		update_option( self::OPTION_NAME, $oauth_data );
	}

	/**
	 * Migrate existing tokens to encrypted format
	 *
	 * @return array Migration statistics
	 */
	public static function migrate_tokens_to_encrypted() {
		return Abilities_Bridge_Token_Encryption::migrate_existing_tokens();
	}
}
