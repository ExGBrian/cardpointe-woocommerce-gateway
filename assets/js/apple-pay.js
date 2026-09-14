/**
 * Apple Pay button for the classic checkout, CheckoutWC and the order-pay page.
 *
 * The button is rendered by PHP inside the card gateway's payment box, hidden, with
 * the current total on its data attributes. This script reveals every present and
 * future button with one class on <html> once Safari confirms Apple Pay is usable,
 * and handles clicks by delegation, so the payment box can be re-rendered as often
 * as WooCommerce likes without any re-binding.
 *
 * On authorization the encrypted Apple token is posted with the rest of the checkout
 * form through WooCommerce's own checkout endpoint; the server tokenizes it with
 * CardSecure and authorizes as a normal card payment. The Apple Pay sheet is only
 * told the payment succeeded once WooCommerce says the order went through.
 *
 * Depends on paradox_cardpointe_params (localized with checkout-classic.js).
 */
( function ( window, document ) {
	'use strict';

	var params = window.paradox_cardpointe_params || {};
	var config = params.applePay;
	var i18n = params.i18n || {};

	if ( ! config ) {
		return;
	}

	var DEBUG = /[?&]paradox-cardpointe-debug=1/.test( window.location.search ) || ( function () {
		try {
			return window.localStorage.getItem( 'paradoxCardpointeDebug' ) === '1';
		} catch ( e ) {
			return false;
		}
	} )();

	function log() {
		if ( DEBUG && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ '[CardPointe Apple Pay ' + ( params.version || '' ) + ']' ].concat( Array.prototype.slice.call( arguments ) ) );
		}
	}

	var ApplePaySession = window.ApplePaySession;
	if ( ! ApplePaySession || ! ApplePaySession.canMakePayments || ! ApplePaySession.canMakePayments() ) {
		log( 'Apple Pay is not available in this browser' );
		return;
	}

	document.documentElement.classList.add( 'paradox-cardpointe-apple-pay-available' );
	log( 'Apple Pay available; buttons will be shown' );

	var busy = false;

	/**
	 * Highest API version this Safari supports that we know how to talk to.
	 */
	function pickVersion() {
		for ( var v = 6; v >= 3; v-- ) {
			if ( ApplePaySession.supportsVersion( v ) ) {
				return v;
			}
		}
		return 1;
	}

	function closest( el, selector ) {
		while ( el && el.nodeType === 1 ) {
			if ( el.matches( selector ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function errorsBox( fieldset ) {
		return fieldset.querySelector( '.paradox-cardpointe-errors' );
	}

	function showError( fieldset, message ) {
		var box = errorsBox( fieldset );
		if ( ! box ) {
			return;
		}
		if ( ! message ) {
			box.textContent = '';
			box.hidden = true;
			return;
		}
		box.textContent = message;
		box.hidden = false;
		scrollTo( box );
	}

	/**
	 * Shows WooCommerce's own error markup (the checkout endpoint returns HTML notices).
	 */
	function showMessages( fieldset, html ) {
		var box = errorsBox( fieldset );
		if ( ! box ) {
			return;
		}
		if ( ! html ) {
			showError( fieldset, i18n.applePayFailed );
			return;
		}
		var temp = document.createElement( 'div' );
		temp.innerHTML = html;
		// Keep the text, drop WooCommerce's wrappers so it sits inside our own alert box.
		var items = temp.querySelectorAll( 'li' );
		box.textContent = '';
		if ( items.length ) {
			var list = document.createElement( 'ul' );
			Array.prototype.forEach.call( items, function ( li ) {
				var item = document.createElement( 'li' );
				item.textContent = li.textContent.trim();
				list.appendChild( item );
			} );
			box.appendChild( list );
		} else {
			box.textContent = temp.textContent.trim() || i18n.applePayFailed;
		}
		box.hidden = false;
		scrollTo( box );
	}

	function scrollTo( el ) {
		try {
			var top = el.getBoundingClientRect().top + window.pageYOffset - 100;
			window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		} catch ( e ) {
			// Older Safari: no smooth scrolling options; not worth failing over.
		}
	}

	function block( form ) {
		form.classList.add( 'processing' );
		if ( window.jQuery && window.jQuery.fn && window.jQuery.fn.block ) {
			window.jQuery( form ).block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
		}
	}

	function unblock( form ) {
		form.classList.remove( 'processing' );
		if ( window.jQuery && window.jQuery.fn && window.jQuery.fn.unblock ) {
			window.jQuery( form ).unblock();
		}
	}

	function complete( session, version, ok ) {
		var status = ok ? ApplePaySession.STATUS_SUCCESS : ApplePaySession.STATUS_FAILURE;
		try {
			session.completePayment( version >= 3 ? { status: status } : status );
		} catch ( e ) {
			log( 'completePayment failed', e );
		}
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
	 * WooCommerce wraps its checkout JSON in comment markers when PHP notices leak into the output.
	 */
	function parseCheckoutResult( text ) {
		var match = text.match( /<!--WC_START-->([\s\S]*)<!--WC_END-->/ );
		try {
			return JSON.parse( match ? match[ 1 ] : text );
		} catch ( e ) {
			return { result: 'failure', messages: '' };
		}
	}

	/**
	 * Writes the wallet fields into the checkout form data, replacing any card token.
	 */
	function applyWalletFields( formData, gateway, wallet ) {
		formData.set( 'payment_method', gateway );
		formData.set( gateway + '_wallet', 'apple_pay' );
		formData.set( gateway + '_wallet_data', wallet.data );
		formData.set( gateway + '_wallet_network', wallet.network );
		formData.set( gateway + '_wallet_display', wallet.display );
		formData.set( 'wc-' + gateway + '-payment-token', 'new' );
		formData.delete( 'wc-' + gateway + '-new-payment-method' );
		formData.delete( gateway + '_token' );
		formData.delete( gateway + '_expiry' );
		formData.delete( gateway + '_brand_hint' );
	}

	/**
	 * Submits the checkout through WooCommerce's AJAX endpoint and resolves with the outcome.
	 */
	function submitCheckout( form, gateway, wallet ) {
		var formData = new window.FormData( form );
		applyWalletFields( formData, gateway, wallet );

		var url = ( window.wc_checkout_params && window.wc_checkout_params.checkout_url ) || config.checkoutUrl;
		block( form );
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
			headers: { Accept: 'application/json, text/javascript, */*; q=0.01', 'X-Requested-With': 'XMLHttpRequest' }
		} ).then( function ( response ) {
			return response.text();
		} ).then( function ( text ) {
			unblock( form );
			var result = parseCheckoutResult( text );
			log( 'checkout result', result.result );
			if ( result.result === 'success' && result.redirect ) {
				return { success: true, redirect: result.redirect };
			}
			if ( result.reload === true || result.reload === 'true' ) {
				return { success: false, reload: true };
			}
			return { success: false, messages: result.messages || '' };
		}, function ( error ) {
			unblock( form );
			throw error;
		} );
	}

	/**
	 * The order-pay form is a plain POST, so the wallet fields are written into it and
	 * the form submitted after the sheet is told to close.
	 */
	function fillOrderPayForm( form, fieldset, gateway, wallet ) {
		var fields = {};
		fields[ gateway + '_wallet' ] = 'apple_pay';
		fields[ gateway + '_wallet_data' ] = wallet.data;
		fields[ gateway + '_wallet_network' ] = wallet.network;
		fields[ gateway + '_wallet_display' ] = wallet.display;

		Object.keys( fields ).forEach( function ( name ) {
			var input = fieldset.querySelector( 'input[name="' + name + '"]' );
			if ( ! input ) {
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = name;
				fieldset.appendChild( input );
			}
			input.value = fields[ name ];
		} );

		var radio = form.querySelector( '#payment_method_' + gateway );
		if ( radio ) {
			radio.checked = true;
		}
		var newToken = form.querySelector( 'input[name="wc-' + gateway + '-payment-token"][value="new"]' );
		if ( newToken ) {
			newToken.checked = true;
		}
		var save = form.querySelector( 'input[name="wc-' + gateway + '-new-payment-method"]' );
		if ( save ) {
			save.checked = false;
		}
	}

	function start( wrap, form, fieldset ) {
		var ds = wrap.dataset;
		var gateway = ds.gateway || fieldset.getAttribute( 'data-gateway' );
		var request = {
			countryCode: ds.country,
			currencyCode: ds.currency,
			supportedNetworks: ( ds.networks || '' ).split( ',' ).filter( Boolean ),
			merchantCapabilities: [ 'supports3DS' ],
			total: { label: ds.label, amount: ds.amount, type: 'final' }
		};
		var version = pickVersion();
		var session;

		try {
			session = new ApplePaySession( version, request );
		} catch ( e ) {
			log( 'could not create session', e );
			showError( fieldset, i18n.applePayFailed );
			return;
		}

		busy = true;
		showError( fieldset, '' );
		log( 'session started', { version: version, amount: ds.amount, currency: ds.currency } );

		session.onvalidatemerchant = function ( event ) {
			postForm( config.validateUrl, { validation_url: event.validationURL, nonce: config.nonce } ).then( function ( json ) {
				if ( json && json.success && json.data ) {
					session.completeMerchantValidation( json.data );
					return;
				}
				throw new Error( ( json && json.data && json.data.message ) || 'merchant validation rejected' );
			} ).catch( function ( error ) {
				log( 'merchant validation failed', error );
				try {
					session.abort();
				} catch ( e ) {
					// The sheet may already be gone.
				}
				busy = false;
				showError( fieldset, i18n.applePayValidation );
			} );
		};

		session.onpaymentauthorized = function ( event ) {
			var token = ( event.payment && event.payment.token ) || {};
			var method = token.paymentMethod || {};
			var wallet = {
				data: JSON.stringify( token.paymentData || {} ),
				network: method.network || '',
				display: method.displayName || ''
			};

			if ( form.id === 'order_review' ) {
				fillOrderPayForm( form, fieldset, gateway, wallet );
				complete( session, version, true );
				busy = false;
				form.submit();
				return;
			}

			submitCheckout( form, gateway, wallet ).then( function ( result ) {
				if ( result.success ) {
					complete( session, version, true );
					window.location.href = result.redirect;
					return;
				}
				complete( session, version, false );
				busy = false;
				if ( result.reload ) {
					window.location.reload();
					return;
				}
				showMessages( fieldset, result.messages );
			}, function ( error ) {
				log( 'checkout request failed', error );
				complete( session, version, false );
				busy = false;
				showError( fieldset, i18n.applePayFailed );
			} );
		};

		session.oncancel = function () {
			log( 'sheet cancelled' );
			busy = false;
		};

		session.begin();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = closest( event.target, '.paradox-cardpointe-apple-pay-button' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		if ( busy ) {
			return;
		}
		var wrap = closest( button, '.paradox-cardpointe-apple-pay' );
		var fieldset = closest( button, '.paradox-cardpointe-form' );
		var form = closest( button, 'form' );
		if ( ! wrap || ! fieldset || ! form ) {
			log( 'button is not inside a payment form' );
			return;
		}
		start( wrap, form, fieldset );
	}, true );
} )( window, document );
