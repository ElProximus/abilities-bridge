<?php
/**
 * Pending Store
 *
 * Short-lived, one-time-use server-side storage for in-flight OAuth state
 * (authorization requests and consent tokens).
 *
 * Values are stored in a single non-autoloaded option rather than in
 * transients. With an external object cache drop-in installed, transients are
 * never written to the database, so a broken, flushed, or evicting cache
 * backend silently loses them mid-flow. Options are always persisted to the
 * database and merely cached, so a cache miss falls back to the database
 * instead of failing the flow.
 *
 * @package Abilities_Bridge
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB-backed replacement for the OAuth flow's short-lived transients.
 *
 * @since 1.4.0
 */
class Abilities_Bridge_Pending_Store {

	/**
	 * Option name holding all pending entries.
	 */
	const OPTION_NAME = 'abilities_bridge_oauth_pending';

	/**
	 * Store a value under a key with an expiry.
	 *
	 * @param string $key        Unique key for the pending value.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Lifetime in seconds.
	 * @return true|WP_Error True on success, or a retryable lock error.
	 */
	public static function set( $key, $value, $expiration ) {
		return self::locked(
			function () use ( $key, $value, $expiration ) {
				$entries = self::get_entries();

				$entries[ $key ] = array(
					'value'   => $value,
					'expires' => time() + (int) $expiration,
				);

				update_option( self::OPTION_NAME, $entries, false );
				return true;
			}
		);
	}

	/**
	 * Retrieve and delete a value (one-time use).
	 *
	 * Expired entries are pruned on every call so the option cannot grow
	 * unbounded when flows are abandoned.
	 *
	 * @param string $key Key to consume.
	 * @return mixed|WP_Error Stored value, false if missing/expired, or a retryable lock error.
	 */
	public static function take( $key ) {
		return self::locked(
			function () use ( $key ) {
				$entries = self::get_entries();
				$value   = false;

				if ( isset( $entries[ $key ] ) ) {
					if ( time() <= (int) ( $entries[ $key ]['expires'] ?? 0 ) ) {
						$value = $entries[ $key ]['value'];
					}
					unset( $entries[ $key ] );
				}

				$entries = self::prune_expired( $entries );
				update_option( self::OPTION_NAME, $entries, false );

				return $value;
			}
		);
	}

	/**
	 * Remove expired entries. Intended for the daily OAuth cleanup cron.
	 *
	 * @return true|WP_Error True on success, or a retryable lock error.
	 */
	public static function cleanup_expired() {
		return self::locked(
			function () {
				$entries = self::prune_expired( self::get_entries() );
				update_option( self::OPTION_NAME, $entries, false );
				return true;
			}
		);
	}

	/**
	 * Run a read-modify-write cycle under a MySQL advisory lock.
	 *
	 * The set(), take(), and cleanup-cron paths all read the whole option, mutate
	 * the array in memory, and write it back. Two concurrent OAuth flows
	 * doing that dance each write only their own view - the second write
	 * silently erases the first flow's entry, which then dies with
	 * "Authorization request has expired or is invalid" (the exact symptom
	 * the DB-backed store was built to eliminate, reachable as a race
	 * instead of a cache bug). One named lock per site serializes the
	 * cycle; entry keys stay per-request random as before. If the database does
	 * not grant the lock, running the operation would restore the lost-update
	 * race, so the store fails closed with a temporary retry error and performs
	 * no read or write.
	 *
	 * @param callable $operation Operation to run while holding the lock.
	 * @return mixed|WP_Error The operation result, or a retryable lock error.
	 */
	private static function locked( $operation ) {
		global $wpdb;

		$lock_name = 'ab_oauth_pending_' . $wpdb->prefix;
		$acquired  = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name ) );

		if ( ! $acquired ) {
			return new WP_Error(
				'abilities_bridge_pending_store_busy',
				__( 'OAuth authorization is temporarily busy because its database lock could not be obtained. The unsafe operation was not run; please restart the connection.', 'abilities-bridge' ),
				array( 'status' => 503 )
			);
		}

		try {
			return $operation();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Fetch all stored entries.
	 *
	 * @return array
	 */
	private static function get_entries() {
		$entries = get_option( self::OPTION_NAME, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Filter out expired entries.
	 *
	 * @param array $entries Stored entries.
	 * @return array
	 */
	private static function prune_expired( $entries ) {
		$now = time();

		return array_filter(
			$entries,
			function ( $entry ) use ( $now ) {
				return is_array( $entry ) && $now <= (int) ( $entry['expires'] ?? 0 );
			}
		);
	}
}
