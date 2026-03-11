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
		return 'gpt-5.4';
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

		if ( 200 !== $response_code ) {
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

		if ( ! isset( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return new WP_Error(
				'invalid_response_structure',
				'Invalid API response structure',
				array(
					'response_preview' => substr( $response_body, 0, 200 ),
					'retryable'        => true,
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
	 * Perform an OpenAI Responses API request and parse the JSON payload.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error
	 */
	private function perform_request( $body ) {
		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => 300,
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

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'json_parse_error',
				'Invalid API response format',
				array(
					'json_error'       => json_last_error_msg(),
					'response_preview' => substr( $response_body, 0, 200 ),
					'retryable'        => true,
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

		return false !== strpos( $error_text, 'previous_response_id' ) || false !== strpos( $error_text, 'previous response' );
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
					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'tool_result' === $block['type'] ) {
							$input_items[] = array(
								'type'    => 'function_call_output',
								'call_id' => isset( $block['tool_use_id'] ) ? $block['tool_use_id'] : '',
								'output'  => is_string( $block['content'] ) ? $block['content'] : wp_json_encode( $block['content'] ),
							);
						}
					}
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
			$text_chunks = array();
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
