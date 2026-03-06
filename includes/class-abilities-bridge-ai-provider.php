<?php
/**
 * AI provider helper class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Provider utilities.
 */
class Abilities_Bridge_AI_Provider {
	const PROVIDER_ANTHROPIC = 'anthropic';
	const PROVIDER_OPENAI    = 'openai';

	/**
	 * Get supported providers.
	 *
	 * @return array Provider key => label
	 */
	public static function get_providers() {
		return array(
			self::PROVIDER_ANTHROPIC => 'Anthropic (Claude)',
			self::PROVIDER_OPENAI    => 'OpenAI',
		);
	}

	/**
	 * Infer provider based on model name.
	 *
	 * @param string      $model Model identifier.
	 * @param string|null $fallback Provider key fallback.
	 * @return string
	 */
	public static function infer_provider_from_model( $model, $fallback = null ) {
		$fallback = $fallback ? $fallback : self::get_current_provider();
		if ( empty( $model ) ) {
			return $fallback;
		}

		$openai_models     = self::get_available_models( self::PROVIDER_OPENAI );
		$anthropic_models  = self::get_available_models( self::PROVIDER_ANTHROPIC );

		if ( isset( $openai_models[ $model ] ) || 0 === strpos( $model, 'gpt-' ) ) {
			return self::PROVIDER_OPENAI;
		}

		if ( isset( $anthropic_models[ $model ] ) || 0 === strpos( $model, 'claude-' ) ) {
			return self::PROVIDER_ANTHROPIC;
		}

		return $fallback;
	}

	/**
	 * Get current provider.
	 *
	 * @return string Provider key
	 */
	public static function get_current_provider() {
		$user_id  = get_current_user_id();
		$provider = get_user_meta( $user_id, 'abilities_bridge_selected_provider', true );
		if ( empty( $provider ) ) {
			$provider = get_option( 'abilities_bridge_ai_provider', self::PROVIDER_ANTHROPIC );
		}
		$providers = self::get_providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = self::PROVIDER_ANTHROPIC;
		}
		return $provider;
	}

	/**
	 * Set selected provider for user.
	 *
	 * @param string $provider Provider key.
	 * @return bool
	 */
	public static function set_selected_provider( $provider ) {
		$providers = self::get_providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		return update_user_meta( $user_id, 'abilities_bridge_selected_provider', $provider );
	}

	/**
	 * Get provider label.
	 *
	 * @param string|null $provider Provider key.
	 * @return string Label
	 */
	public static function get_provider_label( $provider = null ) {
		$provider  = $provider ? $provider : self::get_current_provider();
		$providers = self::get_providers();
		return isset( $providers[ $provider ] ) ? $providers[ $provider ] : $provider;
	}

	/**
	 * Get provider API key.
	 *
	 * @param string|null $provider Provider key.
	 * @return string API key
	 */
	public static function get_api_key( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();

		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return get_option( 'abilities_bridge_openai_api_key', '' );
			case self::PROVIDER_ANTHROPIC:
			default:
				return get_option( 'abilities_bridge_api_key', '' );
		}
	}

	/**
	 * Check if API key configured for current provider.
	 *
	 * @param string|null $provider Provider key.
	 * @return bool
	 */
	public static function has_api_key( $provider = null ) {
		return ! empty( self::get_api_key( $provider ) );
	}

	/**
	 * Get available models for provider.
	 *
	 * @param string|null $provider Provider key.
	 * @return array
	 */
	public static function get_available_models( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();

		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return Abilities_Bridge_OpenAI_API::get_available_models();
			case self::PROVIDER_ANTHROPIC:
			default:
				return Abilities_Bridge_Claude_API::get_available_models();
		}
	}

	/**
	 * Get provider default model.
	 *
	 * @param string|null $provider Provider key.
	 * @return string
	 */
	public static function get_default_model( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();

		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return Abilities_Bridge_OpenAI_API::get_default_model();
			case self::PROVIDER_ANTHROPIC:
			default:
				return Abilities_Bridge_Claude_API::get_default_model();
		}
	}

	/**
	 * Get selected model for provider.
	 *
	 * @param string|null $provider Provider key.
	 * @return string
	 */
	public static function get_selected_model( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();
		$user_id  = get_current_user_id();
		$key      = 'abilities_bridge_selected_model_' . $provider;
		$model    = get_user_meta( $user_id, $key, true );

		$available_models = self::get_available_models( $provider );
		if ( empty( $model ) && self::PROVIDER_ANTHROPIC === $provider ) {
			$legacy_model = get_user_meta( $user_id, 'abilities_bridge_selected_model', true );
			if ( ! empty( $legacy_model ) && isset( $available_models[ $legacy_model ] ) ) {
				$model = $legacy_model;
				update_user_meta( $user_id, $key, $model );
			}
		}

		if ( empty( $model ) || ! isset( $available_models[ $model ] ) ) {
			$model = self::get_default_model( $provider );
		}

		return $model;
	}

	/**
	 * Set selected model for provider.
	 *
	 * @param string      $model Model identifier.
	 * @param string|null $provider Provider key.
	 * @return bool
	 */
	public static function set_selected_model( $model, $provider = null ) {
		$provider         = $provider ? $provider : self::get_current_provider();
		$available_models = self::get_available_models( $provider );

		if ( ! isset( $available_models[ $model ] ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		$key     = 'abilities_bridge_selected_model_' . $provider;
		$updated = update_user_meta( $user_id, $key, $model );
		if ( self::PROVIDER_ANTHROPIC === $provider ) {
			update_user_meta( $user_id, 'abilities_bridge_selected_model', $model );
		}
		return $updated;
	}

	/**
	 * Create API client for provider.
	 *
	 * @param string|null $provider Provider key.
	 * @return Abilities_Bridge_Claude_API|Abilities_Bridge_OpenAI_API
	 */
	public static function create_client( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();

		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return new Abilities_Bridge_OpenAI_API();
			case self::PROVIDER_ANTHROPIC:
			default:
				return new Abilities_Bridge_Claude_API();
		}
	}

	/**
	 * Get shared tool definitions.
	 *
	 * @return array
	 */
	public static function get_tool_definitions() {
		return Abilities_Bridge_Claude_API::get_tool_definitions();
	}
}
