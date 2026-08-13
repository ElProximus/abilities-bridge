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
	const TABLE_CHAT_JOBS           = 'abilities_bridge_chat_jobs';
	const TABLE_CHAT_JOB_STEPS      = 'abilities_bridge_chat_job_steps';

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
			self::TABLE_CHAT_JOBS,
			self::TABLE_CHAT_JOB_STEPS,
		);

		if ( ! in_array( $table_base, $allowed, true ) ) {
			return '';
		}

		return $wpdb->prefix . $table_base;
	}

	/**
	 * Create or upgrade database tables.
	 *
	 * @return bool Whether the durable schema is available.
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
			model varchar(100) NOT NULL DEFAULT 'claude-sonnet-4-6',
			last_openai_response_id varchar(255) DEFAULT NULL,
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
			KEY provider (provider),
			KEY last_openai_response_id (last_openai_response_id)
		) $charset_collate;";

		// Messages table.
		$messages_table = self::table( self::TABLE_MESSAGES );
		$messages_sql   = "CREATE TABLE IF NOT EXISTS $messages_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) UNSIGNED NOT NULL,
			job_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			job_round int(10) UNSIGNED NOT NULL DEFAULT 0,
			context_visible tinyint(1) UNSIGNED NOT NULL DEFAULT 1,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY job_round (job_id, job_round),
			KEY conversation_context (conversation_id, context_visible),
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

		// Durable background chat jobs. Messages retain a nullable-equivalent
		// job_id so superseded turns remain visible to the user while being
		// excluded from future provider context.
		$chat_jobs_table = self::table( self::TABLE_CHAT_JOBS );
		$chat_jobs_sql   = "CREATE TABLE IF NOT EXISTS $chat_jobs_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) UNSIGNED NOT NULL,
			user_id bigint(20) UNSIGNED NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'preparing',
			phase varchar(20) NOT NULL DEFAULT 'ready',
			provider varchar(20) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			plan_mode tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			user_message_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			public_token char(64) NOT NULL,
			runner_token char(64) NOT NULL,
			lock_token char(64) NOT NULL DEFAULT '',
			cancel_requested tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			superseded_by_job_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			attachment_json longtext DEFAULT NULL,
			provider_response_id varchar(191) NOT NULL DEFAULT '',
			recovery_status varchar(20) NOT NULL DEFAULT '',
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message text DEFAULT NULL,
			rounds_completed int(10) UNSIGNED NOT NULL DEFAULT 0,
			last_activity varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			last_dispatched_at datetime DEFAULT NULL,
			heartbeat_at datetime DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_token (public_token),
			KEY conversation_status (conversation_id, status),
			KEY user_status (user_id, status),
			KEY status_created (status, created_at)
		) $charset_collate;";

		$chat_steps_table = self::table( self::TABLE_CHAT_JOB_STEPS );
		$chat_steps_sql   = "CREATE TABLE IF NOT EXISTS $chat_steps_table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id bigint(20) UNSIGNED NOT NULL,
			round_number int(10) UNSIGNED NOT NULL,
			tool_use_id varchar(191) NOT NULL,
			tool_name varchar(191) NOT NULL,
			is_readonly tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			input_json longtext DEFAULT NULL,
			result_json longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_tool (job_id, tool_use_id),
			KEY job_round (job_id, round_number),
			KEY job_status (job_id, status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $conversations_sql );
		dbDelta( $messages_sql );
		dbDelta( $logs_sql );
		dbDelta( $ability_permissions_sql );
		dbDelta( $memories_sql );
		dbDelta( $chat_jobs_sql );
		dbDelta( $chat_steps_sql );

		// Run upgrade for existing installations.
		self::upgrade_database();

		$jobs_exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $chat_jobs_table ) ) );
		$steps_exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $chat_steps_table ) ) );
		$job_id_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $messages_table, 'job_id' ) );
		$job_round_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $messages_table, 'job_round' ) );
		$visible_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $messages_table, 'context_visible' ) );

		return $jobs_exists === $chat_jobs_table
			&& $steps_exists === $chat_steps_table
			&& 'job_id' === $job_id_exists
			&& 'job_round' === $job_round_exists
			&& 'context_visible' === $visible_exists;
	}

	/**
	 * Upgrade database schema for existing installations
	 */
	public static function upgrade_database() {
		global $wpdb;
		$conversations_table = self::table( self::TABLE_CONVERSATIONS );
		$logs_table          = self::table( self::TABLE_LOGS );
		$ability_table       = self::table( self::TABLE_ABILITY_PERMISSIONS );
		$messages_table      = self::table( self::TABLE_MESSAGES );

		// dbDelta does not reliably add multiple new columns and composite
		// indexes to this long-lived custom table, so checkpoint fields use an
		// explicit idempotent migration inside the DB-version gate.
		$message_columns = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$messages_table
			)
		);
		if ( ! in_array( 'job_id', $message_columns, true ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN job_id bigint(20) UNSIGNED NOT NULL DEFAULT 0 AFTER conversation_id',
					$messages_table
				)
			);
		}
		if ( ! in_array( 'job_round', $message_columns, true ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN job_round int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER job_id',
					$messages_table
				)
			);
		}
		if ( ! in_array( 'context_visible', $message_columns, true ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN context_visible tinyint(1) UNSIGNED NOT NULL DEFAULT 1 AFTER job_round',
					$messages_table
				)
			);
		}

		$message_indexes = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$messages_table
			)
		);
		if ( ! in_array( 'job_round', $message_indexes, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY job_round (job_id, job_round)', $messages_table ) );
		}
		if ( ! in_array( 'conversation_context', $message_indexes, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY conversation_context (conversation_id, context_visible)', $messages_table ) );
		}

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
					'claude-sonnet-4-6'
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
				"UPDATE %i SET provider = 'openai' WHERE (provider IS NULL OR provider = '') AND model LIKE %s",
				$conversations_table,
				'gpt-%'
			)
		);

		// Check if last_openai_response_id column exists.
		$response_id_column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'last_openai_response_id'",
				DB_NAME,
				$conversations_table
			)
		);

		// Add last_openai_response_id column if it doesn't exist.
		if ( empty( $response_id_column_exists ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN last_openai_response_id varchar(255) DEFAULT NULL AFTER model, ADD KEY last_openai_response_id (last_openai_response_id)',
					$conversations_table
				)
			);
		}

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

		if ( class_exists( 'Abilities_Bridge_Attachments' ) ) {
			Abilities_Bridge_Attachments::ensure_storage_dir();
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
	 * @param string $model Model identifier (default: claude-sonnet-4-6).
	 * @param string $provider Provider key (default: anthropic).
	 * @return int|false Conversation ID or false on failure
	 */
	public static function create_conversation( $user_id, $title = 'New Conversation', $model = 'claude-sonnet-4-6', $provider = 'anthropic' ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'user_id'  => $user_id,
				'title'    => $title,
				'provider' => $provider,
				'model'    => $model,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get a conversation by ID
	 *
	 * @param int  $conversation_id Conversation ID.
	 * @param int  $user_id User ID to scope the lookup to.
	 * @param bool $include_deleted Whether soft-deleted conversations should be included.
	 * @return object|null
	 */
	public static function get_conversation( $conversation_id, $user_id = 0, $include_deleted = false ) {
		global $wpdb;

		$query  = 'SELECT * FROM %i WHERE id = %d';
		$params = array(
			self::table( self::TABLE_CONVERSATIONS ),
			$conversation_id,
		);

		if ( $user_id > 0 ) {
			$query   .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		if ( ! $include_deleted ) {
			$query .= ' AND deleted_at IS NULL';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query fragments are fixed above; all values are placeholders.
		return $wpdb->get_row( $wpdb->prepare( $query, ...$params ) );
	}

	/**
	 * Update the last OpenAI response id for a conversation.
	 *
	 * @param int         $conversation_id Conversation ID.
	 * @param string|null $response_id Response id.
	 * @return bool
	 */
	public static function update_last_openai_response_id( $conversation_id, $response_id ) {
		global $wpdb;

		$result = $wpdb->update(
			self::table( self::TABLE_CONVERSATIONS ),
			array( 'last_openai_response_id' => $response_id ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Clear the last OpenAI response id for a conversation.
	 *
	 * @param int    $conversation_id     Conversation ID.
	 * @param string $expected_response_id Response ID that must still own the pointer.
	 * @return bool
	 */
	public static function clear_last_openai_response_id( $conversation_id, $expected_response_id = '' ) {
		global $wpdb;

		if ( '' === (string) $expected_response_id ) {
			return self::update_last_openai_response_id( $conversation_id, null );
		}

		// Clear only the pointer owned by this terminal job. A later job may have
		// already advanced the conversation before a zombie reaches reconciliation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic continuation-pointer fence.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_openai_response_id = NULL WHERE id = %d AND last_openai_response_id = %s',
				self::table( self::TABLE_CONVERSATIONS ),
				(int) $conversation_id,
				(string) $expected_response_id
			)
		);

		return false !== $updated;
	}

	/**
	 * Soft delete a conversation (archives for 30 days)
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id User ID to scope the delete to.
	 * @return bool
	 */
	public static function delete_conversation( $conversation_id, $user_id = 0 ) {
		global $wpdb;
		if ( $user_id > 0 && ! self::get_conversation( $conversation_id, $user_id ) ) {
			return false;
		}
		if ( class_exists( 'Abilities_Bridge_Chat_Jobs' ) ) {
			Abilities_Bridge_Chat_Jobs::cancel_for_conversation( $conversation_id );
		}

		$where = array( 'id' => $conversation_id );

		if ( $user_id > 0 ) {
			$where['user_id'] = $user_id;
		}

		// Soft delete: Set deleted_at timestamp and deleted_by_user_id.
		$result = $wpdb->update(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'deleted_at'         => current_time( 'mysql' ),
				'deleted_by_user_id' => get_current_user_id(),
			),
			$where,
			array( '%s', '%d' ),
			array_fill( 0, count( $where ), '%d' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Restore a soft-deleted conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id User ID to scope the restore to.
	 * @return bool
	 */
	public static function restore_conversation( $conversation_id, $user_id = 0 ) {
		global $wpdb;

		$where = array( 'id' => $conversation_id );

		if ( $user_id > 0 ) {
			$where['user_id'] = $user_id;
		}

		// Clear deleted_at and deleted_by_user_id to restore.
		$result = $wpdb->update(
			self::table( self::TABLE_CONVERSATIONS ),
			array(
				'deleted_at'         => null,
				'deleted_by_user_id' => null,
			),
			$where,
			array( '%s', '%d' ),
			array_fill( 0, count( $where ), '%d' )
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Permanently delete a conversation and all its messages and logs
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id User ID to scope the delete to.
	 * @return bool
	 */
	public static function permanently_delete_conversation( $conversation_id, $user_id = 0 ) {
		global $wpdb;

		$conversation = self::get_conversation( $conversation_id, $user_id, true );

		if ( ! $conversation ) {
			return false;
		}

		// Best-effort file cleanup first. File deletion failures must not block the DB purge.
		if ( class_exists( 'Abilities_Bridge_Attachments' ) ) {
			$attachments_deleted = Abilities_Bridge_Attachments::delete_conversation_attachments( $conversation_id );
			if ( ! $attachments_deleted ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'Abilities Bridge attachment cleanup failed during permanent deletion for conversation ID %d.',
						(int) $conversation_id
					)
				);
			}
		}

		// Hard delete messages.
		$wpdb->delete( self::table( self::TABLE_MESSAGES ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );

		// Hard delete logs.
		$wpdb->delete( self::table( self::TABLE_LOGS ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );

		// Hard delete durable job steps before their parent ledgers.
		$job_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE conversation_id = %d',
				self::table( self::TABLE_CHAT_JOBS ),
				(int) $conversation_id
			)
		);
		foreach ( $job_ids as $job_id ) {
			$wpdb->delete( self::table( self::TABLE_CHAT_JOB_STEPS ), array( 'job_id' => (int) $job_id ), array( '%d' ) );
		}
		$wpdb->delete( self::table( self::TABLE_CHAT_JOBS ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );

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
	 * @param int    $job_id Owning chat job, or zero.
	 * @param bool   $context_visible Whether providers may replay the message.
	 * @param int    $job_round Provider round within the owning job.
	 * @return int|false Message ID or false on failure
	 */
	public static function add_message( $conversation_id, $role, $content, $job_id = 0, $context_visible = true, $job_round = 0 ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::table( self::TABLE_MESSAGES ),
			array(
				'conversation_id' => $conversation_id,
				'job_id'          => $job_id,
				'job_round'       => $job_round,
				'context_visible' => $context_visible ? 1 : 0,
				'role'            => $role,
				'content'         => $content,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s' )
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
	 * @param int  $conversation_id Conversation ID.
	 * @param bool $context_only Return only provider-visible messages.
	 * @return array
	 */
	public static function get_messages( $conversation_id, $context_only = false ) {
		global $wpdb;
		if ( $context_only ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE conversation_id = %d AND context_visible = 1 ORDER BY created_at ASC, id ASC',
					self::table( self::TABLE_MESSAGES ),
					$conversation_id
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %d ORDER BY created_at ASC, id ASC',
				self::table( self::TABLE_MESSAGES ),
				$conversation_id
			)
		);
	}

	/**
	 * Hide one superseded job's messages from future provider context.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function hide_job_messages_from_context( $job_id ) {
		global $wpdb;
		$conversation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT conversation_id FROM %i WHERE job_id = %d LIMIT 1',
				self::table( self::TABLE_MESSAGES ),
				(int) $job_id
			)
		);

		$result = $wpdb->update(
			self::table( self::TABLE_MESSAGES ),
			array( 'context_visible' => 0 ),
			array( 'job_id' => (int) $job_id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( $conversation_id ) {
			wp_cache_delete( 'abilities_bridge_conv_msgs_' . $conversation_id, 'abilities_bridge' );
		}

		return false !== $result;
	}

	/**
	 * Restore every persisted message for a recovered job to provider context.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public static function show_job_messages_in_context( $job_id ) {
		global $wpdb;

		$conversation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT conversation_id FROM %i WHERE job_id = %d LIMIT 1',
				self::table( self::TABLE_MESSAGES ),
				(int) $job_id
			)
		);
		$result          = $wpdb->update(
			self::table( self::TABLE_MESSAGES ),
			array( 'context_visible' => 1 ),
			array( 'job_id' => (int) $job_id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( $conversation_id ) {
			wp_cache_delete( 'abilities_bridge_conv_msgs_' . $conversation_id, 'abilities_bridge' );
		}

		return false !== $result;
	}

	/**
	 * Keep only a terminal job's longest complete tool-protocol prefix visible.
	 *
	 * @param int $job_id         Job ID.
	 * @param int $user_message_id Initial user message ID.
	 * @param int $through_round   Last complete provider/tool round.
	 * @return bool
	 */
	public static function set_job_context_prefix( $job_id, $user_message_id, $through_round ) {
		global $wpdb;

		$table           = self::table( self::TABLE_MESSAGES );
		$conversation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT conversation_id FROM %i WHERE job_id = %d LIMIT 1',
				$table,
				(int) $job_id
			)
		);
		$hidden         = $wpdb->update(
			$table,
			array( 'context_visible' => 0 ),
			array( 'job_id' => (int) $job_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $hidden ) {
			return false;
		}

		if ( $through_round > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scoped transcript checkpoint update.
			$shown = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET context_visible = 1 WHERE job_id = %d AND (id = %d OR (job_round > 0 AND job_round <= %d))',
					$table,
					(int) $job_id,
					(int) $user_message_id,
					(int) $through_round
				)
			);
			if ( false === $shown ) {
				return false;
			}
		}

		if ( $conversation_id ) {
			wp_cache_delete( 'abilities_bridge_conv_msgs_' . $conversation_id, 'abilities_bridge' );
		}

		return true;
	}

	/**
	 * Determine whether one durable round already has its exact tool-result set.
	 *
	 * @param int   $job_id       Job ID.
	 * @param int   $round_number Provider round.
	 * @param array $tool_use_ids Expected opaque provider tool IDs.
	 * @return bool
	 */
	public static function has_job_tool_result_message( $job_id, $round_number, $tool_use_ids ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable checkpoint lookup.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT content FROM %i WHERE job_id = %d AND job_round = %d AND role = 'user' ORDER BY id ASC",
				self::table( self::TABLE_MESSAGES ),
				(int) $job_id,
				(int) $round_number
			)
		);
		$expected = array_map( 'strval', (array) $tool_use_ids );
		sort( $expected, SORT_STRING );

		foreach ( $rows as $row ) {
			$content = json_decode( $row->content, true );
			if ( ! is_array( $content ) ) {
				continue;
			}

			$actual = array();
			foreach ( $content as $block ) {
				if ( is_array( $block ) && 'tool_result' === ( $block['type'] ?? '' ) && isset( $block['tool_use_id'] ) ) {
					$actual[] = (string) $block['tool_use_id'];
				}
			}
			sort( $actual, SORT_STRING );
			if ( $expected === $actual ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an already-persisted assistant checkpoint for one provider round.
	 *
	 * Recovery callers hold the conversation lock, so this lookup makes a
	 * retry after a process crash idempotent without requiring a schema change.
	 *
	 * @param int $job_id       Job ID.
	 * @param int $round_number Provider round.
	 * @return int Message ID, or zero when absent.
	 */
	public static function get_job_assistant_message_id( $job_id, $round_number ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable checkpoint lookup.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE job_id = %d AND job_round = %d AND role = 'assistant' ORDER BY id ASC LIMIT 1",
				self::table( self::TABLE_MESSAGES ),
				(int) $job_id,
				(int) $round_number
			)
		);
	}

	/**
	 * Insert or replace one durable round's tool-result protocol message.
	 *
	 * Callers serialize this operation with the shared conversation lock.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $job_id         Job ID.
	 * @param int   $round_number   Provider round.
	 * @param array $results        Tool-result blocks.
	 * @return int|WP_Error Message ID or error.
	 */
	public static function upsert_job_tool_result_message( $conversation_id, $job_id, $round_number, $results ) {
		global $wpdb;

		$table = self::table( self::TABLE_MESSAGES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable checkpoint lookup.
		$message_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE job_id = %d AND job_round = %d AND role = 'user' ORDER BY id ASC LIMIT 1",
				$table,
				(int) $job_id,
				(int) $round_number
			)
		);
		$content = wp_json_encode( $results );

		if ( $message_id ) {
			$updated = $wpdb->update(
				$table,
				array(
					'content'         => $content,
					'context_visible' => 1,
				),
				array( 'id' => $message_id ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'tool_result_persistence_failed', __( 'The durable tool-result message could not be updated.', 'abilities-bridge' ) );
			}
			wp_cache_delete( 'abilities_bridge_conv_msgs_' . (int) $conversation_id, 'abilities_bridge' );

			return $message_id;
		}

		$message_id = self::add_message( $conversation_id, 'user', $content, $job_id, true, $round_number );
		if ( ! $message_id ) {
			return new WP_Error( 'tool_result_persistence_failed', __( 'The durable tool-result message could not be saved.', 'abilities-bridge' ) );
		}

		return (int) $message_id;
	}

	/**
	 * Delete a single message created during a failed enqueue.
	 *
	 * @param int $message_id      Message ID.
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function delete_message( $message_id, $conversation_id ) {
		global $wpdb;

		$result = $wpdb->delete(
			self::table( self::TABLE_MESSAGES ),
			array(
				'id'              => (int) $message_id,
				'conversation_id' => (int) $conversation_id,
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( 'abilities_bridge_conv_msgs_' . (int) $conversation_id, 'abilities_bridge' );

		return false !== $result;
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
		$total = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_values ) );

		// Results query.
		$select_sql    = "SELECT l.*, c.title as conversation_title, c.deleted_at as conversation_deleted_at
			FROM %i l
			LEFT JOIN %i c ON l.conversation_id = c.id
			WHERE {$where_sql}
			ORDER BY l.created_at DESC
			LIMIT %d OFFSET %d";
		$select_values = array_merge( $select_base_values, $values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL built from hardcoded placeholders only.
		$results = $wpdb->get_results( $wpdb->prepare( $select_sql, ...$select_values ), ARRAY_A );

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
			'abilities_bridge_chat_jobs',
			'abilities_bridge_chat_job_steps',
		);

		// Remove prefix and check against allowlist.
		$base_name = str_replace( $wpdb->prefix, '', $table_name );

		return in_array( $base_name, $allowed_tables, true ) ? $table_name : false;
	}
}
