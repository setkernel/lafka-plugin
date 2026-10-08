/**
 * Lafka Shipping Settings → Advanced → "Store location": the admin map.
 *
 * The store point is the business geo (lafka_business_geo_lat / _lng), the
 * one place the store's coordinates live: the schema, the directions links
 * and every map read it. This map shows it and lets the operator move it:
 * click the map, drag the pin, search an address, or geocode the WooCommerce
 * store address. Each writes the hidden #lafka_store_point input
 * (URL-encoded JSON { lat, lng }); saving the settings stores it as the
 * business geo (Lafka_Shipping_Areas_Admin::sanitize_advanced_settings()).
 * Opening and saving the page without pinning changes nothing.
 *
 * With no point yet the map opens on window.lafkaMapDefaults (the WooCommerce
 * base region, else Canada).
 *
 * Params: window.lafkaStorePicker = { point: { lat, lng } | null, storeAddress, i18n }.
 */
( function ( window, document ) {
	'use strict';

	const params = window.lafkaStorePicker || {};
	const i18n = params.i18n || {};

	/**
	 * Write the chosen point into the hidden field the form saves.
	 *
	 * @param {{lat: number, lng: number}} point Point.
	 */
	function store( point ) {
		const input = document.getElementById( 'lafka_store_point' );
		if ( input ) {
			input.value = encodeURIComponent( JSON.stringify( { lat: Number( point.lat.toFixed( 7 ) ), lng: Number( point.lng.toFixed( 7 ) ) } ) );
		}
		const shown = document.getElementById( 'lafka-store-point-coordinates' );
		if ( shown ) {
			shown.textContent = point.lat.toFixed( 6 ) + ', ' + point.lng.toFixed( 6 );
		}
	}

	/**
	 * @param {string} text Message ('' clears it).
	 */
	function say( text ) {
		const box = document.getElementById( 'lafka-store-point-message' );
		if ( box ) {
			box.textContent = text || '';
		}
	}

	function init( maps ) {
		const container = document.getElementById( 'lafka-shipping-areas-admin-store-map' );
		if ( ! container ) {
			return;
		}
		const saved = maps.point( params.point );
		const map = maps.map( container, saved ? { lat: saved.lat, lng: saved.lng, zoom: 15 } : maps.defaults );
		if ( ! map ) {
			return;
		}
		let marker = null;

		const pick = ( point ) => {
			if ( marker ) {
				marker.set( point );
			} else {
				marker = map.marker( point, { draggable: true, onMove: pick } );
			}
			store( point );
			say( '' );
		};

		if ( saved ) {
			marker = map.marker( saved, { draggable: true, onMove: pick } );
		}
		map.onClick( pick );

		const lookUp = ( query ) => {
			if ( ! query ) {
				return;
			}
			say( i18n.searching || '' );
			maps.geocode( query ).then(
				( result ) => {
					if ( ! result ) {
						say( maps.i18n.notFound || '' );
						return;
					}
					map.view( result, 16 );
					pick( result );
				},
				( error ) => say( error.message )
			);
		};

		const search = document.getElementById( 'lafka-shipping-areas-floating-search-panel-submit' );
		const field = document.getElementById( 'lafka-shipping-areas-search-address' );
		if ( search && field ) {
			search.addEventListener( 'click', () => lookUp( field.value.trim() ) );
			field.addEventListener( 'keydown', ( event ) => {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					lookUp( field.value.trim() );
				}
			} );
		}
		const locate = document.getElementById( 'lafka_shipping_store_map_locate' );
		if ( locate ) {
			locate.addEventListener( 'click', () => lookUp( String( params.storeAddress || '' ).trim() ) );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( window.lafkaMaps ) {
			window.lafkaMaps.ready().then( init, ( error ) => say( error.message ) );
		}
	} );
} )( window, document );
