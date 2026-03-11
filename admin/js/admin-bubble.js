(function($) {
	'use strict';

	const storageKeys = {
		open: 'abilitiesBridgeBubbleOpen',
		conversationId: 'abilitiesBridgeBubbleConversationId'
	};

	const state = {
		currentConversationId: null,
		currentRequest: null,
		isOpen: false
	};

	const selectors = {
		root: '#abilities-bridge-bubble-root',
		launcher: '#abilities-bridge-bubble-launcher',
		panel: '#abilities-bridge-bubble-panel',
		close: '#abilities-bridge-bubble-close',
		status: '#abilities-bridge-bubble-status',
		provider: '#abilities-bridge-bubble-provider',
		model: '#abilities-bridge-bubble-model',
		conversationSelect: '#abilities-bridge-bubble-conversation-select',
		newConversation: '#abilities-bridge-bubble-new',
		messages: '#abilities-bridge-bubble-messages',
		form: '#abilities-bridge-bubble-form',
		input: '#abilities-bridge-bubble-input',
		send: '#abilities-bridge-bubble-send',
		tokenCount: '#abilities-bridge-bubble-token-count',
		tokenLimit: '#abilities-bridge-bubble-token-limit',
		tokenFill: '#abilities-bridge-bubble-token-fill'
	};

	function init() {
		if (!$(selectors.root).length) {
			return;
		}

		bindEvents();
		renderWelcome();
		loadProviderState();
		loadConversations();
		setPanelOpen(false);
		setStatus(abilitiesBridgeBubbleData.i18n.ready || 'Ready');

		const shouldOpen = window.sessionStorage.getItem(storageKeys.open) === '1';
		if (shouldOpen) {
			setPanelOpen(true);
		}
	}

	function bindEvents() {
		$(selectors.launcher).on('click', function() {
			setPanelOpen(!state.isOpen);
		});

		$(selectors.close).on('click', function() {
			setPanelOpen(false);
		});

		$(selectors.form).on('submit', handleSubmit);
		$(selectors.newConversation).on('click', function() {
			handleNewConversation(false);
		});
		$(selectors.conversationSelect).on('change', function() {
			const conversationId = $(this).val();
			if (conversationId) {
				loadConversation(conversationId);
			}
		});
		$(selectors.provider).on('change', handleProviderChange);
		$(selectors.model).on('change', handleModelChange);
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
		if (!message) {
			return;
		}

		appendMessage('user', message);
		$(selectors.input).val('');
		setLoading(true);
		setStatus(abilitiesBridgeBubbleData.i18n.sending || 'Processing...');

		state.currentRequest = $.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_send_message',
				nonce: abilitiesBridgeBubbleData.nonce,
				message: message,
				conversation_id: state.currentConversationId
			}
		}).done(function(response) {
			if (!response.success) {
				handleError(extractErrorMessage(response));
				return;
			}

			if (response.data.conversation_id) {
				setConversationId(response.data.conversation_id);
				loadConversations();
			}

			appendMessage('assistant', response.data.response || '');
			updateTokenMeter();
			setStatus(abilitiesBridgeBubbleData.i18n.ready || 'Ready');
		}).fail(function() {
			handleError(abilitiesBridgeBubbleData.i18n.connectionError);
		}).always(function() {
			setLoading(false);
			state.currentRequest = null;
			$(selectors.input).trigger('focus');
		});
	}

	function handleNewConversation(skipConfirm) {
		if (!skipConfirm && state.currentConversationId && !window.confirm(abilitiesBridgeBubbleData.i18n.newConversation)) {
			return;
		}

		setConversationId(null);
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
			syncProviderFromConversation(response.data.conversation || {});
			updateTokenMeter();
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
		$(selectors.send)
			.prop('disabled', loading)
			.text(loading ? abilitiesBridgeBubbleData.i18n.sending : abilitiesBridgeBubbleData.i18n.send);
	}

	function handleProviderChange() {
		const provider = $(selectors.provider).val();
		if (!provider) {
			return;
		}

		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_set_provider',
				nonce: abilitiesBridgeBubbleData.nonce,
				provider: provider
			}
		}).done(function(response) {
			if (!response.success) {
				handleError(extractErrorMessage(response));
				return;
			}

			updateProviderOptions(response.data.provider);
			updateModelOptions(response.data.available_models || {}, response.data.model);
			setStatus(abilitiesBridgeBubbleData.i18n.providerChanged);
			handleNewConversation(true);
		}).fail(function() {
			handleError(abilitiesBridgeBubbleData.i18n.connectionError);
		});
	}

	function handleModelChange() {
		const model = $(selectors.model).val();
		if (!model) {
			return;
		}

		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_set_model',
				nonce: abilitiesBridgeBubbleData.nonce,
				model: model
			}
		}).done(function(response) {
			if (!response.success) {
				handleError(extractErrorMessage(response));
				return;
			}

			setStatus(abilitiesBridgeBubbleData.i18n.modelChanged);
			handleNewConversation(true);
		}).fail(function() {
			handleError(abilitiesBridgeBubbleData.i18n.connectionError);
		});
	}

	function loadProviderState() {
		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_provider',
				nonce: abilitiesBridgeBubbleData.nonce
			}
		}).done(function(response) {
			if (!response.success) {
				return;
			}

			updateProviderOptions(response.data.provider);
			updateModelOptions(response.data.available_models || {}, response.data.model);
			setStatus(`Using ${response.data.model_name}`);
		}).fail(function() {
			setStatus(abilitiesBridgeBubbleData.i18n.loadProviderFailed || 'Unable to load provider settings.');
		});
	}

	function syncProviderFromConversation(conversation) {
		if (!conversation || !conversation.provider) {
			return;
		}

		if ($(selectors.provider).val() === conversation.provider) {
			if (conversation.model) {
				$(selectors.model).val(conversation.model);
				setStatus(`Using ${$(selectors.model).find('option:selected').text() || conversation.model}`);
			}
			return;
		}

		$.ajax({
			url: abilitiesBridgeBubbleData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_set_provider',
				nonce: abilitiesBridgeBubbleData.nonce,
				provider: conversation.provider
			}
		}).done(function(response) {
			if (!response.success) {
				return;
			}

			updateProviderOptions(response.data.provider);
			updateModelOptions(response.data.available_models || {}, conversation.model || response.data.model);
			setStatus(`Using ${$(selectors.model).find('option:selected').text() || response.data.model_name}`);
		});
	}

	function updateProviderOptions(selectedProvider) {
		const providers = [
			{ value: 'anthropic', label: 'Anthropic' },
			{ value: 'openai', label: 'OpenAI' }
		];
		const $select = $(selectors.provider);
		$select.empty();

		providers.forEach(function(provider) {
			const selected = provider.value === selectedProvider ? ' selected' : '';
			$select.append(`<option value="${provider.value}"${selected}>${provider.label}</option>`);
		});
	}

	function updateModelOptions(models, selectedModel) {
		const $select = $(selectors.model);
		$select.empty();

		Object.keys(models).forEach(function(modelId) {
			const selected = modelId === selectedModel ? ' selected' : '';
			$select.append(`<option value="${escapeAttribute(modelId)}"${selected}>${escapeHtml(models[modelId])}</option>`);
		});
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

	function escapeAttribute(text) {
		return escapeHtml(text).replace(/`/g, '&#096;');
	}

	$(init);
})(jQuery);
