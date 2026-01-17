<?php
/**
 * Tests for Token Encryption
 *
 * These are SECURITY-CRITICAL tests! The encryption class protects OAuth tokens at rest.
 * We test:
 * - Encryption/decryption roundtrip
 * - Error handling for invalid inputs
 * - Backward compatibility with plaintext tokens
 * - Cryptographic security properties
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Abilities_Bridge_Token_Encryption;

class TokenEncryptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WordPress translation functions
		Functions\when( '__' )->returnArg();

		// Define WordPress security salts (required for encryption key derivation)
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key-for-phpunit-tests-random-string-12345' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-phpunit-67890' );
		}
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'test-logged-in-key-for-phpunit-abcdef' );
		}
		if ( ! defined( 'NONCE_KEY' ) ) {
			define( 'NONCE_KEY', 'test-nonce-key-for-phpunit-testing-xyz789' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Encryption and decryption roundtrip should return original token
	 *
	 * This is the MOST IMPORTANT test - if encryption/decryption doesn't work,
	 * nothing works!
	 */
	public function test_encrypt_decrypt_roundtrip() {
		// Arrange: Create a test token
		$original_token = 'test_access_token_12345_abcdef_xyz';

		// Act: Encrypt the token
		$encrypted = Abilities_Bridge_Token_Encryption::encrypt( $original_token );

		// Assert: Encryption should succeed
		$this->assertIsString( $encrypted, 'Encryption should return a string' );
		$this->assertFalse( is_wp_error( $encrypted ), 'Encryption should not return WP_Error' );

		// Act: Decrypt the token
		$decrypted = Abilities_Bridge_Token_Encryption::decrypt( $encrypted );

		// Assert: Decryption should succeed and match original
		$this->assertIsString( $decrypted, 'Decryption should return a string' );
		$this->assertFalse( is_wp_error( $decrypted ), 'Decryption should not return WP_Error' );
		$this->assertSame(
			$original_token,
			$decrypted,
			'Decrypted token must exactly match original token'
		);
	}

	/**
	 * Test: Encrypted token should have version prefix
	 *
	 * The version prefix (v1:) allows for future encryption algorithm upgrades
	 */
	public function test_encrypted_token_has_version_prefix() {
		// Arrange
		$token = 'test_token_123';

		// Act
		$encrypted = Abilities_Bridge_Token_Encryption::encrypt( $token );

		// Assert: Should start with 'v1:'
		$this->assertStringStartsWith(
			'v1:',
			$encrypted,
			'Encrypted token must have version prefix for future compatibility'
		);
	}

	/**
	 * Test: Encrypting the same token twice should produce different ciphertexts
	 *
	 * This tests that we're using random IVs correctly. If we get the same
	 * ciphertext twice, it means IVs are not random (SECURITY BUG!)
	 */
	public function test_encryption_uses_random_iv() {
		// Arrange: Same token
		$token = 'same_token_encrypted_twice';

		// Act: Encrypt twice
		$encrypted1 = Abilities_Bridge_Token_Encryption::encrypt( $token );
		$encrypted2 = Abilities_Bridge_Token_Encryption::encrypt( $token );

		// Assert: Ciphertexts should be different (due to random IVs)
		$this->assertNotSame(
			$encrypted1,
			$encrypted2,
			'Encrypting the same token twice should produce different ciphertexts (random IVs)'
		);

		// But both should decrypt to the same value
		$decrypted1 = Abilities_Bridge_Token_Encryption::decrypt( $encrypted1 );
		$decrypted2 = Abilities_Bridge_Token_Encryption::decrypt( $encrypted2 );
		$this->assertSame( $token, $decrypted1 );
		$this->assertSame( $token, $decrypted2 );
	}

	/**
	 * Test: Empty input should return WP_Error
	 *
	 * Testing error conditions is as important as testing success!
	 */
	public function test_encrypt_empty_input_returns_error() {
		// Act: Try to encrypt empty string
		$result = Abilities_Bridge_Token_Encryption::encrypt( '' );

		// Assert: Should return WP_Error
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Encrypting empty string should return WP_Error'
		);
		$this->assertSame(
			'invalid_input',
			$result->get_error_code()
		);
	}

	/**
	 * Test: Decrypting empty input should return WP_Error
	 */
	public function test_decrypt_empty_input_returns_error() {
		// Act
		$result = Abilities_Bridge_Token_Encryption::decrypt( '' );

		// Assert
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	/**
	 * Test: Decrypting corrupted data should return WP_Error
	 *
	 * Security note: We must fail gracefully on corrupted/tampered data
	 */
	public function test_decrypt_corrupted_data_returns_error() {
		// Arrange: Create fake corrupted encrypted data
		$corrupted = 'v1:' . base64_encode( 'corrupted_data_xxx' );

		// Act
		$result = Abilities_Bridge_Token_Encryption::decrypt( $corrupted );

		// Assert: Should fail gracefully with WP_Error
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Decrypting corrupted data should return WP_Error, not crash'
		);
	}

	/**
	 * Test: Backward compatibility - plaintext tokens should pass through
	 *
	 * For gradual migration, the system accepts old plaintext tokens
	 * (tokens without 'v1:' prefix)
	 */
	public function test_decrypt_plaintext_token_returns_as_is() {
		// Arrange: Plaintext token (no v1: prefix)
		$plaintext_token = 'old_plaintext_token_from_v1_0';

		// Act: "Decrypt" it (should return as-is)
		$result = Abilities_Bridge_Token_Encryption::decrypt( $plaintext_token );

		// Assert: Should return the same token unchanged
		$this->assertSame(
			$plaintext_token,
			$result,
			'Plaintext tokens should pass through unchanged for backward compatibility'
		);
	}

	/**
	 * Test: Decrypting data that's too short should fail
	 *
	 * Minimum encrypted token size = IV (16 bytes) + encrypted data (at least 1 byte)
	 */
	public function test_decrypt_too_short_data_returns_error() {
		// Arrange: Data shorter than IV length
		$too_short = 'v1:' . base64_encode( 'short' ); // Only 5 bytes, need 16+

		// Act
		$result = Abilities_Bridge_Token_Encryption::decrypt( $too_short );

		// Assert
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'decryption_failed', $result->get_error_code() );
	}

	/**
	 * Test: Invalid base64 should return error
	 *
	 * Security: Malformed input should fail gracefully
	 */
	public function test_decrypt_invalid_base64_returns_error() {
		// Arrange: Invalid base64 (contains characters not in base64 alphabet)
		$invalid_base64 = 'v1:@@@invalid###base64***';

		// Act
		$result = Abilities_Bridge_Token_Encryption::decrypt( $invalid_base64 );

		// Assert
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'decryption_failed', $result->get_error_code() );
	}

	/**
	 * Test: Encryption failures return WP_Error, never plaintext fallback
	 *
	 * SECURITY-CRITICAL: This test verifies that encryption failures are returned
	 * as WP_Error objects and NOT silently converted to plaintext storage.
	 * This prevents a dangerous security vulnerability where encryption failures
	 * could result in tokens being stored in plaintext in the database.
	 */
	public function test_encryption_failure_returns_error_not_plaintext() {
		// Arrange: Empty input (should trigger encryption failure)
		$input = '';

		// Act: Attempt to encrypt empty input
		$result = Abilities_Bridge_Token_Encryption::encrypt( $input );

		// Assert: Must return WP_Error, NOT plaintext
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'SECURITY: Encryption failures MUST return WP_Error, never fall back to plaintext'
		);

		$this->assertSame(
			'invalid_input',
			$result->get_error_code(),
			'Empty input should trigger invalid_input error'
		);

		// CRITICAL: Verify the result is NOT the original plaintext
		$this->assertNotSame(
			$input,
			$result,
			'SECURITY VIOLATION: Encryption failure returned plaintext instead of WP_Error!'
		);
	}
}
