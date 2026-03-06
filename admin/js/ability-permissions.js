/**
 * Abilities Bridge - Ability Permissions Admin JavaScript
 *
 * @package Abilities_Bridge
 */

(function($) {
	'use strict';

	$(document).ready(function() {

		// Details modal functionality
		var $modal = $('#ability-details-modal');
		var $modalContent = $('#ability-details-content');
		var $closeBtn = $('.abilities-bridge-modal-close');

		// Show details modal
		$(document).on('click', '.button-info', function(e) {
			e.preventDefault();

			var abilityData = $(this).data('ability');

			if (!abilityData) {
				return;
			}

			// Build details HTML
			var html = '<dl>';
			html += '<dt>Ability Name</dt><dd><code>' + escapeHtml(abilityData.ability_name) + '</code></dd>';
			html += '<dt>Risk Level</dt><dd><span class="risk-badge risk-' + escapeHtml(abilityData.risk_level) + '">' + escapeHtml(abilityData.risk_level.toUpperCase()) + '</span></dd>';
			html += '<dt>Status</dt><dd>' + (abilityData.enabled ? '<span class="status-badge status-enabled">✓ Enabled</span>' : '<span class="status-badge status-disabled">✗ Disabled</span>') + '</dd>';
			html += '<dt>Max Per Day</dt><dd>' + escapeHtml(abilityData.max_per_day) + '</dd>';
			html += '<dt>Max Per Hour</dt><dd>' + escapeHtml(abilityData.max_per_hour) + '</dd>';

			if (abilityData.min_capability) {
				html += '<dt>Min Capability</dt><dd><code>' + escapeHtml(abilityData.min_capability) + '</code></dd>';
			}

			html += '<dt>Description</dt><dd>' + escapeHtml(abilityData.description) + '</dd>';
			html += '<dt>Reason for Approval</dt><dd>' + escapeHtml(abilityData.reason_for_approval) + '</dd>';
			html += '<dt>Total Executions</dt><dd>' + escapeHtml(abilityData.execution_count) + '</dd>';

			if (abilityData.last_executed) {
				html += '<dt>Last Executed</dt><dd>' + escapeHtml(abilityData.last_executed) + '</dd>';
			}

			if (abilityData.approved_by_user_id) {
				html += '<dt>Approved By</dt><dd>User ID ' + escapeHtml(abilityData.approved_by_user_id) + ' on ' + escapeHtml(abilityData.approved_date) + '</dd>';
			}

			html += '</dl>';

			$modalContent.html(html);
			$modal.fadeIn(200);
		});

		// Close modal on close button
		$closeBtn.on('click', function() {
			$modal.fadeOut(200);
		});

		// Close modal on outside click
		$modal.on('click', function(e) {
			if (e.target === this) {
				$modal.fadeOut(200);
			}
		});

		// Close modal on escape key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $modal.is(':visible')) {
				$modal.fadeOut(200);
			}
		});

		// Confirm dangerous actions
		$('.button-disable').on('click', function(e) {
			if (!confirm('Are you sure you want to disable this ability? The AI will no longer be able to execute it.')) {
				e.preventDefault();
			}
		});

		// Confirm delete with strong warning
		$('.button-link-delete').on('click', function(e) {
			var confirmMsg = 'WARNING: Permanently delete this ability?\n\n';
			confirmMsg += 'This will:\n';
			confirmMsg += '• Remove all approval history\n';
			confirmMsg += '• Delete execution statistics\n';
			confirmMsg += '• Cannot be undone\n\n';
			confirmMsg += 'The ability must be re-registered and re-approved from scratch.\n\n';
			confirmMsg += 'Are you absolutely sure?';

			if (!confirm(confirmMsg)) {
				e.preventDefault();
			}
		});

		// Form validation
		$('.abilities-bridge-ability-form').on('submit', function(e) {
			var $form = $(this);
			var abilityName = $form.find('#ability_name').val().trim();
			var maxPerDay = parseInt($form.find('#max_per_day').val());
			var description = $form.find('#description').val().trim();

			var errors = [];
			var availableAbilities = (window.abilitiesBridgeAbilities && window.abilitiesBridgeAbilities.availableAbilities) ? window.abilitiesBridgeAbilities.availableAbilities : [];
			var abilitiesApiAvailable = window.abilitiesBridgeAbilities && window.abilitiesBridgeAbilities.abilitiesApiAvailable;

			// Validate ability name format
			if (!abilityName.match(/^[a-z0-9\-]+\/[a-z0-9\-]+$/)) {
				errors.push('Ability name must be in format "category/name" (e.g., "core/create-post")');
			}

			if (abilitiesApiAvailable && Array.isArray(availableAbilities) && availableAbilities.length > 0) {
				if (availableAbilities.indexOf(abilityName) === -1) {
					errors.push('Ability name not found in WordPress. Select a registered ability or check the spelling.');
				}
			}

			// Validate rate limits
			if (maxPerDay < 0 || maxPerDay > 10000) {
				errors.push('Max per day must be between 0 and 10000');
			}

			// Validate descriptions
			if (description.length < 10) {
				errors.push('Description must be at least 10 characters');
			}


			if (errors.length > 0) {
				e.preventDefault();
				alert('Please fix the following errors:\n\n• ' + errors.join('\n• '));
				return false;
			}

			// Confirm registration for high-risk abilities
			var riskLevel = $form.find('#risk_level').val();
			if (riskLevel === 'high' || riskLevel === 'critical') {
				var confirmMsg = 'You are registering a ' + riskLevel.toUpperCase() + ' risk ability.\n\n';
				confirmMsg += 'Ability: ' + abilityName + '\n';
				confirmMsg += 'Max executions: ' + maxPerDay + '/day\n\n';
				confirmMsg += 'Are you sure you want to proceed?';

				if (!confirm(confirmMsg)) {
					e.preventDefault();
					return false;
				}
			}
		});

		// Auto-suggest risk level based on ability name
		$('#ability_name').on('blur', function() {
			var name = $(this).val().toLowerCase();
			var $riskSelect = $('#risk_level');

			// Don't override if already selected
			if ($riskSelect.val()) {
				return;
			}

			// Auto-suggest based on common patterns
			if (name.includes('get') || name.includes('list') || name.includes('read') || name.includes('view')) {
				$riskSelect.val('low');
			} else if (name.includes('update') || name.includes('edit') || name.includes('modify')) {
				$riskSelect.val('medium');
			} else if (name.includes('create') || name.includes('insert') || name.includes('add')) {
				$riskSelect.val('high');
			} else if (name.includes('delete') || name.includes('remove') || name.includes('destroy')) {
				$riskSelect.val('critical');
			}
		});

		// Risk level change warning
		$('#risk_level').on('change', function() {
			var risk = $(this).val();
			var $maxPerDay = $('#max_per_day');

			// Suggest conservative limits based on risk
			switch(risk) {
				case 'low':
					if (!$maxPerDay.val() || $maxPerDay.val() < 100) {
						$maxPerDay.val(1000);
					}
					break;
				case 'medium':
					if (!$maxPerDay.val() || $maxPerDay.val() > 50) {
						$maxPerDay.val(50);
					}
					break;
				case 'high':
					if (!$maxPerDay.val() || $maxPerDay.val() > 10) {
						$maxPerDay.val(10);
					}
					break;
				case 'critical':
					if (!$maxPerDay.val() || $maxPerDay.val() > 1) {
						$maxPerDay.val(1);
					}
					break;
			}
		});

		// Show success/error messages
		var urlParams = new URLSearchParams(window.location.search);
		var success = urlParams.get('success');
		var error = urlParams.get('error');

		if (success) {
			var message = '';
			switch(success) {
				case 'registered':
					message = 'Ability authorized successfully!';
					break;
				case 'enabled':
					message = 'Ability enabled successfully!';
					break;
				case 'disabled':
					message = 'Ability disabled successfully!';
					break;
				case 'deleted':
					message = 'Ability deleted successfully!';
					break;
			}
			if (message) {
				showNotice(message, 'success');
			}
		}

		if (error) {
			showNotice(decodeURIComponent(error), 'error');
		}

		// Helper function to show notices
		function showNotice(message, type) {
			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
			$('.abilities-bridge-permissions-wrap h1').after($notice);

			// Auto-dismiss after 5 seconds
			setTimeout(function() {
				$notice.fadeOut(400, function() {
					$(this).remove();
				});
			}, 5000);
		}

		// Helper function to escape HTML
		function escapeHtml(text) {
			if (typeof text !== 'string') {
				return text;
			}
			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, function(m) { return map[m]; });
		}

		// Live search/filter for abilities table
		var $searchInput = $('<input type="text" class="regular-text" placeholder="Search abilities..." style="margin-bottom: 10px;">');
		$('.abilities-bridge-abilities-table').before($searchInput);

		$searchInput.on('keyup', function() {
			var searchTerm = $(this).val().toLowerCase();

			$('.abilities-bridge-abilities-table tbody tr').each(function() {
				var $row = $(this);
				var text = $row.text().toLowerCase();

				if (text.indexOf(searchTerm) > -1) {
					$row.show();
				} else {
					$row.hide();
				}
			});
		});

	// Check if consent was already given (from PHP)
	var consentAlreadyGiven = window.abilitiesBridgeAbilities && window.abilitiesBridgeAbilities.abilitiesConsentGiven;

	// If consent not already given, ensure checkbox starts unchecked
	if (!consentAlreadyGiven) {
		$('#abilities_bridge_abilities_api_consent').prop('checked', false);
	}

	// Abilities API Enable/Disable toggle
	$('#abilities_bridge_enable_abilities_api').on('change', function() {
		var isEnabled = $(this).is(':checked');
		var $container = $('.abilities-bridge-permissions-list');
		var $consentContainer = $('#abilities-api-consent-container');

		if (isEnabled) {
			$container.removeClass('abilities-disabled');
			// Only show consent container if consent not already given
			if (!consentAlreadyGiven) {
				$consentContainer.slideDown();
				$('#abilities_bridge_abilities_api_consent').prop('checked', false);
			}
		} else {
			$container.addClass('abilities-disabled');
			$consentContainer.slideUp();
			if (!consentAlreadyGiven) {
				$('#abilities_bridge_abilities_api_consent').prop('checked', false);
			}
		}

		// Check if save button should be disabled
		checkAbilitiesConsent();
	});

		// Abilities API Consent validation
		$('#abilities_bridge_abilities_api_consent').on('change', checkAbilitiesConsent);

		function checkAbilitiesConsent() {
			var isEnabled = $('#abilities_bridge_enable_abilities_api').is(':checked');
			var consentChecked = $('#abilities_bridge_abilities_api_consent').is(':checked');
			var $saveButton = $('#abilities-api-save-button');

			// Disable save button if abilities enabled but no consent (either checked now or already given)
			if (isEnabled && !consentChecked && !consentAlreadyGiven) {
				$saveButton.prop('disabled', true);
			} else {
				$saveButton.prop('disabled', false);
			}
		}

		// Run validation on page load
		checkAbilitiesConsent();
	});

})(jQuery);
