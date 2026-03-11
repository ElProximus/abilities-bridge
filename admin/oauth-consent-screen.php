<?php
/**
 * OAuth Consent Screen Template
 *
 * Displays a user-friendly consent screen for OAuth 2.0 authorization requests.
 *
 * @package Abilities_Bridge
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register and enqueue OAuth consent assets for proper WordPress handling.
wp_enqueue_style(
	'abilities-bridge-oauth-consent',
	ABILITIES_BRIDGE_PLUGIN_URL . 'admin/css/oauth-consent.css',
	array(),
	ABILITIES_BRIDGE_VERSION
);

wp_enqueue_script(
	'abilities-bridge-oauth-consent',
	ABILITIES_BRIDGE_PLUGIN_URL . 'admin/js/oauth-consent.js',
	array(),
	ABILITIES_BRIDGE_VERSION,
	true
);

// Variables available from handle_authorize_request():.
// $client_id, $redirect_uri, $response_type, $code_challenge,
// $code_challenge_method, $state, $scope.

// Get client information.
$abilities_bridge_oauth_data = get_option( 'abilities_bridge_mcp_oauth', array() );
$abilities_bridge_client     = isset( $abilities_bridge_oauth_data['clients'][ $client_id ] ) ? $abilities_bridge_oauth_data['clients'][ $client_id ] : null;

// Default app name if client not found.
$abilities_bridge_app_name = 'Unknown Application';
$abilities_bridge_profile  = Abilities_Bridge_OAuth_Client_Manager::PROFILE_ANTHROPIC;
if ( $abilities_bridge_client ) {
	if ( isset( $abilities_bridge_client['name'] ) ) {
		$abilities_bridge_app_name = $abilities_bridge_client['name'];
	}
	if ( isset( $abilities_bridge_client['profile'] ) ) {
		$abilities_bridge_profile = Abilities_Bridge_OAuth_Client_Manager::normalize_profile( $abilities_bridge_client['profile'] );
	}
} else {
	$abilities_bridge_parsed_uri = wp_parse_url( $redirect_uri );
	if ( isset( $abilities_bridge_parsed_uri['host'] ) && strpos( $abilities_bridge_parsed_uri['host'], 'claude.ai' ) !== false ) {
		$abilities_bridge_app_name = 'Claude Desktop';
	}
}

$abilities_bridge_site_label = self_admin_url() ? home_url( '/' ) : home_url( '/' );

// Parse requested scopes.
$abilities_bridge_requested_scopes = ! empty( $scope ) ? explode( ' ', $scope ) : array( 'mcp' );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Authorize Application', 'abilities-bridge' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<?php wp_print_styles( 'abilities-bridge-oauth-consent' ); ?>
</head>
<body>
	<div class="oauth-container">
		<div class="oauth-header">
			<h1><?php esc_html_e( 'Authorize Application', 'abilities-bridge' ); ?></h1>
			<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		</div>

		<div class="oauth-body">
			<div class="app-info">
				<div class="app-name"><?php echo esc_html( $abilities_bridge_app_name ); ?></div>
				<div class="app-description">
					<?php
					printf(
						/* translators: %s: application name */
						esc_html__( '%s is requesting access to your WordPress site via Abilities Bridge', 'abilities-bridge' ),
						'<strong>' . esc_html( $abilities_bridge_app_name ) . '</strong>'
					);
					?>
				</div>
			</div>

			<div class="permissions-section">
				<p><strong><?php esc_html_e( 'Provider Profile:', 'abilities-bridge' ); ?></strong> <?php echo esc_html( Abilities_Bridge_OAuth_Client_Manager::get_profile_label( $abilities_bridge_profile ) ); ?></p>
				<p><strong><?php esc_html_e( 'Default Site:', 'abilities-bridge' ); ?></strong> <?php echo esc_html( home_url( '/' ) ); ?></p>
				<h2><?php esc_html_e( 'This application will be able to:', 'abilities-bridge' ); ?></h2>
				<ul class="permission-list">
					<li class="permission-item">
						<div class="permission-icon">[OK]</div>
						<div class="permission-text">
							<div class="permission-title"><?php esc_html_e( 'Store Context Data', 'abilities-bridge' ); ?></div>
							<div class="permission-description"><?php esc_html_e( 'Create and manage memory entries in the database for maintaining conversation context', 'abilities-bridge' ); ?></div>
						</div>
					</li>
					<li class="permission-item">
						<div class="permission-icon">[OK]</div>
						<div class="permission-text">
							<div class="permission-title"><?php esc_html_e( 'Execute WordPress Abilities', 'abilities-bridge' ); ?></div>
							<div class="permission-description"><?php esc_html_e( 'Run authorized WordPress Abilities API functions registered by plugins', 'abilities-bridge' ); ?></div>
						</div>
					</li>
				</ul>
			</div>

			<div class="security-notice">
				<div class="security-notice-title"><?php esc_html_e( 'Security Information', 'abilities-bridge' ); ?></div>
				<p><?php esc_html_e( 'This application uses OAuth 2.0 with PKCE for secure authentication. Your WordPress admin credentials will not be shared with the application. You can revoke access at any time from your WordPress admin panel, and each MCP client is scoped to its configured provider profile.', 'abilities-bridge' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( rest_url( 'abilities-bridge-mcp/v1/authorize' ) ); ?>">
				<?php wp_nonce_field( 'abilities_bridge_oauth_authorize', 'oauth_nonce' ); ?>
				<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
				<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
				<input type="hidden" name="response_type" value="<?php echo esc_attr( $response_type ); ?>">
				<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
				<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
				<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
				<input type="hidden" name="scope" value="<?php echo esc_attr( implode( ' ', $abilities_bridge_requested_scopes ) ); ?>">
				<input type="hidden" name="approved" id="approved-field" value="">

				<div class="action-buttons">
					<button type="submit" data-approved="no" class="btn btn-secondary">
						<?php esc_html_e( 'Deny', 'abilities-bridge' ); ?>
					</button>
					<button type="submit" data-approved="yes" class="btn btn-primary">
						<?php esc_html_e( 'Authorize', 'abilities-bridge' ); ?>
					</button>
				</div>
			</form>

			<?php wp_print_scripts( 'abilities-bridge-oauth-consent' ); ?>

			<details class="technical-details">
				<summary><?php esc_html_e( 'Technical Details', 'abilities-bridge' ); ?></summary>
				<dl class="technical-details-content">
					<dt><?php esc_html_e( 'Client ID:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( $client_id ); ?></dd>

					<dt><?php esc_html_e( 'Redirect URI:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( $redirect_uri ); ?></dd>

					<dt><?php esc_html_e( 'Response Type:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( $response_type ); ?></dd>

					<dt><?php esc_html_e( 'PKCE Method:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( $code_challenge_method ); ?></dd>

					<dt><?php esc_html_e( 'Requested Scopes:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( implode( ', ', $abilities_bridge_requested_scopes ) ); ?></dd>

					<?php if ( ! empty( $state ) ) : ?>
					<dt><?php esc_html_e( 'State:', 'abilities-bridge' ); ?></dt>
					<dd><?php echo esc_html( substr( $state, 0, 20 ) . '...' ); ?></dd>
					<?php endif; ?>
				</dl>
			</details>
		</div>
	</div>
</body>
</html>

