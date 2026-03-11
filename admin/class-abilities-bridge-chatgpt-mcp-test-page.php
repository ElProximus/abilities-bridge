<?php
/**
 * ChatGPT MCP test page.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatGPT MCP test page class.
 */
class Abilities_Bridge_ChatGPT_MCP_Test_Page {

	/**
	 * Page slug.
	 */
	const PAGE_SLUG = 'abilities-bridge-chatgpt-mcp-test';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_run_chatgpt_mcp_tests', array( $this, 'ajax_run_tests' ) );
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'abilities-bridge',
			__( 'ChatGPT MCP Test', 'abilities-bridge' ),
			__( 'ChatGPT MCP Test', 'abilities-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'abilities-bridge_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$script_version = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/chatgpt-mcp-test.js' );

		wp_enqueue_script(
			'abilities-bridge-chatgpt-mcp-test',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/chatgpt-mcp-test.js',
			array(),
			$script_version ? $script_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-chatgpt-mcp-test',
			'abilitiesBridgeChatGPTMcpTest',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_chatgpt_mcp_test' ),
			)
		);
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'abilities-bridge' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OpenAI ChatGPT MCP Test', 'abilities-bridge' ); ?></h1>
			<p><?php esc_html_e( 'Run local readiness checks for the direct ChatGPT MCP flow before creating the custom ChatGPT MCP app. This tests WordPress-side MCP metadata, local MCP requests, OAuth client configuration, and endpoint readiness.', 'abilities-bridge' ); ?></p>

