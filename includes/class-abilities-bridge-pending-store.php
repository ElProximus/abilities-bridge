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
 * @since 1.3.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB-backed replacement for the OAuth flow's short-lived transients.
 *
 * @since 1.3.4
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
	 * @return void
	 */
	public static function set( $key, $value, $expiration ) {
		$entries = self::get_entries();

		$entries[ $key ] = array(
			'value'   => $value,
			'expires' => time() + (int) $expiration,
		);

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Retrieve and delete a value (one-time use).
	 *
	 * Expired entries are pruned on every call so the option cannot grow
	 * unbounded when flows are abandoned.
	 *
	 * @param string $key Key to consume.
	 * @return mixed Stored value, or false if missing or expired.
	 */
	public static function take( $key ) {
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

	/**
	 * Remove expired entries. Intended for the daily OAuth cleanup cron.
	 *
	 * @return void
	 */
	public static function cleanup_expired() {
		$entries = self::prune_expired( self::get_entries() );
		update_option( self::OPTION_NAME, $entries, false );
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
