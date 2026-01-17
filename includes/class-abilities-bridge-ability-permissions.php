<?php
/**
 * Abilities Bridge Ability Permissions
 *
 * Hardcoded permission system for ability execution.
 * Fail-closed architecture - default deny, explicit allow only.
 *
 * This file uses direct database queries for the custom ability_permissions table.
 * An in-memory static cache ($permissions_cache) is used instead of wp_cache
 * as permissions are loaded once per request and don't benefit from persistent caching.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability Permissions class.
 *
 * Hardcoded permission system for ability execution with fail-closed architecture.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Ability_Permissions {

	/**
	 * In-memory cache of all permissions (loaded once per request).
	 *
	 * @var array|null
	 */
	private static $permissions_cache = null;

	/**
	 * PRIMARY GATE: Check if ability can execute
	 *
	 * FAIL CLOSED: Returns permission object, caller must check 'allowed' key
	 *
	 * @param string $ability_name The ability to check.
	 * @param array  $input_params Input parameters for validation.
	 * @param array  $context Additional context.
	 * @return array Permission result ['allowed' => bool, 'reason' => string, 'checks' => array]
	 */
	public static function can_execute_ability(
		$ability_name,
		$input_params = array(),
		$context = array()
	) {
		// FAIL CLOSED: Default deny.
		$result = array(
			'allowed'      => false,
			'reason'       => '',
			'checks'       => array(),
			'ability_name' => $ability_name,
		);

		// ====== CHECK 0: Abilities API enabled? ======
		$abilities_enabled = get_option( 'abilities_bridge_enable_abilities_api', false );
		if ( ! $abilities_enabled ) {
			$result['reason']   = 'WordPress Abilities API is disabled. Enable it in Settings > Ability Permissions.';
			$result['checks'][] = array(
				'gate'   => '0_api_enabled',
				'passed' => false,
			);
			self::log_violation( $ability_name, $result );
			return $result;
		}
		$result['checks'][] = array(
			'gate'   => '0_api_enabled',
			'passed' => true,
		);

		// ====== CHECK 1: Ability registered in WordPress? ======
		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( $ability_name ) ) {
			$result['reason']   = 'Ability not registered in WordPress';
			$result['checks'][] = array(
				'gate'   => '1_existence',
				'passed' => false,
			);
			self::log_violation( $ability_name, $result );
			return $result;
		}
		$result['checks'][] = array(
			'gate'   => '1_existence',
			'passed' => true,
		);

		// ====== CHECK 2: Ability in permission registry? ======
		$config = self::get_ability_config( $ability_name );
		if ( ! $config ) {
			$result['reason']   = 'Ability not in permission registry (must be explicitly approved)';
			$result['checks'][] = array(
				'gate'   => '2_registry',
				'passed' => false,
			);
			self::log_violation( $ability_name, $result );
			return $result;
		}
		$result['checks'][] = array(
			'gate'   => '2_registry',
			'passed' => true,
		);

		// ====== CHECK 3: Ability enabled? ======
		if ( ! $config['enabled'] ) {
			$result['reason']   = sprintf(
				'Ability disabled since %s by admin',
				$config['disabled_date']
			);
			$result['checks'][] = array(
				'gate'   => '3_enabled',
				'passed' => false,
			);
			self::log_violation( $ability_name, $result );
			return $result;
		}
		$result['checks'][] = array(
			'gate'   => '3_enabled',
			'passed' => true,
		);

		// ====== CHECK 4: Rate limits OK? ======
		$rate_result        = self::check_rate_limits( $ability_name, $config );
		$result['checks'][] = $rate_result;
		if ( ! $rate_result['passed'] ) {
			$result['reason'] = $rate_result['reason'];
			self::log_violation( $ability_name, $result );
			return $result;
		}

		// ====== CHECK 5: User has required capability? ======
		$cap_result         = self::check_user_capability( $config );
		$result['checks'][] = $cap_result;
		if ( ! $cap_result['passed'] ) {
			$result['reason'] = $cap_result['reason'];
			self::log_violation( $ability_name, $result );
			return $result;
		}

		// ====== CHECK 6: Input parameters valid? ======
		$input_result       = self::validate_input( $ability_name, $input_params, $config );
		$result['checks'][] = $input_result;
		if ( ! $input_result['passed'] ) {
			$result['reason'] = $input_result['reason'];
			self::log_violation( $ability_name, $result );
			return $result;
		}

		// ====== ALL CHECKS PASSED ======
		$result['allowed']  = true;
		$result['reason']   = 'All permission gates passed - execution approved';
		$result['checks'][] = array(
			'gate'   => 'final',
			'passed' => true,
		);

		self::log_permission_check( 'ALLOWED', $ability_name, $result );
		return $result;
	}

	/**
	 * ADMIN ONLY: Register ability for execution
	 *
	 * @param string $ability_name Name of ability (e.g., 'core/create-post').
	 * @param array  $config Configuration array.
	 * @return bool|WP_Error
	 */
	public static function register_ability( $ability_name, $config = array() ) {
		// ====== SECURITY: Only admins can register ======
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', 'Only site administrators can register abilities.' );
		}

		// ====== VALIDATION: Required fields ======
		$required_fields = array(
			'enabled',
			'max_per_day',
			'risk_level',
			'description',
			'reason_for_approval',
		);

		foreach ( $required_fields as $field ) {
			if ( ! isset( $config[ $field ] ) ) {
				return new WP_Error(
					'missing_field',
					"Missing required config: $field"
				);
			}
		}

		// ====== VALIDATION: Risk level ======
		$valid_risks = array( 'low', 'medium', 'high' );
		if ( ! in_array( $config['risk_level'], $valid_risks, true ) ) {
			return new WP_Error(
				'invalid_risk',
				'Risk must be: ' . implode( ', ', $valid_risks )
			);
		}

		// ====== VALIDATION: Rate limits ======
		$max_per_day = (int) $config['max_per_day'];
		if ( $max_per_day < 0 || $max_per_day > 100 ) {
			return new WP_Error(
				'invalid_rate_limit',
				'Rate limit must be 0-100'
			);
		}

		global $wpdb;
		$current_user = wp_get_current_user();

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		// Check if already exists.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				$ability_table,
				$ability_name
			)
		);

		if ( $existing ) {
			// UPDATE existing.
			$wpdb->update(
				$ability_table,
				array(
					'enabled'                 => (int) $config['enabled'],
					'max_per_day'             => $max_per_day,
					'max_per_hour'            => (int) ( $config['max_per_hour'] ?? 0 ),
					'risk_level'              => sanitize_text_field( $config['risk_level'] ),
					'requires_user_approval'  => (int) ( $config['requires_user_approval'] ?? 1 ),
					'requires_admin_approval' => (int) ( $config['requires_admin_approval'] ?? 0 ),
					'min_capability'          => sanitize_text_field( $config['min_capability'] ?? null ),
					'description'             => sanitize_textarea_field( $config['description'] ),
					'reason_for_approval'     => sanitize_textarea_field( $config['reason_for_approval'] ),
					'approved_by_user_id'     => $current_user->ID,
					'approved_date'           => current_time( 'mysql' ),
					'enabled_date'            => $config['enabled'] ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $existing->id ),
				array(
					'%d',
					'%d',
					'%d',
					'%s',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
				),
				array( '%d' )
			);
		} else {
			// INSERT new.
			$wpdb->insert(
				$ability_table,
				array(
					'ability_name'            => $ability_name,
					'enabled'                 => (int) $config['enabled'],
					'max_per_day'             => $max_per_day,
					'max_per_hour'            => (int) ( $config['max_per_hour'] ?? 0 ),
					'max_per_request'         => 1, // Always 1.
					'risk_level'              => sanitize_text_field( $config['risk_level'] ),
					'requires_user_approval'  => (int) ( $config['requires_user_approval'] ?? 1 ),
					'requires_admin_approval' => (int) ( $config['requires_admin_approval'] ?? 0 ),
					'min_capability'          => sanitize_text_field( $config['min_capability'] ?? null ),
					'description'             => sanitize_textarea_field( $config['description'] ),
					'reason_for_approval'     => sanitize_textarea_field( $config['reason_for_approval'] ),
					'approved_by_user_id'     => $current_user->ID,
					'approved_date'           => current_time( 'mysql' ),
					'enabled_date'            => $config['enabled'] ? current_time( 'mysql' ) : null,
				),
				array(
					'%s',
					'%d',
					'%d',
					'%d',
					'%d',
					'%s',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
				)
			);
		}

		self::invalidate_cache();

		return true;
	}

	/**
	 * ADMIN ONLY: Disable ability.
	 *
	 * @param string $ability_name The ability name to disable.
	 * @param string $reason       Optional reason for disabling.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function disable_ability( $ability_name, $reason = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', 'Only administrators can disable abilities.' );
		}

		global $wpdb;

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		$wpdb->update(
			$ability_table,
			array(
				'enabled'       => 0,
				'disabled_date' => current_time( 'mysql' ),
			),
			array( 'ability_name' => $ability_name ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		self::invalidate_cache();

		return true;
	}

	/**
	 * ADMIN ONLY: Enable ability.
	 *
	 * @param string $ability_name The ability name to enable.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function enable_ability( $ability_name ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', 'Only administrators can enable abilities.' );
		}

		global $wpdb;

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		$wpdb->update(
			$ability_table,
			array(
				'enabled'      => 1,
				'enabled_date' => current_time( 'mysql' ),
			),
			array( 'ability_name' => $ability_name ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		self::invalidate_cache();

		return true;
	}

	/**
	 * Check rate limits for ability execution.
	 *
	 * @param string $ability_name The ability name.
	 * @param array  $config       The ability configuration.
	 * @return array Rate limit check result.
	 */
	private static function check_rate_limits( $ability_name, $config ) {
		// If max_per_day is 0, no execution allowed.
		if ( 0 === $config['max_per_day'] || '0' === $config['max_per_day'] ) {
			return array(
				'gate'   => '4_rate_limits',
				'passed' => false,
				'reason' => 'Ability has execution disabled (max_per_day = 0)',
			);
		}

		global $wpdb;

		$logs_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS );

		// Check daily limit.
		if ( $config['max_per_day'] > 0 ) {
			$today_count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE action = %s AND DATE(created_at) = %s',
					$logs_table,
					'ability_executed_' . $ability_name,
					current_time( 'Y-m-d' )
				)
			);

			if ( $today_count >= $config['max_per_day'] ) {
				return array(
					'gate'   => '4_rate_limits',
					'passed' => false,
					'reason' => sprintf(
						'Daily limit exceeded: %d/%d executions',
						$today_count,
						$config['max_per_day']
					),
				);
			}
		}

		// Check hourly limit.
		if ( $config['max_per_hour'] > 0 ) {
			$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
			$hour_count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE action = %s AND created_at > %s',
					$logs_table,
					'ability_executed_' . $ability_name,
					$one_hour_ago
				)
			);

			if ( $hour_count >= $config['max_per_hour'] ) {
				return array(
					'gate'   => '4_rate_limits',
					'passed' => false,
					'reason' => sprintf(
						'Hourly limit exceeded: %d/%d executions',
						$hour_count,
						$config['max_per_hour']
					),
				);
			}
		}

		return array(
			'gate'   => '4_rate_limits',
			'passed' => true,
			'reason' => 'Rate limits OK',
		);
	}

	/**
	 * Check user capability for ability execution.
	 *
	 * @param array $config The ability configuration.
	 * @return array Capability check result.
	 */
	private static function check_user_capability( $config ) {
		if ( ! $config['min_capability'] ) {
			return array(
				'gate'   => '5_user_capability',
				'passed' => true,
				'reason' => 'No capability requirement',
			);
		}

		// Check if this is an OAuth request.
		if ( isset( $GLOBALS['abilities_bridge_oauth_user_id'] ) ) {
			$user_id = $GLOBALS['abilities_bridge_oauth_user_id'];
			$user    = get_userdata( $user_id );

			if ( ! $user ) {
				return array(
					'gate'   => '5_user_capability',
					'passed' => false,
					'reason' => sprintf(
						'OAuth user ID %d not found',
						$user_id
					),
				);
			}

			// Check capability for OAuth user.
			if ( ! user_can( $user, $config['min_capability'] ) ) {
				return array(
					'gate'   => '5_user_capability',
					'passed' => false,
					'reason' => sprintf(
						'User "%s" (OAuth) lacks required capability: %s',
						$user->user_login,
						$config['min_capability']
					),
				);
			}

			return array(
				'gate'   => '5_user_capability',
				'passed' => true,
				'reason' => sprintf(
					'User "%s" (OAuth) has required capability',
					$user->user_login
				),
			);
		}

		// Fall back to WordPress current user for non-OAuth requests.
		if ( ! current_user_can( $config['min_capability'] ) ) {
			$user = wp_get_current_user();
			return array(
				'gate'   => '5_user_capability',
				'passed' => false,
				'reason' => sprintf(
					'User "%s" lacks required capability: %s',
					$user->user_login,
					$config['min_capability']
				),
			);
		}

		return array(
			'gate'   => '5_user_capability',
			'passed' => true,
			'reason' => 'User has required capability',
		);
	}

	/**
	 * Validate input parameters for ability execution.
	 *
	 * @param string $ability_name The ability name.
	 * @param array  $input_params Input parameters.
	 * @param array  $config       The ability configuration.
	 * @return array Validation result.
	 */
	private static function validate_input( $ability_name, $input_params, $config ) {
		// If no validation function, skip.
		if ( ! $config['input_validation_function'] ) {
			return array(
				'gate'   => '6_input_validation',
				'passed' => true,
				'reason' => 'No validation required',
			);
		}

		$validation_func = $config['input_validation_function'];

		// Validation function must exist.
		if ( ! function_exists( $validation_func ) ) {
			return array(
				'gate'   => '6_input_validation',
				'passed' => false,
				'reason' => "Validation function not found: $validation_func",
			);
		}

		try {
			$validation_result = call_user_func( $validation_func, $input_params );

			if ( true === $validation_result ) {
				return array(
					'gate'   => '6_input_validation',
					'passed' => true,
					'reason' => 'Input parameters validated',
				);
			} else {
				$reason = is_string( $validation_result )
					? $validation_result
					: 'Input validation failed';

				return array(
					'gate'   => '6_input_validation',
					'passed' => false,
					'reason' => $reason,
				);
			}
		} catch ( Exception $e ) {
			return array(
				'gate'   => '6_input_validation',
				'passed' => false,
				'reason' => 'Validation error: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Get ability configuration from cache.
	 *
	 * @param string $ability_name The ability name.
	 * @return array|null Ability configuration or null if not found.
	 */
	private static function get_ability_config( $ability_name ) {
		if ( null === self::$permissions_cache ) {
			self::load_permissions_cache();
		}

		return isset( self::$permissions_cache[ $ability_name ] )
			? self::$permissions_cache[ $ability_name ]
			: null;
	}

	/**
	 * Load permissions into memory cache.
	 *
	 * @return void
	 */
	private static function load_permissions_cache() {
		global $wpdb;

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		$permissions = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1', $ability_table ),
			ARRAY_A
		);

		self::$permissions_cache = array();
		foreach ( $permissions as $perm ) {
			self::$permissions_cache[ $perm['ability_name'] ] = $perm;
		}
	}

	/**
	 * Invalidate the permissions cache.
	 *
	 * @return void
	 */
	private static function invalidate_cache() {
		self::$permissions_cache = null;
	}

	/**
	 * Log permission check result.
	 *
	 * @param string $result      The check result status.
	 * @param string $ability_name The ability name.
	 * @param array  $permission  The permission result data.
	 * @return void
	 */
	private static function log_permission_check( $result, $ability_name, $permission ) {
		Abilities_Bridge_Database::add_log(
			array(
				'action'         => 'permission_check_' . $result,
				'function_name'  => $ability_name,
				'function_input' => wp_json_encode(
					array(
						'checks' => $permission['checks'],
						'reason' => $permission['reason'],
					)
				),
			)
		);
	}

	/**
	 * Log permission violation.
	 *
	 * @param string $ability_name The ability name.
	 * @param array  $permission   The permission result data.
	 * @return void
	 */
	private static function log_violation( $ability_name, $permission ) {
		// Get user - check OAuth context first.
		if ( isset( $GLOBALS['abilities_bridge_oauth_user_id'] ) ) {
			$user       = get_userdata( $GLOBALS['abilities_bridge_oauth_user_id'] );
			$user_login = $user ? $user->user_login . ' (OAuth)' : 'OAuth user ID ' . $GLOBALS['abilities_bridge_oauth_user_id'];
		} else {
			$user       = wp_get_current_user();
			$user_login = $user->user_login;
		}

		Abilities_Bridge_Database::add_log(
			array(
				'action'         => 'permission_violation',
				'function_name'  => $ability_name,
				'error_message'  => $permission['reason'],
				'function_input' => wp_json_encode( $permission['checks'] ),
			)
		);
	}

	/**
	 * Get all ability permissions.
	 *
	 * @param bool $enabled_only Whether to return only enabled abilities.
	 * @return array Array of ability permissions.
	 */
	public static function get_all_permissions( $enabled_only = false ) {
		global $wpdb;

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		if ( $enabled_only ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE enabled = %d ORDER BY risk_level DESC, ability_name ASC',
					$ability_table,
					1
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY risk_level DESC, ability_name ASC', $ability_table ),
			ARRAY_A
		);
	}

	/**
	 * Increment execution count for rate limiting.
	 *
	 * @param string $ability_name The ability name.
	 * @return void
	 */
	public static function increment_execution_count( $ability_name ) {
		global $wpdb;

		$ability_table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET execution_count = execution_count + 1, last_executed = %s WHERE ability_name = %s',
				$ability_table,
				current_time( 'mysql' ),
				$ability_name
			)
		);
	}
}
