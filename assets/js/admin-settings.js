/**
 * Settings page: CardPointe connection test, environment highlighting, and the
 * Apple Pay tab (certificate upload, setup test, test button).
 */
( function ( $, window ) {
	'use strict';

	var cfg = window.paradox_cardpointe_admin || {};
	var i18n = cfg.i18n || {};
	var ap = cfg.applePay || {};
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

	/* ---------------------------------------------------------------------
	 * Apple Pay
	 * ------------------------------------------------------------------ */

	/**
	 * Status lines in the same markup PHP renders: [ [ 'ok'|'fail'|'warn'|'info', text ], ... ].
	 */
	function reportList( lines ) {
		var $list = $( '<ul class="paradox-cardpointe-report"></ul>' );
		$.each( lines, function ( _, line ) {
			if ( line[ 1 ] ) {
				$list.append( $( '<li></li>' ).addClass( 'is-' + line[ 0 ] ).text( line[ 1 ] ) );
			}
		} );
		return $list;
	}

	/**
	 * "General | Apple Pay" sub-navigation.
	 *
	 * Both groups stay in the one WooCommerce form and are only shown or hidden, so
	 * saving from either tab posts every field and nothing is reset.
	 */
	function initTabs() {
		var $start = field( 'apple_pay_section' );
		if ( ! $start.length ) {
			return;
		}
		var $apple = $start.nextUntil( 'h3.wc-settings-sub-title:not([id*="apple_pay_"])' ).addBack();
		var $general = $start.parent().children( 'h3.wc-settings-sub-title, table.form-table, p' ).not( $apple ).not( '.submit' );
		var tabs = [
			{ id: 'general', label: ap.tabGeneral || 'General', $els: $general },
			{ id: 'apple-pay', label: ap.tabApplePay || 'Apple Pay', $els: $apple }
		];
		var $nav = $( '<ul class="subsubsub paradox-cardpointe-subnav"></ul>' );

		function show( id ) {
			$.each( tabs, function ( _, tab ) {
				var active = tab.id === id;
				tab.$els.toggle( active );
				tab.$link.toggleClass( 'current', active ).attr( 'aria-current', active ? 'page' : null );
			} );
			try {
				window.sessionStorage.setItem( 'paradoxCardpointeTab', id );
			} catch ( e ) {
				// Private browsing: the tab simply is not remembered.
			}
		}

		$.each( tabs, function ( index, tab ) {
			tab.$link = $( '<a></a>' ).attr( 'href', '#' + tab.id ).text( tab.label ).on( 'click', function ( event ) {
				event.preventDefault();
				show( tab.id );
			} );
			var $item = $( '<li></li>' ).append( tab.$link );
			if ( index < tabs.length - 1 ) {
				$item.append( ' | ' );
			}
			$nav.append( $item );
		} );

		var $header = $( '.paradox-cardpointe-settings-header' ).first();
		if ( $header.length ) {
			$header.after( $( '<br class="clear" />' ) ).after( $nav );
		} else {
			$start.parent().prepend( $( '<br class="clear" />' ) ).prepend( $nav );
		}

		var initial = 'general';
		if ( window.location.hash === '#apple-pay' ) {
			initial = 'apple-pay';
		} else {
			try {
				initial = window.sessionStorage.getItem( 'paradoxCardpointeTab' ) || 'general';
			} catch ( e ) {
				initial = 'general';
			}
		}
		show( initial === 'apple-pay' ? 'apple-pay' : 'general' );
	}

	/**
	 * Keeps the test button and preview in step with the style and label selects.
	 */
	var core = window.ParadoxCardPointeApplePay;

	function canUseApplePay() {
		return !! ( core && core.supported() );
	}

	/**
	 * (Re)draws the test button in the currently selected style and label, through the
	 * same code the storefront uses: Apple's own element when the SDK is loaded (any
	 * browser), the CSS button in Safari without it.
	 */
	function syncButtonLook() {
		var style = field( 'apple_pay_button_style' ).val() || 'black';
		var type = field( 'apple_pay_button_type' ).val() || 'plain';
		var $preview = $( '#paradox-cardpointe-apple-pay-preview' );
		var slot = $preview.find( '.paradox-cardpointe-apple-pay-slot' ).get( 0 );
		$preview.find( '.paradox-cardpointe-apple-pay-mock' ).attr( 'class', 'paradox-cardpointe-apple-pay-mock is-style-' + style );
		if ( slot && canUseApplePay() ) {
			slot.removeAttribute( 'data-mounted' );
			core.mountButton( slot, { style: style, type: type, onClick: openTestSheet } );
		}
	}

	/**
	 * Reveals the test button: the real one where Apple Pay can run, a labelled preview elsewhere.
	 */
	function showPreview() {
		var $preview = $( '#paradox-cardpointe-apple-pay-preview' );
		var live = canUseApplePay();
		$preview.prop( 'hidden', false );
		$preview.find( '.paradox-cardpointe-apple-pay-slot, .paradox-cardpointe-apple-pay-sheet-hint' ).prop( 'hidden', ! live );
		$preview.find( '.paradox-cardpointe-apple-pay-mock, .paradox-cardpointe-apple-pay-nosafari' ).prop( 'hidden', live );
		syncButtonLook();
	}

	function hidePreview() {
		$( '#paradox-cardpointe-apple-pay-preview' ).prop( 'hidden', true );
		$( '#paradox-cardpointe-apple-pay-sheet-result' ).empty();
	}

	function testRequest( extra ) {
		return $.post( cfg.ajaxUrl, $.extend( {
			action: 'paradox_cardpointe_apple_pay_test',
			nonce: $( '#paradox-cardpointe-apple-pay-test' ).data( 'nonce' ),
			merchant_id: field( 'apple_pay_merchant_id' ).val(),
			cert_path: field( 'apple_pay_cert_path' ).val()
		}, extra || {} ) );
	}

	function failureLines( response, heading ) {
		var data = ( response && response.data ) || {};
		var lines = [ [ 'fail', heading ], [ 'fail', data.message || ap.requestError ] ];
		$.each( data.hints || data.errors || [], function ( _, hint ) {
			lines.push( [ 'warn', hint ] );
		} );
		return lines;
	}

	function initUpload() {
		var $button = $( '#paradox-cardpointe-apple-pay-upload' );
		var $file = $( '#paradox-cardpointe-apple-pay-file' );
		var $result = $( '#paradox-cardpointe-apple-pay-cert-result' );
		if ( ! $button.length || ! window.FormData ) {
			return;
		}

		$button.on( 'click', function () {
			$file.val( '' ).trigger( 'click' );
		} );

		$file.on( 'change', function () {
			var file = this.files && this.files[ 0 ];
			if ( ! file ) {
				return;
			}
			var $spinner = $button.next( '.spinner' );
			var body = new window.FormData();
			body.append( 'action', 'paradox_cardpointe_apple_pay_upload' );
			body.append( 'nonce', $button.data( 'nonce' ) );
			body.append( 'certificate', file );

			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.empty().append( reportList( [ [ 'info', ap.uploading ] ] ) );

			$.ajax( { url: cfg.ajaxUrl, method: 'POST', data: body, processData: false, contentType: false, dataType: 'json' } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					var data = ( response && response.data ) || {};
					var failed = [ [ 'fail', data.message || ap.uploadFailed ] ];
					$.each( data.errors || [], function ( _, error ) {
						failed.push( [ 'fail', error ] );
					} );
					$result.empty().append( reportList( failed ) );
					return;
				}
				var saved = response.data || {};
				var lines = [ [ 'ok', saved.message ], [ 'ok', ap.pairOk ], [ 'ok', saved.common_name ] ];
				if ( saved.expires ) {
					lines.push( [ 'ok', String( ap.validUntil || '%s' ).replace( '%s', saved.expires ) ] );
				}
				field( 'apple_pay_cert_path' ).val( saved.path );
				if ( saved.merchant_id && ! $.trim( field( 'apple_pay_merchant_id' ).val() ) ) {
					field( 'apple_pay_merchant_id' ).val( saved.merchant_id );
					lines.push( [ 'ok', ap.merchantSet ] );
				}
				$.each( saved.warnings || [], function ( _, warning ) {
					lines.push( [ 'warn', warning ] );
				} );
				$result.empty().append( reportList( lines ) );

				// A new certificate has not been tested with Apple yet.
				hidePreview();
				$( '#paradox-cardpointe-apple-pay-status, #paradox-cardpointe-apple-pay-test-result' ).empty();
			} ).fail( function () {
				$result.empty().append( reportList( [ [ 'fail', ap.uploadFailed ] ] ) );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	}

	function initTest() {
		var $button = $( '#paradox-cardpointe-apple-pay-test' );
		var $result = $( '#paradox-cardpointe-apple-pay-test-result' );
		if ( ! $button.length ) {
			return;
		}

		$button.on( 'click', function () {
			var $spinner = $button.next( '.spinner' );
			$button.prop( 'disabled', true );
			$spinner.addClass( 'is-active' );
			$result.empty().append( reportList( [ [ 'info', ap.testing ] ] ) );

			testRequest().done( function ( response ) {
				if ( response && response.success ) {
					var lines = [ [ 'ok', response.data.message ] ];
					$.each( response.data.notes || [], function ( _, note ) {
						lines.push( [ 'warn', note ] );
					} );
					$result.empty().append( reportList( lines ) );
					$( '#paradox-cardpointe-apple-pay-status' ).empty();
					showPreview();
					return;
				}
				$result.empty().append( reportList( failureLines( response, ap.testFailed ) ) );
				hidePreview();
			} ).fail( function () {
				$result.empty().append( reportList( [ [ 'fail', ap.requestError ] ] ) );
			} ).always( function () {
				$button.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
		} );
	}

	/**
	 * The test button opens a real Apple Pay sheet in Safari. Merchant validation goes
	 * through the same server code as checkout; an authorized test is acknowledged to
	 * the sheet and then dropped, so no token ever reaches CardPointe.
	 */
	function openTestSheet() {
		var $preview = $( '#paradox-cardpointe-apple-pay-preview' );
		var $result = $( '#paradox-cardpointe-apple-pay-sheet-result' );
		( function () {
			var Session = window.ApplePaySession;
			if ( ! canUseApplePay() ) {
				return;
			}
			var version = 1;
			for ( var v = 6; v >= 3; v-- ) {
				if ( Session.supportsVersion( v ) ) {
					version = v;
					break;
				}
			}
			var session;
			try {
				session = new Session( version, {
					countryCode: $preview.data( 'country' ),
					currencyCode: $preview.data( 'currency' ),
					supportedNetworks: String( $preview.data( 'networks' ) || '' ).split( ',' ).filter( Boolean ),
					merchantCapabilities: [ 'supports3DS' ],
					total: { label: ap.sheetLabel || 'Test', amount: '1.00', type: 'final' }
				} );
			} catch ( e ) {
				$result.empty().append( reportList( [ [ 'fail', ap.sheetFailed ], [ 'fail', String( e && e.message ? e.message : e ) ] ] ) );
				return;
			}
			$result.empty();

			session.onvalidatemerchant = function ( validation ) {
				testRequest( { validation_url: validation.validationURL } ).done( function ( response ) {
					if ( response && response.success && response.data && response.data.session ) {
						session.completeMerchantValidation( response.data.session );
						$result.empty().append( reportList( [ [ 'ok', ap.sheetOpened ] ] ) );
						return;
					}
					try {
						session.abort();
					} catch ( e ) {
						// Already closed.
					}
					$result.empty().append( reportList( failureLines( response, ap.sheetFailed ) ) );
				} ).fail( function () {
					try {
						session.abort();
					} catch ( e ) {
						// Already closed.
					}
					$result.empty().append( reportList( [ [ 'fail', ap.requestError ] ] ) );
				} );
			};

			session.onpaymentauthorized = function () {
				var status = Session.STATUS_SUCCESS;
				session.completePayment( version >= 3 ? { status: status } : status );
				$result.empty().append( reportList( [ [ 'ok', ap.sheetOk ] ] ) );
			};

			session.begin();
		} )();
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

		initTabs();
		initUpload();
		initTest();

		field( 'apple_pay_button_style' ).add( field( 'apple_pay_button_type' ) ).on( 'change', syncButtonLook );
		if ( $( '#paradox-cardpointe-apple-pay-preview' ).data( 'verified' ) ) {
			showPreview();
		}
	} );
} )( jQuery, window );
