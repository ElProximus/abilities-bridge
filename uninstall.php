<?php
/**
 * Uninstall script.
 *
 * @package Abilities_Bridge
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Validate table name against allowlist.
 *
 * Security: Ensures only plugin tables can be dropped during uninstall.
 * Table names are checked against a whitelist to prevent accidental data loss.
 *
 * @param string $table_name Full table name including prefix.
 * @return string|false Validated table name or false if invalid.
 */
function abilities_bridge_validate_table_name( $table_name ) {
	global $wpdb;

	// Only allow alphanumeric characters and underscores.
	if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
		return false;
	}

	// Allowlist of plugin tables.
	$allowed_tables = array(
		'abilities_bridge_conversations',
		'abilities_bridge_messages',
		'abilities_bridge_logs',
		'abilities_bridge_ability_permissions',
		'abilities_bridge_oauth_clients',
		'abilities_bridge_oauth_authorization_codes',
		'abilities_bridge_oauth_access_tokens',
		'abilities_bridge_activity_log',
		'abilities_bridge_memories',
	);

	// Remove prefix and check against allowlist.
	$base_name = str_replace( $wpdb->prefix, '', $table_name );

	return in_array( $base_name, $allowed_tables, true ) ? $table_name : false;
}

/**
 * Check path prefix with directory-boundary awareness.
 *
 * @param string $path Path.
 * @param string $base Base path.
 * @return bool
 */
function abilities_bridge_uninstall_path_has_prefix( $path, $base ) {
	$path = rtrim( str_replace( '\\', '/', $path ), '/' );
	$base = rtrim( str_replace( '\\', '/', $base ), '/' );

	return $path === $base || 0 === strpos( $path, $base . '/' );
}

/**
 * List direct directory entries, including dotfiles.
 *
 * @param string $directory Directory path.
 * @return array|false
 */
function abilities_bridge_uninstall_list_entries( $directory ) {
	$names = scandir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
	if ( false === $names ) {
		return false;
	}

	$entries = array();
	foreach ( $names as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$entries[] = trailingslashit( $directory ) . $name;
	}

	return $entries;
}

/**
 * Confirm a path is inside the attachment root.
 *
 * Symlinks are guarded by their parent directory so uninstall can unlink the
 * symlink itself without following it.
 *
 * @param string $path Path.
 * @param string $base_real Resolved attachment root.
 * @return bool
 */
function abilities_bridge_uninstall_is_safe_attachment_path( $path, $base_real ) {
	if ( is_link( $path ) ) {
		$parent_real = realpath( dirname( $path ) );
		return false !== $parent_real && abilities_bridge_uninstall_path_has_prefix( $parent_real, $base_real );
	}

	$path_real = realpath( $path );
	if ( false === $path_real ) {
		return true;
	}

	return abilities_bridge_uninstall_path_has_prefix( $path_real, $base_real );
}

/**
 * Delete one file or symlink from the attachment tree.
 *
 * @param string $path Path.
 * @param string $base_real Resolved attachment root.
 * @return bool
 */
function abilities_bridge_uninstall_delete_file_entry( $path, $base_real ) {
	if ( ! abilities_bridge_uninstall_is_safe_attachment_path( $path, $base_real ) ) {
		return false;
	}

	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		return true;
	}

	return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
}

/**
 * Delete direct children of a flat attachment directory without following symlinks.
 *
 * @param string $directory Directory path.
 * @param string $base_real Resolved attachment root.
 * @param bool   $remove_directory Whether to remove the directory after clearing it.
 * @return bool
 */
function abilities_bridge_uninstall_delete_flat_attachment_directory( $directory, $base_real, $remove_directory = true ) {
	if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
		return true;
	}

	if ( is_link( $directory ) || is_file( $directory ) ) {
		return abilities_bridge_uninstall_delete_file_entry( $directory, $base_real );
	}

	if ( ! abilities_bridge_uninstall_is_safe_attachment_path( $directory, $base_real ) ) {
		return false;
	}

	$success = true;
	$entries = abilities_bridge_uninstall_list_entries( $directory );
	if ( false === $entries ) {
		return false;
	}

	foreach ( $entries as $entry ) {
		if ( is_link( $entry ) || is_file( $entry ) ) {
			$success = abilities_bridge_uninstall_delete_file_entry( $entry, $base_real ) && $success;
		} elseif ( is_dir( $entry ) ) {
			$child_entries = abilities_bridge_uninstall_list_entries( $entry );
			if ( array() === $child_entries ) {
				$success = rmdir( $entry ) && $success; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				$success = abilities_bridge_uninstall_delete_flat_attachment_directory( $entry, $base_real, true ) && $success;
			}
		}
	}

	if ( $remove_directory && file_exists( $directory ) ) {
		$success = rmdir( $directory ) && $success; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	return $success;
}

/**
 * Delete private image attachment files for the current site's upload directory.
 *
 * Note: this mirrors the rest of this uninstall script and does not iterate
 * every site in a multisite network.
 *
 * @return void
 */
