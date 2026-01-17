<?php
/**
 * Tests for OAuth Client Manager
 *
 * The client manager generates and stores OAuth client credentials.
 * These tests ensure:
 * - Credentials are generated with correct format
 * - Secrets are hashed before storage (NEVER stored in plaintext!)
 * - Revocation works correctly
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Abilities_Bridge_OAuth_Client_Manager;

class ClientManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WordPress functions
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test: Generated client ID has correct format
	 *
	 * Client IDs must:
	 * - Start with 'abilities_bridge_client_' prefix
	 * - Be exactly 40 characters total
	 */
	public function test_generate_credentials_creates_valid_client_id() {
		// Arrange: Mock WordPress functions
		Functions\expect( 'get_current_user_id' )
			->once()
			->andReturn( 1 );

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 16, false ) // Client ID: 16 chars, no special chars
			->andReturn( 'abcd1234efgh5678' );

		Functions\expect( 'wp_generate_password' )
			->once()
			->with( 32, true, true ) // Client secret: 32 chars with special
			->andReturn( 'secret32characterslong1234567' );

		Functions\expect( 'get_option' )
			->once()
			->andReturn( array() );

		Functions\expect( 'wp_hash_password' )
			->once()
			->andReturn( '$2y$10$hashedsecret...' );

		Functions\expect( 'update_option' )
			->once()
			->andReturn( true );

		// Act: Generate credentials
		$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials();

		// Assert: Client ID format
		$this->assertStringStartsWith(
			'abilities_bridge_client_',
			$credentials['client_id'],
			'Client ID must start with abilities_bridge_client_ prefix'
		);

		$this->assertSame(
			40,
			strlen( $credentials['client_id'] ),
			'Client ID must be exactly 40 characters (abilities_bridge_client_ + 16)'
		);
	}

	/**
	 * Test: Generated client secret has correct length
	 */
	public function test_generate_credentials_creates_32_char_secret() {
		// Arrange
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\expect( 'wp_generate_password' )
			->twice()
			->andReturnUsing( function( $length, $special_chars, $extra_special_chars = false ) {
				if ( $length === 16 ) {
					return str_repeat( 'x', 16 );
				}
				return str_repeat( 'a', 32 ); // 32-char secret
			} );

		Functions\expect( 'get_option' )->andReturn( array() );
		Functions\expect( 'wp_hash_password' )->andReturn( 'hashed' );
		Functions\expect( 'update_option' )->andReturn( true );

		// Act
		$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials();

		// Assert
		$this->assertSame(
			32,
			strlen( $credentials['client_secret'] ),
			'Client secret must be exactly 32 characters'
		);
	}

	/**
	 * Test: Client secret is hashed before storage
	 *
	 * SECURITY CRITICAL: Secrets must NEVER be stored in plaintext!
	 */
	public function test_generate_credentials_hashes_secret_before_storage() {
		// Arrange
		$plain_secret = 'my_plain_secret_32chars_long';

		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\expect( 'wp_generate_password' )
			->twice()
			->andReturnUsing( function( $length ) {
				return $length === 16 ? str_repeat( 'x', 16 ) : 'my_plain_secret_32chars_long';
			} );

		Functions\expect( 'get_option' )->andReturn( array() );

		// This is the critical assertion: wp_hash_password must be called!
		Functions\expect( 'wp_hash_password' )
			->once()
			->with( $plain_secret )
			->andReturn( '$2y$10$hashed_version_of_secret' );

		// Capture what gets stored
		$stored_data = null;
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing( function( $option_name, $data ) use ( &$stored_data ) {
				$stored_data = $data;
				return true;
			} );

		// Act
		$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials();

		// Assert: Returned secret is plaintext
		$this->assertSame(
			$plain_secret,
			$credentials['client_secret'],
			'Returned secret should be plaintext (shown only once)'
		);

		// Assert: Stored secret is hashed
		$client_id = $credentials['client_id'];
		$this->assertStringStartsWith(
			'$2y$',
			$stored_data['clients'][ $client_id ]['client_secret'],
			'Stored secret MUST be hashed (bcrypt format)'
		);
		$this->assertNotSame(
			$plain_secret,
			$stored_data['clients'][ $client_id ]['client_secret'],
			'Stored secret must NOT be plaintext'
		);
	}

	/**
	 * Test: Credentials include created_at timestamp
	 *
	 * This allows tracking credential age (important for our discussion
	 * about whether old credentials should expire!)
	 */
	public function test_generate_credentials_stores_created_at_timestamp() {
		// Arrange
		$before_time = time();

		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\expect( 'wp_generate_password' )->twice()->andReturn( str_repeat( 'x', 16 ), str_repeat( 'y', 32 ) );
		Functions\expect( 'get_option' )->andReturn( array() );
		Functions\expect( 'wp_hash_password' )->andReturn( 'hashed' );

		$stored_data = null;
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing( function( $option_name, $data ) use ( &$stored_data ) {
				$stored_data = $data;
				return true;
			} );

		// Act
		$credentials = Abilities_Bridge_OAuth_Client_Manager::generate_credentials();

		$after_time = time();

		// Assert: created_at exists and is recent
		$client_id = $credentials['client_id'];
		$created_at = $stored_data['clients'][ $client_id ]['created_at'];

		$this->assertIsInt( $created_at, 'created_at should be a timestamp' );
		$this->assertGreaterThanOrEqual(
			$before_time,
			$created_at,
			'created_at should be at or after test start time'
		);
		$this->assertLessThanOrEqual(
			$after_time,
			$created_at,
			'created_at should be at or before test end time'
		);
	}

	/**
	 * Test: Revoking credentials removes them from storage
	 *
	 * Important for security: users must be able to revoke compromised credentials
	 */
	public function test_revoke_credentials_removes_client() {
		// Arrange: Mock stored credentials
		$client_id = 'mcp_testclient12345';
		$oauth_data = array(
			'clients' => array(
				$client_id => array(
					'client_id'     => $client_id,
					'client_secret' => '$2y$10$hashed',
					'user_id'       => 1,
					'created_at'    => time(),
				),
			),
		);

		Functions\expect( 'get_option' )
			->once()
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( $oauth_data );

		// Capture what gets stored after revocation
		$stored_data = null;
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing( function( $option_name, $data ) use ( &$stored_data ) {
				$stored_data = $data;
				return true;
			} );

		// Act: Revoke the credentials
		$result = Abilities_Bridge_OAuth_Client_Manager::revoke_credentials( $client_id );

		// Assert: Revocation succeeded
		$this->assertTrue( $result, 'Revocation should return true' );

		// Assert: Client was removed from storage
		$this->assertArrayNotHasKey(
			$client_id,
			$stored_data['clients'],
			'Revoked client should be removed from storage'
		);
	}

	/**
	 * Test: Revoking non-existent credentials returns false
	 */
	public function test_revoke_nonexistent_credentials_returns_false() {
		// Arrange: Empty storage
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'clients' => array() ) );

		// Act: Try to revoke non-existent client
		$result = Abilities_Bridge_OAuth_Client_Manager::revoke_credentials( 'mcp_doesnotexist123' );

		// Assert: Should return false
		$this->assertFalse(
			$result,
			'Revoking non-existent credentials should return false'
		);
	}
}
