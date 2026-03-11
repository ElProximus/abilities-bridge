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

		$model            = self::normalize_model_for_provider( $model, self::PROVIDER_OPENAI );
		$openai_models    = self::get_available_models( self::PROVIDER_OPENAI );
		$anthropic_models = self::get_available_models( self::PROVIDER_ANTHROPIC );

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
	 * Check if WP AI Client is available.
	 *
	 * Detects both the standalone WP AI Client plugin and
	 * the built-in WordPress 7.0 Connectors feature.
	 *
	 * @return bool
	 */
	public static function is_wp_ai_client_available() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Get an API key from WP AI Client / WordPress Connectors storage.
	 *
	 * Checks WordPress core Connectors options first (bypassing the
	 * masking filter to get the real key), then falls back to the
	 * standalone WP AI Client plugin array option.
	 *
	 * @param string $provider Provider key.
	 * @return string API key or empty string if not found.
	 */
	public static function get_wp_ai_client_key( $provider ) {
		// Map provider to WordPress core Connectors option names.
		// Format: connectors_ai_{connector_id}_api_key
		$core_option_map = array(
			self::PROVIDER_ANTHROPIC => 'connectors_ai_anthropic_api_key',
			self::PROVIDER_OPENAI    => 'connectors_ai_openai_api_key',
		);

		// 1. Check WordPress core Connectors options.
		if ( isset( $core_option_map[ $provider ] ) ) {
			$option_name = $core_option_map[ $provider ];

			// WordPress masks Connectors keys via an option filter.
			// Bypass it to get the real key.
			$mask_callback = '_wp_connectors_mask_api_key';
			$filter_name   = 'option_' . $option_name;
			$has_filter    = has_filter( $filter_name, $mask_callback );

			if ( false !== $has_filter ) {
				remove_filter( $filter_name, $mask_callback );
			}

			$key = get_option( $option_name, '' );

			if ( false !== $has_filter ) {
				add_filter( $filter_name, $mask_callback );
			}

			if ( ! empty( $key ) ) {
				return $key;
			}
		}

		// 2. Fall back to standalone WP AI Client plugin array option.
		$plugin_key_map = array(
			self::PROVIDER_ANTHROPIC => 'anthropic',
			self::PROVIDER_OPENAI    => 'openai',
		);

		$creds = get_option( 'wp_ai_client_provider_credentials', array() );
		if ( is_array( $creds ) && isset( $plugin_key_map[ $provider ], $creds[ $plugin_key_map[ $provider ] ] ) ) {
			$key = $creds[ $plugin_key_map[ $provider ] ];
			if ( ! empty( $key ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Get provider API key.
	 *
	 * @param string|null $provider Provider key.
	 * @return string API key
	 */
	public static function get_api_key( $provider = null ) {
		$provider = $provider ? $provider : self::get_current_provider();

		// Check WP AI Client credentials when enabled.
		if ( get_option( 'abilities_bridge_use_wp_ai_client', false ) ) {
			$wp_ai_key = self::get_wp_ai_client_key( $provider );
			if ( ! empty( $wp_ai_key ) ) {
				return $wp_ai_key;
			}
		}

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

		$normalized_model = self::normalize_model_for_provider( $model, $provider );
		if ( ! empty( $normalized_model ) && $normalized_model !== $model ) {
			$model = $normalized_model;
			update_user_meta( $user_id, $key, $model );
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
		$model            = self::normalize_model_for_provider( $model, $provider );
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
	 * Normalize model ids for a specific provider.
	 *
	 * @param string $model Model identifier.
	 * @param string $provider Provider key.
	 * @return string
	 */
	private static function normalize_model_for_provider( $model, $provider ) {
		if ( self::PROVIDER_OPENAI === $provider ) {
			return Abilities_Bridge_OpenAI_API::normalize_model( $model );
		}

		return $model;
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