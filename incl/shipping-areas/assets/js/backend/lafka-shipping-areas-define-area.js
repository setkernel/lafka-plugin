/**
 * Delivery zone editor (the "Draw Shipping Area" box on a Lafka Shipping
 * Area): click the map to add a corner, drag a corner to move it, click a
 * corner to remove it, drag a midpoint to insert a corner.
 *
 * The polygon is stored in the hidden #lafka_shipping_area_polygon_coordinates
 * input as an Encoded Polyline (Google's algorithm, lafkaMaps.polyline) — the
 * format the server decodes for the checkout geo-fence
 * (Lafka_Shipping_Areas::decode_polygon_coordinates()), the branch modal and
 * the [lafka_shipping_areas] map read. Works with either map provider.
 *
 * An existing zone opens fitted to its polygon; a new one on
 * window.lafkaMapDefaults (the store, else the store's region).
 */
( function ( window, document ) {
	'use strict';

	function init( maps ) {
		const container = document.getElementById( 'lafka-shipping-areas-admin-define-area-map' );
		const input = document.getElementById( 'lafka_shipping_area_polygon_coordinates' );
		if ( ! container || ! input ) {
			return;
		}
		const path = maps.polyline.decode( input.value );
		const map = maps.map( container, maps.defaults );
		if ( ! map ) {
			return;
		}
		map.editor( path, ( points ) => {
			input.value = maps.polyline.encode( points );
		} );
		if ( path.length ) {
			map.fit( path );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		if ( window.lafkaMaps ) {
			window.lafkaMaps.ready().then( init );
		}
	} );
} )( window, document );