			<div class="card" style="max-width: 1100px; padding: 20px; margin-top: 20px;">
				<p><strong><?php esc_html_e( 'What this verifies', 'abilities-bridge' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'Direct WordPress ChatGPT MCP endpoint readiness', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Separate ChatGPT MCP client credentials and profile metadata', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'OAuth authorization-server and protected-resource metadata', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'PKCE and grant-type readiness for ChatGPT developer mode', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Local MCP initialize and tools/list responses from WordPress', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Safe read-only memory smoke test when memory is enabled and visible', 'abilities-bridge' ); ?></li>
				</ul>
				<p>
					<button type="button" class="button button-primary button-hero" id="abilities-bridge-chatgpt-mcp-run-tests"><?php esc_html_e( 'Run ChatGPT MCP Tests', 'abilities-bridge' ); ?></button>
				</p>
			</div>

			<div id="abilities-bridge-chatgpt-mcp-test-status" style="margin-top: 20px;"></div>
			<div id="abilities-bridge-chatgpt-mcp-test-results" style="margin-top: 20px;"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: run tests.
	 *
	 * @return void
	 */
	public function ajax_run_tests() {
		check_ajax_referer( 'abilities_bridge_chatgpt_mcp_test', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'abilities-bridge' ) ), 403 );
		}

		wp_send_json_success( $this->run_diagnostics() );
	}

	/**
	 * Run diagnostics.
	 *
	 * @return array
	 */
	private function run_diagnostics() {
		$results = array(
			'passed' => 0,
			'failed' => 0,
			'warned' => 0,
			'checks' => array(),
			'debug'  => array(),
		);

		$mcp_endpoint                 = rest_url( 'abilities-bridge-mcp/v1/mcp' );
		$authorization_server_url     = home_url( '/.well-known/oauth-authorization-server?profile=' . rawurlencode( Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT ) );
		$protected_resource_url       = home_url( '/.well-known/oauth-protected-resource?profile=' . rawurlencode( Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT ) );
		$mcp_discovery_url            = home_url( '/.well-known/mcp?profile=' . rawurlencode( Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT ) );
		$clients                      = Abilities_Bridge_OAuth_Client_Manager::get_user_clients( get_current_user_id(), Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT );

		$this->add_check( $results, 'Setup Complete', Abilities_Bridge_Welcome_Wizard::is_setup_complete(), __( 'Welcome wizard is complete.', 'abilities-bridge' ), __( 'Complete the welcome wizard before using MCP features.', 'abilities-bridge' ) );
		$this->add_check( $results, 'OpenAI API Key', Abilities_Bridge_AI_Provider::has_api_key( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI ), __( 'OpenAI API key is configured.', 'abilities-bridge' ), __( 'OpenAI API key is not configured.', 'abilities-bridge' ) );
		$this->add_check( $results, 'Direct ChatGPT MCP Endpoint', ! empty( $mcp_endpoint ) && 0 === strpos( $mcp_endpoint, 'https://' ), sprintf( __( 'Direct WordPress MCP endpoint is ready: %s', 'abilities-bridge' ), $mcp_endpoint ), __( 'The direct WordPress MCP endpoint must be HTTPS for ChatGPT developer mode.', 'abilities-bridge' ), false );
		$this->add_check( $results, 'ChatGPT OAuth Clients', ! empty( $clients ), sprintf( __( '%d ChatGPT MCP client credential set(s) found for the current administrator.', 'abilities-bridge' ), count( $clients ) ), __( 'No ChatGPT MCP client credentials found yet. Generate one in the OpenAI ChatGPT MCP tab.', 'abilities-bridge' ), true );

		$results['debug']['chatgpt_clients']             = $clients;
		$results['debug']['derived_endpoint']            = $mcp_endpoint;
		$results['debug']['authorization_server_url']    = $authorization_server_url;
		$results['debug']['protected_resource_url']      = $protected_resource_url;
		$results['debug']['mcp_discovery_url']           = $mcp_discovery_url;

		$results['debug']['authorization_server_metadata'] = $this->invoke_discovery_handler( 'handle_metadata_request', Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT );
		$results['debug']['protected_resource_metadata']   = $this->invoke_discovery_handler( 'handle_protected_resource_request', Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT );
		$results['debug']['mcp_discovery']                 = $this->invoke_discovery_handler( 'handle_mcp_discovery', Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT );
		$results['debug']['initialize_response']           = $this->run_local_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'chatgpt-mcp-initialize',
				'method'  => 'initialize',
				'params'  => array(),
			)
		);
		$results['debug']['tools_list_response']           = $this->run_local_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'chatgpt-mcp-tools-list',
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);

		$auth_metadata = $results['debug']['authorization_server_metadata'];
		$protected     = $results['debug']['protected_resource_metadata'];
		$discovery     = $results['debug']['mcp_discovery'];
		$initialize    = $results['debug']['initialize_response'];
		$tools_list    = $results['debug']['tools_list_response'];

		$this->add_check( $results, 'Authorization Server Metadata', isset( $auth_metadata['authorization_endpoint'], $auth_metadata['token_endpoint'], $auth_metadata['resource'] ), __( 'Authorization-server metadata includes authorization, token, and resource endpoints.', 'abilities-bridge' ), __( 'Authorization-server metadata is missing required fields.', 'abilities-bridge' ) );
		$this->add_check( $results, 'Protected Resource Metadata', isset( $protected['resource'], $protected['authorization_servers'] ) && ! empty( $protected['authorization_servers'] ), __( 'Protected-resource metadata is available.', 'abilities-bridge' ), __( 'Protected-resource metadata is missing required fields.', 'abilities-bridge' ) );
		$this->add_check( $results, 'MCP Discovery Metadata', isset( $discovery['experimental']['oauth']['protectedResource'] ), __( 'MCP discovery advertises OAuth and protected-resource metadata.', 'abilities-bridge' ), __( 'MCP discovery metadata is missing OAuth protected-resource details.', 'abilities-bridge' ) );
		$this->add_check( $results, 'PKCE Metadata', ! empty( $auth_metadata['code_challenge_methods_supported'] ) && in_array( 'S256', $auth_metadata['code_challenge_methods_supported'], true ) && ! empty( $discovery['experimental']['oauth']['pkceRequired'] ), __( 'OAuth metadata advertises PKCE and supports S256.', 'abilities-bridge' ), __( 'OAuth metadata does not clearly advertise PKCE support.', 'abilities-bridge' ) );
		$this->add_check( $results, 'No Client Credentials Grant Advertised', empty( $auth_metadata['grant_types_supported'] ) || ! in_array( 'client_credentials', $auth_metadata['grant_types_supported'], true ), __( 'Authorization metadata does not advertise client_credentials.', 'abilities-bridge' ), __( 'Authorization metadata still advertises client_credentials, which should not be exposed for the direct ChatGPT flow.', 'abilities-bridge' ) );
		$this->add_check( $results, 'Local MCP Initialize', isset( $initialize['result']['protocolVersion'] ), __( 'Local MCP initialize request returned a valid protocol response.', 'abilities-bridge' ), __( 'Local MCP initialize request did not return a valid result.', 'abilities-bridge' ) );
		$this->add_check( $results, 'Initialize Grant Types', isset( $initialize['result']['capabilities']['experimental']['oauth']['grantTypes'] ) && in_array( 'authorization_code', $initialize['result']['capabilities']['experimental']['oauth']['grantTypes'], true ) && ! in_array( 'client_credentials', $initialize['result']['capabilities']['experimental']['oauth']['grantTypes'], true ), __( 'Initialize response advertises authorization_code/refresh_token without client_credentials.', 'abilities-bridge' ), __( 'Initialize response grant types are not aligned with the direct ChatGPT OAuth flow.', 'abilities-bridge' ) );
		$this->add_check( $results, 'Local MCP Tools List', isset( $tools_list['result']['tools'] ) && is_array( $tools_list['result']['tools'] ), __( 'Local MCP tools/list returned a valid tools array.', 'abilities-bridge' ), __( 'Local MCP tools/list did not return a valid tools array.', 'abilities-bridge' ) );

		$tools = isset( $tools_list['result']['tools'] ) && is_array( $tools_list['result']['tools'] ) ? $tools_list['result']['tools'] : array();
		$results['debug']['tool_names'] = wp_list_pluck( $tools, 'name' );

		$memory_tool_visible = in_array( 'memory', $results['debug']['tool_names'], true );
		if ( $memory_tool_visible ) {
			$results['debug']['memory_smoke_test'] = $this->run_local_mcp_request(
				array(
					'jsonrpc' => '2.0',
					'id'      => 'chatgpt-mcp-memory-test',
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'memory',
						'arguments' => array(
							'command'    => 'view',
							'path'       => '/memories',
							'view_range' => array( 0, 10 ),
						),
					),
				)
			);

			$memory_test_ok = ! isset( $results['debug']['memory_smoke_test']['error'] );
			$this->add_check( $results, 'Memory Smoke Test', $memory_test_ok, __( 'Safe read-only memory smoke test completed.', 'abilities-bridge' ), __( 'Memory smoke test returned an error.', 'abilities-bridge' ), true );
		}

		$results['debug']['chatgpt_profile'] = Abilities_Bridge_OAuth_Client_Manager::PROFILE_CHATGPT;
		$results['status']                   = 0 === $results['failed'] ? ( $results['warned'] > 0 ? 'warning' : 'success' ) : 'error';

		return $results;
	}

	/**
	 * Add a check result.
	 *
	 * @param array  $results Result container.
	 * @param string $label Check label.
	 * @param bool   $passed Passed state.
	 * @param string $success Success message.
	 * @param string $failure Failure message.
	 * @param bool   $warning Whether a failed result should be a warning instead of a failure.
	 * @return void
	 */
	private function add_check( array &$results, $label, $passed, $success, $failure, $warning = false ) {
		$status = $passed ? 'pass' : ( $warning ? 'warn' : 'fail' );
		$results['checks'][] = array(
			'label'   => $label,
			'status'  => $status,
			'message' => $passed ? $success : $failure,
		);

		if ( 'pass' === $status ) {
			++$results['passed'];
		} elseif ( 'warn' === $status ) {
			++$results['warned'];
		} else {
			++$results['failed'];
		}
	}

	/**
	 * Invoke discovery handler with a temporary profile context.
	 *
	 * @param string $method Handler method.
	 * @param string $profile MCP profile.
	 * @return array
	 */
	private function invoke_discovery_handler( $method, $profile ) {
		$previous_profile = isset( $_GET['profile'] ) ? wp_unslash( $_GET['profile'] ) : null;
		$_GET['profile']  = $profile;

		try {
			$response = call_user_func( array( 'Abilities_Bridge_OAuth_Discovery_Handler', $method ) );
			if ( $response instanceof WP_REST_Response ) {
				return $response->get_data();
			}

			return is_array( $response ) ? $response : array( 'raw' => $response );
		} finally {
			if ( null === $previous_profile ) {
				unset( $_GET['profile'] );
			} else {
				$_GET['profile'] = $previous_profile;
			}
		}
	}

	/**
	 * Run a local MCP request as the current admin user.
	 *
	 * @param array $payload JSON-RPC payload.
	 * @return array
	 */
	private function run_local_mcp_request( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/abilities-bridge-mcp/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$mcp_server = new Abilities_Bridge_MCP_Server();
		$response   = $mcp_server->handle_request( $request );

		if ( $response instanceof WP_REST_Response ) {
			return $response->get_data();
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'error'   => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);
		}

		return array( 'raw' => $response );
	}
}