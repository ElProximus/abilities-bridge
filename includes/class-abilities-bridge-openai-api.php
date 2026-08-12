<?php
/**
 * OpenAI API wrapper class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI API class.
 *
 * Handles communication with the OpenAI Responses API.
 */
class Abilities_Bridge_OpenAI_API {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Previous Responses API response id for stateful continuation.
	 *
	 * @var string
	 */
	private $previous_response_id = '';

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.openai.com/v1/responses';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_key = Abilities_Bridge_AI_Provider::get_api_key( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI );
	}

	/**
	 * Get available OpenAI models with user-friendly names
	 *
	 * @return array Model ID => Display name
	 */
	public static function get_available_models() {
		return array(
			'gpt-5.5' => 'GPT-5.5 (Recommended)',
			'gpt-5.4' => 'GPT-5.4',
			'gpt-5.2' => 'GPT-5.2',
			'gpt-5.1' => 'GPT-5.1',
			'gpt-5'   => 'GPT-5',
		);
	}

	/**
	 * Map legacy chat-oriented model aliases onto supported Responses models.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	public static function normalize_model( $model ) {
		$aliases = array(
			'gpt-5.5-chat-latest' => 'gpt-5.5',
			'gpt-5.2-chat-latest' => 'gpt-5.2',
			'gpt-5-chat-latest'   => 'gpt-5',
			'gpt-5.1-chat-latest' => 'gpt-5.1',
		);

		return isset( $aliases[ $model ] ) ? $aliases[ $model ] : $model;
	}

	/**
	 * Get default OpenAI model
	 *
	 * @return string
	 */
	public static function get_default_model() {
		return 'gpt-5.5';
	}

	/**
	 * Set the previous response id for a stateful Responses continuation.
	 *
	 * @param string $previous_response_id Previous OpenAI response id.
	 * @return void
	 */
	public function set_previous_response_id( $previous_response_id ) {
		$this->previous_response_id = is_string( $previous_response_id ) ? trim( $previous_response_id ) : '';
	}

	/**
	 * Send a message to OpenAI with tool support.
	 *
	 * @param array  $messages Array of messages (Claude-style content blocks).
	 * @param array  $tools Array of tool definitions.
	 * @param int    $max_tokens Maximum tokens for response.
	 * @param string $model Model to use.
	 * @return array|WP_Error Response or error
	 */
	public function send_message( $messages, $tools = array(), $max_tokens = 4096, $model = null ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'OpenAI API key not configured. Please add your API key in Settings.' );
		}

		if ( empty( $model ) ) {
			$model = self::get_default_model();
		}

		$model = self::normalize_model( $model );

		$available_models = self::get_available_models();
		if ( ! isset( $available_models[ $model ] ) ) {
			return new WP_Error(
				'model_not_found',
				'OpenAI model is not supported by this plugin configuration.',
				array(
					'status'         => 400,
					'selected_model' => $model,
					'allowed_models' => array_keys( $available_models ),
				)
			);
		}

		$body = array(
			'model' => $model,
			'input' => $this->convert_messages_to_responses_input( $messages, ! empty( $this->previous_response_id ) ),
		);
		if ( apply_filters( 'abilities_bridge_openai_background_mode', false ) ) {
			$body['background'] = true;
			$body['store']      = true;
		}

		if ( ! empty( $this->previous_response_id ) ) {
			$body['previous_response_id'] = $this->previous_response_id;
		}

		$system_prompt = get_option( 'abilities_bridge_system_prompt', Abilities_Bridge_Claude_API::get_default_system_prompt() );
		if ( ! empty( $system_prompt ) ) {
			$body['instructions'] = $system_prompt;
		}

		if ( $max_tokens > 0 ) {
			$body['max_output_tokens'] = $max_tokens;
		}

		if ( ! empty( $tools ) ) {
			$body['tools']       = $this->convert_tools_to_responses( $tools );
			$body['tool_choice'] = 'auto';
		}

		$request = $this->perform_request( $body );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$response_code = $request['response_code'];
		$response_body = $request['response_body'];
		$data          = $request['data'];

		if ( ! empty( $body['previous_response_id'] ) && 400 === $response_code && $this->should_retry_without_previous_response( $data ) ) {
			unset( $body['previous_response_id'] );
			$body['input'] = $this->convert_messages_to_responses_input( $messages, false );
			$request       = $this->perform_request( $body );

			if ( is_wp_error( $request ) ) {
				return $request;
			}

			$response_code = $request['response_code'];
			$response_body = $request['response_body'];
			$data          = $request['data'];
		}

		if ( $response_code < 200 || $response_code >= 300 ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown API error';
			$error_type    = isset( $data['error']['code'] ) ? $data['error']['code'] : ( isset( $data['error']['type'] ) ? $data['error']['type'] : 'api_error' );

			return new WP_Error(
				$error_type,
				$error_message,
				array(
					'status'           => $response_code,
					'provider'         => 'openai',
					'provider_code'    => $error_type,
					'provider_message' => $error_message,
					'data'             => $data,
				)
			);
		}

		if ( ! empty( $body['background'] ) && ! empty( $data['id'] ) ) {
			do_action( 'abilities_bridge_openai_response_submitted', sanitize_text_field( $data['id'] ) );
			if ( in_array( $data['status'] ?? '', array( 'queued', 'in_progress' ), true ) ) {
				$request = $this->poll_background_response( $data['id'] );
				if ( is_wp_error( $request ) ) {
					return $request;
				}
				$response_code = $request['response_code'];
				$response_body = $request['response_body'];
				$data          = $request['data'];
			}
		}

		return $this->parse_response_data( $data, $response_body, $response_code );
	}

	/**
	 * Convert a completed Responses payload into the plugin's provider-neutral shape.
	 *
	 * @param array  $data Parsed payload.
	 * @param string $response_body Raw body for diagnostics.
	 * @param int    $response_code HTTP status.
	 * @return array|WP_Error
	 */
	public function parse_response_data( $data, $response_body = '', $response_code = 200 ) {
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'failed', 'cancelled', 'incomplete' ), true ) ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'The OpenAI background response did not complete.', 'abilities-bridge' );
			return new WP_Error(
				'openai_background_failed',
				$message,
				array(
					'status'           => $response_code,
					'provider'         => 'openai',
					'provider_status'  => $data['status'],
					'provider_message' => $message,
				)
			);
		}

		if ( ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return new WP_Error(
				'ai_generation_ambiguous',
				__( 'The AI returned an unreadable successful response. It may already have been billed, so it was not retried automatically.', 'abilities-bridge' ),
				array(
					'status'           => $response_code,
					'provider'         => 'openai',
					'response_preview' => substr( $response_body, 0, 200 ),
					'retryable'        => false,
				)
			);
		}

		$content_blocks = array();
		$has_tool_calls = false;

		foreach ( $data['output'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['type'] ) ) {
				continue;
			}

			if ( 'message' === $item['type'] && ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
				foreach ( $item['content'] as $content_item ) {
					if ( isset( $content_item['type'] ) && 'output_text' === $content_item['type'] && isset( $content_item['text'] ) ) {
						$content_blocks[] = array(
							'type' => 'text',
							'text' => $content_item['text'],
						);
					}
				}
			} elseif ( 'function_call' === $item['type'] ) {
				$has_tool_calls = true;
				$tool_id        = isset( $item['call_id'] ) ? $item['call_id'] : ( isset( $item['id'] ) ? $item['id'] : uniqid( 'tool_', true ) );
				$tool_name      = isset( $item['name'] ) ? $item['name'] : '';
				$args_json      = isset( $item['arguments'] ) ? $item['arguments'] : '';
				$tool_args      = json_decode( $args_json, true );

				if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $tool_args ) ) {
					$tool_args = array();
				}

				$content_blocks[] = array(
					'type'  => 'tool_use',
					'id'    => $tool_id,
					'name'  => $tool_name,
					'input' => $tool_args,
				);
			}
		}

		$stop_reason = $has_tool_calls ? 'tool_use' : 'end_turn';

		return array(
			'content'     => $content_blocks,
			'stop_reason' => $stop_reason,
			'usage'       => isset( $data['usage'] ) ? $data['usage'] : array(),
			'response_id' => isset( $data['id'] ) ? $data['id'] : '',
		);
	}

	/**
	 * Fetch a stored OpenAI response. GET retries are billing-safe.
	 *
	 * @param string $response_id OpenAI response ID.
	 * @return array|WP_Error Parsed transport envelope.
	 */
	public function fetch_background_response( $response_id ) {
		$response_id = sanitize_text_field( $response_id );
		if ( '' === $response_id ) {
			return new WP_Error( 'missing_response_id', __( 'The OpenAI response ID is missing.', 'abilities-bridge' ) );
		}

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			if ( $attempt > 0 ) {
				sleep( (int) pow( 2, $attempt - 1 ) );
			}

			$response = wp_remote_get(
				trailingslashit( $this->api_url ) . rawurlencode( $response_id ),
				array(
					'timeout' => 30,
					'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
				)
			);
			if ( is_wp_error( $response ) ) {
				if ( 3 === $attempt ) {
					return $response;
				}
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			if ( $code >= 200 && $code < 300 ) {
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
					if ( 3 === $attempt ) {
						return new WP_Error( 'openai_fetch_parse_error', __( 'OpenAI returned an unreadable stored response.', 'abilities-bridge' ) );
					}
					continue;
				}
				return array(
					'response_code' => $code,
					'response_body' => $body,
					'data'          => $data,
				);
			}

			if ( ! in_array( $code, array( 429, 500, 502, 503, 504, 529 ), true ) || 3 === $attempt ) {
				$message = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unable to fetch the stored OpenAI response.', 'abilities-bridge' );
				return new WP_Error(
					'openai_fetch_failed',
					$message,
					array(
						'status'   => $code,
						'provider' => 'openai',
					)
				);
			}
		}

		return new WP_Error( 'openai_fetch_failed', __( 'Unable to fetch the stored OpenAI response.', 'abilities-bridge' ) );
	}

	/**
	 * Poll a submitted background response without creating a new generation.
	 *
	 * @param string $response_id OpenAI response ID.
	 * @return array|WP_Error
	 */
	private function poll_background_response( $response_id ) {
		$interval  = max( 1, (int) apply_filters( 'abilities_bridge_openai_poll_interval', 30 ) );
		$max_polls = max( 1, (int) apply_filters( 'abilities_bridge_openai_max_polls', 40 ) );

		for ( $poll = 0; $poll < $max_polls; ++$poll ) {
			if ( ! apply_filters( 'abilities_bridge_openai_continue_polling', true, $response_id ) ) {
				return new WP_Error( 'job_cancelled', __( 'The chat job was stopped.', 'abilities-bridge' ) );
			}
			sleep( $interval );
			do_action( 'abilities_bridge_openai_poll_tick', $response_id );
			$request = $this->fetch_background_response( $response_id );
			if ( is_wp_error( $request ) ) {
				return $request;
			}

			$status = $request['data']['status'] ?? '';
			if ( ! in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
				return $request;
			}
		}

		return new WP_Error(
			'openai_poll_timeout',
			__( 'OpenAI is still processing the stored response. It was not submitted again.', 'abilities-bridge' ),
			array(
				'provider'    => 'openai',
				'response_id' => $response_id,
			)
		);
	}

	/**
	 * Perform an OpenAI Responses API request and parse the JSON payload.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error
	 */
	private function perform_request( $body ) {
		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => (int) apply_filters( 'abilities_bridge_ai_request_timeout', 120 ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		// Classify the HTTP status before validating JSON. Gateway error
		// responses are commonly empty or HTML, and a parse error must not
		// hide the status used by the billing-safety retry policy.
		if ( $response_code < 200 || $response_code >= 300 ) {
			return array(
				'response_code' => $response_code,
				'response_body' => $response_body,
				'data'          => is_array( $data ) ? $data : array(),
			);
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'ai_generation_ambiguous',
				__( 'The AI returned an unreadable successful response. It may already have been billed, so it was not retried automatically.', 'abilities-bridge' ),
				array(
					'status'           => $response_code,
					'provider'         => 'openai',
					'json_error'       => json_last_error_msg(),
					'response_preview' => substr( $response_body, 0, 200 ),
					'retryable'        => false,
				)
			);
		}

		return array(
			'response_code' => $response_code,
			'response_body' => $response_body,
			'data'          => $data,
		);
	}

	/**
	 * Detect stale or invalid previous_response_id errors and fall back to full history.
	 *
	 * @param array $data Parsed error payload.
	 * @return bool
	 */
	private function should_retry_without_previous_response( $data ) {
		if ( empty( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return false;
		}

		$error_text = '';
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$error_text .= strtolower( $data['error']['message'] );
		}
		if ( isset( $data['error']['code'] ) && is_string( $data['error']['code'] ) ) {
			$error_text .= ' ' . strtolower( $data['error']['code'] );
		}

		return false !== strpos( $error_text, 'previous_response_id' )
			|| false !== strpos( $error_text, 'previous response' )
			|| false !== strpos( $error_text, 'no tool output found' )
			|| false !== strpos( $error_text, 'missing tool output' )
			|| false !== strpos( $error_text, 'function_call_output' );
	}

	/**
	 * Convert internal messages into Responses API input format.
	 *
	 * @param array $messages Internal messages.
	 * @param bool  $incremental_only Whether to convert only the latest user/tool-result input.
	 * @return array
	 */
	private function convert_messages_to_responses_input( $messages, $incremental_only = false ) {
		if ( $incremental_only ) {
			$latest_message = end( $messages );
			if ( false !== $latest_message && isset( $latest_message['role'] ) ) {
				$messages = array( $latest_message );
			}
		}

		$input_items = array();

		foreach ( $messages as $message ) {
			$role    = isset( $message['role'] ) ? $message['role'] : '';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			if ( 'user' === $role ) {
				if ( is_array( $content ) ) {
					$user_content_items = array();

					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'tool_result' === $block['type'] ) {
							$input_items   = $this->flush_user_content_items( $input_items, $user_content_items );
							$input_items[] = array(
								'type'    => 'function_call_output',
								'call_id' => isset( $block['tool_use_id'] ) ? $block['tool_use_id'] : '',
								'output'  => is_string( $block['content'] ) ? $block['content'] : wp_json_encode( $block['content'] ),
							);
						} elseif ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
							$user_content_items[] = array(
								'type' => 'input_text',
								'text' => $block['text'],
							);
						} elseif ( isset( $block['type'], $block['source'] ) && 'image' === $block['type'] && is_array( $block['source'] ) ) {
							$media_type = isset( $block['source']['media_type'] ) ? $block['source']['media_type'] : '';
							$data       = isset( $block['source']['data'] ) ? $block['source']['data'] : '';

							if ( $media_type && $data ) {
								$user_content_items[] = array(
									'type'      => 'input_image',
									'image_url' => 'data:' . $media_type . ';base64,' . $data,
									'detail'    => 'auto',
								);
							}
						}
					}

					$input_items = $this->flush_user_content_items( $input_items, $user_content_items );
				} else {
					$input_items[] = array(
						'role'    => 'user',
						'content' => $content,
					);
				}
			} elseif ( 'assistant' === $role ) {
				if ( is_array( $content ) ) {
					$text_chunks = array();

					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
							$text_chunks[] = $block['text'];
						} elseif ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
							$input_items   = $this->flush_assistant_text_chunks( $input_items, $text_chunks );
							$arguments     = $this->encode_tool_arguments( isset( $block['input'] ) ? $block['input'] : array() );
							$call_id       = isset( $block['id'] ) ? $block['id'] : uniqid( 'tool_', true );
							$input_items[] = array(
								'type'      => 'function_call',
								'call_id'   => $call_id,
								'name'      => isset( $block['name'] ) ? $block['name'] : '',
								'arguments' => $arguments,
							);
						}
					}

					$input_items = $this->flush_assistant_text_chunks( $input_items, $text_chunks );
				} else {
					$input_items[] = array(
						'role'    => 'assistant',
						'content' => $content,
					);
				}
			}
		}

		return $input_items;
	}

	/**
	 * Append buffered assistant text as a single input item while preserving order.
	 *
	 * @param array $input_items Existing input items.
	 * @param array $text_chunks Buffered text chunks.
	 * @return array
	 */
	private function flush_assistant_text_chunks( $input_items, &$text_chunks ) {
		if ( ! empty( $text_chunks ) ) {
			$input_items[] = array(
				'role'    => 'assistant',
				'content' => implode( "\n\n", $text_chunks ),
			);
			$text_chunks   = array();
		}

		return $input_items;
	}

	/**
	 * Append buffered multimodal user content as one Responses message.
	 *
	 * @param array $input_items Existing input items.
	 * @param array $content_items Buffered user content items.
	 * @return array
	 */
	private function flush_user_content_items( $input_items, &$content_items ) {
		if ( ! empty( $content_items ) ) {
			$input_items[] = array(
				'role'    => 'user',
				'content' => $content_items,
			);
			$content_items = array();
		}

		return $input_items;
	}

	/**
	 * Convert internal tool definitions into Responses API tool format.
	 *
	 * @param array $tools Tool definitions.
	 * @return array
	 */
	private function convert_tools_to_responses( $tools ) {
		$openai_tools = array();
		foreach ( $tools as $tool ) {
			$openai_tools[] = array(
				'type'        => 'function',
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $tool['input_schema'],
			);
		}
		return $openai_tools;
	}

	/**
	 * Encode tool arguments ensuring empty objects are preserved.
	 *
	 * @param mixed $input Tool input.
	 * @return string
	 */
	private function encode_tool_arguments( $input ) {
		if ( is_array( $input ) && empty( $input ) ) {
			$input = new stdClass();
		}
		return wp_json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
