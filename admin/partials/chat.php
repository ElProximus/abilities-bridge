<?php
/**
 * Chat interface partial template
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="abilities-bridge-chat-content">
	<div class="abilities-bridge-token-meter" id="abilities-bridge-token-meter">
		<div class="token-meter-bar">
			<div class="token-meter-fill" style="width: 0%"></div>
		</div>
		<div class="token-meter-info">
			<span class="token-count">0 tokens used</span>
			<span class="token-limit-info">No limit enforced</span>
		</div>
	</div>

	<div class="abilities-bridge-chat-messages" id="abilities-bridge-chat-messages">
		<div class="abilities-bridge-welcome-message">
			<div class="abilities-bridge-message abilities-bridge-message-assistant">
				<div class="abilities-bridge-message-content">
					<p><?php esc_html_e( 'Hi, I\'m your AI assistant. How can I help you today?', 'abilities-bridge' ); ?></p>
				</div>
			</div>
		</div>
	</div>

	<div class="abilities-bridge-chat-loading" id="abilities-bridge-chat-loading" style="display: none;">
		<div class="abilities-bridge-loading-spinner"></div>
		<span><?php esc_html_e( 'AI is thinking...', 'abilities-bridge' ); ?></span>
	</div>

	<div class="abilities-bridge-activity-history" id="abilities-bridge-activity-history" style="display: none;">
		<div class="activity-history-header" id="activity-history-toggle">
			<span class="activity-history-title">
				📋 <span id="activity-history-label"><?php esc_html_e( 'Activity History', 'abilities-bridge' ); ?></span> (<span id="activity-count">0</span> <?php esc_html_e( 'tools used', 'abilities-bridge' ); ?>)
			</span>
			<span class="activity-history-arrow">▼</span>
		</div>
		<div class="activity-history-content" id="activity-history-content">
			<div id="activity-history-list">
				<p class="activity-history-empty"><?php esc_html_e( 'No activity yet...', 'abilities-bridge' ); ?></p>
			</div>
		</div>
	</div>

	<div class="abilities-bridge-chat-input-container">
		<form id="abilities-bridge-chat-form">
			<?php $image_attachments_enabled = Abilities_Bridge_Attachments::is_enabled(); ?>
			<textarea
				id="abilities-bridge-chat-input"
				name="message"
				rows="3"
				placeholder="<?php esc_attr_e( 'Type your message here...', 'abilities-bridge' ); ?>"
				<?php echo $image_attachments_enabled ? '' : 'required'; ?>
			></textarea>
			<?php if ( $image_attachments_enabled ) : ?>
				<div id="abilities-bridge-chat-attachment-panel" class="abilities-bridge-attachment-panel">
					<input type="file" id="abilities-bridge-chat-image-input" class="abilities-bridge-attachment-file-input" multiple accept="image/jpeg,image/png,image/webp">
					<div class="abilities-bridge-attachment-toolbar">
						<button type="button" class="button" id="abilities-bridge-chat-upload-image">
							<?php esc_html_e( 'Upload image', 'abilities-bridge' ); ?>
						</button>
						<button type="button" class="button" id="abilities-bridge-chat-capture-screenshot">
							<?php esc_html_e( 'Screenshot', 'abilities-bridge' ); ?>
						</button>
						<span id="abilities-bridge-chat-attachment-status" class="abilities-bridge-attachment-status" aria-live="polite"></span>
					</div>
					<div id="abilities-bridge-chat-attachment-preview" class="abilities-bridge-attachment-preview-list"></div>
				</div>
			<?php endif; ?>
			<div class="abilities-bridge-chat-actions">
				<button type="submit" class="button button-primary" id="abilities-bridge-send-button">
					<?php esc_html_e( 'Send Message', 'abilities-bridge' ); ?>
				</button>
				<button type="button" class="button" id="abilities-bridge-stop-button" style="display: none;">
					<?php esc_html_e( '⏹ Stop', 'abilities-bridge' ); ?>
				</button>
				<span class="abilities-bridge-chat-hint">
					<?php esc_html_e( 'Shift + Enter for new line', 'abilities-bridge' ); ?>
				</span>
			</div>
		</form>
	</div>
</div>
