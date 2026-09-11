/**
 * CardPointe Hosted iFrame Tokenizer bridge.
 *
 * Mounts the tokenizer iframe into a container and turns its postMessage
 * events into callbacks. Messages are accepted only when both the origin
 * and the source window match the iframe we created.
 *
 * Global: window.ParadoxCardPointeTokenizer
 */
( function ( window, document ) {
	'use strict';

	var VALIDATION_CODES = { '1001': 1, '1002': 1, '1003': 1, '1004': 1, '1005': 1, '1006': 1 };

	// Reveal the frame even if cssLoaded never arrives, so it is never left invisible.
	var REVEAL_TIMEOUT = 4000;

	// No load event by this point means the navigation never happened rather than
	// being merely slow. Re-point src once and tell the host UI, which can offer a
	// manual reload.
	var LOAD_TIMEOUT = 8000;

	/**
	 * Card brand from a CardSecure token (digits two and three mirror the PAN).
	 *
	 * @param {string} token Token.
	 * @return {string} Brand slug or empty string.
	 */
	function brandFromToken( token ) {
		if ( ! token || token.charAt( 0 ) !== '9' || token.length < 3 ) {
			return '';
		}
		var two = parseInt( token.substr( 1, 2 ), 10 );
		var one = parseInt( token.charAt( 1 ), 10 );
		if ( one === 4 ) {
			return 'visa';
		}
		if ( ( two >= 51 && two <= 55 ) || ( two >= 22 && two <= 27 ) ) {
			return 'mastercard';
		}
		if ( two === 34 || two === 37 ) {
			return 'amex';
		}
		if ( two === 60 || two === 64 || two === 65 ) {
			return 'discover';
		}
		if ( two === 35 ) {
			return 'jcb';
		}
		if ( two === 30 || two === 36 || two === 38 || two === 39 ) {
			return 'diners';
		}
		return '';
	}

	/**
	 * Creates a tokenizer instance.
	 *
	 * @param {HTMLElement} container Element that receives the iframe.
	 * @param {Object}      options   { src, origin, height, title, onToken, onError, onCleared, onTyping, onReady, onTimeout }
	 * @return {Object} Instance with reload(), destroy(), waitForToken(), getToken(), hasToken().
	 */
	function mount( container, options ) {
		options = options || {};

		var state = {
			token: '',
			expiry: '',
			ready: false,
			typing: false,
			destroyed: false,
			loaded: false,
			retried: false
		};
		var iframe = document.createElement( 'iframe' );
		var origin = options.origin || '';
		var listeners = [];
		var revealTimer = null;
		var loadTimer = null;

		iframe.setAttribute( 'title', options.title || 'Secure payment form' );
		iframe.setAttribute( 'frameborder', '0' );
		iframe.setAttribute( 'scrolling', 'no' );
		iframe.setAttribute( 'allowtransparency', 'true' );
		// Optimisation plugins rewrite every iframe on the page to loading="lazy".
		// Inside a payment box that starts hidden that can defer the load indefinitely,
		// so pin the behaviour this frame needs.
		iframe.setAttribute( 'loading', 'eager' );
		iframe.setAttribute( 'fetchpriority', 'high' );
		iframe.className = 'paradox-cardpointe-iframe';
		iframe.style.width = '100%';
		iframe.style.height = ( parseInt( options.height, 10 ) || 250 ) + 'px';
		iframe.style.border = '0';
		iframe.style.visibility = 'hidden';

		// Remove any previous iframe in this container.
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}

		// Attach before assigning src. A detached iframe does not begin loading, and
		// assigning src first can leave the frame sitting on about:blank once it is
		// attached, which is indistinguishable from a hung load.
		container.appendChild( iframe );

		function call( name ) {
			if ( typeof options[ name ] === 'function' ) {
				try {
					options[ name ].apply( null, Array.prototype.slice.call( arguments, 1 ) );
				} catch ( e ) {
					if ( window.console && window.console.error ) {
						window.console.error( e );
					}
				}
			}
		}

		function clearRevealTimer() {
			if ( revealTimer ) {
				window.clearTimeout( revealTimer );
				revealTimer = null;
			}
		}

		function clearLoadTimer() {
			if ( loadTimer ) {
				window.clearTimeout( loadTimer );
				loadTimer = null;
			}
		}

		function reveal() {
			if ( state.ready || state.destroyed ) {
				return;
			}
			// Only the reveal timer is cleared here. The load watchdog has to survive so
			// that a frame which is revealed but still blank is retried below.
			clearRevealTimer();
			state.ready = true;
			iframe.style.visibility = 'visible';
			container.setAttribute( 'data-ready', '1' );
			call( 'onReady' );
			listeners.forEach( function ( fn ) { fn(); } );
			listeners = [];
		}

		function onLoadTimeout() {
			loadTimer = null;
			if ( state.destroyed || state.loaded ) {
				return;
			}
			call( 'onTimeout' );
			if ( ! state.retried ) {
				state.retried = true;
				start();
			}
		}

		function start() {
			state.loaded = false;
			clearRevealTimer();
			clearLoadTimer();
			revealTimer = window.setTimeout( reveal, REVEAL_TIMEOUT );
			loadTimer = window.setTimeout( onLoadTimeout, LOAD_TIMEOUT );
			iframe.src = options.src;
		}

		iframe.addEventListener( 'load', function () {
			state.loaded = true;
			clearLoadTimer();
			// cssLoaded normally beats this; the short delay gives it a chance to arrive
			// first so the form is not shown mid-style.
			window.setTimeout( reveal, 600 );
		} );

		function onMessage( event ) {
			if ( state.destroyed ) {
				return;
			}
			if ( origin && event.origin !== origin ) {
				return;
			}
			if ( event.source !== iframe.contentWindow ) {
				return;
			}
			if ( typeof event.data !== 'string' ) {
				return;
			}
			var data;
			try {
				data = JSON.parse( event.data );
			} catch ( e ) {
				return;
			}
			if ( ! data || typeof data !== 'object' ) {
				return;
			}

			// Any message at all proves the document is live, whatever the load event did.
			state.loaded = true;
			clearLoadTimer();

			if ( typeof data.cssLoaded !== 'undefined' ) {
				reveal();
				return;
			}

			if ( typeof data.cardTyping !== 'undefined' ) {
				state.typing = !! data.cardTyping;
				call( 'onTyping', state.typing );
				return;
			}

			var token = typeof data.message !== 'undefined' ? String( data.message || '' ) : String( data.token || '' );
			var errorCode = typeof data.errorCode !== 'undefined' ? String( data.errorCode ) : '';
			var errorMessage = data.errorMessage || data.validationError || '';

			if ( data.validationError || ( errorCode && errorCode !== '0' && ! token ) ) {
				state.token = '';
				state.expiry = '';
				call( 'onError', errorCode, String( errorMessage ), !! VALIDATION_CODES[ errorCode ] );
				return;
			}

			if ( ! token ) {
				state.token = '';
				state.expiry = '';
				call( 'onCleared' );
				return;
			}

			state.token = token.replace( /\D/g, '' );
			state.expiry = String( data.expiry || '' ).replace( /\D/g, '' );
			call( 'onToken', state.token, state.expiry, brandFromToken( state.token ), data );
		}

		window.addEventListener( 'message', onMessage, false );

		start();

		return {
			iframe: iframe,
			container: container,
			getToken: function () { return state.token; },
			getExpiry: function () { return state.expiry; },
			hasToken: function () { return state.token !== ''; },
			isTyping: function () { return state.typing; },
			isReady: function () { return state.ready; },
			clear: function () { state.token = ''; state.expiry = ''; },
			reload: function () {
				state.token = '';
				state.expiry = '';
				state.ready = false;
				state.retried = false;
				iframe.style.visibility = 'hidden';
				container.removeAttribute( 'data-ready' );
				start();
			},
			/**
			 * Resolves with the token once it arrives, or rejects after ms.
			 *
			 * @param {number} ms Milliseconds to wait.
			 * @return {Promise}
			 */
			waitForToken: function ( ms ) {
				var self = this;
				return new Promise( function ( resolve, reject ) {
					if ( self.hasToken() ) {
						resolve( state.token );
						return;
					}
					var started = Date.now();
					var timer = window.setInterval( function () {
						if ( self.hasToken() ) {
							window.clearInterval( timer );
							resolve( state.token );
						} else if ( Date.now() - started > ms ) {
							window.clearInterval( timer );
							reject( new Error( 'timeout' ) );
						}
					}, 100 );
				} );
			},
			destroy: function () {
				state.destroyed = true;
				window.removeEventListener( 'message', onMessage, false );
				clearRevealTimer();
				clearLoadTimer();
				if ( iframe.parentNode ) {
					iframe.parentNode.removeChild( iframe );
				}
			}
		};
	}

	window.ParadoxCardPointeTokenizer = {
		mount: mount,
		brandFromToken: brandFromToken
	};
} )( window, document );
