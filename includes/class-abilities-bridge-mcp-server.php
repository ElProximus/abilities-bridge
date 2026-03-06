<?php
/**
 * MCP Server Implementation - PHP-based Model Context Protocol Server.
 *
 * Implements the MCP protocol with Streamable HTTP transport for remote connector support.
 * This allows Claude Desktop to connect to WordPress via Settings → Connectors GUI.
 *
 * @package Abilities_Bridge
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Server class for handling Model Context Protocol requests.
 */
class Abilities_Bridge_MCP_Server {

	/**
	 * MCP Protocol version.
	 */
	const PROTOCOL_VERSION = '2025-03-26';

	/**
	 * Server information.
	 */
	const SERVER_NAME    = 'abilities-bridge';
	const SERVER_VERSION = '1.1.1';

	/**
	 * Handle MCP protocol request.
	 *
	 * @param WP_REST_Request $request Full request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function handle_request( $request ) {
		try {
			// Check JSON validity FIRST (JSON-RPC 2.0 spec requires parse errors before auth).
			$body = $request->get_json_params();

			if ( empty( $body ) ) {
				return $this->error_response( -32700, 'Parse error: Invalid JSON', null );
			}

			// Check authentication after successful JSON parsing.
			// This ensures auth errors are returned in proper JSON-RPC 2.0 format.
			$oauth       = new Abilities_Bridge_MCP_OAuth();
			$auth_result = $oauth->check_permission( $request );

			if ( is_wp_error( $auth_result ) ) {
				// Convert WP_Error to JSON-RPC error format.
				return $this->error_response(
					-32000, // Server error code for authentication failures.
					$auth_result->get_error_message(),
					isset( $body['id'] ) ? $body['id'] : null // Include ID if parsed.
				);
			}

			// Validate JSON-RPC 2.0 format.
			if ( ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
				return $this->error_response( -32600, 'Invalid Request: Missing or invalid jsonrpc version', null );
			}

			if ( ! isset( $body['method'] ) ) {
				return $this->error_response( -32600, 'Invalid Request: Missing method', null );
			}

			$method = $body['method'];
			$params = isset( $body['params'] ) ? $body['params'] : array();
			$id     = isset( $body['id'] ) ? $body['id'] : null;

			// Route to appropriate handler.
			switch ( $method ) {
				case 'initialize':
					$result = $this->handle_initialize( $params );
					break;

				case 'tools/list':
					$result = $this->handle_tools_list( $params );
					break;

				case 'tools/call':
					$result = $this->handle_tools_call( $params );
					break;

				case 'ping':
					$result = array( 'status' => 'ok' );
					break;

				default:
					return $this->error_response( -32601, "Method not found: {$method}", $id );
			}

			// Return successful response.
			return $this->success_response( $result, $id );

		} catch ( Exception $e ) {
			// Catch any exceptions and return as JSON-RPC error.
			$id = isset( $body['id'] ) ? $body['id'] : null;
			return $this->error_response( -32603, 'Internal error: ' . $e->getMessage(), $id );
		}
	}

	/**
	 * Handle initialize request.
	 *
	 * @param array $params Request parameters.
	 * @return array Initialization response.
	 */
	private function handle_initialize( $params ) {
		$site_url = trailingslashit( get_site_url() );
		$base_url = $site_url . 'wp-json/abilities-bridge-mcp/v1';

		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'serverInfo'      => array(
				'name'    => self::SERVER_NAME,
				'version' => self::SERVER_VERSION,
			),
			'capabilities'    => array(
				'tools'        => new stdClass(), // Proper empty object.
				'experimental' => array(
					'oauth' => array(
						'authorizationEndpoint' => $base_url . '/authorize',
						'tokenEndpoint'         => $base_url . '/oauth/token',
						'grantTypes'            => array( 'authorization_code', 'client_credentials', 'refresh_token' ),
						'pkceRequired'          => true,
					),
				),
			),
		);
	}

	/**
	 * Handle tools/list request.
	 *
	 * @param array $params Request parameters.
	 * @return array Tools list response.
	 */
	private function handle_tools_list( $params ) {
		$tools = array();

		// Add memory tool if enabled.
		$memory_enabled = get_option( 'abilities_bridge_enable_memory', false );
		if ( $memory_enabled ) {
			$tools[] = array(
				'name'        => 'memory',
				'description' => 'Persistent storage system for maintaining context and notes across conversations. Can create, read, update, and delete memory entries in the database.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'command'     => array(
							'type'        => 'string',
							'description' => 'Memory command to execute.',
							'enum'        => array( 'view', 'create', 'str_replace', 'insert', 'delete', 'rename' ),
						),
						'path'        => array(
							'type'        => 'string',
							'description' => 'Path to memory file or directory.',
						),
						'file_text'   => array(
							'type'        => 'string',
							'description' => 'Content for create command.',
						),
						'old_str'     => array(
							'type'        => 'string',
							'description' => 'String to replace in str_replace command.',
						),
						'new_str'     => array(
							'type'        => 'string',
							'description' => 'Replacement string in str_replace command.',
						),
						'insert_line' => array(
							'type'        => 'integer',
							'description' => 'Line number for insert command.',
						),
						'insert_text' => array(
							'type'        => 'string',
							'description' => 'Text to insert.',
						),
						'old_path'    => array(
							'type'        => 'string',
							'description' => 'Source path for rename command.',
						),
						'new_path'    => array(
							'type'        => 'string',
							'description' => 'Destination path for rename command.',
						),
						'view_range'  => array(
							'type'        => 'array',
							'description' => 'Line range for view command [start, end].',
							'items'       => array( 'type' => 'integer' ),
						),
					),
					'required'   => array( 'command' ),
				),
			);
		}

		// Add enabled abilities from WordPress Abilities API (only if API is enabled).
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
					if ( empty( $input_schema ) ) {
						continue;
					}

					// Fix: Ensure empty properties array encodes as {} not [].
					// PHP's json_encode converts empty arrays to [], but MCP protocol requires objects.
					if ( isset( $input_schema['properties'] ) && empty( $input_schema['properties'] ) ) {
						$input_schema['properties'] = new stdClass();
					}

					// Build tool name with ability_ prefix (convert / to _).
					$tool_name = 'ability_' . str_replace( '/', '_', $ability_name );

					// Add ability to tools list.
					$tools[] = array(
						'name'        => $tool_name,
						'description' => ! empty( $ability->description ) ? $ability->description : "Execute {$ability_name} ability",
						'inputSchema' => $input_schema,
					);
				}
			}
		}

		return array(
			'tools' => $tools,
		);
	}

	/**
	 * Handle tools/call request.
	 *
	 * @param array $params Request parameters.
	 * @return array Tool execution response.
	 * @throws Exception When tool is not found or disabled.
	 */
	private function handle_tools_call( $params ) {
		if ( ! isset( $params['name'] ) ) {
			throw new Exception( 'Missing required parameter: name' );
		}

		$tool_name = $params['name'];
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		// Check if this is an ability call (starts with "ability_").
		if ( strpos( $tool_name, 'ability_' ) === 0 ) {
			// Extract ability name and convert underscores back to slashes.
			$ability_name = substr( $tool_name, 8 ); // Remove "ability_" prefix.
			$ability_name = str_replace( '_', '/', $ability_name );

			// Use orchestrator to execute ability with permission checks.
			if ( class_exists( 'Abilities_Bridge_MCP_Orchestrator' ) ) {
				$orchestrator = new Abilities_Bridge_MCP_Orchestrator();
				$result       = $orchestrator->execute_ability_request(
					$ability_name,
					$arguments,
					'MCP Server request via Claude Desktop'
				);

				return $this->format_tool_response( $result );
			} else {
				throw new Exception( 'Orchestrator not available for ability execution' );
			}
		}

		// SCOPE ENFORCEMENT: Check if OAuth token has required scope for this tool.
		if ( isset( $GLOBALS['abilities_bridge_oauth_scope'] ) ) {
			$granted_scope = $GLOBALS['abilities_bridge_oauth_scope'];

			if ( ! Abilities_Bridge_OAuth_Scopes::can_access_tool( $granted_scope, $tool_name ) ) {
				$required_scope = Abilities_Bridge_OAuth_Scopes::get_required_scope_for_tool( $tool_name );

				throw new Exception(
					sprintf(
						'Access denied: Tool %s requires %s scope. Your token has scope: %s. Please re-authorize with the required scope.',
						"'" . esc_html( $tool_name ) . "'",
						$required_scope ? "'" . esc_html( $required_scope ) . "'" : 'appropriate',
						"'" . esc_html( $granted_scope ) . "'"
					)
				);
			}
		}

		// Execute the core tools.
		switch ( $tool_name ) {
			case 'memory':
				$result = $this->execute_memory( $arguments );
				break;

			default:
				throw new Exception( 'Unknown tool: ' . esc_html( $tool_name ) );
		}

		return $result;
	}

	/**
	 * Execute memory tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Formatted tool response.
	 */
	private function execute_memory( $arguments ) {
		// Check if memory tool is enabled.
		if ( ! get_option( 'abilities_bridge_enable_memory', false ) ) {
			return $this->format_tool_response(
				array(
					'success' => false,
					'error'   => 'Memory tool is disabled. Enable it in Settings > Memory.',
				)
			);
		}

		// Check if user has given consent for file operations.
		if ( ! get_option( 'abilities_bridge_memory_consent', false ) ) {
			return $this->format_tool_response(
				array(
					'success' => false,
					'error'   => 'Memory tool requires user consent. Please enable and accept the consent in Settings > Memory.',
				)
			);
		}

		$result = Abilities_Bridge_Memory_Functions::execute( $arguments );

		// Log execution.
		Abilities_Bridge_Logger::log_tool_execution(
			'memory',
			$arguments,
			$result,
			null,
			'mcp'
		);

		return $this->format_tool_response( $result );
	}

	/**
	 * Format tool response for MCP protocol.
	 *
	 * @param array $result WordPress function result.
	 * @return array MCP-formatted response.
	 */
	private function format_tool_response( $result ) {
		// Convert WordPress function result to MCP content format.
		$is_error = isset( $result['success'] ) && ! $result['success'];

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				),
			),
			'isError' => $is_error,
		);
	}

	/**
	 * Create success response.
	 *
	 * @param mixed $result Result data.
	 * @param mixed $id Request ID (string, number, or null).
	 * @return WP_REST_Response Response object.
	 */
	private function success_response( $result, $id = null ) {
		$response_data = array(
			'jsonrpc' => '2.0',
			'result'  => $result,
			'id'      => $id, // Always include ID (null if not determined).
		);

		return rest_ensure_response( $response_data );
	}

	/**
	 * Create error response.
	 *
	 * @param int    $code Error code (must be integer).
	 * @param string $message Error message (must be string).
	 * @param mixed  $id Request ID (string, number, or null).
	 * @return WP_REST_Response Response object.
	 */
	private function error_response( $code, $message, $id = null ) {
		$response_data = array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => (int) $code,
				'message' => (string) $message,
			),
			'id'      => $id, // Always include ID (null if not determined).
		);

		return rest_ensure_response( $response_data );
	}
}
