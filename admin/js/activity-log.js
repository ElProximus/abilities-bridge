/**
 * Activity Log Page JavaScript
 * Handles AJAX interactions for the Activity Log admin page
 *
 * @package Abilities_Bridge
 */

(function($) {
	'use strict';

	// Wait for DOM ready
	$(document).ready(function() {

		// Initialize on Activity Log tab
		if ($('#activity-log-table').length) {
			initActivityLogTab();
		}

		// Initialize on Deleted Conversations tab
		if ($('#deleted-conversations-table').length) {
			initDeletedTab();
		}
	});

	/**
	 * Initialize Activity Log tab functionality
	 */
	function initActivityLogTab() {
		// Filter form submission
		$('#activity-log-filters').on('submit', function(e) {
			e.preventDefault();
			refreshActivityLog(1);
		});

		// Pagination clicks
		$(document).on('click', '.activity-log-pagination a', function(e) {
			e.preventDefault();
			var page = $(this).data('page');
			if (page) {
				refreshActivityLog(page);
			}
		});

		// Row detail toggle
		$(document).on('click', '.toggle-details', function(e) {
			e.preventDefault();
			var $button = $(this);
			var logId = $button.data('log-id');
			var $details = $('#details-' + logId);

			if ($details.is(':visible')) {
				$details.slideUp();
				$button.text('Details');
			} else {
				$details.slideDown();
				$button.text('Hide Details');
			}
		});

		// Export CSV button
		$('#export-csv-btn').on('click', function(e) {
			e.preventDefault();
			exportLogsToCSV();
		});
	}

	/**
	 * Initialize Deleted Conversations tab functionality
	 */
	function initDeletedTab() {
		// Restore conversation
		$(document).on('click', '.restore-conversation', function(e) {
			e.preventDefault();
			var $button = $(this);
			var conversationId = $button.data('conversation-id');
			var title = $button.data('title');

			if (!confirm('Restore conversation "' + title + '"?')) {
				return;
			}

			restoreConversation(conversationId, $button);
		});

		// Permanently delete conversation
		$(document).on('click', '.permanently-delete', function(e) {
			e.preventDefault();
			var $button = $(this);
			var conversationId = $button.data('conversation-id');
			var title = $button.data('title');

			if (!confirm('PERMANENTLY DELETE conversation "' + title + '"?\n\nThis action cannot be undone!')) {
				return;
			}

			permanentlyDeleteConversation(conversationId, $button);
		});
	}

	/**
	 * Refresh activity log table via AJAX
	 */
	function refreshActivityLog(page) {
		var $table = $('#activity-log-table');
		var $tbody = $table.find('tbody');
		var $pagination = $('.activity-log-pagination');

		// Get filter values
		var filters = {
			user_id: $('#filter-user').val(),
			status: $('#filter-status').val(),
			search: $('#filter-search').val(),
			page: page || 1
		};

		// Show loading state
		$tbody.html('<tr><td colspan="6" style="text-align:center;">Loading...</td></tr>');
		$pagination.hide();

		// AJAX request
		$.ajax({
			url: abilitiesBridgeActivityLog.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_get_logs',
				nonce: abilitiesBridgeActivityLog.nonce,
				filters: filters
			},
			success: function(response) {
				if (response.success) {
					// Update table
					$tbody.html(response.data.html);

					// Update pagination
					if (response.data.pagination) {
						$pagination.html(response.data.pagination).show();
					} else {
						$pagination.hide();
					}
				} else {
					showError($tbody, response.data.message || 'Failed to load logs');
				}
			},
			error: function(xhr, status, error) {
				showError($tbody, 'AJAX error: ' + error);
			}
		});
	}

	/**
	 * Export logs to CSV
	 */
	function exportLogsToCSV() {
		var $button = $('#export-csv-btn');

		// Get filter values
		var filters = {
			user_id: $('#filter-user').val(),
			status: $('#filter-status').val(),
			search: $('#filter-search').val()
		};

		// Show loading state
		$button.prop('disabled', true).text('Exporting...');

		// Create a hidden form and submit it (POST with native download)
		var $form = $('<form>', {
			'method': 'POST',
			'action': abilitiesBridgeActivityLog.ajaxUrl
		});

		// Add action parameter
		$form.append($('<input>', {
			'type': 'hidden',
			'name': 'action',
			'value': 'abilities_bridge_export_logs'
		}));

		// Add nonce
		$form.append($('<input>', {
			'type': 'hidden',
			'name': 'nonce',
			'value': abilitiesBridgeActivityLog.nonce
		}));

		// Add filter parameters (only if they have values)
		if (filters.user_id) {
			$form.append($('<input>', {
				'type': 'hidden',
				'name': 'user_id',
				'value': filters.user_id
			}));
		}

		if (filters.status) {
			$form.append($('<input>', {
				'type': 'hidden',
				'name': 'status',
				'value': filters.status
			}));
		}

		if (filters.search) {
			$form.append($('<input>', {
				'type': 'hidden',
				'name': 'search',
				'value': filters.search
			}));
		}

		// Append form to body, submit, and remove
		$('body').append($form);
		$form.submit();
		$form.remove();

		// Reset button after short delay
		setTimeout(function() {
			$button.prop('disabled', false).text('Export CSV');
		}, 1000);
	}

	/**
	 * Restore a soft-deleted conversation
	 */
	function restoreConversation(conversationId, $button) {
		var $row = $button.closest('tr');

		// Show loading state
		$button.prop('disabled', true).text('Restoring...');

		// AJAX request
		$.ajax({
			url: abilitiesBridgeActivityLog.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_restore_conversation',
				nonce: abilitiesBridgeActivityLog.nonce,
				conversation_id: conversationId
			},
			success: function(response) {
				if (response.success) {
					// Remove row with fade effect
					$row.fadeOut(400, function() {
						$(this).remove();

						// Check if table is now empty
						if ($('#deleted-conversations-table tbody tr').length === 0) {
							$('#deleted-conversations-table tbody').html(
								'<tr><td colspan="5" style="text-align:center;">No deleted conversations found.</td></tr>'
							);
						}
					});

					showNotice('Conversation restored successfully', 'success');
				} else {
					showNotice(response.data.message || 'Failed to restore conversation', 'error');
					$button.prop('disabled', false).text('Restore');
				}
			},
			error: function(xhr, status, error) {
				showNotice('AJAX error: ' + error, 'error');
				$button.prop('disabled', false).text('Restore');
			}
		});
	}

	/**
	 * Permanently delete a conversation
	 */
	function permanentlyDeleteConversation(conversationId, $button) {
		var $row = $button.closest('tr');

		// Show loading state
		$button.prop('disabled', true).text('Deleting...');

		// AJAX request
		$.ajax({
			url: abilitiesBridgeActivityLog.ajaxUrl,
			type: 'POST',
			data: {
				action: 'abilities_bridge_permanently_delete',
				nonce: abilitiesBridgeActivityLog.nonce,
				conversation_id: conversationId
			},
			success: function(response) {
				if (response.success) {
					// Remove row with fade effect
					$row.fadeOut(400, function() {
						$(this).remove();

						// Check if table is now empty
						if ($('#deleted-conversations-table tbody tr').length === 0) {
							$('#deleted-conversations-table tbody').html(
								'<tr><td colspan="5" style="text-align:center;">No deleted conversations found.</td></tr>'
							);
						}
					});

					showNotice('Conversation permanently deleted', 'success');
				} else {
					showNotice(response.data.message || 'Failed to delete conversation', 'error');
					$button.prop('disabled', false).text('Delete Permanently');
				}
			},
			error: function(xhr, status, error) {
				showNotice('AJAX error: ' + error, 'error');
				$button.prop('disabled', false).text('Delete Permanently');
			}
		});
	}

	/**
	 * Show error message in table
	 */
	function showError($tbody, message) {
		$tbody.html(
			'<tr><td colspan="6" style="text-align:center;color:#d63638;">' +
			'<strong>Error:</strong> ' + escapeHtml(message) +
			'</td></tr>'
		);
	}

	/**
	 * Show admin notice
	 */
	function showNotice(message, type) {
		type = type || 'info';
		var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');

		// Insert after h1
		$('h1').first().after($notice);

		// Auto-dismiss after 5 seconds
		setTimeout(function() {
			$notice.fadeOut(400, function() {
				$(this).remove();
			});
		}, 5000);
	}

	/**
	 * Escape HTML to prevent XSS
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

})(jQuery);
