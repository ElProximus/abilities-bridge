<?php
/**
 * Dashboard partial template
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="abilities-bridge-dashboard-content">
	<div class="card abilities-bridge-model-selector-card">
		<h2><?php esc_html_e( 'Model Selection', 'abilities-bridge' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Select which model to use. Changing models will start a new conversation.', 'abilities-bridge' ); ?>
		</p>

		<?php
		$abilities_bridge_providers        = Abilities_Bridge_AI_Provider::get_providers();
		$abilities_bridge_selected_provider = Abilities_Bridge_AI_Provider::get_current_provider();
		$abilities_bridge_provider_label   = Abilities_Bridge_AI_Provider::get_provider_label( $abilities_bridge_selected_provider );
		$abilities_bridge_available_models = Abilities_Bridge_AI_Provider::get_available_models( $abilities_bridge_selected_provider );
		$abilities_bridge_selected_model   = Abilities_Bridge_AI_Provider::get_selected_model( $abilities_bridge_selected_provider );
		?>

		<div class="abilities-bridge-model-selector" style="margin-bottom: 12px;">
			<label for="abilities-bridge-provider-select">
				<strong><?php esc_html_e( 'AI Provider:', 'abilities-bridge' ); ?></strong>
			</label>
			<select id="abilities-bridge-provider-select" class="regular-text">
				<?php foreach ( $abilities_bridge_providers as $provider_key => $provider_label ) : ?>
					<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $abilities_bridge_selected_provider, $provider_key ); ?>>
						<?php echo esc_html( $provider_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="abilities-bridge-model-info" style="margin-top: 8px; font-size: 12px; color: #666;">
				<?php esc_html_e( 'Choose which provider powers the admin chat. MCP integrations are unaffected.', 'abilities-bridge' ); ?>
			</p>
		</div>

		<div class="abilities-bridge-model-selector">
			<label for="abilities-bridge-model-select">
				<strong><?php esc_html_e( 'Current Model:', 'abilities-bridge' ); ?></strong>
			</label>
			<select id="abilities-bridge-model-select" class="regular-text">
				<?php foreach ( $abilities_bridge_available_models as $abilities_bridge_model_id => $abilities_bridge_model_name ) : ?>
					<option value="<?php echo esc_attr( $abilities_bridge_model_id ); ?>" <?php selected( $abilities_bridge_selected_model, $abilities_bridge_model_id ); ?>>
						<?php echo esc_html( $abilities_bridge_model_name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="abilities-bridge-model-info" style="margin-top: 10px; font-size: 12px; color: #666;">
				<span id="abilities-bridge-model-description"></span>
				<span class="abilities-bridge-model-provider">
					<?php echo esc_html( ' · ' . $abilities_bridge_provider_label ); ?>
				</span>
			</p>
		</div>
	</div>

	<div class="card">
		<h2><?php esc_html_e( 'Conversation Management', 'abilities-bridge' ); ?></h2>

		<div class="abilities-bridge-conversation-controls">
			<button type="button" class="button button-primary" id="abilities-bridge-new-conversation">
				<?php esc_html_e( 'New Conversation', 'abilities-bridge' ); ?>
			</button>

			<div class="abilities-bridge-resume-conversation">
				<label for="abilities-bridge-conversation-select">
					<?php esc_html_e( 'Resume Conversation:', 'abilities-bridge' ); ?>
					<button type="button" class="button-link" id="abilities-bridge-refresh-conversations" style="margin-left: 10px; font-size: 12px;">
						<?php esc_html_e( '↻ Refresh', 'abilities-bridge' ); ?>
					</button>
				</label>
				<select id="abilities-bridge-conversation-select" class="regular-text" disabled>
					<option value=""><?php esc_html_e( '⏳ Loading conversations...', 'abilities-bridge' ); ?></option>
				</select>
			</div>

			<button type="button" class="button button-link-delete" id="abilities-bridge-delete-conversation" style="display: none;">
				<?php esc_html_e( 'Delete Conversation', 'abilities-bridge' ); ?>
			</button>
		</div>

		<div id="abilities-bridge-conversation-info" style="margin-top: 15px; display: none;">
			<p>
				<strong><?php esc_html_e( 'Active Conversation:', 'abilities-bridge' ); ?></strong>
				<span id="abilities-bridge-conversation-title"></span>
			</p>
		</div>
	</div>

	<div class="card">
		<h2><?php esc_html_e( 'System Prompt', 'abilities-bridge' ); ?></h2>
		<p>
			<?php esc_html_e( 'Customize how the AI behaves and responds by editing the system prompt in Settings.', 'abilities-bridge' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=abilities-bridge-settings' ) ); ?>" class="button">
				<?php esc_html_e( 'Edit System Prompt', 'abilities-bridge' ); ?>
			</a>
		</p>
	</div>
</div>
