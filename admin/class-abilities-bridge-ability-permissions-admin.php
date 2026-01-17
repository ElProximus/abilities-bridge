<?php
/**
 * Ability Permissions Admin Interface
 *
 * This admin page uses direct database queries for the custom ability_permissions table.
 * Queries are for admin display/management and don't benefit from object caching.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability Permissions Admin class.
 *
 * Handles the ability permissions admin interface.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Ability_Permissions_Admin {

	/**
	 * Initialize admin interface
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_abilities_bridge_register_ability', array( __CLASS__, 'handle_register_ability' ) );
		add_action( 'admin_post_abilities_bridge_toggle_ability', array( __CLASS__, 'handle_toggle_ability' ) );
		add_action( 'admin_post_abilities_bridge_delete_ability', array( __CLASS__, 'handle_delete_ability' ) );
		add_action( 'admin_post_abilities_bridge_toggle_abilities_api', array( __CLASS__, 'handle_toggle_abilities_api' ) );
		add_action( 'admin_post_abilities_bridge_toggle_core_abilities', array( __CLASS__, 'handle_toggle_core_abilities' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register settings
	 */
	public static function register_settings() {
		register_setting(
			'abilities_bridge_abilities_api',
			'abilities_bridge_enable_abilities_api',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	/**
	 * Add admin menu
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'abilities-bridge',
			__( 'Ability Permissions', 'abilities-bridge' ),
			__( 'Ability Permissions', 'abilities-bridge' ),
			'manage_options',
			'abilities-bridge-permissions',
			array( __CLASS__, 'render_permissions_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'abilities-bridge-permissions' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'abilities-bridge-permissions',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/ability-permissions.css',
			array(),
			ABILITIES_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'abilities-bridge-permissions',
			ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/ability-permissions.js',
			array( 'jquery' ),
			ABILITIES_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'abilities-bridge-permissions',
			'abilitiesBridgeAbilities',
			array(
				'abilitiesConsentGiven' => (bool) get_option( 'abilities_bridge_abilities_api_consent', false ),
			)
		);
	}

	/**
	 * Verify the page navigation nonce.
	 *
	 * Returns true if nonce is valid or not present (first page load).
	 * Dies with error if nonce is present but invalid.
	 *
	 * @return bool True if nonce is valid, false if no nonce present.
	 */
	private static function verify_page_nonce() {
		$nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// No nonce present = first page load, allow with defaults.
		if ( empty( $nonce ) ) {
			return false;
		}

		// Nonce present - verify it.
		if ( ! wp_verify_nonce( $nonce, 'abilities_bridge_permissions_nav' ) ) {
			wp_die(
				esc_html__( 'Security verification failed. Please use the navigation links provided.', 'abilities-bridge' ),
				esc_html__( 'Security Check Failed', 'abilities-bridge' ),
				array( 'response' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get a sanitized GET parameter for display/routing purposes.
	 *
	 * Verifies nonce before accessing parameters. If no nonce is present
	 * (first page load), returns the default value.
	 *
	 * @param string $key     Parameter key.
	 * @param string $default Default value.
	 * @return string Sanitized value.
	 */
	private static function get_url_param( $key, $default = '' ) {
		// Verify nonce - returns false if no nonce (first load).
		if ( ! self::verify_page_nonce() ) {
			return $default;
		}

		$value = filter_input( INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		return ! empty( $value ) ? sanitize_key( $value ) : $default;
	}

	/**
	 * Get a sanitized integer GET parameter for display purposes.
	 *
	 * Verifies nonce before accessing parameters. If no nonce is present
	 * (first page load), returns the default value.
	 *
	 * @param string $key     Parameter key.
	 * @param int    $default Default value.
	 * @return int Sanitized integer value.
	 */
	private static function get_url_int_param( $key, $default = 0 ) {
		// Verify nonce - returns false if no nonce (first load).
		if ( ! self::verify_page_nonce() ) {
			return $default;
		}

		$value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_INT );
		return false !== $value && null !== $value ? max( 1, absint( $value ) ) : $default;
	}

	/**
	 * Render permissions page
	 */
	public static function render_permissions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'abilities-bridge' ) );
		}

		$active_tab = self::get_url_param( 'tab', 'list' );

		?>
		<div class="wrap abilities-bridge-permissions-wrap">
			<h1>
				<?php esc_html_e( 'Ability Permissions', 'abilities-bridge' ); ?>
				<span class="abilities-bridge-security-badge">🔐 Hardcoded Security</span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Manage which WordPress abilities Claude can execute. All permissions are enforced at code level.', 'abilities-bridge' ); ?>
			</p>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-permissions&tab=list', 'abilities_bridge_permissions_nav' ) ); ?>" class="nav-tab <?php echo esc_attr( 'list' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Permissions', 'abilities-bridge' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-permissions&tab=register', 'abilities_bridge_permissions_nav' ) ); ?>" class="nav-tab <?php echo esc_attr( 'register' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Authorize Ability', 'abilities-bridge' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-permissions&tab=stats', 'abilities_bridge_permissions_nav' ) ); ?>" class="nav-tab <?php echo esc_attr( 'stats' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Usage Statistics', 'abilities-bridge' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( '?page=abilities-bridge-permissions&tab=logs', 'abilities_bridge_permissions_nav' ) ); ?>" class="nav-tab <?php echo esc_attr( 'logs' === $active_tab ? 'nav-tab-active' : '' ); ?>">
					<?php esc_html_e( 'Audit Logs', 'abilities-bridge' ); ?>
				</a>
			</nav>

			<div class="abilities-bridge-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'register':
						self::render_register_tab();
						break;
					case 'stats':
						self::render_stats_tab();
						break;
					case 'logs':
						self::render_logs_tab();
						break;
					case 'list':
					default:
						self::render_list_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render permissions list tab
	 */
	private static function render_list_tab() {
		$permissions         = Abilities_Bridge_Ability_Permissions::get_all_permissions( false );
		$read_only_abilities = array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' );
		$abilities_enabled   = get_option( 'abilities_bridge_enable_abilities_api', false );
		$abilities_consent   = get_option( 'abilities_bridge_abilities_api_consent', false );

		// Display error notice if consent was required.
		if ( 'abilities_consent_required' === self::get_url_param( 'error' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Error:', 'abilities-bridge' ); ?></strong>
					<?php esc_html_e( 'You must check the consent box to enable the WordPress Abilities API.', 'abilities-bridge' ); ?>
				</p>
			</div>
			<?php
		}
		?>
		<div class="abilities-bridge-permissions-list <?php echo esc_attr( ! $abilities_enabled ? 'abilities-disabled' : '' ); ?>">
			<div class="notice notice-info">
				<p>
					<strong>🛡️ Security Architecture:</strong>
					Every ability execution must pass ALL 7 hardcoded permission gates. Default is DENY, explicit ALLOW only.
				</p>
			</div>

			<!-- Enable Abilities Master Toggle -->
			<div class="abilities-api-toggle-container" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="abilities-api-toggle-form">
					<input type="hidden" name="action" value="abilities_bridge_toggle_abilities_api">
					<?php wp_nonce_field( 'abilities_bridge_toggle_abilities_api', '_wpnonce' ); ?>

					<label for="abilities_bridge_enable_abilities_api" style="display: flex; align-items: center; gap: 10px; font-size: 16px; cursor: pointer;">
						<input
							type="checkbox"
							name="abilities_bridge_enable_abilities_api"
							id="abilities_bridge_enable_abilities_api"
							value="1"
							<?php checked( $abilities_enabled, true ); ?>
							style="width: 20px; height: 20px; cursor: pointer;"
						/>
						<strong><?php esc_html_e( 'Enable Abilities Execution', 'abilities-bridge' ); ?></strong>
					</label>

					<!-- Consent Checkbox (appears when Abilities API is enabled AND consent not yet given) -->
					<div id="abilities-api-consent-container" style="margin: 15px 0 0 30px; padding: 12px; background: #fcf9e8; border-left: 4px solid #dba617; <?php echo ( ! $abilities_enabled || $abilities_consent ) ? 'display: none;' : ''; ?>">
						<label for="abilities_bridge_abilities_api_consent" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
							<input
								type="checkbox"
								name="abilities_bridge_abilities_api_consent"
								id="abilities_bridge_abilities_api_consent"
								value="1"
								style="width: 18px; height: 18px; margin-top: 2px; cursor: pointer; flex-shrink: 0;"
							/>
							<span style="font-size: 14px;">
								<?php esc_html_e( 'Granting Claude permission to execute abilities comes with inherent risks, and some abilities may be destructive. Always test abilities thoroughly on non-production sites before enabling them on live websites. By enabling this feature, you acknowledge that you do so at your own risk and are solely responsible for configuring ability permissions appropriately.', 'abilities-bridge' ); ?>
							</span>
						</label>
					</div>

					<div class="notice notice-warning inline" style="margin: 15px 0 0 0; padding: 10px;">
						<p style="margin: 0;">
							<strong>ℹ️</strong>
							<?php esc_html_e( 'Requires Abilities API or WordPress Version 6.9', 'abilities-bridge' ); ?>
						</p>
					</div>

					<p style="margin: 15px 0 0 0;">
						<button type="submit" class="button button-primary" id="abilities-api-save-button">
							<?php esc_html_e( 'Save Setting', 'abilities-bridge' ); ?>
						</button>
					</p>
				</form>
			</div>

			<h2><?php esc_html_e( 'Core Read-Only Abilities', 'abilities-bridge' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'These are safe, read-only abilities provided by WordPress Abilities API. Enable them to allow Claude to access basic site information.', 'abilities-bridge' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'abilities_bridge_toggle_core_abilities', 'toggle_core_abilities_nonce' ); ?>
				<input type="hidden" name="action" value="abilities_bridge_toggle_core_abilities" />

				<table class="wp-list-table widefat fixed striped core-abilities-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ability Name', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Description', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Status', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $read_only_abilities as $ability_name ) : ?>
							<?php
							$ability_config = null;
							foreach ( $permissions as $perm ) {
								if ( $perm['ability_name'] === $ability_name ) {
									$ability_config = $perm;
									break;
								}
							}
							$is_enabled = $ability_config ? (bool) $ability_config['enabled'] : false;
							?>
							<tr>
								<td>
									<strong><code><?php echo esc_html( $ability_name ); ?></code></strong>
								</td>
								<td><?php echo esc_html( $ability_config ? $ability_config['description'] : 'Read-only analysis' ); ?></td>
								<td>
									<fieldset class="core-ability-toggle">
										<label>
											<input type="radio"
													name="core_ability_<?php echo esc_attr( $ability_name ); ?>"
													value="1"
													<?php checked( $is_enabled, true ); ?> />
											<?php esc_html_e( 'Enabled', 'abilities-bridge' ); ?>
										</label>
										<label style="margin-left: 15px;">
											<input type="radio"
													name="core_ability_<?php echo esc_attr( $ability_name ); ?>"
													value="0"
													<?php checked( $is_enabled, false ); ?> />
											<?php esc_html_e( 'Disabled', 'abilities-bridge' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Changes', 'abilities-bridge' ); ?>
					</button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Authorized Abilities (Permission-Gated)', 'abilities-bridge' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'These abilities require explicit approval and configuration.', 'abilities-bridge' ); ?>
			</p>

			<?php if ( empty( $permissions ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No abilities authorized yet. Use the "Authorize Ability" tab to add new abilities.', 'abilities-bridge' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped abilities-bridge-abilities-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ability', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Risk', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Status', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Usage Today', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Limit', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Get all today's usage counts in a single query (avoid N+1).
						global $wpdb;
						$today        = current_time( 'Y-m-d' );
						$like_pattern = $wpdb->esc_like( 'ability_executed_' ) . '%';
						$usage_counts = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT
									REPLACE(action, 'ability_executed_', '') as ability_name,
									COUNT(*) as count
								 FROM %i
								 WHERE action LIKE %s
								   AND DATE(created_at) = %s
								 GROUP BY action",
								Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
								$like_pattern,
								$today
							),
							OBJECT_K
						);

						foreach ( $permissions as $perm ) :
							// Get count from pre-loaded array (no query in loop).
							$today_count = isset( $usage_counts[ $perm['ability_name'] ] ) ? $usage_counts[ $perm['ability_name'] ]->count : 0;

							$usage_percent = $perm['max_per_day'] > 0 ? ( $today_count / $perm['max_per_day'] ) * 100 : 0;
							$usage_class   = $usage_percent >= 90 ? 'high' : ( $usage_percent >= 70 ? 'medium' : 'low' );
							?>
							<tr>
								<td>
									<strong><code><?php echo esc_html( $perm['ability_name'] ); ?></code></strong>
									<div class="ability-description"><?php echo esc_html( $perm['description'] ); ?></div>
								</td>
								<td>
									<span class="risk-badge risk-<?php echo esc_attr( $perm['risk_level'] ); ?>">
										<?php echo esc_html( ucfirst( $perm['risk_level'] ) ); ?>
									</span>
								</td>
								<td>
									<?php if ( $perm['enabled'] ) : ?>
										<span class="status-badge status-enabled">✓ Enabled</span>
									<?php else : ?>
										<span class="status-badge status-disabled">✗ Disabled</span>
									<?php endif; ?>
								</td>
								<td>
									<div class="usage-bar-container">
										<div class="usage-bar usage-<?php echo esc_attr( $usage_class ); ?>" style="width: <?php echo esc_attr( $usage_percent ); ?>%"></div>
									</div>
									<span class="usage-text"><?php echo esc_html( $today_count ); ?></span>
								</td>
								<td>
									<?php
									if ( $perm['max_per_day'] > 0 ) {
										echo esc_html( $perm['max_per_day'] . '/day' );
									} else {
										echo '<span class="status-badge status-disabled">Disabled</span>';
									}
									?>
								</td>
								<td>
									<div class="ability-actions">
										<?php if ( $perm['enabled'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
												<?php wp_nonce_field( 'abilities_bridge_toggle_ability' ); ?>
												<input type="hidden" name="action" value="abilities_bridge_toggle_ability">
												<input type="hidden" name="ability" value="<?php echo esc_attr( $perm['ability_name'] ); ?>">
												<input type="hidden" name="enable" value="0">
												<button type="submit" class="button button-small button-disable"><?php esc_html_e( 'Disable', 'abilities-bridge' ); ?></button>
											</form>
										<?php else : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
												<?php wp_nonce_field( 'abilities_bridge_toggle_ability' ); ?>
												<input type="hidden" name="action" value="abilities_bridge_toggle_ability">
												<input type="hidden" name="ability" value="<?php echo esc_attr( $perm['ability_name'] ); ?>">
												<input type="hidden" name="enable" value="1">
												<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Enable', 'abilities-bridge' ); ?></button>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this ability?', 'abilities-bridge' ) ); ?>');">
												<?php wp_nonce_field( 'abilities_bridge_delete_ability' ); ?>
												<input type="hidden" name="action" value="abilities_bridge_delete_ability">
												<input type="hidden" name="ability" value="<?php echo esc_attr( $perm['ability_name'] ); ?>">
												<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'abilities-bridge' ); ?></button>
											</form>
										<?php endif; ?>

										<?php
										$edit_url = add_query_arg(
											array(
												'page' => 'abilities-bridge-permissions',
												'tab'  => 'register',
												'edit' => rawurlencode( $perm['ability_name'] ),
											),
											admin_url( 'admin.php' )
										);
										$edit_url = wp_nonce_url( $edit_url, 'abilities_bridge_edit_ability', '_wpnonce' );
										?>
										<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
											<?php esc_html_e( 'Edit', 'abilities-bridge' ); ?>
										</a>

										<a href="#" class="button button-small button-info" data-ability="<?php echo esc_attr( wp_json_encode( $perm ) ); ?>">
											<?php esc_html_e( 'Details', 'abilities-bridge' ); ?>
										</a>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Details Modal -->
		<div id="ability-details-modal" class="abilities-bridge-modal" style="display: none;">
			<div class="abilities-bridge-modal-content">
				<span class="abilities-bridge-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Ability Details', 'abilities-bridge' ); ?></h2>
				<div id="ability-details-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render register ability tab
	 */
	private static function render_register_tab() {
		// Check if we're editing an existing ability.
		$editing   = false;
		$edit_data = null;

		if ( isset( $_GET['edit'] ) ) {
			// Verify nonce FIRST with generic action (before using any user input).
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'abilities_bridge_edit_ability' ) ) {
				wp_die(
					esc_html__( 'Security verification failed. This edit link may have expired or is invalid. Please return to the permissions list and try again.', 'abilities-bridge' ),
					esc_html__( 'Security Check Failed', 'abilities-bridge' ),
					array( 'response' => 403 )
				);
			}

			// Now safe to use user input after nonce verified.
			$ability_name = sanitize_text_field( wp_unslash( $_GET['edit'] ) );
			$editing      = true;

			global $wpdb;
			$edit_data = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM %i WHERE ability_name = %s', Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS ), $ability_name ),
				ARRAY_A
			);

			// If ability not found, show error and stop editing mode.
			if ( ! $edit_data ) {
				$editing = false;
				?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Error:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'Ability not found.', 'abilities-bridge' ); ?></p>
				</div>
				<?php
			}
		}
		?>
		<div class="abilities-bridge-register-form">
			<h2>
				<?php
				if ( $editing && $edit_data ) {
					echo esc_html__( 'Edit Ability: ', 'abilities-bridge' ) . esc_html( $edit_data['ability_name'] );
				} else {
					esc_html_e( 'Authorize Ability for Claude', 'abilities-bridge' );
				}
				?>
			</h2>

			<?php if ( $editing && $edit_data ) : ?>
				<div class="notice notice-info">
					<p>
						<strong>ℹ️ <?php esc_html_e( 'Editing Mode:', 'abilities-bridge' ); ?></strong>
						<?php esc_html_e( 'You are editing an existing ability. Changes will update the current configuration.', 'abilities-bridge' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						<strong>ℹ️ About Authorization:</strong>
						Authorize an existing WordPress ability for Claude to execute. Abilities must first be registered in code via <code>wp_register_ability()</code> by plugin developers. This form configures security permissions and rate limits for the ability.
					</p>
				</div>

				<div class="notice notice-warning">
					<p>
						<strong>⚠️ Important:</strong>
						Only authorize abilities that you trust and understand. All executions are logged and subject to rate limits.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="abilities-bridge-ability-form">
				<?php wp_nonce_field( 'abilities_bridge_register_ability', 'abilities_bridge_nonce' ); ?>
				<input type="hidden" name="action" value="abilities_bridge_register_ability">

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ability_name"><?php esc_html_e( 'Ability Name', 'abilities-bridge' ); ?> *</label>
						</th>
						<td>
							<input type="text"
									id="ability_name"
									name="ability_name"
									class="regular-text"
									placeholder="e.g., core/create-post"
									value="<?php echo $editing && $edit_data ? esc_attr( $edit_data['ability_name'] ) : ''; ?>"
									<?php echo $editing ? 'readonly' : ''; ?>
									required>
							<p class="description">
								<?php
								if ( $editing ) {
									esc_html_e( 'Ability name cannot be changed when editing.', 'abilities-bridge' );
								} else {
									esc_html_e( 'Must match the registered WordPress ability name exactly.', 'abilities-bridge' );
								}
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="risk_level"><?php esc_html_e( 'Risk Level', 'abilities-bridge' ); ?> *</label>
						</th>
						<td>
							<?php $current_risk = $editing && $edit_data ? $edit_data['risk_level'] : ''; ?>
							<select id="risk_level" name="risk_level" required>
								<option value="">-- <?php esc_html_e( 'Select Risk Level', 'abilities-bridge' ); ?> --</option>
								<option value="low" <?php selected( $current_risk, 'low' ); ?>>🟢 <?php esc_html_e( 'Low (Read-only, no side effects)', 'abilities-bridge' ); ?></option>
								<option value="medium" <?php selected( $current_risk, 'medium' ); ?>>🟡 <?php esc_html_e( 'Medium (Modifications, easy to undo)', 'abilities-bridge' ); ?></option>
								<option value="high" <?php selected( $current_risk, 'high' ); ?>>🔴 <?php esc_html_e( 'High (Significant modifications)', 'abilities-bridge' ); ?></option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="max_per_day"><?php esc_html_e( 'Max Executions Per Day', 'abilities-bridge' ); ?> *</label>
						</th>
						<td>
							<input type="number"
									id="max_per_day"
									name="max_per_day"
									min="0"
									max="10000"
									value="<?php echo $editing && $edit_data ? esc_attr( $edit_data['max_per_day'] ) : '10'; ?>"
									required>
							<p class="description">
								<?php esc_html_e( '0 = Disabled, 1-10000 = Rate limit. Start conservative!', 'abilities-bridge' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="max_per_hour"><?php esc_html_e( 'Max Executions Per Hour', 'abilities-bridge' ); ?></label>
						</th>
						<td>
							<input type="number"
									id="max_per_hour"
									name="max_per_hour"
									min="0"
									max="1000"
									value="<?php echo $editing && $edit_data ? esc_attr( $edit_data['max_per_hour'] ) : '5'; ?>">
							<p class="description">
								<?php esc_html_e( 'Optional hourly limit for additional protection.', 'abilities-bridge' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="min_capability"><?php esc_html_e( 'Minimum User Capability', 'abilities-bridge' ); ?></label>
						</th>
						<td>
							<input type="text"
									id="min_capability"
									name="min_capability"
									class="regular-text"
									value="<?php echo $editing && $edit_data ? esc_attr( $edit_data['min_capability'] ) : ''; ?>"
									placeholder="e.g., publish_posts">
							<p class="description">
								<?php esc_html_e( 'WordPress capability required to execute. Leave empty for no requirement.', 'abilities-bridge' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="description"><?php esc_html_e( 'Description', 'abilities-bridge' ); ?> *</label>
						</th>
						<td>
							<textarea id="description"
										name="description"
										rows="4"
										class="large-text"
										required
										placeholder="What does this ability do?"><?php echo $editing && $edit_data ? esc_textarea( $edit_data['description'] ) : ''; ?></textarea>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="reason_for_approval"><?php esc_html_e( 'Reason for Approval', 'abilities-bridge' ); ?> *</label>
						</th>
						<td>
							<textarea id="reason_for_approval"
										name="reason_for_approval"
										rows="4"
										class="large-text"
										required
										placeholder="Why are you approving this ability for Claude to execute?"><?php echo $editing && $edit_data ? esc_textarea( $edit_data['reason_for_approval'] ) : ''; ?></textarea>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="enabled"><?php esc_html_e( 'Enable Immediately', 'abilities-bridge' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" id="enabled" name="enabled" value="1" <?php checked( $editing && $edit_data ? $edit_data['enabled'] : 1, 1 ); ?>>
								<?php esc_html_e( 'Enable this ability immediately after authorization', 'abilities-bridge' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-large">
						<?php
						if ( $editing && $edit_data ) {
							esc_html_e( 'Update Ability', 'abilities-bridge' );
						} else {
							esc_html_e( 'Authorize Ability', 'abilities-bridge' );
						}
						?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render statistics tab
	 */
	private static function render_stats_tab() {
		global $wpdb;

		// Get overall stats.
		$total_executions = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE action LIKE %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_executed_' ) . '%'
			)
		);

		$today_executions = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE action LIKE %s
				 AND DATE(created_at) = %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_executed_' ) . '%',
				current_time( 'Y-m-d' )
			)
		);

		$violations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE action = 'permission_violation'",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS )
			)
		);

		// Get source breakdown.
		$admin_executions = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE action LIKE %s
				 AND source = %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_executed_' ) . '%',
				'admin'
			)
		);

		$mcp_executions = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE action LIKE %s
				 AND source = %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_executed_' ) . '%',
				'mcp'
			)
		);

		// Get top abilities.
		$top_abilities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					REPLACE(action, 'ability_executed_', '') as ability_name,
					COUNT(*) as execution_count,
					MAX(created_at) as last_executed
				 FROM %i
				 WHERE action LIKE %s
				 AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY action
				 ORDER BY execution_count DESC
				 LIMIT 10",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_executed_' ) . '%'
			)
		);

		?>
		<div class="abilities-bridge-stats">
			<h2><?php esc_html_e( 'Usage Statistics', 'abilities-bridge' ); ?></h2>

			<div class="abilities-bridge-stats-grid">
				<div class="stat-card">
					<div class="stat-value"><?php echo esc_html( number_format( $total_executions ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Executions', 'abilities-bridge' ); ?></div>
				</div>

				<div class="stat-card">
					<div class="stat-value"><?php echo esc_html( number_format( $today_executions ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Executions Today', 'abilities-bridge' ); ?></div>
				</div>

				<div class="stat-card stat-warning">
					<div class="stat-value"><?php echo esc_html( number_format( $violations ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Permission Violations', 'abilities-bridge' ); ?></div>
				</div>

			<div class="stat-card stat-info">
				<div class="stat-value"><?php echo esc_html( number_format( $admin_executions ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'Admin Chat Executions', 'abilities-bridge' ); ?></div>
			</div>

			<div class="stat-card stat-info">
				<div class="stat-value"><?php echo esc_html( number_format( $mcp_executions ) ); ?></div>
				<div class="stat-label"><?php esc_html_e( 'MCP (Claude Desktop) Executions', 'abilities-bridge' ); ?></div>
			</div>
			</div>

			<h3><?php esc_html_e( 'Top Abilities (Last 30 Days)', 'abilities-bridge' ); ?></h3>

			<?php if ( empty( $top_abilities ) ) : ?>
				<p><?php esc_html_e( 'No ability executions recorded yet.', 'abilities-bridge' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ability', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Executions', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Last Executed', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_abilities as $ability ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ability->ability_name ); ?></code></td>
								<td><?php echo esc_html( number_format( $ability->execution_count ) ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $ability->last_executed ), time() ) ); ?> ago</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render logs tab
	 */
	private static function render_logs_tab() {
		global $wpdb;

		$paged    = self::get_url_int_param( 'paged', 1 );
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE action LIKE %s OR action = %s
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_' ) . '%',
				'permission_violation',
				$per_page,
				$offset
			)
		);

		$total_logs = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				 WHERE action LIKE %s OR action = %s',
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_LOGS ),
				$wpdb->esc_like( 'ability_' ) . '%',
				'permission_violation'
			)
		);

		$total_pages = ceil( $total_logs / $per_page );

		?>
		<div class="abilities-bridge-logs">
			<h2><?php esc_html_e( 'Audit Logs', 'abilities-bridge' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Complete audit trail of all ability executions and permission checks.', 'abilities-bridge' ); ?>
			</p>

			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No logs recorded yet.', 'abilities-bridge' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped abilities-bridge-logs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'User', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Action', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Ability', 'abilities-bridge' ); ?></th>
							<th><?php esc_html_e( 'Result', 'abilities-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( human_time_diff( strtotime( $log->created_at ), time() ) ); ?> ago</td>
								<td><?php echo esc_html( $log->username ); ?></td>
								<td>
									<?php
									$action_class = 'log-action';
									if ( strpos( $log->action, 'success' ) !== false ) {
										$action_class .= ' log-success';
									} elseif ( strpos( $log->action, 'failed' ) !== false || 'permission_violation' === $log->action ) {
										$action_class .= ' log-error';
									}
									?>
									<span class="<?php echo esc_attr( $action_class ); ?>">
										<?php echo esc_html( str_replace( '_', ' ', ucwords( $log->action, '_' ) ) ); ?>
									</span>
								</td>
								<td><code><?php echo esc_html( $log->function_name ); ?></code></td>
								<td>
									<?php if ( $log->error_message ) : ?>
										<span class="log-error-message"><?php echo esc_html( $log->error_message ); ?></span>
									<?php elseif ( $log->function_output ) : ?>
										<span class="log-output">✓</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav">
						<div class="tablenav-pages">
							<?php
							// Add nonce to pagination links.
							$base_url = wp_nonce_url( add_query_arg( 'paged', '%#%' ), 'abilities_bridge_permissions_nav' );
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => $base_url,
										'format'    => '',
										'prev_text' => __( '&laquo;', 'abilities-bridge' ),
										'next_text' => __( '&raquo;', 'abilities-bridge' ),
										'total'     => (int) $total_pages,
										'current'   => $paged,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle ability registration
	 */
	public static function handle_register_ability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'abilities-bridge' ) );
		}

		check_admin_referer( 'abilities_bridge_register_ability', 'abilities_bridge_nonce' );

		$ability_name        = isset( $_POST['ability_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ability_name'] ) ) : '';
		$enabled             = isset( $_POST['enabled'] ) ? 1 : 0;
		$max_per_day         = isset( $_POST['max_per_day'] ) ? intval( wp_unslash( $_POST['max_per_day'] ) ) : 0;
		$max_per_hour        = isset( $_POST['max_per_hour'] ) ? intval( wp_unslash( $_POST['max_per_hour'] ) ) : 0;
		$risk_level          = isset( $_POST['risk_level'] ) ? sanitize_text_field( wp_unslash( $_POST['risk_level'] ) ) : '';
		$min_capability      = isset( $_POST['min_capability'] ) ? sanitize_text_field( wp_unslash( $_POST['min_capability'] ) ) : '';
		$description         = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$reason_for_approval = isset( $_POST['reason_for_approval'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason_for_approval'] ) ) : '';

		$result = Abilities_Bridge_Ability_Permissions::register_ability(
			$ability_name,
			array(
				'enabled'             => $enabled,
				'max_per_day'         => $max_per_day,
				'max_per_hour'        => $max_per_hour,
				'risk_level'          => $risk_level,
				'min_capability'      => $min_capability,
				'description'         => $description,
				'reason_for_approval' => $reason_for_approval,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'abilities-bridge-permissions',
						'tab'   => 'register',
						'error' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
		} else {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'abilities-bridge-permissions',
						'tab'     => 'list',
						'success' => 'registered',
					),
					admin_url( 'admin.php' )
				)
			);
		}
		exit;
	}

	/**
	 * Handle ability toggle
	 */
	public static function handle_toggle_ability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'abilities-bridge' ) );
		}

		check_admin_referer( 'abilities_bridge_toggle_ability' );

		$ability_name = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( $_POST['ability'] ) ) : '';
		$enable       = isset( $_POST['enable'] ) ? intval( wp_unslash( $_POST['enable'] ) ) : 0;

		if ( $enable ) {
			Abilities_Bridge_Ability_Permissions::enable_ability( $ability_name );
		} else {
			Abilities_Bridge_Ability_Permissions::disable_ability( $ability_name );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'abilities-bridge-permissions',
					'success' => $enable ? 'enabled' : 'disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle ability deletion
	 */
	public static function handle_delete_ability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'abilities-bridge' ) );
		}

		check_admin_referer( 'abilities_bridge_delete_ability' );

		$ability_name = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( $_POST['ability'] ) ) : '';

		global $wpdb;
		$wpdb->delete(
			Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_ABILITY_PERMISSIONS ),
			array( 'ability_name' => $ability_name ),
			array( '%s' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'abilities-bridge-permissions',
					'success' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle enable/disable abilities API toggle
	 */
	public static function handle_toggle_abilities_api() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'abilities-bridge' ) );
		}

		check_admin_referer( 'abilities_bridge_toggle_abilities_api' );

		$enabled               = isset( $_POST['abilities_bridge_enable_abilities_api'] ) && sanitize_text_field( wp_unslash( $_POST['abilities_bridge_enable_abilities_api'] ) ) === '1';
		$consent_submitted     = isset( $_POST['abilities_bridge_abilities_api_consent'] ) && sanitize_text_field( wp_unslash( $_POST['abilities_bridge_abilities_api_consent'] ) ) === '1';
		$consent_already_given = get_option( 'abilities_bridge_abilities_api_consent', false );

		// Validate: If trying to enable, consent must be checked (or already given).
		if ( $enabled && ! $consent_submitted && ! $consent_already_given ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'abilities-bridge-permissions',
						'tab'   => 'list',
						'error' => 'abilities_consent_required',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Persist consent if newly given (one-time consent).
		if ( $consent_submitted && ! $consent_already_given ) {
			update_option( 'abilities_bridge_abilities_api_consent', true );
		}

		// Update abilities API setting.
		update_option( 'abilities_bridge_enable_abilities_api', $enabled );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'abilities-bridge-permissions',
					'tab'     => 'list',
					'success' => $enabled ? 'abilities_enabled' : 'abilities_disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle core abilities enable/disable toggle
	 */
	public static function handle_toggle_core_abilities() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'abilities-bridge' ) );
		}

		check_admin_referer( 'abilities_bridge_toggle_core_abilities', 'toggle_core_abilities_nonce' );

		$core_abilities  = array( 'core/get-site-info', 'core/get-user-info', 'core/get-environment-info' );
		$all_permissions = Abilities_Bridge_Ability_Permissions::get_all_permissions( false );
		$changes_made    = array();

		foreach ( $core_abilities as $ability_name ) {
			$field_name = 'core_ability_' . $ability_name;

			if ( isset( $_POST[ $field_name ] ) ) {
				$enable = intval( wp_unslash( $_POST[ $field_name ] ) );

				// Check if ability is already registered.
				$permission = null;
				foreach ( $all_permissions as $perm ) {
					if ( $perm['ability_name'] === $ability_name ) {
						$permission = $perm;
						break;
					}
				}

				if ( ! $permission ) {
					// Auto-register with safe defaults if not exists.
					Abilities_Bridge_Ability_Permissions::register_ability(
						$ability_name,
						array(
							'enabled'             => $enable,
							'max_per_day'         => 100,
							'max_per_hour'        => 50,
							'risk_level'          => 'low',
							'min_capability'      => null,
							'description'         => self::get_core_ability_description( $ability_name ),
							'reason_for_approval' => 'Core read-only ability enabled via UI',
						)
					);
					$changes_made[] = $ability_name . ' registered and ' . ( $enable ? 'enabled' : 'disabled' );
				} elseif ( $enable && ! $permission['enabled'] ) {
					// Enable existing ability.
					Abilities_Bridge_Ability_Permissions::enable_ability( $ability_name );
					$changes_made[] = $ability_name . ' enabled';
				} elseif ( ! $enable && $permission['enabled'] ) {
					// Disable existing ability.
					Abilities_Bridge_Ability_Permissions::disable_ability( $ability_name );
					$changes_made[] = $ability_name . ' disabled';
				}
			}
		}

		$message = ! empty( $changes_made ) ? 'core_abilities_updated' : 'no_changes';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'abilities-bridge-permissions',
					'tab'     => 'list',
					'success' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Get description for core abilities.
	 *
	 * @param string $ability_name The ability name.
	 * @return string The ability description.
	 */
	private static function get_core_ability_description( $ability_name ) {
		$descriptions = array(
			'core/get-site-info'        => 'Get WordPress site information',
			'core/get-user-info'        => 'Get current user information',
			'core/get-environment-info' => 'Get server environment information',
		);
		return isset( $descriptions[ $ability_name ] ) ? $descriptions[ $ability_name ] : 'Core read-only ability';
	}
}

// Initialize admin interface.
Abilities_Bridge_Ability_Permissions_Admin::init();
