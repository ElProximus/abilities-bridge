<?php
/**
 * Floating bubble chat UI.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Floating chat bubble class.
 */
class Abilities_Bridge_Admin_Bubble {

	/**
	 * Whether the bubble markup has already been rendered.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_admin_bubble' ) );
		add_action( 'wp_footer', array( $this, 'render_frontend_bubble' ) );
	}

	/**
	 * Enqueue assets in wp-admin.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		if ( ! $this->should_render_bubble() || $this->is_hidden_admin_screen() ) {
			return;
		}

		$this->enqueue_shared_assets();
	}

	/**
	 * Enqueue assets on the front end.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( ! $this->should_render_bubble() ) {
			return;
		}

		$this->enqueue_shared_assets();
	}

	/**
	 * Render bubble markup in wp-admin.
	 *
	 * @return void
	 */
	public function render_admin_bubble() {
		if ( ! $this->should_render_bubble() || $this->is_hidden_admin_screen() ) {
			return;
		}

		$this->render_bubble_markup();
	}

	/**
	 * Render bubble markup on the front end.
	 *
	 * @return void
	 */
	public function render_frontend_bubble() {
		if ( ! $this->should_render_bubble() ) {
			return;
		}

		$this->render_bubble_markup();
	}

	/**
	 * Determine whether the bubble should be available.
	 *
	 * @return bool
	 */
	private function should_render_bubble() {
		$enabled = rest_sanitize_boolean( get_option( 'abilities_bridge_enable_chat_bubble', false ) );

		if ( ! $enabled || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( class_exists( 'Abilities_Bridge_Welcome_Wizard' ) && ! Abilities_Bridge_Welcome_Wizard::is_setup_complete() ) {
			return false;
		}

		return true;
	}

	/**
	 * Skip rendering on the main dashboard, which already contains the full chat UI.
	 *
	 * @return bool
	 */
	private function is_hidden_admin_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && 'toplevel_page_abilities-bridge' === $screen->id;
	}

