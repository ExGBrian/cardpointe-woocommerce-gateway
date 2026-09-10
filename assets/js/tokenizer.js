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
	 * @param {Object}      options   { src, origin, height, title, onToken, onError, onCleared, onTyping, onReady }
	 * @return {Object} Instance with reload(), destroy(), waitForToken(), getToken(), hasToken().
	 */
	function mount( container, options ) {
		options = options || {};

		var state = { token: '', expiry: '', ready: false, typing: false, destroyed: false };
		var iframe = document.createElement( 'iframe' );
		var origin = options.origin || '';
		var listeners = [];

		iframe.setAttribute( 'title', options.title || 'Secure payment form' );
		iframe.setAttribute( 'frameborder', '0' );
		iframe.setAttribute( 'scrolling', 'no' );
		iframe.setAttribute( 'allowtransparency', 'true' );
		iframe.className = 'paradox-cardpointe-iframe';
		iframe.style.width = '100%';
		iframe.style.height = ( parseInt( options.height, 10 ) || 250 ) + 'px';
		iframe.style.border = '0';
		iframe.style.visibility = 'hidden';
		iframe.src = options.src;

		// Remove any previous iframe in this container.
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
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

		function reveal() {
			state.ready = true;
			iframe.style.visibility = 'visible';
			container.setAttribute( 'data-ready', '1' );
			call( 'onReady' );
			listeners.forEach( function ( fn ) { fn(); } );
			listeners = [];
		}

		// Fallback: reveal even if the cssLoaded event never arrives.
		var revealTimer = window.setTimeout( reveal, 4000 );
		iframe.addEventListener( 'load', function () {
			window.setTimeout( function () { if ( ! state.ready ) { reveal(); } }, 600 );
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

			if ( typeof data.cssLoaded !== 'undefined' ) {
				window.clearTimeout( revealTimer );
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

		return {
			iframe: iframe,
			getToken: function () { return state.token; },
			getExpiry: function () { return state.expiry; },
			hasToken: function () { return state.token !== ''; },
			isTyping: function () { return state.typing; },
			clear: function () { state.token = ''; state.expiry = ''; },
			reload: function () {
				state.token = '';
				state.expiry = '';
				state.ready = false;
				iframe.style.visibility = 'hidden';
				container.removeAttribute( 'data-ready' );
				revealTimer = window.setTimeout( reveal, 4000 );
				iframe.src = options.src;
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
				window.clearTimeout( revealTimer );
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
