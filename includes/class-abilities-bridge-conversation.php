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
			$this->conversation_id = $conversation_id;
			$this->load_messages();
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
		// Use user's selected model if not specified.
		if ( empty( $model ) ) {
			$model = Abilities_Bridge_Claude_API::get_selected_model();
		}

		$this->conversation_id = Abilities_Bridge_Database::create_conversation( $this->user_id, $title, $model );
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
		$db_messages = Abilities_Bridge_Database::get_messages( $this->conversation_id );

		foreach ( $db_messages as $msg ) {
			$content = json_decode( $msg->content, true );

			// Handle both string content and array content (for tool use).
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $content ) ) {
				// Fix empty arrays that should be empty objects (for tool inputs).
				$content = Abilities_Bridge_Message_Validator::fix_empty_tool_inputs( $content );

				$this->messages[] = array(
					'role'    => $msg->role,
					'content' => $content,
				);
			} else {
				$this->messages[] = array(
					'role'    => $msg->role,
					'content' => $msg->content,
				);
			}
		}

		// Validate and repair the conversation if needed.
		Abilities_Bridge_Message_Validator::validate_and_repair_conversation( $this->messages, $this->conversation_id );

		// Cache the full conversation for 10 minutes.
		wp_cache_set( $cache_key, $this->messages, 'abilities_bridge', 600 );
	}

	/**
	 * Add a user message
	 *
	 * @param string $content Message content.
	 */
	public function add_user_message( $content ) {
		$this->messages[] = array(
			'role'    => 'user',
			'content' => $content,
		);

		if ( $this->conversation_id ) {
			Abilities_Bridge_Database::add_message( $this->conversation_id, 'user', $content );
			// Invalidate cache when adding new message.
			$this->invalidate_cache();
		}
	}

	/**
	 * Add a user message with array content (for tool results)
	 *
	 * @param array $content Message content array.
	 */
	public function add_user_message_array( $content ) {
		$this->messages[] = array(
			'role'    => 'user',
			'content' => $content,
		);

		if ( $this->conversation_id ) {
			Abilities_Bridge_Database::add_message(
				$this->conversation_id,
				'user',
				wp_json_encode( $content )
			);
			// Invalidate cache when adding new message.
			$this->invalidate_cache();
		}
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
	 * Add an assistant message
	 *
	 * @param array|string $content Message content (can be array for tool use).
	 */
	public function add_assistant_message( $content ) {
		$this->messages[] = array(
			'role'    => 'assistant',
			'content' => $content,
		);

		if ( $this->conversation_id ) {
			$content_json = is_array( $content ) ? wp_json_encode( $content ) : $content;
			Abilities_Bridge_Database::add_message( $this->conversation_id, 'assistant', $content_json );
			// Invalidate cache when adding new message.
			$this->invalidate_cache();
		}
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
	 * Get messages for sending to Claude API
	 *
	 * @return array Messages array
	 */
	public function get_messages_for_api() {
		return $this->messages;
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
		$tools = Abilities_Bridge_Claude_API::get_tool_definitions();

		// Get model from conversation or use current setting.
		$model = null;
		if ( $this->conversation_id ) {
			$conversation = Abilities_Bridge_Database::get_conversation( $this->conversation_id );
			if ( $conversation && isset( $conversation->model ) ) {
				$model = $conversation->model;
			}
		}

		return Abilities_Bridge_Token_Calculator::calculate_token_usage(
			$this->get_messages_for_api(),
			$tools,
			$model
		);
	}

	/**
	 * Send message to Claude and handle tool use loop
	 * Delegates to Message Processor service
	 *
	 * @param string $user_message User's message.
	 * @param bool   $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @return array Result with success status and response/error
	 */
	public function send_message( $user_message, $plan_mode = false ) {
		return $this->message_processor->send_and_process( $this, $user_message, $plan_mode );
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
			if ( 'user' === $msg['role'] && is_string( $msg['content'] ) ) {
				return substr( $msg['content'], 0, 50 ) . ( strlen( $msg['content'] ) > 50 ? '...' : '' );
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
