/**
 * Order tracker: keeps the stepper current while the order is open.
 *
 * Polls the read-only status endpoint (data-endpoint, authorised by the order
 * key in data-key or, for a logged-in owner, the REST nonce in data-nonce).
 * Starts at data-interval seconds and backs off while nothing changes. Stops
 * at a final state, on a refused request, after four hours, and while the tab
 * is hidden (it polls once as soon as the tab is visible again).
 */
( function () {
	'use strict';

	const MAX_AGE_MS = 4 * 60 * 60 * 1000;
	const root = document.querySelector( '[data-lafka-tracker]' );
	if ( ! root || '1' === root.getAttribute( 'data-final' ) || ! window.fetch ) {
		return;
	}

	const base = Math.max( 15, parseInt( root.getAttribute( 'data-interval' ), 10 ) || 20 ) * 1000;
	const started = Date.now();
	let delay = base;
	let unchanged = 0;
	let timer = null;
	let last = root.getAttribute( 'data-step' ) + '|' + ( root.querySelector( '[data-lafka-tracker-eta]' ) || {} ).textContent;

	function url() {
		const endpoint = new URL( root.getAttribute( 'data-endpoint' ), window.location.href );
		endpoint.searchParams.set( 'key', root.getAttribute( 'data-key' ) || '' );
		return endpoint.toString();
	}

	function render( state ) {
		const order = Array.prototype.map.call( root.querySelectorAll( '[data-step]' ), function ( item ) {
			return item;
		} );
		root.setAttribute( 'data-kind', state.kind );
		root.setAttribute( 'data-step', state.step );
		root.setAttribute( 'data-final', state.final ? '1' : '0' );
		order.forEach( function ( item, position ) {
			const done = 'progress' === state.kind && ( position < state.index || ( 'done' === state.step && position === state.index ) );
			const current = 'progress' === state.kind && ! done && position === state.index;
			item.classList.toggle( 'is-done', done );
			item.classList.toggle( 'is-current', current );
			if ( current ) {
				item.setAttribute( 'aria-current', 'step' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
		root.querySelector( '[data-lafka-tracker-headline]' ).textContent = state.headline;
		const eta = root.querySelector( '[data-lafka-tracker-eta]' );
		eta.textContent = state.eta;
		eta.hidden = '' === state.eta;
		const reorder = root.querySelector( '[data-lafka-tracker-reorder]' );
		if ( reorder ) {
			reorder.hidden = 'done' !== state.step;
		}
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = null;
		if ( document.hidden || Date.now() - started > MAX_AGE_MS ) {
			return;
		}
		timer = window.setTimeout( poll, delay );
	}

	function poll() {
		timer = null;
		window.fetch( url(), {
			credentials: 'same-origin',
			headers: root.getAttribute( 'data-nonce' ) ? { 'X-WP-Nonce': root.getAttribute( 'data-nonce' ) } : {},
		} )
			.then( function ( response ) {
				if ( 429 === response.status || response.status >= 500 ) {
					// Busy: wait longer and try again.
					delay = Math.min( delay * 2, 5 * 60 * 1000 );
					return null;
				}
				return response.ok ? response.json() : false;
			} )
			.then( function ( state ) {
				if ( false === state ) {
					return; // Refused: this page cannot follow the order, so stop.
				}
				if ( state ) {
					const now = state.step + '|' + state.eta + '|' + state.kind;
					unchanged = now === last ? unchanged + 1 : 0;
					last = now;
					render( state );
					if ( state.final ) {
						return;
					}
					delay = 0 === unchanged ? base : Math.min( base * ( unchanged < 3 ? 1 : unchanged < 10 ? 1.5 : 3 ), 90000 );
				}
				schedule();
			} )
			.catch( function () {
				delay = Math.min( delay * 2, 5 * 60 * 1000 );
				schedule();
			} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			window.clearTimeout( timer );
			timer = null;
		} else if ( null === timer ) {
			delay = base;
			poll();
		}
	} );

	schedule();
}() );
