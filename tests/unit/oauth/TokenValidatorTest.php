<?php
/**
 * Tests for OAuth Token Validator
 *
 * The token validator is the GATEKEEPER of your API - if it fails,
 * unauthorized access could occur. These tests are critical!
 *
 * We test:
 * - Valid token acceptance
 * - Expired token rejection
 * - Invalid token rejection
 * - Timing-safe comparison
 * - Scope validation
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Abilities_Bridge_OAuth_Token_Validator;
use Abilities_Bridge_Token_Encryption;

class TokenValidatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WordPress functions
		Functions\when( '__' )->returnArg();

		// Define encryption keys (needed for token encryption/decryption)
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure' );
		}
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'test-logged-in' );
		}
		if ( ! defined( 'NONCE_KEY' ) ) {
			define( 'NONCE_KEY', 'test-nonce' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Valid unexpired token should be accepted
	 *
	 * This is the "happy path" - everything works correctly
	 */
	public function test_valid_token_is_accepted() {
		// Arrange: Create a valid token
		$plaintext_token = 'valid_access_token_12345';
		$encrypted_token = Abilities_Bridge_Token_Encryption::encrypt( $plaintext_token );

		// Mock stored token data (no scope - will use default)
		$oauth_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_token,
					'expires_at'   => time() + 3600, // Expires in 1 hour
					'user_id'      => 1,
					// No scope specified - will use default scope for backward compatibility
				),
			),
		);

		// Mock get_option to return our test data
		Functions\expect( 'get_option' )
			->once()
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( $oauth_data );

		// Note: Abilities_Bridge_OAuth_Scopes class will be called directly
		// (it's loaded in bootstrap.php, no mocking needed for simple scope validation)

		// Act: Validate the token
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $plaintext_token );

		// Assert: Should return true
		$this->assertTrue(
			$result,
			'Valid unexpired token should be accepted'
		);
	}

	/**
	 * Test: Expired token should be rejected
	 *
	 * SECURITY CRITICAL: Expired tokens must be rejected!
	 */
	public function test_expired_token_is_rejected() {
		// Arrange: Create an expired token
		$plaintext_token = 'expired_token_12345';
		$encrypted_token = Abilities_Bridge_Token_Encryption::encrypt( $plaintext_token );

		// Token expired 1 hour ago
		$oauth_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_token,
					'expires_at'   => time() - 3600, // Expired!
					'user_id'      => 1,
					'scope'        => 'mcp:memory',
				),
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( $oauth_data );

		// Act
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $plaintext_token );

		// Assert: Should return WP_Error with 'token_expired' code
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Expired token must be rejected'
		);
		$this->assertSame(
			'token_expired',
			$result->get_error_code(),
			'Error code should be token_expired'
		);
	}

	/**
	 * Test: Invalid (non-existent) token should be rejected
	 */
	public function test_invalid_token_is_rejected() {
		// Arrange: Try to validate a token that doesn't exist in storage
		$nonexistent_token = 'this_token_does_not_exist';

		// Mock empty token storage
		$oauth_data = array(
			'access_tokens' => array(),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( $oauth_data );

		// Act
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $nonexistent_token );

		// Assert: Should return WP_Error
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Non-existent token should be rejected'
		);
	}

	/**
	 * Test: Token with missing expires_at should still work
	 *
	 * Backward compatibility: Old tokens might not have expires_at field
	 */
	public function test_token_without_expires_at_is_accepted() {
		// Arrange
		$plaintext_token = 'legacy_token_no_expiration';
		$encrypted_token = Abilities_Bridge_Token_Encryption::encrypt( $plaintext_token );

		$oauth_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_token,
					// Note: No expires_at field!
					'user_id'      => 1,
				),
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->andReturn( $oauth_data );

		// Note: Abilities_Bridge_OAuth_Scopes::get_default_scope() will be called directly

		// Act
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $plaintext_token );

		// Assert: Should be accepted (backward compatibility)
		$this->assertTrue(
			$result,
			'Token without expires_at should be accepted for backward compatibility'
		);
	}

	/**
	 * Test: Wrong token value should be rejected (tests timing-safe comparison)
	 *
	 * The validator uses hash_equals() to prevent timing attacks.
	 * This test ensures different tokens are rejected.
	 */
	public function test_wrong_token_value_is_rejected() {
		// Arrange: Store one token, try to validate a different one
		$stored_token = 'correct_token_abc123';
		$wrong_token  = 'wrong_token_xyz789';

		$encrypted_stored = Abilities_Bridge_Token_Encryption::encrypt( $stored_token );

		$oauth_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_stored,
					'expires_at'   => time() + 3600,
					'user_id'      => 1,
				),
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->andReturn( $oauth_data );

		// Act: Try to validate with wrong token
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $wrong_token );

		// Assert: Should be rejected
		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Wrong token value should be rejected (hash_equals should catch this)'
		);
	}

	/**
	 * Test: Token with invalid scope should be rejected
	 *
	 * SECURITY: Tokens with malformed scopes must be rejected
	 */
	public function test_token_with_invalid_scope_is_rejected() {
		// Arrange
		$plaintext_token = 'token_with_bad_scope';
		$encrypted_token = Abilities_Bridge_Token_Encryption::encrypt( $plaintext_token );

		$oauth_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_token,
					'expires_at'   => time() + 3600,
					'user_id'      => 1,
					'scope'        => 'invalid:malformed:scope',
				),
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->andReturn( $oauth_data );

		// Note: Abilities_Bridge_OAuth_Scopes::is_valid_scope() will be called directly
		// It should return false for this malformed scope

		// Act
		$result = Abilities_Bridge_OAuth_Token_Validator::validate_oauth_token( $plaintext_token );

		// Assert: Should be rejected
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}
}
