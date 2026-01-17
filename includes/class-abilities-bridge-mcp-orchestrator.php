<?php
/**
 * Abilities Bridge MCP Orchestrator
 *
 * Main execution layer that:
 * 1. Receives requests from Claude
 * 2. Validates permissions (hardcoded)
 * 3. Executes abilities directly
 * 4. Returns results
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Orchestrator class.
 *
 * Orchestrates MCP ability execution with permissions.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_MCP_Orchestrator {

	/**
	 * Main entry point: Execute ability request from Claude
	 *
	 * @param string $ability_name Name of ability to execute.
	 * @param array  $parameters Input parameters.
	 * @param string $reason Why this is being executed.
	 * @param int    $conversation_id Conversation ID for audit.
	 *
	 * @return array Result with 'success' and 'data'/'error' keys
	 */
	public function execute_ability_request(
		$ability_name,
		$parameters = array(),
		$reason = '',
		$conversation_id = null
	) {

		// ========================================
		// GATE 1: Permission Check (Hardcoded).
		// ========================================
		$permission = Abilities_Bridge_Ability_Permissions::can_execute_ability(
			$ability_name,
			$parameters,
			array( 'conversation_id' => $conversation_id )
		);

		// FAIL CLOSED: If not explicitly allowed, DENY.
		if ( ! $permission['allowed'] ) {
			return array(
				'success'    => false,
				'error'      => $permission['reason'],
				'error_code' => 'permission_denied',
				'ability'    => $ability_name,
				'checks'     => $permission['checks'],
			);
		}

		// ========================================
		// GATE 2: Get ability and validate.
		// ========================================
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success'    => false,
				'error'      => 'Abilities API not available',
				'error_code' => 'abilities_api_missing',
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return array(
				'success'    => false,
				'error'      => 'Ability not found',
				'error_code' => 'ability_not_found',
			);
		}

		// ========================================
		// GATE 3: Validate input against schema.
		// ========================================
		$schema_validation = $this->validate_against_schema(
			$parameters,
			$ability->get_input_schema()
		);

		if ( ! $schema_validation['valid'] ) {
			return array(
				'success'    => false,
				'error'      => 'Invalid input parameters',
				'error_code' => 'validation_failed',
				'errors'     => $schema_validation['errors'],
			);
		}

		// ========================================
		// GATE 4: Execute ability.
		// ========================================
		try {
			// Log execution start.
			$this->log_execution_start( $ability_name, $parameters, $reason );

			// CRITICAL FIX: Set OAuth user context for ability permission callbacks.
			// Abilities from abilities-api plugin use current_user_can() which requires wp_set_current_user().
			$restore_user = null;
			if ( isset( $GLOBALS['abilities_bridge_oauth_user_id'] ) ) {
				$restore_user = get_current_user_id(); // Save current user (likely 0).
				wp_set_current_user( $GLOBALS['abilities_bridge_oauth_user_id'] ); // Set OAuth user temporarily.
			}

			// Call ability's permission callback (now sees OAuth user as current user).
			$perm_check = $ability->check_permissions( $parameters );

			// Restore original user context immediately after permission check.
			if ( null !== $restore_user ) {
				wp_set_current_user( $restore_user );
			}

			if ( is_wp_error( $perm_check ) || ! $perm_check ) {
				$error_msg = is_wp_error( $perm_check )
					? $perm_check->get_error_message()
					: 'Permission denied';

				return array(
					'success'    => false,
					'error'      => $error_msg,
					'error_code' => 'ability_permission_denied',
				);
			}

			// Set OAuth user context again for ability execution.
			if ( isset( $GLOBALS['abilities_bridge_oauth_user_id'] ) ) {
				wp_set_current_user( $GLOBALS['abilities_bridge_oauth_user_id'] );
			}

			// Execute ability.
			$result = $ability->execute( $parameters );

			// Restore original user context after execution.
			if ( null !== $restore_user ) {
				wp_set_current_user( $restore_user );
			}

			// Check for errors.
			if ( is_wp_error( $result ) ) {
				$this->log_execution_result( 'failed', $ability_name, $result->get_error_message() );

				return array(
					'success'    => false,
					'error'      => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				);
			}

			// Sanitize output if configured.
			$config = Abilities_Bridge_Ability_Permissions::get_all_permissions();
			foreach ( $config as $cfg ) {
				if ( $cfg['ability_name'] === $ability_name && $cfg['output_sanitization_function'] ) {
					$sanitize_func = $cfg['output_sanitization_function'];
					if ( function_exists( $sanitize_func ) ) {
						$result = call_user_func( $sanitize_func, $result );
					}
					break;
				}
			}

			// Log success.
			$this->log_execution_result( 'success', $ability_name, 'Executed successfully' );
			Abilities_Bridge_Ability_Permissions::increment_execution_count( $ability_name );

			return array(
				'success' => true,
				'data'    => $result,
				'ability' => $ability_name,
			);

		} catch ( Exception $e ) {
			$this->log_execution_result( 'error', $ability_name, $e->getMessage() );

			return array(
				'success'    => false,
				'error'      => 'Execution failed: ' . $e->getMessage(),
				'error_code' => 'execution_failed',
			);
		}
	}

	/**
	 * Validate input against schema.
	 *
	 * @param array $input  Input parameters.
	 * @param array $schema Input schema.
	 * @return array Validation result with 'valid' and 'errors' keys.
	 */
	private function validate_against_schema( $input, $schema ) {
		// Basic schema validation.
		// In production, use a proper JSON Schema validator.

		if ( ! $schema ) {
			return array(
				'valid'  => true,
				'errors' => array(),
			);
		}

		$errors = array();

		// Check required fields.
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			if ( is_array( $input ) ) {
				foreach ( $schema['required'] as $field ) {
					if ( ! isset( $input[ $field ] ) ) {
						$errors[] = "Missing required field: $field";
					}
				}
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Log execution start.
	 *
	 * @param string $ability_name The ability name.
	 * @param array  $parameters   Input parameters.
	 * @param string $reason       Reason for execution.
	 * @return void
	 */
	private function log_execution_start( $ability_name, $parameters, $reason ) {
		Abilities_Bridge_Database::add_log(
			array(
				'action'         => 'ability_execution_start',
				'function_name'  => $ability_name,
				'function_input' => wp_json_encode(
					array(
						'parameters' => $parameters,
						'reason'     => $reason,
					)
				),
			)
		);
	}

	/**
	 * Log execution result.
	 *
	 * @param string $status       Execution status (success/failed/error).
	 * @param string $ability_name The ability name.
	 * @param string $message      Result message.
	 * @return void
	 */
	private function log_execution_result( $status, $ability_name, $message ) {
		$action_map = array(
			'success' => 'ability_execution_success',
			'failed'  => 'ability_execution_failed',
			'error'   => 'ability_execution_error',
		);

		Abilities_Bridge_Database::add_log(
			array(
				'action'          => $action_map[ $status ] ?? 'ability_execution_unknown',
				'function_name'   => $ability_name,
				'function_output' => $message,
			)
		);

		// Also log execution in ability_executed_X format for rate limiting.
		if ( 'success' === $status ) {
			Abilities_Bridge_Database::add_log(
				array(
					'action'        => 'ability_executed_' . $ability_name,
					'function_name' => $ability_name,
				)
			);
		}
	}
}
