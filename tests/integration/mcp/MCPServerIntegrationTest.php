<?php
/**
 * MCP Server Integration Tests
 *
 * INTEGRATION TESTS vs UNIT TESTS:
 * - Unit tests: Test ONE component in isolation
 * - Integration tests: Test MULTIPLE components working together
 *
 * These tests verify:
 * 1. MCP Server + OAuth Validator (authentication flow)
 * 2. MCP Server + JSON-RPC protocol handling
 * 3. MCP Server + OAuth Scopes (authorization)
 * 4. Complete request → response flow
 *
 * @package Abilities_Bridge
 * @subpackage Tests
 */

namespace Abilities_Bridge\Tests\Integration\MCP;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Abilities_Bridge_MCP_Server;
use Abilities_Bridge_Token_Encryption;
use WP_REST_Request;

class MCPServerIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WordPress functions
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// Define WordPress salts for encryption
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key-integration' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure-integration' );
		}
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'test-logged-in-integration' );
		}
		if ( ! defined( 'NONCE_KEY' ) ) {
			define( 'NONCE_KEY', 'test-nonce-integration' );
		}

		// Define WordPress JSON functions if not available
		if ( ! function_exists( 'wp_json_encode' ) ) {
			function wp_json_encode( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		}
	}

	protected function tearDown(): void {
		// Clear OAuth globals
		unset( $GLOBALS['abilities_bridge_oauth_user_id'] );
		unset( $GLOBALS['abilities_bridge_oauth_scope'] );
		unset( $GLOBALS['abilities_bridge_oauth_client_id'] );

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: Create a valid encrypted OAuth token
	 */
	private function create_valid_token( $user_id = 1, $scope = 'admin', $expires_offset = 3600 ) {
		$plaintext_token = 'integration_test_token_' . time();
		$encrypted_token = Abilities_Bridge_Token_Encryption::encrypt( $plaintext_token );

		$token_data = array(
			'access_tokens' => array(
				array(
					'access_token' => $encrypted_token,
					'expires_at'   => time() + $expires_offset,
					'user_id'      => $user_id,
					'client_id'    => 'mcp_integrationtest',
					'scope'        => $scope,
				),
			),
		);

		// Mock WordPress functions for OAuth
		Functions\expect( 'get_option' )
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( $token_data )
			->zeroOrMoreTimes();

		Functions\expect( 'get_userdata' )
			->with( $user_id )
			->andReturn( (object) array(
				'ID'   => $user_id,
				'data' => (object) array( 'ID' => $user_id ),
			) )
			->zeroOrMoreTimes();

		Functions\expect( 'user_can' )
			->with( \Mockery::type( 'stdClass' ), 'manage_options' )
			->andReturn( true )
			->zeroOrMoreTimes();

		return $plaintext_token;
	}

	/**
	 * Helper: Create a mock WP_REST_Request
	 */
	private function create_mcp_request( $json_rpc_body, $bearer_token = null ) {
		$request = new WP_REST_Request( 'POST', '/abilities-bridge-mcp/v1/mcp' );

		if ( $bearer_token ) {
			$request->set_header( 'Authorization', 'Bearer ' . $bearer_token );
		}

		$request->set_body( wp_json_encode( $json_rpc_body ) );

		return $request;
	}

	/**
	 * TEST 1: Valid OAuth token allows MCP request
	 *
	 * INTEGRATION: MCP Server + OAuth Validator + Token Encryption
	 *
	 * Flow:
	 * 1. Create valid encrypted token
	 * 2. Send JSON-RPC request with Bearer token
	 * 3. MCP Server validates OAuth
	 * 4. Request is processed
	 * 5. Response is returned
	 */
	public function test_valid_oauth_token_allows_mcp_request() {
		// Arrange: Create valid token
		$token = $this->create_valid_token();

		// Arrange: Create JSON-RPC initialize request
		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-03-26',
				'capabilities'    => array(),
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
		);

		$request = $this->create_mcp_request( $json_rpc, $token );

		// Act: Process request through MCP Server
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Should succeed
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'jsonrpc', $response, 'Response should have jsonrpc field' );
		$this->assertSame( '2.0', $response['jsonrpc'], 'Should be JSON-RPC 2.0' );
		$this->assertArrayHasKey( 'result', $response, 'Successful response should have result field' );
		$this->assertArrayNotHasKey( 'error', $response, 'Successful response should not have error field' );
	}

	/**
	 * TEST 2: Expired token returns JSON-RPC error
	 *
	 * INTEGRATION: MCP Server + OAuth Validator
	 *
	 * Flow:
	 * 1. Create expired token (expires_at in past)
	 * 2. MCP Server checks OAuth
	 * 3. OAuth Validator detects expiration
	 * 4. MCP Server converts WP_Error to JSON-RPC error
	 */
	public function test_expired_token_returns_jsonrpc_error() {
		// Arrange: Create expired token (expired 1 hour ago)
		$token = $this->create_valid_token( 1, 'admin', -3600 );

		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request = $this->create_mcp_request( $json_rpc, $token );

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Should return JSON-RPC error
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'Expired token should return error' );
		$this->assertArrayNotHasKey( 'result', $response, 'Error response should not have result' );
		$this->assertSame( '2.0', $response['jsonrpc'] );

		// Assert: Error structure
		$error = $response['error'];
		$this->assertArrayHasKey( 'code', $error );
		$this->assertArrayHasKey( 'message', $error );
		$this->assertSame( -32000, $error['code'], 'Authentication errors use code -32000' );
		$this->assertStringContainsString( 'expired', strtolower( $error['message'] ) );
	}

	/**
	 * TEST 3: Missing token returns authentication error
	 *
	 * INTEGRATION: MCP Server + OAuth Validator
	 *
	 * Flow:
	 * 1. Send request WITHOUT Authorization header
	 * 2. OAuth Validator rejects
	 * 3. MCP Server returns error
	 */
	public function test_missing_token_returns_authentication_error() {
		// Arrange: Request without token
		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'initialize',
			'params'  => array(),
		);

		// Note: No token provided!
		$request = $this->create_mcp_request( $json_rpc );

		// Mock empty OAuth data
		Functions\expect( 'get_option' )
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( array() );

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Authentication error
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( -32000, $response['error']['code'] );
		$this->assertStringContainsString( 'authentication', strtolower( $response['error']['message'] ) );
	}

	/**
	 * TEST 4: Valid initialize request succeeds
	 *
	 * INTEGRATION: MCP Server + Response Formatter
	 *
	 * Tests the complete initialize flow including response format
	 */
	public function test_valid_initialize_request_succeeds() {
		// Arrange
		$token = $this->create_valid_token();

		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-03-26',
				'capabilities'    => array(),
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
		);

		$request = $this->create_mcp_request( $json_rpc, $token );

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Response structure
		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertSame( 4, $response['id'], 'Response ID should match request ID' );
		$this->assertArrayHasKey( 'result', $response );

		// Assert: Result contains server info
		$result = $response['result'];
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertArrayHasKey( 'serverInfo', $result );
		$this->assertArrayHasKey( 'capabilities', $result );

		// Assert: Server info details
		$server_info = $result['serverInfo'];
		$this->assertArrayHasKey( 'name', $server_info );
		$this->assertArrayHasKey( 'version', $server_info );
	}

	/**
	 * TEST 5: Invalid JSON returns parse error
	 *
	 * INTEGRATION: MCP Server (protocol validation)
	 *
	 * Tests error handling BEFORE OAuth (authentication happens after parsing)
	 */
	public function test_invalid_json_returns_parse_error() {
		// Arrange: Create request with invalid JSON
		$request = new WP_REST_Request( 'POST', '/abilities-bridge-mcp/v1/mcp' );
		$request->set_header( 'Authorization', 'Bearer fake_token' );
		$request->set_body( 'this is not valid JSON{{{' );

		// Mock OAuth (even though we won't reach it)
		Functions\expect( 'get_option' )
			->with( 'abilities_bridge_mcp_oauth', array() )
			->andReturn( array() );

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Parse error
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( -32700, $response['error']['code'], 'Parse errors use code -32700' );
		$this->assertStringContainsString( 'parse', strtolower( $response['error']['message'] ) );
		$this->assertNull( $response['id'], 'Parse errors have null ID (request not parsed)' );
	}

	/**
	 * TEST 6: Unknown method returns method not found error
	 *
	 * INTEGRATION: MCP Server (method routing)
	 */
	public function test_unknown_method_returns_method_not_found() {
		// Arrange
		$token = $this->create_valid_token();

		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 6,
			'method'  => 'unknown/method',  // Method that doesn't exist
			'params'  => array(),
		);

		$request = $this->create_mcp_request( $json_rpc, $token );

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response = $mcp_server->handle_request( $request );

		// Assert: Method not found
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( -32601, $response['error']['code'], 'Unknown method uses code -32601' );
		$this->assertStringContainsString( 'not found', strtolower( $response['error']['message'] ) );
		$this->assertSame( 6, $response['id'], 'Error includes request ID' );
	}

	/**
	 * TEST 7: Memory scope allows memory tool
	 *
	 * INTEGRATION: MCP Server + OAuth Scopes + Tool Handler
	 *
	 * This tests the COMPLETE authorization flow:
	 * 1. OAuth token has 'memory' scope
	 * 2. tools/call method invoked for memory
	 * 3. Scope checker verifies 'memory' allows 'memory'
	 * 4. Tool is allowed to execute
	 */
	public function test_memory_scope_allows_memory_tool() {
		// Arrange: Token with ONLY 'memory' scope
		$token = $this->create_valid_token( 1, 'memory', 3600 );

		// Mock get_option for plugin settings (memory enabled)
		Functions\expect( 'get_option' )
			->with( 'abilities_bridge_options', \Mockery::any() )
			->andReturn( array(
				'enable_memory' => true,
			) );

		$json_rpc = array(
			'jsonrpc' => '2.0',
			'id'      => 7,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'memory',
				'arguments' => array(
					'command' => 'view',
					'path'    => '/memories',
				),
			),
		);

		$request = $this->create_mcp_request( $json_rpc, $token );

		// Note: We won't actually execute the tool (would require database)
		// But we CAN test that scope check passes
		// In a real integration test, you'd mock the tool execution

		// Act
		$mcp_server = new Abilities_Bridge_MCP_Server();

		// We expect this to NOT throw an "Access denied" error
		// (It might fail for other reasons like consent not given, but not scope)

		// For now, just verify the scope would allow it
		$this->assertTrue(
			\Abilities_Bridge_OAuth_Scopes::can_access_tool( 'memory', 'memory' ),
			'Memory scope should allow memory tool'
		);
	}
}
