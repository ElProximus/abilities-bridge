<?php
/**
 * Settings page class
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Page class.
 *
 * Handles the plugin settings page.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Settings_Page {

	/**
	 * Get the current admin page parameter for routing checks.
	 *
	 * This is used internally for routing logic only - it does not require
	 * nonce verification because it only checks the current page name
	 * (a WordPress-controlled parameter) for routing purposes.
	 *
	 * @return string The current page parameter value, or empty string.
	 */
	private function get_current_page() {
		$value = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ! empty( $value ) ? sanitize_key( $value ) : '';
	}

	/**
	 * Verify the settings page nonce for URL parameters.
	 *
	 * Returns true if nonce is valid, false if no nonce present.
	 * Dies with error if nonce is present but invalid.
	 *
	 * @return bool True if nonce is valid, false if no nonce present.
	 */
	private function verify_settings_nonce() {
		$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// No nonce present = no URL parameters to process.
		if ( empty( $nonce ) ) {
			return false;
		}

		// Nonce present - verify it.
		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_settings_nav' ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Please use the navigation links provided.', 'abilities-bridge' ),
				esc_html__( 'Security Check Failed', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		return true;
	}

	/**
	 * Initialize the settings page
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_mcp_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue CSS and JavaScript for settings page
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only load on settings page.
		// Hook format: {parent_slug}_page_{menu_slug}.
		if ( false === strpos( $hook, 'abilities-bridge-settings' ) ) {
			return;
		}

		wp_enqueue_style(
			'abilities-bridge-settings-page',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/settings-page.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-settings-page',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/settings-page.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-settings-page',
			'abilitiesBridgeSettings',
			array(
				'nonce'               => wp_create_nonce( 'abilities_bridge_settings' ),
				'memoryConsentGiven'  => (bool) get_option( 'abilities_bridge_memory_consent', false ),
				'i18n'                => array(
					'copied'               => __( 'Copied!', 'abilities-bridge' ),
					'copyFailed'           => __( 'Failed to copy to clipboard', 'abilities-bridge' ),
					'restorePromptConfirm' => __( 'Are you sure you want to restore the default system prompt? Your customizations will be lost.', 'abilities-bridge' ),
				),
				'defaultSystemPrompt' => Abilities_Bridge_Claude_API::get_default_system_prompt(),
			)
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'abilities_bridge_settings',
			'abilities_bridge_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'abilities_bridge_settings',
			'abilities_bridge_enable_memory',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'abilities_bridge_settings',
			'abilities_bridge_memory_consent',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		add_settings_section(
			'abilities_bridge_api_section',
			__( 'Anthropic API Configuration', 'abilities-bridge' ),
			array( $this, 'render_section' ),
			'abilities-bridge-settings'
		);

		add_settings_field(
			'abilities_bridge_api_key',
			__( 'API Key', 'abilities-bridge' ),
			array( $this, 'render_api_key_field' ),
			'abilities-bridge-settings',
			'abilities_bridge_api_section'
		);

		// System Prompt setting.
		register_setting(
			'abilities_bridge_settings',
			'abilities_bridge_system_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
				'default'           => '',
			)
		);

		add_settings_section(
			'abilities_bridge_prompt_section',
			__( 'System Prompt', 'abilities-bridge' ),
			array( $this, 'render_prompt_section' ),
			'abilities-bridge-settings'
		);

		add_settings_field(
			'abilities_bridge_system_prompt',
			__( 'Claude System Prompt', 'abilities-bridge' ),
			array( $this, 'render_system_prompt_field' ),
			'abilities-bridge-settings',
			'abilities_bridge_prompt_section'
		);

		// Memory settings (for separate Memory tab).
		// Memory tool settings.
		register_setting(
			'abilities_bridge_builtin_tools',
			'abilities_bridge_enable_memory',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'abilities_bridge_builtin_tools',
			'abilities_bridge_memory_consent',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'validate_memory_consent' ),
				'default'           => false,
			)
		);
	}

	/**
	 * Validate memory consent
	 *
	 * Ensures that if memory is being enabled, consent must be given.
	 *
	 * @param bool $value The consent value being saved.
	 * @return bool The validated consent value.
	 */
	public function validate_memory_consent( $value ) {
		// Sanitize the boolean value first.
		$value = rest_sanitize_boolean( $value );

		// Check if memory is being enabled (from POST data).
		// Settings API sanitize callbacks are called after nonce verification by options.php.
		$enable_memory_raw = filter_input( INPUT_POST, 'abilities_bridge_enable_memory', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$enable_memory     = rest_sanitize_boolean( $enable_memory_raw );

		// If memory is enabled but consent is not given, show error and prevent enabling.
		if ( $enable_memory && ! $value ) {
			add_settings_error(
				'abilities_bridge_memory_consent',
				'memory_consent_required',
				__( 'You must check the consent box to enable the Memory Tool.', 'abilities-bridge' ),
				'error'
			);

			// Also prevent memory from being enabled by resetting it.
			update_option( 'abilities_bridge_enable_memory', false );

			// Return false to prevent consent from being saved as true.
			return false;
		}

		return $value;
	}

	/**
	 * Render section description
	 */
	public function render_section() {
		?>
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: %s: Anthropic console URL */
					__( 'Enter your Anthropic API key. You can get one from the <a href="%s" target="_blank">Anthropic Console</a>.', 'abilities-bridge' ),
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
						),
					)
				),
				esc_url( 'https://console.anthropic.com/' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render API key field
	 */
	public function render_api_key_field() {
		$api_key = get_option( 'abilities_bridge_api_key', '' );
		?>
		<input
			type="password"
			name="abilities_bridge_api_key"
			id="abilities_bridge_api_key"
			value="<?php echo esc_attr( $api_key ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description">
			<?php esc_html_e( 'Your Anthropic API key (starts with "sk-ant-"). This will be stored securely in your database.', 'abilities-bridge' ); ?>
		</p>

		<!-- API Key Consent Checkboxes -->
		<div id="api-key-consent-box" style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 4px; display: none;">
			<h4 style="margin-top: 0; color: #856404;">⚠️ <?php esc_html_e( 'API Key Consent Required', 'abilities-bridge' ); ?></h4>
			<p style="margin-bottom: 15px;"><strong><?php esc_html_e( 'Before saving your API key, please confirm:', 'abilities-bridge' ); ?></strong></p>

			<label style="display: block; margin-bottom: 10px; cursor: pointer;">
				<input type="checkbox" id="consent-api-permissions" class="api-key-consent-checkbox" value="1">
				<?php esc_html_e( 'I understand this plugin provides AI access through memory and abilities I control, and I am responsible for configuring appropriate permission levels for my security needs. I assume all responsibility and risk for enabling AI access to my site.', 'abilities-bridge' ); ?>
			</label>

			<label style="display: block; margin-bottom: 0; cursor: pointer;">
				<input type="checkbox" id="consent-api-billing" class="api-key-consent-checkbox" value="1">
				<?php esc_html_e( 'I understand API costs and Claude Account subscription costs are my responsibility and billed by Anthropic directly. The makers of this plugin are NOT responsible for API usage costs, Claude Account subscription fees, or billing.', 'abilities-bridge' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Render system prompt section description
	 */
	public function render_prompt_section() {
		?>
		<p>
			<?php esc_html_e( 'Customize the system prompt that defines how Claude behaves and responds. This prompt is sent with every conversation.', 'abilities-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render system prompt textarea field
	 */
	public function render_system_prompt_field() {
		$system_prompt  = get_option( 'abilities_bridge_system_prompt', '' );
		$default_prompt = Abilities_Bridge_Claude_API::get_default_system_prompt();

		// Use default if empty.
		if ( empty( $system_prompt ) ) {
			$system_prompt = $default_prompt;
		}
		?>
		<textarea
			name="abilities_bridge_system_prompt"
			id="abilities_bridge_system_prompt"
			class="large-text code abilities-bridge-system-prompt"
			rows="20"
		><?php echo esc_textarea( $system_prompt ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'This prompt defines Claude\'s behavior, available tools, and how it should interact with your WordPress site.', 'abilities-bridge' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="abilities-bridge-restore-default-prompt">
				<?php esc_html_e( 'Restore Default Prompt', 'abilities-bridge' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Render memory section description
	 */
	public function render_memory_section() {
		?>
		<p>
			<?php esc_html_e( 'The Memory Tool allows Claude to store persistent notes and knowledge across conversations. Data is stored securely in the WordPress database.', 'abilities-bridge' ); ?>
		</p>
		<p style="color: #d63638;">
			<strong><?php esc_html_e( 'Important:', 'abilities-bridge' ); ?></strong>
			<?php esc_html_e( 'Enabling this feature allows the AI to store memory data in the database. These memories help the AI remember site context and maintain conversation history.', 'abilities-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render memory enable field
	 */
	public function render_memory_enable_field() {
		$enabled = get_option( 'abilities_bridge_enable_memory', false );
		$consent = get_option( 'abilities_bridge_memory_consent', false );
		?>
		<label for="abilities_bridge_enable_memory">
			<input
				type="checkbox"
				name="abilities_bridge_enable_memory"
				id="abilities_bridge_enable_memory"
				value="1"
				<?php checked( $enabled, true ); ?>
			/>
			<?php esc_html_e( 'Enable persistent memory across conversations', 'abilities-bridge' ); ?>
		</label>

		<div id="abilities_bridge_memory_consent_container" style="margin-top: 15px; <?php echo esc_attr( $enabled && ! $consent ? '' : 'display: none;' ); ?>">
			<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 10px 0;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Memory Tool Consent Required', 'abilities-bridge' ); ?></h4>
				<p><strong><?php esc_html_e( 'Please read and understand:', 'abilities-bridge' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'The Memory Tool allows AI to create, modify, and delete memory records', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Memory data is stored securely in the WordPress database', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Memory content is limited to 1MB each, 50MB total', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Memory data helps AI remember site context across sessions', 'abilities-bridge' ); ?></li>
				</ul>
				<label for="abilities_bridge_memory_consent" style="display: block; margin-top: 15px;">
					<input
						type="checkbox"
						name="abilities_bridge_memory_consent"
						id="abilities_bridge_memory_consent"
						value="1"
						<?php checked( $consent, true ); ?>
					/>
					<strong><?php esc_html_e( 'I understand and accept that enabling the Memory Tool allows the AI to store memories in the WordPress database', 'abilities-bridge' ); ?></strong>
				</label>
			</div>
		</div>

		<p class="description">
			<?php
			printf(
				/* translators: %s: table name */
				esc_html__( 'Memory storage: %s', 'abilities-bridge' ),
				'<code>Database (abilities_bridge_memories table)</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render Memory section
	 */
	public function render_builtin_tools_section() {
		// Get current settings.
		$enable_memory  = get_option( 'abilities_bridge_enable_memory', false );
		$memory_consent = get_option( 'abilities_bridge_memory_consent', false );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Memory', 'abilities-bridge' ); ?></h2>
			<p>
				<?php esc_html_e( 'Enable persistent memory storage for Claude to remember context across conversations.', 'abilities-bridge' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'abilities_bridge_builtin_tools' ); ?>

				<table class="widefat" style="margin-top: 20px;">
					<thead>
						<tr>
							<th style="width: 200px;"><?php esc_html_e( 'Tool', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Description', 'abilities-bridge' ); ?></th>
							<th style="width: 100px; text-align: center;"><?php esc_html_e( 'Enabled', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<!-- memory -->
						<tr>
							<td><strong><code>memory</code></strong></td>
							<td>
								<?php esc_html_e( 'Persistent storage for maintaining context across conversations. Can create, read, update, and delete memory entries in the database.', 'abilities-bridge' ); ?>
								<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
									<strong style="color: #856404;"><?php esc_html_e( 'Important:', 'abilities-bridge' ); ?></strong>
									<?php esc_html_e( 'This tool allows memory data storage. Data is stored in the database and limited to 1MB each, 50MB total.', 'abilities-bridge' ); ?>
								</div>
							</td>
							<td style="text-align: center;">
								<input
									type="checkbox"
									name="abilities_bridge_enable_memory"
									id="abilities_bridge_enable_memory"
									value="1"
									<?php checked( $enable_memory, true ); ?>
								/>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Memory consent dialog (only shows when memory is enabled AND consent not yet given) -->
				<div id="abilities_bridge_memory_consent_container" style="margin-top: 20px; <?php echo ( $enable_memory && ! $memory_consent ) ? '' : 'display: none;'; ?>">
					<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px;">
						<h3 style="margin-top: 0;"><?php esc_html_e( 'Memory Tool Consent Required', 'abilities-bridge' ); ?></h3>
						<p><strong><?php esc_html_e( 'Please read and understand:', 'abilities-bridge' ); ?></strong></p>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><?php esc_html_e( 'The Memory Tool allows AI to create, modify, and delete memory records', 'abilities-bridge' ); ?></li>
							<li><?php esc_html_e( 'Memory data is stored securely in the WordPress database', 'abilities-bridge' ); ?></li>
							<li><?php esc_html_e( 'Memory content is limited to 1MB each, 50MB total', 'abilities-bridge' ); ?></li>
							<li><?php esc_html_e( 'Memory data helps AI remember site context across sessions', 'abilities-bridge' ); ?></li>
						</ul>
						<label for="abilities_bridge_memory_consent" style="display: block; margin-top: 15px;">
							<input
								type="checkbox"
								name="abilities_bridge_memory_consent"
								id="abilities_bridge_memory_consent"
								value="1"
								<?php checked( $memory_consent, true ); ?>
							/>
							<strong><?php esc_html_e( 'I understand and accept that enabling the Memory Tool allows the AI to store memories in the WordPress database', 'abilities-bridge' ); ?></strong>
						</label>
					</div>
				</div>

				<?php submit_button( __( 'Save Tool Settings', 'abilities-bridge' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle MCP-related POST actions
	 */
	public function handle_mcp_actions() {
		// Only process on settings page (read-only routing check).
		$page = $this->get_current_page();
		if ( 'abilities-bridge-settings' !== $page ) {
			return;
		}

		// Only process POST requests.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		// Verify capability BEFORE processing any POST data.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to perform this action.', 'abilities-bridge' ),
				esc_html__( 'Permission Denied', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		// Check if nonce is present - if not, this isn't an MCP form submission.
		if ( ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		// Handle OAuth credential generation.
		if ( isset( $_POST['generate_oauth'] ) ) {
			// Verify nonce BEFORE processing.
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'abilities_bridge_mcp_generate_oauth' ) ) {
				wp_die(
					esc_html__( 'Security token validation failed. Please refresh the page and try again.', 'abilities-bridge' ),
					esc_html__( 'Security Check Failed', 'abilities-bridge' ),
					array( 'response' => 403 )
				);
			}

			// Check if consent has been given.
			if ( ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
				wp_die( esc_html__( 'You must complete the welcome wizard and provide consent before generating OAuth credentials.', 'abilities-bridge' ) );
			}

			$oauth       = new Abilities_Bridge_MCP_OAuth();
			$credentials = $oauth->generate_credentials();

			// Store in transient to display after redirect.
			set_transient( 'abilities_bridge_new_credentials', $credentials, 60 );

			// Redirect to avoid resubmission (include nonce for credentials-generated flag).
			$redirect_url = add_query_arg( 'credentials-generated', '1', admin_url( 'admin.php?page=abilities-bridge-settings' ) );
			$redirect_url = wp_nonce_url( $redirect_url, 'abilities_bridge_settings_nav' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Handle client revocation.
		if ( isset( $_POST['revoke_client'] ) ) {
			// Verify nonce BEFORE processing.
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'abilities_bridge_mcp_revoke_client' ) ) {
				wp_die(
					esc_html__( 'Security token validation failed. Please refresh the page and try again.', 'abilities-bridge' ),
					esc_html__( 'Security Check Failed', 'abilities-bridge' ),
					array( 'response' => 403 )
				);
			}

			$oauth = new Abilities_Bridge_MCP_OAuth();

			if ( ! isset( $_POST['client_id'] ) ) {
				wp_die( esc_html__( 'Missing client ID', 'abilities-bridge' ) );
			}

			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ) );

			if ( $oauth->revoke_client( $client_id ) ) {
				add_settings_error(
					'abilities_bridge_messages',
					'client_revoked',
					__( 'Client credentials revoked successfully.', 'abilities-bridge' ),
					'success'
				);
			} else {
				add_settings_error(
					'abilities_bridge_messages',
					'client_revoke_failed',
					__( 'Failed to revoke client credentials.', 'abilities-bridge' ),
					'error'
				);
			}

			// Redirect to avoid resubmission.
			wp_safe_redirect( admin_url( 'admin.php?page=abilities-bridge-settings' ) );
			exit;
		}
	}

	/**
	 * Render MCP section description and setup instructions.
	 */
	public function render_mcp_section() {
		// Get MCP endpoint URL.
		$mcp_endpoint = rest_url( 'abilities-bridge-mcp/v1/mcp' );

		// Check for existing OAuth credentials.
		$oauth            = new Abilities_Bridge_MCP_OAuth();
		$existing_clients = $oauth->get_user_clients();

		// Check for newly generated credentials (from transient after redirect).
		// Verify nonce before accessing the credentials-generated URL parameter.
		$generated_client_id     = null;
		$generated_client_secret = null;
		$show_credentials        = false;

		// Only check credentials-generated if nonce is valid.
		if ( $this->verify_settings_nonce() ) {
			$credentials_flag = filter_input( INPUT_GET, 'credentials-generated', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$show_credentials = ! empty( $credentials_flag );
		}

		if ( $show_credentials ) {
			$credentials = get_transient( 'abilities_bridge_new_credentials' );
			if ( $credentials ) {
				$generated_client_id     = $credentials['client_id'];
				$generated_client_secret = $credentials['client_secret'];
				delete_transient( 'abilities_bridge_new_credentials' );
			}
		}

		?>
		<div class="card" style="max-width: 100%; margin-top: 20px;">
			<h2><?php esc_html_e( 'MCP Server Setup', 'abilities-bridge' ); ?></h2>
			<p>
				<?php esc_html_e( 'Connect this WordPress site to Claude Desktop using the Model Context Protocol (MCP). This creates a remote connector that runs on your WordPress server.', 'abilities-bridge' ); ?>
			</p>

		<?php if ( ! is_ssl() ) : ?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'HTTPS Required:', 'abilities-bridge' ); ?></strong>
				<?php esc_html_e( 'Remote MCP connectors require HTTPS. Please enable SSL on your WordPress site.', 'abilities-bridge' ); ?>
			</p>
		</div>
		<?php endif; ?>

			<h3><?php esc_html_e( 'MCP Connector Setup', 'abilities-bridge' ); ?></h3>

			<div style="background: #d1f0d1; border-left: 4px solid #46b450; padding: 15px; margin: 15px 0;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Step 1: Generate Client Credentials', 'abilities-bridge' ); ?></h4>

				<?php if ( isset( $generated_client_id ) && isset( $generated_client_secret ) ) : ?>
					<!-- Security Warning Banner -->
					<div class="notice notice-warning inline" style="margin: 15px 0; padding: 15px; border-left-width: 4px;">
						<p style="margin: 0;">
							<strong style="font-size: 14px;">⚠️ SECURITY NOTICE: ONE-TIME VIEW</strong><br>
							<span style="font-size: 13px;">
								<?php esc_html_e( 'These credentials are shown ONE TIME ONLY and cannot be viewed again. Copy them immediately and store them securely.', 'abilities-bridge' ); ?>
								<?php esc_html_e( 'If you lose them, you must revoke and generate new credentials.', 'abilities-bridge' ); ?>
							</span>
						</p>
					</div>

					<div id="generated-credentials" data-one-time-view="true" style="background: #fff; border: 2px solid #46b450; padding: 15px; margin: 10px 0;">
						<p><strong><?php esc_html_e( 'Your OAuth 2.0 Credentials:', 'abilities-bridge' ); ?></strong></p>

						<p style="margin-top: 15px;"><strong><?php esc_html_e( 'Client ID:', 'abilities-bridge' ); ?></strong></p>
						<code id="mcp-client-id" style="display: block; padding: 10px; background: #f0f0f0; word-break: break-all;">
							<?php echo esc_html( $generated_client_id ); ?>
						</code>
						<button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-client-id" style="margin-top: 5px;">
							<?php esc_html_e( 'Copy Client ID', 'abilities-bridge' ); ?>
						</button>

						<p style="margin-top: 15px;"><strong><?php esc_html_e( 'Client Secret:', 'abilities-bridge' ); ?></strong></p>
						<code id="mcp-client-secret" style="display: block; padding: 10px; background: #f0f0f0; word-break: break-all;">
							<?php echo esc_html( $generated_client_secret ); ?>
						</code>
						<button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-client-secret" style="margin-top: 5px;">
							<?php esc_html_e( 'Copy Client Secret', 'abilities-bridge' ); ?>
						</button>
					</div>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=abilities-bridge-settings' ) ); ?>">
						<?php wp_nonce_field( 'abilities_bridge_mcp_generate_oauth' ); ?>
						<input type="hidden" name="generate_oauth" value="1">
						<p>
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Generate New Client Credentials', 'abilities-bridge' ); ?>
							</button>
						</p>
					</form>
				<?php endif; ?>

				<?php if ( ! empty( $existing_clients ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of active clients */
							esc_html( _n( 'You have %d active client.', 'You have %d active clients.', count( $existing_clients ), 'abilities-bridge' ) ),
							count( $existing_clients )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 15px; margin: 15px 0;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Step 2: Remote MCP Server URL', 'abilities-bridge' ); ?></h4>
				<p><?php esc_html_e( 'Use this URL to connect from Claude Desktop:', 'abilities-bridge' ); ?></p>
				<code id="mcp-endpoint-url" style="display: block; padding: 10px; background: #fff; border: 1px solid #ddd;">
					<?php echo esc_url( $mcp_endpoint ); ?>
				</code>
				<button type="button" class="button button-small mcp-copy-btn" data-copy-target="mcp-endpoint-url" style="margin-top: 10px;">
					<?php esc_html_e( 'Copy URL', 'abilities-bridge' ); ?>
				</button>
			</div>

			<div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 15px; margin: 15px 0;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Step 3: Add to Claude Desktop', 'abilities-bridge' ); ?></h4>

				<p><strong><?php esc_html_e( 'Method A: Using Claude Desktop GUI (Recommended):', 'abilities-bridge' ); ?></strong></p>
				<ol style="margin-left: 20px;">
					<li><?php esc_html_e( 'Open Claude Desktop → Settings → Connectors', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Click "Add custom connector"', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Fill in the form:', 'abilities-bridge' ); ?>
						<ul style="list-style: disc; margin-left: 20px; margin-top: 5px;">
							<li><strong><?php esc_html_e( 'Name:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'WordPress (or any name you prefer)', 'abilities-bridge' ); ?></li>
							<li><strong><?php esc_html_e( 'Remote MCP server URL:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'Paste the URL from Step 2 above', 'abilities-bridge' ); ?></li>
							<li><strong><?php esc_html_e( 'OAuth Client ID:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'Paste your Client ID from Step 1', 'abilities-bridge' ); ?></li>
							<li><strong><?php esc_html_e( 'OAuth Client Secret:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'Paste your Client Secret from Step 1', 'abilities-bridge' ); ?></li>
						</ul>
					</li>
					<li><?php esc_html_e( 'Click "Add connector"', 'abilities-bridge' ); ?></li>
				</ol>
				<p style="padding: 10px; background: #e7f3ff; border-left: 4px solid #2196f3; margin-top: 10px;">
					<strong><?php esc_html_e( 'Note:', 'abilities-bridge' ); ?></strong>
					<?php esc_html_e( 'Claude Desktop will automatically exchange your credentials for access tokens and handle token refresh in the background.', 'abilities-bridge' ); ?>
				</p>

			</div>

			<div style="background: #d1f0d1; border-left: 4px solid #46b450; padding: 15px; margin: 15px 0;">
				<h4 style="margin-top: 0;"><?php esc_html_e( 'Step 4: Connect and Authorize', 'abilities-bridge' ); ?></h4>
				<p><?php esc_html_e( 'In Claude, click the Connect button. You will be redirected to your website to authorize the connection, then redirected back to Claude where the connection should be successful.', 'abilities-bridge' ); ?></p>
			</div>

			<?php if ( ! empty( $existing_clients ) ) : ?>
				<h3 style="margin-top: 30px;"><?php esc_html_e( 'Manage Client Credentials', 'abilities-bridge' ); ?></h3>
				<p><?php esc_html_e( 'View and revoke your active OAuth client credentials.', 'abilities-bridge' ); ?></p>

				<table class="widefat" style="margin-top: 15px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Client ID', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Created', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $existing_clients as $client ) : ?>
							<tr>
								<td><code><?php echo esc_html( $client['client_id'] ); ?></code></td>
								<td>
									<?php
									echo esc_html(
										wp_date(
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											$client['created_at']
										)
									);
									?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=abilities-bridge-settings' ) ); ?>" style="display: inline;">
										<?php wp_nonce_field( 'abilities_bridge_mcp_revoke_client' ); ?>
										<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>">
										<input type="hidden" name="revoke_client" value="1">
										<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to revoke this client? This will invalidate all tokens and the client will no longer be able to connect.', 'abilities-bridge' ); ?>');">
											<?php esc_html_e( 'Revoke', 'abilities-bridge' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top: 10px;">
					<?php esc_html_e( 'Revoking a client will invalidate all associated access tokens and refresh tokens. You will need to generate new client credentials to reconnect.', 'abilities-bridge' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the settings page
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'abilities-bridge' ) );
		}

		// Check system requirements.
		$requirements = $this->check_requirements();

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<!-- Tab Navigation -->
			<h2 class="nav-tab-wrapper abilities-bridge-settings-tabs">
				<a href="#general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e( 'General', 'abilities-bridge' ); ?></a>
				<a href="#memory" class="nav-tab" data-tab="memory"><?php esc_html_e( 'Memory', 'abilities-bridge' ); ?></a>
				<a href="#mcp-setup" class="nav-tab" data-tab="mcp-setup"><?php esc_html_e( 'MCP Setup', 'abilities-bridge' ); ?></a>
				<a href="#about" class="nav-tab" data-tab="about"><?php esc_html_e( 'About', 'abilities-bridge' ); ?></a>
				<a href="#pro" class="nav-tab" data-tab="pro" style="color: #4CAF50; font-weight: 600;">
					<?php esc_html_e( 'Pro Features', 'abilities-bridge' ); ?>
				</a>
			</h2>

			<!-- General Tab -->
			<div class="abilities-bridge-tab-content" id="tab-general" style="display: block;">
				<div class="card">
				<h2><?php esc_html_e( 'System Requirements', 'abilities-bridge' ); ?></h2>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Requirement', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Status', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Details', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $requirements as $req ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $req['name'] ); ?></strong></td>
								<td>
									<?php if ( $req['status'] ) : ?>
										<span style="color: green;">✓ <?php esc_html_e( 'OK', 'abilities-bridge' ); ?></span>
									<?php else : ?>
										<span style="color: red;">✗ <?php esc_html_e( 'Missing', 'abilities-bridge' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $req['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

				<form method="post" action="options.php">
					<?php
					settings_fields( 'abilities_bridge_settings' );
					do_settings_sections( 'abilities-bridge-settings' );
					submit_button();
					?>
				</form>
			</div>

			<!-- Memory Tab -->
			<div class="abilities-bridge-tab-content" id="tab-memory" style="display: none;">
				<?php $this->render_builtin_tools_section(); ?>
			</div>

			<!-- MCP Setup Tab -->
			<div class="abilities-bridge-tab-content" id="tab-mcp-setup" style="display: none;">
				<?php $this->render_mcp_section(); ?>
			</div>

			<!-- About Tab -->
			<div class="abilities-bridge-tab-content" id="tab-about" style="display: none;">

			<div class="card">
				<h2><?php esc_html_e( 'About Abilities Bridge', 'abilities-bridge' ); ?></h2>
				<p><strong><?php esc_html_e( 'Version:', 'abilities-bridge' ); ?></strong> <?php echo esc_html( ABILITIES_BRIDGE_VERSION ); ?></p>
				<p>
					<?php esc_html_e( 'Abilities Bridge connects Claude AI to your WordPress site through two interfaces:', 'abilities-bridge' ); ?>
				</p>
				<ol style="margin-left: 20px; margin-bottom: 15px;">
					<li><strong><?php esc_html_e( 'Admin Chat Interface', 'abilities-bridge' ); ?></strong> - <?php esc_html_e( 'Built-in chat interface powered by Claude AI', 'abilities-bridge' ); ?></li>
					<li><strong><?php esc_html_e( 'MCP Integration', 'abilities-bridge' ); ?></strong> - <?php esc_html_e( 'Connect via MCP (Model Context Protocol) to use with Claude Code, Claude Desktop and other MCP integrations', 'abilities-bridge' ); ?></li>
				</ol>

				<p><strong><?php esc_html_e( 'Memory Tool (Optional)', 'abilities-bridge' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px; margin-bottom: 15px;">
					<li><?php esc_html_e( 'Store persistent notes and context across conversations in the WordPress database', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Requires explicit consent in Memory settings', 'abilities-bridge' ); ?></li>
				</ul>

				<p><strong><?php esc_html_e( 'Managed AI Abilities', 'abilities-bridge' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px; margin-bottom: 15px;">
					<li><?php esc_html_e( 'The Abilities API allows plugins to register AI-callable functions with comprehensive permission controls', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Each ability passes a 7-gate permission system: enable/disable toggle, daily rate limits, hourly rate limits, per-request limits, risk level classification, user approval requirements, and admin approval requirements', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'Site administrators can review and approve ability executions through the Ability Permissions tab', 'abilities-bridge' ); ?></li>
					<li><?php esc_html_e( 'All ability executions are logged with full input/output tracking and audit trails', 'abilities-bridge' ); ?></li>
				</ul>

				<p>
					<?php esc_html_e( 'All executions are logged with source tracking (Admin Chat vs MCP), activity history, and usage statistics.', 'abilities-bridge' ); ?>
				</p>

				<p>
					<strong><?php esc_html_e( 'Security:', 'abilities-bridge' ); ?></strong>
					<?php esc_html_e( 'Abilities registered by plugins are subject to the user-managed permission system. All actions require explicit authorization.', 'abilities-bridge' ); ?>
				</p>
			</div>
			</div>

			<!-- Pro Features Tab -->
			<div class="abilities-bridge-tab-content" id="tab-pro" style="display: none;">
				<div class="card" style="max-width: 900px;">
					<!-- Hero Section -->
					<div style="background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%); color: white; padding: 40px; border-radius: 8px; text-align: center; margin-bottom: 30px;">
						<h1 style="color: white; margin: 0 0 10px 0; font-size: 36px;">
							Upgrade to Abilities Bridge Pro
						</h1>
						<p style="font-size: 18px; margin: 0 0 20px 0; opacity: 0.95;">
							<?php esc_html_e( 'Unlock powerful features for teams and advanced workflows', 'abilities-bridge' ); ?>
						</p>
						<a href="https://aisystemadmin.com/upgrade" class="button button-primary button-hero" style="background: white; border: none; color: #2d3748; font-size: 18px; padding: 12px 40px; height: auto; text-shadow: none; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
							<?php esc_html_e( 'Upgrade to Pro →', 'abilities-bridge' ); ?>
						</a>
					</div>

					<!-- Feature Grid (2 Column Layout) -->
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-bottom: 30px; max-width: 800px; margin-left: auto; margin-right: auto;">

						<!-- Feature 1: User Access Control -->
						<div style="border: 2px solid #e0e0e0; padding: 30px; border-radius: 8px; background: #f9f9f9;">
							<h3 style="margin: 0 0 15px 0; color: #333; font-size: 22px;">
								<?php esc_html_e( 'User Access Control', 'abilities-bridge' ); ?>
							</h3>
							<p style="margin: 0 0 15px 0; color: #666; line-height: 1.6; font-size: 15px;">
								<?php esc_html_e( 'Grant AI access to non-admin users with granular permissions. Perfect for editors and content managers who need AI assistance without full admin access.', 'abilities-bridge' ); ?>
							</p>
							<ul style="margin: 0; padding-left: 20px; color: #666; line-height: 1.8;">
								<li><?php esc_html_e( 'Per-user tool permissions', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'File path restrictions', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'Custom rate limits', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'Revokable access', 'abilities-bridge' ); ?></li>
							</ul>
						</div>

						<!-- Feature 2: Human Support for AI Solutions -->
						<div style="border: 2px solid #e0e0e0; padding: 30px; border-radius: 8px; background: #f9f9f9;">
							<h3 style="margin: 0 0 15px 0; color: #333; font-size: 22px;">
								<?php esc_html_e( 'Human Support for AI Solutions', 'abilities-bridge' ); ?>
							</h3>
							<p style="margin: 0 0 15px 0; color: #666; line-height: 1.6; font-size: 15px;">
								<?php esc_html_e( 'Get expert help from real humans who understand both WordPress and AI. Complete support package combining technical assistance with strategic AI consulting.', 'abilities-bridge' ); ?>
							</p>
							<ul style="margin: 0; padding-left: 20px; color: #666; line-height: 1.8;">
								<li><?php esc_html_e( 'Direct developer support', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'AI strategy consulting', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'Implementation guidance', 'abilities-bridge' ); ?></li>
								<li><?php esc_html_e( 'WordPress + AI integration help', 'abilities-bridge' ); ?></li>
							</ul>
						</div>

					</div>

					<!-- Pricing / CTA Section -->
					<div style="text-align: center; padding: 30px; background: #fafafa; border-radius: 8px;">
						<h2 style="margin: 0 0 15px 0;">
							<?php esc_html_e( 'Ready to Upgrade?', 'abilities-bridge' ); ?>
						</h2>
						<p style="font-size: 16px; color: #666; margin: 0 0 25px 0;">
							<?php esc_html_e( 'Unlock all Pro features and take your WordPress AI integration to the next level.', 'abilities-bridge' ); ?>
						</p>
						<a href="https://aisystemadmin.com/upgrade" class="button button-primary button-hero" style="font-size: 18px; padding: 12px 40px; height: auto;">
							<?php esc_html_e( 'View Pro Plans & Pricing →', 'abilities-bridge' ); ?>
						</a>
						<p style="margin: 20px 0 0 0; color: #999; font-size: 14px;">
							<?php esc_html_e( '30-day money-back guarantee • No contracts • Cancel anytime', 'abilities-bridge' ); ?>
						</p>
					</div>

					<!-- Have Questions Section -->
					<div style="margin-top: 30px; padding: 20px; border-top: 1px solid #e0e0e0;">
						<h3><?php esc_html_e( 'Have Questions?', 'abilities-bridge' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: %1$s: opening link tag, %2$s: closing link tag */
								esc_html__( 'Check out our %1$sdocumentation%2$s or contact us at support@aisystemadmin.com', 'abilities-bridge' ),
								'<a href="https://aisystemadmin.com/docs" target="_blank">',
								'</a>'
							);
							?>
						</p>
					</div>

				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check system requirements
	 *
	 * @return array
	 */
	private function check_requirements() {
		$requirements = array();

		// Check PHP version.
		$requirements[] = array(
			'name'    => 'PHP Version',
			'status'  => version_compare( PHP_VERSION, '7.4', '>=' ),
			'details' => 'Current: ' . PHP_VERSION . ' (Required: 7.4+)',
		);

		// Check JSON extension.
		$requirements[] = array(
			'name'    => 'JSON Extension',
			'status'  => function_exists( 'json_encode' ),
			'details' => function_exists( 'json_encode' ) ? 'Available' : 'Missing - required for API communication',
		);

		// Check cURL extension.
		$requirements[] = array(
			'name'    => 'cURL Extension',
			'status'  => function_exists( 'curl_init' ),
			'details' => function_exists( 'curl_init' ) ? 'Available' : 'Missing - required for API communication',
		);

		// Check file permissions for uploads directory using WP_Filesystem.
		$upload_dir  = wp_upload_dir();
		$content_dir = $upload_dir['basedir'] . '/abilities-bridge';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$parent_writable = $wp_filesystem->is_writable( $upload_dir['basedir'] );
		$dir_exists      = $wp_filesystem->is_dir( $content_dir );
		$dir_writable    = $dir_exists && $wp_filesystem->is_writable( $content_dir );

		$requirements[] = array(
			'name'    => 'Uploads Directory Writable',
			'status'  => $parent_writable || $dir_writable,
			'details' => $parent_writable ? 'Uploads directory writable' : ( $dir_exists ? 'Plugin directory exists' : 'Cannot create plugin directory' ),
		);

		return $requirements;
	}
}
