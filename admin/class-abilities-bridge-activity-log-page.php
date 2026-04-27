<?php
/**
 * Activity Log Page - View and manage conversation logs
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity Log Page class.
 *
 * Handles viewing and managing conversation logs.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Activity_Log_Page {

	/**
	 * Get an accessible conversation for the current admin.
	 *
	 * @param int  $conversation_id Conversation ID.
	 * @param bool $include_deleted Whether soft-deleted conversations should be included.
	 * @return object|null
	 */
	private static function get_accessible_conversation( $conversation_id, $include_deleted = false ) {
		return Abilities_Bridge_Database::get_conversation(
			(int) $conversation_id,
			get_current_user_id(),
			$include_deleted
		);
	}

	/**
	 * Initialize the activity log page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_get_logs', array( __CLASS__, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_abilities_bridge_export_logs', array( __CLASS__, 'ajax_export_logs' ) );
		add_action( 'wp_ajax_abilities_bridge_restore_conversation', array( __CLASS__, 'ajax_restore_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_permanently_delete', array( __CLASS__, 'ajax_permanently_delete' ) );
	}

	/**
	 * Add menu page
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'abilities-bridge',
			__( 'Activity Log', 'abilities-bridge' ),
			__( 'Activity Log', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-activity-log',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'abilities-bridge_page_abilities-bridge-activity-log' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'abilities-bridge-activity-log',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/activity-log.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-activity-log',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/activity-log.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-activity-log',
			'abilitiesBridgeActivityLog',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_activity_log' ),
			)
		);
	}

	/**
	 * Verify the page navigation nonce.
	 *
	 * Returns true if nonce is valid or not present (first page load).
	 * Dies with error if nonce is present but invalid.
	 *
	 * @return bool True if request is valid.
	 */
	private static function verify_page_nonce() {
		$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// No nonce present = first page load, allow with defaults.
		if ( empty( $nonce ) ) {
			return false;
		}

		// Nonce present - verify it.
		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_activity_log_nav' ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Please use the navigation links provided.', 'abilities-bridge' ),
				esc_html__( 'Security Check Failed', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get a sanitized GET parameter for display purposes.
	 *
	 * Verifies nonce before accessing parameters. If no nonce is present
	 * (first page load), returns the default value.
	 *
	 * @param string $key     Parameter key.
	 * @param string $default Default value.
	 * @return string Sanitized value.
	 */
	private static function get_display_param( $key, $default = '' ) {
		// Verify nonce - returns false if no nonce (first load).
		if ( ! self::verify_page_nonce() ) {
			return $default;
		}

		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ! empty( $value ) ? sanitize_key( $value ) : $default;
	}

	/**
	 * Get a sanitized integer GET parameter for display purposes.
	 *
	 * Verifies nonce before accessing parameters. If no nonce is present
	 * (first page load), returns the default value.
	 *
	 * @param string $key     Parameter key.
	 * @param int    $default Default value.
	 * @return int Sanitized integer value.
	 */
	private static function get_display_int_param( $key, $default = 0 ) {
		// Verify nonce - returns false if no nonce (first load).
		if ( ! self::verify_page_nonce() ) {
			return $default;
		}

		$value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_INT );
		return false !== $value && null !== $value ? max( 1, absint( $value ) ) : $default;
	}

	/**
	 * Render the page
	 */
	public static function render_page() {
		$current_tab = self::get_display_param( 'tab', 'activity' );
		?>
		<div class="wrap abilities-bridge-activity-log-page">
			<h1><?php esc_html_e( 'Activity Log', 'abilities-bridge' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php
				$nav_nonce = wp_create_nonce( 'abilities_bridge_activity_log_nav' );
				?>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-activity-log&tab=activity', 'abilities_bridge_activity_log_nav' ) ); ?>" class="nav-tab <?php echo 'activity' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Activity Log', 'abilities-bridge' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-activity-log&tab=deleted', 'abilities_bridge_activity_log_nav' ) ); ?>" class="nav-tab <?php echo 'deleted' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Deleted Conversations', 'abilities-bridge' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-activity-log&tab=settings', 'abilities_bridge_activity_log_nav' ) ); ?>" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'abilities-bridge' ); ?>
				</a>
			</h2>

			<?php
			if ( 'activity' === $current_tab ) {
				self::render_activity_tab();
			} elseif ( 'deleted' === $current_tab ) {
				self::render_deleted_tab();
			} elseif ( 'settings' === $current_tab ) {
				self::render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Safely get filters from request with nonce validation
	 *
	 * Security Model:
	 * - No nonce present = Return empty array (show all logs)
	 * - Invalid nonce = wp_die() (block potential attack)
	 * - Valid nonce = Return sanitized filters
	 *
	 * This is the ONLY method that should access $_GET filter parameters.
	 * All filter building must go through this method to ensure nonce validation.
	 *
	 * @return array Validated filters, empty array if no valid nonce
	 */
	private static function get_validated_filters() {
		// Initialize empty filters.
		$filters = array();

		// Check if nonce parameter exists using filter_input (avoids direct superglobal access).
		$nonce = filter_input( INPUT_GET, 'abilities_bridge_activity_log_filter_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( empty( $nonce ) ) {
			// No nonce = no filters allowed (graceful: show all logs).
			return array();
		}

		// Nonce present - verify it (already sanitized via filter_input).
		$nonce = sanitize_text_field( $nonce );

		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_activity_log_filter' ) ) {
			// Invalid/expired nonce = die (possible attack).
			wp_die(
				esc_html__( 'Security token validation failed. Please refresh the page and try again.', 'abilities-bridge' ),
				esc_html__( 'Security Check Failed', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Nonce is valid - build filters safely using filter_input.
		// User ID filter.
		$user_id = filter_input( INPUT_GET, 'user_id', FILTER_VALIDATE_INT );
		if ( false !== $user_id && null !== $user_id && $user_id > 0 ) {
			$filters['user_id'] = $user_id;
		}

		// Status filter.
		$status = filter_input( INPUT_GET, 'status', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! empty( $status ) ) {
			$filters['status'] = sanitize_key( $status );
		}

		// Search term filter.
		$search = filter_input( INPUT_GET, 'search', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! empty( $search ) ) {
			$filters['search'] = sanitize_text_field( $search );
		}

		return $filters;
	}

	/**
	 * Render Activity Log tab
	 */
	private static function render_activity_tab() {
		// Get validated filters (handles all nonce checking internally).
		$filters = self::get_validated_filters();

		// Get filter options.
		$users = get_users( array( 'fields' => array( 'ID', 'user_login' ) ) );
		?>
		<div class="abilities-bridge-log-filters">
			<form method="get" id="activity-log-filters">
				<?php wp_nonce_field( 'abilities_bridge_activity_log_filter', 'abilities_bridge_activity_log_filter_nonce' ); ?>
				<input type="hidden" name="page" value="abilities-bridge-activity-log">
				<input type="hidden" name="tab" value="activity">

				<div class="filter-row">
					<label for="filter-user"><?php esc_html_e( 'User:', 'abilities-bridge' ); ?></label>
					<select name="user_id" id="filter-user">
						<option value=""><?php esc_html_e( 'All Users', 'abilities-bridge' ); ?></option>
						<?php foreach ( $users as $user ) : ?>
							<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( isset( $filters['user_id'] ) ? $filters['user_id'] : '', $user->ID ); ?>>
								<?php echo esc_html( $user->user_login ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label for="filter-status"><?php esc_html_e( 'Status:', 'abilities-bridge' ); ?></label>
					<select name="status" id="filter-status">
						<option value=""><?php esc_html_e( 'All', 'abilities-bridge' ); ?></option>
						<option value="success" <?php selected( isset( $filters['status'] ) ? $filters['status'] : '', 'success' ); ?>><?php esc_html_e( 'Success', 'abilities-bridge' ); ?></option>
						<option value="error" <?php selected( isset( $filters['status'] ) ? $filters['status'] : '', 'error' ); ?>><?php esc_html_e( 'Error', 'abilities-bridge' ); ?></option>
					</select>

					<label for="filter-search"><?php esc_html_e( 'Search:', 'abilities-bridge' ); ?></label>
					<input type="text" name="search" id="filter-search" value="<?php echo esc_attr( isset( $filters['search'] ) ? $filters['search'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Search actions...', 'abilities-bridge' ); ?>">

					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'abilities-bridge' ); ?></button>
					<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-activity-log&tab=activity', 'abilities_bridge_activity_log_nav' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'abilities-bridge' ); ?></a>
					<button type="button" class="button" id="export-csv-btn"><?php esc_html_e( 'Export CSV', 'abilities-bridge' ); ?></button>
				</div>
			</form>
		</div>

		<div id="activity-log-table-container">
			<?php self::render_activity_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render activity table
	 */
	private static function render_activity_table() {
		// Get validated filters (handles all nonce checking internally).
		$filters = self::get_validated_filters();

		// Get pagination using helper method (read-only display parameter).
		$page     = self::get_display_int_param( 'paged', 1 );
		$per_page = 50;

		// Get logs.
		$result = Abilities_Bridge_Database::get_logs_filtered( $filters, $page, $per_page );
		?>
		<table id="activity-log-table" class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Timestamp', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'User', 'abilities-bridge' ); ?></th>
				<th><?php esc_html_e( 'Source', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Conversation', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Function', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Status', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Action', 'abilities-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php self::render_table_rows( $result ); ?>
			</tbody>
		</table>

		<?php if ( $result['total_pages'] > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					$base_url = remove_query_arg( 'paged' );
					// Add nonce to pagination links.
					$base_url = wp_nonce_url( $base_url, 'abilities_bridge_activity_log_nav' );
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%', $base_url ),
								'format'    => '',
								'current'   => $page,
								'total'     => $result['total_pages'],
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render table rows for activity log (extracted for AJAX reuse)
	 *
	 * @param array $result Result from get_logs_filtered().
	 */
	private static function render_table_rows( $result ) {
		if ( empty( $result['items'] ) ) :
			?>
			<tr>
				<td colspan="7" style="text-align: center; padding: 40px;">
					<?php esc_html_e( 'No logs found matching your filters.', 'abilities-bridge' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $result['items'] as $log ) : ?>
				<tr class="log-row" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">
					<td><?php echo esc_html( $log['created_at'] ); ?></td>
					<td><?php echo esc_html( $log['username'] ); ?></td>
				<td>
					<?php if ( isset( $log['source'] ) && 'mcp' === $log['source'] ) : ?>
						<span class="source-badge source-mcp">MCP</span>
					<?php else : ?>
						<span class="source-badge source-admin">Chat</span>
					<?php endif; ?>
				</td>
					<td>
						<?php if ( $log['conversation_title'] ) : ?>
							<?php echo esc_html( $log['conversation_title'] ); ?>
							<?php if ( $log['conversation_deleted_at'] ) : ?>
								<span class="deleted-badge"><?php esc_html_e( 'Deleted', 'abilities-bridge' ); ?></span>
							<?php endif; ?>
					<?php elseif ( isset( $log['source'] ) && 'mcp' === $log['source'] ) : ?>
						<em><?php esc_html_e( 'MCP Session', 'abilities-bridge' ); ?></em>
					<?php else : ?>
						<em><?php esc_html_e( 'N/A', 'abilities-bridge' ); ?></em>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $log['function_name'] ); ?></code></td>
					<td>
						<?php if ( $log['error_message'] ) : ?>
							<span class="status-error">✗ <?php esc_html_e( 'Error', 'abilities-bridge' ); ?></span>
						<?php else : ?>
							<span class="status-success">✓ <?php esc_html_e( 'Success', 'abilities-bridge' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $log['action'] ); ?>
						<button type="button" class="button-link toggle-details" data-log-id="<?php echo esc_attr( $log['id'] ); ?>">
							<?php esc_html_e( 'Details', 'abilities-bridge' ); ?>
						</button>
						<div class="log-details" id="details-<?php echo esc_attr( $log['id'] ); ?>" style="display: none;">
							<?php if ( $log['function_input'] ) : ?>
								<strong><?php esc_html_e( 'Input:', 'abilities-bridge' ); ?></strong>
								<pre><?php echo esc_html( $log['function_input'] ); ?></pre>
							<?php endif; ?>
							<?php if ( $log['error_message'] ) : ?>
								<strong><?php esc_html_e( 'Error:', 'abilities-bridge' ); ?></strong>
								<p class="error-message"><?php echo esc_html( $log['error_message'] ); ?></p>
							<?php endif; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php
		endif;
	}

	/**
	 * Render pagination for activity log (extracted for AJAX reuse)
	 *
	 * @param array $result Result from get_logs_filtered().
	 * @param int   $page Current page number.
	 */
	private static function render_pagination( $result, $page = 1 ) {
		if ( $result['total_pages'] > 1 ) :
			?>
			<div class="activity-log-pagination">
				<?php
				for ( $i = 1; $i <= $result['total_pages']; $i++ ) {
					if ( $i === $page ) {
						echo '<span class="current">' . esc_html( $i ) . '</span> ';
					} else {
						echo '<a href="#" data-page="' . esc_attr( $i ) . '">' . esc_html( $i ) . '</a> ';
					}
				}
				?>
			</div>
			<?php
		endif;
	}

	/**
	 * Render Deleted Conversations tab
	 */
	private static function render_deleted_tab() {
		$deleted_conversations = Abilities_Bridge_Database::get_deleted_conversations();
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Deleted conversations are archived for 30 days. After 30 days, they are permanently removed along with all messages and logs.', 'abilities-bridge' ); ?></p>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Deleted Date', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Deleted By', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Days Until Purge', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Messages', 'abilities-bridge' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'abilities-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $deleted_conversations ) ) : ?>
					<tr>
						<td colspan="7" style="text-align: center; padding: 40px;">
							<?php esc_html_e( 'No deleted conversations to display.', 'abilities-bridge' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $deleted_conversations as $conv ) : ?>
						<?php
						$seconds_since_deleted = $conv['seconds_since_deleted'];
						$days_until_purge      = max( 0, 30 - floor( $seconds_since_deleted / 86400 ) );
						?>
						<tr>
							<td><?php echo esc_html( $conv['title'] ); ?></td>
							<td><?php echo esc_html( $conv['deleted_at'] ); ?></td>
							<td><?php echo esc_html( $conv['deleted_by_username'] ); ?></td>
							<td><?php echo esc_html( $days_until_purge ); ?> <?php esc_html_e( 'days', 'abilities-bridge' ); ?></td>
							<td><?php echo esc_html( $conv['message_count'] ); ?></td>
							<td>
								<button type="button" class="button button-small restore-conversation" data-conversation-id="<?php echo esc_attr( $conv['id'] ); ?>">
									<?php esc_html_e( 'Restore', 'abilities-bridge' ); ?>
								</button>
								<button type="button" class="button button-small button-link-delete permanently-delete" data-conversation-id="<?php echo esc_attr( $conv['id'] ); ?>">
									<?php esc_html_e( 'Delete Permanently', 'abilities-bridge' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render settings tab content
	 */
	private static function render_settings_tab() {
		// Get current settings.
		$retention_days = get_option( 'abilities_bridge_log_retention_days', 30 );
		$stats          = Abilities_Bridge_Database::get_log_statistics();
		$last_cleanup   = Abilities_Bridge_Log_Cleanup::get_last_cleanup_info();

		// Handle form submission.
		if ( isset( $_POST['abilities_bridge_save_retention'] ) && check_admin_referer( 'abilities_bridge_retention_nonce' ) ) {
			$new_retention = isset( $_POST['retention_days'] ) ? intval( wp_unslash( $_POST['retention_days'] ) ) : 30;
			if ( $new_retention >= 0 && $new_retention <= 90 ) {
				update_option( 'abilities_bridge_log_retention_days', $new_retention );
				$retention_days = $new_retention;
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Log retention settings saved.', 'abilities-bridge' ) . '</p></div>';
			}
		}

		// Handle manual cleanup.
		if ( isset( $_POST['abilities_bridge_manual_cleanup'] ) && check_admin_referer( 'abilities_bridge_cleanup_nonce' ) ) {
			$results = Abilities_Bridge_Log_Cleanup::manual_cleanup();
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
			/* translators: 1: number of logs deleted, 2: number of conversations purged */
				esc_html__( 'Cleanup complete: %1$d logs deleted, %2$d conversations purged.', 'abilities-bridge' ),
				absint( $results['logs_deleted'] ),
				absint( $results['conversations_deleted'] )
			);
			echo '</p></div>';
			// Refresh stats.
			$stats        = Abilities_Bridge_Database::get_log_statistics();
			$last_cleanup = Abilities_Bridge_Log_Cleanup::get_last_cleanup_info();
		}
		?>

		<div class="card">
			<h2><?php esc_html_e( 'Log Retention Policy', 'abilities-bridge' ); ?></h2>

			<form method="post" style="margin-bottom: 20px;">
				<?php wp_nonce_field( 'abilities_bridge_retention_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="retention_days"><?php esc_html_e( 'Delete logs older than', 'abilities-bridge' ); ?></label>
						</th>
						<td>
							<select name="retention_days" id="retention_days">
								<option value="0" <?php selected( $retention_days, 0 ); ?>><?php esc_html_e( 'Keep forever (disabled)', 'abilities-bridge' ); ?></option>
								<option value="7" <?php selected( $retention_days, 7 ); ?>>7 <?php esc_html_e( 'days', 'abilities-bridge' ); ?></option>
								<option value="14" <?php selected( $retention_days, 14 ); ?>>14 <?php esc_html_e( 'days', 'abilities-bridge' ); ?></option>
								<option value="30" <?php selected( $retention_days, 30 ); ?>>30 <?php esc_html_e( 'days', 'abilities-bridge' ); ?></option>
								<option value="60" <?php selected( $retention_days, 60 ); ?>>60 <?php esc_html_e( 'days', 'abilities-bridge' ); ?></option>
								<option value="90" <?php selected( $retention_days, 90 ); ?>>90 <?php esc_html_e( 'days', 'abilities-bridge' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Logs older than this will be automatically deleted daily. Deleted conversations are always archived for 30 days.', 'abilities-bridge' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="abilities_bridge_save_retention" class="button button-primary" value="<?php esc_attr_e( 'Save Retention Settings', 'abilities-bridge' ); ?>" />
				</p>
			</form>

			<h3><?php esc_html_e( 'Database Statistics', 'abilities-bridge' ); ?></h3>
			<table class="widefat">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total Logs', 'abilities-bridge' ); ?></strong></td>
						<td><?php echo number_format( $stats['total_logs'] ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Oldest Log', 'abilities-bridge' ); ?></strong></td>
						<td><?php echo $stats['oldest_log'] ? esc_html( $stats['oldest_log'] ) : esc_html__( 'No logs', 'abilities-bridge' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Database Size', 'abilities-bridge' ); ?></strong></td>
						<td><?php echo esc_html( $stats['size_mb'] ) . ' MB'; ?></td>
					</tr>
					<?php if ( $last_cleanup ) : ?>
					<tr>
						<td><strong><?php esc_html_e( 'Last Cleanup', 'abilities-bridge' ); ?></strong></td>
						<td>
							<?php echo esc_html( $last_cleanup['timestamp'] ); ?>
							(<?php echo esc_html( sprintf( /* translators: 1: number of logs, 2: number of conversations */ __( '%1$d logs, %2$d conversations removed', 'abilities-bridge' ), $last_cleanup['results']['logs_deleted'], $last_cleanup['results']['conversations_deleted'] ) ); ?>)
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top: 20px;">
				<?php wp_nonce_field( 'abilities_bridge_cleanup_nonce' ); ?>
				<p>
					<input type="submit" name="abilities_bridge_manual_cleanup" class="button button-secondary" value="<?php esc_attr_e( 'Run Cleanup Now', 'abilities-bridge' ); ?>" onclick="return confirm('<?php esc_attr_e( 'This will permanently delete old logs based on your retention settings. Continue?', 'abilities-bridge' ); ?>');" />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * AJAX: Get logs (for refresh)
	 */
	public static function ajax_get_logs() {
		check_ajax_referer( 'abilities_bridge_activity_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		// Extract and validate filters from POST (AJAX request).
		$filters = array();
		$page    = 1;

		// Retrieve filters from POST data (nonce verified above via check_ajax_referer).
		$raw_filters = filter_input( INPUT_POST, 'filters', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		if ( ! empty( $raw_filters ) && is_array( $raw_filters ) ) {
			$posted_filters = map_deep( $raw_filters, 'sanitize_text_field' );

			if ( ! empty( $posted_filters['user_id'] ) ) {
				$filters['user_id'] = intval( $posted_filters['user_id'] );
			}

			if ( ! empty( $posted_filters['status'] ) ) {
				$filters['status'] = sanitize_key( $posted_filters['status'] );
			}

			if ( ! empty( $posted_filters['search'] ) ) {
				$filters['search'] = sanitize_text_field( $posted_filters['search'] );
			}

			if ( ! empty( $posted_filters['page'] ) ) {
				$page = intval( $posted_filters['page'] );
			}
		}

		$page     = max( 1, $page );
		$per_page = 50;

		// Get logs with filters.
		$result = Abilities_Bridge_Database::get_logs_filtered( $filters, $page, $per_page );

		// Render table rows only (not entire table).
		ob_start();
		self::render_table_rows( $result );
		$html = ob_get_clean();

		// Render pagination.
		ob_start();
		self::render_pagination( $result, $page );
		$pagination = ob_get_clean();

		wp_send_json_success(
			array(
				'html'       => $html,
				'pagination' => $pagination,
			)
		);
	}

	/**
	 * AJAX: Export logs to CSV
	 */
	public static function ajax_export_logs() {
		check_ajax_referer( 'abilities_bridge_activity_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Rate limiting - prevent export spam/abuse.
		// Max 5 exports per minute per user.
		$user_id       = get_current_user_id();
		$transient_key = 'abilities_bridge_export_attempts_' . $user_id;
		$attempts      = get_transient( $transient_key );

		if ( false === $attempts ) {
			$attempts = 0;
		}

		if ( $attempts >= 5 ) {
			wp_die(
				esc_html__( 'Too many export attempts. Please wait a moment and try again.', 'abilities-bridge' ),
				esc_html__( 'Rate Limit Exceeded', 'abilities-bridge' ),
				array( 'response' => 429 )
			);
		}

		// Increment export attempts counter (expires in 60 seconds).
		set_transient( $transient_key, $attempts + 1, MINUTE_IN_SECONDS );

		// Build filters from POST data (nonce verified above).
		$filters = array();
		if ( ! empty( $_POST['user_id'] ) ) {
			$filters['user_id'] = intval( wp_unslash( $_POST['user_id'] ) );
		}
		if ( ! empty( $_POST['status'] ) ) {
			$filters['status'] = sanitize_key( wp_unslash( $_POST['status'] ) );
		}
		if ( ! empty( $_POST['search'] ) ) {
			$filters['search'] = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		}

		// Get logs (max 10000).
		$result = Abilities_Bridge_Database::get_logs_filtered( $filters, 1, 10000 );

		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=activity-log-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output CSV using PHP output stream (standard WordPress CSV export pattern).
		$output = fopen( 'php://output', 'w' );

		// Add UTF-8 BOM for Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );

		// CSV headers.
		fputcsv( $output, array( 'Timestamp', 'User', 'Conversation', 'Function', 'Status', 'Action', 'Error' ) );

		// CSV rows.
		foreach ( $result['items'] as $log ) {
			fputcsv(
				$output,
				array(
					$log['created_at'],
					$log['username'],
					$log['conversation_title'] ? $log['conversation_title'] : 'N/A',
					$log['function_name'],
					$log['error_message'] ? 'Error' : 'Success',
					$log['action'],
					$log['error_message'] ? $log['error_message'] : '',
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * AJAX: Restore conversation
	 */
	public static function ajax_restore_conversation() {
		check_ajax_referer( 'abilities_bridge_activity_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( ! $conversation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid conversation ID' ) );
		}

		if ( ! self::get_accessible_conversation( $conversation_id, true ) ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
		}

		$result = Abilities_Bridge_Database::restore_conversation( $conversation_id, get_current_user_id() );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Conversation restored successfully' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to restore conversation' ) );
		}
	}

	/**
	 * AJAX: Permanently delete conversation
	 */
	public static function ajax_permanently_delete() {
		check_ajax_referer( 'abilities_bridge_activity_log', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( ! $conversation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid conversation ID' ) );
		}

		if ( ! self::get_accessible_conversation( $conversation_id, true ) ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
		}

		$result = Abilities_Bridge_Database::permanently_delete_conversation( $conversation_id, get_current_user_id() );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Conversation permanently deleted' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to delete conversation' ) );
		}
	}
}
