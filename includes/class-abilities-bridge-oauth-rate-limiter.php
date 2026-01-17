<?php
/**
 * OAuth Rate Limiter
 *
 * Protects OAuth endpoints from brute force attacks and abuse.
 * Implements sliding window rate limiting with IP-based throttling.
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Rate Limiter class.
 *
 * Protects OAuth endpoints from brute force attacks and abuse.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_OAuth_Rate_Limiter {

	/**
	 * Option name for rate limit data
	 */
	const OPTION_NAME = 'abilities_bridge_oauth_rate_limits';

	/**
	 * Rate limit windows (endpoint => [requests, seconds])
	 */
	const RATE_LIMITS = array(
		'token'     => array(
			'requests' => 10,
			'window'   => 60,
		),    // 10 requests per minute.
		'authorize' => array(
			'requests' => 20,
			'window'   => 60,
		),    // 20 requests per minute.
		'revoke'    => array(
			'requests' => 5,
			'window'   => 60,
		),     // 5 requests per minute.
	);

	/**
	 * Lockout settings
	 */
	const LOCKOUT_THRESHOLD = 5;        // Failed attempts before lockout.
	const LOCKOUT_DURATION  = 900;      // 15 minutes lockout.

	/**
	 * Check if request should be rate limited
	 *
	 * @param string $endpoint Endpoint identifier (token, authorize, revoke).
	 * @param string $identifier Rate limit identifier (IP address or client_id).
	 * @return bool|WP_Error True if allowed, error if rate limited
	 */
	public function check_rate_limit( $endpoint, $identifier = null ) {
		// Get identifier (default to IP address).
		if ( null === $identifier ) {
			$identifier = $this->get_client_ip();
		}

		// Check if identifier is locked out.
		if ( $this->is_locked_out( $identifier ) ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many failed attempts. Please try again later.', 'abilities-bridge' ),
				array(
					'status'      => 429,
					'retry_after' => $this->get_lockout_remaining( $identifier ),
				)
			);
		}

		// Get rate limit for endpoint.
		if ( ! isset( self::RATE_LIMITS[ $endpoint ] ) ) {
			// No rate limit configured for this endpoint.
			return true;
		}

		$limit = self::RATE_LIMITS[ $endpoint ];

		// Get request history.
		$key       = $this->get_rate_limit_key( $endpoint, $identifier );
		$rate_data = get_option( self::OPTION_NAME, array() );
		$requests  = isset( $rate_data[ $key ] ) ? $rate_data[ $key ] : array();

		// Clean up old requests outside the window.
		$window_start = time() - $limit['window'];
		$requests     = array_filter(
			$requests,
			function ( $timestamp ) use ( $window_start ) {
				return $timestamp > $window_start;
			}
		);

		// Check if limit exceeded.
		if ( count( $requests ) >= $limit['requests'] ) {
			$this->log_rate_limit_exceeded( $endpoint, $identifier );

			// Calculate retry-after time.
			$oldest_request = min( $requests );
			$retry_after    = $limit['window'] - ( time() - $oldest_request );

			return new WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: %d: seconds to wait */
					__( 'Rate limit exceeded. Please try again in %d seconds.', 'abilities-bridge' ),
					$retry_after
				),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		// Record this request.
		$requests[]        = time();
		$rate_data[ $key ] = $requests;
		update_option( self::OPTION_NAME, $rate_data );

		return true;
	}

	/**
	 * Record a failed authentication attempt
	 *
	 * @param string $identifier Identifier (IP or client_id).
	 * @return void
	 */
	public function record_failed_attempt( $identifier = null ) {
		if ( null === $identifier ) {
			$identifier = $this->get_client_ip();
		}

		$lockout_key  = $this->get_lockout_key( $identifier );
		$lockout_data = get_option( 'abilities_bridge_oauth_lockouts', array() );

		if ( ! isset( $lockout_data[ $lockout_key ] ) ) {
			$lockout_data[ $lockout_key ] = array(
				'attempts'      => 0,
				'first_attempt' => time(),
			);
		}

		++$lockout_data[ $lockout_key ]['attempts'];
		$lockout_data[ $lockout_key ]['last_attempt'] = time();

		// Check if lockout threshold reached.
		if ( $lockout_data[ $lockout_key ]['attempts'] >= self::LOCKOUT_THRESHOLD ) {
			$lockout_data[ $lockout_key ]['locked_until'] = time() + self::LOCKOUT_DURATION;

			$this->log_lockout( $identifier, $lockout_data[ $lockout_key ]['attempts'] );
		}

		update_option( 'abilities_bridge_oauth_lockouts', $lockout_data );
	}

	/**
	 * Reset failed attempts (call after successful auth)
	 *
	 * @param string $identifier Identifier (IP or client_id).
	 * @return void
	 */
	public function reset_failed_attempts( $identifier = null ) {
		if ( null === $identifier ) {
			$identifier = $this->get_client_ip();
		}

		$lockout_key  = $this->get_lockout_key( $identifier );
		$lockout_data = get_option( 'abilities_bridge_oauth_lockouts', array() );

		if ( isset( $lockout_data[ $lockout_key ] ) ) {
			unset( $lockout_data[ $lockout_key ] );
			update_option( 'abilities_bridge_oauth_lockouts', $lockout_data );
		}
	}

	/**
	 * Check if identifier is locked out
	 *
	 * @param string $identifier Identifier to check.
	 * @return bool True if locked out
	 */
	private function is_locked_out( $identifier ) {
		$lockout_key  = $this->get_lockout_key( $identifier );
		$lockout_data = get_option( 'abilities_bridge_oauth_lockouts', array() );

		if ( ! isset( $lockout_data[ $lockout_key ] ) ) {
			return false;
		}

		$data = $lockout_data[ $lockout_key ];

		// Check if lockout has expired.
		if ( isset( $data['locked_until'] ) && time() < $data['locked_until'] ) {
			return true;
		}

		// Lockout expired, clean up.
		if ( isset( $data['locked_until'] ) ) {
			unset( $lockout_data[ $lockout_key ] );
			update_option( 'abilities_bridge_oauth_lockouts', $lockout_data );
		}

		return false;
	}

	/**
	 * Get remaining lockout time in seconds
	 *
	 * @param string $identifier Identifier to check.
	 * @return int Seconds remaining
	 */
	private function get_lockout_remaining( $identifier ) {
		$lockout_key  = $this->get_lockout_key( $identifier );
		$lockout_data = get_option( 'abilities_bridge_oauth_lockouts', array() );

		if ( ! isset( $lockout_data[ $lockout_key ]['locked_until'] ) ) {
			return 0;
		}

		$remaining = $lockout_data[ $lockout_key ]['locked_until'] - time();
		return max( 0, $remaining );
	}

	/**
	 * Clean up expired rate limit data
	 *
	 * @return int Number of entries cleaned
	 */
	public function cleanup_expired_data() {
		$cleaned = 0;

		// Clean up rate limit data.
		$rate_data = get_option( self::OPTION_NAME, array() );
		foreach ( $rate_data as $key => $requests ) {
			// If all requests are older than max window, remove.
			$max_window   = max( array_column( self::RATE_LIMITS, 'window' ) );
			$window_start = time() - $max_window;

			$recent_requests = array_filter(
				$requests,
				function ( $timestamp ) use ( $window_start ) {
					return $timestamp > $window_start;
				}
			);

			if ( empty( $recent_requests ) ) {
				unset( $rate_data[ $key ] );
				++$cleaned;
			} else {
				$rate_data[ $key ] = $recent_requests;
			}
		}
		update_option( self::OPTION_NAME, $rate_data );

		// Clean up lockout data.
		$lockout_data = get_option( 'abilities_bridge_oauth_lockouts', array() );
		foreach ( $lockout_data as $key => $data ) {
			// Remove if lockout expired and no recent attempts.
			if ( isset( $data['locked_until'] ) && time() > $data['locked_until'] ) {
				unset( $lockout_data[ $key ] );
				++$cleaned;
			}
		}
		update_option( 'abilities_bridge_oauth_lockouts', $lockout_data );

		return $cleaned;
	}

	/**
	 * Get client IP address
	 *
	 * @return string IP address
	 */
	private function get_client_ip() {
		// Check for proxy headers.
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Standard proxy header.
			'HTTP_X_REAL_IP',        // Nginx.
			'REMOTE_ADDR',           // Fallback.
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				// Take first IP if comma-separated list.
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0];
				$ip = trim( $ip );

				// Validate IP.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Get rate limit key
	 *
	 * @param string $endpoint Endpoint name.
	 * @param string $identifier Identifier.
	 * @return string Key for storage
	 */
	private function get_rate_limit_key( $endpoint, $identifier ) {
		return 'rate_' . $endpoint . '_' . md5( $identifier );
	}

	/**
	 * Get lockout key
	 *
	 * @param string $identifier Identifier.
	 * @return string Key for storage
	 */
	private function get_lockout_key( $identifier ) {
		return 'lockout_' . md5( $identifier );
	}

	/**
	 * Log rate limit exceeded event
	 *
	 * @param string $endpoint Endpoint name.
	 * @param string $identifier Identifier.
	 * @return void
	 */
	private function log_rate_limit_exceeded( $endpoint, $identifier ) {

		do_action( 'abilities_bridge_oauth_rate_limit_exceeded', $endpoint, $identifier );
	}

	/**
	 * Log lockout event
	 *
	 * @param string $identifier Identifier.
	 * @param int    $attempts Number of failed attempts.
	 * @return void
	 */
	private function log_lockout( $identifier, $attempts ) {

		do_action( 'abilities_bridge_oauth_lockout', $identifier, $attempts, self::LOCKOUT_DURATION );
	}
}
