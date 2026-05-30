<?php
/**
 * Tests for the Integrations page discovery contract.
 *
 * @package Abilities_Bridge
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Integration discovery tests.
 */
final class IntegrationsPageTest extends TestCase {

	/**
	 * Option values returned by get_option().
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Set up test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wp_filter']         = array();
		$GLOBALS['wp_current_filter'] = array();
		$this->options                = array(
			'abilities_bridge_approved_integrations' => array(),
		);

		$this->stub_wp_functions();
	}

	/**
	 * Tear down test state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wp_filter'], $GLOBALS['wp_current_filter'] );
		parent::tearDown();
	}

	/**
	 * A throwing callback does not erase earlier integrations or block later callbacks.
	 *
	 * @return void
	 */
	public function test_safe_dispatcher_continues_after_throwing_callback(): void {
		$this->register_callback(
			10,
			'first',
			function ( $integrations ) {
				$integrations['first-plugin'] = $this->integration( 'first-plugin', 'First Plugin' );
				return $integrations;
			}
		);

		$this->register_callback(
			20,
			'broken',
			function () {
				throw new RuntimeException( 'Nope.' );
			}
		);

		$this->register_callback(
			30,
			'second',
			function ( $integrations ) {
				$integrations['second-plugin'] = $this->integration( 'second-plugin', 'Second Plugin' );
				return $integrations;
			}
		);

		$integrations = Abilities_Bridge_Integrations_Page::discover_integrations();

		$this->assertArrayHasKey( 'first-plugin', $integrations );
		$this->assertArrayHasKey( 'second-plugin', $integrations );
	}

	/**
	 * First valid integration wins duplicate slug collisions.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_first_wins(): void {
		$this->register_callback(
			10,
			'first',
			function ( $integrations ) {
				$integrations['same-plugin'] = $this->integration( 'same-plugin', 'First Plugin' );
				return $integrations;
			}
		);

		$this->register_callback(
			20,
			'duplicate',
			function ( $integrations ) {
				$integrations['same-plugin'] = $this->integration( 'same-plugin', 'Duplicate Plugin' );
				return $integrations;
			}
		);

		$integrations = Abilities_Bridge_Integrations_Page::discover_integrations();

		$this->assertSame( 'First Plugin', $integrations['same-plugin']['plugin_name'] );
	}

	/**
	 * Unsafe settings URLs are rejected while the card still renders.
	 *
	 * @return void
	 */
	public function test_unsafe_settings_url_is_rejected(): void {
		$this->register_callback(
			10,
			'unsafe_url',
			function ( $integrations ) {
				$integration                         = $this->integration( 'unsafe-url-plugin', 'Unsafe URL Plugin' );
				$integration['settings_admin_page'] = 'javascript:alert(1)';
				$integrations['unsafe-url-plugin']  = $integration;
				return $integrations;
			}
		);

		$integrations = Abilities_Bridge_Integrations_Page::discover_integrations();

		$this->assertArrayHasKey( 'unsafe-url-plugin', $integrations );
		$this->assertSame( '', $integrations['unsafe-url-plugin']['settings_url'] );
	}

	/**
	 * Integration caps are enforced.
	 *
	 * @return void
	 */
	public function test_integration_cap_is_enforced(): void {
		$this->register_callback(
			10,
			'too_many',
			function () {
				$integrations = array();
				for ( $i = 1; $i <= 51; $i++ ) {
					$slug                  = 'plugin-' . $i;
					$integrations[ $slug ] = $this->integration( $slug, 'Plugin ' . $i );
				}
				return $integrations;
			}
		);

		$integrations = Abilities_Bridge_Integrations_Page::discover_integrations();

		$this->assertCount( 50, $integrations );
		$this->assertArrayNotHasKey( 'plugin-51', $integrations );
	}

	/**
	 * Register a test callback in the WP_Hook double.
	 *
	 * @param int      $priority Priority.
	 * @param string   $id       Callback id.
	 * @param callable $callback Callback.
	 * @return void
	 */
	private function register_callback( $priority, $id, $callback ): void {
		if ( empty( $GLOBALS['wp_filter'][ Abilities_Bridge_Integrations_Page::FILTER_NAME ] ) ) {
			$GLOBALS['wp_filter'][ Abilities_Bridge_Integrations_Page::FILTER_NAME ] = new WP_Hook();
		}

		$GLOBALS['wp_filter'][ Abilities_Bridge_Integrations_Page::FILTER_NAME ]->callbacks[ $priority ][ $id ] = array(
			'function'      => $callback,
			'accepted_args' => 1,
		);
	}

	/**
	 * Build a valid integration.
	 *
	 * @param string $slug Slug.
	 * @param string $name Name.
	 * @return array
	 */
	private function integration( $slug, $name ): array {
		return array(
			'plugin_slug'         => $slug,
			'plugin_name'         => $name,
			'plugin_description'  => 'Test integration.',
			'plugin_version'      => '1.0.0',
			'integration_enabled' => true,
			'settings_admin_page' => 'test-settings',
			'abilities'           => array(
				array(
					'name'        => $slug . '/read',
					'description' => 'Read test data.',
					'risk_level'  => 'low',
					'permissions' => array(
						'max_per_day'  => 10,
						'max_per_hour' => 5,
					),
				),
			),
			'approval_profiles'   => array(
				'all' => array(
					'label' => 'Approve All',
				),
			),
		);
	}

	/**
	 * Stub WordPress functions used during discovery.
	 *
	 * @return void
	 */
	private function stub_wp_functions(): void {
		Functions\when( '__' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( $value ) {
				return $value;
			}
		);
		Functions\when( '_doing_it_wrong' )->justReturn( null );
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
		);
		Functions\when( 'sanitize_html_class' )->alias(
			function ( $value ) {
				return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $value ) {
				return strip_tags( (string) $value );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url ) {
				return parse_url( $url );
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $query, $url ) {
				return $url . '?' . http_build_query( $query );
			}
		);
		Functions\when( 'wp_list_pluck' )->alias(
			function ( $list, $field ) {
				return array_map(
					function ( $item ) use ( $field ) {
						return isset( $item[ $field ] ) ? $item[ $field ] : null;
					},
					$list
				);
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}
}
