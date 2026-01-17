<?php
/**
 * Log cleanup and retention management
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log Cleanup class.
 *
 * Handles log cleanup and retention management.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Log_Cleanup {

	/**
	 * Hook name for the cleanup cron job
	 */
	const CRON_HOOK = 'abilities_bridge_daily_cleanup';

	/**
	 * Initialize the cleanup system
	 */
	public static function init() {
		// Register cron hook.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup' ) );

		// Schedule cron on plugin activation if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Run the daily cleanup tasks
	 */
	public static function run_cleanup() {
		$start_time = microtime( true );
		$results    = array();

		// Get log retention setting (default 30 days).
		$retention_days = get_option( 'abilities_bridge_log_retention_days', 30 );

		// Only run if retention is enabled (0 = disabled/keep forever).
		if ( $retention_days > 0 ) {
			// Purge old logs.
			$logs_deleted            = Abilities_Bridge_Database::purge_old_logs( $retention_days );
			$results['logs_deleted'] = $logs_deleted;
		} else {
			$results['logs_deleted'] = 0;
		}

		// Always purge deleted conversations older than 30 days.
		$conversations_deleted            = Abilities_Bridge_Database::purge_old_deleted_conversations();
		$results['conversations_deleted'] = $conversations_deleted;

		// Calculate execution time.
		$execution_time            = microtime( true ) - $start_time;
		$results['execution_time'] = round( $execution_time, 2 );

		// Store last cleanup results for display in admin (serves as the cleanup log).
		update_option(
			'abilities_bridge_last_cleanup',
			array(
				'timestamp' => current_time( 'mysql' ),
				'results'   => $results,
			)
		);

		return $results;
	}

	/**
	 * Unschedule the cleanup cron job
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Get last cleanup information
	 *
	 * @return array|false Last cleanup data or false if never run
	 */
	public static function get_last_cleanup_info() {
		return get_option( 'abilities_bridge_last_cleanup', false );
	}

	/**
	 * Manually trigger cleanup (for testing or admin action)
	 *
	 * @return array Cleanup results
	 */
	public static function manual_cleanup() {
		return self::run_cleanup();
	}
}

// Initialize on WordPress init.
add_action( 'init', array( 'Abilities_Bridge_Log_Cleanup', 'init' ) );
