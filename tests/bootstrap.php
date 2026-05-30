<?php
/**
 * PHPUnit bootstrap for Abilities Bridge.
 *
 * @package Abilities_Bridge
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ABILITIES_BRIDGE_VERSION' ) ) {
	define( 'ABILITIES_BRIDGE_VERSION', '1.3.0' );
}

if ( ! defined( 'ABILITIES_BRIDGE_PLUGIN_DIR' ) ) {
	define( 'ABILITIES_BRIDGE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ABILITIES_BRIDGE_PLUGIN_URL' ) ) {
	define( 'ABILITIES_BRIDGE_PLUGIN_URL', 'https://example.test/wp-content/plugins/abilities-bridge/' );
}

if ( ! defined( 'ABILITIES_BRIDGE_CONTENT_DIR' ) ) {
	define( 'ABILITIES_BRIDGE_CONTENT_DIR', sys_get_temp_dir() . '/abilities-bridge-test/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}

if ( ! class_exists( 'WP_Hook' ) ) {
	/**
	 * Minimal WP_Hook test double for read-only callback iteration tests.
	 */
	class WP_Hook {
		/**
		 * Registered callbacks.
		 *
		 * @var array
		 */
		public $callbacks = array();
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error test double.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/class-abilities-bridge-integrations-page.php';
