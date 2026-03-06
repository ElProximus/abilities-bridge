<?php
/**
 * Welcome Wizard / Consent Screen Template
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap abilities-bridge-welcome-wizard">

	<div class="welcome-wizard-card">
		<h1>
			<?php
			if ( $is_reconsent ) {
				esc_html_e( 'Abilities Bridge - Updated Terms & Consent', 'abilities-bridge' );
			} else {
				esc_html_e( 'Abilities Bridge - Setup & Consent', 'abilities-bridge' );
			}
			?>
		</h1>

		<?php if ( $is_reconsent ) : ?>
			<p style="font-size: 16px; margin: 20px 0;">
				<?php
				printf(
					/* translators: %s: version number */
					esc_html__( 'Abilities Bridge has been updated to version %s. Please review the changes and provide consent to continue using the plugin.', 'abilities-bridge' ),
					'<strong>' . esc_html( ABILITIES_BRIDGE_VERSION ) . '</strong>'
				);
				?>
			</p>
		<?php else : ?>
			<p style="font-size: 16px; margin: 20px 0;">
				<?php esc_html_e( 'Before using this plugin, please review the following information and provide your consent.', 'abilities-bridge' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $changelog ) ) : ?>
			<div class="changelog-section">
				<h3>
					<?php
					printf(
						/* translators: %s: version number */
						esc_html__( 'What\'s New in Version %s', 'abilities-bridge' ),
						esc_html( ABILITIES_BRIDGE_VERSION )
					);
					?>
				</h3>
				<?php echo wp_kses_post( $changelog ); ?>
			</div>
		<?php endif; ?>

		<!-- AI Access & Permission System -->
		<div class="wizard-section">
			<h2><?php esc_html_e( 'AI Access & Permission System', 'abilities-bridge' ); ?></h2>

			<h3><?php esc_html_e( 'Memory (Optional):', 'abilities-bridge' ); ?></h3>
			<p><?php esc_html_e( 'Store persistent memories in the database across conversations. This feature requires separate consent in Settings and is disabled by default.', 'abilities-bridge' ); ?></p>

			<h3><?php esc_html_e( 'WordPress Abilities API:', 'abilities-bridge' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Each ability has granular permission controls', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You set: enable/disable, rate limits, approval requirements, risk levels', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Default abilities: get-site-info, get-user-info, get-environment-info', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Other plugins can register additional abilities you manage', 'abilities-bridge' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Your Control:', 'abilities-bridge' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Configure ability permissions in the Ability Permissions page', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Review all AI actions in the Activity Log', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'All AI actions are logged with full audit trails for transparency', 'abilities-bridge' ); ?></li>
			</ul>

			<p><strong><?php esc_html_e( 'Important:', 'abilities-bridge' ); ?></strong> <?php esc_html_e( 'You are responsible for reviewing and setting appropriate permissions based on your security requirements.', 'abilities-bridge' ); ?></p>
		</div>

		<!-- Responsibility & Risk -->
		<div class="wizard-section">
			<h2><?php esc_html_e( 'Your Responsibility & Risk Acknowledgment', 'abilities-bridge' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'You assume all responsibility for enabling AI access to your WordPress installation', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You are responsible for configuring tool and ability permissions appropriately', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You are responsible for reviewing AI activity logs and access patterns', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You acknowledge the inherent risks of providing AI access to your data', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You are responsible for protecting your AI provider API key', 'abilities-bridge' ); ?></li>
			</ul>
		</div>

		<!-- API and Subscription Costs & Billing -->
		<div class="wizard-section">
			<h2><?php esc_html_e( 'API and Subscription Costs & Billing', 'abilities-bridge' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'This plugin requires an Anthropic or OpenAI API key for the admin chat interface, or a Claude account (Free or Pro) for MCP integration', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'API and subscription usage may incur costs charged by your AI provider (not by this plugin)', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'You are solely responsible for API and subscription usage and associated billing charges', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'For billing issues, questions, or disputes, contact your AI provider directly', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'The makers of this plugin are NOT responsible for your API and subscription usage costs or billing', 'abilities-bridge' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Cost Optimization Features:', 'abilities-bridge' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Prompt caching enabled (90% cost reduction on repeated content)', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Smart token estimation and usage tracking', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Rate limiting controls available in Ability Permissions', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Usage statistics dashboard to monitor costs', 'abilities-bridge' ); ?></li>
			</ul>
		</div>

		<!-- Security & Best Practices -->
		<div class="wizard-section">
			<h2><?php esc_html_e( 'Security & Best Practices', 'abilities-bridge' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Review and configure permissions before enabling tools', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Use only on development/staging sites initially', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Review activity logs regularly', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Set conservative rate limits for abilities', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Never share your API key', 'abilities-bridge' ); ?></li>
				<li><?php esc_html_e( 'Disable tools/abilities when not needed', 'abilities-bridge' ); ?></li>
			</ul>
		</div>

		<!-- Consent Form -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="consent-form">
			<?php wp_nonce_field( 'abilities_bridge_consent', 'abilities_bridge_consent_nonce' ); ?>
			<input type="hidden" name="action" value="abilities_bridge_submit_consent">

			<div class="consent-checkboxes">
				<h3><?php esc_html_e( 'Consent & Acknowledgment:', 'abilities-bridge' ); ?></h3>

				<div class="consent-checkbox-item">
					<input type="checkbox" name="consent_permissions" id="consent_permissions" value="1" required>
					<label for="consent_permissions">
						<?php esc_html_e( 'I understand this plugin provides AI access through memory and abilities I control, and I am responsible for configuring appropriate permission levels for my security needs. I assume all responsibility and risk for enabling AI access to my site.', 'abilities-bridge' ); ?>
					</label>
				</div>

				<div class="consent-checkbox-item">
					<input type="checkbox" name="consent_billing" id="consent_billing" value="1" required>
					<label for="consent_billing">
						<?php esc_html_e( 'I understand API and subscription costs are my responsibility and billed by my AI provider directly. The makers of this plugin are NOT responsible for API usage costs or billing.', 'abilities-bridge' ); ?>
					</label>
				</div>

				<div class="consent-checkbox-item">
					<input type="checkbox" name="consent_understanding" id="consent_understanding" value="1" required>
					<label for="consent_understanding">
						<?php
						if ( $is_reconsent ) {
							printf(
								/* translators: %s: version number */
								esc_html__( 'I have read and understand the security information and best practices above, including the changes in version %s.', 'abilities-bridge' ),
								esc_html( ABILITIES_BRIDGE_VERSION )
							);
						} else {
							esc_html_e( 'I have read and understand the security information and best practices above.', 'abilities-bridge' );
						}
						?>
					</label>
				</div>
			</div>

			<div class="wizard-actions">
				<button type="submit" class="button button-primary" id="submit-consent-btn" disabled>
					<?php
					if ( $is_reconsent ) {
						esc_html_e( 'Update Consent & Continue', 'abilities-bridge' );
					} else {
						esc_html_e( 'Complete Setup & Continue', 'abilities-bridge' );
					}
					?>
				</button>
			</div>
		</form>
	</div>
</div>
