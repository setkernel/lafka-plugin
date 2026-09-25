/**
 * Cart-drawer quantity stepper (GX4).
 *
 * Each drawer row rendered with the stepper (lafka_cart_drawer_render_stepper_item)
 * carries [data-lafka-qty] with its cart key, product name and nonce. A tap
 * on − / + posts the new quantity to wc-ajax=lafka_cart_set_qty, which answers
 * with WooCommerce's refreshed fragments; they are swapped in, WooCommerce's
 * fragment cache is updated, `wc_fragments_refreshed` fires (the theme's
 * cart-count sync and free-delivery tracker listen), focus returns to the
 * stepper and a polite live region says "{name}: {qty}". An expired nonce
 * (a page served from cache) refreshes the fragments once and retries.
 * Rapid taps are queued, not dropped: each moves the shown quantity at once
 * and the newest quantity is sent when the request in flight lands.
 *
 * Dispatches `lafka:cart-qty` on document ({ detail: { key, quantity } }).
 * No dependencies; jQuery is used only to trigger WooCommerce's event.
 */
( function () {
	'use strict';

	var config = window.lafkaCartQty;
	if ( ! config || ! config.url || typeof window.fetch !== 'function' ) {
		return;
	}
	var i18n = config.i18n || {};
	var live = null;

	function format( template, name, qty ) {
		return String( template || '' ).replace( '%1$s', name ).replace( '%2$s', String( qty ) ).replace( '%s', name );
	}

	function announce( text ) {
		if ( ! text ) {
			return;
		}
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.className = 'screen-reader-text';
			live.setAttribute( 'role', 'status' );
			live.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( live );
		}
		live.textContent = text;
	}

	function encode( params ) {
		return Object.keys( params ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} ).join( '&' );
	}

	function request( url, params ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: encode( params ),
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} );
		} );
	}

	function applyFragments( data ) {
		var fragments = data && data.fragments;
		if ( ! fragments ) {
			return false;
		}
		Object.keys( fragments ).forEach( function ( selector ) {
			Array.prototype.forEach.call( document.querySelectorAll( selector ), function ( node ) {
				// Same-origin fragment HTML rendered by WooCommerce/the plugin, as
				// WooCommerce's own add-to-cart.js swaps it; <template> runs no script.
				var template = document.createElement( 'template' );
				template.innerHTML = fragments[ selector ];
				var fresh = template.content.firstElementChild;
				if ( fresh ) {
					node.replaceWith( fresh );
				}
			} );
		} );
		try {
			var params = window.wc_cart_fragments_params;
			if ( params && window.sessionStorage ) {
				window.sessionStorage.setItem( params.fragment_name, JSON.stringify( fragments ) );
				if ( params.cart_hash_key && data.cart_hash ) {
					window.sessionStorage.setItem( params.cart_hash_key, data.cart_hash );
				}
			}
		} catch {
			// Storage unavailable (private mode): WooCommerce re-fetches on the next page.
		}
		if ( typeof window.jQuery === 'function' ) {
			window.jQuery( document.body ).trigger( 'wc_fragments_refreshed' );
		}
		return true;
	}

	function stepperFor( key ) {
		return document.querySelector( '[data-lafka-qty][data-cart-key="' + String( key ).replace( /["\\]/g, '\\$&' ) + '"]' );
	}

	function enabledStep( stepper, direction ) {
		var buttons = stepper ? stepper.querySelectorAll( '[data-lafka-qty-step]' ) : [];
		var fallback = null;
		for ( var i = 0; i < buttons.length; i++ ) {
			if ( buttons[ i ].hasAttribute( 'disabled' ) ) {
				continue;
			}
			if ( buttons[ i ].getAttribute( 'data-lafka-qty-step' ) === direction ) {
				return buttons[ i ];
			}
			fallback = fallback || buttons[ i ];
		}
		return fallback;
	}

	function restoreFocus( key, delta ) {
		var target = enabledStep( stepperFor( key ), delta > 0 ? '1' : '-1' );
		if ( ! target ) {
			target = document.querySelector( '.lafka-cart-drawer__items' );
			if ( target && ! target.hasAttribute( 'tabindex' ) ) {
				target.setAttribute( 'tabindex', '-1' );
			}
		}
		if ( target && typeof target.focus === 'function' ) {
			target.focus();
		}
	}

	function dispatch( key, quantity ) {
		if ( typeof window.CustomEvent === 'function' ) {
			document.dispatchEvent( new window.CustomEvent( 'lafka:cart-qty', { detail: { key: key, quantity: quantity } } ) );
		}
	}

	function setBusy( key, on ) {
		var stepper = stepperFor( key );
		if ( ! stepper ) {
			return;
		}
		if ( on ) {
			stepper.setAttribute( 'aria-busy', 'true' );
		} else {
			stepper.removeAttribute( 'aria-busy' );
		}
	}

	// ── Rapid taps (O-17) ───────────────────────────────────────────────────
	// Every tap counts: it moves the row's TARGET quantity and paints it at
	// once; one request runs at a time, and when it lands, any row whose
	// target moved on is sent again with the newest target. Nothing is
	// dropped, the server always ends on the last quantity asked for, and a
	// refused change repaints the quantity the cart really holds.
	var targets = {}; // cart key => wanted quantity
	var origins = {}; // cart key => quantity before the first queued tap
	var deltas = {}; // cart key => direction of the last tap (focus)
	var inflight = false;

	function shownQty( stepper ) {
		var output = stepper.querySelector( '.lafka-cart-drawer__qty' );
		return parseInt( output ? output.textContent : '', 10 ) || 0;
	}

	function paint( stepper, qty ) {
		var output = stepper.querySelector( '.lafka-cart-drawer__qty' );
		var max = parseInt( stepper.getAttribute( 'data-max' ) || '', 10 ) || 0;
		if ( output ) {
			output.textContent = String( qty );
		}
		Array.prototype.forEach.call( stepper.querySelectorAll( '[data-lafka-qty-step]' ), function ( button ) {
			var less = '-1' === button.getAttribute( 'data-lafka-qty-step' );
			var off = less ? qty <= 1 : max > 0 && qty >= max;
			if ( off ) {
				button.setAttribute( 'disabled', '' );
			} else {
				button.removeAttribute( 'disabled' );
			}
		} );
	}

	function repaintPending() {
		Object.keys( targets ).forEach( function ( key ) {
			var stepper = stepperFor( key );
			if ( stepper ) {
				paint( stepper, targets[ key ] );
			}
		} );
	}

	function settle( key, quantity ) {
		delete targets[ key ];
		delete origins[ key ];
		restoreFocus( key, deltas[ key ] || 1 );
		var stepper = stepperFor( key );
		var name = stepper ? stepper.getAttribute( 'data-name' ) || '' : '';
		announce( quantity > 0 ? format( i18n.quantity, name, quantity ) : format( i18n.removed, name ) );
		dispatch( key, quantity );
	}

	function fail( key, message ) {
		var stepper = stepperFor( key );
		if ( stepper && Object.prototype.hasOwnProperty.call( origins, key ) ) {
			paint( stepper, origins[ key ] );
		}
		delete targets[ key ];
		delete origins[ key ];
		announce( message || i18n.failed );
	}

	function send( key, retried ) {
		var stepper = stepperFor( key );
		if ( ! stepper || ! Object.prototype.hasOwnProperty.call( targets, key ) ) {
			delete targets[ key ];
			return Promise.resolve( null );
		}
		var quantity = targets[ key ];

		inflight = true;
		setBusy( key, true );
		return request( config.url, {
			cart_item_key: key,
			quantity: quantity,
			nonce: stepper.getAttribute( 'data-nonce' ) || '',
		} ).then( function ( data ) {
			if ( data && data.fragments ) {
				applyFragments( data );
				if ( targets[ key ] === quantity ) {
					settle( key, quantity );
				} else {
					restoreFocus( key, deltas[ key ] || 1 );
				}
				repaintPending();
				return null;
			}
			var error = ( data && data.data ) || {};
			if ( 'invalid_nonce' === error.code && ! retried && config.refreshUrl ) {
				return request( config.refreshUrl, {} ).then( function ( fresh ) {
					applyFragments( fresh );
					repaintPending();
					return send( key, true );
				} );
			}
			fail( key, error.message );
			return null;
		} ).catch( function () {
			fail( key, i18n.failed );
		} ).then( function () {
			inflight = false;
			setBusy( key, false );
			pump();
		} );
	}

	function pump() {
		if ( inflight ) {
			return;
		}
		var next = Object.keys( targets )[ 0 ];
		if ( undefined !== next ) {
			send( next, false );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-lafka-qty-step]' ) : null;
		if ( ! button || button.hasAttribute( 'disabled' ) ) {
			return;
		}
		var stepper = button.closest( '[data-lafka-qty]' );
		if ( ! stepper ) {
			return;
		}
		event.preventDefault();
		var key = stepper.getAttribute( 'data-cart-key' ) || '';
		var delta = parseInt( button.getAttribute( 'data-lafka-qty-step' ), 10 ) || 0;
		var max = parseInt( stepper.getAttribute( 'data-max' ) || '', 10 ) || 0;
		var base = Object.prototype.hasOwnProperty.call( targets, key ) ? targets[ key ] : shownQty( stepper );
		var wanted = Math.max( 1, base + delta );
		if ( max > 0 ) {
			wanted = Math.min( max, wanted );
		}
		if ( wanted === base ) {
			return;
		}
		if ( ! Object.prototype.hasOwnProperty.call( origins, key ) ) {
			origins[ key ] = shownQty( stepper );
		}
		targets[ key ] = wanted;
		deltas[ key ] = delta;
		paint( stepper, wanted );
		pump();
	} );
}() );
