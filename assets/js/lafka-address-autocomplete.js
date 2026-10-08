/**
 * Lafka address autocomplete: the browser half of the "lafka" provider in
 * WooCommerce's address-autocomplete framework (classic and block checkout).
 *
 * WooCommerce draws the suggestion list and fills the address fields; this
 * script only answers its three questions:
 *   canSearch( country )          the shop sells to that country
 *   search( text, country, type ) suggestions from the Lafka REST route,
 *                                 after a pause in typing, from 4 characters
 *   select( id )                  the chosen address (WooCommerce's field
 *                                 names) and, on the side, its point
 *
 * The point becomes the delivery pin so the delivery fee uses the chosen
 * address (the server still checks it against its own geocode):
 *   classic  the hidden `lafka_picked_delivery_geocoded` field of the form;
 *   block    a Store API update (wc.blocksCheckout.extensionCartUpdate).
 * Only the address that is delivered to gets a pin (not a separate billing one).
 *
 * @since 10.4.0
 */
( function ( window, document ) {
	'use strict';

	const config = window.lafkaAddressAutocomplete || {};
	const common = window.wc && window.wc.addressAutocomplete;
	if ( ! common || ! common.registerAddressAutocompleteProvider || ! config.suggestUrl ) {
		return;
	}

	const FIELD_PIN = 'lafka_picked_delivery_geocoded';
	const FIELD_PLACED = 'lafka_is_location_clicked';
	const state = { sequence: 0, controller: null, session: newSession(), type: 'shipping', cache: new Map() };

	/** @return {string} A fresh autocomplete session id (Google bills a session as one). */
	function newSession() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'lafka-' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 );
	}

	/**
	 * A REST URL with query parameters (works with plain permalinks too).
	 *
	 * @param {string} base   Route URL.
	 * @param {Object} params Query parameters.
	 * @return {string} URL.
	 */
	function url( base, params ) {
		const target = new URL( base, window.location.href );
		Object.keys( params ).forEach( ( key ) => target.searchParams.set( key, params[ key ] ) );
		return target.toString();
	}

	/**
	 * Which address the customer is typing in. The classic framework says so;
	 * the block checkout does not, so the focused field's id does.
	 *
	 * @param {string} given The framework's type, when it passes one.
	 * @return {string} 'billing' or 'shipping'.
	 */
	function addressType( given ) {
		if ( 'billing' === given || 'shipping' === given ) {
			return given;
		}
		const active = document.activeElement;
		const found = active && active.id ? /^(billing|shipping)[-_]address_1$/.exec( active.id ) : null;
		return found ? found[ 1 ] : state.type;
	}

	/**
	 * Wait for a pause in typing; a newer search ends this one.
	 *
	 * @param {number} sequence This search's number.
	 * @return {Promise<boolean>} True when this search is still the newest.
	 */
	function pause( sequence ) {
		return new Promise( ( resolve ) => {
			window.setTimeout( () => resolve( sequence === state.sequence ), config.debounce || 400 );
		} );
	}

	/**
	 * Suggestions for what the customer typed.
	 *
	 * @param {string} text    Typed text.
	 * @param {string} country Country code.
	 * @param {string} type    Address type, when the framework passes one.
	 * @return {Promise<Array>} Suggestions ({id, label}).
	 */
	async function search( text, country, type ) {
		const query = String( text || '' ).trim();
		const sequence = ++state.sequence;
		state.type = addressType( type );
		if ( state.controller ) {
			state.controller.abort();
			state.controller = null;
		}
		if ( query.length < ( config.minChars || 4 ) ) {
			return [];
		}
		const cacheKey = country + '|' + query.toLowerCase();
		if ( state.cache.has( cacheKey ) ) {
			return state.cache.get( cacheKey );
		}
		if ( ! ( await pause( sequence ) ) ) {
			return [];
		}
		const controller = new window.AbortController();
		state.controller = controller;
		try {
			const response = await window.fetch( url( config.suggestUrl, { q: query, country, session: state.session } ), {
				credentials: 'same-origin',
				headers: { 'X-Lafka-Address-Token': config.token || '' },
				signal: controller.signal,
			} );
			if ( ! response.ok ) {
				return [];
			}
			const body = await response.json();
			const found = Array.isArray( body.suggestions ) ? body.suggestions : [];
			if ( state.cache.size >= 50 ) {
				state.cache.delete( state.cache.keys().next().value );
			}
			state.cache.set( cacheKey, found );
			return sequence === state.sequence ? found : [];
		} catch {
			// An aborted search is a newer one taking over; anything else just means no suggestions.
			return [];
		}
	}

	/**
	 * The hidden field of the classic checkout form (made when the form has
	 * none, i.e. the delivery pin map is off).
	 *
	 * @param {string} name Field name and id.
	 * @return {?HTMLInputElement} Field.
	 */
	function formField( name ) {
		let field = document.getElementById( name );
		const form = document.querySelector( 'form.checkout' );
		if ( ! field && form ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = name;
			field.id = name;
			field.setAttribute( 'data-lafka-autocomplete', '1' );
			form.appendChild( field );
		}
		return field;
	}

	/**
	 * Classic checkout: put the point in the form and ask for fresh rates.
	 * Called twice: at once, and again after WooCommerce has filled the fields
	 * (changing the state field makes the pin map forget its pin).
	 *
	 * @param {{lat: number, lng: number}} point Point.
	 * @param {boolean}                    again Ask for new rates when this changed the form.
	 */
	function setClassicPin( point, again ) {
		const pin = formField( FIELD_PIN );
		const placed = formField( FIELD_PLACED );
		if ( ! pin || ! placed ) {
			return;
		}
		const value = JSON.stringify( { lat: point.lat, lng: point.lng } );
		if ( pin.value === value ) {
			return;
		}
		pin.value = value;
		placed.value = 'autocomplete';
		if ( again && window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	/** Typing in an address field ends a pin this script made (the pin map clears its own). */
	function forgetOwnPin() {
		[ FIELD_PIN, FIELD_PLACED ].forEach( ( name ) => {
			const field = document.getElementById( name );
			if ( field && field.getAttribute( 'data-lafka-autocomplete' ) ) {
				field.value = '';
			}
		} );
	}

	/**
	 * Hand the chosen point to the delivery price.
	 *
	 * @param {Object} place Chosen place (address fields, lat, lng).
	 * @param {string} type  Address type it was chosen for.
	 * @return {Promise<void>}
	 */
	async function sendPin( place, type ) {
		const point = { lat: place.lat, lng: place.lng };
		if ( common.isBlocksContext && common.isBlocksContext() ) {
			const blocks = window.wc && window.wc.blocksCheckout;
			if ( 'shipping' !== type || ! blocks || ! blocks.extensionCartUpdate ) {
				return;
			}
			try {
				await blocks.extensionCartUpdate( {
					namespace: config.namespace,
					data: {
						lat: point.lat,
						lng: point.lng,
						address: {
							country: place.country,
							state: place.state,
							postcode: place.postcode,
							city: place.city,
							address_1: place.address_1,
						},
					},
				} );
			} catch {
				// The price then comes from the address alone; the address itself still fills.
			}
			return;
		}
		const different = document.getElementById( 'ship-to-different-address-checkbox' );
		if ( type !== ( different && different.checked ? 'shipping' : 'billing' ) ) {
			return;
		}
		setClassicPin( point, false );
		window.setTimeout( () => setClassicPin( point, true ), 200 );
	}

	/**
	 * The chosen suggestion as the address fields WooCommerce fills.
	 *
	 * @param {string} id      Suggestion id.
	 * @param {string} country Country of the form (the block checkout passes it).
	 * @return {Promise<Object>} country, state, postcode, city, address_1, address_2.
	 */
	async function select( id, country ) {
		const type = state.type;
		const response = await window.fetch( url( config.placeUrl, { id, country: country || countryOf( type ), session: state.session } ), {
			credentials: 'same-origin',
			headers: { 'X-Lafka-Address-Token': config.token || '' },
		} );
		const body = response.ok ? await response.json() : null;
		const place = body && body.place;
		state.session = newSession();
		if ( ! place ) {
			throw new Error( 'The chosen address could not be loaded.' );
		}
		await sendPin( place, type );
		return {
			country: place.country,
			state: place.state,
			postcode: place.postcode,
			city: place.city,
			address_1: place.address_1,
			address_2: place.address_2,
		};
	}

	/**
	 * The country the form for an address type is on.
	 *
	 * @param {string} type Address type.
	 * @return {string} Country code ('' when the form has none).
	 */
	function countryOf( type ) {
		const field = document.getElementById( type + '_country' ) || document.getElementById( type + '-country' );
		return field && field.value ? field.value : '';
	}

	common.registerAddressAutocompleteProvider( {
		id: config.id,
		canSearch: ( country ) => Array.isArray( config.countries ) && config.countries.indexOf( String( country ).toUpperCase() ) !== -1,
		search,
		select,
	} );

	document.addEventListener( 'keydown', ( event ) => {
		const target = event.target;
		const edits = 1 === event.key.length || 'Backspace' === event.key || 'Delete' === event.key;
		if ( edits && target && target.closest && target.closest( 'form.checkout .address-field' ) && 'INPUT' === target.tagName ) {
			forgetOwnPin();
		}
	} );
} )( window, document );
