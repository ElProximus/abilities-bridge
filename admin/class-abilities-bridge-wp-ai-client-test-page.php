<?php
/**
 * WP AI Client integration test page.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP AI Client test page class.
 */
class Abilities_Bridge_WP_AI_Client_Test_Page {

	/**
	 * Page slug.
	 */
	const PAGE_SLUG = 'abilities-bridge-wp-ai-client-test';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_abilities_bridge_run_wp_ai_client_tests', array( $this, 'ajax_run_tests' ) );
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'abilities-bridge',
			__( 'WP AI Client Test', 'abilities-bridge' ),
			__( 'WP AI Client Test', 'abilities-bridge' ),
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

		$script_version = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/wp-ai-client-test.js' );

		wp_enqueue_script(
			'abilities-bridge-wp-ai-client-test',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/wp-ai-client-test.js',
			array(),
			$script_version ? $script_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-wp-ai-client-test',
			'abilitiesBridgeWpAiClientTest',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_wp_ai_client_test' ),
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
			<h1><?php esc_html_e( 'WP AI Client Integration Test', 'abilities-bridge' ); ?></h1>
			<p><?php esc_html_e( 'Run diagnostic checks to verify the WP AI Client credential integration. This tests detection, credential storage formats, key resolution, and fallback behavior for each provider.', 'abilities-bridge' ); ?></p>

			<div class="card" style="max-width: 1100px; padding: 20px; margin-top: 20px;">
				<p><strong><?php esc_html_e( 'What this verifies', 'abilities-bridge' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'WP AI Client function detection (wp_ai_client_prompt)', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Abilities Bridge "use WP AI Client" setting status', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'WordPress 7.0 core Connectors credential storage (individual options)', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Standalone WP AI Client plugin credential storage (array option)', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Anthropic key resolution — which source provides the active key', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'OpenAI key resolution — which source provides the active key', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Fallback behavior — local keys used when WP AI Client keys are absent', 'abilities-bridge' ); ?></li>
				</ul>
				<p>
					<button type="button" class="button button-primary button-hero" id="abilities-bridge-wp-ai-client-run-tests"><?php esc_html_e( 'Run WP AI Client Tests', 'abilities-bridge' ); ?></button>
				</p>
			</div>

			<div id="abilities-bridge-wp-ai-client-test-status" style="margin-top: 20px;"></div>
			<div id="abilities-bridge-wp-ai-client-test-results" style="margin-top: 20px;"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: run tests.
	 *
	 * @return void
	 */
	public function ajax_run_tests() {
		check_ajax_referer( 'abilities_bridge_wp_ai_client_test', 'nonce' );

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

		// --- 1. WP AI Client Detection ---
		$available = Abilities_Bridge_AI_Provider::is_wp_ai_client_available();
		$this->add_check(
			$results,
			'WP AI Client Detection',
			$available,
			__( 'wp_ai_client_prompt() function is available. WP AI Client or WordPress 7.0+ Connectors detected.', 'abilities-bridge' ),
			__( 'wp_ai_client_prompt() function not found. Install WP AI Client plugin or upgrade to WordPress 7.0+.', 'abilities-bridge' ),
			true
		);
		$results['debug']['wp_ai_client_available'] = $available;

		// --- 2. Setting Status ---
		$setting_enabled = rest_sanitize_boolean( get_option( 'abilities_bridge_use_wp_ai_client', false ) );
		$this->add_check(
			$results,
			'Integration Setting Enabled',
			$setting_enabled,
			__( 'The "Use WP AI Client" option is enabled in Abilities Bridge settings.', 'abilities-bridge' ),
			__( 'The "Use WP AI Client" option is not enabled. Enable it in Settings > General to use shared credentials.', 'abilities-bridge' ),
			true
		);
		$results['debug']['setting_enabled'] = $setting_enabled;

		// --- 3. WP 7.0 Core Anthropic Key ---
		$core_anthropic_key    = $this->get_core_connector_key( 'connectors_ai_anthropic_api_key' );
		$core_anthropic_exists = ! empty( $core_anthropic_key );
		$this->add_check(
			$results,
			'WP 7.0 Core Anthropic Key',
			$core_anthropic_exists,
			__( 'Anthropic key found in connectors_ai_anthropic_api_key option.', 'abilities-bridge' ),
			__( 'No Anthropic key found in connectors_ai_anthropic_api_key option.', 'abilities-bridge' ),
			true
		);
		$results['debug']['core_anthropic_key'] = $core_anthropic_exists ? $this->mask_key( $core_anthropic_key ) : '(empty)';

		// --- 4. WP 7.0 Core OpenAI Key ---
		$core_openai_key    = $this->get_core_connector_key( 'connectors_ai_openai_api_key' );
		$core_openai_exists = ! empty( $core_openai_key );
		$this->add_check(
			$results,
			'WP 7.0 Core OpenAI Key',
			$core_openai_exists,
			__( 'OpenAI key found in connectors_ai_openai_api_key option.', 'abilities-bridge' ),
			__( 'No OpenAI key found in connectors_ai_openai_api_key option.', 'abilities-bridge' ),
			true
		);
		$results['debug']['core_openai_key'] = $core_openai_exists ? $this->mask_key( $core_openai_key ) : '(empty)';

		// --- 5. Standalone Plugin Credentials ---
		$plugin_creds = get_option( 'wp_ai_client_provider_credentials', array() );
		$plugin_creds_valid = is_array( $plugin_creds ) && ! empty( $plugin_creds );
		$this->add_check(
			$results,
			'Standalone Plugin Credentials Option',
			$plugin_creds_valid,
			sprintf(
				/* translators: %s: comma-separated list of provider keys */
				__( 'wp_ai_client_provider_credentials option found with keys: %s', 'abilities-bridge' ),
				implode( ', ', array_keys( $plugin_creds ) )
			),
			__( 'wp_ai_client_provider_credentials option is empty or not set. This is normal if using WordPress 7.0 core Connectors.', 'abilities-bridge' ),
			true
		);

		$plugin_anthropic = is_array( $plugin_creds ) && ! empty( $plugin_creds['anthropic'] ) ? $plugin_creds['anthropic'] : '';
		$plugin_openai    = is_array( $plugin_creds ) && ! empty( $plugin_creds['openai'] ) ? $plugin_creds['openai'] : '';
		$results['debug']['plugin_creds_anthropic'] = ! empty( $plugin_anthropic ) ? $this->mask_key( $plugin_anthropic ) : '(empty)';
		$results['debug']['plugin_creds_openai']    = ! empty( $plugin_openai ) ? $this->mask_key( $plugin_openai ) : '(empty)';

		// --- 6. get_wp_ai_client_key() Resolution ---
		$resolved_anthropic = Abilities_Bridge_AI_Provider::get_wp_ai_client_key( Abilities_Bridge_AI_Provider::PROVIDER_ANTHROPIC );
		$resolved_openai    = Abilities_Bridge_AI_Provider::get_wp_ai_client_key( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI );

		$this->add_check(
			$results,
			'WP AI Client Anthropic Key Resolution',
			! empty( $resolved_anthropic ),
			__( 'get_wp_ai_client_key() resolved an Anthropic key from WP AI Client storage.', 'abilities-bridge' ),
			__( 'get_wp_ai_client_key() could not find an Anthropic key in any WP AI Client storage location.', 'abilities-bridge' ),
			true
		);
		$results['debug']['resolved_wp_ai_client_anthropic'] = ! empty( $resolved_anthropic ) ? $this->mask_key( $resolved_anthropic ) : '(empty)';

		$anthropic_source = '(none)';
		if ( ! empty( $resolved_anthropic ) ) {
			$anthropic_source = $core_anthropic_exists ? 'WP 7.0 core option' : ( ! empty( $plugin_anthropic ) ? 'standalone plugin option' : 'unknown' );
		}
		$results['debug']['resolved_wp_ai_client_anthropic_source'] = $anthropic_source;

		$this->add_check(
			$results,
			'WP AI Client OpenAI Key Resolution',
			! empty( $resolved_openai ),
			__( 'get_wp_ai_client_key() resolved an OpenAI key from WP AI Client storage.', 'abilities-bridge' ),
			__( 'get_wp_ai_client_key() could not find an OpenAI key in any WP AI Client storage location.', 'abilities-bridge' ),
			true
		);
		$results['debug']['resolved_wp_ai_client_openai'] = ! empty( $resolved_openai ) ? $this->mask_key( $resolved_openai ) : '(empty)';

		$openai_source = '(none)';
		if ( ! empty( $resolved_openai ) ) {
			$openai_source = $core_openai_exists ? 'WP 7.0 core option' : ( ! empty( $plugin_openai ) ? 'standalone plugin option' : 'unknown' );
		}
		$results['debug']['resolved_wp_ai_client_openai_source'] = $openai_source;

		// --- 7. Local Keys ---
		$local_anthropic = get_option( 'abilities_bridge_api_key', '' );
		$local_openai    = get_option( 'abilities_bridge_openai_api_key', '' );

		$this->add_check(
			$results,
			'Local Anthropic Key (Fallback)',
			! empty( $local_anthropic ),
			__( 'Local Anthropic API key is configured in Abilities Bridge settings.', 'abilities-bridge' ),
			__( 'No local Anthropic API key configured. If WP AI Client key is also absent, Anthropic calls will fail.', 'abilities-bridge' ),
			true
		);
		$results['debug']['local_anthropic_key'] = ! empty( $local_anthropic ) ? $this->mask_key( $local_anthropic ) : '(empty)';

		$this->add_check(
			$results,
			'Local OpenAI Key (Fallback)',
			! empty( $local_openai ),
			__( 'Local OpenAI API key is configured in Abilities Bridge settings.', 'abilities-bridge' ),
			__( 'No local OpenAI API key configured. If WP AI Client key is also absent, OpenAI calls will fail.', 'abilities-bridge' ),
			true
		);
		$results['debug']['local_openai_key'] = ! empty( $local_openai ) ? $this->mask_key( $local_openai ) : '(empty)';

		// --- 8. Final Active Key Resolution (get_api_key) ---
		$active_anthropic = Abilities_Bridge_AI_Provider::get_api_key( Abilities_Bridge_AI_Provider::PROVIDER_ANTHROPIC );
		$active_openai    = Abilities_Bridge_AI_Provider::get_api_key( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI );

		$this->add_check(
			$results,
			'Active Anthropic Key',
			! empty( $active_anthropic ),
			__( 'get_api_key() resolved a usable Anthropic key.', 'abilities-bridge' ),
			__( 'get_api_key() returned empty for Anthropic. No key available from any source.', 'abilities-bridge' )
		);

		// Determine where the active key came from.
		$active_anthropic_source = '(none)';
		if ( ! empty( $active_anthropic ) ) {
			if ( $setting_enabled && ! empty( $resolved_anthropic ) && $active_anthropic === $resolved_anthropic ) {
				$active_anthropic_source = 'WP AI Client (' . $anthropic_source . ')';
			} else {
				$active_anthropic_source = 'local Abilities Bridge option';
			}
		}
		$results['debug']['active_anthropic_key']    = ! empty( $active_anthropic ) ? $this->mask_key( $active_anthropic ) : '(empty)';
		$results['debug']['active_anthropic_source'] = $active_anthropic_source;

		$this->add_check(
			$results,
			'Active OpenAI Key',
			! empty( $active_openai ),
			__( 'get_api_key() resolved a usable OpenAI key.', 'abilities-bridge' ),
			__( 'get_api_key() returned empty for OpenAI. No key available from any source.', 'abilities-bridge' )
		);

		$active_openai_source = '(none)';
		if ( ! empty( $active_openai ) ) {
			if ( $setting_enabled && ! empty( $resolved_openai ) && $active_openai === $resolved_openai ) {
				$active_openai_source = 'WP AI Client (' . $openai_source . ')';
			} else {
				$active_openai_source = 'local Abilities Bridge option';
			}
		}
		$results['debug']['active_openai_key']    = ! empty( $active_openai ) ? $this->mask_key( $active_openai ) : '(empty)';
		$results['debug']['active_openai_source'] = $active_openai_source;

		// --- 9. has_api_key() Consistency ---
		$has_anthropic = Abilities_Bridge_AI_Provider::has_api_key( Abilities_Bridge_AI_Provider::PROVIDER_ANTHROPIC );
		$has_openai    = Abilities_Bridge_AI_Provider::has_api_key( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI );

		$this->add_check(
			$results,
			'has_api_key() Anthropic Consistency',
			$has_anthropic === ! empty( $active_anthropic ),
			__( 'has_api_key() returns consistent result with get_api_key() for Anthropic.', 'abilities-bridge' ),
			__( 'has_api_key() result does not match get_api_key() for Anthropic. This indicates a bug.', 'abilities-bridge' )
		);

		$this->add_check(
			$results,
			'has_api_key() OpenAI Consistency',
			$has_openai === ! empty( $active_openai ),
			__( 'has_api_key() returns consistent result with get_api_key() for OpenAI.', 'abilities-bridge' ),
			__( 'has_api_key() result does not match get_api_key() for OpenAI. This indicates a bug.', 'abilities-bridge' )
		);

		// --- 10. Fallback Scenario ---
		if ( $setting_enabled ) {
			// Check mixed scenario: WP AI Client key for one provider, local for the other.
			$anthropic_from_wp = ! empty( $resolved_anthropic );
			$openai_from_wp    = ! empty( $resolved_openai );

			if ( $anthropic_from_wp && ! $openai_from_wp && ! empty( $active_openai ) ) {
				$this->add_check(
					$results,
					'Mixed Source Fallback',
					true,
					__( 'Anthropic key from WP AI Client, OpenAI key falling back to local. Mixed-source resolution working correctly.', 'abilities-bridge' ),
					''
				);
			} elseif ( ! $anthropic_from_wp && $openai_from_wp && ! empty( $active_anthropic ) ) {
				$this->add_check(
					$results,
					'Mixed Source Fallback',
					true,
					__( 'OpenAI key from WP AI Client, Anthropic key falling back to local. Mixed-source resolution working correctly.', 'abilities-bridge' ),
					''
				);
			} elseif ( ! $anthropic_from_wp && ! $openai_from_wp ) {
				$this->add_check(
					$results,
					'Full Fallback',
					! empty( $active_anthropic ) || ! empty( $active_openai ),
					__( 'No keys found in WP AI Client. Falling back to local keys for both providers.', 'abilities-bridge' ),
					__( 'No keys found in WP AI Client and no local keys configured either. No API calls will succeed.', 'abilities-bridge' ),
					true
				);
			}
		}

		// Overall status.
		$results['status'] = 0 === $results['failed'] ? ( $results['warned'] > 0 ? 'warning' : 'success' ) : 'error';

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
		$status            = $passed ? 'pass' : ( $warning ? 'warn' : 'fail' );
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
	 * Read a WordPress Connectors API key option, bypassing the masking filter.
	 *
	 * @param string $option_name Option name (e.g. connectors_ai_anthropic_api_key).
	 * @return string Raw key or empty string.
	 */
	private function get_core_connector_key( $option_name ) {
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

		return $key;
	}

	/**
	 * Mask an API key for safe display.
	 *
	 * Shows the first 6 and last 4 characters.
	 *
	 * @param string $key API key.
	 * @return string Masked key.
	 */
	private function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 10 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 6 ) . str_repeat( '*', $len - 10 ) . substr( $key, -4 );
	}
}
