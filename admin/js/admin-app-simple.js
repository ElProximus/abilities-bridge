/**
 * Abilities Bridge - Simplified Progress Implementation
 * Falls back to original behavior if progress tracking fails
 *
 * @package Abilities_Bridge
 */

(function($) {
	'use strict';

	let currentConversationId = null;
	let activityPollInterval = null;
	let currentRequest = null;
	let attachmentManager = null;
	let currentJobToken = null;
	let jobPollTimer = null;
	let jobPollStartedAt = 0;
	let supersedeJobToken = null;
	let enqueueInFlight = false;
	let stopRequestedDuringEnqueue = false;
	let pendingReplacementPayload = null;
	let consecutiveJobPollFailures = 0;
	let jobPollRequestId = 0;
	let foregroundRunInFlight = false;
	const maxConsecutiveJobPollFailures = 12;
	let displayedActivities = new Set(); // Track which activities are already displayed

	/**
	 * Initialize the application
	 */
	function init() {
		bindEvents();
		initAttachments();
		initProgressBar();
		loadProviderState();
		// Load conversations after a short delay to ensure page is ready
		setTimeout(function() {
			loadConversations();
			resumeLatestJob();
			updateTokenMeter(); // Update token meter on init
		}, 500);
	}

	/**
	 * Initialize shared image attachment controls.
	 */
	function initAttachments() {
		if (!window.AbilitiesBridgeChatAttachments) {
			return;
		}

		attachmentManager = window.AbilitiesBridgeChatAttachments.create({
			config: abilitiesBridgeData.attachments || {},
			selectors: {
				root: '#abilities-bridge-chat-attachment-panel',
				fileInput: '#abilities-bridge-chat-image-input',
				uploadButton: '#abilities-bridge-chat-upload-image',
				screenshotButton: '#abilities-bridge-chat-capture-screenshot',
				preview: '#abilities-bridge-chat-attachment-preview',
				status: '#abilities-bridge-chat-attachment-status'
			}
		});
	}

	/**
	 * Initialize activity log UI
	 */
	function initProgressBar() {
		if ($('#abilities-bridge-activity-log').length === 0) {
			const activityHtml = `
				<div id="abilities-bridge-activity-log" style="display: none;">
					<div class="activity-header">
						<strong>⚡ AI Activity</strong>
					</div>
					<div class="activity-list" id="activity-list">
						<!-- Activities will be added here -->
					</div>
				</div>
			`;
			// Insert before the chat input form
			// This places it above the input, inside the chat content
			$('.abilities-bridge-chat-input-container').before(activityHtml);
		} else {
		}
	}

	/**
	 * Bind event handlers
	 */
	function bindEvents() {
		$('#abilities-bridge-chat-form').on('submit', handleChatSubmit);
		$('#abilities-bridge-new-conversation').on('click', handleNewConversation);
		$('#abilities-bridge-conversation-select').on('change', handleLoadConversation);
		$('#abilities-bridge-delete-conversation').on('click', handleDeleteConversation);
		$('#abilities-bridge-refresh-conversations').on('click', loadConversations);
		$('#abilities-bridge-model-select').on('change', handleModelChange);
		$('#abilities-bridge-provider-select').on('change', handleProviderChange);
		$('#abilities-bridge-stop-button').on('click', handleStopRequest);

		$('#abilities-bridge-chat-input').on('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				$('#abilities-bridge-chat-form').submit();
			}
		});

		// Plan Mode visual feedback
		$('#abilities-bridge-plan-mode').on('change', function() {
			if ($(this).is(':checked')) {
				$('.abilities-bridge-chat-content').addClass('plan-mode-active');
			} else {
				$('.abilities-bridge-chat-content').removeClass('plan-mode-active');
			}
		});
	}

	/**
	 * Persist a chat turn and let the durable runner continue independently.
	 */
	function handleChatSubmit(e) {
		e.preventDefault();

		const message = $('#abilities-bridge-chat-input').val().trim();
		const attachments = attachmentManager ? attachmentManager.getPayload() : [];
		if (!message && attachments.length === 0) return;
		const payload = {
			message: message,
			attachments: attachments,
			planMode: $('#abilities-bridge-plan-mode').is(':checked')
		};
		if (enqueueInFlight && stopRequestedDuringEnqueue && pendingReplacementPayload) {
			appendMessage('error', 'A replacement request is already waiting. The newer text was not queued.');
			return;
		}

		appendMessage('user', buildLocalDisplayContent(message, attachments));
		$('#abilities-bridge-chat-input').val('');
		if (attachmentManager) {
			attachmentManager.clear();
		}

		if (enqueueInFlight && stopRequestedDuringEnqueue) {
			pendingReplacementPayload = payload;
			setLoading(true);
			$('#abilities-bridge-send-button').hide();
			$('#abilities-bridge-stop-button').hide();
			showSimpleProgress();
			$('#abilities-bridge-chat-loading').show().find('span').text('Saving the replacement request…');
			return;
		}

		enqueueChatPayload(payload);
	}

	function enqueueChatPayload(payload) {
		setLoading(true);
		$('#abilities-bridge-send-button').hide();
		$('#abilities-bridge-stop-button').show();

		showSimpleProgress();
		$('#abilities-bridge-chat-loading').show();
		enqueueInFlight = true;
		stopRequestedDuringEnqueue = false;
		let replacementAfterStop = null;

		const request = $.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_chat_job_enqueue',
				nonce: abilitiesBridgeData.nonce,
				message: payload.message,
				attachments: JSON.stringify(payload.attachments),
				conversation_id: currentConversationId,
				plan_mode: payload.planMode ? 'true' : 'false',
				supersede_job: supersedeJobToken || ''
			}
		});
		currentRequest = request;

		request.done(function(response) {
			if (!response.success) {
				if (stopRequestedDuringEnqueue) {
					replacementAfterStop = pendingReplacementPayload;
					pendingReplacementPayload = null;
					stopRequestedDuringEnqueue = false;
				}
				handleError(response.data && response.data.message ? response.data.message : 'Unable to queue the chat request.');
				finishJobUi();
				return;
			}

			currentConversationId = response.data.conversation_id;
			updateConversationInfo();
			loadConversations();
			if (stopRequestedDuringEnqueue) {
				supersedeJobToken = response.data.job;
				cancelJobWithRetry(response.data.job);
				stopRequestedDuringEnqueue = false;
				replacementAfterStop = pendingReplacementPayload;
				pendingReplacementPayload = null;
				finishJobUi();
				return;
			}
			supersedeJobToken = null;
			startJobPolling(response.data.job, 0);
		}).fail(function() {
			if (stopRequestedDuringEnqueue) {
				replacementAfterStop = pendingReplacementPayload;
				pendingReplacementPayload = null;
				stopRequestedDuringEnqueue = false;
			}
			handleError('Unable to queue the chat request. Please try again.');
			finishJobUi();
		}).always(function() {
			if (currentRequest === request) {
				currentRequest = null;
			}
			enqueueInFlight = false;
			if (replacementAfterStop) {
				enqueueChatPayload(replacementAfterStop);
			}
		});
	}

	/**
	 * Handle stop request
	 */
	function handleStopRequest() {
		if (!currentJobToken && enqueueInFlight) {
			stopRequestedDuringEnqueue = true;
			finishJobUi();
			appendMessage('system', 'Stopping as soon as this request is safely saved. You can enter a replacement question now.');
			return;
		}
		if (!currentJobToken) return;

		const stoppedJob = currentJobToken;
		supersedeJobToken = stoppedJob;
		currentJobToken = null;
		clearTimeout(jobPollTimer);
		finishJobUi();
		appendMessage('system', 'Stopped. A step that was already running may still finish (and be billed) at the provider; its result was discarded.');

		cancelJobWithRetry(stoppedJob);
	}

	function cancelJobWithRetry(jobToken, attempt) {
		const retryAttempt = attempt || 0;
		$.post(abilitiesBridgeData.ajax_url, {
			action: 'abilities_bridge_chat_job_cancel',
			nonce: abilitiesBridgeData.nonce,
			job: jobToken
		}).done(function(response) {
			if (!response.success && retryAttempt < 4) {
				setTimeout(function() { cancelJobWithRetry(jobToken, retryAttempt + 1); }, Math.pow(2, retryAttempt) * 1000);
			} else if (!response.success) {
				appendMessage('error', 'The Stop request could not be confirmed. It will be attempted again if you send a replacement question.');
			}
		}).fail(function() {
			if (retryAttempt < 4) {
				setTimeout(function() { cancelJobWithRetry(jobToken, retryAttempt + 1); }, Math.pow(2, retryAttempt) * 1000);
			} else {
				appendMessage('error', 'The Stop request could not be confirmed. It will be attempted again if you send a replacement question.');
			}
		});
	}

	function startJobPolling(jobToken, elapsed) {
		currentJobToken = jobToken;
		jobPollStartedAt = Date.now() - ((elapsed || 0) * 1000);
		consecutiveJobPollFailures = 0;
		pollJob();
	}

	function pollJob() {
		if (!currentJobToken) return;
		const token = currentJobToken;
		clearTimeout(jobPollTimer);
		jobPollTimer = null;
		const requestId = ++jobPollRequestId;

		$.post(abilitiesBridgeData.ajax_url, {
			action: 'abilities_bridge_chat_job_status',
			nonce: abilitiesBridgeData.nonce,
			job: token
		}).done(function(response) {
			if (token !== currentJobToken || requestId !== jobPollRequestId) return;
			if (!response.success) {
				currentJobToken = null;
				handleError(response.data && response.data.message ? response.data.message : 'Unable to read the background chat status.');
				finishJobUi();
				return;
			}
			consecutiveJobPollFailures = 0;
			const job = response.data;
			const elapsed = Math.max(job.elapsed || 0, Math.floor((Date.now() - jobPollStartedAt) / 1000));
			const activity = job.last_activity || (job.status === 'preparing' ? 'Saving request' : 'Working');
			$('#abilities-bridge-chat-loading span').text(activity + ' · ' + formatElapsed(elapsed));

			if (job.status === 'missing') {
				currentJobToken = null;
				appendMessage('error', 'This saved background job is no longer available. Reload the conversation to check for its final answer.');
				finishJobUi();
				return;
			}

			if (job.status === 'completed') {
				currentJobToken = null;
				loadConversation(job.conversation_id);
				finishJobUi();
				return;
			}
			if (job.status === 'uncertain' && job.recovery_pending) {
				$('#abilities-bridge-chat-loading span').text('Recovering the accepted OpenAI response · ' + formatElapsed(elapsed));
				$('#abilities-bridge-run-foreground').remove();
				jobPollTimer = setTimeout(pollJob, 15000);
				return;
			}
			if (job.status === 'failed' || job.status === 'uncertain') {
				currentJobToken = null;
				appendMessage(job.status === 'uncertain' ? 'system' : 'error', job.error_message || (job.status === 'uncertain' ? 'The last step has an uncertain outcome and may have been billed. Check the affected data before trying again.' : 'The background chat job failed.'));
				finishJobUi();
				return;
			}
			if (job.status === 'cancelled') {
				currentJobToken = null;
				finishJobUi();
				return;
			}

			if (job.background_unavailable) {
				renderForegroundOffer(token);
			} else {
				$('#abilities-bridge-run-foreground').remove();
			}

			const delay = elapsed >= 60 ? 5000 : 2000;
			jobPollTimer = setTimeout(pollJob, delay);
		}).fail(function() {
			if (token === currentJobToken && requestId === jobPollRequestId) {
				consecutiveJobPollFailures++;
				if (consecutiveJobPollFailures >= maxConsecutiveJobPollFailures) {
					currentJobToken = null;
					appendMessage('error', 'Status checks repeatedly failed. The saved job may still be running; reload or reopen this conversation to resume monitoring.');
					finishJobUi();
					return;
				}
				jobPollTimer = setTimeout(pollJob, 5000);
			}
		});
	}

	function runJobInForeground(token) {
		if (foregroundRunInFlight) return;
		foregroundRunInFlight = true;
		clearTimeout(jobPollTimer);
		jobPollTimer = null;
		++jobPollRequestId;
		$('#abilities-bridge-run-foreground').prop('disabled', true).text('Starting foreground run…');
		currentRequest = $.post(abilitiesBridgeData.ajax_url, {
			action: 'abilities_bridge_chat_job_run_foreground',
			nonce: abilitiesBridgeData.nonce,
			job: token
		}).done(function(response) {
			if (!response.success) {
				handleError(response.data && response.data.message ? response.data.message : 'The foreground takeover did not start.');
			}
		}).fail(function() {
			handleError('The foreground takeover request failed. Background monitoring will continue.');
		}).always(function() {
			currentRequest = null;
			foregroundRunInFlight = false;
			$('#abilities-bridge-run-foreground').remove();
			clearTimeout(jobPollTimer);
			jobPollTimer = null;
			if (token === currentJobToken) pollJob();
		});
	}

	function renderForegroundOffer(token) {
		if (foregroundRunInFlight) return;
		if ($('#abilities-bridge-run-foreground').length) return;
		$('<button>', {
			id: 'abilities-bridge-run-foreground',
			type: 'button',
			class: 'button button-secondary',
			text: 'Run this saved request in the foreground'
		}).on('click', function() {
			runJobInForeground(token);
		}).appendTo('#abilities-bridge-chat-loading');
	}

	function resumeLatestJob() {
		if (currentConversationId || currentJobToken) return;
		$.post(abilitiesBridgeData.ajax_url, {
			action: 'abilities_bridge_chat_job_resume',
			nonce: abilitiesBridgeData.nonce,
			conversation_id: 0
		}).done(function(response) {
			if (!response.success || !response.data.job) return;
			const job = response.data.job;
			if (['preparing', 'queued', 'dispatching', 'running', 'uncertain'].indexOf(job.status) !== -1) {
				loadConversation(job.conversation_id);
			}
		});
	}

	function resumeJob(conversationId) {
		if (!conversationId || currentJobToken) return;
		$.post(abilitiesBridgeData.ajax_url, {
			action: 'abilities_bridge_chat_job_resume',
			nonce: abilitiesBridgeData.nonce,
			conversation_id: conversationId
		}).done(function(response) {
			if (!response.success || !response.data.job) return;
			const job = response.data.job;
			if (['preparing', 'queued', 'dispatching', 'running'].indexOf(job.status) !== -1 || (job.status === 'uncertain' && job.recovery_pending)) {
				setLoading(true);
				$('#abilities-bridge-send-button').hide();
				$('#abilities-bridge-stop-button').show();
				showSimpleProgress();
				$('#abilities-bridge-chat-loading').show();
				startJobPolling(job.job, job.elapsed);
			} else if (job.status === 'uncertain') {
				appendMessage('system', job.error_message || 'The prior request has an uncertain outcome and may have been billed.');
			}
		});
	}

	function finishJobUi() {
		stopActivityPolling();
		hideSimpleProgress();
		$('#abilities-bridge-chat-loading').hide();
		$('#abilities-bridge-run-foreground').remove();
		setLoading(false);
		$('#abilities-bridge-stop-button').hide();
		$('#abilities-bridge-send-button').show();
		loadActivityHistory();
		updateTokenMeter();
		$('#abilities-bridge-chat-input').focus();
	}

	function formatElapsed(seconds) {
		const minutes = Math.floor(seconds / 60);
		return minutes + ':' + String(seconds % 60).padStart(2, '0');
	}

	/**
	 * Start polling for activity updates
	 */
	function startActivityPolling(conversationId) {
		if (!conversationId) {
			return;
		}


		// Clear any existing interval
		if (activityPollInterval) {
			clearInterval(activityPollInterval);
		}

		// Poll every 500ms for activity updates
		activityPollInterval = setInterval(function() {
			$.ajax({
				url: abilitiesBridgeData.ajax_url,
				type: 'POST',
				data: {
					action: 'abilities_bridge_get_recent_activity',
					nonce: abilitiesBridgeData.nonce,
					conversation_id: conversationId
				},
				success: function(response) {
					if (response.success && response.data && response.data.activities) {
						const activities = response.data.activities;

						if (activities.length > 0) {
							const $list = $('#activity-list');

							// Show most recent activities (limit to 3)
							activities.slice(0, 3).forEach(function(activity) {
								// Create unique ID for this activity
								const activityId = activity.id || (activity.timestamp + activity.message);

								// Only add if not already displayed
								if (!displayedActivities.has(activityId)) {
									// Extract icon from message if present
									const iconMatch = activity.message.match(/^([📄🔍📊✅⚙️⏳⏱️🗄️📁]+)\s*/);
									const icon = iconMatch ? iconMatch[1] : '⚙️';
									const text = activity.message.replace(/^([📄🔍📊✅⚙️⏳⏱️🗄️📁]+)\s*/, '');

									addActivity(icon, text, activityId);
									displayedActivities.add(activityId);
								}
							});
						}
					}
				},
				error: function() {
					// Conversation might not have activity yet, that's OK
				}
			});
		}, 500);
	}

	/**
	 * Stop polling for activity
	 */
	function stopActivityPolling() {
		if (activityPollInterval) {
			clearInterval(activityPollInterval);
			activityPollInterval = null;
		}
	}

	/**
	 * Show activity log
	 */
	function showSimpleProgress() {
		$('#activity-list').empty();
		displayedActivities.clear(); // Reset tracking for new request
		addActivity('⏳', 'Sending request to AI...', 'initial-request');
		$('#abilities-bridge-activity-log').slideDown(200);
	}

	/**
	 * Add activity to log
	 */
	function addActivity(icon, text, activityId) {
		const activityHtml = `
			<div class="activity-item" data-activity-id="${activityId || ''}">
				<span class="activity-icon">${icon}</span>
				<span class="activity-text">${text}</span>
			</div>
		`;
		const $list = $('#activity-list');
		$list.append(activityHtml);

		// Keep only last 5 items
		const items = $list.find('.activity-item');
		if (items.length > 5) {
			const removed = items.first();
			const removedId = removed.attr('data-activity-id');
			if (removedId) {
				displayedActivities.delete(removedId);
			}
			removed.remove();
		}

		// Auto-scroll to bottom
		$list.scrollTop($list[0].scrollHeight);
	}

	/**
	 * Parse and display tool activities from response
	 */
	function displayToolActivities(toolUsage) {

		// Tool icons mapping
		const toolIcons = {
			'memory': '🧠'
		};

		// Display each tool use
		toolUsage.forEach(function(toolUse) {
			const icon = toolIcons[toolUse.tool] || '🔧';
			let description = '';

			// Create meaningful description based on tool and input
			if (toolUse.tool === 'memory') {
				description = 'Memory operation';
			} else {
				description = toolUse.tool;
			}

			addActivity(icon, description);
		});

		// Add completion message
		addActivity('✅', 'Request completed');
	}

	/**
	 * Hide activity log
	 */
	function hideSimpleProgress() {
		$('#abilities-bridge-activity-log').slideUp(200);
		// Removed 5-second delay - Activity History preserves all data
	}

	/**
	 * Set loading state
	 */
	function setLoading(loading) {
		const $input = $('#abilities-bridge-chat-input');
		const $button = $('#abilities-bridge-chat-form button[type="submit"]');
		if (attachmentManager) {
			attachmentManager.setDisabled(loading);
		}
		$('#abilities-bridge-new-conversation, #abilities-bridge-conversation-select, #abilities-bridge-delete-conversation').prop('disabled', loading);

		if (loading) {
			$input.prop('disabled', true);
			$button.prop('disabled', true).text('Processing...');
			$('.abilities-bridge-chat-container').addClass('loading');
		} else {
			$input.prop('disabled', false);
			$button.prop('disabled', false).text('Send');
			$('.abilities-bridge-chat-container').removeClass('loading');
		}
	}

	/**
	 * Append message to chat
	 */
	function appendMessage(role, content, isExcluded) {
		const $chatMessages = $('#abilities-bridge-chat-messages');
		const messageClass = role === 'user' ? 'user-message' :
							 role === 'error' ? 'error-message' :
							 role === 'system' ? 'system-message' : 'assistant-message';

		// Add excluded class if message is excluded
		const excludedClass = isExcluded ? ' excluded-message' : '';
		const excludedLabel = isExcluded ? '<span class="excluded-label">📦 Archived</span>' : '';

		const messageHtml = `
			<div class="chat-message ${messageClass}${excludedClass}">
				<div class="message-role">${role === 'user' ? 'You' : role === 'system' ? 'System' : role === 'error' ? 'Error' : 'AI'}${excludedLabel}</div>
				<div class="message-content">${renderMessageContent(content)}</div>
			</div>
		`;

		$chatMessages.append(messageHtml);
		$chatMessages.scrollTop($chatMessages[0].scrollHeight);
	}

	/**
	 * Build local display blocks for a just-submitted message.
	 */
	function buildLocalDisplayContent(message, attachments) {
		const blocks = [];
		(attachments || []).forEach(function(attachment) {
			blocks.push({
				type: 'image',
				source: attachment.source,
				name: attachment.name,
				width: attachment.width,
				height: attachment.height,
				preview_url: attachment.data_url
			});
		});
		if (message) {
			blocks.push({ type: 'text', text: message });
		}
		return blocks.length ? blocks : message;
	}

	/**
	 * Render string or multimodal message content.
	 */
	function renderMessageContent(content) {
		if (window.AbilitiesBridgeChatAttachments) {
			return window.AbilitiesBridgeChatAttachments.renderMessageContent(content);
		}

		return escapeHtml(String(content || ''));
	}

	/**
	 * Handle errors
	 */
	function handleError(message) {
		appendMessage('error', message);
	}

	/**
	 * Escape HTML
	 */
	function escapeHtml(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(/[&<>"']/g, m => map[m]);
	}

	/**
	 * Format date for display
	 */
	function formatDate(dateString) {
		const date = new Date(dateString);
		const now = new Date();
		const diffTime = Math.abs(now - date);
		const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

		// If today, show time
		if (diffDays === 0 || diffDays === 1) {
			const hours = date.getHours().toString().padStart(2, '0');
			const minutes = date.getMinutes().toString().padStart(2, '0');
			if (diffDays === 0) {
				return `Today ${hours}:${minutes}`;
			} else {
				return `Yesterday ${hours}:${minutes}`;
			}
		}

		// If this week, show day name
		if (diffDays < 7) {
			const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
			return days[date.getDay()] + ' ' + date.toLocaleDateString();
		}

		// Otherwise show date
		return date.toLocaleDateString();
	}

	/**
	 * Load conversations
	 */
	function loadConversations() {
		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_conversations',
				nonce: abilitiesBridgeData.nonce
			},
			success: function(response) {
				if (response.success) {
					updateConversationsList(response.data);
				} else {
				}
			},
			error: function(xhr, status, error) {
			}
		});
	}

	/**
	 * Update conversations list - IMPROVED VERSION
	 */
	function updateConversationsList(data) {
		const $select = $('#abilities-bridge-conversation-select');
		$select.prop('disabled', false); // Enable dropdown after loading completes
		$select.empty();

		// Add improved placeholder
		$select.append('<option value="">💬 Select a conversation...</option>');

		// Safely extract conversations array
		let conversations = [];
		if (data && data.conversations && Array.isArray(data.conversations)) {
			conversations = data.conversations;
		} else if (Array.isArray(data)) {
			conversations = data;
		}

		if (conversations.length > 0) {
			conversations.forEach(function(conv) {
				// Format the title - now supports 80 chars from backend
				let title = conv.title || `Conversation #${conv.id}`;
				
				// Truncate if still too long for dropdown
				if (title.length > 70) {
					title = title.substring(0, 70) + '...';
				}
				
				// Format date nicely
				const formattedDate = formatDate(conv.updated_at || conv.created_at);
				
				// Create option with ID prefix and date
				const optionText = `#${conv.id} - ${title} • ${formattedDate}`;
				const option = $('<option></option>')
					.val(conv.id)
					.text(optionText);
				
				// Highlight current conversation
				if (currentConversationId && parseInt(conv.id) === parseInt(currentConversationId)) {
					option.attr('selected', 'selected');
					option.css('font-weight', 'bold');
				}
				
				$select.append(option);
			});
			
			// Show delete button if conversation is selected
			if (currentConversationId) {
				$('#abilities-bridge-delete-conversation').show();
				$('#abilities-bridge-conversation-info').show();
			}
		} else {
			$select.append('<option value="">No conversations yet</option>');
		}

		// Reselect current conversation if exists
		if (currentConversationId) {
			$select.val(currentConversationId);
		}
	}

	/**
	 * Update conversation info
	 */
	function updateConversationInfo() {
		if (currentConversationId) {
			$('#abilities-bridge-conversation-title').text('Conversation #' + currentConversationId);
			$('#abilities-bridge-conversation-info').show();
			$('#abilities-bridge-delete-conversation').show();
		} else {
			$('#abilities-bridge-conversation-info').hide();
			$('#abilities-bridge-delete-conversation').hide();
		}
	}

	/**
	 * Handle new conversation
	 */
	function handleNewConversation() {
		currentConversationId = null;
		$('#abilities-bridge-chat-messages').empty();
		if (attachmentManager) {
			attachmentManager.clear();
		}

		// Show welcome message (matches initial page load behavior)
		appendMessage('assistant', 'Hi, I\'m your AI assistant. How can I help you today?');
		appendMessage('system', 'Started a new conversation.');

		$('#abilities-bridge-conversation-select').val('');
		$('#abilities-bridge-conversation-info').hide();
		$('#abilities-bridge-delete-conversation').hide();
		$('#abilities-bridge-activity-history').hide(); // Hide activity history for new conversation
		updateTokenMeter(); // Reset token bar immediately
	}

	/**
	 * Handle load conversation
	 */
	function handleLoadConversation() {
		const conversationId = $('#abilities-bridge-conversation-select').val();
		if (!conversationId) return;

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_load_conversation',
				nonce: abilitiesBridgeData.nonce,
				conversation_id: conversationId
			},
			success: function(response) {
				if (response.success) {
					currentConversationId = conversationId;
					// Sync provider/model selectors to match the loaded conversation
					syncProviderFromConversation(response.data.conversation);


					// Clear and rebuild messages
					const $chatMessages = $('#abilities-bridge-chat-messages');
					$chatMessages.empty();

					if (response.data.messages && response.data.messages.length > 0) {
						response.data.messages.forEach(function(msg) {
							appendMessage(msg.role, msg.content);
						});
					}

					updateConversationInfo();
					updateTokenMeter(); // Update token bar for loaded conversation
					loadActivityHistory(); // Load activity history for this conversation

					appendMessage('system', '📂 Conversation loaded successfully.');
					resumeJob(conversationId);
				}
			}
		});
	}

	/**
	 * Load a specific conversation by ID
	 */
	function loadConversation(conversationId) {
		if (!conversationId) {
			return;
		}

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_load_conversation',
				nonce: abilitiesBridgeData.nonce,
				conversation_id: conversationId
			},
			success: function(response) {
				if (response.success) {
					currentConversationId = conversationId;
					// Sync provider/model selectors to match the loaded conversation
					syncProviderFromConversation(response.data.conversation);

					// Clear and rebuild messages
					const $chatMessages = $('#abilities-bridge-chat-messages');
					$chatMessages.empty();

					if (response.data.messages && response.data.messages.length > 0) {
						response.data.messages.forEach(function(msg) {
							appendMessage(msg.role, msg.content);
						});
					}

					// Update conversation selector to match
					$('#abilities-bridge-conversation-select').val(conversationId);

					updateConversationInfo();
					updateTokenMeter();
					loadActivityHistory(); // Reload activity history
					resumeJob(conversationId);
				} else {
					handleError('Failed to reload conversation: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
				}
			},
			error: function(xhr, status, error) {
				handleError('Error reloading conversation. Please refresh the page.');
			}
		});
	}

	/**
	 * Handle delete conversation
	 */
	let deleteConversationConfirmTimer = null;

	function handleDeleteConversation() {
		if (!currentConversationId) {
			appendMessage('system', 'No conversation selected.');
			return;
		}

		const $deleteButton = $('#abilities-bridge-delete-conversation');

		if (!$deleteButton.hasClass('is-confirming-delete')) {
			$deleteButton
				.text('Click again to delete')
				.addClass('is-confirming-delete')
				.addClass('button-link-delete');
			appendMessage('system', 'Click Delete Conversation again to permanently delete this conversation.');

			clearTimeout(deleteConversationConfirmTimer);
			deleteConversationConfirmTimer = setTimeout(function() {
				$deleteButton
					.text('Delete Conversation')
					.removeClass('is-confirming-delete')
					.removeClass('button-link-delete');
			}, 5000);
			return;
		}

		clearTimeout(deleteConversationConfirmTimer);
		$deleteButton.prop('disabled', true).text('Deleting...');

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_delete_conversation',
				nonce: abilitiesBridgeData.nonce,
				conversation_id: currentConversationId
			},
			success: function(response) {
				if (response.success) {
					handleNewConversation();
					loadConversations();
				} else {
					handleError('Failed to delete conversation.');
				}
			},
			error: function() {
				handleError('Error deleting conversation. Please try again.');
			},
			complete: function() {
				$deleteButton
					.prop('disabled', false)
					.text('Delete Conversation')
					.removeClass('is-confirming-delete')
					.removeClass('button-link-delete');
			}
		});
	}

	/**
	 * Handle model selection change
	 */
	function handleModelChange() {
		const selectedModel = $('#abilities-bridge-model-select').val();

		// Save model selection via AJAX
		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_set_model',
				nonce: abilitiesBridgeData.nonce,
				model: selectedModel
			},
			success: function(response) {
				if (response.success) {
					// Show notification
					appendMessage('system', 'Model changed to ' + response.data.model_name + '. Starting new conversation...');

					// Start a new conversation (force user to create fresh conversation)
					handleNewConversation();

					// Update UI with model info
					updateModelDescription(response.data.model_name, response.data.model_guidance);
				} else {
					handleError(response.data.message || 'Failed to change model');
				}
			},
			error: function() {
				handleError('Failed to change model');
			}
		});
	}

	/**
	 * Handle provider selection change
	 */
	function handleProviderChange() {
		const selectedProvider = $('#abilities-bridge-provider-select').val();

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_set_provider',
				nonce: abilitiesBridgeData.nonce,
				provider: selectedProvider
			},
			success: function(response) {
				if (response.success) {
					appendMessage('system', 'Provider changed to ' + response.data.provider_label + '. Starting new conversation...');

					updateModelOptions(response.data.available_models, response.data.model);

					handleNewConversation();

					updateModelDescription(response.data.model_name, response.data.model_guidance);
				} else {
					handleError(response.data.message || 'Failed to change provider');
				}
			},
			error: function() {
				handleError('Failed to change provider');
			}
		});
	}

	function updateModelDescription(modelName, modelGuidance) {
		const description = modelGuidance || ('Using: ' + modelName);
		$('#abilities-bridge-model-description').text(description);
	}

	/**
	 * Update model options based on provider
	 */
	function updateModelOptions(availableModels, selectedModel) {
		const $select = $('#abilities-bridge-model-select');
		if (!$select.length) {
			return;
		}

		$select.empty();
		Object.keys(availableModels || {}).forEach(function(modelId) {
			const name = availableModels[modelId];
			const $option = $('<option></option>').val(modelId).text(name);
			if (modelId === selectedModel) {
				$option.prop('selected', true);
			}
			$select.append($option);
		});
	}

	/**
	 * Sync provider/model UI to match a loaded conversation's provider
	 */
	function syncProviderFromConversation(conversation) {
		if (!conversation || !conversation.provider) return;

		var currentProvider = $('#abilities-bridge-provider-select').val();
		var conversationProvider = conversation.provider;
		var conversationModel = conversation.model;

		if (currentProvider !== conversationProvider) {
			// Provider differs — update dropdown and sync backend
			$('#abilities-bridge-provider-select').val(conversationProvider);

			$.ajax({
				url: abilitiesBridgeData.ajax_url,
				type: 'POST',
				data: {
					action: 'abilities_bridge_set_provider',
					nonce: abilitiesBridgeData.nonce,
					provider: conversationProvider
				},
				success: function(response) {
					if (response.success) {
						updateModelOptions(response.data.available_models, conversationModel || response.data.model);
						var models = response.data.available_models || {};
						var modelName = models[conversationModel] || conversationModel || response.data.model_name;
						updateModelDescription(modelName, response.data.model_guidance);
					}
				}
			});
		} else if (conversationModel) {
			// Same provider — just sync model selector if needed
			var $modelSelect = $('#abilities-bridge-model-select');
			if ($modelSelect.length && $modelSelect.val() !== conversationModel) {
				$modelSelect.val(conversationModel);
				var modelName = $modelSelect.find('option:selected').text() || conversationModel;
				updateModelDescription(modelName);
			}
		}
	}

	/**
	 * Load provider state and refresh model list
	 */
	function loadProviderState() {
		const $providerSelect = $('#abilities-bridge-provider-select');
		if (!$providerSelect.length) {
			return;
		}

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_provider',
				nonce: abilitiesBridgeData.nonce
			},
			success: function(response) {
				if (response.success) {
					$providerSelect.val(response.data.provider);
					updateModelOptions(response.data.available_models, response.data.model);
					updateModelDescription(response.data.model_name, response.data.model_guidance);
				}
			}
		});
	}

	/**
	 * Update token meter display
	 */
	function updateTokenMeter() {
		if (!currentConversationId) {
			// No conversation, show empty meter
			$('.token-meter-fill').css('width', '0%');
			$('.token-count').text('0 tokens used');
			$('.token-limit-info').text('No conversation');
			return;
		}

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_token_usage',
				nonce: abilitiesBridgeData.nonce,
				conversation_id: currentConversationId
			},
			success: function(response) {
				if (response.success) {
					const data = response.data;

					// Format token count
					$('.token-count').text(formatNumber(data.total) + ' tokens used');

					// Show model info
					if (data.input_limit >= 1000000) {
						$('.token-limit-info').text('1M context available');
						$('.token-model').text(data.model || 'Default model');
					} else if (data.input_limit >= 200000) {
						$('.token-limit-info').text('200k context');
						$('.token-model').text(data.model || 'Default model');
					} else {
						$('.token-limit-info').text('No limit enforced');
					}

					// Update meter bar
					if (data.input_limit > 0) {
						const percentage = Math.min(data.percentage, 100);
						$('.token-meter-fill').css('width', percentage + '%');

						// Color coding (informational only)
						if (percentage < 50) {
							$('.token-meter-fill').css('background', '#10b981'); // Green
						} else if (percentage < 80) {
							$('.token-meter-fill').css('background', '#f59e0b'); // Amber
						} else if (percentage < 95) {
							$('.token-meter-fill').css('background', '#ef4444'); // Red
						} else {
							$('.token-meter-fill').css('background', '#991b1b'); // Dark Red
						}
					} else {
						// No known limit - show full blue bar
						$('.token-meter-fill').css('width', '100%');
						$('.token-meter-fill').css('background', '#3b82f6'); // Blue
					}

					// Add tooltip with breakdown
					$('.token-meter-bar').attr('title',
						'System: ' + formatNumber(data.system) + ' tokens\n' +
						'Messages: ' + formatNumber(data.messages) + ' tokens\n' +
						'Tools: ' + formatNumber(data.tools) + ' tokens\n' +
						'Total: ' + formatNumber(data.total) + ' tokens\n' +
						(data.remaining > 0 ? 'Remaining: ' + formatNumber(data.remaining) + ' tokens' : '')
					);
				}
			}
		});
	}

	/**
	 * Format large numbers with commas
	 */
	function formatNumber(num) {
		if (num === undefined || num === null) return '0';
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	/**
	 * Load and display activity history for current conversation
	 */
	function loadActivityHistory() {
		if (!currentConversationId) {
			return;
		}

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_conversation_activity',
				nonce: abilitiesBridgeData.nonce,
				conversation_id: currentConversationId
			},
			success: function(response) {
				if (response.success && response.data) {
					const activities = response.data.activities;
					const count = response.data.count;

					// Update count
					$('#activity-count').text(count);

					// Show/hide dropdown
					if (count > 0) {
						$('#abilities-bridge-activity-history').show();
						renderActivityHistory(activities);
					} else {
						$('#abilities-bridge-activity-history').hide();
					}
				}
			},
			error: function() {
			}
		});
	}

	/**
	 * Render activity history in the dropdown
	 */
	function renderActivityHistory(activities) {
		const $list = $('#activity-history-list');
		$list.empty();

		if (activities.length === 0) {
			$list.append('<p class="activity-history-empty">No activity yet...</p>');
			return;
		}

		activities.forEach(function(activity) {
			const timestamp = new Date(activity.timestamp).toLocaleString();
			const icon = activity.success ? '✅' : '❌';
			const statusClass = activity.success ? 'success' : 'error';

			const itemHtml = `
				<div class="activity-history-item ${statusClass}">
					<div class="activity-history-header">
						<span class="activity-history-icon">${icon}</span>
						<span class="activity-history-function">${escapeHtml(activity.function || '')}</span>
						<span class="activity-history-timestamp">${timestamp}</span>
					</div>
					<div class="activity-history-description">${escapeHtml(activity.description)}</div>
					${activity.error ? `<div class="activity-history-error">${escapeHtml(activity.error)}</div>` : ''}
				</div>
			`;

			$list.append(itemHtml);
		});
	}

	/**
	 * Toggle activity history dropdown
	 */
	function toggleActivityHistory() {
		const $content = $('#activity-history-content');
		const $arrow = $('.activity-history-arrow');

		$content.slideToggle(200);
		$arrow.toggleClass('rotated');

		// Load history if opening and not already loaded
		if ($content.is(':visible') && $('#activity-history-list .activity-history-item').length === 0) {
			loadActivityHistory();
		}
	}

	/**
	 * Show summary continuation warning
	 */
	function showSummaryWarning(type, conversationId, tokenCount) {
		const $chatMessages = $('#abilities-bridge-chat-messages');

		let warningHtml = '';
		if (type === 'first') {
			// 10k tokens - friendly first warning
			warningHtml = `
				<div class="abilities-bridge-message abilities-bridge-message-info summary-warning" data-type="first">
					<div class="abilities-bridge-message-content">
						<p><strong>🎯 This conversation is getting long!</strong></p>
						<p>Current context: ${formatNumber(tokenCount)} / 200,000 tokens</p>
						<p>Larger token contexts mean higher API costs. You can summarize this conversation and start fresh (saves money) or continue as-is.</p>
						<div style="margin-top: 15px;">
							<button class="button button-primary summarize-conversation-btn" data-conversation-id="${conversationId}">
								📝 Summarize & Start Fresh
							</button>
							<button class="button dismiss-summary-warning" style="margin-left: 10px;">
								➡️ Continue Anyway
							</button>
						</div>
					</div>
				</div>
			`;
		} else if (type === 'final') {
			// 15k tokens - final warning
			warningHtml = `
				<div class="abilities-bridge-message abilities-bridge-message-warning summary-warning" data-type="final">
					<div class="abilities-bridge-message-content">
						<p><strong>⚠️ Approaching Context Limit</strong></p>
						<p>Current context: ${formatNumber(tokenCount)} / 200,000 tokens</p>
						<p>This is your final warning. We strongly recommend summarizing now before reaching the hard limit.</p>
						<div style="margin-top: 15px;">
							<button class="button button-primary summarize-conversation-btn" data-conversation-id="${conversationId}">
								📝 Summarize Now
							</button>
							<button class="button dismiss-summary-warning" style="margin-left: 10px;">
								Continue (Not Recommended)
							</button>
						</div>
					</div>
				</div>
			`;
		}

		$chatMessages.append(warningHtml);
		$chatMessages.scrollTop($chatMessages[0].scrollHeight);
	}

	/**
	 * Handle summarize conversation button click
	 */
	function handleSummarizeConversation(conversationId) {
		// Disable button to prevent double-click
		$('.summarize-conversation-btn').prop('disabled', true).text('Creating summary...');

		// Send summary request to AI
		const summaryPrompt = "Please create a comprehensive summary of our entire conversation so far. Include:\n\n" +
			"1. Main topics and objectives discussed\n" +
			"2. Key decisions and conclusions reached\n" +
			"3. Important technical details and context\n" +
			"4. Current state and next steps\n\n" +
			"Make this summary detailed enough that we can continue our work seamlessly with this context.";

		// Show the summary request as a user message
		setLoading(true);
		appendMessage('user', summaryPrompt);

		// Remove warning immediately - user has taken action
		$('.summary-warning').fadeOut(300, function() { $(this).remove(); });

		// Show progress indicators
		showSimpleProgress();
		startActivityPolling(conversationId);

		// Send the summary request via AJAX
		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			timeout: 180000, // 3 minutes
			data: {
				action: 'abilities_bridge_send_message',
				nonce: abilitiesBridgeData.nonce,
				message: summaryPrompt,
				conversation_id: conversationId,
				plan_mode: 'false'
			},
			success: function(response) {
				if (response.success) {
					// Summary was generated successfully
					const summaryText = response.data.response;

					// Display the summary response
					appendMessage('assistant', summaryText);

					// Show the "Start New Session" button
					const buttonHtml = `
						<div class="summary-continuation-actions" style="margin: 15px 0; padding: 15px; background: #f0f6fc; border-radius: 6px; border-left: 4px solid #0969da;">
							<p style="margin: 0 0 10px 0;"><strong>💫 Summary created!</strong></p>
							<p style="margin: 0 0 15px 0; font-size: 13px;">Click below to start a new session with fresh token context. The summary will be used as context for the AI.</p>
							<button class="button button-primary start-new-session-btn" data-conversation-id="${conversationId}" data-summary="${escapeHtml(summaryText)}">
								✨ Start New Session with Summary
							</button>
						</div>
					`;

					$('#abilities-bridge-chat-messages').append(buttonHtml);

					// Scroll to bottom
					const $chatMessages = $('#abilities-bridge-chat-messages');
					$chatMessages.scrollTop($chatMessages[0].scrollHeight);
				} else {
					// Error creating summary
					handleError('Failed to create summary: ' + (response.data.message || 'Unknown error'));
					$('.summarize-conversation-btn').prop('disabled', false).text('📝 Try Again');
				}

				// Stop progress indicators
				stopActivityPolling();
				hideSimpleProgress();
				setLoading(false);
			},
			error: function(xhr, status, error) {
				if (status === 'timeout') {
					handleError('Summary request timed out. Please try again.');
				} else {
					handleError('Error creating summary: ' + error);
				}
				$('.summarize-conversation-btn').prop('disabled', false).text('📝 Try Again');

				// Stop progress indicators
				stopActivityPolling();
				hideSimpleProgress();
				setLoading(false);
			}
		});
	}

	/**
	 * Handle start new session button click
	 */
	function handleStartNewSession(parentConversationId, summaryText) {
		$('.start-new-session-btn').prop('disabled', true).text('Creating new session...');

		$.ajax({
			url: abilitiesBridgeData.ajax_url,
			type: 'POST',
			data: {
				action: 'abilities_bridge_create_summary_continuation',
				nonce: abilitiesBridgeData.nonce,
				parent_conversation_id: parentConversationId,
				summary_text: summaryText
			},
			success: function(response) {
				if (response.success) {
					// Switch to the new conversation
					currentConversationId = response.data.new_conversation_id;

					// Add visual separator
					const separatorHtml = `
						<div class="conversation-continuation-marker">
							<div class="continuation-line"></div>
							<div class="continuation-text">💫 Continuing with fresh context</div>
							<div class="continuation-line"></div>
						</div>
					`;
					$('#abilities-bridge-chat-messages').append(separatorHtml);

					// Show the summary as a visible first message
					appendMessage('user', summaryText);

					// Update UI
					updateConversationInfo();
					updateTokenMeter();

					// Remove the action buttons
					$('.summary-continuation-actions').fadeOut(300, function() { $(this).remove(); });
				} else {
					handleError('Failed to create new session: ' + (response.data.message || 'Unknown error'));
					$('.start-new-session-btn').prop('disabled', false).text('✨ Try Again');
				}
			},
			error: function(xhr, status, error) {
				handleError('Error creating new session: ' + error);
				$('.start-new-session-btn').prop('disabled', false).text('✨ Try Again');
			}
		});
	}

	/**
	 * Escape HTML for use in data attributes
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Bind summary continuation events
	 */
	function bindSummaryContinuationEvents() {
		// Handle "Summarize & Start Fresh" button
		$(document).on('click', '.summarize-conversation-btn', function() {
			const conversationId = parseInt($(this).data('conversation-id'));
			handleSummarizeConversation(conversationId);
		});

		// Handle "Dismiss" button
		$(document).on('click', '.dismiss-summary-warning', function() {
			$(this).closest('.summary-warning').fadeOut(300, function() {
				$(this).remove();
			});
		});

		// Handle "Start New Session" button
		$(document).on('click', '.start-new-session-btn', function() {
			const parentConversationId = parseInt($(this).data('conversation-id'));
			const summaryText = $(this).data('summary');
			handleStartNewSession(parentConversationId, summaryText);
		});
	}

	/**
	 * Bind activity history events
	 */
	function bindActivityHistoryEvents() {
		$('#activity-history-toggle').on('click', toggleActivityHistory);
	}

	// Initialize on document ready
	$(document).ready(function() {
		init();
		bindActivityHistoryEvents();
		bindSummaryContinuationEvents();
	});

})(jQuery);
