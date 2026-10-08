/**
 * Lafka core: the small shared helpers every storefront script uses, so each
 * one has a single home (window.lafka).
 *
 *   lafka.track( event, params )     push { event, ...params } to the dataLayer
 *   lafka.cookie.get / set / remove  first-party cookies (path /, SameSite=Lax)
 *   lafka.money.format( amount, { trim } )  an amount in the store currency
 *   lafka.debounce( fn, wait )       trailing-edge debounce
 *   lafka.api.get / post / url       the REST API with the nonce attached
 *
 * Dependency-free. Configuration comes from window.lafkaCore (wp_localize_script):
 * { restRoot, nonce, currency: { symbol, format, decimals, decimalSep, thousandSep } }
 * where `format` is WooCommerce's price format ("%1$s%2$s": symbol, number).
 *
 * @since 10.4.0
 */
( function ( w, d ) {
	'use strict';

	const cfg = w.lafkaCore || {};
	const lafka = ( w.lafka = w.lafka || {} );

	lafka.track = function ( event, params ) {
		if ( ! event || ! w.dataLayer || typeof w.dataLayer.push !== 'function' ) {
			return;
		}
		w.dataLayer.push( Object.assign( {}, params, { event } ) );
	};

	lafka.cookie = {
		get( name ) {
			const pairs = ( d.cookie || '' ).split( ';' );
			for ( const pair of pairs ) {
				const at = pair.indexOf( '=' );
				if ( at > -1 && pair.slice( 0, at ).trim() === name ) {
					try {
						return decodeURIComponent( pair.slice( at + 1 ).trim() );
					} catch {
						return pair.slice( at + 1 ).trim();
					}
				}
			}
			return '';
		},
		set( name, value, days ) {
			let cookie = name + '=' + encodeURIComponent( value ) + ';path=/;SameSite=Lax';
			if ( days ) {
				cookie += ';max-age=' + Math.round( days * 86400 );
			}
			if ( w.location.protocol === 'https:' ) {
				cookie += ';Secure';
			}
			d.cookie = cookie;
		},
		remove( name ) {
			d.cookie = name + '=;path=/;max-age=0;SameSite=Lax';
		},
	};

	lafka.money = {
		/**
		 * An amount as WooCommerce would print it: its symbol, position,
		 * separators and decimals. With trim, a whole amount drops its zero cents.
		 */
		format( amount, options ) {
			const c = cfg.currency || {};
			const decimals = typeof c.decimals === 'number' ? c.decimals : 2;
			let n = parseFloat( amount );
			if ( isNaN( n ) ) {
				n = 0;
			}
			const whole = Math.abs( n - Math.round( n ) ) < 0.005;
			const places = options && options.trim && whole ? 0 : decimals;
			const parts = Math.abs( n ).toFixed( places ).split( '.' );
			parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousandSep === undefined ? ',' : c.thousandSep );
			const number = ( n < 0 ? '-' : '' ) + parts.join( c.decimalSep || '.' );
			return ( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol || '' ).replace( '%2$s', number );
		},
	};

	lafka.debounce = function ( fn, wait ) {
		let timer = 0;
		return function () {
			const args = arguments;
			const self = this;
			w.clearTimeout( timer );
			timer = w.setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	};

	function request( path, options ) {
		const init = Object.assign( { credentials: 'same-origin', headers: {} }, options );
		init.headers = Object.assign( {}, init.headers );
		if ( cfg.nonce ) {
			init.headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		if ( init.body && typeof init.body === 'object' && ! ( init.body instanceof w.FormData ) ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( init.body );
		}
		return w.fetch( lafka.api.url( path ), init ).then( function ( response ) {
			return response.text().then( function ( text ) {
				let data;
				try {
					data = text ? JSON.parse( text ) : null;
				} catch {
					data = null;
				}
				if ( ! response.ok ) {
					const error = new Error( ( data && data.message ) || 'Request failed' );
					error.status = response.status;
					error.data = data;
					throw error;
				}
				return data;
			} );
		} );
	}

	lafka.api = {
		/** Absolute URL for a REST path such as "lafka/v1/open-status". */
		url( path ) {
			return ( cfg.restRoot || '/wp-json/' ).replace( /\/?$/, '/' ) + String( path ).replace( /^\//, '' );
		},
		get( path, options ) {
			return request( path, Object.assign( { method: 'GET' }, options ) );
		},
		post( path, body, options ) {
			return request( path, Object.assign( { method: 'POST', body }, options ) );
		},
	};
}( window, document ) );
