<?php
/**
 * Token calculation utility class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Calculator class.
 *
 * Calculates token usage and costs.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Token_Calculator {

	/**
	 * Calculate token usage for conversation
	 * No limits enforced - purely informational
	 *
	 * @param array       $messages Conversation messages.
	 * @param array       $tools Tool definitions.
	 * @param string|null $model Model identifier (uses current setting if not specified).
	 * @return array Token usage statistics
	 */
	public static function calculate_token_usage( $messages, $tools = array(), $model = null, $provider = null ) {
		$provider = $provider ? $provider : Abilities_Bridge_AI_Provider::get_current_provider();

		// Get current model or use specified one.
		if ( empty( $model ) ) {
			$model = Abilities_Bridge_AI_Provider::get_selected_model( $provider );
		}

		// Get model limits dynamically.
		$model_limits = self::get_model_limits( $model, $provider );

		$token_count = array(
			'messages'     => 0,
			'system'       => 0,
			'tools'        => 0,
			'total'        => 0,
			'model'        => $model,
			'input_limit'  => $model_limits['input'],
			'output_limit' => $model_limits['output'],
			'percentage'   => 0,
			'status'       => 'healthy',
		);

		// Count system prompt tokens.
		$system_prompt         = get_option( 'abilities_bridge_system_prompt', Abilities_Bridge_Claude_API::get_default_system_prompt() );
		$token_count['system'] = self::estimate_tokens( $system_prompt, $provider );

		// Count message tokens.
		foreach ( $messages as $msg ) {
			$content                  = is_array( $msg['content'] ) ? wp_json_encode( $msg['content'] ) : $msg['content'];
			$token_count['messages'] += self::estimate_tokens( $content, $provider );
		}

		// Count tool definition tokens.
		if ( ! empty( $tools ) ) {
			$token_count['tools'] = self::estimate_tokens( wp_json_encode( $tools ), $provider );
		}

		// Calculate totals.
		$token_count['total'] = $token_count['system'] + $token_count['messages'] + $token_count['tools'];

		// Calculate percentage only if we know the limit.
		if ( $model_limits['input'] > 0 ) {
			$token_count['percentage'] = round( ( $token_count['total'] / $model_limits['input'] ) * 100, 1 );
		}

		$token_count['remaining'] = $model_limits['input'] > 0
			? $model_limits['input'] - $token_count['total']
			: -1; // -1 indicates no limit.

		return $token_count;
	}

	/**
	 * Estimate token count for text
	 * More accurate estimation using character and word counts
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count
	 */
	public static function estimate_tokens( $text, $provider = null ) {
		if ( empty( $text ) ) {
			return 0;
		}

		$provider   = $provider ? $provider : Abilities_Bridge_AI_Provider::get_current_provider();
		$char_count = strlen( $text );
		$word_count = str_word_count( $text );

		if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider ) {
			// OpenAI guidance: ~1 token per 4 characters in English.
			$estimated = ( $char_count / 4 ) + ( $word_count * 0.2 );
		} else {
			// Claude uses approximately 1 token per 3.5 characters for English.
			$estimated = ( $char_count / 3.5 ) + ( $word_count * 0.3 );
		}

		return intval( $estimated );
	}

	/**
	 * Get model limits for specified model
	 *
	 * @param string $model Model identifier.
	 * @return array Model limits (input, output, name)
	 */
	public static function get_model_limits( $model, $provider = null ) {
		$provider = $provider ? $provider : Abilities_Bridge_AI_Provider::get_current_provider();

		if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider ) {
			$model_configs = array(
				'gpt-5.4' => array(
					'input_limit'  => 1050000,
					'output_limit' => 128000,
					'name'         => 'GPT-5.4',
				),
				'gpt-5.4-chat-latest' => array(
					'input_limit'  => 1050000,
					'output_limit' => 128000,
					'name'         => 'GPT-5.4',
				),
				'gpt-5.2-chat-latest' => array(
					'input_limit'  => 128000,
					'output_limit' => 16384,
					'name'         => 'GPT-5.2 Chat',
				),
				'gpt-5.1-chat-latest' => array(
					'input_limit'  => 128000,
					'output_limit' => 16384,
					'name'         => 'GPT-5.1 Chat',
				),
			);

			$config = isset( $model_configs[ $model ] )
				? $model_configs[ $model ]
				: $model_configs['gpt-5.4'];
		} else {
			// Model configurations for Claude models.
			$model_configs = array(
				'claude-opus-4-6'            => array(
					'input_limit'  => 200000,
					'output_limit' => 64000,
					'name'         => 'Claude Opus 4.6',
				),
				'claude-opus-4-5-20251101'   => array(
					'input_limit'  => 200000,
					'output_limit' => 64000,
					'name'         => 'Claude Opus 4.5',
				),
				'claude-sonnet-4-5-20250929' => array(
					'input_limit'  => 200000,
					'output_limit' => 64000,
					'name'         => 'Claude Sonnet 4.5',
				),
				'claude-haiku-4-5-20251001'  => array(
					'input_limit'  => 200000,
					'output_limit' => 64000,
					'name'         => 'Claude Haiku 4.5',
				),
			);

			// Get config for current model, fallback to Sonnet 4.5.
			$config = isset( $model_configs[ $model ] )
				? $model_configs[ $model ]
				: $model_configs['claude-sonnet-4-5-20250929'];
		}

		return array(
			'input'  => $config['input_limit'],
			'output' => $config['output_limit'],
			'name'   => $config['name'],
		);
	}
}
