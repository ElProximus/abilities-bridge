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
	 * Get an accessible conversation for the current admin.
	 *
	 * @param int  $conversation_id Conversation ID.
	 * @param bool $include_deleted Whether soft-deleted conversations should be included.
	 * @return object|null
	 */
	private function get_accessible_conversation( $conversation_id, $include_deleted = false ) {
		return Abilities_Bridge_Database::get_conversation(
			(int) $conversation_id,
			get_current_user_id(),
			$include_deleted
		);
	}

	/**
	 * Initialize the admin page
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_abilities_bridge_chat_job_enqueue', array( $this, 'ajax_chat_job_enqueue' ) );
		add_action( 'wp_ajax_abilities_bridge_chat_job_status', array( $this, 'ajax_chat_job_status' ) );
		add_action( 'wp_ajax_abilities_bridge_chat_job_cancel', array( $this, 'ajax_chat_job_cancel' ) );
		add_action( 'wp_ajax_abilities_bridge_chat_job_resume', array( $this, 'ajax_chat_job_resume' ) );
		add_action( 'wp_ajax_abilities_bridge_chat_job_run_foreground', array( $this, 'ajax_chat_job_run_foreground' ) );
		add_action( 'wp_ajax_abilities_bridge_new_conversation', array( $this, 'ajax_new_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_load_conversation', array( $this, 'ajax_load_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_delete_conversation', array( $this, 'ajax_delete_conversation' ) );
		add_action( 'wp_ajax_abilities_bridge_get_conversations', array( $this, 'ajax_get_conversations' ) );
		add_action( 'wp_ajax_abilities_bridge_get_token_usage', array( $this, 'ajax_get_token_usage' ) );
		add_action( 'wp_ajax_abilities_bridge_get_recent_activity', array( $this, 'ajax_get_recent_activity' ) );
		add_action( 'wp_ajax_abilities_bridge_get_conversation_activity', array( $this, 'ajax_get_conversation_activity' ) );
		add_action( 'wp_ajax_abilities_bridge_set_model', array( $this, 'ajax_set_model' ) );
		add_action( 'wp_ajax_abilities_bridge_get_model', array( $this, 'ajax_get_model' ) );
		add_action( 'wp_ajax_abilities_bridge_set_provider', array( $this, 'ajax_set_provider' ) );
		add_action( 'wp_ajax_abilities_bridge_get_provider', array( $this, 'ajax_get_provider' ) );
		add_action( 'wp_ajax_abilities_bridge_create_summary_continuation', array( $this, 'ajax_create_summary_continuation' ) );
		add_action( 'wp_ajax_abilities_bridge_get_attachment', array( $this, 'ajax_get_attachment' ) );
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

		$styles_version      = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/css/admin-styles.css' );
		$progress_version    = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/css/progress-styles.css' );
		$bubbles_version     = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/css/chat-bubbles.css' );
		$attachments_version = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/chat-attachments.js' );
		$app_version         = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/admin-app-simple.js' );
		$dashboard_version   = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/dashboard.js' );

		wp_enqueue_style(
			'abilities-bridge-styles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/admin-styles.css',
			array(),
			$styles_version ? $styles_version : ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_style(
			'abilities-bridge-progress-styles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/progress-styles.css',
			array(),
			$progress_version ? $progress_version : ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_style(
			'abilities-bridge-chat-bubbles',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/chat-bubbles.css',
			array(),
			$bubbles_version ? $bubbles_version : ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-chat-attachments',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/chat-attachments.js',
			array( 'jquery' ),
			$attachments_version ? $attachments_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_enqueue_script(
			'abilities-bridge-app',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/admin-app-simple.js',
			array( 'jquery', 'abilities-bridge-chat-attachments' ),
			$app_version ? $app_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_enqueue_script(
			'abilities-bridge-dashboard',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/dashboard.js',
			array( 'jquery' ),
			$dashboard_version ? $dashboard_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-app',
			'abilitiesBridgeData',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'abilities_bridge_nonce' ),
				'user_id'     => get_current_user_id(),
				'attachments' => Abilities_Bridge_Attachments::get_config(),
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
		$provider_label     = Abilities_Bridge_AI_Provider::get_provider_label();
		$api_key_configured = Abilities_Bridge_AI_Provider::has_api_key();

		?>
		<div class="wrap abilities-bridge-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( ! $api_key_configured ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: 1: provider name, 2: settings page URL */
							esc_html__( '%1$s API key not configured. Please <a href="%2$s">add your API key</a> to use Abilities Bridge.', 'abilities-bridge' ),
							esc_html( $provider_label ),
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
		$attachments_raw = isset( $_POST['attachments'] ) ? wp_unslash( $_POST['attachments'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$attachments     = Abilities_Bridge_Attachments::parse_incoming_attachments( $attachments_raw );

		if ( is_wp_error( $attachments ) ) {
			wp_send_json_error( array( 'message' => $attachments->get_error_message() ) );
		}

		if ( '' === trim( $message ) && empty( $attachments ) ) {
			wp_send_json_error( array( 'message' => 'Message or image attachment required' ) );
		}

		// Limit message length to prevent abuse.
		if ( strlen( $message ) > 10000 ) {
			wp_send_json_error( array( 'message' => 'Message too long (max 10,000 characters)' ) );
		}

		if ( $conversation_id && ! $this->get_accessible_conversation( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
		}

		// Load or create conversation.
		$conversation = new Abilities_Bridge_Conversation( $conversation_id );

		if ( ! $conversation_id ) {
			// Create new conversation with first message as title.
			$title  = Abilities_Bridge_Attachments::derive_title( $message, $attachments );
			$new_id = $conversation->create( $title );

			if ( ! $new_id ) {
				wp_send_json_error( array( 'message' => 'Failed to create conversation' ) );
			}

			$conversation_id = $new_id;
		}

		$image_blocks = Abilities_Bridge_Attachments::store_attachments( $conversation_id, $attachments );
		if ( is_wp_error( $image_blocks ) ) {
			wp_send_json_error( array( 'message' => $image_blocks->get_error_message() ) );
		}

		// Log the action.
		Abilities_Bridge_Logger::log_action( $conversation_id, 'sent message to AI' );

		// Log initial activity.
		Abilities_Bridge_Logger::log_tool_progress( $conversation_id, 'request', 'processing', '⏳ Processing message...' );

		// Send message to Claude.
		$result = $conversation->send_message( $message, $plan_mode, $image_blocks );

		if ( ! $result['success'] ) {
			Abilities_Bridge_Logger::log_action( $conversation_id, 'message failed', $result['error'] );

			$error_payload = array(
				'message'    => $result['error'],
				'error_data' => isset( $result['error_data'] ) ? $result['error_data'] : null,
			);

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$error_payload['debug'] = $result;
			}

			wp_send_json_error( $error_payload );
		}

		// Check token usage.
		$token_usage = $conversation->calculate_token_usage();

		// Check for summary continuation thresholds.
		$summary_status = 'none';

		// Don't show warnings if this is a summary request.
		$is_summary_request = ( strpos( $message, 'comprehensive summary' ) !== false );

		if ( ! $is_summary_request && isset( $token_usage['percentage'] ) ) {
			// Scale the warning to the model's context window so larger-window
			// models are not flagged prematurely.
			if ( $token_usage['percentage'] >= 90 ) {
				$summary_status = 'final_warning'; // Second warning - last chance.
			} elseif ( $token_usage['percentage'] >= 80 ) {
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
	 * AJAX: Persist and dispatch a durable chat job.
	 *
	 * @throws RuntimeException When an enqueue checkpoint cannot be persisted.
	 */
	public function ajax_chat_job_enqueue() {
		$this->verify_chat_job_browser_request();

		// The shared verifier above checks the nonce before any request field is read.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
		$plan_mode       = isset( $_POST['plan_mode'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['plan_mode'] ) );
		$attachments_raw = isset( $_POST['attachments'] ) ? wp_unslash( $_POST['attachments'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by attachment parser.
		$supersede_token = isset( $_POST['supersede_job'] ) ? sanitize_text_field( wp_unslash( $_POST['supersede_job'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$attachments = Abilities_Bridge_Attachments::parse_incoming_attachments( $attachments_raw );

		if ( is_wp_error( $attachments ) ) {
			wp_send_json_error( array( 'message' => $attachments->get_error_message() ) );
		}
		if ( '' === trim( $message ) && empty( $attachments ) ) {
			wp_send_json_error( array( 'message' => __( 'Message or image attachment required.', 'abilities-bridge' ) ) );
		}
		if ( strlen( $message ) > 10000 ) {
			wp_send_json_error( array( 'message' => __( 'Message too long (max 10,000 characters).', 'abilities-bridge' ) ) );
		}
		if ( $conversation_id && ! $this->get_accessible_conversation( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Conversation not found.', 'abilities-bridge' ) ) );
		}

		$conversation = new Abilities_Bridge_Conversation( $conversation_id );
		if ( ! $conversation_id ) {
			$conversation_id = (int) $conversation->create( Abilities_Bridge_Attachments::derive_title( $message, $attachments ) );
			if ( ! $conversation_id ) {
				wp_send_json_error( array( 'message' => __( 'Failed to create conversation.', 'abilities-bridge' ) ) );
			}
		}

		$locked = Abilities_Bridge_Chat_Jobs::acquire_conversation_lock( $conversation_id, 3 );
		if ( ! $locked ) {
			wp_send_json_error( array( 'message' => __( 'Another chat request is being prepared. Please try again.', 'abilities-bridge' ) ) );
		}

		$payload = null;
		$error   = null;
		try {
			if ( '' !== $supersede_token ) {
				$superseded = Abilities_Bridge_Chat_Jobs::get_by_token( $supersede_token );
				if ( $superseded && get_current_user_id() === (int) $superseded->user_id && $conversation_id === (int) $superseded->conversation_id ) {
					Abilities_Bridge_Chat_Jobs::request_cancel( $superseded->id );
				}
			}
			$blocker = Abilities_Bridge_Chat_Jobs::find_blocking( $conversation_id );
			if ( $blocker ) {
				$result = Abilities_Bridge_Chat_Jobs::sweep( $blocker->id );
				if ( 'requeued' === $result ) {
					Abilities_Bridge_Chat_Job_Runner::dispatch( $blocker->id, true );
				}
				$blocker = Abilities_Bridge_Chat_Jobs::find_blocking( $conversation_id );
			}
			if ( $blocker ) {
				throw new RuntimeException( __( 'This conversation already has a question in progress.', 'abilities-bridge' ) );
			}

			$conversation_data = Abilities_Bridge_Database::get_conversation( $conversation_id, get_current_user_id() );
			$provider          = ! empty( $conversation_data->provider ) ? $conversation_data->provider : Abilities_Bridge_AI_Provider::get_current_provider();
			$model             = ! empty( $conversation_data->model ) ? $conversation_data->model : Abilities_Bridge_AI_Provider::get_selected_model( $provider );
			$job               = Abilities_Bridge_Chat_Jobs::create_preparing(
				array(
					'conversation_id' => $conversation_id,
					'user_id'         => get_current_user_id(),
					'provider'        => $provider,
					'model'           => $model,
					'plan_mode'       => $plan_mode,
				)
			);
			if ( is_wp_error( $job ) ) {
				throw new RuntimeException( $job->get_error_message() );
			}

			$image_blocks = Abilities_Bridge_Attachments::store_attachments( $conversation_id, $attachments );
			if ( is_wp_error( $image_blocks ) ) {
				Abilities_Bridge_Chat_Jobs::delete_preparing( $job->id );
				throw new RuntimeException( $image_blocks->get_error_message() );
			}
			if ( ! Abilities_Bridge_Chat_Jobs::store_preparing_attachments( $job->id, wp_json_encode( $image_blocks ) ) ) {
				Abilities_Bridge_Attachments::delete_attachment_blocks( $conversation_id, $image_blocks );
				Abilities_Bridge_Chat_Jobs::delete_preparing( $job->id );
				throw new RuntimeException( __( 'The attachment checkpoint could not be saved.', 'abilities-bridge' ) );
			}

			$processor  = new Abilities_Bridge_Message_Processor();
			$message_id = $processor->begin( $conversation, $message, $plan_mode, $image_blocks, $job->id );
			if ( is_wp_error( $message_id ) ) {
				Abilities_Bridge_Attachments::delete_attachment_blocks( $conversation_id, $image_blocks );
				Abilities_Bridge_Chat_Jobs::delete_preparing( $job->id );
				throw new RuntimeException( $message_id->get_error_message() );
			}

			if ( ! Abilities_Bridge_Chat_Jobs::activate_prepared( $job->id, $message_id, wp_json_encode( $image_blocks ) ) ) {
				Abilities_Bridge_Database::delete_message( $message_id, $conversation_id );
				Abilities_Bridge_Attachments::delete_attachment_blocks( $conversation_id, $image_blocks );
				Abilities_Bridge_Chat_Jobs::delete_preparing( $job->id );
				throw new RuntimeException( __( 'The chat job could not be queued safely.', 'abilities-bridge' ) );
			}

			Abilities_Bridge_Chat_Jobs::mark_superseded( $conversation_id, $job->id );
			Abilities_Bridge_Logger::log_action( $conversation_id, 'queued message for AI' );
			Abilities_Bridge_Chat_Job_Runner::dispatch( $job->id, true );
			$payload = array(
				'job'             => $job->public_token,
				'conversation_id' => $conversation_id,
				'status'          => 'queued',
			);
		} catch ( Throwable $throwable ) {
			$error = $throwable->getMessage();
		} finally {
			Abilities_Bridge_Chat_Jobs::release_conversation_lock( $conversation_id );
		}

		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}
		wp_send_json_success( $payload );
	}

	/**
	 * AJAX: Poll one durable job.
	 */
	public function ajax_chat_job_status() {
		$this->verify_chat_job_browser_request();
		$job = $this->get_owned_job_from_request();

		$sweep = Abilities_Bridge_Chat_Jobs::sweep( $job->id );
		$job   = Abilities_Bridge_Chat_Jobs::get( $job->id );
		if ( Abilities_Bridge_Chat_Jobs::is_openai_recovery_pending( $job ) ) {
			Abilities_Bridge_Chat_Job_Runner::recover_openai_job( $job );
			$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
		}
		if ( $job && 'queued' === $job->status && ( 'requeued' === $sweep || Abilities_Bridge_Chat_Jobs::age( $job ) >= 15 ) ) {
			Abilities_Bridge_Chat_Job_Runner::dispatch( $job->id );
			$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
		}

		wp_send_json_success( $this->format_chat_job( $job ) );
	}

	/**
	 * AJAX: Stop a job immediately and free the conversation for another turn.
	 */
	public function ajax_chat_job_cancel() {
		$this->verify_chat_job_browser_request();
		$job             = $this->get_owned_job_from_request();
		$conversation_id = (int) $job->conversation_id;
		if ( ! Abilities_Bridge_Chat_Jobs::acquire_conversation_lock( $conversation_id, 2 ) ) {
			wp_send_json_error( array( 'message' => __( 'The conversation is busy finalizing a response. Please try Stop again.', 'abilities-bridge' ) ), 409 );
		}

		try {
			$job = Abilities_Bridge_Chat_Jobs::request_cancel( $job->id );
		} finally {
			Abilities_Bridge_Chat_Jobs::release_conversation_lock( $conversation_id );
		}

		wp_send_json_success( $this->format_chat_job( $job ) );
	}

	/**
	 * AJAX: Find a resumable job for a conversation after page load.
	 */
	public function ajax_chat_job_resume() {
		$this->verify_chat_job_browser_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by shared guard above.
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
		if ( $conversation_id && ! $this->get_accessible_conversation( $conversation_id ) ) {
			wp_send_json_success( array( 'job' => null ) );
		}

		$job = $conversation_id
			? Abilities_Bridge_Chat_Jobs::find_resumable( $conversation_id, get_current_user_id() )
			: Abilities_Bridge_Chat_Jobs::find_latest_resumable_for_user( get_current_user_id() );
		if ( $job && ! $this->get_accessible_conversation( $job->conversation_id ) ) {
			$job = null;
		}
		if ( $job ) {
			Abilities_Bridge_Chat_Jobs::sweep( $job->id );
			$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
			if ( Abilities_Bridge_Chat_Jobs::is_openai_recovery_pending( $job ) ) {
				Abilities_Bridge_Chat_Job_Runner::recover_openai_job( $job );
				$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
			}
			if ( 'queued' === $job->status ) {
				Abilities_Bridge_Chat_Job_Runner::dispatch( $job->id );
			}
		}

		wp_send_json_success( array( 'job' => $job ? $this->format_chat_job( $job ) : null ) );
	}

	/**
	 * AJAX: Explicit foreground transport takeover of the same prepared job.
	 */
	public function ajax_chat_job_run_foreground() {
		$this->verify_chat_job_browser_request();
		$job    = $this->get_owned_job_from_request();
		$runner = new Abilities_Bridge_Chat_Job_Runner();
		$result = $runner->run_existing( $job->id, $job->runner_token );
		$job    = Abilities_Bridge_Chat_Jobs::get( $job->id );
		if ( is_wp_error( $result ) && ( ! $job || 'queued' === $job->status ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'job'     => $this->format_chat_job( $job ),
				)
			);
		}

		wp_send_json_success( $this->format_chat_job( $job ) );
	}

	/**
	 * Verify the common browser-facing chat-job security boundary.
	 */
	private function verify_chat_job_browser_request() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'abilities-bridge' ) ), 403 );
		}
		if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			wp_send_json_error( array( 'message' => __( 'Please complete the welcome wizard before using chat.', 'abilities-bridge' ) ), 403 );
		}
	}

	/**
	 * Resolve a public token and enforce user ownership.
	 *
	 * @return object Job row.
	 */
	private function get_owned_job_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller runs the shared verifier first.
		$token = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job   = Abilities_Bridge_Chat_Jobs::get_by_token( $token );
		if ( ! $job || get_current_user_id() !== (int) $job->user_id || ! $this->get_accessible_conversation( $job->conversation_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Chat job not found.', 'abilities-bridge' ) ), 404 );
		}

		return $job;
	}

	/**
	 * Format safe progress data for browser polling.
	 *
	 * @param object $job Job row.
	 * @return array
	 */
	private function format_chat_job( $job ) {
		if ( ! $job ) {
			return array( 'status' => 'missing' );
		}

		return array(
			'job'                    => $job->public_token,
			'conversation_id'        => (int) $job->conversation_id,
			'status'                 => $job->status,
			'phase'                  => $job->phase,
			'rounds_completed'       => (int) $job->rounds_completed,
			'last_activity'          => sanitize_text_field( $job->last_activity ),
			'elapsed'                => Abilities_Bridge_Chat_Jobs::age( $job ),
			'error_code'             => sanitize_key( $job->error_code ),
			'error_message'          => sanitize_text_field( $job->error_message ),
			'background_unavailable' => 'queued' === $job->status && Abilities_Bridge_Chat_Jobs::age( $job ) >= 45,
			'cancel_requested'       => (bool) $job->cancel_requested,
			'recovery_pending'       => Abilities_Bridge_Chat_Jobs::is_openai_recovery_pending( $job ),
		);
	}

	/**
	 * AJAX: Serve a private conversation image attachment.
	 *
	 * @return void
	 */
	public function ajax_get_attachment() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Insufficient permissions', 'abilities-bridge' ) );
		}

		$conversation_id = isset( $_GET['conversation_id'] ) ? intval( wp_unslash( $_GET['conversation_id'] ) ) : 0;
		$attachment_id   = isset( $_GET['attachment_id'] ) ? sanitize_key( wp_unslash( $_GET['attachment_id'] ) ) : '';

		if ( ! $conversation_id || ! $attachment_id || ! $this->get_accessible_conversation( $conversation_id ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Image attachment not found.', 'abilities-bridge' ) );
		}

		$attachment = Abilities_Bridge_Attachments::get_attachment_for_serving( $conversation_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			status_header( 404 );
			wp_die( esc_html( $attachment->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $attachment['mime_type'] );
		header( 'Content-Length: ' . filesize( $attachment['path'] ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $attachment['name'] ) . '"' );
		readfile( $attachment['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
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

		$conversation = $this->get_accessible_conversation( $conversation_id );

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
					$formatted_messages[] = array(
						'role'      => 'user',
						'content'   => Abilities_Bridge_Attachments::format_content_for_display( $content, $conversation_id ),
						'timestamp' => $msg->created_at,
					);
				}
			} elseif ( 'assistant' === $msg->role ) {
				// Superseded/cancelled turns remain auditable in storage, but their
				// partial model output must not appear as part of the newer exchange.
				if ( isset( $msg->context_visible ) && ! (int) $msg->context_visible ) {
					continue;
				}
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

		$conversation = $this->get_accessible_conversation( $conversation_id );

		if ( ! $conversation ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
		}

		$result = Abilities_Bridge_Database::delete_conversation( $conversation_id, get_current_user_id() );

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
			if ( ! $this->get_accessible_conversation( $conversation_id ) ) {
				wp_send_json_error( array( 'message' => 'Conversation not found' ) );
			}

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

		if ( ! $this->get_accessible_conversation( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
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

		if ( ! $this->get_accessible_conversation( $conversation_id ) ) {
			wp_send_json_error( array( 'message' => 'Conversation not found' ) );
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

		$provider = Abilities_Bridge_AI_Provider::get_current_provider();
		$success  = Abilities_Bridge_AI_Provider::set_selected_model( $model, $provider );

		if ( $success ) {
			$available_models = Abilities_Bridge_AI_Provider::get_available_models( $provider );
			wp_send_json_success(
				array(
					'message'        => 'Model updated successfully',
					'model'          => $model,
					'model_name'     => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
					'model_guidance' => Abilities_Bridge_AI_Provider::get_model_guidance( $model, $provider ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => sprintf(
						'Invalid model for %s provider',
						Abilities_Bridge_AI_Provider::get_provider_label( $provider )
					),
				)
			);
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

		$provider         = Abilities_Bridge_AI_Provider::get_current_provider();
		$model            = Abilities_Bridge_AI_Provider::get_selected_model( $provider );
		$available_models = Abilities_Bridge_AI_Provider::get_available_models( $provider );

		wp_send_json_success(
			array(
				'provider'         => $provider,
				'model'            => $model,
				'model_name'       => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
				'model_guidance'   => Abilities_Bridge_AI_Provider::get_model_guidance( $model, $provider ),
				'available_models' => $available_models,
			)
		);
	}

	/**
	 * AJAX: Set selected provider
	 */
	public function ajax_set_provider() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';

		if ( empty( $provider ) ) {
			wp_send_json_error( array( 'message' => 'Provider is required' ) );
		}

		$success = Abilities_Bridge_AI_Provider::set_selected_provider( $provider );

		if ( $success ) {
			$available_models = Abilities_Bridge_AI_Provider::get_available_models( $provider );
			$model            = Abilities_Bridge_AI_Provider::get_selected_model( $provider );
			wp_send_json_success(
				array(
					'message'          => 'Provider updated successfully',
					'provider'         => $provider,
					'provider_label'   => Abilities_Bridge_AI_Provider::get_provider_label( $provider ),
					'model'            => $model,
					'model_name'       => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
					'model_guidance'   => Abilities_Bridge_AI_Provider::get_model_guidance( $model, $provider ),
					'available_models' => $available_models,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => 'Invalid provider' ) );
		}
	}

	/**
	 * AJAX: Get selected provider
	 */
	public function ajax_get_provider() {
		check_ajax_referer( 'abilities_bridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$provider         = Abilities_Bridge_AI_Provider::get_current_provider();
		$available_models = Abilities_Bridge_AI_Provider::get_available_models( $provider );
		$model            = Abilities_Bridge_AI_Provider::get_selected_model( $provider );

		wp_send_json_success(
			array(
				'provider'         => $provider,
				'provider_label'   => Abilities_Bridge_AI_Provider::get_provider_label( $provider ),
				'model'            => $model,
				'model_name'       => isset( $available_models[ $model ] ) ? $available_models[ $model ] : $model,
				'model_guidance'   => Abilities_Bridge_AI_Provider::get_model_guidance( $model, $provider ),
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
		$parent_conversation = $this->get_accessible_conversation( $parent_conversation_id );
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
				'provider'               => ! empty( $parent_conversation->provider ) ? sanitize_text_field( $parent_conversation->provider ) : Abilities_Bridge_AI_Provider::get_current_provider(),
				'model'                  => sanitize_text_field( $parent_conversation->model ),
				'parent_conversation_id' => $parent_conversation_id,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
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
