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
 * Handles communication with the OpenAI Chat Completions API.
 */
class Abilities_Bridge_OpenAI_API {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_key = get_option( 'abilities_bridge_openai_api_key', '' );
	}

	/**
	 * Get available OpenAI models with user-friendly names
	 *
	 * @return array Model ID => Display name
	 */
	public static function get_available_models() {
		return array(
			'gpt-5.2-chat-latest' => 'GPT-5.2 Chat (Latest)',
			'gpt-5-chat-latest' => 'GPT-5 Chat (Latest)',
			'gpt-5.1-chat-latest' => 'GPT-5.1 Chat (Latest)',
		);
	}

	/**
	 * Get default OpenAI model
	 *
	 * @return string
	 */
	public static function get_default_model() {
		return 'gpt-5.2-chat-latest';
	}

	/**
	 * Send a message to OpenAI with tool support
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

		$openai_messages = $this->convert_messages_to_openai( $messages );

		$body = array(
			'model'    => $model,
			'messages' => $openai_messages,
		);

		if ( $max_tokens > 0 ) {
			if ( 0 === strpos( $model, 'gpt-5' ) ) {
				$body['max_completion_tokens'] = $max_tokens;
			} else {
				$body['max_tokens'] = $max_tokens;
			}
		}

		if ( ! empty( $tools ) ) {
			$body['tools']      = $this->convert_tools_to_openai( $tools );
			$body['tool_choice'] = 'auto';
		}

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

		if ( 200 !== $response_code ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown API error';
			$error_type    = isset( $data['error']['type'] ) ? $data['error']['type'] : 'api_error';

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

		if ( ! isset( $data['choices'][0]['message'] ) ) {
			return new WP_Error(
				'invalid_response_structure',
				'Invalid API response structure',
				array(
					'response_preview' => substr( $response_body, 0, 200 ),
					'retryable'        => true,
				)
			);
		}

		$choice        = $data['choices'][0];
		$message       = $choice['message'];
		$finish_reason = isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '';

		$content_blocks = array();

		if ( ! empty( $message['content'] ) ) {
			$content_blocks[] = array(
				'type' => 'text',
				'text' => $message['content'],
			);
		}

		if ( ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			foreach ( $message['tool_calls'] as $tool_call ) {
				$tool_id   = isset( $tool_call['id'] ) ? $tool_call['id'] : uniqid( 'tool_', true );
				$tool_name = isset( $tool_call['function']['name'] ) ? $tool_call['function']['name'] : '';
				$args_json = isset( $tool_call['function']['arguments'] ) ? $tool_call['function']['arguments'] : '';
				$tool_args = json_decode( $args_json, true );

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

		$stop_reason = ( ! empty( $message['tool_calls'] ) || 'tool_calls' === $finish_reason ) ? 'tool_use' : 'end_turn';

		return array(
			'content'     => $content_blocks,
			'stop_reason' => $stop_reason,
			'usage'       => isset( $data['usage'] ) ? $data['usage'] : array(),
		);
	}

	/**
	 * Convert internal messages into OpenAI message format
	 *
	 * @param array $messages Internal messages.
	 * @return array
	 */
	private function convert_messages_to_openai( $messages ) {
		$openai_messages = array();

		$system_prompt = get_option( 'abilities_bridge_system_prompt', Abilities_Bridge_Claude_API::get_default_system_prompt() );
		if ( ! empty( $system_prompt ) ) {
			$openai_messages[] = array(
				'role'    => 'system',
				'content' => $system_prompt,
			);
		}

		foreach ( $messages as $message ) {
			$role    = isset( $message['role'] ) ? $message['role'] : '';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			if ( 'user' === $role ) {
				if ( is_array( $content ) ) {
					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'tool_result' === $block['type'] ) {
							$openai_messages[] = array(
								'role'         => 'tool',
								'tool_call_id' => isset( $block['tool_use_id'] ) ? $block['tool_use_id'] : '',
								'content'      => is_string( $block['content'] ) ? $block['content'] : wp_json_encode( $block['content'] ),
							);
						}
					}
				} else {
					$openai_messages[] = array(
						'role'    => 'user',
						'content' => $content,
					);
				}
			} elseif ( 'assistant' === $role ) {
				if ( is_array( $content ) ) {
					$text_chunks = array();
					$tool_calls  = array();

					foreach ( $content as $block ) {
						if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
							$text_chunks[] = $block['text'];
						} elseif ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
							$arguments = $this->encode_tool_arguments( isset( $block['input'] ) ? $block['input'] : array() );
							$tool_calls[] = array(
								'id'       => $block['id'],
								'type'     => 'function',
								'function' => array(
									'name'      => $block['name'],
									'arguments' => $arguments,
								),
							);
						}
					}

					$assistant_message = array(
						'role' => 'assistant',
					);

					if ( ! empty( $text_chunks ) ) {
						$assistant_message['content'] = implode( "\n\n", $text_chunks );
					} else {
						$assistant_message['content'] = null;
					}

					if ( ! empty( $tool_calls ) ) {
						$assistant_message['tool_calls'] = $tool_calls;
					}

					$openai_messages[] = $assistant_message;
				} else {
					$openai_messages[] = array(
						'role'    => 'assistant',
						'content' => $content,
					);
				}
			}
		}

		return $openai_messages;
	}

	/**
	 * Convert internal tool definitions into OpenAI tool format
	 *
	 * @param array $tools Tool definitions.
	 * @return array
	 */
	private function convert_tools_to_openai( $tools ) {
		$openai_tools = array();
		foreach ( $tools as $tool ) {
			$openai_tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => $tool['input_schema'],
				),
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
