<?php
/**
 * Durable per-tool execution ledger for background chat jobs.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records each tool call before execution so a worker restart never repeats an
 * ambiguous side effect.
 */
class Abilities_Bridge_Chat_Job_Steps {

	/**
	 * Seed one provider round's tools. Existing rows win on races/resume.
	 *
	 * @param int   $job_id       Job ID.
	 * @param int   $round_number Provider round number.
	 * @param array $tool_uses    Provider tool-use blocks.
	 * @return true|WP_Error
	 */
	public static function seed( $job_id, $round_number, $tool_uses ) {
		global $wpdb;

		$table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS );
		$now   = current_time( 'mysql', true );

		foreach ( $tool_uses as $tool_use ) {
			if ( empty( $tool_use['id'] ) || empty( $tool_use['name'] ) ) {
				return new WP_Error( 'invalid_tool_request', __( 'The AI returned an invalid tool request.', 'abilities-bridge' ) );
			}
			$tool_use_id = (string) $tool_use['id'];
			if ( '' === $tool_use_id || strlen( $tool_use_id ) > 191 ) {
				return new WP_Error( 'invalid_tool_request', __( 'The AI returned an invalid tool request identifier.', 'abilities-bridge' ) );
			}

			$tool_name = sanitize_text_field( $tool_use['name'] );
			$input     = isset( $tool_use['input'] ) ? $tool_use['input'] : array();
			$readonly  = self::is_readonly_tool( $tool_name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i
					(job_id, round_number, tool_use_id, tool_name, is_readonly, status, input_json, created_at)
					VALUES (%d, %d, %s, %s, %d, 'pending', %s, %s)",
					$table,
					(int) $job_id,
					(int) $round_number,
					$tool_use_id,
					$tool_name,
					$readonly ? 1 : 0,
					wp_json_encode( $input ),
					$now
				)
			);

