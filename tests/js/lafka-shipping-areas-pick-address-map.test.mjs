/**
 * incl/shipping-areas/assets/js/backend/lafka-shipping-areas-pick-address-map.js
 *
 * The admin store-location map must never invent a location: opening the
 * settings page writes nothing (the old code dropped a Sydney pin and wrote
 * it into the setting, so a plain "Save" persisted Sydney as the store).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

const SOURCE = readFileSync(
	new URL( '../../incl/shipping-areas/assets/js/backend/lafka-shipping-areas-pick-address-map.js', import.meta.url ),
	'utf8'
);

const MARKUP = `
<input id="store_map_location" type="hidden" value="">
<button id="lafka_shipping_store_map_locate" type="button">Locate</button>
<input id="lafka-shipping-areas-search-address" value="">
<input id="lafka-shipping-areas-floating-search-panel-submit" type="button">
<div id="lafka-shipping-areas-admin-store-map"></div>`;

/**
 * @param {object} params   lafka_admin_map_params.
 * @param {object} geocodes address => { lat, lng } the fake geocoder resolves.
 */
async function open( params, geocodes = {} ) {
	const { document, Event } = parseHTML( `<!doctype html><html><body>${ MARKUP }</body></html>` );
	const state = { maps: [], markers: [], geocoded: [] };
	const listeners = {};

	class FakeMap {
		constructor( el, options ) {
			this.center = options.center;
			this.zoom = options.zoom;
			this.handlers = {};
			state.maps.push( this );
		}
		setCenter( c ) {
			this.center = c;
		}
		setZoom( z ) {
			this.zoom = z;
		}
		panTo( c ) {
			this.center = c;
		}
		addListener( type, fn ) {
			this.handlers[ type ] = fn;
		}
	}
	class FakeMarker {
		constructor( { position } ) {
			this.position = position;
			this.onMap = true;
			state.markers.push( this );
		}
		setMap( map ) {
			this.onMap = null !== map;
		}
	}
	class FakeGeocoder {
		geocode( { address } ) {
			state.geocoded.push( address );
			const hit = geocodes[ address ];
			return hit ? Promise.resolve( { results: [ { geometry: { location: hit } } ] } ) : Promise.reject( new Error( 'ZERO_RESULTS' ) );
		}
	}

	const window = {
		document,
		alert: () => {},
		addEventListener: ( type, fn ) => ( listeners[ type ] = fn ),
	};
	vm.runInNewContext( SOURCE, {
		window,
		document,
		JSON,
		Math,
		Promise,
		encodeURIComponent,
		decodeURIComponent,
		parseFloat,
		isFinite,
		String,
		google: { maps: { Map: FakeMap, Marker: FakeMarker, Geocoder: FakeGeocoder } },
		lafka_admin_map_params: params,
	} );
	listeners.DOMContentLoaded();
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	const input = document.getElementById( 'store_map_location' );
	return {
		state,
		map: state.maps[ 0 ],
		input,
		visibleMarkers: () => state.markers.filter( ( m ) => m.onMap ),
		click: async ( id ) => {
			document.getElementById( id ).dispatchEvent( new Event( 'click' ) );
			await new Promise( ( resolve ) => setImmediate( resolve ) );
		},
	};
}

const STORE = { lat: 12.34, lng: -56.78 };

test( 'nothing saved: centre on the store address, no marker, nothing written', async () => {
	const page = await open( { saved_store_address_lat_long: '', store_address: '1 Example St Exampleville' }, { '1 Example St Exampleville': STORE } );

	assert.deepEqual( page.map.center, STORE );
	assert.equal( page.visibleMarkers().length, 0 );
	assert.equal( page.input.value, '', 'Opening the page must not write a location.' );
} );

test( 'nothing saved and the address does not geocode: neutral world view, no marker', async () => {
	const page = await open( { saved_store_address_lat_long: '', store_address: '' } );

	assert.equal( page.map.zoom, 2 );
	assert.notEqual( page.map.center.lat, -33.8688197, 'No hard-coded Sydney.' );
	assert.equal( page.visibleMarkers().length, 0 );
	assert.equal( page.input.value, '' );
} );

test( 'a saved location shows its marker without rewriting the field', async () => {
	const saved = encodeURIComponent( JSON.stringify( STORE ) );
	const page = await open( { saved_store_address_lat_long: saved, store_address: 'x' } );

	// Plain copy: objects built inside the VM have a foreign prototype.
	assert.deepEqual( JSON.parse( JSON.stringify( page.map.center ) ), STORE );
	assert.equal( page.visibleMarkers().length, 1 );
	assert.equal( page.input.value, '' );
	assert.deepEqual( page.state.geocoded, [], 'No geocoding needed.' );
} );

test( 'a malformed saved value is treated as not saved', async () => {
	const page = await open( { saved_store_address_lat_long: '%7Bnot-json', store_address: '' } );

	assert.equal( page.visibleMarkers().length, 0 );
} );

test( 'pinning on the map is what writes the location', async () => {
	const page = await open( { saved_store_address_lat_long: '', store_address: '' } );

	page.map.handlers.click( { latLng: { lat: () => 12.5, lng: () => -56.9 } } );

	assert.equal( page.visibleMarkers().length, 1 );
	assert.deepEqual( JSON.parse( decodeURIComponent( page.input.value ) ), { lat: 12.5, lng: -56.9 } );
} );

test( '"Geocode WooCommerce Store Address" pins and writes the store', async () => {
	const page = await open( { saved_store_address_lat_long: '', store_address: '1 Example St' }, { '1 Example St': STORE } );
	assert.equal( page.input.value, '' );

	await page.click( 'lafka_shipping_store_map_locate' );

	assert.equal( page.visibleMarkers().length, 1 );
	assert.deepEqual( JSON.parse( decodeURIComponent( page.input.value ) ), STORE );
} );
