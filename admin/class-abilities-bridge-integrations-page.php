<?php
/**
 * Integrations Page - Discover and manage plugin integrations.
 *
 * @package Abilities_Bridge
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations Page class.
 *
 * Discovers plugins that register abilities with Abilities Bridge,
 * displays their available abilities, and manages approval state.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_Integrations_Page {

	/**
	 * Initialize the integrations page.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_approve_integration', array( __CLASS__, 'ajax_approve_integration' ) );
		add_action( 'wp_ajax_abilities_bridge_revoke_integration', array( __CLASS__, 'ajax_revoke_integration' ) );
	}

	/**
	 * Add submenu page under Abilities Bridge.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'abilities-bridge',
			__( 'Integrations', 'abilities-bridge' ),
			__( 'Integrations', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-integrations',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JS assets only on the integrations page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'abilities-bridge-integrations' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'ab-integrations',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/integrations.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'ab-integrations',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/integrations.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'ab-integrations',
			'abIntegrations',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_integrations_nonce' ),
			)
		);
	}

	/**
	 * Discover available plugin integrations.
	 *
	 * Uses the filter 'abilities_bridge_plugin_integrations' for plugins to register.
	 * Also auto-detects known plugins like Beacon Send.
	 *
	 * Returns array of integrations, each with:
	 * - plugin_slug: unique identifier
	 * - plugin_name: display name
	 * - plugin_description: what the plugin does
	 * - plugin_version: version string
	 * - plugin_active: boolean
	 * - abilities: array of ability definitions (name, description, parameters, risk_level)
	 * - status: 'available' | 'approved' | 'partial'
	 *
	 * @return array Associative array of integration data keyed by plugin slug.
	 */
	public static function discover_integrations() {
		$integrations = array();

		// Auto-detect Beacon Send.
		if ( class_exists( 'BS_Abilities_Bridge' ) ) {
			$tools     = BS_Abilities_Bridge::get_tool_definitions();
			$abilities = array();

			foreach ( $tools as $tool ) {
				$abilities[] = array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => isset( $tool['parameters'] ) ? $tool['parameters'] : array(),
					'risk_level'  => self::assess_risk( $tool['name'] ),
				);
			}

			$integrations['beacon-send'] = array(
				'plugin_slug'        => 'beacon-send',
				'plugin_name'        => 'Beacon Send',
				'plugin_description' => 'AI-powered email and push notification campaigns via Brevo and Firebase.',
				'plugin_version'     => defined( 'BS_VERSION' ) ? BS_VERSION : 'Unknown',
				'plugin_active'      => true,
				'abilities'          => $abilities,
				'icon'               => 'dashicons-megaphone',
			);
		}

		/**
		 * Allow other plugins to register their integrations.
		 *
		 * @param array $integrations Current integrations array.
		 * @return array Modified integrations array.
		 */
		$integrations = apply_filters( 'abilities_bridge_plugin_integrations', $integrations );

		// Check approval status for each integration.
		$approved = get_option( 'abilities_bridge_approved_integrations', array() );

		foreach ( $integrations as $slug => &$integration ) {
			$approved_abilities = isset( $approved[ $slug ] ) ? $approved[ $slug ] : array();
			$total              = count( $integration['abilities'] );
			$approved_count     = 0;

			foreach ( $integration['abilities'] as &$ability ) {
				$ability['approved'] = in_array( $ability['name'], $approved_abilities, true );
				if ( $ability['approved'] ) {
					++$approved_count;
				}
			}
			unset( $ability );

			if ( 0 === $approved_count ) {
				$integration['status'] = 'available';
			} elseif ( $approved_count === $total ) {
				$integration['status'] = 'approved';
			} else {
				$integration['status'] = 'partial';
			}

			$integration['approved_count'] = $approved_count;
		}
		unset( $integration );

		return $integrations;
	}

	/**
	 * Assess risk level based on ability name.
	 *
	 * Write/send operations are medium risk; read operations are low risk.
	 *
	 * @param string $ability_name The ability name to assess.
	 * @return string Risk level: 'low', 'medium', or 'high'.
	 */
	private static function assess_risk( $ability_name ) {
		$medium_risk = array( 'create_campaign', 'schedule_campaign', 'send_push', 'update_brand_voice' );

		foreach ( $medium_risk as $pattern ) {
			if ( strpos( $ability_name, $pattern ) !== false ) {
				return 'medium';
			}
		}

		return 'low';
	}

	/**
	 * AJAX handler: Approve all abilities for a plugin integration.
	 *
	 * Stores approval in both the cosmetic option (for UI state) and the
	 * ability_permissions database table (for MCP runtime exposure).
	 *
	 * @return void
	 */
	public static function ajax_approve_integration() {
		check_ajax_referer( 'abilities_bridge_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( array( 'message' => 'Plugin slug required.' ) );
		}

		$integrations = self::discover_integrations();

		if ( ! isset( $integrations[ $plugin_slug ] ) ) {
			wp_send_json_error( array( 'message' => 'Integration not found.' ) );
		}

		$integration   = $integrations[ $plugin_slug ];
		$approved      = get_option( 'abilities_bridge_approved_integrations', array() );
		$ability_names = array();

		foreach ( $integration['abilities'] as $ability ) {
			$ability_names[] = $ability['name'];

			// Register ability in the runtime permissions table.
			self::approve_ability_in_runtime( $ability );
		}

		$approved[ $plugin_slug ] = $ability_names;
		update_option( 'abilities_bridge_approved_integrations', $approved );

		// Ensure the Abilities API runtime gate is enabled — without this,
		// the Claude API and MCP server skip all abilities regardless of
		// their approval status in the ability_permissions table.
		if ( ! get_option( 'abilities_bridge_enable_abilities_api', false ) ) {
			update_option( 'abilities_bridge_enable_abilities_api', true );
		}

		wp_send_json_success(
			array(
				/* translators: 1: number of abilities, 2: plugin name */
				'message' => sprintf( '%d abilities approved for %s.', count( $ability_names ), $integration['plugin_name'] ),
				'count'   => count( $ability_names ),
			)
		);
	}

	/**
	 * AJAX handler: Revoke all abilities for a plugin integration.
	 *
	 * Removes approval from both the cosmetic option and the
	 * ability_permissions database table.
	 *
	 * @return void
	 */
	public static function ajax_revoke_integration() {
		check_ajax_referer( 'abilities_bridge_integrations_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) ) : '';

		// Disable abilities in the ability_permissions table.
		$integrations = self::discover_integrations();
		if ( isset( $integrations[ $plugin_slug ] ) && class_exists( 'Abilities_Bridge_Database' ) ) {
			global $wpdb;
			$table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			foreach ( $integrations[ $plugin_slug ]['abilities'] as $ability ) {
				$wpdb->update(
					$table,
					array(
						'enabled'       => 0,
						'disabled_date' => current_time( 'mysql' ),
					),
					array( 'ability_name' => $ability['name'] ),
					array( '%d', '%s' ),
					array( '%s' )
				);
			}
		}

		$approved = get_option( 'abilities_bridge_approved_integrations', array() );
		unset( $approved[ $plugin_slug ] );
		update_option( 'abilities_bridge_approved_integrations', $approved );

		wp_send_json_success( array( 'message' => 'Integration revoked.' ) );
	}

	/**
	 * Render the Integrations admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'abilities-bridge' ) );
		}

		$integrations = self::discover_integrations();
		include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/partials/integrations.php';
	}

	/**
	 * Register an ability in the runtime permissions system.
	 *
	 * Uses the WordPress Abilities API if available, otherwise falls back
	 * to direct database insertion so that MCP runtime can expose the ability.
	 *
	 * @param array $ability Ability definition with 'name', 'description', optional 'parameters', 'risk_level', 'callback'.
	 * @return void
	 */
	private static function approve_ability_in_runtime( $ability ) {
		global $wpdb;

		// Try WordPress Abilities API first.
		if ( function_exists( 'wp_register_ability' ) ) {
			// Register with WP if not already registered.
			if ( ! wp_get_ability( $ability['name'] ) ) {
				wp_register_ability(
					$ability['name'],
					array(
						'description'  => $ability['description'],
						'input_schema' => isset( $ability['parameters'] ) ? self::build_schema( $ability['parameters'] ) : array(),
						'callback'     => isset( $ability['callback'] ) ? $ability['callback'] : null,
					)
				);
			}

			// Register in Abilities Bridge permissions.
			if ( class_exists( 'Abilities_Bridge_Ability_Permissions' ) ) {
				Abilities_Bridge_Ability_Permissions::register_ability(
					$ability['name'],
					array(
						'enabled'                => 1,
						'max_per_day'            => 1000,
						'max_per_hour'           => 100,
						'risk_level'             => isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low',
						'requires_user_approval' => 0,
						'min_capability'         => 'manage_options',
						'description'            => $ability['description'],
						'reason_for_approval'    => 'Approved via Integrations page for ' . $ability['name'],
					)
				);
			}
			return;
		}

		// Fallback: Direct DB insert if WP Abilities API not available.
		if ( class_exists( 'Abilities_Bridge_Database' ) ) {
			$table    = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE ability_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ability['name']
				)
			);

			if ( ! $existing ) {
				$wpdb->insert(
					$table,
					array(
						'ability_name'            => $ability['name'],
						'enabled'                 => 1,
						'max_per_day'             => 1000,
						'max_per_hour'            => 100,
						'max_per_request'         => 1,
						'risk_level'              => isset( $ability['risk_level'] ) ? $ability['risk_level'] : 'low',
						'requires_user_approval'  => 0,
						'requires_admin_approval' => 0,
						'min_capability'          => 'manage_options',
						'description'             => $ability['description'],
						'reason_for_approval'     => 'Approved via Integrations page',
						'approved_by_user_id'     => get_current_user_id(),
						'approved_date'           => current_time( 'mysql' ),
						'enabled_date'            => current_time( 'mysql' ),
					),
					array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
				);
			} else {
				$wpdb->update(
					$table,
					array(
						'enabled'      => 1,
						'enabled_date' => current_time( 'mysql' ),
					),
					array( 'ability_name' => $ability['name'] ),
					array( '%d', '%s' ),
					array( '%s' )
				);
			}
		}
	}

	/**
	 * Build a JSON Schema object from a parameters definition array.
	 *
	 * @param array $parameters Associative array of parameter definitions.
	 * @return array JSON Schema compatible array.
	 */
	private static function build_schema( $parameters ) {
		$properties = array();
		$required   = array();

		foreach ( $parameters as $name => $param ) {
			$properties[ $name ] = array(
				'type'        => isset( $param['type'] ) ? $param['type'] : 'string',
				'description' => isset( $param['description'] ) ? $param['description'] : '',
			);
			if ( ! empty( $param['required'] ) ) {
				$required[] = $name;
			}
		}

		return array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => $required,
		);
	}
}
