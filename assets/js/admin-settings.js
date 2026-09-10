/**
 * Settings page: test connection and environment highlighting.
 */
( function ( $, window ) {
	'use strict';

	var cfg = window.paradox_cardpointe_admin || {};
	var i18n = cfg.i18n || {};
	var prefix = 'woocommerce_' + ( cfg.gatewayId || 'paradox_cardpointe' ) + '_';

	function field( name ) {
		return $( '#' + prefix + name );
	}

	function environment() {
		return field( 'sandbox_mode' ).is( ':checked' ) ? 'sandbox' : 'production';
	}

	function highlight() {
		var env = environment();
		$( '.paradox-cardpointe-env-production, .paradox-cardpointe-env-sandbox' ).each( function () {
			var $input = $( this );
			var active = $input.hasClass( 'paradox-cardpointe-env-' + env );
			$input.closest( 'tr' ).toggleClass( 'paradox-cardpointe-env-active', active ).toggleClass( 'paradox-cardpointe-env-inactive', ! active );
		} );
	}

	function renderResult( $result, data ) {
		var labels = i18n.labels || {};
		var $list = $( '<dl class="paradox-cardpointe-merchant-info"></dl>' );
		function yn( value ) {
			var v = String( value || '' ).toUpperCase();
			if ( v === 'Y' || v === 'TRUE' || v === '1' ) { return i18n.yes; }
			if ( v === 'N' || v === 'FALSE' || v === '0' ) { return i18n.no; }
			return value || '-';
		}
		var rows = [
			[ labels.host, data.host ],
			[ labels.site, data.site ],
			[ labels.enabled, yn( data.enabled ) ],
			[ labels.echeck, yn( data.echeck ) ],
			[ labels.cvv, yn( data.cvv ) ],
			[ labels.avs, yn( data.avs ) ],
			[ labels.acctupdater, yn( data.acctupdater ) ],
			[ labels.cardproc, data.cardproc || '-' ]
		];
		$.each( rows, function ( _, row ) {
			$list.append( $( '<dt></dt>' ).text( row[ 0 ] ) ).append( $( '<dd></dd>' ).text( row[ 1 ] ) );
		} );
		$result.empty().append( $( '<p class="paradox-cardpointe-ok"></p>' ).text( i18n.success ) ).append( $list );
		$.each( data.warnings || [], function ( _, warning ) {
			$result.append( $( '<p class="paradox-cardpointe-warn"></p>' ).text( warning ) );
		} );
	}

	$( function () {
		field( 'sandbox_mode' ).on( 'change', highlight );
		highlight();

		$( '#paradox-cardpointe-test-connection' ).on( 'click', function () {
			var $button = $( this );
			var $spinner = $button.next( '.spinner' );
			var $result = $( '#paradox-cardpointe-test-connection-result' );
			var env = environment();

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.empty().append( $( '<p></p>' ).text( i18n.testing ) );

			$.post( cfg.ajaxUrl, {
				action: 'paradox_cardpointe_test_connection',
				nonce: $button.data( 'nonce' ),
				environment: env,
				site: field( env + '_site' ).val(),
				merchant_id: field( env + '_merchant_id' ).val(),
				username: field( env + '_api_username' ).val(),
				password: field( env + '_api_password' ).val()
			} ).done( function ( response ) {
				if ( response && response.success ) {
					renderResult( $result, response.data || {} );
				} else {
					var message = response && response.data && response.data.message ? response.data.message : i18n.failure;
					$result.empty().append( $( '<p class="paradox-cardpointe-fail"></p>' ).text( i18n.failure + ' ' + message ) );
				}
			} ).fail( function () {
				$result.empty().append( $( '<p class="paradox-cardpointe-fail"></p>' ).text( i18n.failure ) );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	} );
} )( jQuery, window );
