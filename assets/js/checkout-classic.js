/**
 * Classic (shortcode) checkout, order-pay, add-payment-method and
 * Subscriptions change-payment-method forms.
 *
 * Depends on jQuery and ParadoxCardPointeTokenizer.
 */
( function ( $, window, document ) {
	'use strict';

	var params = window.paradox_cardpointe_params || {};
	var i18n = params.i18n || {};
	var instances = {};

	function sprintf( text, value ) {
		return String( text || '' ).replace( '%s', value );
	}

	// How often to look for a payment box that has become visible without any event
	// telling us so (multi-step checkouts such as CheckoutWC reveal their payment step
	// client-side and fire nothing WooCommerce-shaped).
	var WATCH_INTERVAL_MS = 500;

	// A visible frame still not ready after this long is remounted once automatically,
	// then offered to the shopper as a manual reload.
	var STUCK_AFTER_MS = 3500;

	/**
	 * Whether an element is actually laid out, regardless of which ancestor hides it.
	 */
	function isVisible( el ) {
		return !! ( el && ( el.offsetWidth || el.offsetHeight || el.getClientRects().length ) );
	}

	/**
	 * Returns the form element that contains a payment box.
	 */
	function getForm( $el ) {
		return $el.closest( 'form.checkout, form#order_review, form#add_payment_method' );
	}

	function showError( $fieldset, message ) {
		var $errors = $fieldset.find( '.paradox-cardpointe-errors' );
		if ( ! message ) {
			$errors.text( '' ).prop( 'hidden', true );
			return;
		}
		$errors.text( message ).prop( 'hidden', false );
	}

	function setStatus( $fieldset, brand ) {
		var $status = $fieldset.find( '.paradox-cardpointe-status' );
		$status.empty();
		if ( ! brand ) {
			return;
		}
		var label = ( i18n.brands && i18n.brands[ brand ] ) ? i18n.brands[ brand ] : brand;
		if ( params.iconUrls && params.iconUrls[ brand ] ) {
			$status.append( $( '<img>', { src: params.iconUrls[ brand ], alt: label, width: 32, height: 20, 'class': 'paradox-cardpointe-card-icon' } ) );
		}
		$status.append( document.createTextNode( ' ' + sprintf( i18n.detected, label ) ) );
	}

	/**
	 * Offers a manual reload once the iframe has failed to load in time.
	 */
	function showRetry( $fieldset, gateway ) {
		var $loading = $fieldset.find( '.paradox-cardpointe-loading' );
		if ( ! $loading.length || $loading.find( '.paradox-cardpointe-retry' ).length ) {
			return;
		}
		$loading.append(
			$( '<button>', {
				type: 'button',
				'class': 'paradox-cardpointe-retry',
				text: i18n.retryLoad
			} ).on( 'click', function () {
				$( this ).remove();
				showError( $fieldset, '' );
				remount( $fieldset );
			} )
		);
	}

	/**
	 * Throws away any existing instance for a fieldset and mounts a fresh one.
	 */
	function remount( $fieldset ) {
		var gateway = $fieldset.data( 'gateway' );
		if ( instances[ gateway ] ) {
			instances[ gateway ].destroy();
			delete instances[ gateway ];
		}
		$fieldset.find( '.paradox-cardpointe-frame' ).removeData( 'visibleSince' );
		mountFieldset( $fieldset.get( 0 ) );
	}

	/**
	 * Mounts the tokenizer for a fieldset when not already mounted.
	 */
	function mountFieldset( fieldset ) {
		var $fieldset = $( fieldset );
		var gateway = $fieldset.data( 'gateway' );
		var type = $fieldset.data( 'type' );
		var $frame = $fieldset.find( '.paradox-cardpointe-frame' );
		if ( ! gateway || ! $frame.length ) {
			return;
		}
		var container = $frame.get( 0 );
		var existing = instances[ gateway ];

		// WooCommerce replaces the payment box on updated_checkout. Re-mount only when the
		// iframe is really gone or a different container has appeared: tearing a frame down
		// while it is still loading and starting a new one is what makes the first paint
		// look hung, because each rebuild restarts the round trip to CardPointe.
		if ( existing && existing.container === container && existing.iframe && document.body.contains( existing.iframe ) ) {
			return;
		}
		if ( existing ) {
			existing.destroy();
		}

		$fieldset.find( '.paradox-cardpointe-retry' ).remove();
		$fieldset.find( '.paradox-cardpointe-loading' ).show();

		instances[ gateway ] = window.ParadoxCardPointeTokenizer.mount( container, {
			src: $frame.data( 'src' ),
			origin: $frame.data( 'origin' ),
			height: $frame.data( 'height' ),
			title: $frame.data( 'title' ),
			onReady: function () {
				$fieldset.find( '.paradox-cardpointe-retry' ).remove();
				$fieldset.find( '.paradox-cardpointe-loading' ).hide();
				$frame.removeAttr( 'data-attempts' ).removeData( 'visibleSince' );
			},
			onTimeout: function () {
				showRetry( $fieldset, gateway );
			},
			onToken: function ( token, expiry, brand ) {
				$fieldset.find( '.paradox-cardpointe-token' ).val( token );
				$fieldset.find( '.paradox-cardpointe-expiry' ).val( expiry );
				$fieldset.find( '.paradox-cardpointe-brand' ).val( brand );
				showError( $fieldset, '' );
				if ( type === 'card' ) {
					setStatus( $fieldset, brand );
					if ( brand && params.allowedCardTypes && $.inArray( brand, params.allowedCardTypes ) === -1 ) {
						var label = ( i18n.brands && i18n.brands[ brand ] ) ? i18n.brands[ brand ] : brand;
						showError( $fieldset, sprintf( i18n.cardNotAccepted, label ) );
					}
				}
			},
			onCleared: function () {
				$fieldset.find( '.paradox-cardpointe-token, .paradox-cardpointe-expiry, .paradox-cardpointe-brand' ).val( '' );
				setStatus( $fieldset, '' );
			},
			onError: function ( code, message, isValidation ) {
				$fieldset.find( '.paradox-cardpointe-token, .paradox-cardpointe-expiry, .paradox-cardpointe-brand' ).val( '' );
				setStatus( $fieldset, '' );
				var text = ( i18n.errorCodes && i18n.errorCodes[ code ] ) ? i18n.errorCodes[ code ] : ( message || ( type === 'card' ? i18n.invalidCard : i18n.invalidBank ) );
				// Required-field codes fire while the shopper is still typing; only surface real validation errors.
				if ( isValidation && code !== '1004' && code !== '1005' && code !== '1006' ) {
					showError( $fieldset, text );
				} else if ( ! isValidation ) {
					showError( $fieldset, text );
				}
			}
		} );
	}

	var mountTimer = null;

	/**
	 * Coalesces mount requests. updated_checkout fires on the initial review refresh and
	 * again on every address change, often several times in a burst; without this each one
	 * would start another iframe load.
	 */
	function scheduleMount() {
		if ( mountTimer ) {
			window.clearTimeout( mountTimer );
		}
		mountTimer = window.setTimeout( function () {
			mountTimer = null;
			mountVisible();
		}, 80 );
	}

	function mountVisible() {
		$( '.paradox-cardpointe-form' ).each( function () {
			var $fieldset = $( this );
			// Only mount for the selected gateway (or when there is no gateway selector, e.g. add payment method).
			var gateway = $fieldset.data( 'gateway' );
			var $radio = $( '#payment_method_' + gateway );
			if ( $radio.length && ! $radio.is( ':checked' ) ) {
				return;
			}
			// Mounting into a container with no layout wastes the load and, under display:none,
			// some browsers never start it. Wait for the frame itself to be laid out, whichever
			// ancestor is doing the hiding; the watchers below bring us back when it appears.
			if ( ! isVisible( $fieldset.find( '.paradox-cardpointe-frame' ).get( 0 ) ) ) {
				return;
			}
			mountFieldset( this );
		} );
	}

	/**
	 * Recovers frames that are visible but have not become ready: once automatically,
	 * then by offering the shopper a reload control.
	 */
	function stuckCheck() {
		$( '.paradox-cardpointe-form' ).each( function () {
			var $fieldset = $( this );
			var $frame = $fieldset.find( '.paradox-cardpointe-frame' );
			if ( ! $frame.length || $frame.attr( 'data-ready' ) === '1' ) {
				return;
			}
			if ( ! isVisible( $frame.get( 0 ) ) ) {
				$frame.removeData( 'visibleSince' );
				return;
			}
			var since = $frame.data( 'visibleSince' );
			if ( ! since ) {
				$frame.data( 'visibleSince', Date.now() );
				return;
			}
			if ( Date.now() - since < STUCK_AFTER_MS ) {
				return;
			}
			var attempts = parseInt( $frame.attr( 'data-attempts' ) || '0', 10 );
			if ( attempts < 1 ) {
				$frame.attr( 'data-attempts', String( attempts + 1 ) );
				remount( $fieldset );
				return;
			}
			showRetry( $fieldset, $fieldset.data( 'gateway' ) );
		} );
	}

	/**
	 * Watches for the payment box appearing through any route WooCommerce does not announce.
	 */
	function startWatchers() {
		// Multi-step checkouts route on the URL fragment (CheckoutWC: #cfw-payment-method).
		$( window ).on( 'hashchange', scheduleMount );

		// A step being revealed is a style, class or hidden-attribute change somewhere above
		// our fieldset, or the step's markup being inserted. Our own mutations re-enter here
		// too, but the debounce absorbs them and an already-mounted frame is a no-op.
		if ( window.MutationObserver ) {
			new window.MutationObserver( scheduleMount ).observe( document.body, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'style', 'class', 'hidden', 'aria-hidden' ]
			} );
		}

		// Backstop for anything that escapes the observer, and the driver for stuckCheck.
		window.setInterval( function () {
			mountVisible();
			stuckCheck();
		}, WATCH_INTERVAL_MS );
	}

	/**
	 * Whether a saved token (not "new") is selected for a gateway.
	 */
	function usingSavedToken( gateway ) {
		var $tokens = $( 'input[name="wc-' + gateway + '-payment-token"]' );
		if ( ! $tokens.length ) {
			return false;
		}
		var value = $tokens.filter( ':checked' ).val();
		return value && value !== 'new';
	}

	/**
	 * Validates before submit. Returns true to continue, false to block.
	 */
	function validate( gateway ) {
		var $fieldset = $( '.paradox-cardpointe-form[data-gateway="' + gateway + '"]' );
		if ( ! $fieldset.length || usingSavedToken( gateway ) ) {
			return true;
		}
		var type = $fieldset.data( 'type' );
		var instance = instances[ gateway ];
		var token = $fieldset.find( '.paradox-cardpointe-token' ).val();

		if ( type === 'echeck' ) {
			if ( ! $fieldset.find( 'input[name="' + gateway + '_accttype"]:checked' ).length ) {
				showError( $fieldset, i18n.chooseAccttype );
				return false;
			}
			if ( ! $fieldset.find( 'input[name="' + gateway + '_consent"]' ).is( ':checked' ) ) {
				showError( $fieldset, i18n.consentRequired );
				return false;
			}
		}

		if ( token ) {
			if ( type === 'card' ) {
				var brand = $fieldset.find( '.paradox-cardpointe-brand' ).val();
				if ( brand && params.allowedCardTypes && $.inArray( brand, params.allowedCardTypes ) === -1 ) {
					var label = ( i18n.brands && i18n.brands[ brand ] ) ? i18n.brands[ brand ] : brand;
					showError( $fieldset, sprintf( i18n.cardNotAccepted, label ) );
					return false;
				}
			}
			return true;
		}

		// No token yet: the tokenizer may still be finishing after blur. Wait briefly, then resubmit.
		if ( instance ) {
			var $form = getForm( $fieldset );
			$form.addClass( 'processing' );
			if ( $.fn.block ) {
				$form.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
			}
			instance.waitForToken( 3000 ).then( function () {
				$form.removeClass( 'processing' );
				if ( $.fn.unblock ) {
					$form.unblock();
				}
				$form.trigger( 'submit' );
			}, function () {
				$form.removeClass( 'processing' );
				if ( $.fn.unblock ) {
					$form.unblock();
				}
				showError( $fieldset, type === 'card' ? i18n.enterCard : i18n.enterBank );
				$( 'html, body' ).animate( { scrollTop: Math.max( 0, $fieldset.offset().top - 100 ) }, 300 );
			} );
		} else {
			showError( $fieldset, type === 'card' ? i18n.enterCard : i18n.enterBank );
		}
		return false;
	}

	function reloadAll() {
		$.each( instances, function ( gateway, instance ) {
			var $fieldset = $( '.paradox-cardpointe-form[data-gateway="' + gateway + '"]' );
			$fieldset.find( '.paradox-cardpointe-token, .paradox-cardpointe-expiry, .paradox-cardpointe-brand' ).val( '' );
			setStatus( $fieldset, '' );
			if ( instance.iframe && document.body.contains( instance.iframe ) ) {
				instance.reload();
			}
		} );
	}

	$( function () {
		var gateways = params.gateways || [];

		// Checkout form submission per gateway.
		$.each( gateways, function ( _, gateway ) {
			$( 'form.checkout' ).on( 'checkout_place_order_' + gateway, function () {
				return validate( gateway );
			} );
		} );

		// Order-pay, add payment method and change payment method forms.
		$( 'form#order_review, form#add_payment_method' ).on( 'submit', function () {
			var $form = $( this );
			var chosen = $form.find( 'input[name="payment_method"]:checked' ).val();
			var ok = true;
			$.each( gateways, function ( _, gateway ) {
				if ( chosen === gateway || ( ! chosen && $form.find( '.paradox-cardpointe-form[data-gateway="' + gateway + '"]' ).length ) ) {
					ok = validate( gateway );
				}
			} );
			return ok;
		} );

		$( document.body ).on( 'updated_checkout payment_method_selected', scheduleMount );
		$( document ).on( 'change', 'input[name="payment_method"]', scheduleMount );
		$( document ).on( 'change', 'input.woocommerce-SavedPaymentMethods-tokenInput', scheduleMount );

		// A failed attempt consumes the CVV attached to the token; start over with a fresh iframe.
		$( document.body ).on( 'checkout_error', reloadAll );

		mountVisible();
		startWatchers();
	} );
} )( jQuery, window, document );