			if ( false === $result ) {
				return new WP_Error( 'tool_checkpoint_failed', __( 'Unable to save the tool checkpoint.', 'abilities-bridge' ) );
			}
		}

		return true;
	}

	/**
	 * Get a job's tool steps, optionally for one round.
	 *
	 * @param int      $job_id       Job ID.
	 * @param int|null $round_number Optional round.
	 * @return array
	 */
	public static function get_for_job( $job_id, $round_number = null ) {
		global $wpdb;

		$table = Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS );
		if ( null === $round_number ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
			return (array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d ORDER BY round_number ASC, id ASC', $table, (int) $job_id )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE job_id = %d AND round_number = %d ORDER BY id ASC',
				$table,
				(int) $job_id,
				(int) $round_number
			)
		);
	}

	/**
	 * Get the latest round that has tool steps.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public static function latest_round( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(round_number) FROM %i WHERE job_id = %d',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				(int) $job_id
			)
		);
	}

	/**
	 * Atomically mark a pending tool as running.
	 *
	 * @param int $step_id Step ID.
	 * @param int $job_id  Job ID.
	 * @return bool
	 */
	public static function mark_running( $step_id, $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', started_at = %s
				WHERE id = %d AND job_id = %d AND status = 'pending'",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				current_time( 'mysql', true ),
				(int) $step_id,
				(int) $job_id
			)
		);

		return 1 === $result;
	}

	/**
	 * Persist a tool result before it is added to provider conversation history.
	 *
	 * @param int   $step_id Step ID.
	 * @param int   $job_id  Job ID.
	 * @param mixed $result  Tool result.
	 * @return bool
	 */
	public static function complete( $step_id, $job_id, $result ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'completed', result_json = %s, completed_at = %s
				WHERE id = %d AND job_id = %d AND status = 'running'",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				wp_json_encode( $result ),
				current_time( 'mysql', true ),
				(int) $step_id,
				(int) $job_id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Reset interrupted read-only calls so a replacement worker may rerun them.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function reset_interrupted_readonly( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recovery transition.
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'pending', started_at = NULL
				WHERE job_id = %d AND status = 'running' AND is_readonly = 1",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				(int) $job_id
			)
		);

		return false !== $result;
	}

	/**
	 * Whether an interrupted write/unknown tool makes automatic resume unsafe.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function has_ambiguous_write( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recovery lookup.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE job_id = %d AND status = 'running' AND is_readonly = 0",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				(int) $job_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Build provider tool-result blocks once every step in a round is complete.
	 *
	 * @param int $job_id       Job ID.
	 * @param int $round_number Round number.
	 * @return array|WP_Error
	 */
	public static function build_result_blocks( $job_id, $round_number ) {
		$steps   = self::get_for_job( $job_id, $round_number );
		$results = array();

		if ( empty( $steps ) ) {
			return new WP_Error( 'missing_tool_checkpoint', __( 'The saved tool checkpoint is missing.', 'abilities-bridge' ) );
		}

		foreach ( $steps as $step ) {
			if ( 'completed' !== $step->status || null === $step->result_json ) {
				return new WP_Error( 'tool_steps_incomplete', __( 'A saved tool step has not completed yet.', 'abilities-bridge' ) );
			}

			$results[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $step->tool_use_id,
				'content'     => $step->result_json,
			);
		}

		return $results;
	}

	/**
	 * Build a protocol-complete terminal result set without running any tool.
	 *
	 * @param array $steps Durable tool step rows.
	 * @return array
	 */
	public static function build_terminal_result_blocks( $steps ) {
		$results = array();

		foreach ( $steps as $step ) {
			if ( 'completed' === $step->status && null !== $step->result_json ) {
				$content  = $step->result_json;
				$is_error = false;
			} elseif ( 'running' === $step->status && ! (int) $step->is_readonly ) {
				$content  = wp_json_encode(
					array(
						'status'  => 'outcome_uncertain',
						'message' => __( 'This data-changing step may have completed. Do not repeat it automatically; ask the user to verify the affected data.', 'abilities-bridge' ),
					)
				);
				$is_error = true;
			} elseif ( 'running' === $step->status ) {
				$content  = wp_json_encode(
					array(
						'status'  => 'interrupted',
						'message' => __( 'This read-only step was interrupted and its result was not retained.', 'abilities-bridge' ),
					)
				);
				$is_error = true;
			} else {
				$content  = wp_json_encode(
					array(
						'status'  => 'not_run',
						'message' => __( 'This step was stopped before it started.', 'abilities-bridge' ),
					)
				);
				$is_error = true;
			}

			$block = array(
				'type'        => 'tool_result',
				'tool_use_id' => (string) $step->tool_use_id,
				'content'     => $content,
			);
			if ( $is_error ) {
				$block['is_error'] = true;
			}
			$results[] = $block;
		}

		return $results;
	}

	/**
	 * Whether every step in a round has a durable result.
	 *
	 * @param array $steps Durable tool step rows.
	 * @return bool
	 */
	public static function round_is_fully_completed( $steps ) {
		if ( empty( $steps ) ) {
			return false;
		}

		foreach ( $steps as $step ) {
			if ( 'completed' !== $step->status || null === $step->result_json ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a partial round contains a completed or ambiguous write outcome.
	 *
	 * @param array $steps Durable tool step rows.
	 * @return bool
	 */
	public static function round_has_side_effect_outcome( $steps ) {
		foreach ( $steps as $step ) {
			if ( ! (int) $step->is_readonly && in_array( $step->status, array( 'running', 'completed' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine read-only behavior from WordPress Ability annotations.
	 * Unknown and built-in non-ability tools fail closed as writes.
	 *
	 * @param string $tool_name Provider tool name.
	 * @return bool
	 */
	public static function is_readonly_tool( $tool_name ) {
		if ( 0 !== strpos( $tool_name, 'ability_' ) || ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability_name = self::resolve_ability_name( $tool_name );
		if ( null === $ability_name ) {
			return false;
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability || ! method_exists( $ability, 'get_meta_item' ) ) {
			return false;
		}

		$annotations = $ability->get_meta_item( 'annotations', array() );

		return is_array( $annotations ) && true === ( $annotations['readonly'] ?? null );
	}

	/**
	 * Resolve an API-safe ability tool name without guessing at underscores.
	 *
	 * @param string $tool_name Provider tool name.
	 * @return string|null WordPress ability name.
	 */
	public static function resolve_ability_name( $tool_name ) {
		if ( 0 !== strpos( (string) $tool_name, 'ability_' ) || ! class_exists( 'Abilities_Bridge_Ability_Permissions' ) ) {
			return null;
		}

		// Exact lookup against the SAME canonical map used when the tool
		// list was created. The old code re-derived names with a different
		// (broader) transform, so any hyphenated ability advertised a name
		// this resolver could never match.
		$map = Abilities_Bridge_Ability_Permissions::provider_tool_map();

		return isset( $map[ $tool_name ] ) ? $map[ $tool_name ] : null;
	}
}
