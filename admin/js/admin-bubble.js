(function($) {
	'use strict';

	const storageKeys = {
		open: 'abilitiesBridgeBubbleOpen',
		conversationId: 'abilitiesBridgeBubbleConversationId'
	};

	const state = {
		currentConversationId: null,
		currentRequest: null,
		currentJobToken: null,
		jobPollTimer: null,
		jobStartedAt: 0,
		supersedeJobToken: null,
		enqueueInFlight: false,
		stopRequestedDuringEnqueue: false,
		pendingReplacementPayload: null,
		consecutiveJobPollFailures: 0,
		jobPollRequestId: 0,
		foregroundRunInFlight: false,
		isOpen: false,
		attachmentManager: null
	};

	const selectors = {
		root: '#abilities-bridge-bubble-root',
		launcher: '#abilities-bridge-bubble-launcher',
		panel: '#abilities-bridge-bubble-panel',
		close: '#abilities-bridge-bubble-close',
		status: '#abilities-bridge-bubble-status',
		conversationSelect: '#abilities-bridge-bubble-conversation-select',
		newConversation: '#abilities-bridge-bubble-new',
		messages: '#abilities-bridge-bubble-messages',
		form: '#abilities-bridge-bubble-form',
		input: '#abilities-bridge-bubble-input',
		send: '#abilities-bridge-bubble-send',
		stop: '#abilities-bridge-bubble-stop',
		attachmentRoot: '#abilities-bridge-bubble-attachment-panel',
		fileInput: '#abilities-bridge-bubble-image-input',
		uploadImage: '#abilities-bridge-bubble-upload-image',
		captureScreenshot: '#abilities-bridge-bubble-capture-screenshot',
		attachmentStatus: '#abilities-bridge-bubble-attachment-status',
		attachmentPreview: '#abilities-bridge-bubble-attachment-preview',
		tokenCount: '#abilities-bridge-bubble-token-count',
		tokenLimit: '#abilities-bridge-bubble-token-limit',
		tokenFill: '#abilities-bridge-bubble-token-fill'
	};

	function init() {
		if (!$(selectors.root).length) {
			return;
		}

		bindEvents();
		initAttachments();
		renderWelcome();
		loadConversations();
		setPanelOpen(false);
		setStatus(abilitiesBridgeBubbleData.i18n.ready || 'Ready');

		const shouldOpen = window.sessionStorage.getItem(storageKeys.open) === '1';
		if (shouldOpen) {
			setPanelOpen(true);
		}
	}

	function initAttachments() {
		if (!window.AbilitiesBridgeChatAttachments) {
			return;
		}

		state.attachmentManager = window.AbilitiesBridgeChatAttachments.create({
			config: abilitiesBridgeBubbleData.attachments || {},
			selectors: {
				root: selectors.attachmentRoot,
				fileInput: selectors.fileInput,
				uploadButton: selectors.uploadImage,
				screenshotButton: selectors.captureScreenshot,
				status: selectors.attachmentStatus,
				preview: selectors.attachmentPreview
			},
			onStatus: function(message) {
				if (message) {
					setStatus(message);
				}
			}
		});
	}

	function bindEvents() {
		$(selectors.launcher).on('click', function() {
			setPanelOpen(!state.isOpen);
		});

		$(selectors.close).on('click', function() {
			setPanelOpen(false);
		});

		$(selectors.form).on('submit', handleSubmit);
		$(selectors.stop).on('click', stopCurrentJob);
		$(selectors.newConversation).on('click', function() {
			handleNewConversation();
		});
		$(selectors.conversationSelect).on('change', function() {
			const conversationId = $(this).val();
			if (conversationId) {
				loadConversation(conversationId);
			}
		});
		$(selectors.input).on('keydown', function(event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				event.preventDefault();
				$(selectors.form).trigger('submit');
			}
		});
	}

	function setPanelOpen(isOpen) {
		state.isOpen = isOpen;
		$(selectors.panel)
			.prop('hidden', !isOpen)
			.css('display', isOpen ? 'flex' : 'none');
		$(selectors.launcher).attr('aria-expanded', isOpen ? 'true' : 'false');
		window.sessionStorage.setItem(storageKeys.open, isOpen ? '1' : '0');

		if (isOpen) {
			$(selectors.input).trigger('focus');
		}
	}

	function setStatus(message) {
		const $status = $(selectors.status);
		if ($status.length) {
			$status.text(message || '');
		}
	}

	function setConversationId(conversationId) {
		state.currentConversationId = conversationId ? parseInt(conversationId, 10) : null;

		if (state.currentConversationId) {
			window.sessionStorage.setItem(storageKeys.conversationId, String(state.currentConversationId));
		} else {
			window.sessionStorage.removeItem(storageKeys.conversationId);
		}
	}

	function renderWelcome() {
		$(selectors.messages).html(`
			<div class="abilities-bridge-bubble-message assistant-message">
				<div class="abilities-bridge-bubble-role">AI</div>
				<div class="abilities-bridge-bubble-content">${renderMarkdown(abilitiesBridgeBubbleData.i18n.welcome)}</div>
			</div>
		`);
	}

	function handleSubmit(event) {
		event.preventDefault();

		const message = $(selectors.input).val().trim();
		const attachments = state.attachmentManager ? state.attachmentManager.getPayload() : [];
		if (!message && attachments.length === 0) {
			return;
		}
		const payload = { message: message, attachments: attachments };
		if (state.enqueueInFlight && state.stopRequestedDuringEnqueue && state.pendingReplacementPayload) {
			appendMessage('error', 'A replacement request is already waiting. The newer text was not queued.');
			return;
		}

		appendMessage('user', buildLocalDisplayContent(message, attachments));
		$(selectors.input).val('');
		if (state.attachmentManager) {
			state.attachmentManager.clear();
		}

		if (state.enqueueInFlight && state.stopRequestedDuringEnqueue) {
			state.pendingReplacementPayload = payload;
			setLoading(true);
			$(selectors.stop).prop('hidden', true).hide();
			setStatus('Saving the replacement request…');
			return;
		}

		enqueueChatPayload(payload);
	}

	function enqueueChatPayload(payload) {
		setLoading(true);
		setStatus(abilitiesBridgeBubbleData.i18n.sending || 'Processing...');
		state.enqueueInFlight = true;
		state.stopRequestedDuringEnqueue = false;
		let replacementAfterStop = null;

		const request = $.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_chat_job_enqueue',
				nonce: abilitiesBridgeBubbleData.nonce,
				message: payload.message,
				attachments: JSON.stringify(payload.attachments),
				conversation_id: state.currentConversationId,
				supersede_job: state.supersedeJobToken || ''
			}
		});
		state.currentRequest = request;

		request.done(function(response) {
			if (!response.success) {
				if (state.stopRequestedDuringEnqueue) {
					replacementAfterStop = state.pendingReplacementPayload;
					state.pendingReplacementPayload = null;
					state.stopRequestedDuringEnqueue = false;
				}
				handleError(extractErrorMessage(response));
				finishJobUi();
				return;
			}

			if (response.data.conversation_id) {
				setConversationId(response.data.conversation_id);
				loadConversations();
			}

			if (state.stopRequestedDuringEnqueue) {
				state.supersedeJobToken = response.data.job;
				cancelJobWithRetry(response.data.job);
				state.stopRequestedDuringEnqueue = false;
				replacementAfterStop = state.pendingReplacementPayload;
				state.pendingReplacementPayload = null;
				finishJobUi();
				return;
			}

			state.currentJobToken = response.data.job;
			state.supersedeJobToken = null;
			startJobPolling(response.data.job, 0);
		}).fail(function() {
			if (state.stopRequestedDuringEnqueue) {
				replacementAfterStop = state.pendingReplacementPayload;
				state.pendingReplacementPayload = null;
				state.stopRequestedDuringEnqueue = false;
			}
			handleError(abilitiesBridgeBubbleData.i18n.connectionError);
			finishJobUi();
		}).always(function() {
			if (state.currentRequest === request) {
				state.currentRequest = null;
			}
			state.enqueueInFlight = false;
			if (replacementAfterStop) {
				enqueueChatPayload(replacementAfterStop);
			}
		});
	}

	function startJobPolling(jobToken, elapsed) {
		state.currentJobToken = jobToken;
		state.jobStartedAt = Date.now() - ((elapsed || 0) * 1000);
		state.consecutiveJobPollFailures = 0;
		pollJob();
	}

	function pollJob() {
		if (!state.currentJobToken) return;
		const token = state.currentJobToken;
		clearTimeout(state.jobPollTimer);
		state.jobPollTimer = null;
		const requestId = ++state.jobPollRequestId;

		$.post(abilitiesBridgeBubbleData.ajaxUrl, {
			action: 'abilities_bridge_chat_job_status',
			nonce: abilitiesBridgeBubbleData.nonce,
			job: token
		}).done(function(response) {
			if (token !== state.currentJobToken || requestId !== state.jobPollRequestId) return;
			if (!response.success) {
				state.currentJobToken = null;
				handleError(extractErrorMessage(response));
				finishJobUi();
				return;
			}
			state.consecutiveJobPollFailures = 0;
			const job = response.data;
			const elapsed = Math.max(job.elapsed || 0, Math.floor((Date.now() - state.jobStartedAt) / 1000));
			setStatus((job.last_activity || (job.status === 'preparing' ? 'Saving request' : 'Working')) + ' · ' + formatElapsed(elapsed));

			if (job.status === 'missing') {
				state.currentJobToken = null;
				appendMessage('error', 'This saved background job is no longer available. Reload the conversation to check for its final answer.');
				finishJobUi();
				return;
			}

			if (job.status === 'completed') {
				state.currentJobToken = null;
				loadConversation(job.conversation_id, false);
				finishJobUi();
				return;
			}
			if (job.status === 'failed' || job.status === 'uncertain') {
				state.currentJobToken = null;
				appendMessage(job.status === 'uncertain' ? 'system' : 'error', job.error_message || (job.status === 'uncertain' ? 'The last step has an uncertain outcome and may have been billed. Check before trying again.' : 'The background chat job failed.'));
				finishJobUi();
				return;
			}
			if (job.status === 'cancelled') {
				state.currentJobToken = null;
				finishJobUi();
				return;
			}

			if (job.background_unavailable) {
				renderForegroundOffer(token);
			}

			state.jobPollTimer = setTimeout(pollJob, elapsed >= 60 ? 5000 : 2000);
		}).fail(function() {
			if (token === state.currentJobToken && requestId === state.jobPollRequestId) {
				state.consecutiveJobPollFailures++;
				if (state.consecutiveJobPollFailures >= 12) {
					state.currentJobToken = null;
					appendMessage('error', 'Status checks repeatedly failed. The saved job may still be running; reopen this conversation to resume monitoring.');
					finishJobUi();
					return;
				}
				state.jobPollTimer = setTimeout(pollJob, 5000);
			}
		});
	}

	function stopCurrentJob() {
		if (!state.currentJobToken && state.enqueueInFlight) {
			state.stopRequestedDuringEnqueue = true;
			finishJobUi();
			appendMessage('system', 'Stopping as soon as this request is safely saved. You can enter a replacement question now.');
			return;
		}
		if (!state.currentJobToken) return;
		const token = state.currentJobToken;
		state.supersedeJobToken = token;
		state.currentJobToken = null;
		clearTimeout(state.jobPollTimer);
		finishJobUi();
		appendMessage('system', 'Stopped. A step that was already running may still finish (and be billed) at the provider; its result was discarded.');
		cancelJobWithRetry(token);
	}

	function cancelJobWithRetry(jobToken, attempt) {
		const retryAttempt = attempt || 0;
		$.post(abilitiesBridgeBubbleData.ajaxUrl, {
			action: 'abilities_bridge_chat_job_cancel',
			nonce: abilitiesBridgeBubbleData.nonce,
			job: jobToken
		}).done(function(response) {
			if (!response.success && retryAttempt < 4) {
				setTimeout(function() { cancelJobWithRetry(jobToken, retryAttempt + 1); }, Math.pow(2, retryAttempt) * 1000);
			} else if (!response.success) {
				appendMessage('error', 'The Stop request could not be confirmed. It will be attempted again with a replacement question.');
			}
		}).fail(function() {
			if (retryAttempt < 4) {
				setTimeout(function() { cancelJobWithRetry(jobToken, retryAttempt + 1); }, Math.pow(2, retryAttempt) * 1000);
			} else {
				appendMessage('error', 'The Stop request could not be confirmed. It will be attempted again with a replacement question.');
			}
		});
	}

	function runJobInForeground(token) {
		if (state.foregroundRunInFlight) return;
		state.foregroundRunInFlight = true;
		clearTimeout(state.jobPollTimer);
		state.jobPollTimer = null;
		++state.jobPollRequestId;
		$('#abilities-bridge-bubble-run-foreground').prop('disabled', true).text('Starting foreground run…');
		state.currentRequest = $.post(abilitiesBridgeBubbleData.ajaxUrl, {
			action: 'abilities_bridge_chat_job_run_foreground',
			nonce: abilitiesBridgeBubbleData.nonce,
			job: token
		}).done(function(response) {
			if (!response.success) {
				handleError(extractErrorMessage(response));
			}
		}).fail(function() {
			handleError('The foreground takeover request failed. Background monitoring will continue.');
		}).always(function() {
			state.currentRequest = null;
			state.foregroundRunInFlight = false;
			$('#abilities-bridge-bubble-run-foreground').remove();
			clearTimeout(state.jobPollTimer);
			state.jobPollTimer = null;
			if (token === state.currentJobToken) pollJob();
		});
	}

	function renderForegroundOffer(token) {
		if (state.foregroundRunInFlight) return;
		if ($('#abilities-bridge-bubble-run-foreground').length) return;
		$('<button>', {
			id: 'abilities-bridge-bubble-run-foreground',
			type: 'button',
			class: 'button button-small',
			text: 'Run saved request in foreground'
		}).on('click', function() {
			runJobInForeground(token);
		}).appendTo(selectors.status);
	}

	function resumeJob(conversationId) {
		if (!conversationId || state.currentJobToken) return;
		$.post(abilitiesBridgeBubbleData.ajaxUrl, {
			action: 'abilities_bridge_chat_job_resume',
			nonce: abilitiesBridgeBubbleData.nonce,
			conversation_id: conversationId
		}).done(function(response) {
			if (!response.success || !response.data.job) return;
			const job = response.data.job;
			if (['preparing', 'queued', 'dispatching', 'running'].indexOf(job.status) !== -1) {
				setLoading(true);
				startJobPolling(job.job, job.elapsed);
			} else if (job.status === 'uncertain') {
				appendMessage('system', job.error_message || 'The prior request has an uncertain outcome and may have been billed.');
			}
		});
	}

	function resumeLatestJob() {
		if (state.currentConversationId || state.currentJobToken) return;
		$.post(abilitiesBridgeBubbleData.ajaxUrl, {
			action: 'abilities_bridge_chat_job_resume',
			nonce: abilitiesBridgeBubbleData.nonce,
			conversation_id: 0
		}).done(function(response) {
			if (!response.success || !response.data.job) return;
			const job = response.data.job;
			if (['preparing', 'queued', 'dispatching', 'running', 'uncertain'].indexOf(job.status) !== -1) {
				loadConversation(job.conversation_id, false);
			}
		});
	}

	function finishJobUi() {
		setLoading(false);
		updateTokenMeter();
		setStatus(abilitiesBridgeBubbleData.i18n.ready || 'Ready');
		$(selectors.input).trigger('focus');
	}

	function formatElapsed(seconds) {
		return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
	}

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

	function handleNewConversation() {
		setConversationId(null);
		if (state.attachmentManager) {
			state.attachmentManager.clear();
		}
		$(selectors.conversationSelect).val('');
		renderWelcome();
		updateTokenMeter();
		setStatus(abilitiesBridgeBubbleData.i18n.ready || 'Ready');
	}

	function loadConversations() {
		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_conversations',
				nonce: abilitiesBridgeBubbleData.nonce
			}
		}).done(function(response) {
			if (!response.success) {
				return;
			}

			updateConversationList(response.data);
		}).fail(function() {
			setStatus(abilitiesBridgeBubbleData.i18n.loadConversationsFailed || 'Unable to load saved conversations.');
		});
	}

	function updateConversationList(data) {
		const $select = $(selectors.conversationSelect);
		const conversations = data && Array.isArray(data.conversations) ? data.conversations : [];
		const preferredConversation = state.currentConversationId || window.sessionStorage.getItem(storageKeys.conversationId);

		$select.empty();
		$select.append(`<option value="">${escapeHtml(abilitiesBridgeBubbleData.i18n.selectConversation)}</option>`);

		if (!conversations.length) {
			$select.append(`<option value="">${escapeHtml(abilitiesBridgeBubbleData.i18n.emptyConversations)}</option>`);
			return;
		}

		conversations.forEach(function(conversation) {
			const title = formatConversationTitle(conversation);
			const selected = String(preferredConversation) === String(conversation.id) ? ' selected' : '';
			$select.append(`<option value="${conversation.id}"${selected}>${escapeHtml(title)}</option>`);
		});

		if (preferredConversation && conversations.some((conversation) => String(conversation.id) === String(preferredConversation))) {
			if (!state.currentConversationId) {
				loadConversation(preferredConversation, false);
			}
		} else if (state.currentConversationId) {
			$select.val(String(state.currentConversationId));
		} else {
			resumeLatestJob();
		}
	}

	function loadConversation(conversationId, announce) {
		const shouldAnnounce = announce !== false;

		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_load_conversation',
				nonce: abilitiesBridgeBubbleData.nonce,
				conversation_id: conversationId
			}
		}).done(function(response) {
			if (!response.success) {
				handleError(abilitiesBridgeBubbleData.i18n.loadFailed);
				return;
			}

			setConversationId(conversationId);
			renderMessages(response.data.messages || []);
			updateTokenMeter();
			resumeJob(conversationId);
			if (shouldAnnounce) {
				setStatus(abilitiesBridgeBubbleData.i18n.conversationLoaded || 'Conversation loaded.');
			}
		}).fail(function() {
			handleError(abilitiesBridgeBubbleData.i18n.loadFailed);
		});
	}

	function renderMessages(messages) {
		const $messages = $(selectors.messages);
		$messages.empty();

		if (!messages.length) {
			renderWelcome();
			return;
		}

		messages.forEach(function(message) {
			appendMessage(message.role, message.content);
		});
	}

	function appendMessage(role, content) {
		const messageClass = role === 'user' ? 'user-message' : role === 'error' ? 'error-message' : role === 'system' ? 'system-message' : 'assistant-message';
		const roleLabel = role === 'user' ? 'You' : role === 'error' ? 'Error' : role === 'system' ? 'System' : 'AI';
		const html = role === 'assistant' ? renderMarkdown(extractDisplayText(content)) : renderPlainContent(content);
		$(selectors.messages).append(`
			<div class="abilities-bridge-bubble-message ${messageClass}">
				<div class="abilities-bridge-bubble-role">${roleLabel}</div>
				<div class="abilities-bridge-bubble-content">${html}</div>
			</div>
		`);
		const element = $(selectors.messages).get(0);
		if (element) {
			element.scrollTop = element.scrollHeight;
		}
	}

	function handleError(message) {
		appendMessage('error', message);
		setStatus(abilitiesBridgeBubbleData.i18n.attention || 'Something needs attention.');
	}

	function setLoading(loading) {
		$(selectors.input).prop('disabled', loading);
		$(selectors.newConversation).prop('disabled', loading);
		$(selectors.conversationSelect).prop('disabled', loading);
		if (state.attachmentManager) {
			state.attachmentManager.setDisabled(loading);
		}
		$(selectors.send)
			.prop('disabled', loading)
			.text(loading ? abilitiesBridgeBubbleData.i18n.sending : abilitiesBridgeBubbleData.i18n.send);
		$(selectors.send).prop('hidden', loading);
		$(selectors.stop).prop('hidden', !loading);
	}

	function updateTokenMeter() {
		if (!state.currentConversationId) {
			$(selectors.tokenCount).text(`0 ${abilitiesBridgeBubbleData.i18n.tokensUsed}`);
			$(selectors.tokenLimit).text(abilitiesBridgeBubbleData.i18n.noConversation);
			$(selectors.tokenFill).css({ width: '0%', background: '#2563eb' });
			return;
		}

		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_token_usage',
				nonce: abilitiesBridgeBubbleData.nonce,
				conversation_id: state.currentConversationId
			}
		}).done(function(response) {
			if (!response.success) {
				return;
			}

			const data = response.data;
			const percentage = data.input_limit > 0 ? Math.min(data.percentage || 0, 100) : 0;
			let color = '#10b981';
			if (percentage >= 80) {
				color = '#dc2626';
			} else if (percentage >= 50) {
				color = '#f59e0b';
			}

			$(selectors.tokenCount).text(`${formatNumber(data.total)} ${abilitiesBridgeBubbleData.i18n.tokensUsed}`);
			$(selectors.tokenLimit).text(data.input_limit > 0 ? `${formatNumber(data.remaining || 0)} remaining` : abilitiesBridgeBubbleData.i18n.noConversation);
			$(selectors.tokenFill).css({ width: `${percentage}%`, background: color });
		}).fail(function() {
			setStatus(abilitiesBridgeBubbleData.i18n.loadTokenFailed || 'Unable to load token usage.');
		});
	}

	function formatConversationTitle(conversation) {
		const rawTitle = conversation.title || `Conversation #${conversation.id}`;
		const dateValue = conversation.updated_at || conversation.created_at;
		const title = rawTitle.length > 48 ? `${rawTitle.slice(0, 48)}...` : rawTitle;
		return `#${conversation.id} - ${title}${dateValue ? ' \u2192 ' + formatDate(dateValue) : ''}`;
	}

	function formatDate(dateString) {
		const date = new Date(dateString);
		if (Number.isNaN(date.getTime())) {
			return '';
		}
		return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
	}

	function formatNumber(value) {
		const number = value || 0;
		return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function extractErrorMessage(response) {
		return response && response.data && response.data.message ? response.data.message : abilitiesBridgeBubbleData.i18n.connectionError;
	}

	function extractDisplayText(content) {
		if (window.AbilitiesBridgeChatAttachments) {
			return window.AbilitiesBridgeChatAttachments.extractDisplayText(content);
		}

		if (Array.isArray(content)) {
			return content.map(function(block) {
				if (block && block.type === 'text' && block.text) {
					return block.text;
				}
				if (block && block.type === 'tool_use' && block.name) {
					return `Tool requested: ${block.name}`;
				}
				if (block && block.type === 'tool_result' && block.content) {
					return block.content;
				}
				return '';
			}).filter(Boolean).join('\n\n');
		}

		if (content && typeof content === 'object') {
			return JSON.stringify(content, null, 2);
		}

		return String(content || '');
	}

	function renderPlainContent(content) {
		if (window.AbilitiesBridgeChatAttachments) {
			return window.AbilitiesBridgeChatAttachments.renderMessageContent(content);
		}

		return escapeHtml(extractDisplayText(content)).replace(/\n/g, '<br>');
	}

	function renderMarkdown(content) {
		const text = extractDisplayText(content).replace(/\r\n/g, '\n');
		if (!text.trim()) {
			return '';
		}

		const codeBlocks = [];
		let escaped = escapeHtml(text).replace(/```([\s\S]*?)```/g, function(match, code) {
			const token = `@@CODEBLOCK${codeBlocks.length}@@`;
			codeBlocks.push(`<pre><code>${code.trim()}</code></pre>`);
			return token;
		});

		escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');
		escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		escaped = escaped.replace(/(^|[^*])\*([^\n*]+)\*(?=[^*]|$)/g, '$1<em>$2</em>');

		const lines = escaped.split('\n');
		let html = '';
		let inUl = false;
		let inOl = false;
		let paragraph = [];

		function flushParagraph() {
			if (paragraph.length) {
				html += `<p>${paragraph.join('<br>')}</p>`;
				paragraph = [];
			}
		}

		function closeLists() {
			if (inUl) {
				html += '</ul>';
				inUl = false;
			}
			if (inOl) {
				html += '</ol>';
				inOl = false;
			}
		}

		lines.forEach(function(rawLine) {
			const line = rawLine.trim();
			if (!line) {
				flushParagraph();
				closeLists();
				return;
			}

			if (/^@@CODEBLOCK\d+@@$/.test(line)) {
				flushParagraph();
				closeLists();
				html += line;
				return;
			}

			if (/^[-*]\s+/.test(line)) {
				flushParagraph();
				if (inOl) {
					html += '</ol>';
					inOl = false;
				}
				if (!inUl) {
					html += '<ul>';
					inUl = true;
				}
				html += `<li>${line.replace(/^[-*]\s+/, '')}</li>`;
				return;
			}

			if (/^\d+\.\s+/.test(line)) {
				flushParagraph();
				if (inUl) {
					html += '</ul>';
					inUl = false;
				}
				if (!inOl) {
					html += '<ol>';
					inOl = true;
				}
				html += `<li>${line.replace(/^\d+\.\s+/, '')}</li>`;
				return;
			}

			closeLists();
			paragraph.push(line);
		});

		flushParagraph();
		closeLists();

		codeBlocks.forEach(function(block, index) {
			html = html.replace(`@@CODEBLOCK${index}@@`, block);
		});

		return html;
	}

	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function(match) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[match];
		});
	}

	$(init);
})(jQuery);
