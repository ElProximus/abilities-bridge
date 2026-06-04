<?php
/**
 * Tests for MCP server authentication gating.
 *
 * Verifies that every MCP method — including the discovery methods
 * (initialize, tools/list, ping) — requires authentication, and that an
 * unauthenticated request receives an HTTP 401 with a WWW-Authenticate
 * challenge alongside the JSON-RPC -32000 error body.
 *
 * @package Abilities_Bridge
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-server.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-mcp-oauth.php';
require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-oauth-token-validator.php';

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response test double.
	 */
	class WP_REST_Response {
		/**
		 * Response payload.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		protected $status = 200;

		/**
		 * Response headers.
		 *
		 * @var array
		 */
		protected $headers = array();

		/**
		 * Constructor.
		 *
		 * @param mixed $data   Payload.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * Set the HTTP status.
		 *
		 * @param int $code Status code.
		 * @return void
		 */
		public function set_status( $code ) {
			$this->status = (int) $code;
		}

		/**
		 * Get the HTTP status.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Set a header.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Get all headers.
		 *
		 * @return array
		 */
		public function get_headers() {
			return $this->headers;
		}

		/**
		 * Get the payload.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'Abilities_Bridge_Test_MCP_Request' ) ) {
	/**
	 * Minimal WP_REST_Request test double for the MCP endpoint.
	 */
	final class Abilities_Bridge_Test_MCP_Request {
		/**
		 * Parsed JSON body.
		 *
		 * @var array
		 */
		private $body;

		/**
		 * Constructor.
		 *
		 * @param array $body JSON-RPC body.
		 */
		public function __construct( array $body ) {
			$this->body = $body;
		}

		/**
		 * Return the parsed JSON body.
		 *
		 * @return array
		 */
		public function get_json_params() {
			return $this->body;
		}

		/**
		 * Return a request header (none set in these tests).
		 *
		 * @param string $name Header name.
		 * @return string
		 */
		public function get_header( $name ) {
			return '';
		}

		/**
		 * Return a request parameter (none set in these tests).
		 *
		 * @param string $name Param name.
		 * @return null
		 */
		public function get_param( $name ) {
			return null;
		}
	}
}

/**
 * MCP server authentication gating tests.
 */
final class McpServerAuthTest extends TestCase {

	/**
	 * Set up Brain Monkey and WordPress function stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->alias(
			static function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'rest_ensure_response' )->alias(
			static function ( $data ) {
				return new WP_REST_Response( $data );
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return $url;
			}
		);

		// No authenticated user and no Bearer token → check_permission fails.
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
	}

	/**
	 * Tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every MCP method must require authentication.
	 *
	 * @return array
	 */
	public function methodProvider() {
		return array(
			'initialize' => array( 'initialize' ),
			'tools/list' => array( 'tools/list' ),
			'ping'       => array( 'ping' ),
			'tools/call' => array( 'tools/call' ),
		);
	}

	/**
	 * An unauthenticated request returns HTTP 401 + WWW-Authenticate + -32000.
	 *
	 * @dataProvider methodProvider
	 *
	 * @param string $method JSON-RPC method.
	 * @return void
	 */
	public function test_unauthenticated_request_is_challenged( $method ) {
		$server  = new Abilities_Bridge_MCP_Server();
		$request = new Abilities_Bridge_Test_MCP_Request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => array(),
			)
		);

		$response = $server->handle_request( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertSame( 401, $response->get_status(), "{$method} must be gated behind authentication" );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'WWW-Authenticate', $headers );
		$this->assertStringContainsString( 'Bearer resource_metadata=', $headers['WWW-Authenticate'] );

		$data = $response->get_data();
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( -32000, $data['error']['code'] );
	}
}
