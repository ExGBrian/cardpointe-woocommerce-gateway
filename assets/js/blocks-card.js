/**
 * Checkout block integration: credit card.
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
	var settings = wc.wcSettings.getSetting( 'paradox_cardpointe_data', {} );
	var i18n = settings.i18n || {};
	var NAME = settings.name || 'paradox_cardpointe';

	function sprintf( text, value ) {
		return String( text || '' ).replace( '%s', value );
	}

	function brandLabel( brand ) {
		return ( i18n.brands && i18n.brands[ brand ] ) ? i18n.brands[ brand ] : brand;
	}

	function Icons() {
		var icons = settings.icons || [];
		return el(
			'span',
			{ className: 'paradox-cardpointe-icons' },
			icons.map( function ( icon ) {
				return el( 'img', { key: icon.id, src: icon.src, alt: icon.alt, width: 32, height: 20, className: 'paradox-cardpointe-card-icon' } );
			} )
		);
	}

	function Label() {
		return el(
			'span',
			{ className: 'paradox-cardpointe-label wc-block-components-payment-method-label' },
			el( 'span', null, decode( settings.title || '' ) ),
			el( Icons )
		);
	}

	function Content( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var containerRef = useRef( null );
		var instanceRef = useRef( null );
		var stateRef = useRef( { token: '', expiry: '', brand: '' } );
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var brandState = useState( '' );
		var brand = brandState[ 0 ];
		var setBrand = brandState[ 1 ];
		var readyState = useState( false );
		var ready = readyState[ 0 ];
		var setReady = readyState[ 1 ];

		// Mount the iframe once; it stays mounted across re-renders.
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
				onToken: function ( token, expiry, detected ) {
					stateRef.current = { token: token, expiry: expiry, brand: detected };
					setBrand( detected );
					if ( detected && settings.allowedCardTypes && settings.allowedCardTypes.indexOf( detected ) === -1 ) {
						setError( sprintf( i18n.cardNotAccepted, brandLabel( detected ) ) );
					} else {
						setError( '' );
					}
				},
				onCleared: function () {
					stateRef.current = { token: '', expiry: '', brand: '' };
					setBrand( '' );
				},
				onError: function ( code, message, isValidation ) {
					stateRef.current = { token: '', expiry: '', brand: '' };
					setBrand( '' );
					if ( isValidation && ( code === '1004' || code === '1005' || code === '1006' ) ) {
						return;
					}
					setError( ( i18n.errorCodes && i18n.errorCodes[ code ] ) ? i18n.errorCodes[ code ] : ( message || i18n.invalidCard ) );
				}
			} );
			return function () {
				if ( instanceRef.current ) {
					instanceRef.current.destroy();
					instanceRef.current = null;
				}
			};
		}, [] );

		// Hand the token to the Store API on submit.
		useEffect( function () {
			if ( ! eventRegistration || ! eventRegistration.onPaymentSetup ) {
				return undefined;
			}
			return eventRegistration.onPaymentSetup( function () {
				var current = stateRef.current;
				var instance = instanceRef.current;

				function success( state ) {
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								paradox_cardpointe_token: state.token,
								paradox_cardpointe_expiry: state.expiry,
								paradox_cardpointe_brand_hint: state.brand
							}
						}
					};
				}
				function failure( message ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: message,
						messageContext: emitResponse.noticeContexts.PAYMENTS
					};
				}

				if ( current.brand && settings.allowedCardTypes && settings.allowedCardTypes.indexOf( current.brand ) === -1 ) {
					return failure( sprintf( i18n.cardNotAccepted, brandLabel( current.brand ) ) );
				}
				if ( current.token ) {
					return success( current );
				}
				if ( ! instance ) {
					return failure( i18n.enterCard );
				}
				// The shopper may have clicked "Place order" before the tokenizer finished.
				return instance.waitForToken( 3000 ).then( function () {
					return success( stateRef.current );
				}, function () {
					return failure( i18n.enterCard );
				} );
			} );
		}, [ eventRegistration, emitResponse ] );

		// After a failed order the CVV attached to the token is gone: start over.
		useEffect( function () {
			if ( ! eventRegistration || ! eventRegistration.onCheckoutFail ) {
				return undefined;
			}
			return eventRegistration.onCheckoutFail( function () {
				stateRef.current = { token: '', expiry: '', brand: '' };
				setBrand( '' );
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
				'div',
				{ key: 'frame', className: 'paradox-cardpointe-frame-wrap', style: { minHeight: ( settings.iframeHeight || 250 ) + 'px' } },
				el( 'div', { className: 'paradox-cardpointe-frame', ref: containerRef } ),
				! ready ? el( 'p', { className: 'paradox-cardpointe-loading' }, i18n.loading ) : null
			)
		);
		if ( brand ) {
			children.push(
				el(
					'p',
					{ key: 'status', className: 'paradox-cardpointe-status' },
					settings.icons && settings.icons.filter( function ( i ) { return i.id === brand; } ).map( function ( icon ) {
						return el( 'img', { key: icon.id, src: icon.src, alt: icon.alt, width: 32, height: 20, className: 'paradox-cardpointe-card-icon' } );
					} ),
					' ' + sprintf( i18n.detected, brandLabel( brand ) )
				)
			);
		}

		return el( 'div', { className: 'paradox-cardpointe-form paradox-cardpointe-blocks' }, children );
	}

	function SavedTokenContent() {
		// Nothing to collect for a saved card; Blocks posts the token ID for us.
		return settings.isSandbox ? el( 'p', { className: 'paradox-cardpointe-sandbox-notice' }, settings.sandboxNotice ) : null;
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
		ariaLabel: decode( settings.title || 'Credit Card' ),
		canMakePayment: function () { return true; },
		supports: {
			features: settings.supports || [ 'products' ],
			showSavedCards: !! settings.showSavedCards,
			showSaveOption: !! settings.showSaveOption
		}
	} );
} )( window.wp, window.wc, window );
