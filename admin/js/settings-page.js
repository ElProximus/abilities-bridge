/**
 * Settings Page and Welcome Wizard JavaScript
 *
 * @package Abilities_Bridge
 */

(function($) {
	'use strict';

	/**
	 * API Key Consent Handler
	 * Handles showing/hiding consent box when API key changes
	 */
	function initApiKeyConsent() {
		var $anthropicKeyInput = $('#abilities_bridge_api_key');
		var $anthropicConsentBox = $('#anthropic-api-key-consent-box');
		var $openaiKeyInput = $('#abilities_bridge_openai_api_key');
		var $openaiConsentBox = $('#openai-api-key-consent-box');
		var $submitButton = $('#submit');

		if (!$anthropicKeyInput.length && !$openaiKeyInput.length) {
			return;
		}

		var originalAnthropicKey = $anthropicKeyInput.length ? $anthropicKeyInput.val() : '';
		var originalOpenaiKey = $openaiKeyInput.length ? $openaiKeyInput.val() : '';

		function updateConsentState() {
			var anthropicChanged = $anthropicKeyInput.length && $anthropicKeyInput.val() && $anthropicKeyInput.val() !== originalAnthropicKey;
			var openaiChanged = $openaiKeyInput.length && $openaiKeyInput.val() && $openaiKeyInput.val() !== originalOpenaiKey;

			var anthropicConsented = !anthropicChanged || $('.api-key-consent-checkbox:checked').length === 2;
			var openaiConsented = !openaiChanged || $('.api-key-consent-checkbox-openai:checked').length === 2;

			$submitButton.prop('disabled', !(anthropicConsented && openaiConsented));
		}

		if ($anthropicKeyInput.length && $anthropicConsentBox.length) {
			$anthropicKeyInput.on('input', function() {
				var currentKey = $(this).val();
				if (currentKey && currentKey !== originalAnthropicKey) {
					$anthropicConsentBox.show();
					updateConsentState();
				} else {
					$anthropicConsentBox.hide();
					$('.api-key-consent-checkbox').prop('checked', false);
					updateConsentState();
				}
			});
		}

		if ($openaiKeyInput.length && $openaiConsentBox.length) {
			$openaiKeyInput.on('input', function() {
				var currentKey = $(this).val();
				if (currentKey && currentKey !== originalOpenaiKey) {
					$openaiConsentBox.show();
					updateConsentState();
				} else {
					$openaiConsentBox.hide();
					$('.api-key-consent-checkbox-openai').prop('checked', false);
					updateConsentState();
				}
			});
		}

		$('.api-key-consent-checkbox, .api-key-consent-checkbox-openai').on('change', updateConsentState);

	}

	/**
	 * Memory Enable Toggle Handler
	 * Shows/hides memory consent container when memory checkbox changes
	 * Only shows consent if consent has not already been given
	 */
	function initMemoryEnableToggle() {
		var $enableMemory = $('#abilities_bridge_enable_memory');
		var $consentContainer = $('#abilities_bridge_memory_consent_container');

		if (!$enableMemory.length || !$consentContainer.length) {
			return;
		}

		// Check if consent was already given (from PHP)
		var consentAlreadyGiven = window.abilitiesBridgeSettings && window.abilitiesBridgeSettings.memoryConsentGiven;

		$enableMemory.on('change', function() {
			// Only show consent container if enabling AND consent not already given
			if ($(this).is(':checked') && !consentAlreadyGiven) {
				$consentContainer.slideDown();
			} else {
				$consentContainer.slideUp();
			}
		});
	}

	/**
	 * Memory Consent Validation Handler
	 * Validates that consent is given when memory is enabled
	 */
	function initMemoryConsentValidation() {
		var $enableMemory = $('#abilities_bridge_enable_memory');
		var $memoryConsent = $('#abilities_bridge_memory_consent');
		var $consentContainer = $('#abilities_bridge_memory_consent_container');
		var $submitButton = $('#submit');

		if (!$enableMemory.length || !$memoryConsent.length) {
			return;
		}

		// Show/hide consent box and validate when memory checkbox changes
		$enableMemory.on('change', function() {
			if ($(this).is(':checked')) {
				$consentContainer.slideDown();
				checkMemoryConsent();
			} else {
				$consentContainer.slideUp();
				$submitButton.prop('disabled', false);
				$memoryConsent.prop('checked', false);
			}
		});

		// Validate consent when consent checkbox changes
		$memoryConsent.on('change', checkMemoryConsent);

		// Check if consent is given when memory is enabled
		function checkMemoryConsent() {
			var memoryEnabled = $enableMemory.is(':checked');
			var consentGiven = $memoryConsent.is(':checked');

			if (memoryEnabled && !consentGiven) {
				$submitButton.prop('disabled', true);
			} else {
				$submitButton.prop('disabled', false);
			}
		}

		// Run initial check on page load
		if ($enableMemory.is(':checked')) {
			checkMemoryConsent();
		}
	}

	/**
	 * MCP Copy Button Handler
	 * Handles copying MCP credentials and URLs to clipboard
	 */
	function initMcpCopyButtons() {
		var $copyButtons = $('.mcp-copy-btn');

		if (!$copyButtons.length) {
			return;
		}

		$copyButtons.on('click', function(e) {
			e.preventDefault();
			var targetId = $(this).data('copy-target');
			var text = $('#' + targetId).text().trim();
			var $btn = $(this);
			var originalText = $btn.text();

			navigator.clipboard.writeText(text).then(function() {
				$btn.text(abilitiesBridgeSettings.i18n.copied);
				setTimeout(function() {
					$btn.text(originalText);
				}, 2000);
			}).catch(function(err) {
				console.error('Failed to copy:', err);
				alert(abilitiesBridgeSettings.i18n.copyFailed);
			});
		});
	}

	/**
	 * Tab Switching Handler
	 * Handles settings page tab navigation
	 */
	function initTabSwitching() {
		var $tabs = $('.abilities-bridge-settings-tabs .nav-tab');
		var $tabContent = $('.abilities-bridge-tab-content');

		if (!$tabs.length) {
			return;
		}

		$tabs.on('click', function(e) {
			e.preventDefault();

			var tabId = $(this).data('tab');
			var currentTab = $('.abilities-bridge-settings-tabs .nav-tab-active').data('tab');

			// Security: Auto-hide credentials when switching away from MCP tabs
			if ((currentTab === 'anthropic-mcp' || currentTab === 'chatgpt-mcp') && tabId !== currentTab) {
				var $credentials = $('#generated-credentials');
				if ($credentials.length && $credentials.data('one-time-view')) {
					// Fade out and remove credentials container
					$credentials.fadeOut(400, function() {
						$(this).remove();
					});
					// Also remove the warning banner
					$credentials.prev('.notice-warning').fadeOut(400, function() {
						$(this).remove();
					});
				}
			}

			// Update tab navigation
			$tabs.removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			// Update tab content
			$tabContent.hide();
			$('#tab-' + tabId).show();

			// Store active tab in localStorage
			localStorage.setItem('abilities_bridge_active_tab', tabId);
		});

		// Restore active tab on page load: URL tab param takes priority over localStorage
		var urlParams = new URLSearchParams(window.location.search);
		var activeTab = urlParams.get('tab') || localStorage.getItem('abilities_bridge_active_tab');
		if (activeTab) {
			var $targetTab = $('.abilities-bridge-settings-tabs .nav-tab[data-tab="' + activeTab + '"]');
			if ($targetTab.length && !$targetTab.hasClass('nav-tab-active')) {
				$targetTab.trigger('click');
			}
		}
	}

	/**
	 * System Prompt Restore Default Handler
	 * Restores the system prompt to its default value
	 */
	function initSystemPromptRestore() {
		var $restoreBtn = $('#abilities-bridge-restore-default-prompt');
		var $promptTextarea = $('#abilities_bridge_system_prompt');

		if (!$restoreBtn.length || !$promptTextarea.length) {
			return;
		}

		$restoreBtn.on('click', function() {
			if (confirm(abilitiesBridgeSettings.i18n.restorePromptConfirm)) {
				$promptTextarea.val(abilitiesBridgeSettings.defaultSystemPrompt);
			}
		});
	}

	/**
	 * OpenAI connection test handler
	 */
	function initOpenAiConnectionTest() {
		var $button = $('#abilities-bridge-test-openai');
		var $result = $('#abilities-bridge-openai-test-result');

		if (!$button.length || !$result.length) {
			return;
		}

		function showResult(type, message) {
			$result
				.removeClass('notice-success notice-error notice-warning')
				.addClass(type)
				.html('<p>' + message + '</p>')
				.show();
		}

		$button.on('click', function() {
			$button.prop('disabled', true);
			showResult('notice-warning', abilitiesBridgeSettings.i18n.openaiTestRunning);

			$.ajax({
				url: abilitiesBridgeSettings.ajaxUrl,
				type: 'POST',
				data: {
					action: 'abilities_bridge_test_openai',
					nonce: abilitiesBridgeSettings.nonce
				},
				success: function(response) {
					if (response.success) {
						var successMessage = abilitiesBridgeSettings.i18n.openaiTestSuccess;
						if (response.data && response.data.model) {
							successMessage += ' Model: <code>' + response.data.model + '</code>';
						}
						showResult('notice-success', successMessage);
					} else {
						var failureMessage = abilitiesBridgeSettings.i18n.openaiTestFailed;
						if (response.data && response.data.message) {
							failureMessage += ' ' + response.data.message;
						}
						if (response.data && response.data.model) {
							failureMessage += ' Model: <code>' + response.data.model + '</code>';
						}
						showResult('notice-error', failureMessage);
					}
				},
				error: function() {
					showResult('notice-error', abilitiesBridgeSettings.i18n.openaiTestAjaxError);
				},
				complete: function() {
					$button.prop('disabled', false);
				}
			});
		});
	}

	/**
	 * Welcome Wizard Consent Handler
	 * Enables submit button only when all consent checkboxes are checked
	 */
	function initWelcomeWizardConsent() {
		var $consentForm = $('#consent-form');
		var $submitBtn = $('#submit-consent-btn');

		if (!$consentForm.length || !$submitBtn.length) {
			return;
		}

		var $checkboxes = $consentForm.find('input[type="checkbox"]');

		function updateSubmitButton() {
			var allChecked = $('#consent_permissions').is(':checked') &&
							$('#consent_billing').is(':checked') &&
							$('#consent_understanding').is(':checked');
			$submitBtn.prop('disabled', !allChecked);
		}

		$checkboxes.on('change', updateSubmitButton);
		updateSubmitButton();
	}

	/**
	 * Security Tab Handlers
	 * Handle path management actions
	 */
	function initSecurityTabHandlers() {
		// Add Path
		$('#abilities-bridge-add-path').on('click', function() {
			var path = $('#abilities-bridge-custom-path').val().trim();
			var notes = $('#abilities-bridge-custom-path-notes').val().trim();

			if (!path) {
				alert('Please enter a path');
				return;
			}

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'abilities_bridge_add_path',
					nonce: abilitiesBridgeSettings.nonce,
					path: path,
					notes: notes
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('AJAX error occurred');
				}
			});
		});

		// Remove Path
		$(document).on('click', '.abilities-bridge-remove-path', function() {
			if (!confirm('Are you sure you want to remove this path?')) {
				return;
			}

			var path = $(this).data('path');

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'abilities_bridge_remove_path',
					nonce: abilitiesBridgeSettings.nonce,
					path: path
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('AJAX error occurred');
				}
			});
		});

		// Test Path
		$('#abilities-bridge-test-path').on('click', function() {
			var path = $('#abilities-bridge-custom-path').val().trim();

			if (!path) {
				alert('Please enter a path to test');
				return;
			}

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'abilities_bridge_test_path',
					nonce: abilitiesBridgeSettings.nonce,
					path: path
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('AJAX error occurred');
				}
			});
		});

		// Reset to Default
		$('#abilities-bridge-reset-default').on('click', function() {
			if (!confirm('This will reset to wp-content only. All custom paths will be removed. Continue?')) {
				return;
			}

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'abilities_bridge_reset_default',
					nonce: abilitiesBridgeSettings.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('AJAX error occurred');
				}
			});
		});
	}

	/**
	 * WP AI Client Toggle Handler
	 * Dims API key rows when WP AI Client checkbox is checked
	 */
	function initWpAiClientToggle() {
		var $checkbox = $('#abilities_bridge_use_wp_ai_client');

		if (!$checkbox.length) {
			return;
		}

		var $anthropicRow = $('#abilities_bridge_api_key').closest('tr');
		var $openaiRow = $('#abilities_bridge_openai_api_key').closest('tr');

		function toggleKeyRows() {
			var opacity = $checkbox.is(':checked') ? 0.5 : 1;
			$anthropicRow.css('opacity', opacity);
			$openaiRow.css('opacity', opacity);
		}

		$checkbox.on('change', toggleKeyRows);
		toggleKeyRows();
	}

	/**
	 * Initialize all handlers on document ready
	 */
	$(document).ready(function() {
		initApiKeyConsent();
		initMemoryEnableToggle();
		initMemoryConsentValidation();
		initMcpCopyButtons();
		initTabSwitching();
		initSystemPromptRestore();
		initOpenAiConnectionTest();
		initWelcomeWizardConsent();
		initSecurityTabHandlers();
		initWpAiClientToggle();
	});

})(jQuery);