	/**
	 * Enqueue shared assets.
	 *
	 * @return void
	 */
	private function enqueue_shared_assets() {
		$css_version = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/css/admin-bubble.css' );
		$js_version  = filemtime( ABILITIES_BRIDGE_PLUGIN_DIR . 'admin/js/admin-bubble.js' );

		wp_enqueue_style(
			'abilities-bridge-bubble',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/admin-bubble.css',
			array(),
			$css_version ? $css_version : ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-bubble',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/admin-bubble.js',
			array( 'jquery' ),
			$js_version ? $js_version : ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-bubble',
			'abilitiesBridgeBubbleData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'abilities_bridge_nonce' ),
				'i18n'    => array(
					'welcome'            => __( 'Hi, I\'m your AI assistant. How can I help you today?', 'abilities-bridge' ),
					'newConversation'    => __( 'Start a new conversation?', 'abilities-bridge' ),
					'sending'            => __( 'Processing...', 'abilities-bridge' ),
					'send'               => __( 'Send', 'abilities-bridge' ),
					'providerChanged'    => __( 'Provider updated. Start a fresh conversation for best results.', 'abilities-bridge' ),
					'modelChanged'       => __( 'Model updated. Start a fresh conversation for best results.', 'abilities-bridge' ),
					'loadFailed'         => __( 'Unable to load that conversation right now.', 'abilities-bridge' ),
					'connectionError'    => __( 'Unable to connect to the AI service. Please try again.', 'abilities-bridge' ),
					'emptyConversations' => __( 'No saved conversations yet', 'abilities-bridge' ),
					'selectConversation' => __( 'Select a conversation...', 'abilities-bridge' ),
					'noConversation'     => __( 'No conversation selected', 'abilities-bridge' ),
					'tokensUsed'         => __( 'tokens used', 'abilities-bridge' ),
					'ready'              => __( 'Ready', 'abilities-bridge' ),
					'conversationLoaded' => __( 'Conversation loaded.', 'abilities-bridge' ),
					'attention'          => __( 'Something needs attention.', 'abilities-bridge' ),
					'loadConversationsFailed' => __( 'Unable to load saved conversations.', 'abilities-bridge' ),
					'loadProviderFailed' => __( 'Unable to load provider settings.', 'abilities-bridge' ),
					'loadTokenFailed'    => __( 'Unable to load token usage.', 'abilities-bridge' ),
				),
			)
		);
	}

	/**
	 * Render bubble markup once.
	 *
	 * @return void
	 */
	private function render_bubble_markup() {
		if ( $this->rendered ) {
			return;
		}

		$this->rendered = true;
		?>
		<div id="abilities-bridge-bubble-root" class="abilities-bridge-bubble-root" aria-live="polite">
			<button type="button" id="abilities-bridge-bubble-launcher" class="abilities-bridge-bubble-launcher" aria-controls="abilities-bridge-bubble-panel" aria-expanded="false">
				<span class="abilities-bridge-bubble-launcher-label"><?php esc_html_e( 'Bridge', 'abilities-bridge' ); ?></span>
			</button>

			<section id="abilities-bridge-bubble-panel" class="abilities-bridge-bubble-panel" hidden>
				<div class="abilities-bridge-bubble-header abilities-bridge-bubble-header-compact">
					<button type="button" id="abilities-bridge-bubble-close" class="abilities-bridge-bubble-close" aria-label="<?php esc_attr_e( 'Collapse chat bubble', 'abilities-bridge' ); ?>">&#8964;</button>
				</div>

				<div class="abilities-bridge-bubble-controls">
					<div class="abilities-bridge-bubble-field-group">
						<label for="abilities-bridge-bubble-provider"><?php esc_html_e( 'Provider', 'abilities-bridge' ); ?></label>
						<select id="abilities-bridge-bubble-provider"></select>
					</div>
					<div class="abilities-bridge-bubble-field-group">
						<label for="abilities-bridge-bubble-model"><?php esc_html_e( 'Model', 'abilities-bridge' ); ?></label>
						<select id="abilities-bridge-bubble-model"></select>
					</div>
				</div>
				<div id="abilities-bridge-bubble-model-guidance" class="abilities-bridge-bubble-model-guidance"></div>

				<div class="abilities-bridge-bubble-conversations">
					<label for="abilities-bridge-bubble-conversation-select"><?php esc_html_e( 'Saved Conversations', 'abilities-bridge' ); ?></label>
					<div class="abilities-bridge-bubble-conversation-row">
						<select id="abilities-bridge-bubble-conversation-select"></select>
						<button type="button" id="abilities-bridge-bubble-new" class="abilities-bridge-bubble-secondary"><?php esc_html_e( 'New', 'abilities-bridge' ); ?></button>
					</div>
				</div>

				<div id="abilities-bridge-bubble-status" class="abilities-bridge-bubble-status" aria-live="polite"><?php esc_html_e( 'Ready', 'abilities-bridge' ); ?></div>

				<div class="abilities-bridge-bubble-token-meter">
					<div class="abilities-bridge-bubble-token-bar">
						<div class="abilities-bridge-bubble-token-fill" style="width: 0%"></div>
					</div>
					<div class="abilities-bridge-bubble-token-meta">
						<span id="abilities-bridge-bubble-token-count">0 <?php esc_html_e( 'tokens used', 'abilities-bridge' ); ?></span>
						<span id="abilities-bridge-bubble-token-limit"><?php esc_html_e( 'No conversation selected', 'abilities-bridge' ); ?></span>
					</div>
				</div>

				<div id="abilities-bridge-bubble-messages" class="abilities-bridge-bubble-messages"></div>

				<form id="abilities-bridge-bubble-form" class="abilities-bridge-bubble-form">
					<textarea id="abilities-bridge-bubble-input" rows="3" placeholder="<?php esc_attr_e( 'Type your message here...', 'abilities-bridge' ); ?>" required></textarea>
					<div class="abilities-bridge-bubble-form-actions">
						<span class="abilities-bridge-bubble-hint"><?php esc_html_e( 'Enter to send, Shift + Enter for a new line', 'abilities-bridge' ); ?></span>
						<button type="submit" id="abilities-bridge-bubble-send" class="abilities-bridge-bubble-primary"><?php esc_html_e( 'Send', 'abilities-bridge' ); ?></button>
					</div>
				</form>
			</section>
		</div>
		<?php
	}
}
