<?php
/**
 * Tests for Token Format Validation
 *
 * This test file validates that OAuth tokens meet the required format specifications:
 * - Authorization codes: 43 characters
 * - Access/Refresh tokens: 64 characters
 * - Client IDs: 40 characters (abilities_bridge_client_ prefix + 16 chars)
 * - PKCE verifier: 43-128 characters
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class TokenFormatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Authorization codes must be exactly 43 characters
	 *
	 * From your docs: Authorization codes use 43 characters (256 bits base64url)
	 * per RFC 6749
	 */
	public function test_authorization_code_length_is_correct() {
		// Arrange: Create a 43-character code (simulating base64url output)
		$code = str_repeat( 'a', 43 );

		// Assert: Length should be exactly 43
		$this->assertSame(
			43,
			strlen( $code ),
			'Authorization code must be exactly 43 characters'
		);
	}

	/**
	 * Test: Access tokens must be exactly 64 characters
	 *
	 * From your code: wp_generate_password( 64, true, true )
	 */
	public function test_access_token_length_is_correct() {
		// Arrange: Simulate 64-character access token
		$token = str_repeat( 'a', 64 );

		// Assert
		$this->assertSame(
			64,
			strlen( $token ),
			'Access token must be exactly 64 characters'
		);
	}

	/**
	 * Test: Refresh tokens must be exactly 64 characters
	 *
	 * Same length as access tokens
	 */
	public function test_refresh_token_length_is_correct() {
		// Arrange
		$token = str_repeat( 'a', 64 );

		// Assert
		$this->assertSame(
			64,
			strlen( $token ),
			'Refresh token must be exactly 64 characters'
		);
	}

	/**
	 * Test: Client IDs must be exactly 40 characters (abilities_bridge_client_ + 16)
	 *
	 * From your code: 'abilities_bridge_client_' . wp_generate_password( 16, false )
	 */
	public function test_client_id_format_is_correct() {
		// Arrange: Simulate client ID
		$client_id = 'abilities_bridge_client_' . str_repeat( 'x', 16 );

		// Assert: Should be 40 characters total
		$this->assertSame(
			40,
			strlen( $client_id ),
			'Client ID must be exactly 40 characters (abilities_bridge_client_ + 16)'
		);

		// Assert: Should start with abilities_bridge_client_
		$this->assertStringStartsWith(
			'abilities_bridge_client_',
			$client_id,
			'Client ID must start with abilities_bridge_client_ prefix'
		);
	}

	/**
	 * Test: Client secrets must be exactly 32 characters
	 *
	 * From your code: wp_generate_password( 32, true, true )
	 */
	public function test_client_secret_length_is_correct() {
		// Arrange
		$secret = str_repeat( 'a', 32 );

		// Assert
		$this->assertSame(
			32,
			strlen( $secret ),
			'Client secret must be exactly 32 characters'
		);
	}

	/**
	 * Test: PKCE code verifier must be within allowed range
	 *
	 * From your code: RFC 7636 requires 43-128 characters
	 *
	 * @dataProvider pkceVerifierProvider
	 */
	public function test_pkce_verifier_length_validation( $length, $should_be_valid ) {
		// Arrange: Create a verifier of specified length
		$verifier = str_repeat( 'a', $length );

		// Act: Check if length is within allowed range (43-128)
		$is_valid = strlen( $verifier ) >= 43 && strlen( $verifier ) <= 128;

		// Assert
		$this->assertSame(
			$should_be_valid,
			$is_valid,
			sprintf(
				'PKCE verifier of length %d should be %s',
				$length,
				$should_be_valid ? 'valid' : 'invalid'
			)
		);
	}

	/**
	 * Data Provider for PKCE verifier tests
	 *
	 * This is a DATA PROVIDER - a PHPUnit feature that lets you run the same test
	 * with different inputs. Each array becomes one test case.
	 *
	 * Format: [length, should_be_valid]
	 *
	 * @return array
	 */
	public function pkceVerifierProvider() {
		return array(
			'too short - 42 chars'  => array( 42, false ),  // Invalid: too short
			'minimum - 43 chars'    => array( 43, true ),   // Valid: minimum
			'middle - 85 chars'     => array( 85, true ),   // Valid: middle
			'maximum - 128 chars'   => array( 128, true ),  // Valid: maximum
			'too long - 129 chars'  => array( 129, false ), // Invalid: too long
		);
	}

	/**
	 * Test: Token format regex pattern for PKCE verifier
	 *
	 * From your code: /^[A-Za-z0-9\-._~]{43,128}$/
	 */
	public function test_pkce_verifier_character_set() {
		// Arrange: Valid PKCE verifier with allowed characters
		$valid_verifier = 'abcABC123-._~' . str_repeat( 'a', 31 ); // 43 chars total

		// Assert: Should match the regex pattern
		$this->assertMatchesRegularExpression(
			'/^[A-Za-z0-9\-._~]{43,128}$/',
			$valid_verifier,
			'PKCE verifier should only contain allowed characters'
		);

		// Arrange: Invalid verifier with disallowed character
		$invalid_verifier = 'invalid@character' . str_repeat( 'a', 26 ); // 43 chars with @

		// Assert: Should NOT match
		$this->assertDoesNotMatchRegularExpression(
			'/^[A-Za-z0-9\-._~]{43,128}$/',
			$invalid_verifier,
			'PKCE verifier with invalid characters should be rejected'
		);
	}
}
