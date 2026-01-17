<?php
/**
 * PHPUnit Bootstrap File
 *
 * This file is loaded before any tests run. It sets up the testing environment:
 * - Loads Composer dependencies (PHPUnit, Brain Monkey, etc.)
 * - Sets up Brain Monkey for mocking WordPress functions
 * - Loads the plugin classes we want to test
 *
 * @package Abilities_Bridge
 */

// Load Composer autoloader (includes PHPUnit, Brain Monkey, Mockery)
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Set up Brain Monkey - this allows us to mock WordPress functions
// Brain Monkey must be set up before tests, but individual tests will call
// Brain\Monkey\setUp() and Brain\Monkey\tearDown() in their setUp/tearDown methods
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/api.php';

/**
 * Mock WordPress core functions that our code depends on
 *
 * Since we're testing without WordPress loaded, we need to provide
 * basic implementations of common WordPress functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Load WP_Error class
 *
 * Many WordPress plugins use WP_Error for error handling.
 * We provide a simple implementation for testing.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $errors = array();
		private $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return empty( $codes ) ? '' : $codes[0];
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			$messages = $this->errors[ $code ] ?? array();
			return empty( $messages ) ? '' : $messages[0];
		}

		public function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) {
				return array_values( array_merge( ...$this->errors ) );
			}
			return $this->errors[ $code ] ?? array();
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

/**
 * Helper function to check if something is a WP_Error
 */
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Mock is_user_logged_in for testing
 */
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return false; // Default to not logged in for OAuth testing
	}
}

/**
 * Mock get_site_url for testing
 */
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return 'http://localhost';
	}
}

/**
 * Mock rest_ensure_response for testing
 */
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		// Simply return the response as-is for testing
		// In real WordPress, this wraps in WP_REST_Response
		return $response;
	}
}

/**
 * Mock WP_REST_Request for testing
 *
 * Simplified version of WordPress REST API request object
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $headers = array();
		private $body = '';
		private $method = 'GET';
		private $route = '';

		public function __construct( $method = 'GET', $route = '' ) {
			$this->method = $method;
			$this->route = $route;
		}

		public function set_header( $key, $value ) {
			// Store headers with lowercase keys for case-insensitive lookup
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( $key ) {
			// Lookup headers case-insensitively
			$key = strtolower( $key );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_route() {
			return $this->route;
		}

		public function get_json_params() {
			return json_decode( $this->body, true );
		}
	}
}

/**
 * Load plugin files that we want to test
 *
 * Load these in dependency order (classes that depend on others come after)
 */

// OAuth Components
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-token-encryption.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-scopes.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-client-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-token-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-authorization-code.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-oauth-token-handler.php';

// MCP Server Components (for integration tests)
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-mcp-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-abilities-bridge-mcp-server.php';

// Output a friendly message
echo "\n🧪 PHPUnit Bootstrap Complete\n";
echo "   ✓ Composer autoloader loaded\n";
echo "   ✓ Brain Monkey available for mocking\n";
echo "   ✓ WP_Error class defined\n";
echo "   ✓ Plugin classes loaded\n";
echo "   Ready to run tests!\n\n";
