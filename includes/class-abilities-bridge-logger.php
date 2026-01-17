<?php
/**
 * Logging system class
 *
 * This file uses direct database queries for the custom logs table.
 * Log queries are real-time displays and don't benefit from object caching.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class.
 *
 * Handles logging of function calls and tool progress.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Logger {

	/**
	 * Log a function call
	 *
	 * @param int|null $conversation_id Conversation ID.
	 * @param string   $function_name Function name.
	 * @param array    $input Function input.
	 * @param array    $output Function output.
	 */
	public static function log_function_call( $conversation_id, $function_name, $input, $output ) {
		$user     = wp_get_current_user();
		$username = $user->user_login;

		$action = sprintf(
			'%s (via Abilities Bridge) executed function: %s',
			$username,
			$function_name
		);

		$error_message = null;
		$stack_trace   = null;

		if ( isset( $output['error'] ) && ! empty( $output['error'] ) ) {
			$error_message = $output['error'];
			if ( isset( $output['trace'] ) ) {
				$stack_trace = $output['trace'];
			}
		}

		Abilities_Bridge_Database::add_log(
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => get_current_user_id(),
				'username'        => $username,
				'action'          => $action,
				'function_name'   => $function_name,
				'function_input'  => wp_json_encode( $input ),
				'function_output' => wp_json_encode( $output ),
				'error_message'   => $error_message,
				'stack_trace'     => $stack_trace,
			)
		);
	}

	/**
	 * Log tool execution (unified logging for both admin chat and MCP)
	 *
	 * @param string   $tool_name Tool or ability name.
	 * @param array    $input Tool input parameters.
	 * @param array    $output Tool output/result.
	 * @param int|null $conversation_id Conversation ID (NULL for MCP).
	 * @param string   $source Execution source ('admin' or 'mcp').
	 */
	public static function log_tool_execution( $tool_name, $input, $output, $conversation_id = null, $source = 'admin' ) {
		$user     = wp_get_current_user();
		$username = $user->user_login;

		$source_label = ( 'mcp' === $source ) ? 'MCP' : 'Chat';
		$action       = sprintf(
			'%s (via Abilities Bridge %s) executed tool: %s',
			$username,
			$source_label,
			$tool_name
		);

		$error_message = null;
		$stack_trace   = null;

		if ( isset( $output['error'] ) && ! empty( $output['error'] ) ) {
			$error_message = $output['error'];
			if ( isset( $output['trace'] ) ) {
				$stack_trace = $output['trace'];
			}
		}

		Abilities_Bridge_Database::add_log(
			array(
				'conversation_id' => $conversation_id,
				'source'          => $source,
				'user_id'         => get_current_user_id(),
				'username'        => $username,
				'action'          => $action,
				'function_name'   => $tool_name,
				'function_input'  => wp_json_encode( $input ),
				'function_output' => wp_json_encode( $output ),
				'error_message'   => $error_message,
				'stack_trace'     => $stack_trace,
			)
		);
	}

	/**
	 * Log a general action
	 *
	 * @param int|null    $conversation_id Conversation ID.
	 * @param string      $action Action description.
	 * @param string|null $error_message Error message if any.
	 */
	public static function log_action( $conversation_id, $action, $error_message = null ) {
		$user     = wp_get_current_user();
		$username = $user->user_login;

		$full_action = sprintf(
			'%s (via Abilities Bridge) %s',
			$username,
			$action
		);

		Abilities_Bridge_Database::add_log(
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => get_current_user_id(),
				'username'        => $username,
				'action'          => $full_action,
				'error_message'   => $error_message,
			)
		);
	}

	/**
	 * Log an error with full context
	 *
	 * @param int|null        $conversation_id Conversation ID.
	 * @param string          $action Action being performed.
	 * @param Exception|Error $exception Exception or error object.
	 */
	public static function log_error( $conversation_id, $action, $exception ) {
		$user     = wp_get_current_user();
		$username = $user->user_login;

		$full_action = sprintf(
			'%s (via Abilities Bridge) encountered error during: %s',
			$username,
			$action
		);

		Abilities_Bridge_Database::add_log(
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => get_current_user_id(),
				'username'        => $username,
				'action'          => $full_action,
				'error_message'   => $exception->getMessage(),
				'stack_trace'     => $exception->getTraceAsString(),
			)
		);
	}

	/**
	 * Get formatted logs for display
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array Formatted logs
	 */
	public static function get_formatted_logs( $conversation_id ) {
		$logs      = Abilities_Bridge_Database::get_logs( $conversation_id );
		$formatted = array();

		foreach ( $logs as $log ) {
			$formatted[] = array(
				'id'            => $log->id,
				'timestamp'     => $log->created_at,
				'action'        => $log->action,
				'function_name' => $log->function_name,
				'input'         => $log->function_input ? json_decode( $log->function_input, true ) : null,
				'output'        => $log->function_output ? json_decode( $log->function_output, true ) : null,
				'error'         => $log->error_message,
				'trace'         => $log->stack_trace,
			);
		}

		return $formatted;
	}

	/**
	 * Log tool progress/activity (for real-time display)
	 *
	 * @param int|null $conversation_id Conversation ID.
	 * @param string   $tool_name Tool/function name.
	 * @param string   $status Status (starting, processing, completed, failed).
	 * @param string   $message User-friendly progress message.
	 */
	public static function log_tool_progress( $conversation_id, $tool_name, $status, $message ) {
		$user     = wp_get_current_user();
		$username = $user->user_login;

		Abilities_Bridge_Database::add_log(
			array(
				'conversation_id' => $conversation_id,
				'user_id'         => get_current_user_id(),
				'username'        => $username,
				'action'          => $message,
				'function_name'   => $tool_name,
				'function_input'  => wp_json_encode( array( 'status' => $status ) ),
			)
		);
	}

	/**
	 * Get recent activity (for real-time polling)
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $seconds Number of seconds to look back (default 30).
	 * @return array Recent log entries
	 */
	public static function get_recent_activity( $conversation_id, $seconds = 30 ) {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE conversation_id = %d
				 AND created_at > %s
				 ORDER BY created_at DESC
				 LIMIT 20',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$conversation_id,
				$cutoff
			)
		);
	}

	/**
	 * Get all tool activity for a conversation (for activity history dropdown)
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array All tool activity logs
	 */
	public static function get_conversation_activity( $conversation_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE conversation_id = %d
				 AND function_name IS NOT NULL
				 ORDER BY created_at DESC',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$conversation_id
			)
		);
	}
}
