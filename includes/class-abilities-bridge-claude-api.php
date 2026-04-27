<?php
/**
 * Claude API wrapper class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude API class.
 *
 * Handles communication with the Claude AI API.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Claude_API {

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
	private $api_url = 'https://api.anthropic.com/v1/messages';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_key = Abilities_Bridge_AI_Provider::get_api_key( Abilities_Bridge_AI_Provider::PROVIDER_ANTHROPIC );
	}

	/**
	 * Get available Claude models with user-friendly names
	 *
	 * @return array Model ID => Display name
	 */
	public static function get_available_models() {
		return array(
			'claude-opus-4-7'           => 'Opus 4.7 (Most Intelligent)',
			'claude-opus-4-6'           => 'Opus 4.6',
			'claude-sonnet-4-6'         => 'Sonnet 4.6 (Balanced)',
			'claude-haiku-4-5-20251001' => 'Haiku 4.5 (Fastest & Cheapest)',
		);
	}

	/**
	 * Get default Claude model
	 *
	 * @return string
	 */
	public static function get_default_model() {
		return 'claude-sonnet-4-6';
	}

	/**
	 * Get the currently selected model for the user
	 *
	 * @return string Model identifier
	 */
	public static function get_selected_model() {
		$user_id = get_current_user_id();
		$model   = get_user_meta( $user_id, 'abilities_bridge_selected_model', true );

		// Default to Sonnet 4.6 if no preference set (latest balanced model).
		if ( empty( $model ) ) {
			$model = self::get_default_model();
		}

		return $model;
	}

	/**
	 * Set the selected model for the user
	 *
	 * @param string $model Model identifier.
	 * @return bool Success
	 */
	public static function set_selected_model( $model ) {
		$user_id          = get_current_user_id();
		$available_models = self::get_available_models();

		// Validate model exists.
		if ( ! isset( $available_models[ $model ] ) ) {
			return false;
		}

		return update_user_meta( $user_id, 'abilities_bridge_selected_model', $model );
	}

	/**
	 * Get default system prompt
	 *
	 * @return string Default system prompt content
	 */
	public static function get_default_system_prompt() {
		return '# Abilities Bridge System Prompt

You are an AI assistant, operating within WordPress via the Abilities Bridge plugin through two interfaces:
1. Admin Chat Interface - Built-in WordPress admin interface
2. MCP Integration - Connected via Model Context Protocol (AI clients such as Claude Desktop, Claude Code, etc.)

## Your Role

You are an assistant for WordPress sites. Your purpose is to:
- Answer questions about WordPress
- Help with planning and documentation
- Make expert recommendations
- Execute abilities (if enabled)

## Available Tools

### Memory Tool (Optional)
If enabled, you can store persistent notes across conversations:
- Data is stored securely in the WordPress database
- Commands: view, create, str_replace, insert, delete, rename
- Use to remember site context, track findings, store preferences
- Only available if user has enabled and consented in Settings

### Abilities API (Managed Permissions)

WordPress plugins can register AI-callable abilities with granular permission controls. Abilities Bridge passes each ability through a 7-gate permission system that administrators configure. Common abilities may include:
- get-site-info - Get WordPress site information (version, plugins, themes)
- get-user-info - Get user roles and permissions
- get-environment-info - Get server environment details

Important: Abilities are managed by the site administrator. If an ability you need isn\'t available, inform the user that it may need to be enabled in the Ability Permissions settings.

## What You CAN Do

✅ Answer questions and provide guidance
✅ Create implementation plans
✅ Generate documentation
✅ Make expert recommendations
✅ Store notes in memory (if enabled)
✅ Execute abilities registered by plugins (subject to permissions)

## How to Help Users

1. Understand their request - Ask clarifying questions if needed
2. Use available tools and approved abilities to assist
3. Provide helpful guidance and recommendations
4. Create actionable plans that users or administrators can execute
5. Respect permissions - If an ability is unavailable, guide users to enable it';
	}

	/**
	 * Get system prompt from database option
	 *
	 * @return string System prompt content
	 */
	private function get_system_prompt() {
		return get_option( 'abilities_bridge_system_prompt', self::get_default_system_prompt() );
	}

	/**
	 * Send a message to Claude with tool support
	 *
	 * @param array  $messages Array of messages.
	 * @param array  $tools Array of tool definitions.
	 * @param int    $max_tokens Maximum tokens for response.
	 * @param string $model Model to use (default: user's selected model).
	 * @return array|WP_Error Response or error
	 */
	public function send_message( $messages, $tools = array(), $max_tokens = 4096, $model = null ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key not configured. Please add your API key in Settings.' );
		}

		// Use provided model or get user's selected model.
		if ( empty( $model ) ) {
			$model = self::get_selected_model();
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $messages,
		);

		// Add system prompt with prompt caching.
		$system_prompt = $this->get_system_prompt();
		if ( ! empty( $system_prompt ) ) {
			// Use prompt caching for system prompt (90% cost reduction on cached tokens).
			$body['system'] = array(
				array(
					'type'          => 'text',
					'text'          => $system_prompt,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			);
		}

		if ( ! empty( $tools ) ) {
			// Add prompt caching to tools (cache on last tool for 90% cost reduction).
			$tools_with_cache = $tools;
			if ( count( $tools_with_cache ) > 0 ) {
				// Add cache_control to the last tool (memory tool).
				$last_tool_index                                       = count( $tools_with_cache ) - 1;
				$tools_with_cache[ $last_tool_index ]['cache_control'] = array( 'type' => 'ephemeral' );
			}
			$body['tools'] = $tools_with_cache;
		}

		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout' => 300, // Increased to 5 minutes.
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
					'anthropic-beta'    => 'prompt-caching-2024-07-31,context-management-2025-06-27',
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

		// Validate JSON parse.
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

		// Validate response structure.
		if ( ! is_array( $data ) || ! isset( $data['content'] ) ) {
			return new WP_Error(
				'invalid_response_structure',
				'Invalid API response structure',
				array(
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
					'status' => $response_code,
					'data'   => $data,
				)
			);
		}

		// Log cache usage statistics if available.
		$this->log_cache_usage( $data );

		return $data;
	}

	/**
	 * Log cache usage statistics from API response
	 *
	 * @param array $response API response data.
	 */
	private function log_cache_usage( $response ) {
		if ( ! isset( $response['usage'] ) ) {
			return;
		}

		$usage = $response['usage'];

		// Extract cache statistics.
		$cache_stats = array(
			'input_tokens'                => isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0,
			'cache_creation_input_tokens' => isset( $usage['cache_creation_input_tokens'] ) ? $usage['cache_creation_input_tokens'] : 0,
			'cache_read_input_tokens'     => isset( $usage['cache_read_input_tokens'] ) ? $usage['cache_read_input_tokens'] : 0,
			'output_tokens'               => isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0,
		);

		// Calculate cache statistics.
		$total_input    = $cache_stats['input_tokens'] + $cache_stats['cache_creation_input_tokens'] + $cache_stats['cache_read_input_tokens'];
		$cache_hit_rate = $total_input > 0 ? ( $cache_stats['cache_read_input_tokens'] / $total_input ) * 100 : 0;

		// Store cumulative statistics.
		$cumulative_stats = get_option(
			'abilities_bridge_cache_stats',
			array(
				'total_requests'              => 0,
				'total_input_tokens'          => 0,
				'total_cache_creation_tokens' => 0,
				'total_cache_read_tokens'     => 0,
				'total_output_tokens'         => 0,
				'last_updated'                => time(),
			)
		);

		++$cumulative_stats['total_requests'];
		$cumulative_stats['total_input_tokens']          += $cache_stats['input_tokens'];
		$cumulative_stats['total_cache_creation_tokens'] += $cache_stats['cache_creation_input_tokens'];
		$cumulative_stats['total_cache_read_tokens']     += $cache_stats['cache_read_input_tokens'];
		$cumulative_stats['total_output_tokens']         += $cache_stats['output_tokens'];
		$cumulative_stats['last_updated']                 = time();

		update_option( 'abilities_bridge_cache_stats', $cumulative_stats );
	}

	/**
	 * Get cache usage statistics
	 *
	 * @return array Cache statistics
	 */
	public static function get_cache_stats() {
		$stats = get_option(
			'abilities_bridge_cache_stats',
			array(
				'total_requests'              => 0,
				'total_input_tokens'          => 0,
				'total_cache_creation_tokens' => 0,
				'total_cache_read_tokens'     => 0,
				'total_output_tokens'         => 0,
				'last_updated'                => 0,
			)
		);

		// Calculate derived statistics.
		$total_input    = $stats['total_input_tokens'] + $stats['total_cache_creation_tokens'] + $stats['total_cache_read_tokens'];
		$cache_hit_rate = $total_input > 0 ? ( $stats['total_cache_read_tokens'] / $total_input ) * 100 : 0;

		// Calculate cost savings (Sonnet 4.6 pricing).
		$regular_input_cost = 3.00; // $3 per million tokens.
		$cached_input_cost  = 0.30; // $0.30 per million tokens (90% discount)

		$cost_without_cache = ( $total_input / 1000000 ) * $regular_input_cost;
		$cost_with_cache    = (
			( ( $stats['total_input_tokens'] + $stats['total_cache_creation_tokens'] ) / 1000000 ) * $regular_input_cost +
			( $stats['total_cache_read_tokens'] / 1000000 ) * $cached_input_cost
		);
		$cost_savings       = $cost_without_cache - $cost_with_cache;
		$savings_percentage = $cost_without_cache > 0 ? ( $cost_savings / $cost_without_cache ) * 100 : 0;

		$stats['cache_hit_rate']     = round( $cache_hit_rate, 2 );
		$stats['cost_without_cache'] = round( $cost_without_cache, 4 );
		$stats['cost_with_cache']    = round( $cost_with_cache, 4 );
		$stats['cost_savings']       = round( $cost_savings, 4 );
		$stats['savings_percentage'] = round( $savings_percentage, 2 );

		return $stats;
	}

	/**
	 * Reset cache statistics
	 */
	public static function reset_cache_stats() {
		delete_option( 'abilities_bridge_cache_stats' );
	}

	/**
	 * Get API usage and billing information
	 *
	 * @return array|WP_Error Usage data or error
	 */
	public function get_usage() {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'Anthropic API key not configured.' );
		}

		// Note: Anthropic doesn't have a public usage API endpoint yet.
		// This is a placeholder that returns mock data.
		// In production, you would integrate with their billing dashboard API when available.

		return array(
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'balance'       => 'N/A',
			'note'          => 'Usage tracking requires Anthropic Console access. Visit https://console.anthropic.com for billing details.',
		);
	}

	/**
	 * Get tool definitions for Claude
	 *
	 * @return array
	 */
	public static function get_tool_definitions() {
		// Define all core tools.
		// Note: Only memory tool is available. File system access is not permitted.
		$all_core_tools = array(
			array(
				'name'         => 'memory',
				'description'  => 'Store and retrieve persistent memory data across conversations. Allows creating notes, tracking site knowledge, and maintaining context. Data is stored securely in the WordPress database. Available commands: view (read data/directories), create (write data), str_replace (modify text), insert (add lines), delete (remove data), rename (move data).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'command'     => array(
							'type'        => 'string',
							'description' => 'Memory command to execute. Options: "view", "create", "str_replace", "insert", "delete", "rename"',
							'enum'        => array( 'view', 'create', 'str_replace', 'insert', 'delete', 'rename' ),
						),
						'path'        => array(
							'type'        => 'string',
							'description' => 'Path to memory file or directory. Must start with /memories. Example: "/memories/site_notes.xml"',
						),
						'file_text'   => array(
							'type'        => 'string',
							'description' => 'File content for create command',
						),
						'old_str'     => array(
							'type'        => 'string',
							'description' => 'String to replace for str_replace command',
						),
						'new_str'     => array(
							'type'        => 'string',
							'description' => 'Replacement string for str_replace command',
						),
						'insert_line' => array(
							'type'        => 'integer',
							'description' => 'Line number for insert command (0-indexed)',
						),
						'insert_text' => array(
							'type'        => 'string',
							'description' => 'Text to insert for insert command',
						),
						'old_path'    => array(
							'type'        => 'string',
							'description' => 'Source path for rename command',
						),
						'new_path'    => array(
							'type'        => 'string',
							'description' => 'Destination path for rename command',
						),
						'view_range'  => array(
							'type'        => 'array',
							'description' => 'Optional line range for view command [start, end]',
							'items'       => array(
								'type' => 'integer',
							),
						),
					),
					'required'   => array( 'command' ),
				),
			),
		);

		// Filter core tools based on enable/disable settings.
		$tools = array();
		foreach ( $all_core_tools as $tool ) {
			$tool_name = $tool['name'];
			$enabled   = get_option( 'abilities_bridge_enable_' . $tool_name, true ); // Default to true for backward compatibility.

			// Only add tool if it's enabled.
			if ( $enabled ) {
				$tools[] = $tool;
			}
		}

		// Add approved WordPress abilities to tool definitions (only if API is enabled).
		$abilities_api_enabled = get_option( 'abilities_bridge_enable_abilities_api', false );
		if ( $abilities_api_enabled && class_exists( 'Abilities_Bridge_Ability_Permissions' ) && function_exists( 'wp_get_ability' ) ) {
			$enabled_abilities = Abilities_Bridge_Ability_Permissions::get_all_permissions( true ); // Get enabled only.

			foreach ( $enabled_abilities as $ability_config ) {
				$ability_name = $ability_config['ability_name'];

				// Get the ability object from Abilities API.
				$ability = wp_get_ability( $ability_name );

				if ( $ability ) {
					// Get input schema from ability.
					$input_schema = $ability->get_input_schema();

					// Skip abilities without input schemas - they cannot accept parameters.
					// The WordPress Abilities API requires input schemas to validate parameters.
					if ( empty( $input_schema ) ) {
						continue;
					}

					// Note: WordPress Abilities API already returns properly formatted JSON Schema.
					// No conversion needed - schemas are already Anthropic API compatible.

					// Fix: Ensure empty properties array encodes as {} not [].
					// PHP's json_encode converts empty arrays to [], but Claude API requires objects.
					if ( isset( $input_schema['properties'] ) && empty( $input_schema['properties'] ) ) {
						$input_schema['properties'] = new stdClass();
					}

					// Build description with security context.
					$description  = $ability_config['description'];
					$description .= sprintf(
						' [Security: Risk=%s, Limit=%d/day, Requires=%s]',
						strtoupper( $ability_config['risk_level'] ),
						$ability_config['max_per_day'],
						$ability_config['min_capability'] ? $ability_config['min_capability'] : 'any user'
					);

					// Add ability to tool list with ability_ prefix (convert / to _ for API compliance).
					// Tool names must match: ^[a-zA-Z0-9_-]{1,128}$.
					$tool_name = 'ability_' . str_replace( '/', '_', $ability_name );

					$tools[] = array(
						'name'         => $tool_name,
						'description'  => $description,
						'input_schema' => $input_schema,  // Must match hardcoded tools format (snake_case).
					);
				}
			}
		}

		return $tools;
	}
}
