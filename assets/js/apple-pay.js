/**
 * Apple Pay on classic (shortcode) pages, CheckoutWC and the order-pay page.
 *
 * PHP prints empty, hidden slots; this script fills them once the browser says it can
 * use Apple Pay, and keeps filling them as WooCommerce re-renders the page fragments
 * they live in.
 *
 *  - .paradox-cardpointe-apple-pay          inside the credit card box. The shopper has
 *    filled in the checkout form, so the wallet token is posted with that form through
 *    WooCommerce's own checkout endpoint.
 *  - .paradox-cardpointe-apple-pay-express  product page, cart, top of checkout. There
 *    is no form to lean on, so the sheet collects the details (core.express()).
 *
 * Depends on apple-pay-core.js.
 */
( function ( window, document ) {
	'use strict';

	var core = window.ParadoxCardPointeApplePay;
	if ( ! core || ! core.config || ! core.config.validateUrl ) {
		return;
	}
	var config = core.config;
	var i18n = config.i18n || {};

	if ( ! core.supported() ) {
		core.log( 'Apple Pay is not available in this browser; no buttons will be shown' );
		return;
	}

	var busy = false;
	var variationPrice = 0;

	function closest( el, selector ) {
		while ( el && el.nodeType === 1 ) {
			if ( el.matches( selector ) ) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function scrollTo( el ) {
		try {
			var top = el.getBoundingClientRect().top + window.pageYOffset - 100;
			window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		} catch ( e ) {
			// Older browsers: no smooth scrolling options; not worth failing over.
		}
	}

	function showMessage( box, message ) {
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.hidden = ! message;
		if ( message ) {
			scrollTo( box );
		}
	}

	/**
	 * Shows WooCommerce's own error markup (the checkout endpoint returns HTML notices)
	 * as plain text inside our alert box.
	 */
	function showNotices( box, html ) {
		if ( ! box ) {
			return;
		}
		if ( ! html ) {
			showMessage( box, i18n.applePayFailed );
			return;
		}
		var temp = document.createElement( 'div' );
		temp.innerHTML = html;
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

	/**
	 * Asks WooCommerce to redraw whatever the sheet may have changed behind the page's back
	 * (the address and shipping method chosen in an abandoned express sheet).
	 */
	function refresh( location ) {
		if ( ! window.jQuery ) {
			return;
		}
		if ( location === 'cart' ) {
			window.jQuery( document.body ).trigger( 'wc_update_cart' );
		} else if ( location === 'checkout' ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Credit card box: the wallet token travels with the checkout form
	 * ------------------------------------------------------------------ */

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

	function submitCheckout( form, gateway, fields ) {
		var formData = new window.FormData( form );
		formData.set( 'payment_method', gateway );
		formData.delete( 'wc-' + gateway + '-new-payment-method' );
		formData.delete( gateway + '_token' );
		formData.delete( gateway + '_expiry' );
		formData.delete( gateway + '_brand_hint' );
		Object.keys( fields ).forEach( function ( name ) {
			formData.set( name, fields[ name ] );
		} );

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
			core.log( 'checkout result', result.result );
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
	function fillOrderPayForm( form, fieldset, gateway, fields ) {
		Object.keys( fields ).forEach( function ( name ) {
			if ( name.indexOf( 'wc-' ) === 0 ) {
				return;
			}
			var input = fieldset.querySelector( 'input[type="hidden"][name="' + name + '"]' );
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

	function startFormFlow( wrap ) {
		var fieldset = closest( wrap, '.paradox-cardpointe-form' );
		var form = closest( wrap, 'form' );
		if ( ! fieldset || ! form || busy ) {
			return;
		}
		var ds = wrap.dataset;
		var gateway = ds.gateway || config.gateway;
		var errors = fieldset.querySelector( '.paradox-cardpointe-errors' );
		var session;

		try {
			session = core.createSession( core.baseRequest( { amount: ds.amount, currency: ds.currency } ) );
		} catch ( e ) {
			core.log( 'could not create the session', e );
			showMessage( errors, i18n.applePayFailed );
			return;
		}

		busy = true;
		showMessage( errors, '' );

		session.onvalidatemerchant = function ( event ) {
			core.validateMerchant( event.validationURL ).then( function ( merchantSession ) {
				session.completeMerchantValidation( merchantSession );
			} ).catch( function ( error ) {
				core.log( 'merchant validation failed', error );
				try {
					session.abort();
				} catch ( e ) {
					// The sheet may already be gone.
				}
				busy = false;
				showMessage( errors, i18n.applePayValidation );
			} );
		};

		session.onpaymentauthorized = function ( event ) {
			var fields = core.walletFields( core.walletFromPayment( event.payment ), ds.amount );

			if ( form.id === 'order_review' ) {
				fillOrderPayForm( form, fieldset, gateway, fields );
				core.complete( session, true );
				busy = false;
				form.submit();
				return;
			}

			submitCheckout( form, gateway, fields ).then( function ( result ) {
				if ( result.success ) {
					core.complete( session, true );
					window.location.href = result.redirect;
					return;
				}
				core.complete( session, false );
				busy = false;
				if ( result.reload ) {
					window.location.reload();
					return;
				}
				showNotices( errors, result.messages );
			}, function ( error ) {
				core.log( 'checkout request failed', error );
				core.complete( session, false );
				busy = false;
				showMessage( errors, i18n.applePayFailed );
			} );
		};

		session.oncancel = function () {
			busy = false;
		};

		session.begin();
	}

	/* ---------------------------------------------------------------------
	 * Express buttons
	 * ------------------------------------------------------------------ */

	/**
	 * What the shopper has chosen on the product page, or null after telling them what is missing.
	 */
	function readProduct( container, messageBox ) {
		var ds = container.dataset;
		var form = document.querySelector( 'form.cart' );
		var id = parseInt( ds.productId, 10 ) || 0;
		var unit = parseFloat( ds.amount ) || 0;
		var quantity = 1;
		var variation = [];

		if ( form ) {
			var qty = form.querySelector( 'input.qty, input[name="quantity"]' );
			if ( qty ) {
				quantity = Math.max( 1, parseInt( qty.value, 10 ) || 1 );
			}
			if ( ds.productType === 'variable' ) {
				var chosen = form.querySelector( 'input[name="variation_id"]' );
				var variationId = chosen ? parseInt( chosen.value, 10 ) || 0 : 0;
				if ( ! variationId ) {
					showMessage( messageBox, i18n.applePayChooseOptions );
					return null;
				}
				id = variationId;
				Array.prototype.forEach.call( form.querySelectorAll( '[name^="attribute_"]' ), function ( field ) {
					if ( ( field.type === 'radio' || field.type === 'checkbox' ) && ! field.checked ) {
						return;
					}
					if ( field.value ) {
						variation.push( { attribute: field.name.replace( /^attribute_/, '' ), value: field.value } );
					}
				} );
				if ( variationPrice > 0 ) {
					unit = variationPrice;
				}
			}
		}

		return {
			item: { id: id, quantity: quantity, variation: variation },
			amount: ( unit * quantity ).toFixed( 2 )
		};
	}

	function startExpress( container ) {
		if ( busy ) {
			return;
		}
		var ds = container.dataset;
		var messageBox = container.querySelector( '.paradox-cardpointe-apple-pay-message' );
		var options = {
			amount: ds.amount,
			currency: ds.currency,
			needsShipping: ds.needsShipping === '1',
			onOpen: function () {
				busy = true;
			},
			onMessage: function ( message ) {
				showMessage( messageBox, message );
			},
			onClose: function () {
				busy = false;
				refresh( ds.location );
			}
		};

		if ( ds.location === 'product' ) {
			var product = readProduct( container, messageBox );
			if ( ! product ) {
				return;
			}
			options.product = product.item;
			options.amount = product.amount;
		}

		if ( ! core.express( options ) ) {
			busy = false;
		}
	}

	/* ---------------------------------------------------------------------
	 * Filling the slots
	 * ------------------------------------------------------------------ */

	function fill() {
		Array.prototype.forEach.call( document.querySelectorAll( '.paradox-cardpointe-apple-pay' ), function ( wrap ) {
			var slot = wrap.querySelector( '.paradox-cardpointe-apple-pay-slot' );
			if ( ! slot || slot.getAttribute( 'data-mounted' ) === '1' ) {
				return;
			}
			core.mountButton( slot, {
				onClick: function () {
					startFormFlow( wrap );
				}
			} );
			wrap.hidden = false;
		} );

		if ( ! core.expressSupported() ) {
			return;
		}
		Array.prototype.forEach.call( document.querySelectorAll( '.paradox-cardpointe-apple-pay-express' ), function ( container ) {
			var slot = container.querySelector( '.paradox-cardpointe-apple-pay-slot' );
			if ( ! slot || slot.getAttribute( 'data-mounted' ) === '1' ) {
				return;
			}
			core.mountButton( slot, {
				onClick: function () {
					startExpress( container );
				}
			} );
			container.hidden = false;
		} );
	}

	var fillTimer = null;
	function scheduleFill() {
		if ( fillTimer ) {
			window.clearTimeout( fillTimer );
		}
		fillTimer = window.setTimeout( function () {
			fillTimer = null;
			fill();
		}, 100 );
	}

	function init() {
		fill();

		// WooCommerce replaces the payment box, the cart totals and (in multi-step checkouts)
		// whole steps without announcing it in any way we could rely on. Filling is idempotent,
		// so simply do it again whenever the page changes.
		if ( window.MutationObserver ) {
			new window.MutationObserver( scheduleFill ).observe( document.body, { childList: true, subtree: true } );
		}

		// The price of a variable product is only known once a variation is chosen.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'found_variation', '.variations_form', function ( event, variation ) {
				variationPrice = variation && variation.display_price ? parseFloat( variation.display_price ) || 0 : 0;
			} ).on( 'reset_data', '.variations_form', function () {
				variationPrice = 0;
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
