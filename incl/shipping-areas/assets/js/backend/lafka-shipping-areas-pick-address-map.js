/**
 * Shipping Areas → Advanced → "Pick Store Location": the admin map.
 *
 * The hidden #store_map_location input is written ONLY when the operator
 * pins the store — a map click, the address search, or "Geocode WooCommerce
 * Store Address". Opening (and saving) the page never writes a location.
 * With nothing saved, the map centres on a geocode of the WooCommerce store
 * address (no marker), or shows a neutral world view when that fails.
 *
 * Params: lafka_admin_map_params = { saved_store_address_lat_long, store_address }.
 */
let markers = [];

const LAFKA_WORLD_VIEW = { center: { lat: 20, lng: 0 }, zoom: 2 };

function lafka_admin_map_config() {
	return 'undefined' !== typeof lafka_admin_map_params && lafka_admin_map_params ? lafka_admin_map_params : {};
}

function lafka_parse_saved_location( raw ) {
	if ( ! raw ) {
		return null;
	}
	let value;
	try {
		value = JSON.parse( decodeURIComponent( raw ) );
	} catch ( error ) {
		return null;
	}
	const lat = value ? parseFloat( value.lat ) : NaN;
	const lng = value ? parseFloat( value.lng ) : NaN;
	if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 ) {
		return null;
	}
	return { lat, lng };
}

function lafka_admin_init_store_location_map() {
	const container = document.getElementById( 'lafka-shipping-areas-admin-store-map' );
	if ( ! container ) {
		return;
	}
	const params = lafka_admin_map_config();
	const saved = lafka_parse_saved_location( params.saved_store_address_lat_long );
	const storeAddress = String( params.store_address || '' ).trim();

	const map = new google.maps.Map(
		container,
		saved ? { center: saved, zoom: 12 } : { center: LAFKA_WORLD_VIEW.center, zoom: LAFKA_WORLD_VIEW.zoom }
	);
	const geocoder = new google.maps.Geocoder();

	if ( saved ) {
		lafka_show_marker( map, saved );
	} else if ( storeAddress ) {
		// Orientation only: centre on the store address, no marker, no write.
		geocoder
			.geocode( { address: storeAddress } )
			.then( ( { results } ) => {
				if ( results && results[ 0 ] ) {
					map.setCenter( results[ 0 ].geometry.location );
					map.setZoom( 12 );
				}
			} )
			.catch( () => {} );
	}

	map.addListener( 'click', ( event ) => lafka_pick_location( map, event.latLng ) );

	const search = document.getElementById( 'lafka-shipping-areas-floating-search-panel-submit' );
	if ( search ) {
		search.addEventListener( 'click', () => lafka_geocode_address( geocoder, map, '' ) );
	}
	const locate = document.getElementById( 'lafka_shipping_store_map_locate' );
	if ( locate ) {
		locate.addEventListener( 'click', () => lafka_geocode_address( geocoder, map, storeAddress ) );
	}
}

function lafka_geocode_address( geocoder, map, address ) {
	const input = document.getElementById( 'lafka-shipping-areas-search-address' );
	const query = '' === address ? ( input ? input.value : '' ) : address;
	if ( ! query ) {
		return Promise.resolve();
	}
	return geocoder
		.geocode( { address: query } )
		.then( ( { results } ) => {
			if ( ! results || ! results[ 0 ] ) {
				return;
			}
			map.setCenter( results[ 0 ].geometry.location );
			map.setZoom( 12 );
			lafka_pick_location( map, results[ 0 ].geometry.location );
		} )
		.catch( ( error ) => window.alert( 'Geocode was not successful for the following reason: ' + error ) );
}

/** The operator chose this point: show it and store it. */
function lafka_pick_location( map, position ) {
	lafka_show_marker( map, position );
	lafka_fill_input( position );
}

function lafka_show_marker( map, position ) {
	lafka_delete_markers();
	markers.push( new google.maps.Marker( { position, map } ) );
	map.panTo( position );
}

function lafka_delete_markers() {
	for ( let i = 0; i < markers.length; i++ ) {
		markers[ i ].setMap( null );
	}
	markers = [];
}

function lafka_fill_input( position ) {
	const input = document.getElementById( 'store_map_location' );
	if ( ! input ) {
		return;
	}
	const point =
		position && 'function' === typeof position.lat
			? { lat: position.lat(), lng: position.lng() }
			: { lat: position.lat, lng: position.lng };
	input.value = encodeURIComponent( JSON.stringify( point ) );
}

window.addEventListener( 'DOMContentLoaded', function () {
	lafka_admin_init_store_location_map();
} );
