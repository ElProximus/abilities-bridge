/**
 * Integrations page JavaScript.
 *
 * Handles approve/revoke AJAX calls and abilities list toggling.
 *
 * @package Abilities_Bridge
 * @since   1.2.0
 */

( function( $ ) {
	'use strict';

	/**
	 * Show an inline notification bar.
	 *
	 * @param {string} message The message text.
	 * @param {string} type    Either 'success' or 'error'.
	 */
	function showNotification( message, type ) {
		var $notification = $( '#ab-notification' );

		$notification
			.removeClass( 'ab-notification-success ab-notification-error' )
			.addClass( 'ab-notification-' + type )
			.find( '.ab-notification-message' )
			.text( message );

		$notification.stop( true, true ).fadeIn( 200 );

		// Auto-hide after 5 seconds.
		setTimeout( function() {
			$notification.fadeOut( 400 );
		}, 5000 );
	}

	/**
	 * Update card UI after approval state changes.
	 *
	 * @param {jQuery}  $card  The plugin card element.
	 * @param {string}  status New status: 'approved' or 'available'.
	 */
	function updateCardStatus( $card, status ) {
		var $badge     = $card.find( '.ab-status-badge' );
		var $checks    = $card.find( '.ab-ability-row' );
		var totalCount = $checks.length;
		var $countSpan = $card.find( '.ab-ability-count' );
		var $actions   = $card.find( '.ab-actions' );
		var slug       = $card.data( 'plugin-slug' );

		if ( 'approved' === status ) {
			// Update badge.
			$badge
				.removeClass( 'ab-status-available ab-status-partial' )
				.addClass( 'ab-status-approved' )
				.text( 'Approved' );

			// Show checkmarks on all rows.
			$checks.each( function() {
				var $td = $( this ).find( '.ab-col-status' );
				$td.html( '<span class="ab-approved-check" title="Approved">&#10003;</span>' );
			} );

			// Update count.
			$countSpan.text( totalCount + ' / ' + totalCount + ' abilities' );

			// Swap buttons: remove approve, show revoke.
			$actions.html(
				'<button type="button" class="button ab-btn-revoke" data-slug="' + slug + '">Revoke All</button>'
			);
		} else {
			// Update badge.
			$badge
				.removeClass( 'ab-status-approved ab-status-partial' )
				.addClass( 'ab-status-available' )
				.text( 'Available' );

			// Remove checkmarks.
			$checks.each( function() {
				var $td = $( this ).find( '.ab-col-status' );
				$td.html( '<span class="ab-pending-dash" title="Not approved">&mdash;</span>' );
			} );

			// Update count.
			$countSpan.text( '0 / ' + totalCount + ' abilities' );

			// Swap buttons: remove revoke, show approve.
			$actions.html(
				'<button type="button" class="button ab-btn-approve" data-slug="' + slug + '">Approve All Abilities</button>'
			);
		}
	}

	$( document ).ready( function() {

		// Toggle abilities list.
		$( document ).on( 'click', '.ab-toggle-abilities', function() {
			var $btn  = $( this );
			var $body = $btn.siblings( '.ab-abilities-body' );
			var isExpanded = $btn.attr( 'aria-expanded' ) === 'true';

			if ( isExpanded ) {
				$body.slideUp( 200 );
				$btn.attr( 'aria-expanded', 'false' );
				$btn.find( '.dashicons' ).removeClass( 'dashicons-arrow-up-alt2' ).addClass( 'dashicons-arrow-down-alt2' );
			} else {
				$body.slideDown( 200 );
				$btn.attr( 'aria-expanded', 'true' );
				$btn.find( '.dashicons' ).removeClass( 'dashicons-arrow-down-alt2' ).addClass( 'dashicons-arrow-up-alt2' );
			}
		} );

		// Approve All button.
		$( document ).on( 'click', '.ab-btn-approve', function() {
			var $btn  = $( this );
			var slug  = $btn.data( 'slug' );
			var $card = $btn.closest( '.ab-plugin-card' );

			$btn.prop( 'disabled', true ).text( 'Approving...' );

			$.post( abIntegrations.ajaxUrl, {
				action:      'abilities_bridge_approve_integration',
				nonce:       abIntegrations.nonce,
				plugin_slug: slug
			} )
			.done( function( response ) {
				if ( response.success ) {
					updateCardStatus( $card, 'approved' );
					showNotification( response.data.message, 'success' );
				} else {
					showNotification( response.data.message || 'Approval failed.', 'error' );
					$btn.prop( 'disabled', false ).text( 'Approve All Abilities' );
				}
			} )
			.fail( function() {
				showNotification( 'Request failed. Please try again.', 'error' );
				$btn.prop( 'disabled', false ).text( 'Approve All Abilities' );
			} );
		} );

		// Revoke All button.
		$( document ).on( 'click', '.ab-btn-revoke', function() {
			var $btn  = $( this );
			var slug  = $btn.data( 'slug' );
			var $card = $btn.closest( '.ab-plugin-card' );

			$btn.prop( 'disabled', true ).text( 'Revoking...' );

			$.post( abIntegrations.ajaxUrl, {
				action:      'abilities_bridge_revoke_integration',
				nonce:       abIntegrations.nonce,
				plugin_slug: slug
			} )
			.done( function( response ) {
				if ( response.success ) {
					updateCardStatus( $card, 'available' );
					showNotification( response.data.message, 'success' );
				} else {
					showNotification( response.data.message || 'Revocation failed.', 'error' );
					$btn.prop( 'disabled', false ).text( 'Revoke All' );
				}
			} )
			.fail( function() {
				showNotification( 'Request failed. Please try again.', 'error' );
				$btn.prop( 'disabled', false ).text( 'Revoke All' );
			} );
		} );

	} );

} )( jQuery );
