/**
 * Order screen: capture, void and refresh actions in the CardPointe meta box.
 */
( function ( $, window ) {
	'use strict';

	var cfg = window.paradox_cardpointe_order || {};
	var i18n = cfg.i18n || {};

	$( function () {
		$( document ).on( 'click', '.paradox-cardpointe-action', function ( e ) {
			e.preventDefault();
			var $button = $( this );
			var $box = $button.closest( '.paradox-cardpointe-meta-box' );
			var $result = $box.find( '.paradox-cardpointe-action-result' );
			var action = $button.data( 'action' );
			var amount = $box.find( '#paradox-cardpointe-capture-amount' ).val();

			if ( action === 'void' && ! window.confirm( i18n.confirmVoid ) ) {
				return;
			}
			if ( action === 'capture' && ! window.confirm( String( i18n.confirmCapture || '%s' ).replace( '%s', amount ) ) ) {
				return;
			}

			$box.find( 'button' ).prop( 'disabled', true );
			$result.removeClass( 'paradox-cardpointe-ok paradox-cardpointe-fail' ).text( i18n.working );

			$.post( cfg.ajaxUrl, {
				action: 'paradox_cardpointe_order_' + action,
				nonce: cfg.nonce,
				order_id: $box.data( 'order-id' ),
				amount: amount
			} ).done( function ( response ) {
				var message = response && response.data && response.data.message ? response.data.message : '';
				if ( response && response.success ) {
					$result.addClass( 'paradox-cardpointe-ok' ).text( message );
					window.setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					$result.addClass( 'paradox-cardpointe-fail' ).text( message || i18n.error );
					$box.find( 'button' ).prop( 'disabled', false );
				}
			} ).fail( function () {
				$result.addClass( 'paradox-cardpointe-fail' ).text( i18n.error );
				$box.find( 'button' ).prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery, window );
