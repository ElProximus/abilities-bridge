<?php
/**
 * MCP REST API class - Exposes WordPress tools via REST API for MCP servers.
 *
 * Provides tools accessible via WordPress REST API:
 * - memory: Persistent storage operations
 * - MCP unified endpoint for abilities execution
 *
 * Authentication: WordPress Application Passwords (requires HTTPS) or OAuth 2.0.
 * Authorization: manage_options capability.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP REST API class for handling REST endpoints.
 */
class Abilities_Bridge_MCP_REST_API {

	/**
	 * REST API namespace.
	 */
	const NAMESPACE = 'abilities-bridge-mcp/v1';

	/**
	 * Initialize the REST API.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Register unified MCP endpoint (for remote connector support).
		register_rest_route(
			self::NAMESPACE,
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_mcp_request' ),
				'permission_callback' => '__return_true', // Auth handled inside MCP server to ensure JSON-RPC 2.0 error format.
			)
		);

		// Register memory endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/memory',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'memory' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'command'     => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => 'Memory command to execute.',
						'enum'              => array( 'view', 'create', 'str_replace', 'insert', 'delete', 'rename' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'path'        => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => 'Path to memory file or directory.',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'file_text'   => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'File content for create command.',
					),
					'old_str'     => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'String to replace for str_replace command.',
					),
					'new_str'     => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Replacement string for str_replace command.',
					),
					'insert_line' => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => 'Line number for insert command.',
						'sanitize_callback' => 'absint',
					),
					'insert_text' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Text to insert for insert command.',
					),
					'old_path'    => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => 'Source path for rename command.',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_path'    => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => 'Destination path for rename command.',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'view_range'  => array(
						'required'    => false,
						'type'        => 'array',
						'description' => 'Optional line range for view command [start, end].',
						'items'       => array(
							'type' => 'integer',
						),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to access MCP endpoints.
	 *
	 * Requires:
	 * - Valid WordPress authentication (Application Password via Basic Auth).
	 * - manage_options capability.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_permission( $request ) {
		// Check if user is authenticated.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required. Please use WordPress Application Password.', 'abilities-bridge' ),
				array( 'status' => 401 )
			);
		}

		// Check if user has manage_options capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you do not have permission to access this resource.', 'abilities-bridge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Memory endpoint handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function memory( $request ) {
		// Check if memory tool is enabled.
		if ( ! get_option( 'abilities_bridge_enable_memory', false ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'Memory tool is disabled. Enable it in Settings > Memory.',
				)
			);
		}

		// Check if user has given consent for file operations.
		if ( ! get_option( 'abilities_bridge_memory_consent', false ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'Memory tool requires user consent. Please enable and accept the consent in Settings > Memory.',
				)
			);
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// Call existing function.
		$result = Abilities_Bridge_Memory_Functions::execute( $params );

		return rest_ensure_response( $result );
	}

	/**
	 * Handle unified MCP endpoint.
	 *
	 * This endpoint handles all MCP protocol messages for remote connector support.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_mcp_request( $request ) {
		// Initialize MCP server and handle request.
		$mcp_server = new Abilities_Bridge_MCP_Server();
		return $mcp_server->handle_request( $request );
	}

	/**
	 * Check MCP permission (supports OAuth and Application Password).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, error otherwise.
	 */
	public function check_mcp_permission( $request ) {
		// Use OAuth handler for authentication.
		$oauth = new Abilities_Bridge_MCP_OAuth();
		return $oauth->check_permission( $request );
	}
}
