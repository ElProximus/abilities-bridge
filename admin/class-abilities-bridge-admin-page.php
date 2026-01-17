<?php
/**
 * Main admin page class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page class.
 *
 * Handles the main admin page and chat interface.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Admin_Page {

	/**
	 * Initialize the admin page
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_abilities_bridge_new_conversation', array( $this, 'ajax_new_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_load_conversation', array( $this, 'ajax_load_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_delete_conversation', array( $this, 'ajax_delete_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_get_conversations', array( $this, 'ajax_get_conversations' ) );
		add_action( 'wp_ajax_abilities_bridge_get_token_usage', array( $this, 'ajax_get_token_usage' ) );
		add_action( 'wp_ajax_abilities_bridge_get_recent_activity', array( $this, 'ajax_get_recent_activity' ) );
		add_action( 'wp_ajax_abilities_bridge_get_conversation_activity', array( $this, 'ajax_get_conversation_activity' ) );
		add_action( 'wp_ajax_abilities_bridge_set_model', array( $this, 'ajax_set_model' ) );
		add_action( 'wp_ajax_abilities_bridge_get_model', array( $this, 'ajax_get_model' ) );
		add_action( 'wp_ajax_abilities_bridge_create_summary_continuation', array( $this, 'ajax_create_summary_continuation' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Abilities Bridge', 'abilities-bridge' ),
			__( 'Abilities Bridge', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge',
			array( $this, 'render_page' ),
			'dashicons-visibility',
			30
		);

		add_submenu_page(
			'abilities-bridge',
			__( 'Settings', 'abilities-bridge' ),
			__( 'Settings', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-settings',
			array( new Abilities_Bridge_Settings_Page(), 'render_page' )
		);
	}

	/**
	 * Enqueue CSS and JavaScript.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_abilities-bridge' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'abilities-bridge-styles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/admin-styles.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_style(
			'abilities-bridge-progress-styles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/progress-styles.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_style(
			'abilities-bridge-chat-bubbles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/chat-bubbles.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-app',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/admin-app-simple.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_enqueue_script(
			'abilities-bridge-dashboard',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/dashboard.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-app',
			'abilitiesBridgeData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'abilities_bridge_nonce' ),
				'user_id'  => get_current_user_id(),
			)
		);
	}

	/**
	 * Render the main page
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'abilities-bridge' ) );
		}

		// Check if consent has been given.
		if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-welcome' ) );
			exit;
		}

		// Check if API key is configured.
		$api_key            = get_option( 'abilities_bridge_api_key', '' );
		$api_key_configured = ! empty( $api_key );

		?>
		<div class="wrap abilities-bridge-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( ! $api_key_configured ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: %s: settings page URL */
							esc_html__( 'Anthropic API key not configured. Please <a href="%s">add your API key</a> to use Abilities Bridge.', 'abilities-bridge' ),
							esc_url( admin_url( 'admin.php?page=abilities-bridge-settings' ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="abilities-bridge-container">
				<div class="abilities-bridge-dashboard">
					<?php include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/partials/dashboard.php'; ?>
				</div>

				<div class="abilities-bridge-chat">
					<?php include ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/partials/chat.php'; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Send message
	 */
	public function ajax_send_message() {
		// Check if consent has been given.
		if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Please complete the welcome wizard and provide consent before using Abilities Bridge.', 'abilities-bridge' ),
					'redirect' => admin_url( 'admin.php?page=abilities-bridge-welcome' ),
				)
			);
		}

		// Register shutdown function to catch fatal errors.
		register_shutdown_function(
			function () {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ) ) ) {
						// Try to send JSON error response.
					if ( ! headers_sent() ) {
						wp_send_json_error(
							array(
								'message' => 'Fatal error: ' . $error['message'],
								'file'    => basename( $error['file'] ),
								'line'    => $error['line'],
							)
						);
					}
				}
			}
		);

		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : null;
		$plan_mode       = isset( $_POST['plan_mode'] ) && sanitize_text_field( wp_unslash( $_POST['plan_mode'] ) ) === 'true';

		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => 'Message cannot be empty' ) );
		}

		// Limit message length to prevent abuse.
		if ( strlen( $message ) > 10000 ) {
			wp_send_json_error( array( 'message' => 'Message too long (max 10,000 characters)' ) );
		}

		// Load or create conversation.
		$conversation = new Abilities_Bridge_Conversation( $conversation_id );

		if ( ! $conversation_id ) {
			// Create new conversation with first message as title.
			$title  = substr( $message, 0, 80 ) . ( strlen( $message ) > 80 ? '...' : '' );
			$new_id = $conversation->create( $title );

			if ( ! $new_id ) {
				wp_send_json_error( array( 'message' => 'Failed to create conversation' ) );
			}

			$conversation_id = $new_id;
		}

		// Log the action.
		Abilities_Bridge_Logger::log_action( $conversation_id, 'sent message to AI' );

		// Log initial activity.
		Abilities_Bridge_Logger::log_tool_progress( $conversation_id, 'request', 'processing', '⏳ Processing message...' );

		// Send message to Claude.
		$result = $conversation->send_message( $message, $plan_mode );

		if ( ! $result['success'] ) {
			Abilities_Bridge_Logger::log_action( $conversation_id, 'message failed', $result['error'] );

			wp_send_json_error(
				array(
					'message'    => $result['error'],
					'error_data' => isset( $result['error_data'] ) ? $result['error_data'] : null,
				)
			);
		}

		// Check token usage.
		$token_usage = $conversation->calculate_token_usage();

		// Check for summary continuation thresholds.
		$summary_status = 'none';

		// Don't show warnings if this is a summary request.
		$is_summary_request = ( strpos( $message, 'comprehensive summary' ) !== false );

		if ( ! $is_summary_request ) {
			if ( $token_usage['total'] >= 150000 ) {
				$summary_status = 'final_warning'; // Second warning - last chance.
			} elseif ( $token_usage['total'] >= 125000 ) {
				$summary_status = 'first_warning'; // First warning - suggest summary.
			}
		}

		// Truncate response if too large for JSON.
		$response_data = array(
			'response'        => $result['response'],
			'conversation_id' => $conversation_id,
			'iterations'      => $result['iterations'],
			'tool_usage'      => isset( $result['tool_usage'] ) ? $result['tool_usage'] : array(),
			'token_usage'     => $token_usage,
			'summary_status'  => $summary_status,
		);

		// Check response size and truncate if needed.
		$json_size = strlen( wp_json_encode( $response_data ) );
		if ( $json_size > 2097152 ) { // 2MB limit for JSON response.
			$response_data['response']  = substr( $result['response'], 0, 500000 ) . "\n\n[Response truncated due to size]";
			$response_data['truncated'] = true;
		}

		wp_send_json_success( $response_data );
	}

	/**
	 * AJAX: Create new conversation
	 */
	public function ajax_new_conversation() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Check if consent has been given.
		if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Please complete the welcome wizard and provide consent before using Abilities Bridge.', 'abilities-bridge' ),
					'redirect' => admin_url( 'admin.php?page=abilities-bridge-welcome' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => 'New conversation started',
			)
		);
	}

	/**
	 * AJAX: Load conversation
	 */
	public function ajax_load_conversation() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Check if consent has been given.
		if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Please complete the welcome wizard and provide consent before using Abilities Bridge.', 'abilities-bridge' ),
					'redirect' => admin_url( 'admin.php?page=abilities-bridge-welcome' ),
				)
			);
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( ! $conversation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid conversation ID' ) );
		}

		$conversation = Abilities_Bridge_Database::get_conversation( $conversation_id );

		if ( ! $conversation ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
		}

		$messages           = Abilities_Bridge_Database::get_messages( $conversation_id );
		$formatted_messages = array();

		foreach ( $messages as $msg ) {
			$content = json_decode( $msg->content, true );

			// Only include user and text assistant messages for display.
			if ( 'user' === $msg->role ) {
				// Check if this is a tool_result message (should be hidden from UI).
				if ( is_array( $content ) && isset( $content[0]['type'] ) && 'tool_result' === $content[0]['type'] ) {
					// Skip tool_result messages - these are internal Claude API messages.
					continue;
				}

				if ( null === $content || is_string( $content ) ) {
					// Plain text message - display it.
					$formatted_messages[] = array(
						'role'      => 'user',
						'content'   => $msg->content,
						'timestamp' => $msg->created_at,
					);
				} elseif ( is_array( $content ) ) {
					// Array content - extract text, skip tool_result blocks.
					$text_parts = array();
					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) ) {
							if ( 'text' === $block['type'] && isset( $block['text'] ) ) {
								$text_parts[] = $block['text'];
							}
							// Skip tool_result blocks - they're not meant for display.
						}
					}
					if ( ! empty( $text_parts ) ) {
						$formatted_messages[] = array(
							'role'      => 'user',
							'content'   => implode( "\n\n", $text_parts ),
							'timestamp' => $msg->created_at,
						);
					}
				}
			} elseif ( 'assistant' === $msg->role ) {
				if ( is_array( $content ) ) {
					// Extract text from content blocks.
					$text_parts = array();
					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
							$text_parts[] = $block['text'];
						}
					}
					if ( ! empty( $text_parts ) ) {
						$formatted_messages[] = array(
							'role'      => 'assistant',
							'content'   => implode( "\n\n", $text_parts ),
							'timestamp' => $msg->created_at,
						);
					}
				} else {
					$formatted_messages[] = array(
						'role'      => 'assistant',
						'content'   => $msg->content,
						'timestamp' => $msg->created_at,
					);
				}
			}
		}

		wp_send_json_success(
			array(
				'conversation' => $conversation,
				'messages'     => $formatted_messages,
			)
		);
	}

	/**
	 * AJAX: Delete conversation
	 */
	public function ajax_delete_conversation() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( ! $conversation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid conversation ID' ) );
		}

		$result = Abilities_Bridge_Database::delete_conversation( $conversation_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => 'Conversation deleted' ) );
		} else {
			wp_send_json_error( array( 'message' => 'Failed to delete conversation' ) );
		}
	}

	/**
	 * AJAX: Get conversations list
	 */
	public function ajax_get_conversations() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$user_id       = get_current_user_id();
		$conversations = Abilities_Bridge_Database::get_conversations( $user_id );

		// Ensure conversations is always an array.
		if ( ! is_array( $conversations ) ) {
			$conversations = array();
		}

		wp_send_json_success(
			array(
				'conversations' => $conversations,
			)
		);
	}

	/**
	 * AJAX: Get token usage for current conversation
	 */
	public function ajax_get_token_usage() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( $conversation_id ) {
			$conversation = new Abilities_Bridge_Conversation( $conversation_id );
			$token_usage  = $conversation->calculate_token_usage();

			wp_send_json_success( $token_usage );
		} else {
			// Return empty token usage for no conversation.
			wp_send_json_success(
				array(
					'messages'     => 0,
					'system'       => 0,
					'tools'        => 0,
					'total'        => 0,
					'model'        => 'claude-sonnet-4.5-20250929',
					'input_limit'  => 200000,
					'output_limit' => 64000,
					'percentage'   => 0,
					'status'       => 'no_conversation',
				)
			);
		}
	}



	/**
	 * AJAX: Get recent activity (for real-time progress polling)
	 */
	public function ajax_get_recent_activity() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		// Close session to allow concurrent requests (enables real-time progress polling).
		if ( session_status() === PHP_SESSION_ACTIVE ) {
			session_write_close();
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( empty( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Conversation ID required' ) );
		}

		$logs = Abilities_Bridge_Logger::get_recent_activity( $conversation_id, 30 );

		$activities = array();
		foreach ( $logs as $log ) {
			$activities[] = array(
				'message'       => $log->action,
				'function_name' => $log->function_name,
				'timestamp'     => $log->created_at,
			);
		}

		wp_send_json_success( array( 'activities' => $activities ) );
	}

	/**
	 * AJAX: Get all conversation activity (for activity history dropdown)
	 */
	public function ajax_get_conversation_activity() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? intval( wp_unslash( $_POST['conversation_id'] ) ) : 0;

		if ( empty( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Conversation ID required' ) );
		}

		$logs = Abilities_Bridge_Logger::get_conversation_activity( $conversation_id );

		$activities = array();
		foreach ( $logs as $log ) {
			$input = $log->function_input ? json_decode( $log->function_input, true ) : null;

			$activities[] = array(
				'timestamp'   => $log->created_at,
				'function'    => $log->function_name,
				'description' => $log->action,
				'input'       => $input,
				'success'     => empty( $log->error_message ),
				'error'       => $log->error_message,
			);
		}

		wp_send_json_success(
			array(
				'activities' => $activities,
				'count'      => count( $activities ),
			)
		);
	}

	/**
	 * AJAX: Set selected model
	 */
	public function ajax_set_model() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		if ( empty( $model ) ) {
			wp_send_json_error( array( 'message' => 'Model is required' ) );
		}

		$success = Abilities_Bridge_Claude_API::set_selected_model( $model );

		if ( $success ) {
			$available_models = Abilities_Bridge_Claude_API::get_available_models();
			wp_send_json_success(
				array(
					'message'    => 'Model updated successfully',
					'model'      => $model,
					'model_name' => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => 'Invalid model' ) );
		}
	}

	/**
	 * AJAX: Get selected model
	 */
	public function ajax_get_model() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$model            = Abilities_Bridge_Claude_API::get_selected_model();
		$available_models = Abilities_Bridge_Claude_API::get_available_models();

		wp_send_json_success(
			array(
				'model'            => $model,
				'model_name'       => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
				'available_models' => $available_models,
			)
		);
	}

	/**
	 * AJAX: Create summary continuation conversation
	 *
	 * Creates a new conversation with the summary as the first user message,
	 * linking it to the parent conversation via parent_conversation_id.
	 */
	public function ajax_create_summary_continuation() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$parent_conversation_id = isset( $_POST['parent_conversation_id'] ) ? intval( wp_unslash( $_POST['parent_conversation_id'] ) ) : 0;
		$summary_text           = isset( $_POST['summary_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary_text'] ) ) : '';

		if ( ! $parent_conversation_id ) {
			wp_send_json_error( array( 'message' => 'Invalid parent conversation ID' ) );
		}

		if ( empty( $summary_text ) ) {
			wp_send_json_error( array( 'message' => 'Summary text is required' ) );
		}

		// Get parent conversation details.
		$parent_conversation = Abilities_Bridge_Database::get_conversation( $parent_conversation_id );
		if ( ! $parent_conversation ) {
			wp_send_json_error( array( 'message' => 'Parent conversation not found' ) );
		}

		// Create new conversation with same title and model.
		global $wpdb;

		$result = $wpdb->insert(
			Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CONVERSATIONS ),
			array(
				'user_id'                => get_current_user_id(),
				'title'                  => sanitize_text_field( $parent_conversation->title ), // Keep same title.
				'model'                  => sanitize_text_field( $parent_conversation->model ),
				'parent_conversation_id' => $parent_conversation_id,
			),
			array( '%d', '%s', '%s', '%d' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => 'Failed to create new conversation' ) );
		}

		$new_conversation_id = $wpdb->insert_id;

		// Add summary as first user message in new conversation.
		Abilities_Bridge_Database::add_message(
			$new_conversation_id,
			'user',
			$summary_text
		);

		wp_send_json_success(
			array(
				'new_conversation_id'    => $new_conversation_id,
				'parent_conversation_id' => $parent_conversation_id,
				'message'                => 'Summary continuation created successfully',
			)
		);
	}
}
