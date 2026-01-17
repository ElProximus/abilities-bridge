<?php
/**
 * Token Encryption Handler
 * Encrypts and decrypts OAuth tokens using AES-256-CBC
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Encryption class.
 *
 * Encrypts and decrypts OAuth tokens using AES-256-CBC.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Token_Encryption {

	/**
	 * Encryption version prefix
	 */
	const VERSION = 'v1:';

	/**
	 * Encryption cipher method
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * IV length for AES-256-CBC (16 bytes)
	 */
	const IV_LENGTH = 16;

	/**
	 * Encrypt a token using AES-256-CBC
	 *
	 * @param string $plaintext Plain text token to encrypt.
	 * @return string|WP_Error Encrypted token with version prefix or error
	 */
	public static function encrypt( $plaintext ) {
		// Validate input.
		if ( empty( $plaintext ) || ! is_string( $plaintext ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Invalid token input for encryption.', 'abilities-bridge' )
			);
		}

		// Get encryption key.
		$key = self::get_encryption_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		// Generate random IV (16 bytes for AES-256-CBC).
		$iv = openssl_random_pseudo_bytes( self::IV_LENGTH );
		if ( false === $iv || strlen( $iv ) !== self::IV_LENGTH ) {
			return new WP_Error(
				'encryption_failed',
				__( 'Failed to generate initialization vector.', 'abilities-bridge' )
			);
		}

		// Encrypt the token.
		$encrypted = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $encrypted ) {
			return new WP_Error(
				'encryption_failed',
				__( 'Token encryption failed.', 'abilities-bridge' )
			);
		}

		// Combine IV + encrypted data and encode.
		$combined = $iv . $encrypted;
		$encoded  = base64_encode( $combined );

		// Add version prefix.
		return self::VERSION . $encoded;
	}

	/**
	 * Decrypt an encrypted token
	 *
	 * Supports both encrypted (v1: prefix) and legacy plaintext tokens
	 * for backward compatibility.
	 *
	 * @param string $encrypted Encrypted token with version prefix.
	 * @return string|WP_Error Decrypted token or error
	 */
	public static function decrypt( $encrypted ) {
		// Validate input.
		if ( empty( $encrypted ) || ! is_string( $encrypted ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Invalid token input for decryption.', 'abilities-bridge' )
			);
		}

		// Check if token is encrypted (has version prefix).
		if ( strpos( $encrypted, self::VERSION ) !== 0 ) {
			// Legacy plaintext token - return as-is for backward compatibility.
			return $encrypted;
		}

		// Remove version prefix.
		$encoded = substr( $encrypted, strlen( self::VERSION ) );

		// Decode base64.
		$combined = base64_decode( $encoded, true );
		if ( false === $combined ) {
			return new WP_Error(
				'decryption_failed',
				__( 'Invalid encrypted token format.', 'abilities-bridge' )
			);
		}

		// Extract IV (first 16 bytes).
		if ( strlen( $combined ) < self::IV_LENGTH ) {
			return new WP_Error(
				'decryption_failed',
				__( 'Encrypted token is too short.', 'abilities-bridge' )
			);
		}

		$iv             = substr( $combined, 0, self::IV_LENGTH );
		$encrypted_data = substr( $combined, self::IV_LENGTH );

		// Get encryption key.
		$key = self::get_encryption_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		// Decrypt the token.
		$decrypted = openssl_decrypt(
			$encrypted_data,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $decrypted ) {
			return new WP_Error(
				'decryption_failed',
				__( 'Token decryption failed.', 'abilities-bridge' )
			);
		}

		return $decrypted;
	}

	/**
	 * Get encryption key derived from WordPress salts
	 *
	 * Uses AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, and NONCE_KEY
	 * to derive a 32-byte key for AES-256.
	 *
	 * @return string|WP_Error 32-byte encryption key or error
	 */
	private static function get_encryption_key() {
		// Check if required constants are defined.
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ||
			! defined( 'LOGGED_IN_KEY' ) || ! defined( 'NONCE_KEY' ) ) {
			return new WP_Error(
				'missing_salts',
				__( 'WordPress security salts are not configured.', 'abilities-bridge' )
			);
		}

		// Combine all salts.
		$salt_combination = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;

		// Derive 32-byte key using hash_pbkdf2.
		// Using PBKDF2 with 10,000 iterations for key stretching.
		$key = hash_pbkdf2(
			'sha256',
			$salt_combination,
			'abilities_bridge_token_encryption', // Additional salt.
			10000,
			32,
			true // Return raw binary data.
		);

		if ( false === $key || strlen( $key ) !== 32 ) {
			return new WP_Error(
				'key_derivation_failed',
				__( 'Failed to derive encryption key.', 'abilities-bridge' )
			);
		}

		return $key;
	}

	/**
	 * Check if a token is encrypted
	 *
	 * @param string $token Token to check.
	 * @return bool True if encrypted, false if plaintext
	 */
	public static function is_encrypted( $token ) {
		return is_string( $token ) && strpos( $token, self::VERSION ) === 0;
	}

	/**
	 * Migrate existing plaintext tokens to encrypted format
	 *
	 * @return array Migration statistics
	 */
	public static function migrate_existing_tokens() {
		$oauth_data = get_option( Abilities_Bridge_OAuth_Client_Manager::OPTION_NAME, array() );
		$stats      = array(
			'access_tokens_migrated'  => 0,
			'refresh_tokens_migrated' => 0,
			'access_tokens_skipped'   => 0,
			'refresh_tokens_skipped'  => 0,
			'errors'                  => array(),
		);

		// Migrate access tokens.
		if ( ! empty( $oauth_data['access_tokens'] ) ) {
			foreach ( $oauth_data['access_tokens'] as &$token_data ) {
				if ( ! isset( $token_data['access_token'] ) ) {
					continue;
				}

				// Skip if already encrypted.
				if ( self::is_encrypted( $token_data['access_token'] ) ) {
					++$stats['access_tokens_skipped'];
					continue;
				}

				// Encrypt the token.
				$encrypted = self::encrypt( $token_data['access_token'] );

				if ( is_wp_error( $encrypted ) ) {
					$stats['errors'][] = 'Access token encryption failed: ' . $encrypted->get_error_message();
					continue;
				}

				$token_data['access_token'] = $encrypted;
				++$stats['access_tokens_migrated'];
			}
		}

		// Migrate refresh tokens.
		if ( ! empty( $oauth_data['refresh_tokens'] ) ) {
			foreach ( $oauth_data['refresh_tokens'] as &$token_data ) {
				if ( ! isset( $token_data['refresh_token'] ) ) {
					continue;
				}

				// Skip if already encrypted.
				if ( self::is_encrypted( $token_data['refresh_token'] ) ) {
					++$stats['refresh_tokens_skipped'];
					continue;
				}

				// Encrypt the token.
				$encrypted = self::encrypt( $token_data['refresh_token'] );

				if ( is_wp_error( $encrypted ) ) {
					$stats['errors'][] = 'Refresh token encryption failed: ' . $encrypted->get_error_message();
					continue;
				}

				$token_data['refresh_token'] = $encrypted;
				++$stats['refresh_tokens_migrated'];
			}
		}

		// Save updated data if any tokens were migrated.
		if ( $stats['access_tokens_migrated'] > 0 || $stats['refresh_tokens_migrated'] > 0 ) {
			update_option( Abilities_Bridge_OAuth_Client_Manager::OPTION_NAME, $oauth_data );
		}

		return $stats;
	}

	/**
	 * Test encryption and decryption functionality
	 *
	 * Performs self-test to verify encryption is working correctly.
	 *
	 * @return array Test results
	 */
	public static function test_encryption() {
		$results = array(
			'success' => true,
			'tests'   => array(),
		);

		// Test 1: Basic encryption/decryption.
		$test_token = 'test_token_' . wp_generate_password( 32, true, true );
		$encrypted  = self::encrypt( $test_token );

		if ( is_wp_error( $encrypted ) ) {
			$results['success']                   = false;
			$results['tests']['basic_encryption'] = array(
				'passed' => false,
				'error'  => $encrypted->get_error_message(),
			);
		} else {
			$decrypted = self::decrypt( $encrypted );

			if ( is_wp_error( $decrypted ) ) {
				$results['success']                   = false;
				$results['tests']['basic_encryption'] = array(
					'passed' => false,
					'error'  => $decrypted->get_error_message(),
				);
			} else {
				$passed                               = hash_equals( $test_token, $decrypted );
				$results['tests']['basic_encryption'] = array(
					'passed'           => $passed,
					'original_length'  => strlen( $test_token ),
					'encrypted_length' => strlen( $encrypted ),
					'decrypted_match'  => $passed,
				);

				if ( ! $passed ) {
					$results['success'] = false;
				}
			}
		}

		// Test 2: Unique IV per encryption.
		$encrypted1 = self::encrypt( $test_token );
		$encrypted2 = self::encrypt( $test_token );

		$results['tests']['unique_iv'] = array(
			'passed'            => ! is_wp_error( $encrypted1 ) && ! is_wp_error( $encrypted2 ) && $encrypted1 !== $encrypted2,
			'encrypted1_length' => is_string( $encrypted1 ) ? strlen( $encrypted1 ) : 0,
			'encrypted2_length' => is_string( $encrypted2 ) ? strlen( $encrypted2 ) : 0,
			'different'         => ! is_wp_error( $encrypted1 ) && ! is_wp_error( $encrypted2 ) && $encrypted1 !== $encrypted2,
		);

		if ( ! $results['tests']['unique_iv']['passed'] ) {
			$results['success'] = false;
		}

		// Test 3: Version prefix.
		if ( ! is_wp_error( $encrypted ) ) {
			$has_prefix                         = strpos( $encrypted, self::VERSION ) === 0;
			$results['tests']['version_prefix'] = array(
				'passed'     => $has_prefix,
				'prefix'     => self::VERSION,
				'has_prefix' => $has_prefix,
			);

			if ( ! $has_prefix ) {
				$results['success'] = false;
			}
		}

		// Test 4: Backward compatibility (plaintext tokens).
		$plaintext_token     = 'plaintext_legacy_token_12345';
		$decrypted_plaintext = self::decrypt( $plaintext_token );

		$results['tests']['backward_compatibility'] = array(
			'passed'              => ! is_wp_error( $decrypted_plaintext ) && hash_equals( $plaintext_token, $decrypted_plaintext ),
			'plaintext_preserved' => ! is_wp_error( $decrypted_plaintext ) && $decrypted_plaintext === $plaintext_token,
		);

		if ( ! $results['tests']['backward_compatibility']['passed'] ) {
			$results['success'] = false;
		}

		// Test 5: Key derivation.
		$key                                = self::get_encryption_key();
		$results['tests']['key_derivation'] = array(
			'passed'          => ! is_wp_error( $key ) && strlen( $key ) === 32,
			'key_length'      => is_string( $key ) ? strlen( $key ) : 0,
			'expected_length' => 32,
		);

		if ( ! $results['tests']['key_derivation']['passed'] ) {
			$results['success'] = false;
		}

		return $results;
	}
}