function abilities_bridge_uninstall_delete_attachments() {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$attachments_dir = trailingslashit( $upload_dir['basedir'] ) . 'abilities-bridge/attachments';

	if ( is_link( $attachments_dir ) ) {
		unlink( $attachments_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return;
	}

	if ( ! file_exists( $attachments_dir ) ) {
		return;
	}

	$base_real = realpath( $attachments_dir );
	if ( false === $base_real ) {
		return;
	}

	abilities_bridge_uninstall_delete_flat_attachment_directory( $attachments_dir, $base_real, true );
}

/**
 * Remove all plugin data for the current site.
 *
 * Per-site on multisite: table prefixes, options, transients, the
 * uploads attachments directory, and cron events all resolve through
 * the switched site's context. (The user-meta deletions are network
 * -global and simply no-op on repeat passes.)
 *
 * @return void
 */
function abilities_bridge_uninstall_current_site() {
	global $wpdb;

	abilities_bridge_uninstall_delete_attachments();

	// Delete plugin options.
	delete_option( 'abilities_bridge_api_key' );
	delete_option( 'abilities_bridge_openai_api_key' );
	delete_option( 'abilities_bridge_ai_provider' );
	delete_option( 'abilities_bridge_system_prompt' );
	delete_option( 'abilities_bridge_cache_stats' );
	delete_option( 'abilities_bridge_enable_memory' );
	delete_option( 'abilities_bridge_memory_consent' );
	delete_option( 'abilities_bridge_enable_image_attachments' );
	delete_option( 'abilities_bridge_db_version' );
	delete_option( 'abilities_bridge_db_upgrade_retry_after' );
	delete_option( 'abilities_bridge_db_upgrade_error' );
	delete_option( 'abilities_bridge_needs_ability_registration' );
	delete_option( 'abilities_bridge_use_wp_ai_client' );

	// Delete OAuth options.
	delete_option( 'abilities_bridge_mcp_oauth' );
	delete_option( 'abilities_bridge_oauth_rate_limits' );
	delete_option( 'abilities_bridge_oauth_lockouts' );
	delete_option( 'abilities_bridge_oauth_codes' );

	// Delete tool enable/disable options.
	delete_option( 'abilities_bridge_enable_memory' );
	delete_option( 'abilities_bridge_enable_abilities_api' );

	// Delete user meta for model preferences.
	delete_metadata( 'user', 0, 'abilities_bridge_selected_model', '', true );
	delete_metadata( 'user', 0, 'abilities_bridge_selected_model_anthropic', '', true );
	delete_metadata( 'user', 0, 'abilities_bridge_selected_model_openai', '', true );
	delete_metadata( 'user', 0, 'abilities_bridge_selected_provider', '', true );

	// Drop database tables with validation.
	$abilities_bridge_tables = array(
		'abilities_bridge_conversations',
		'abilities_bridge_messages',
		'abilities_bridge_logs',
		'abilities_bridge_ability_permissions',
		'abilities_bridge_oauth_clients',
		'abilities_bridge_oauth_authorization_codes',
		'abilities_bridge_oauth_access_tokens',
		'abilities_bridge_activity_log',
		'abilities_bridge_memories',
		'abilities_bridge_chat_job_steps',
		'abilities_bridge_chat_jobs',
	);

	foreach ( $abilities_bridge_tables as $abilities_bridge_table_base ) {
		$abilities_bridge_table_full      = $wpdb->prefix . $abilities_bridge_table_base;
		$abilities_bridge_table_validated = abilities_bridge_validate_table_name( $abilities_bridge_table_full );

		if ( $abilities_bridge_table_validated ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $abilities_bridge_table_validated ) );
		}
	}

	// Delete known transients using WordPress API.
	delete_transient( 'abilities_bridge_activation_redirect' );
	delete_transient( 'abilities_bridge_new_credentials' );

	// Delete pending one-time credentials display (stored as an option since
	// 1.3.3; one option per user — name suffixed with user ID — since the
	// concurrent-admin fix, plus the legacy shared name).
	delete_option( 'abilities_bridge_new_credentials_pending' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prefix scan over per-user options has no options-API equivalent.
	$abilities_bridge_pending_credential_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'abilities_bridge_new_credentials_pending_' ) . '%'
		)
	);

	foreach ( (array) $abilities_bridge_pending_credential_options as $abilities_bridge_pending_credential_option ) {
		delete_option( $abilities_bridge_pending_credential_option );
	}

	// Delete pending in-flight OAuth state (stored as an option since 1.3.3).
	delete_option( 'abilities_bridge_oauth_pending' );

	// Unschedule any cron jobs.
	wp_clear_scheduled_hook( 'abilities_bridge_oauth_cleanup' );
	wp_clear_scheduled_hook( 'abilities_bridge_log_cleanup' );
	wp_clear_scheduled_hook( 'abilities_bridge_daily_cleanup' );
	wp_clear_scheduled_hook( 'abilities_bridge_run_chat_job' );
}

if ( is_multisite() ) {
	$abilities_bridge_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $abilities_bridge_site_ids as $abilities_bridge_site_id ) {
		switch_to_blog( (int) $abilities_bridge_site_id );
		abilities_bridge_uninstall_current_site();
		restore_current_blog();
	}
} else {
	abilities_bridge_uninstall_current_site();
}
