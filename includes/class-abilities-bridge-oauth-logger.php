<?php
/**
 * OAuth Audit Logger
 *
 * Comprehensive audit logging for OAuth 2.0 operations.
 * Tracks authentication attempts, token lifecycle, and security events.
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Logger class.
 *
 * Comprehensive audit logging for OAuth 2.0 operations.
 *
 * @since 1.2.0
 */
class Abilities_Bridge_OAuth_Logger {

	/**
	 * Option name for audit logs
	 */
	const OPTION_NAME = 'abilities_bridge_oauth_logs';

	/**
	 * Maximum log entries to keep
	 */
	const MAX_LOG_ENTRIES = 1000;

	/**
	 * Log retention period (30 days)
	 */
	const LOG_RETENTION = 2592000;

	/**
	 * Log severity levels
	 */
	const LEVEL_DEBUG    = 'debug';
	const LEVEL_INFO     = 'info';
	const LEVEL_WARNING  = 'warning';
	const LEVEL_ERROR    = 'error';
	const LEVEL_CRITICAL = 'critical';

	/**
	 * Log an OAuth event
	 *
	 * @param string $event Event name.
	 * @param string $level Severity level.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log( $event, $level = self::LEVEL_INFO, $context = array() ) {
		// Build log entry.
		$entry = array(
			'timestamp'  => time(),
			'event'      => $event,
			'level'      => $level,
			'ip_address' => $this->get_client_ip(),
			'user_agent' => $this->get_user_agent(),
			'context'    => $context,
		);

		// Add WordPress user if authenticated.
		if ( is_user_logged_in() ) {
			$entry['wp_user_id'] = get_current_user_id();
		}

		// Store in database.
		$logs = get_option( self::OPTION_NAME, array() );
		array_unshift( $logs, $entry ); // Add to beginning.

		// Trim to max entries.
		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( self::OPTION_NAME, $logs );

		// Fire action for external logging systems.
		do_action( 'abilities_bridge_oauth_log', $event, $level, $context, $entry );

		// Alert on critical events.
		if ( self::LEVEL_CRITICAL === $level ) {
			$this->send_critical_alert( $event, $context );
		}
	}

	/**
	 * Log authorization code generation
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id WordPress user ID.
	 * @param string $scope Requested scope.
	 * @return void
	 */
	public function log_authorization_code_generated( $client_id, $user_id, $scope = '' ) {
		$this->log(
			'authorization_code_generated',
			self::LEVEL_INFO,
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
				'scope'     => $scope,
			)
		);
	}

	/**
	 * Log authorization code consumption
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	public function log_authorization_code_consumed( $client_id, $user_id ) {
		$this->log(
			'authorization_code_consumed',
			self::LEVEL_INFO,
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
			)
		);
	}

	/**
	 * Log token issuance
	 *
	 * @param string $grant_type Grant type used.
	 * @param string $client_id Client ID.
	 * @param string $token_type Token type (access_token, refresh_token).
	 * @return void
	 */
	public function log_token_issued( $grant_type, $client_id, $token_type = 'access_token' ) {
		$this->log(
			'token_issued',
			self::LEVEL_INFO,
			array(
				'grant_type' => $grant_type,
				'client_id'  => $client_id,
				'token_type' => $token_type,
			)
		);
	}

	/**
	 * Log token validation success
	 *
	 * @param string $client_id Client ID.
	 * @param int    $user_id WordPress user ID.
	 * @return void
	 */
	public function log_token_validated( $client_id, $user_id ) {
		$this->log(
			'token_validated',
			self::LEVEL_DEBUG,
			array(
				'client_id' => $client_id,
				'user_id'   => $user_id,
			)
		);
	}

	/**
	 * Log token revocation
	 *
	 * @param string $client_id Client ID.
	 * @param string $token_type Token type revoked.
	 * @return void
	 */
	public function log_token_revoked( $client_id, $token_type = 'access_token' ) {
		$this->log(
			'token_revoked',
			self::LEVEL_INFO,
			array(
				'client_id'  => $client_id,
				'token_type' => $token_type,
			)
		);
	}

	/**
	 * Log authentication failure
	 *
	 * @param string $reason Failure reason.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log_auth_failure( $reason, $context = array() ) {
		$this->log(
			'authentication_failed',
			self::LEVEL_WARNING,
			array_merge(
				array( 'reason' => $reason ),
				$context
			)
		);
	}

	/**
	 * Log security violation
	 *
	 * @param string $violation Violation type.
	 * @param array  $context Additional context.
	 * @return void
	 */
	public function log_security_violation( $violation, $context = array() ) {
		$this->log(
			'security_violation',
			self::LEVEL_CRITICAL,
			array_merge(
				array( 'violation' => $violation ),
				$context
			)
		);
	}

	/**
	 * Get recent logs
	 *
	 * @param int    $limit Maximum number of entries.
	 * @param string $level Minimum severity level.
	 * @return array Log entries
	 */
	public function get_logs( $limit = 100, $level = null ) {
		$logs = get_option( self::OPTION_NAME, array() );

		// Filter by level if specified.
		if ( null !== $level ) {
			$level_priority = $this->get_level_priority( $level );
			$logs           = array_filter(
				$logs,
				function ( $entry ) use ( $level_priority ) {
					return $this->get_level_priority( $entry['level'] ) >= $level_priority;
				}
			);
		}

		// Limit results.
		return array_slice( $logs, 0, $limit );
	}

	/**
	 * Get logs for specific client
	 *
	 * @param string $client_id Client ID.
	 * @param int    $limit Maximum number of entries.
	 * @return array Log entries
	 */
	public function get_logs_for_client( $client_id, $limit = 50 ) {
		$logs = get_option( self::OPTION_NAME, array() );

		// Filter by client_id.
		$client_logs = array_filter(
			$logs,
			function ( $entry ) use ( $client_id ) {
				return isset( $entry['context']['client_id'] ) && $entry['context']['client_id'] === $client_id;
			}
		);

		return array_slice( $client_logs, 0, $limit );
	}

	/**
	 * Clean up old logs
	 *
	 * @return int Number of entries cleaned
	 */
	public function cleanup_old_logs() {
		$logs           = get_option( self::OPTION_NAME, array() );
		$cutoff         = time() - self::LOG_RETENTION;
		$original_count = count( $logs );

		// Remove old entries.
		$logs = array_filter(
			$logs,
			function ( $entry ) use ( $cutoff ) {
				return $entry['timestamp'] > $cutoff;
			}
		);

		// Re-index array.
		$logs = array_values( $logs );

		update_option( self::OPTION_NAME, $logs );

		$cleaned = $original_count - count( $logs );

		if ( $cleaned > 0 ) {
			$this->log(
				'logs_cleaned',
				self::LEVEL_DEBUG,
				array(
					'entries_removed' => $cleaned,
				)
			);
		}

		return $cleaned;
	}

	/**
	 * Export logs to CSV
	 *
	 * @param int    $limit Maximum entries to export.
	 * @param string $level Minimum severity level.
	 * @return string CSV content
	 */
	public function export_to_csv( $limit = 1000, $level = null ) {
		$logs = $this->get_logs( $limit, $level );

		// CSV header.
		$csv = "Timestamp,Event,Level,IP Address,User Agent,Client ID,User ID,Context\n";

		foreach ( $logs as $entry ) {
			$csv .= sprintf(
				'"%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
				gmdate( 'Y-m-d H:i:s', $entry['timestamp'] ),
				$entry['event'],
				$entry['level'],
				$entry['ip_address'],
				$entry['user_agent'],
				$entry['context']['client_id'] ?? '',
				$entry['wp_user_id'] ?? '',
				wp_json_encode( $entry['context'] )
			);
		}

		return $csv;
	}

	/**
	 * Send critical event alert
	 *
	 * @param string $event Event name.
	 * @param array  $context Context data.
	 * @return void
	 */
	private function send_critical_alert( $event, $context ) {
		// Get admin email.
		$admin_email = get_option( 'admin_email' );

		if ( ! $admin_email ) {
			return;
		}

		// Check if alerts are enabled.
		$alerts_enabled = get_option( 'abilities_bridge_oauth_critical_alerts', false );

		if ( ! $alerts_enabled ) {
			return;
		}

		// Build email content.
		$subject = sprintf(
			'[%s] Critical OAuth Security Event: %s',
			get_bloginfo( 'name' ),
			$event
		);

		$message = sprintf(
			"A critical OAuth security event has been detected:\n\n" .
			"Event: %s\n" .
			"Time: %s\n" .
			"IP Address: %s\n" .
			"User Agent: %s\n\n" .
			"Context:\n%s\n\n" .
			'Please review your OAuth logs immediately.',
			$event,
			current_time( 'Y-m-d H:i:s' ),
			$this->get_client_ip(),
			$this->get_user_agent(),
			wp_json_encode( $context, JSON_PRETTY_PRINT )
		);

		wp_mail( $admin_email, $subject, $message );

		// Fire action for additional alerting systems.
		do_action( 'abilities_bridge_oauth_critical_alert', $event, $context );
	}

	/**
	 * Get client IP address
	 *
	 * @return string IP address
	 */
	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0];
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Get user agent string
	 *
	 * @return string User agent
	 */
	private function get_user_agent() {
		return ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : 'Unknown';
	}

	/**
	 * Get priority value for log level
	 *
	 * @param string $level Level name.
	 * @return int Priority value
	 */
	private function get_level_priority( $level ) {
		$priorities = array(
			self::LEVEL_DEBUG    => 0,
			self::LEVEL_INFO     => 1,
			self::LEVEL_WARNING  => 2,
			self::LEVEL_ERROR    => 3,
			self::LEVEL_CRITICAL => 4,
		);

		return isset( $priorities[ $level ] ) ? $priorities[ $level ] : 0;
	}
}
