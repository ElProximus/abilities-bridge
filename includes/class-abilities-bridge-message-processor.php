<?php
/**
 * Message processing service
 * Handles conversation loop, tool execution, and Claude API interaction
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Message Processor class.
 *
 * Processes messages and handles AI tool calls.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Message_Processor {

	/**
	 * MCP Orchestrator instance
	 *
	 * @var Abilities_Bridge_MCP_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Constructor
	 *
	 * @param Abilities_Bridge_MCP_Orchestrator|null $orchestrator Optional orchestrator instance.
	 */
	public function __construct( $orchestrator = null ) {
		$this->orchestrator = $orchestrator ?? new Abilities_Bridge_MCP_Orchestrator();
	}

	/**
	 * Send message to Claude and handle tool use loop
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation instance.
	 * @param string                        $user_message User's message.
	 * @param bool                          $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @return array Result with success status and response/error
	 */
	public function send_and_process( $conversation, $user_message, $plan_mode = false ) {
		// Set overall timeout for entire conversation.
		$conversation_start    = time();
		$max_conversation_time = 300; // 5 minutes max for entire conversation.

		// Add user message to conversation.
		$conversation->add_user_message( $user_message );

		// Get model from conversation or use user's selected model.
		$model           = null;
		$conversation_id = $conversation->get_id();
		if ( $conversation_id ) {
			$conversation_data = Abilities_Bridge_Database::get_conversation( $conversation_id );
			if ( $conversation_data && isset( $conversation_data->model ) ) {
				$model = $conversation_data->model;
			}
		}
		if ( empty( $model ) ) {
			$model = Abilities_Bridge_Claude_API::get_selected_model();
		}

		// Initialize Claude API.
		$claude = new Abilities_Bridge_Claude_API();
		$tools  = Abilities_Bridge_Claude_API::get_tool_definitions();

		// Tool use loop - continue until Claude stops requesting tools.
		$max_iterations    = 10; // Reduced from 20 to prevent excessive loops.
		$iteration         = 0;
		$final_response    = '';
		$tool_errors       = array();
		$has_pending_tools = false; // Track if we have tools that need results.
		$tool_usage        = array(); // Track all tools used for activity log.

		while ( $iteration < $max_iterations ) {
			++$iteration;

			// Check conversation timeout BEFORE sending to Claude (unless we have pending tools).
			// If we have pending tools from a previous iteration, we MUST complete them.
			if ( ! $has_pending_tools && time() - $conversation_start > $max_conversation_time ) {
				$final_response .= "\n\n⚠️ Conversation timeout reached. Some operations may not have completed.";
				break;
			}

			// Send messages to Claude with automatic retry for transient errors.
			$max_retries = 3;
			$retry_delay = 1; // seconds.

			for ( $retry = 0; $retry <= $max_retries; $retry++ ) {
				if ( $retry > 0 ) {
					// Exponential backoff: 1s, 2s, 4s.
					sleep( $retry_delay * pow( 2, $retry - 1 ) );
				}

				$response = $claude->send_message( $conversation->get_messages_for_api(), $tools, 4096, $model );

				if ( ! is_wp_error( $response ) ) {
					break; // Success - continue processing.
				}

				// Check if error is retryable.
				$error_data   = $response->get_error_data();
				$is_retryable = isset( $error_data['retryable'] ) && $error_data['retryable'];
				$http_status  = isset( $error_data['status'] ) ? $error_data['status'] : 0;

				$should_retry = (
					$is_retryable ||
					$http_status >= 500 ||
					429 === $http_status ||
					in_array( $http_status, array( 502, 503, 504 ), true )
				);

				// If not retryable or last retry, handle error.
				if ( ! $should_retry || $retry === $max_retries ) {
					if ( 1 === $iteration ) {
						// First message - show user-friendly error.
						return array(
							'success' => false,
							'error'   => self::get_user_friendly_error( $response ),
						);
					} else {
						// Mid-conversation - log silently.
						Abilities_Bridge_Logger::log_action(
							$conversation_id,
							'api_error_after_retries',
							$response->get_error_message()
						);
						break;
					}
				}
			}

			// If still error after retries, skip this iteration.
			if ( is_wp_error( $response ) ) {
				break;
			}

			// Check stop reason.
			$stop_reason = isset( $response['stop_reason'] ) ? $response['stop_reason'] : '';

			// Extract content from response.
			$content_blocks = isset( $response['content'] ) ? $response['content'] : array();

			// Add assistant message to conversation.
			$conversation->add_assistant_message( $content_blocks );

			// Process content blocks.
			$text_responses = array();
			$tool_uses      = array();

			foreach ( $content_blocks as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$text_responses[] = $block['text'];
				} elseif ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
					$tool_uses[] = $block;
				}
			}

			// Collect text responses.
			if ( ! empty( $text_responses ) ) {
				$final_response .= implode( "\n\n", $text_responses ) . "\n\n";
			}

			// If no tool use, we're done.
			if ( empty( $tool_uses ) || 'tool_use' !== $stop_reason ) {
				$has_pending_tools = false;
				break;
			}

			// IMPORTANT: If we ONLY have tool_use and NO text response yet,.
			// don't return partial response. Let the loop continue to execute tools.
			// and get the final response from Claude.
			if ( empty( $text_responses ) && ! empty( $tool_uses ) && 'tool_use' === $stop_reason ) {
				$has_pending_tools = true;
				// Continue to tool execution below instead of returning early.
			}

			// We have tools to execute.
			$has_pending_tools = true;

			// Process tool uses.
			$tool_results     = array();
			$any_tool_skipped = false;

			foreach ( $tool_uses as $tool_use ) {
				$tool_name  = $tool_use['name'];
				$tool_input = $tool_use['input'];
				$tool_id    = $tool_use['id'];

				// Track tool usage for activity log.
				$tool_usage[] = array(
					'tool'  => $tool_name,
					'input' => $tool_input,
				);

				// Log tool activity for real-time progress display.
				$progress_message = self::get_tool_progress_message( $tool_name, $tool_input );

				Abilities_Bridge_Logger::log_tool_progress(
					$conversation_id,
					$tool_name,
					'processing',
					$progress_message
				);

				// Check timeout BEFORE executing EACH tool (prevents mid-execution timeout).
				$elapsed   = time() - $conversation_start;
				$remaining = $max_conversation_time - $elapsed;

				if ( $remaining < 15 ) {
					// Not enough time to safely execute this tool - skip it.
					$result           = array(
						'success'                => false,
						'error'                  => "Tool execution skipped: {$remaining} seconds remaining, approaching conversation timeout limit",
						'skipped_due_to_timeout' => true,
						'time_remaining'         => $remaining,
					);
					$any_tool_skipped = true;

					// Log skip event.
					Abilities_Bridge_Logger::log_tool_progress(
						$conversation_id,
						$tool_name,
						'skipped',
						"⏱️ Skipped (timeout approaching): {$remaining}s remaining"
					);
				} else {
					// Safe to execute - we have enough time.
					$result = $this->execute_tool( $tool_name, $tool_input, $plan_mode );
				}

				// Log the function call.
				Abilities_Bridge_Logger::log_tool_execution(
					$tool_name,
					$tool_input,
					$result,
					$conversation_id,
					'admin'
				);

				// Mark tool as completed.
				if ( isset( $result['success'] ) && $result['success'] ) {
					Abilities_Bridge_Logger::log_tool_progress(
						$conversation_id,
						$tool_name,
						'completed',
						'✅ ' . $progress_message
					);
				}

				// Format result for Claude.
				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $tool_id,
					'content'     => wp_json_encode( $result ),
				);
			}

			// Add tool results as user message - CRITICAL: This must always happen.
			$conversation->add_user_message_array( $tool_results );

			// Mark tools as processed.
			$has_pending_tools = false;

			// If any tools were skipped due to timeout, exit after saving tool results.
			if ( $any_tool_skipped ) {
				$final_response .= "\n\n⚠️ Conversation approaching timeout limit. Some tool executions were skipped.";
				break;
			}
		}

		// Final activity log before returning to user.
		Abilities_Bridge_Logger::log_tool_progress(
			$conversation_id,
			'response',
			'completed',
			'✅ Processing complete, preparing response...'
		);

		// VALIDATION: Ensure we have a non-empty response.
		// If response is empty and we executed tools, something went wrong.
		$final_response_trimmed = trim( $final_response );
		if ( empty( $final_response_trimmed ) && ! empty( $tool_usage ) ) {
			// Tools were executed but no final response received.
			// Log this issue for debugging.
			Abilities_Bridge_Logger::log_action(
				$conversation_id,
				'empty_response_with_tool_usage',
				'Warning: Response was empty despite tool execution. Iterations: ' . $iteration
			);

			// Return a fallback message.
			$final_response_trimmed = 'Tool execution completed. Response pending - please refresh or send another message.';
		}

		return array(
			'success'    => true,
			'response'   => $final_response_trimmed,
			'iterations' => $iteration,
			'tool_usage' => $tool_usage,
		);
	}

	/**
	 * Generate progress message for tool execution
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input Tool input.
	 * @return string Progress message
	 */
	private static function get_tool_progress_message( $tool_name, $input ) {
		switch ( $tool_name ) {
			case 'memory':
				$command = isset( $input['command'] ) ? $input['command'] : 'access';
				return "🧠 Memory: {$command}";

			default:
				return "🔧 Executing: {$tool_name}";
		}
	}

	/**
	 * Execute a tool function.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input Tool input parameters.
	 * @param bool   $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @return array Result.
	 * @throws Exception When an unknown tool is requested.
	 */
	private function execute_tool( $tool_name, $input, $plan_mode = false ) {
		// Check if this is an ability execution request.
		if ( strpos( $tool_name, 'ability_' ) === 0 ) {
			// Extract ability name and convert underscores back to slashes.
			$ability_name = substr( $tool_name, 8 ); // Remove 'ability_' prefix.
			$ability_name = str_replace( '_', '/', $ability_name ); // Convert back to original format.

			// Validate input.
			if ( is_object( $input ) ) {
				$input = (array) $input;
			}
			if ( is_null( $input ) ) {
				$input = array();
			}

			// Pass input directly to orchestrator - abilities use their native input schemas.
			// No wrapper required (parameters/reason wrapper removed to match MCP server behavior).
			return $this->orchestrator->execute_ability_request(
				$ability_name,
				$input,  // Direct parameters matching the ability's input schema.
				'Executed via WordPress chat',
				null // No conversation ID context needed here.
			);
		}

		// Plan Mode restrictions - simple and clear.
		if ( $plan_mode ) {
			$blocked_tools = array(
				'execute_wp_cli' => 'WP-CLI commands are disabled in Plan Mode',
				'execute_php'    => 'PHP execution is disabled in Plan Mode',
				'write_file'     => 'File writing is disabled in Plan Mode',
				'database_query' => 'Database queries are disabled in Plan Mode',
			);

			if ( isset( $blocked_tools[ $tool_name ] ) ) {
				return array(
					'success'   => false,
					'error'     => $blocked_tools[ $tool_name ],
					'plan_mode' => true,
				);
			}
		}

		// Initialize result.
		$result = array(
			'success' => false,
			'error'   => 'Tool execution failed',
		);

		try {
			// Validate input parameters - convert stdClass objects to arrays.
			if ( is_object( $input ) ) {
				$input = (array) $input;
			}

			// Allow null input for tools that don't require parameters.
			if ( is_null( $input ) ) {
				$input = array();
			}

			if ( ! is_array( $input ) ) {
				throw new Exception( 'Invalid input parameters: must be an array or object' );
			}

			switch ( $tool_name ) {
				case 'memory':
					// Check if memory is enabled.
					if ( ! get_option( 'abilities_bridge_enable_memory', false ) ) {
						$result = array(
							'success' => false,
							'error'   => 'Memory tool is disabled. Enable it in Settings > Memory to use persistent memory.',
						);
						break;
					}

					// Check if user has given consent.
					if ( ! get_option( 'abilities_bridge_memory_consent', false ) ) {
						$result = array(
							'success' => false,
							'error'   => 'Memory tool requires user consent. Please enable and accept the consent in Settings.',
						);
						break;
					}

					require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-memory-functions.php';
					$result = Abilities_Bridge_Memory_Functions::execute( $input );
					break;

				default:
					$result = array(
						'success' => false,
						'error'   => "Unknown tool: {$tool_name}. Available tools: memory",
					);
			}

			// Ensure result is properly formatted.
			if ( ! is_array( $result ) ) {
				$result = array(
					'success'    => false,
					'error'      => 'Tool returned invalid response format',
					'raw_result' => wp_json_encode( $result ),
				);
			}

			// Ensure result has success flag.
			if ( ! isset( $result['success'] ) ) {
				$result['success'] = false;
				$result['error']   = 'Tool did not return success status';
			}
		} catch ( Exception $e ) {
			$result = array(
				'success' => false,
				'error'   => 'Exception: ' . $e->getMessage(),
				'tool'    => $tool_name,
				'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
			);
		} catch ( Error $e ) {
			$result = array(
				'success' => false,
				'error'   => 'Fatal Error: ' . $e->getMessage(),
				'tool'    => $tool_name,
				'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
			);
		}

		// Truncate large error messages.
		if ( isset( $result['error'] ) && strlen( $result['error'] ) > 5000 ) {
			$result['error'] = substr( $result['error'], 0, 5000 ) . '... [truncated]';
		}

		// Truncate large output.
		if ( isset( $result['output'] ) && strlen( $result['output'] ) > 500000 ) {
			$result['output'] = substr( $result['output'], 0, 500000 ) . '... [output truncated]';
		}

		return $result;
	}

	/**
	 * Convert technical error to user-friendly message
	 *
	 * @param WP_Error $error The error object.
	 * @return string User-friendly error message
	 */
	private static function get_user_friendly_error( $error ) {
		$error_code = $error->get_error_code();
		$error_data = $error->get_error_data();

		$friendly_messages = array(
			'json_parse_error'           => 'Unable to connect to AI service. Please try again.',
			'invalid_response_structure' => 'Received unexpected response. Please try again.',
			'no_api_key'                 => 'API key not configured. Please check Settings.',
			'overloaded_error'           => 'AI service is busy. Please wait a moment and try again.',
			'rate_limit_error'           => 'Rate limit reached. Please wait before sending another message.',
		);

		if ( isset( $error_data['status'] ) ) {
			$status = $error_data['status'];
			if ( 401 === $status ) {
				return 'Invalid API key. Please check your settings.';
			} elseif ( 429 === $status ) {
				return 'Rate limit reached. Please wait a moment.';
			} elseif ( $status >= 500 ) {
				return 'AI service temporarily unavailable. Please try again.';
			}
		}

		return isset( $friendly_messages[ $error_code ] )
			? $friendly_messages[ $error_code ]
			: 'Unable to process request. Please try again.';
	}
}
