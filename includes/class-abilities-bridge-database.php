<?php
/**
 * Database operations class.
 *
 * This file uses direct database queries for custom plugin tables.
 * WordPress object cache is not used as these are plugin-specific tables
 * that don't benefit from persistent caching in most use cases.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database class.
 *
 * Handles all database operations for the plugin.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Database {

	// Table name constants (without prefix).
	const TABLE_CONVERSATIONS       = 'abilities_bridge_conversations';
	const TABLE_MESSAGES            = 'abilities_bridge_messages';
	const TABLE_LOGS                = 'abilities_bridge_logs';
	const TABLE_ABILITY_PERMISSIONS = 'abilities_bridge_ability_permissions';
	const TABLE_MEMORIES            = 'abilities_bridge_memories';
	const TABLE_OAUTH_CLIENTS       = 'abilities_bridge_oauth_clients';
	const TABLE_OAUTH_CODES         = 'abilities_bridge_oauth_authorization_codes';
	const TABLE_OAUTH_TOKENS        = 'abilities_bridge_oauth_access_tokens';
	const TABLE_ACTIVITY_LOG        = 'abilities_bridge_activity_log';

	/**
	 * Get full table name with prefix.
	 *
	 * @param string $table_base Table constant (e.g., self::TABLE_CONVERSATIONS).
	 * @return string Full table name with wpdb prefix, or empty string if invalid.
	 */
	public static function table( $table_base ) {
		global $wpdb;

		$allowed = array(
			self::TABLE_CONVERSATIONS,
			self::TABLE_MESSAGES,
			self::TABLE_LOGS,
			self::TABLE_ABILITY_PERMISSIONS,
			self::TABLE_MEMORIES,
			self::TABLE_OAUTH_CLIENTS,
			self::TABLE_OAUTH_CODES,
			self::TABLE_OAUTH_TOKENS,
			self::TABLE_ACTIVITY_LOG,
		);

		if ( ! in_array( $table_base, $allowed, true ) ) {
			return '';
		}

		return $wpdb->prefix . $table_base;
	}

	/**
	 * Create database tables on plugin activation
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Conversations table.
		$conversations_table = self::table( self::TABLE_CONVERSATIONS );
		$conversations_sql   = "CREATE TABLE IF NOT EXISTS $conversations_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			title varchar(255) NOT NULL DEFAULT 'New Conversation',
			provider varchar(20) NOT NULL DEFAULT 'anthropic',
			model varchar(100) NOT NULL DEFAULT 'claude-sonnet-4-5-20250929',
			parent_conversation_id bigint(20) UNSIGNED DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
			deleted_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY deleted_at (deleted_at),
			KEY parent_conversation_id (parent_conversation_id),
			KEY provider (provider)
		) $charset_collate;";

		// Messages table.
		$messages_table = self::table( self::TABLE_MESSAGES );
		$messages_sql   = "CREATE TABLE IF NOT EXISTS $messages_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) UNSIGNED NOT NULL,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Logs table.
		$logs_table = self::table( self::TABLE_LOGS );
		$logs_sql   = "CREATE TABLE IF NOT EXISTS $logs_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) UNSIGNED DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'admin',
			user_id bigint(20) UNSIGNED NOT NULL,
			username varchar(255) NOT NULL,
			action varchar(255) NOT NULL,
			function_name varchar(100) DEFAULT NULL,
			function_input longtext DEFAULT NULL,
			function_output longtext DEFAULT NULL,
			error_message text DEFAULT NULL,
			stack_trace text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY source (source),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY action (action),
			KEY action_created_at (action, created_at)
		) $charset_collate;";

		// Ability Permissions table (hardcoded permission system).
		$ability_permissions_table = self::table( self::TABLE_ABILITY_PERMISSIONS );
		$ability_permissions_sql   = "CREATE TABLE IF NOT EXISTS $ability_permissions_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			ability_name varchar(255) NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 0,
			max_per_day int NOT NULL DEFAULT 0,
			max_per_hour int NOT NULL DEFAULT 0,
			max_per_request int NOT NULL DEFAULT 1,
			risk_level enum('low', 'medium', 'high') DEFAULT 'high',
			requires_user_approval tinyint(1) NOT NULL DEFAULT 1,
			requires_admin_approval tinyint(1) NOT NULL DEFAULT 0,
			min_capability varchar(100) DEFAULT NULL,
			allowed_input_types varchar(255) DEFAULT NULL,
			input_validation_function varchar(255) DEFAULT NULL,
			output_sanitization_function varchar(255) DEFAULT NULL,
			description text DEFAULT NULL,
			reason_for_approval text DEFAULT NULL,
			approved_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
			approved_date datetime DEFAULT NULL,
			enabled_date datetime DEFAULT NULL,
			disabled_date datetime DEFAULT NULL,
			last_executed datetime DEFAULT NULL,
			execution_count bigint(20) UNSIGNED DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY ability_name (ability_name),
			KEY enabled (enabled),
			KEY risk_level (risk_level)
		) $charset_collate;";

		// Memories table (database-based memory storage).
		$memories_table = self::table( self::TABLE_MEMORIES );
		$memories_sql   = "CREATE TABLE IF NOT EXISTS $memories_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			path varchar(500) NOT NULL,
			content longtext DEFAULT NULL,
			type enum('file', 'directory') NOT NULL DEFAULT 'file',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY path (path),
			KEY type (type),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $conversations_sql );
		dbDelta( $messages_sql );
		dbDelta( $logs_sql );
		dbDelta( $ability_permissions_sql );
		dbDelta( $memories_sql );

		// Run upgrade for existing installations.
		self::upgrade_database();
	}

	/**
	 * Upgrade database schema for existing installations
	 */
	public static function upgrade_database() {
		global $wpdb;
		$conversations_table = self::table( self::TABLE_CONVERSATIONS );
		$logs_table          = self::table( self::TABLE_LOGS );
		$ability_table       = self::table( self::TABLE_ABILITY_PERMISSIONS );

		// Check if model column exists.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'model'",
				DB_NAME,
				$conversations_table
			)
		);

		// Add model column if it doesn't exist.
		if ( empty( $column_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN model varchar(100) NOT NULL DEFAULT %s AFTER title',
					$conversations_table,
					'claude-sonnet-4-5-20250929'
				)
			);
		}

		// Check if provider column exists.
		$provider_column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'provider'",
				DB_NAME,
				$conversations_table
			)
		);

		// Add provider column if it doesn't exist.
		if ( empty( $provider_column_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"ALTER TABLE %i ADD COLUMN provider varchar(20) NOT NULL DEFAULT 'anthropic' AFTER title, ADD KEY provider (provider)",
					$conversations_table
				)
			);
		}

		// Backfill provider based on model prefix if missing.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET provider = 'openai' WHERE (provider IS NULL OR provider = '') AND model LIKE 'gpt-%'",
				$conversations_table
			)
		);

		// Check if parent_conversation_id column exists.
		$parent_column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'parent_conversation_id'",
				DB_NAME,
				$conversations_table
			)
		);

		// Add parent_conversation_id column if it doesn't exist.
		if ( empty( $parent_column_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN parent_conversation_id bigint(20) UNSIGNED DEFAULT NULL AFTER model, ADD KEY parent_conversation_id (parent_conversation_id)',
					$conversations_table
				)
			);
		}

		// Check if deleted_at column exists in conversations table.
		$deleted_at_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'deleted_at'",
				DB_NAME,
				$conversations_table
			)
		);

		// Add soft delete columns if they don't exist.
		if ( empty( $deleted_at_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN deleted_at datetime DEFAULT NULL, ADD COLUMN deleted_by_user_id bigint(20) UNSIGNED DEFAULT NULL, ADD KEY deleted_at (deleted_at)',
					$conversations_table
				)
			);
		}

		// Check if source column exists in logs table.
		$source_column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'source'",
				DB_NAME,
				$logs_table
			)
		);

		// Add source column if it doesn't exist.
		if ( empty( $source_column_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"ALTER TABLE %i ADD COLUMN source varchar(20) NOT NULL DEFAULT 'admin' AFTER conversation_id, ADD KEY source (source)",
					$logs_table
				)
			);
		}

		// Migrate 'critical' risk level to 'high' (critical was removed).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET risk_level = 'high' WHERE risk_level = 'critical'",
				$ability_table
			)
		);

		// Alter the enum to remove 'critical' option.
		$wpdb->query(
			$wpdb->prepare(
				"ALTER TABLE %i MODIFY risk_level enum('low', 'medium', 'high') DEFAULT 'high'",
				$ability_table
			)
		);

		// Add composite index for action + created_at (performance optimization).
		$index_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND INDEX_NAME = 'action_created_at'",
				DB_NAME,
				$logs_table
			)
		);

		// Add composite index if it doesn't exist.
		if ( empty( $index_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD KEY action_created_at (action, created_at)',
					$logs_table
				)
			);
		}
	}

	/**
	 * Create plugin directories on activation
	 */
	public static function create_plugin_directories() {
		$dir = ABILITIES_BRIDGE_CONTENT_DIR;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Create memories directory.
		$memories_dir = $dir . 'memories';
		if ( ! file_exists( $memories_dir ) ) {
			wp_mkdir_p( $memories_dir ); // WordPress handles permissions automatically.
		}
	}

	/**
	 * Legacy function name for backwards compatibility
	 *
	 * @deprecated Use create_plugin_directories() instead
	 */
	public static function create_website_md() {
		self::create_plugin_directories();
	}

	/**
	 * Get all conversations for a user
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_conversations( $user_id ) {
		global $wpdb;

		// Exclude soft-deleted conversations (deleted_at IS NULL).
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 50',
				self::table( self::TABLE_CONVERSATIONS ),
				$user_id
			),
			ARRAY_A
		);
	}

	/**
	 * Create a new conversation
	 *
	 * @param int    $user_id User ID.
	 * @param string $title Conversation title.
	 * @param string $model Model identifier (default: claude-sonnet-4-5-20250929).
	 * @param string $provider Provider key (default: anthropic).
	 * @return int|false Conversation ID or false on failure
	 */
	public static function create_conversation( $user_id, $title = 'New Conversation', $model = 'claude-sonnet-4-5-20250929', $provider = 'anthropic' ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'user_id' => $user_id,
				'title'   => $title,
				'provider' => $provider,
				'model'   => $model,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get a conversation by ID
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return object|null
	 */
	public static function get_conversation( $conversation_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::table( self::TABLE_CONVERSATIONS ),
				$conversation_id
			)
		);
	}

	/**
	 * Soft delete a conversation (archives for 30 days)
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function delete_conversation( $conversation_id ) {
		global $wpdb;

		// Soft delete: Set deleted_at timestamp and deleted_by_user_id.
		$result = $wpdb->update(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'deleted_at'         => current_time( 'mysql' ),
				'deleted_by_user_id' => get_current_user_id(),
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Restore a soft-deleted conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function restore_conversation( $conversation_id ) {
		global $wpdb;

		// Clear deleted_at and deleted_by_user_id to restore.
		$result = $wpdb->update(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'deleted_at'         => null,
				'deleted_by_user_id' => null,
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Permanently delete a conversation and all its messages and logs
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function permanently_delete_conversation( $conversation_id ) {
		global $wpdb;

		// Hard delete messages.
		$wpdb->delete( self::table( self::TABLE_MESSAGES ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );

		// Hard delete logs.
		$wpdb->delete( self::table( self::TABLE_LOGS ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );

		// Hard delete conversation.
		$result = $wpdb->delete( self::table( self::TABLE_CONVERSATIONS ), array( 'id' => $conversation_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Add a message to a conversation
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role Message role (user/assistant).
	 * @param string $content Message content.
	 * @return int|false Message ID or false on failure
	 */
	public static function add_message( $conversation_id, $role, $content ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::table( self::TABLE_MESSAGES ),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $content,
			),
			array( '%d', '%s', '%s' )
		);

		// Update conversation updated_at timestamp.
		if ( $result ) {
			$wpdb->update(
				self::table( self::TABLE_CONVERSATIONS ),
				array( 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $conversation_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get all messages for a conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public static function get_messages( $conversation_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %d ORDER BY created_at ASC',
				self::table( self::TABLE_MESSAGES ),
				$conversation_id
			)
		);
	}

	/**
	 * Add a log entry
	 *
	 * @param array $args Log arguments.
	 * @return int|false Log ID or false on failure
	 */
	public static function add_log( $args ) {
		global $wpdb;

		$defaults = array(
			'conversation_id' => null,
			'user_id'         => get_current_user_id(),
			'username'        => wp_get_current_user()->user_login,
			'action'          => '',
			'function_name'   => null,
			'function_input'  => null,
			'function_output' => null,
			'error_message'   => null,
			'stack_trace'     => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$result = $wpdb->insert(
			self::table( self::TABLE_LOGS ),
			$args,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get logs for a conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public static function get_logs( $conversation_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %d ORDER BY created_at ASC',
				self::table( self::TABLE_LOGS ),
				$conversation_id
			)
		);
	}

	/**
	 * Get soft-deleted conversations (for archive view)
	 *
	 * @return array
	 */
	public static function get_deleted_conversations() {
		global $wpdb;

		// Get conversations where deleted_at is NOT NULL.
		// Include message count and deleted user info.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*,
					COUNT(m.id) as message_count,
					u.user_login as deleted_by_username,
					TIMESTAMPDIFF(SECOND, c.deleted_at, NOW()) as seconds_since_deleted
				FROM %i c
				LEFT JOIN %i m ON c.id = m.conversation_id
				LEFT JOIN %i u ON c.deleted_by_user_id = u.ID
				WHERE c.deleted_at IS NOT NULL
				GROUP BY c.id
				ORDER BY c.deleted_at DESC',
				self::table( self::TABLE_CONVERSATIONS ),
				self::table( self::TABLE_MESSAGES ),
				$wpdb->users
			),
			ARRAY_A
		);

		return $results;
	}

	/**
	 * Get filtered activity logs with pagination
	 *
	 * @param array $filters Filter parameters (user_id, function_name, status, date_from, date_to, search, conversation_id).
	 * @param int   $page    Page number (1-based).
	 * @param int   $per_page Items per page.
	 * @return array Array with 'items', 'total', 'page', 'per_page', 'total_pages'
	 */
	public static function get_logs_filtered( $filters = array(), $page = 1, $per_page = 50 ) {
		global $wpdb;

		$logs_table          = self::table( self::TABLE_LOGS );
		$conversations_table = self::table( self::TABLE_CONVERSATIONS );
		$offset              = ( $page - 1 ) * $per_page;

		/*
		 * Security note: The $conditions array contains ONLY hardcoded SQL fragments with
		 * placeholders (e.g., 'l.user_id = %d'). No user input is ever added to this array.
		 * All user-provided values go into $values array and are escaped via wpdb::prepare().
		 */
		$conditions = array();
		$values     = array();

		// Always include table names first for %i placeholders.
		$count_base_values  = array( $logs_table );
		$select_base_values = array( $logs_table, $conversations_table );

		if ( ! empty( $filters['user_id'] ) ) {
			$conditions[] = 'l.user_id = %d';
			$values[]     = $filters['user_id'];
		}

		if ( ! empty( $filters['function_name'] ) ) {
			$conditions[] = 'l.function_name = %s';
			$values[]     = sanitize_text_field( $filters['function_name'] );
		}

		if ( ! empty( $filters['status'] ) ) {
			if ( 'success' === $filters['status'] ) {
				$conditions[] = 'l.error_message IS NULL';
			} elseif ( 'error' === $filters['status'] ) {
				$conditions[] = 'l.error_message IS NOT NULL';
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$date_from = sanitize_text_field( $filters['date_from'] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
				$conditions[] = 'l.created_at >= %s';
				$values[]     = $date_from;
			}
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$date_to = sanitize_text_field( $filters['date_to'] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$conditions[] = 'l.created_at <= %s';
				$values[]     = $date_to;
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$conditions[] = '(l.action LIKE %s OR l.error_message LIKE %s)';
			$search_term  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$values[]     = $search_term;
			$values[]     = $search_term;
		}

		if ( ! empty( $filters['conversation_id'] ) ) {
			$conditions[] = 'l.conversation_id = %d';
			$values[]     = $filters['conversation_id'];
		}

		// Build WHERE clause - $conditions contains only hardcoded placeholder strings, never user input.
		$where_sql = empty( $conditions ) ? '1=1' : implode( ' AND ', $conditions );

		// Count query.
		$count_sql    = "SELECT COUNT(*) FROM %i l WHERE {$where_sql}";
		$count_values = array_merge( $count_base_values, $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built from hardcoded placeholders only.
		$total = $wpdb->get_var( $wpdb->prepare( $count_sql, $count_values ) );

		// Results query.
		$select_sql    = "SELECT l.*, c.title as conversation_title, c.deleted_at as conversation_deleted_at
			FROM %i l
			LEFT JOIN %i c ON l.conversation_id = c.id
			WHERE {$where_sql}
			ORDER BY l.created_at DESC
			LIMIT %d OFFSET %d";
		$select_values = array_merge( $select_base_values, $values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built from hardcoded placeholders only.
		$results = $wpdb->get_results( $wpdb->prepare( $select_sql, $select_values ), ARRAY_A );

		return array(
			'items'       => $results,
			'total'       => (int) $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Purge logs older than specified days
	 *
	 * @param int $days Number of days to keep (logs older than this will be deleted).
	 * @return int Number of rows deleted
	 */
	public static function purge_old_logs( $days ) {
		global $wpdb;

		// Calculate cutoff date.
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Delete old logs.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				self::table( self::TABLE_LOGS ),
				$cutoff_date
			)
		);

		return $result;
	}

	/**
	 * Purge soft-deleted conversations older than 30 days
	 *
	 * @return int Number of conversations permanently deleted
	 */
	public static function purge_old_deleted_conversations() {
		global $wpdb;

		// Get conversations deleted more than 30 days ago.
		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		$old_conversations = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE deleted_at IS NOT NULL AND deleted_at < %s',
				self::table( self::TABLE_CONVERSATIONS ),
				$cutoff_date
			)
		);

		$count = 0;
		foreach ( $old_conversations as $conversation_id ) {
			if ( self::permanently_delete_conversation( $conversation_id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get database statistics for logs
	 *
	 * @return array Statistics array
	 */
	public static function get_log_statistics() {
		global $wpdb;

		$logs_table = self::table( self::TABLE_LOGS );
		$stats      = array();

		// Total log count.
		$stats['total_logs'] = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $logs_table )
		);

		// Oldest log date.
		$stats['oldest_log'] = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(created_at) FROM %i', $logs_table )
		);

		// Database size (approximate).
		$table_status        = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT data_length + index_length as size FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$logs_table
			)
		);
		$stats['size_bytes'] = $table_status ? $table_status->size : 0;
		$stats['size_mb']    = round( $stats['size_bytes'] / 1024 / 1024, 2 );

		return $stats;
	}

	/**
	 * Validate table name against allowlist
	 *
	 * Security: Ensures only plugin tables can be used in dynamic SQL statements.
	 * Table names are checked against a whitelist to prevent SQL injection attacks.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @return string|false Validated table name or false if invalid
	 */
	private static function validate_table_name( $table_name ) {
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
}
