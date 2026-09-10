/**
 * Checkout block integration: eCheck (ACH).
 *
 * Written without JSX so no build step is needed.
 */
( function ( wp, wc, window ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function ( s ) { return s; };
	var settings = wc.wcSettings.getSetting( 'paradox_cardpointe_echeck_data', {} );
	var i18n = settings.i18n || {};
	var NAME = settings.name || 'paradox_cardpointe_echeck';
	var FIELD = NAME;

	function formatMoney( billing ) {
		try {
			var total = billing && billing.cartTotal ? billing.cartTotal.value : 0;
			var currency = billing && billing.currency ? billing.currency : {};
			var minor = typeof currency.minorUnit === 'number' ? currency.minorUnit : 2;
			var amount = ( total / Math.pow( 10, minor ) ).toFixed( minor );
			var parts = amount.split( '.' );
			var sep = currency.thousandSeparator || ',';
			var dec = currency.decimalSeparator || '.';
			parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, sep );
			var text = parts.length > 1 ? parts[ 0 ] + dec + parts[ 1 ] : parts[ 0 ];
			return ( currency.prefix || '' ) + text + ( currency.suffix || '' );
		} catch ( e ) {
			return '';
		}
	}

	function consentText( billing ) {
		var template = settings.consentTemplate || '';
		return template
			.replace( '{amount}', formatMoney( billing ) )
			.replace( '{company}', settings.consentCompany || '' )
			.replace( '{site}', settings.consentCompany || '' );
	}

	function Label() {
		return el( 'span', { className: 'wc-block-components-payment-method-label' }, decode( settings.title || '' ) );
	}

	function Content( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var billing = props.billing;
		var containerRef = useRef( null );
		var instanceRef = useRef( null );
		var tokenRef = useRef( '' );
		var types = settings.accountTypes || [];
		var acctState = useState( types.length ? types[ 0 ].value : 'ECHK' );
		var accttype = acctState[ 0 ];
		var setAccttype = acctState[ 1 ];
		var consentState = useState( false );
		var consent = consentState[ 0 ];
		var setConsent = consentState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var readyState = useState( false );
		var ready = readyState[ 0 ];
		var setReady = readyState[ 1 ];
		var acctRef = useRef( accttype );
		var consentRef = useRef( consent );
		acctRef.current = accttype;
		consentRef.current = consent;

		useEffect( function () {
			if ( ! containerRef.current || ! window.ParadoxCardPointeTokenizer ) {
				return undefined;
			}
			instanceRef.current = window.ParadoxCardPointeTokenizer.mount( containerRef.current, {
				src: settings.tokenizerUrl,
				origin: settings.tokenizerOrigin,
				height: settings.iframeHeight,
				title: i18n.loading,
				onReady: function () { setReady( true ); },
				onToken: function ( token ) {
					tokenRef.current = token;
					setError( '' );
				},
				onCleared: function () { tokenRef.current = ''; },
				onError: function ( code, message, isValidation ) {
					tokenRef.current = '';
					if ( isValidation && ( code === '1004' || code === '1005' || code === '1006' ) ) {
						return;
					}
					setError( ( i18n.errorCodes && i18n.errorCodes[ code ] ) ? i18n.errorCodes[ code ] : ( message || i18n.invalidBank ) );
				}
			} );
			return function () {
				if ( instanceRef.current ) {
					instanceRef.current.destroy();
					instanceRef.current = null;
				}
			};
		}, [] );

		useEffect( function () {
			if ( ! eventRegistration || ! eventRegistration.onPaymentSetup ) {
				return undefined;
			}
			return eventRegistration.onPaymentSetup( function () {
				function failure( message ) {
					return { type: emitResponse.responseTypes.ERROR, message: message, messageContext: emitResponse.noticeContexts.PAYMENTS };
				}
				function success( token ) {
					var data = {};
					data[ FIELD + '_token' ] = token;
					data[ FIELD + '_accttype' ] = acctRef.current;
					data[ FIELD + '_consent' ] = consentRef.current ? '1' : '';
					return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: data } };
				}
				if ( ! acctRef.current ) {
					return failure( i18n.chooseAccttype );
				}
				if ( ! consentRef.current ) {
					return failure( i18n.consentRequired );
				}
				if ( tokenRef.current ) {
					return success( tokenRef.current );
				}
				if ( ! instanceRef.current ) {
					return failure( i18n.enterBank );
				}
				return instanceRef.current.waitForToken( 3000 ).then( function ( token ) {
					return success( token );
				}, function () {
					return failure( i18n.enterBank );
				} );
			} );
		}, [ eventRegistration, emitResponse ] );

		useEffect( function () {
			if ( ! eventRegistration || ! eventRegistration.onCheckoutFail ) {
				return undefined;
			}
			return eventRegistration.onCheckoutFail( function () {
				tokenRef.current = '';
				if ( instanceRef.current ) {
					instanceRef.current.reload();
				}
				return true;
			} );
		}, [ eventRegistration ] );

		var children = [];
		if ( settings.description ) {
			children.push( el( 'p', { key: 'desc', className: 'paradox-cardpointe-description' }, decode( settings.description ) ) );
		}
		if ( settings.isSandbox ) {
			children.push( el( 'p', { key: 'sandbox', className: 'paradox-cardpointe-sandbox-notice' }, settings.sandboxNotice ) );
		}
		if ( error ) {
			children.push( el( 'div', { key: 'error', className: 'paradox-cardpointe-errors wc-block-components-notice-banner is-error', role: 'alert' }, error ) );
		}
		children.push(
			el(
				'p',
				{ key: 'accttype', className: 'paradox-cardpointe-accttype' },
				el( 'span', { className: 'paradox-cardpointe-label' }, settings.accountTypeLabel ),
				types.map( function ( type ) {
					return el(
						'label',
						{ key: type.value, className: 'paradox-cardpointe-radio' },
						el( 'input', {
							type: 'radio',
							name: FIELD + '_accttype_ui',
							value: type.value,
							checked: accttype === type.value,
							onChange: function () { setAccttype( type.value ); }
						} ),
						' ' + type.label
					);
				} )
			)
		);
		children.push(
			el(
				'p',
				{ key: 'help', className: 'paradox-cardpointe-help' },
				el( 'span', { className: 'paradox-cardpointe-label' }, settings.routingLabel ),
				el( 'small', null, settings.routingHelp )
			)
		);
		children.push(
			el(
				'div',
				{ key: 'frame', className: 'paradox-cardpointe-frame-wrap', style: { minHeight: ( settings.iframeHeight || 70 ) + 'px' } },
				el( 'div', { className: 'paradox-cardpointe-frame', ref: containerRef } ),
				! ready ? el( 'p', { className: 'paradox-cardpointe-loading' }, i18n.loading ) : null
			)
		);
		children.push(
			el(
				'p',
				{ key: 'consent', className: 'paradox-cardpointe-consent' },
				el(
					'label',
					null,
					el( 'input', { type: 'checkbox', checked: consent, onChange: function ( e ) { setConsent( !! e.target.checked ); } } ),
					' ' + consentText( billing )
				)
			)
		);

		return el( 'div', { className: 'paradox-cardpointe-form paradox-cardpointe-echeck-form paradox-cardpointe-blocks' }, children );
	}

	function SavedTokenContent( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var billing = props.billing;
		var consentState = useState( false );
		var consent = consentState[ 0 ];
		var setConsent = consentState[ 1 ];
		var consentRef = useRef( consent );
		consentRef.current = consent;

		// A saved bank account still needs a fresh debit authorization each time.
		useEffect( function () {
			if ( ! eventRegistration || ! eventRegistration.onPaymentSetup ) {
				return undefined;
			}
			return eventRegistration.onPaymentSetup( function () {
				if ( ! consentRef.current ) {
					return { type: emitResponse.responseTypes.ERROR, message: i18n.consentRequired, messageContext: emitResponse.noticeContexts.PAYMENTS };
				}
				var data = {};
				data[ FIELD + '_consent' ] = '1';
				return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: data } };
			} );
		}, [ eventRegistration, emitResponse ] );

		return el(
			'p',
			{ className: 'paradox-cardpointe-consent' },
			el(
				'label',
				null,
				el( 'input', { type: 'checkbox', checked: consent, onChange: function ( e ) { setConsent( !! e.target.checked ); } } ),
				' ' + consentText( billing )
			)
		);
	}

	function Edit() {
		return el( 'div', { className: 'paradox-cardpointe-form' }, el( 'p', null, decode( settings.description || '' ) ) );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: NAME,
		paymentMethodId: NAME,
		label: el( Label ),
		content: el( Content ),
		edit: el( Edit ),
		savedTokenComponent: el( SavedTokenContent ),
		ariaLabel: decode( settings.title || 'eCheck' ),
		canMakePayment: function () { return true; },
		supports: {
			features: settings.supports || [ 'products' ],
			showSavedCards: !! settings.showSavedCards,
			showSaveOption: !! settings.showSaveOption
		}
	} );
} )( window.wp, window.wc, window );
