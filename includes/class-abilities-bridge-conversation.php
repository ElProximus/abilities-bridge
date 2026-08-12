<?php
/**
 * Conversation management class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversation class.
 *
 * Manages chat conversations and message history.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Conversation {

	/**
	 * Conversation ID.
	 *
	 * @var int|null
	 */
	private $conversation_id;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Messages array.
	 *
	 * @var array
	 */
	private $messages = array();

	/**
	 * Message processor instance.
	 *
	 * @var Abilities_Bridge_Message_Processor
	 */
	private $message_processor;

	/**
	 * Constructor
	 *
	 * @param int|null                                $conversation_id Conversation ID (null for new conversation).
	 * @param Abilities_Bridge_Message_Processor|null $message_processor Optional message processor for dependency injection.
	 */
	public function __construct( $conversation_id = null, $message_processor = null ) {
		$this->user_id           = get_current_user_id();
		$this->message_processor = $message_processor ?? new Abilities_Bridge_Message_Processor();

		if ( $conversation_id ) {
			$conversation = Abilities_Bridge_Database::get_conversation( $conversation_id, $this->user_id );

			if ( $conversation ) {
				$this->conversation_id = $conversation_id;
				$this->load_messages();
			}
		}
	}

	/**
	 * Create a new conversation
	 *
	 * @param string $title Conversation title.
	 * @param string $model Model identifier (uses user's selected model if not specified).
	 * @return int|false Conversation ID or false on failure
	 */
	public function create( $title = 'New Conversation', $model = null ) {
		$provider = Abilities_Bridge_AI_Provider::get_current_provider();
		// Use user's selected model if not specified.
		if ( empty( $model ) ) {
			$model = Abilities_Bridge_AI_Provider::get_selected_model( $provider );
		}

		$this->conversation_id = Abilities_Bridge_Database::create_conversation( $this->user_id, $title, $model, $provider );
		return $this->conversation_id;
	}

	/**
	 * Load messages from database with caching
	 */
	private function load_messages() {
		if ( ! $this->conversation_id ) {
			return;
		}

		// Try to get from cache first.
		$cache_key       = 'abilities_bridge_conv_msgs_' . $this->conversation_id;
		$cached_messages = wp_cache_get( $cache_key, 'abilities_bridge' );

		if ( false !== $cached_messages ) {
			$this->messages = $cached_messages;
			return;
		}

		// Not in cache, load from database.
		$db_messages = Abilities_Bridge_Database::get_messages( $this->conversation_id, true );

		foreach ( $db_messages as $msg ) {
			$content = json_decode( $msg->content, true );

			// Handle both string content and array content (for tool use).
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $content ) ) {
				// Fix empty arrays that should be empty objects (for tool inputs).
				$content = Abilities_Bridge_Message_Validator::fix_empty_tool_inputs( $content );

					$this->messages[] = array(
						'id'        => (int) $msg->id,
						'job_id'    => isset( $msg->job_id ) ? (int) $msg->job_id : 0,
						'job_round' => isset( $msg->job_round ) ? (int) $msg->job_round : 0,
						'role'      => $msg->role,
						'content'   => $content,
					);
			} else {
					$this->messages[] = array(
						'id'        => (int) $msg->id,
						'job_id'    => isset( $msg->job_id ) ? (int) $msg->job_id : 0,
						'job_round' => isset( $msg->job_round ) ? (int) $msg->job_round : 0,
						'role'      => $msg->role,
						'content'   => $msg->content,
					);
			}
		}

		// Validate and repair the conversation if needed.
		Abilities_Bridge_Message_Validator::validate_and_repair_conversation( $this->messages, $this->conversation_id );

		// Cache the full conversation for 10 minutes.
		wp_cache_set( $cache_key, $this->messages, 'abilities_bridge', 600 );
	}

	/**
	 * Add a user message.
	 *
	 * @param string|array $content Message content.
	 * @param int          $job_id Job that owns this turn, or zero for legacy synchronous messages.
	 * @return int|WP_Error Message ID or error.
	 */
	public function add_user_message( $content, $job_id = 0 ) {
		return $this->add_message( 'user', $content, $job_id );
	}

	/**
	 * Add a user message with array content (for tool results)
	 *
	 * @param array $content Message content array.
	 * @param int   $job_id Job that owns this turn.
	 * @param int   $job_round Provider round within the job.
	 * @return int|WP_Error Message ID or error.
	 */
	public function add_user_message_array( $content, $job_id = 0, $job_round = 0 ) {
		return $this->add_message( 'user', $content, $job_id, $job_round );
	}

	/**
	 * Invalidate conversation cache
	 */
	private function invalidate_cache() {
		if ( $this->conversation_id ) {
			$cache_key = 'abilities_bridge_conv_msgs_' . $this->conversation_id;
			wp_cache_delete( $cache_key, 'abilities_bridge' );
		}
	}

	/**
	 * Reload the provider-visible transcript after an external durable upsert.
	 *
	 * @return void
	 */
	public function refresh_messages() {
		$this->messages = array();
		$this->invalidate_cache();
		$this->load_messages();
	}

	/**
	 * Add an assistant message
	 *
	 * @param array|string $content Message content (can be array for tool use).
	 * @param int          $job_id Job that owns this turn.
	 * @param int          $job_round Provider round within the job.
	 * @return int|WP_Error Message ID or error.
	 */
	public function add_assistant_message( $content, $job_id = 0, $job_round = 0 ) {
		return $this->add_message( 'assistant', $content, $job_id, $job_round );
	}

	/**
	 * Persist a checked message before adding it to the in-memory transcript.
	 *
	 * @param string       $role    Message role.
	 * @param string|array $content Message content.
	 * @param int          $job_id  Owning job.
	 * @param int          $job_round Provider round within the job.
	 * @return int|WP_Error Message ID or error.
	 */
	private function add_message( $role, $content, $job_id, $job_round = 0 ) {
		if ( ! $this->conversation_id ) {
			return new WP_Error( 'conversation_not_ready', __( 'The conversation has not been created yet.', 'abilities-bridge' ) );
		}

		$content_json = is_array( $content ) ? wp_json_encode( $content ) : (string) $content;
		$message_id   = Abilities_Bridge_Database::add_message(
			$this->conversation_id,
			$role,
			$content_json,
			(int) $job_id,
			true,
			(int) $job_round
		);

		if ( ! $message_id ) {
			return new WP_Error( 'message_persistence_failed', __( 'Unable to save the conversation checkpoint.', 'abilities-bridge' ) );
		}

		$this->messages[] = array(
			'id'        => (int) $message_id,
			'job_id'    => (int) $job_id,
			'job_round' => (int) $job_round,
			'role'      => $role,
			'content'   => $content,
		);
		$this->invalidate_cache();

		return (int) $message_id;
	}

	/**
	 * Get messages formatted for Claude API
	 *
	 * @return array Messages array
	 */
	public function get_messages() {
		return $this->messages;
	}

	/**
	 * Get messages for sending to provider APIs.
	 *
	 * @param array $args Replay options.
	 * @return array Messages array
	 */
	public function get_messages_for_api( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'hydrate_image_message_index' => null,
				'hydrate_image_message_id'    => null,
			)
		);

		$messages = array();

		foreach ( $this->messages as $index => $message ) {
			$content = isset( $message['content'] ) ? $message['content'] : '';

			if ( class_exists( 'Abilities_Bridge_Attachments' ) ) {
				$content = Abilities_Bridge_Attachments::prepare_content_for_api(
					$content,
					$this->conversation_id,
					(
							null !== $args['hydrate_image_message_index'] && (int) $index === (int) $args['hydrate_image_message_index']
						) || (
							null !== $args['hydrate_image_message_id'] && isset( $message['id'] ) && (int) $message['id'] === (int) $args['hydrate_image_message_id']
						)
				);
			}

			$messages[] = array(
				'role'    => isset( $message['role'] ) ? $message['role'] : '',
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Get conversation ID
	 *
	 * @return int|null
	 */
	public function get_id() {
		return $this->conversation_id;
	}

	/**
	 * Calculate token usage for current conversation
	 * Delegates to Token Calculator utility
	 *
	 * @return array Token usage statistics
	 */
	public function calculate_token_usage() {
		$tools    = Abilities_Bridge_AI_Provider::get_tool_definitions();
		$provider = Abilities_Bridge_AI_Provider::get_current_provider();

		// Get model from conversation or use current setting.
		$model = null;
		if ( $this->conversation_id ) {
			$conversation = Abilities_Bridge_Database::get_conversation( $this->conversation_id, $this->user_id );
			if ( $conversation ) {
				if ( ! empty( $conversation->provider ) ) {
					$provider = $conversation->provider;
				} elseif ( ! empty( $conversation->model ) ) {
					$provider = Abilities_Bridge_AI_Provider::infer_provider_from_model( $conversation->model, $provider );
				}

				if ( isset( $conversation->model ) ) {
					$available_models = Abilities_Bridge_AI_Provider::get_available_models( $provider );
					if ( isset( $available_models[ $conversation->model ] ) ) {
						$model = $conversation->model;
					}
				}
			}
		}

		return Abilities_Bridge_Token_Calculator::calculate_token_usage(
			$this->get_messages(),
			$tools,
			$model,
			$provider
		);
	}

	/**
	 * Send message to Claude and handle tool use loop
	 * Delegates to Message Processor service
	 *
	 * @param string $user_message User's message.
	 * @param bool   $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @param array  $image_blocks Stored image attachment blocks.
	 * @return array Result with success status and response/error
	 */
	public function send_message( $user_message, $plan_mode = false, $image_blocks = array() ) {
		return $this->message_processor->send_and_process( $this, $user_message, $plan_mode, $image_blocks );
	}

	/**
	 * Get a summary of the conversation for display
	 *
	 * @return string
	 */
	public function get_summary() {
		if ( empty( $this->messages ) ) {
			return 'New Conversation';
		}

		// Get first user message.
		foreach ( $this->messages as $msg ) {
			if ( 'user' !== $msg['role'] ) {
				continue;
			}

			$text = class_exists( 'Abilities_Bridge_Attachments' )
				? Abilities_Bridge_Attachments::extract_text( $msg['content'] )
				: ( is_string( $msg['content'] ) ? trim( $msg['content'] ) : '' );

			if ( '' !== $text ) {
				return substr( $text, 0, 50 ) . ( strlen( $text ) > 50 ? '...' : '' );
			}

			if ( class_exists( 'Abilities_Bridge_Attachments' ) && Abilities_Bridge_Attachments::content_has_images( $msg['content'] ) ) {
				return Abilities_Bridge_Attachments::derive_title( '', is_array( $msg['content'] ) ? $msg['content'] : array() );
			}
		}

		return 'Conversation #' . $this->conversation_id;
	}

	/**
	 * Repair all conversations in database
	 * Delegates to Message Validator utility
	 *
	 * @return array Repair statistics
	 */
	public static function repair_all_conversations() {
		return Abilities_Bridge_Message_Validator::repair_all_conversations();
	}
}
