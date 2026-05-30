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

	var i18n = window.abIntegrations && window.abIntegrations.i18n ? window.abIntegrations.i18n : {};

	/**
	 * Translate with a fallback.
	 *
	 * @param {string} key      Translation key.
	 * @param {string} fallback Fallback text.
	 * @return {string}
	 */
	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

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
	}

	/**
	 * Restore a button after a failed request.
	 *
	 * @param {jQuery} $btn Button.
	 */
	function restoreButton( $btn ) {
		$btn.prop( 'disabled', false ).text( $btn.data( 'original-text' ) );
	}

	/**
	 * Send integration action.
	 *
	 * @param {jQuery} $btn Button.
	 * @param {string} action Action name.
	 * @param {string} busyText Busy button text.
	 * @param {string} fallbackMessage Failure fallback.
	 */
	function sendIntegrationAction( $btn, action, busyText, fallbackMessage ) {
		var slug    = $btn.data( 'slug' );
		var profile = $btn.data( 'profile' ) || 'all';

		$btn.data( 'original-text', $btn.text() );
		$btn.prop( 'disabled', true ).text( busyText );

		$.post( abIntegrations.ajaxUrl, {
			action:      action,
			nonce:       abIntegrations.nonce,
			plugin_slug: slug,
			profile:     profile
		} )
		.done( function( response ) {
			if ( response.success ) {
				showNotification( response.data.message, 'success' );
				window.setTimeout( function() {
					window.location.reload();
				}, 900 );
			} else {
				showNotification( ( response.data && response.data.message ) || fallbackMessage, 'error' );
				restoreButton( $btn );
			}
		} )
		.fail( function( xhr ) {
			var message = t( 'requestFailed', 'Request failed. Please try again.' );

			if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
				message = xhr.responseJSON.data.message;
			}

			showNotification( message, 'error' );
			restoreButton( $btn );
		} );
	}

	$( document ).ready( function() {

		$( document ).on( 'click', '.ab-toggle-abilities', function() {
			var $btn       = $( this );
			var $body      = $btn.siblings( '.ab-abilities-body' );
			var isExpanded = $btn.attr( 'aria-expanded' ) === 'true';

			if ( isExpanded ) {
				$body.slideUp( 200 );
				$btn.attr( 'aria-expanded', 'false' );
			} else {
				$body.slideDown( 200 );
				$btn.attr( 'aria-expanded', 'true' );
			}
		} );

		$( document ).on( 'click', '.ab-btn-approve', function() {
			sendIntegrationAction(
				$( this ),
				'abilities_bridge_approve_integration',
				t( 'approving', 'Approving...' ),
				t( 'approvalFailed', 'Approval failed.' )
			);
		} );

		$( document ).on( 'click', '.ab-btn-revoke', function() {
			sendIntegrationAction(
				$( this ),
				'abilities_bridge_revoke_integration',
				t( 'revoking', 'Revoking...' ),
				t( 'revocationFailed', 'Revocation failed.' )
			);
		} );

	} );

} )( jQuery );
