<?php
/**
 * Tests for Token Expiration Logic
 *
 * This is your first test file! It tests the basic logic of whether tokens are expired.
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test class for token expiration logic
 *
 * Every test class must:
 * 1. Extend PHPUnit\Framework\TestCase
 * 2. Have a name ending with "Test" (PHPUnit convention)
 * 3. Contain methods starting with "test_"
 */
class TokenExpirationTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * This method runs BEFORE each test method.
	 * Use it to set up Brain Monkey and mock WordPress functions.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WordPress translation function (used in error messages)
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Clean up after each test
	 *
	 * This method runs AFTER each test method.
	 * It tears down Brain Monkey and Mockery.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Expired tokens should be identified as expired
	 *
	 * Test naming convention: test_<what_you're_testing>_<expected_behavior>
	 * Example: test_expired_token_is_detected
	 */
	public function test_expired_token_is_detected() {
		// Arrange: Set up test data
		// Create a token that expired 1 hour ago
		$expired_time = time() - 3600; // 3600 seconds = 1 hour

		// Act: Perform the action we're testing
		// Check if the current time is greater than expiration time
		$is_expired = time() > $expired_time;

		// Assert: Verify the result is what we expect
		$this->assertTrue(
			$is_expired,
			'Token that expired 1 hour ago should be detected as expired'
		);
	}

	/**
	 * Test: Valid tokens (not expired) should pass validation
	 */
	public function test_valid_token_is_not_expired() {
		// Arrange: Create a token that expires 1 hour from now
		$future_time = time() + 3600;

		// Act: Check if expired
		$is_expired = time() > $future_time;

		// Assert: Should NOT be expired
		$this->assertFalse(
			$is_expired,
			'Token that expires in the future should not be expired'
		);
	}

	/**
	 * Test: Edge case - token expiring exactly now
	 *
	 * Edge cases are important! What happens when expires_at === time()?
	 */
	public function test_token_expiring_right_now() {
		// Arrange: Token expires exactly now
		$current_time = time();
		$expires_at = $current_time;

		// Act: Standard expiration check (time() > expires_at)
		$is_expired = $current_time > $expires_at;

		// Assert: Should NOT be expired (not greater than, equal to)
		// This means if a token expires at 10:00:00 and you check at 10:00:00,
		// it's still valid for that exact second
		$this->assertFalse(
			$is_expired,
			'Token should still be valid in the exact second it expires'
		);
	}

	/**
	 * BONUS Test: Token expired 1 second ago
	 *
	 * Testing boundary conditions is crucial for security code!
	 */
	public function test_token_expired_one_second_ago() {
		// Arrange: Token expired just 1 second ago
		$expires_at = time() - 1;

		// Act
		$is_expired = time() > $expires_at;

		// Assert: Even 1 second past expiration should be caught
		$this->assertTrue(
			$is_expired,
			'Token expired even 1 second ago should be rejected'
		);
	}
}
