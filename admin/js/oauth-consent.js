/**
 * OAuth Consent Screen JavaScript
 *
 * @package Abilities_Bridge
 */

document.addEventListener('DOMContentLoaded', function() {
	'use strict';

	var form = document.querySelector('form');
	if (!form) {
		return;
	}

	var buttons = form.querySelectorAll('button[type="submit"]');
	var approvedField = document.getElementById('approved-field');

	if (!approvedField || !buttons.length) {
		return;
	}

	// Set the hidden field value when a button is clicked
	buttons.forEach(function(button) {
		button.addEventListener('click', function() {
			var approvedValue = this.getAttribute('data-approved');
			approvedField.value = approvedValue;
		});
	});

	// Disable buttons on submit to prevent double-submission
	form.addEventListener('submit', function() {
		buttons.forEach(function(button) {
			button.disabled = true;
			button.style.opacity = '0.6';
		});
	});
});
