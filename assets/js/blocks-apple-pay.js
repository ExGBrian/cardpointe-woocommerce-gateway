/**
 * Cart and Checkout blocks: Apple Pay express payment method.
 *
 * WooCommerce shows express methods in its own "Express checkout" area on both the
 * Cart and the Checkout block. The button runs the same Store API driven flow as the
 * classic pages (apple-pay-core.js), so the order is placed from the Apple Pay sheet;
 * the block is only told that an express payment has taken over and when it is done.
 *
 * Written without JSX so no build step is needed.
 */
( function ( wp, wc, window ) {
	'use strict';

	var core = window.ParadoxCardPointeApplePay;
	if ( ! core || ! wc || ! wc.wcBlocksRegistry || ! wc.wcBlocksRegistry.registerExpressPaymentMethod || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var settings = wc.wcSettings.getSetting( 'paradox_cardpointe_data', {} );
	var applePay = settings.applePay;
	if ( ! applePay ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var GATEWAY = settings.name || 'paradox_cardpointe';

	/**
	 * The sheet may have changed the address and shipping method on the server; have the
	 * block fetch the cart again rather than show what it remembers.
	 */
	function refreshCart() {
		try {
			wp.data.dispatch( 'wc/store/cart' ).invalidateResolutionForStore();
		} catch ( e ) {
			core.log( 'could not refresh the cart store', e );
		}
	}

	function ExpressButton( props ) {
		var slotRef = useRef( null );
		var propsRef = useRef( props );
		propsRef.current = props;

		useEffect( function () {
			if ( ! slotRef.current ) {
				return undefined;
			}
			core.mountButton( slotRef.current, {
				onClick: function () {
					var current = propsRef.current;
					var billing = current.billing || {};
					var total = billing.cartTotal || {};
					var currency = billing.currency || {};
					var minor = typeof currency.minorUnit === 'number' ? currency.minorUnit : 2;

					core.express( {
						amount: core.minorToDecimal( total.value, minor ),
						currency: currency.code,
						needsShipping: !! ( current.shippingData && current.shippingData.needsShipping ),
						onOpen: function () {
							if ( typeof current.onClick === 'function' ) {
								current.onClick();
							}
						},
						onMessage: function ( message ) {
							if ( message && typeof current.setExpressPaymentError === 'function' ) {
								current.setExpressPaymentError( message );
							}
						},
						onClose: function () {
							if ( typeof current.onClose === 'function' ) {
								current.onClose();
							}
							refreshCart();
						}
					} );
				}
			} );
			return undefined;
		}, [] );

		// React owns this element but not what is inside it: the button is put there by hand
		// and left alone across re-renders.
		return el( 'div', { className: 'paradox-cardpointe-apple-pay-slot paradox-cardpointe-apple-pay-blocks-express', ref: slotRef } );
	}

	function EditorPreview() {
		return el( 'div', { className: 'paradox-cardpointe-apple-pay-mock is-style-' + ( core.config.buttonStyle || 'black' ), 'aria-hidden': 'true' }, ( core.config.i18n || {} ).applePayLabel || 'Apple Pay' );
	}

	wc.wcBlocksRegistry.registerExpressPaymentMethod( {
		name: 'paradox_cardpointe_apple_pay',
		title: 'Apple Pay (CardPointe)',
		description: 'Apple Pay through the CardPointe gateway.',
		gatewayId: GATEWAY,
		paymentMethodId: GATEWAY,
		content: el( ExpressButton ),
		edit: el( EditorPreview ),
		canMakePayment: function () {
			return !! applePay.express && core.expressSupported();
		},
		// Only plain products: a cart that has to keep a card on file (subscriptions) asks
		// for features this method does not declare, and the block hides it by itself.
		supports: {
			features: [ 'products' ]
		}
	} );
} )( window.wp, window.wc, window );
