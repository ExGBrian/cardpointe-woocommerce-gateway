/**
 * Apple Pay core: everything the classic pages, the Checkout/Cart blocks and the
 * settings screen share.
 *
 * Two flows are built on it.
 *
 *  - The form flow (the button inside the credit card box): the shopper has already
 *    filled in the checkout form, so the sheet only collects payment and the wallet
 *    token is submitted with that form. The callers own this flow; core supplies the
 *    button, the session, merchant validation and completion.
 *
 *  - The express flow (product page, cart, top of checkout): there is no form, so the
 *    sheet collects the contact details, and shipping and tax are recalculated live
 *    as the shopper picks an address and a shipping method. express() runs the whole
 *    thing against WooCommerce's Store API, which exists whether a page is built with
 *    blocks or shortcodes, so one implementation serves every surface.
 *
 * With Apple's JS SDK loaded (the "other browsers" setting) ApplePaySession also exists
 * in Chrome, Edge and Firefox, where the sheet is a code the shopper scans with an
 * iPhone, and the button must be Apple's <apple-pay-button> element, because only
 * Safari can draw the CSS one.
 *
 * Global: window.ParadoxCardPointeApplePay
 */
( function ( window, document ) {
	'use strict';

	var config = window.paradox_cardpointe_apple_pay || {};
	var i18n = config.i18n || {};

	var DEBUG = /[?&]paradox-cardpointe-debug=1/.test( window.location.search ) || ( function () {
		try {
			return window.localStorage.getItem( 'paradoxCardpointeDebug' ) === '1';
		} catch ( e ) {
			return false;
		}
	} )();

	function log() {
		if ( DEBUG && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ '[CardPointe Apple Pay ' + ( config.version || '' ) + ']' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	function noop() {}

	/* ---------------------------------------------------------------------
	 * Capability
	 * ------------------------------------------------------------------ */

	function supported() {
		try {
			var Session = window.ApplePaySession;
			return !! ( Session && Session.canMakePayments && Session.canMakePayments() );
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Highest Apple Pay JS version this browser supports that we know how to talk to.
	 */
	function pickVersion() {
		var Session = window.ApplePaySession;
		if ( ! Session || ! Session.supportsVersion ) {
			return 0;
		}
		for ( var v = 6; v >= 1; v-- ) {
			try {
				if ( Session.supportsVersion( v ) ) {
					return v;
				}
			} catch ( e ) {
				// Keep looking.
			}
		}
		return 0;
	}

	/**
	 * The express flow updates totals with the object-style callbacks introduced in version 3.
	 */
	function expressSupported() {
		return supported() && pickVersion() >= 3;
	}

	/* ---------------------------------------------------------------------
	 * Small helpers (exported for tests)
	 * ------------------------------------------------------------------ */

	function minorToDecimal( value, minor ) {
		minor = typeof minor === 'number' ? minor : 2;
		var n = parseInt( value, 10 ) || 0;
		return ( n / Math.pow( 10, minor ) ).toFixed( minor );
	}

	function decode( text ) {
		if ( ! text || String( text ).indexOf( '&' ) === -1 && String( text ).indexOf( '<' ) === -1 ) {
			return String( text || '' );
		}
		var box = document.createElement( 'textarea' );
		box.innerHTML = String( text ).replace( /<[^>]*>/g, '' );
		return box.value;
	}

	/**
	 * Before authorization Apple redacts the address: Canadian and British postcodes
	 * arrive as the outward half only, which WooCommerce rejects as malformed. The
	 * missing half does not affect which shipping zone or tax rate applies, so a
	 * syntactically valid placeholder is appended; the real postcode replaces it once
	 * the payment is authorized.
	 */
	function normalisePostcode( postcode, country ) {
		var value = String( postcode || '' ).trim().toUpperCase();
		if ( country === 'CA' && /^[A-Z]\d[A-Z]$/.test( value ) ) {
			return value + ' 0A0';
		}
		if ( country === 'GB' && /^[A-Z]{1,2}\d[A-Z\d]?$/.test( value ) ) {
			return value + ' 1AA';
		}
		return value;
	}

	/**
	 * WooCommerce address from an Apple Pay contact. Empty values are left out so a
	 * redacted contact updates only what it knows.
	 */
	function addressFromContact( contact, fallbackCountry ) {
		contact = contact || {};
		var lines = contact.addressLines || [];
		var country = String( contact.countryCode || fallbackCountry || '' ).toUpperCase();
		var raw = {
			first_name: contact.givenName,
			last_name: contact.familyName,
			address_1: lines[ 0 ],
			address_2: lines.slice( 1 ).join( ', ' ),
			city: contact.locality,
			state: contact.administrativeArea,
			postcode: normalisePostcode( contact.postalCode, country ),
			country: country
		};
		var address = {};
		Object.keys( raw ).forEach( function ( key ) {
			if ( raw[ key ] ) {
				address[ key ] = String( raw[ key ] );
			}
		} );
		return address;
	}

	function lineItems( cart ) {
		var totals = cart.totals || {};
		var minor = totals.currency_minor_unit;
		var items = [];
		function add( label, value, negative ) {
			var n = parseInt( value, 10 ) || 0;
			if ( n > 0 ) {
				items.push( { label: label, amount: ( negative ? '-' : '' ) + minorToDecimal( n, minor ), type: 'final' } );
			}
		}
		add( i18n.lineSubtotal || 'Subtotal', totals.total_items );
		add( i18n.lineDiscount || 'Discount', totals.total_discount, true );
		if ( cart.needs_shipping ) {
			add( i18n.lineShipping || 'Shipping', totals.total_shipping );
		}
		add( i18n.lineFees || 'Fees', totals.total_fees );
		add( i18n.lineTax || 'Tax', totals.total_tax );
		return items;
	}

	/**
	 * Shipping methods for the sheet. Apple treats the first entry as the selected one.
	 */
	function shippingMethods( cart ) {
		var pkg = ( cart.shipping_rates || [] )[ 0 ];
		if ( ! pkg ) {
			return [];
		}
		var minor = ( cart.totals || {} ).currency_minor_unit;
		return ( pkg.shipping_rates || [] ).slice().sort( function ( a, b ) {
			return ( b.selected ? 1 : 0 ) - ( a.selected ? 1 : 0 );
		} ).map( function ( rate ) {
			return {
				label: decode( rate.name ),
				detail: decode( rate.delivery_time || rate.description || '' ),
				amount: minorToDecimal( rate.price, minor ),
				identifier: String( rate.rate_id )
			};
		} );
	}

	function cartTotal( cart ) {
		return minorToDecimal( cart.totals.total_price, cart.totals.currency_minor_unit );
	}

	/* ---------------------------------------------------------------------
	 * Button
	 * ------------------------------------------------------------------ */

	function elementDefined() {
		return !! ( window.customElements && window.customElements.get && window.customElements.get( 'apple-pay-button' ) );
	}

	/**
	 * Puts an Apple Pay button into a slot, once.
	 *
	 * With Apple's SDK in use the button is Apple's <apple-pay-button> element, which
	 * draws in every browser. The SDK registers that element a moment after it starts
	 * running, which is later than this script mounts its first buttons, so the element
	 * is created whether or not it is defined yet: a custom element created early is
	 * upgraded in place when its definition arrives. Deciding by "is it defined right
	 * now" is what left an empty box in Chrome, where the alternative, the CSS button,
	 * cannot be drawn.
	 *
	 * @param {HTMLElement} slot    Empty container.
	 * @param {Object}      options { style, type, onClick }
	 * @return {HTMLElement|null} The button.
	 */
	function mountButton( slot, options ) {
		if ( ! slot ) {
			return null;
		}
		if ( slot.getAttribute( 'data-mounted' ) === '1' && slot.firstChild ) {
			return slot.firstChild;
		}
		options = options || {};
		var style = options.style || config.buttonStyle || 'black';
		var type = options.type || config.buttonType || 'plain';

		function wire( button ) {
			button.classList.add( 'paradox-cardpointe-apple-pay-trigger' );
			if ( typeof options.onClick === 'function' ) {
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					options.onClick( event );
				} );
			}
			return button;
		}

		function appleElement() {
			var button = document.createElement( 'apple-pay-button' );
			button.setAttribute( 'buttonstyle', style );
			button.setAttribute( 'type', type );
			button.setAttribute( 'locale', config.locale || 'en-US' );
			return wire( button );
		}

		// Only Safari can draw this one.
		function cssButton() {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'paradox-cardpointe-apple-pay-button is-style-' + style + ' is-type-' + type;
			button.setAttribute( 'aria-label', i18n.applePayLabel || 'Apple Pay' );
			return wire( button );
		}

		var defined = elementDefined();
		var useElement = defined || ( !! config.sdk && !! window.customElements );
		var button = useElement ? appleElement() : cssButton();

		while ( slot.firstChild ) {
			slot.removeChild( slot.firstChild );
		}
		slot.appendChild( button );
		slot.setAttribute( 'data-mounted', '1' );

		if ( useElement && ! defined ) {
			// The SDK may never arrive (blocked, offline). Without it only Safari can have
			// got this far, and Safari can draw the CSS button.
			window.setTimeout( function () {
				if ( elementDefined() || button.parentNode !== slot ) {
					return;
				}
				log( 'Apple\'s button element never became available; falling back to the CSS button' );
				slot.replaceChild( cssButton(), button );
			}, 4000 );
		}

		return button;
	}

	/* ---------------------------------------------------------------------
	 * Session plumbing
	 * ------------------------------------------------------------------ */

	function baseRequest( total ) {
		return {
			countryCode: config.countryCode,
			currencyCode: total.currency || config.currencyCode,
			supportedNetworks: config.networks || [],
			merchantCapabilities: [ 'supports3DS' ],
			total: { label: config.label, amount: total.amount, type: total.type || 'final' }
		};
	}

	function createSession( request ) {
		return new window.ApplePaySession( pickVersion() || 1, request );
	}

	function postForm( url, fields ) {
		var body = new window.FormData();
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return window.fetch( url, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Resolves with the merchant session for a validation URL.
	 */
	function validateMerchant( validationURL, nonce ) {
		return postForm( config.validateUrl, { validation_url: validationURL, nonce: nonce || config.nonce } ).then( function ( json ) {
			if ( json && json.success && json.data ) {
				return json.data;
			}
			throw new Error( ( json && json.data && json.data.message ) || i18n.applePayValidation || 'Apple Pay could not be started.' );
		} );
	}

	/**
	 * Tells the sheet how the payment went. A message is shown inside the sheet.
	 */
	function complete( session, ok, message ) {
		var Session = window.ApplePaySession;
		var status = ok ? Session.STATUS_SUCCESS : Session.STATUS_FAILURE;
		try {
			if ( pickVersion() >= 3 ) {
				var result = { status: status };
				if ( ! ok && message && window.ApplePayError ) {
					result.errors = [ new window.ApplePayError( 'unknown', undefined, String( message ) ) ];
				}
				session.completePayment( result );
			} else {
				session.completePayment( status );
			}
		} catch ( e ) {
			log( 'completePayment failed', e );
		}
	}

	function walletFromPayment( payment ) {
		var token = ( payment && payment.token ) || {};
		var method = token.paymentMethod || {};
		return {
			data: JSON.stringify( token.paymentData || {} ),
			network: method.network || '',
			display: method.displayName || ''
		};
	}

	/**
	 * Checkout fields that carry a wallet payment to the gateway. The total is the amount
	 * the shopper approved in the sheet; the server refuses to charge anything else.
	 */
	function walletFields( wallet, total ) {
		var gateway = config.gateway || 'paradox_cardpointe';
		var fields = {};
		fields[ gateway + '_wallet' ] = 'apple_pay';
		fields[ gateway + '_wallet_data' ] = wallet.data;
		fields[ gateway + '_wallet_network' ] = wallet.network;
		fields[ gateway + '_wallet_display' ] = wallet.display;
		if ( total ) {
			fields[ gateway + '_wallet_total' ] = String( total );
		}
		fields[ 'wc-' + gateway + '-payment-token' ] = 'new';
		return fields;
	}

	/* ---------------------------------------------------------------------
	 * Store API
	 * ------------------------------------------------------------------ */

	/**
	 * Minimal Store API client. Every response carries a fresh nonce, which is kept for
	 * the next call, so a nonce baked into a cached product page never gets in the way.
	 */
	function storeApi() {
		var nonce = config.storeApiNonce || '';

		function call( method, path, body ) {
			var headers = { Accept: 'application/json' };
			if ( body ) {
				headers[ 'Content-Type' ] = 'application/json';
			}
			if ( nonce ) {
				headers.Nonce = nonce;
			}
			// WordPress only honours the login cookie on REST requests that carry its own
			// nonce; without it a signed-in shopper would be treated as a guest with a
			// different, empty cart.
			if ( config.loggedIn && config.restNonce ) {
				headers[ 'X-WP-Nonce' ] = config.restNonce;
			}
			return window.fetch( String( config.storeApiRoot || '' ) + path, {
				method: method,
				credentials: 'same-origin',
				headers: headers,
				body: body ? JSON.stringify( body ) : undefined
			} ).then( function ( response ) {
				var fresh = response.headers.get( 'Nonce' );
				if ( fresh ) {
					nonce = fresh;
				}
				return response.json().then( function ( json ) {
					if ( ! response.ok ) {
						var error = new Error( decode( json && json.message ) || i18n.applePayFailed || 'Request failed.' );
						error.code = json && json.code;
						throw error;
					}
					return json;
				} );
			} );
		}

		return {
			get: function ( path ) {
				return call( 'GET', path );
			},
			post: function ( path, body ) {
				return call( 'POST', path, body || {} );
			},
			nonce: function () {
				return nonce;
			}
		};
	}

	/* ---------------------------------------------------------------------
	 * Express flow
	 * ------------------------------------------------------------------ */

	/**
	 * Opens the sheet and sees the purchase through. Must be called from a click handler:
	 * Apple only allows a session to be created during a user gesture, which is why the
	 * sheet opens on an estimated "pending" total and is corrected from the real cart a
	 * moment later.
	 *
	 * @param {Object} opts {
	 *     amount         Estimated total as a decimal string.
	 *     currency       Currency code (defaults to the store's).
	 *     needsShipping  Whether a shipping address has to be collected.
	 *     product        { id, quantity, variation[] } to add to the cart first (product page).
	 *     onOpen         Called once the sheet is opening.
	 *     onMessage      Called with an error message, or '' to clear it.
	 *     onClose        Called with 'cancel' or 'error' when the sheet closes without an order.
	 * }
	 * @return {Object|null} The session, or null when it could not be created.
	 */
	function express( opts ) {
		opts = opts || {};
		var api = storeApi();
		var state = { cart: null, added: null, finished: false };
		var needsShipping = !! opts.needsShipping;

		function say( message ) {
			if ( typeof opts.onMessage === 'function' ) {
				opts.onMessage( message || '' );
			}
		}

		function closed( reason ) {
			if ( typeof opts.onClose === 'function' ) {
				opts.onClose( reason );
			}
		}

		var request = baseRequest( { amount: opts.amount || '0.00', type: 'pending', currency: opts.currency } );
		request.requiredBillingContactFields = [ 'postalAddress', 'name' ];
		request.requiredShippingContactFields = needsShipping ? [ 'postalAddress', 'name', 'phone', 'email' ] : [ 'name', 'phone', 'email' ];

		var session;
		try {
			session = new window.ApplePaySession( pickVersion(), request );
		} catch ( e ) {
			log( 'could not create the session', e );
			say( i18n.applePayFailed );
			return null;
		}
		say( '' );

		/**
		 * Puts the product page's item into the cart, remembering how to take it out again.
		 */
		function addProduct( cart ) {
			var before = {};
			( cart.items || [] ).forEach( function ( item ) {
				before[ item.key ] = item.quantity;
			} );
			return api.post( 'cart/add-item', {
				id: opts.product.id,
				quantity: opts.product.quantity || 1,
				variation: opts.product.variation || []
			} ).then( function ( after ) {
				( after.items || [] ).some( function ( item ) {
					var previous = before[ item.key ] || 0;
					if ( item.quantity > previous ) {
						state.added = { key: item.key, previous: previous };
						return true;
					}
					return false;
				} );
				return after;
			} );
		}

		/**
		 * Restores the cart when a product page purchase is abandoned, so that trying
		 * again does not buy the item twice.
		 */
		function undo() {
			if ( ! state.added || state.finished ) {
				return Promise.resolve();
			}
			var added = state.added;
			state.added = null;
			var request = added.previous > 0
				? api.post( 'cart/update-item', { key: added.key, quantity: added.previous } )
				: api.post( 'cart/remove-item', { key: added.key } );
			return request.catch( noop );
		}

		var ready = api.get( 'cart' ).then( function ( cart ) {
			return opts.product ? addProduct( cart ) : cart;
		} ).then( function ( cart ) {
			if ( cart.needs_shipping && ! needsShipping ) {
				throw new Error( i18n.applePayUseCheckout || i18n.applePayFailed );
			}
			state.cart = cart;
			return cart;
		} );
		ready.catch( noop );

		function update() {
			return {
				newTotal: { label: config.label, amount: cartTotal( state.cart ), type: 'final' },
				newLineItems: lineItems( state.cart )
			};
		}

		function abortWith( error ) {
			log( 'aborting', error );
			try {
				session.abort();
			} catch ( e ) {
				// The sheet may already be gone.
			}
			undo().then( function () {
				say( ( error && error.message ) || i18n.applePayFailed );
				closed( 'error' );
			} );
		}

		session.onvalidatemerchant = function ( event ) {
			ready.then( function () {
				return validateMerchant( event.validationURL, api.nonce() );
			} ).then( function ( merchantSession ) {
				session.completeMerchantValidation( merchantSession );
			} ).catch( abortWith );
		};

		session.onpaymentmethodselected = function () {
			ready.then( function () {
				session.completePaymentMethodSelection( update() );
			} ).catch( abortWith );
		};

		session.onshippingcontactselected = function ( event ) {
			ready.then( function () {
				var address = addressFromContact( event.shippingContact, config.countryCode );
				return api.post( 'cart/update-customer', { shipping_address: address, billing_address: address } );
			} ).then( function ( cart ) {
				state.cart = cart;
				var methods = shippingMethods( cart );
				var result = update();
				result.newShippingMethods = methods;
				if ( needsShipping && ! methods.length && window.ApplePayError ) {
					result.errors = [ new window.ApplePayError( 'addressUnserviceable', undefined, i18n.applePayNoShipping || '' ) ];
				}
				session.completeShippingContactSelection( result );
			} ).catch( abortWith );
		};

		session.onshippingmethodselected = function ( event ) {
			var pkg = ( ( state.cart && state.cart.shipping_rates ) || [] )[ 0 ];
			api.post( 'cart/select-shipping-rate', {
				package_id: pkg ? pkg.package_id : 0,
				rate_id: event.shippingMethod.identifier
			} ).then( function ( cart ) {
				state.cart = cart;
				session.completeShippingMethodSelection( update() );
			} ).catch( abortWith );
		};

		session.onpaymentauthorized = function ( event ) {
			var payment = event.payment || {};
			var shippingContact = payment.shippingContact || {};
			var billing = addressFromContact( payment.billingContact, config.countryCode );

			if ( ! billing.first_name && shippingContact.givenName ) {
				billing.first_name = shippingContact.givenName;
			}
			if ( ! billing.last_name && shippingContact.familyName ) {
				billing.last_name = shippingContact.familyName;
			}
			billing.email = shippingContact.emailAddress || '';
			billing.phone = shippingContact.phoneNumber || '';

			var shipping;
			if ( needsShipping ) {
				shipping = addressFromContact( shippingContact, config.countryCode );
				shipping.phone = shippingContact.phoneNumber || '';
			} else {
				shipping = JSON.parse( JSON.stringify( billing ) );
				delete shipping.email;
			}

			var fields = walletFields( walletFromPayment( payment ), cartTotal( state.cart ) );
			var paymentData = Object.keys( fields ).map( function ( key ) {
				return { key: key, value: fields[ key ] };
			} );

			api.post( 'checkout', {
				billing_address: billing,
				shipping_address: shipping,
				payment_method: config.gateway,
				payment_data: paymentData
			} ).then( function ( order ) {
				var result = order && order.payment_result;
				if ( result && result.payment_status === 'success' && result.redirect_url ) {
					state.finished = true;
					complete( session, true );
					window.location.href = result.redirect_url;
					return;
				}
				throw new Error( i18n.applePayFailed );
			} ).catch( function ( error ) {
				log( 'checkout failed', error );
				var message = ( error && error.message ) || i18n.applePayFailed;
				complete( session, false, message );
				undo().then( function () {
					say( message );
					closed( 'error' );
				} );
			} );
		};

		session.oncancel = function () {
			log( 'sheet cancelled' );
			undo().then( function () {
				closed( 'cancel' );
			} );
		};

		if ( typeof opts.onOpen === 'function' ) {
			opts.onOpen();
		}
		session.begin();
		log( 'express session started', { version: pickVersion(), estimate: opts.amount, needsShipping: needsShipping, product: opts.product || null } );
		return session;
	}

	window.ParadoxCardPointeApplePay = {
		config: config,
		log: log,
		supported: supported,
		expressSupported: expressSupported,
		pickVersion: pickVersion,
		mountButton: mountButton,
		baseRequest: baseRequest,
		createSession: createSession,
		validateMerchant: validateMerchant,
		complete: complete,
		walletFromPayment: walletFromPayment,
		walletFields: walletFields,
		minorToDecimal: minorToDecimal,
		express: express,
		_test: {
			addressFromContact: addressFromContact,
			normalisePostcode: normalisePostcode,
			lineItems: lineItems,
			shippingMethods: shippingMethods,
			cartTotal: cartTotal,
			decode: decode
		}
	};
} )( window, document );
