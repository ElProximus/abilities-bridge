(function () {
	'use strict';

	var runButton = document.getElementById('abilities-bridge-chatgpt-mcp-run-tests');
	var statusEl = document.getElementById('abilities-bridge-chatgpt-mcp-test-status');
	var resultsEl = document.getElementById('abilities-bridge-chatgpt-mcp-test-results');

	if (!runButton || !statusEl || !resultsEl || !window.abilitiesBridgeChatGPTMcpTest) {
		return;
	}

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function renderStatus(data) {
		var color = '#2271b1';
		var label = 'Running';
		if (data.status === 'success') {
			color = '#46b450';
			label = 'Ready';
		} else if (data.status === 'warning') {
			color = '#dba617';
			label = 'Warnings';
		} else if (data.status === 'error') {
			color = '#d63638';
			label = 'Needs Attention';
		}

		statusEl.innerHTML = '<div class="notice inline" style="border-left:4px solid ' + color + '; padding:12px 16px; background:#fff;">'
			+ '<p><strong>' + label + '</strong> '
			+ '(Passed: ' + data.passed + ', Warnings: ' + data.warned + ', Failed: ' + data.failed + ')</p></div>';
	}

	function renderResults(data) {
		var checksHtml = data.checks.map(function (check) {
			var color = check.status === 'pass' ? '#46b450' : (check.status === 'warn' ? '#dba617' : '#d63638');
			return '<li style="margin:0 0 12px; padding:12px; background:#fff; border-left:4px solid ' + color + ';">'
				+ '<strong>' + escapeHtml(check.label) + '</strong><br>'
				+ '<span>' + escapeHtml(check.message) + '</span>'
				+ '</li>';
		}).join('');

		resultsEl.innerHTML = ''
			+ '<div class="card" style="max-width:1100px; padding:20px;">'
			+ '<h2 style="margin-top:0;">Check Results</h2>'
			+ '<ul style="list-style:none; margin:0; padding:0;">' + checksHtml + '</ul>'
			+ '</div>'
			+ '<div class="card" style="max-width:1100px; padding:20px; margin-top:20px;">'
			+ '<h2 style="margin-top:0;">Debug Output</h2>'
			+ '<pre style="white-space:pre-wrap; overflow:auto; background:#0f172a; color:#e2e8f0; padding:16px; border-radius:6px;">'
			+ escapeHtml(JSON.stringify(data.debug, null, 2))
			+ '</pre></div>';
	}

	runButton.addEventListener('click', function () {
		runButton.disabled = true;
		statusEl.innerHTML = '<div class="notice notice-info inline"><p>Running ChatGPT MCP diagnostics...</p></div>';
		resultsEl.innerHTML = '';

		var body = new URLSearchParams();
		body.set('action', 'abilities_bridge_run_chatgpt_mcp_tests');
		body.set('nonce', window.abilitiesBridgeChatGPTMcpTest.nonce);

		fetch(window.abilitiesBridgeChatGPTMcpTest.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString(),
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload.success) {
				throw new Error(payload.data && payload.data.message ? payload.data.message : 'Unknown error');
			}
			renderStatus(payload.data);
			renderResults(payload.data);
		}).catch(function (error) {
			statusEl.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(error.message) + '</p></div>';
		}).finally(function () {
			runButton.disabled = false;
		});
	});
})();
