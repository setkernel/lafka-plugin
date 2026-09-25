/**
 * assets/js/lafka-insights.js — the one-per-page Insights beacon (GX2).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'assets/js/lafka-insights.js';
const ENDPOINT = 'https://shop.example.test/wp-json/lafka/v1/i';

function page( { dataLayer = [], cfg = {}, nav = {}, location, width = 375, localStorage, script = SCRIPT } = {} ) {
	const beacons = [];
	const navigator = {
		sendBeacon: ( url, body ) => {
			beacons.push( { url, body: JSON.parse( body ), bytes: body.length } );
			return true;
		},
		...nav,
	};
	const globals = { dataLayer, navigator, innerWidth: width, lafkaInsightsCfg: { u: ENDPOINT, m: 'a', n: '', ...cfg } };
	if ( location ) {
		globals.location = new URL( location );
	}
	if ( localStorage ) {
		globals.localStorage = localStorage;
	}
	const p = loadScript( script, '', globals );
	p.beacons = beacons;
	p.referrer = ( value ) => Object.defineProperty( p.document, 'referrer', { value, configurable: true } );
	p.hide = () => {
		Object.defineProperty( p.document, 'visibilityState', { value: 'hidden', configurable: true } );
		p.document.dispatchEvent( new p.window.Event( 'visibilitychange' ) );
	};
	return p;
}

const viewItem = ( id ) => ( { event: 'view_item', ecommerce: { items: [ { item_id: String( id ), item_name: 'x', price: 9 } ] } } );

test( 'events pushed before and after the script loads go out in ONE beacon on pagehide', () => {
	const p = page( { dataLayer: [ { event: 'page_context', page_type: 'product' }, { ecommerce: null }, viewItem( 42 ) ] } );
	p.referrer( 'https://www.google.com/search?q=pizza+near+me' );

	p.window.dataLayer.push( { event: 'select_fulfilment', fulfilment_method: 'pickup' } );
	p.window.dataLayer.push( { event: 'phone_click', link_url: 'tel:+15555550100' } ); // not allowlisted
	p.window.dataLayer.push( { event: 'purchase', ecommerce: { transaction_id: '1' } } ); // server-side only
	p.window.dataLayer.push( { event: 'store_closed_view', closed_context: 'pdp' } );

	p.fireWindow( 'pagehide' );
	p.fireWindow( 'pagehide' );
	p.hide();

	assert.equal( p.beacons.length, 1, 'exactly one beacon per page' );
	assert.equal( p.beacons[ 0 ].url, ENDPOINT );
	assert.deepEqual( p.beacons[ 0 ].body, {
		v: 1,
		p: '/menu/',
		t: 'product',
		r: 'www.google.com',
		us: '',
		um: '',
		uc: '',
		d: 'm',
		e: [ [ 'v', '42' ], [ 'f', 'pickup' ], [ 'c' ] ],
	} );
} );

test( 'the referrer is reduced to its host and UTM tags + device class ride along', () => {
	const p = page( { location: 'https://shop.example.test/?utm_source=Newsletter&utm_medium=email&utm_campaign=fall', width: 1280 } );
	p.referrer( 'https://mail.example.org/inbox/secret-path?token=abc' );
	p.hide();

	const body = p.beacons[ 0 ].body;
	assert.equal( body.r, 'mail.example.org' );
	assert.equal( body.p, '/' );
	assert.deepEqual( [ body.us, body.um, body.uc, body.d ], [ 'Newsletter', 'email', 'fall', 'd' ] );
	assert.ok( ! JSON.stringify( body ).includes( 'token' ), 'no query string from the referrer' );
} );

test( 'the dataLayer keeps working for GTM (push still appends and returns)', () => {
	const p = page();
	const length = p.window.dataLayer.push( { event: 'view_item_list' }, { event: 'gtm.custom' } );
	assert.equal( length, 2 );
	assert.equal( p.dataLayer.length, 2 );
} );

test( 'search refinements while typing collapse to the final term', () => {
	const p = page();
	p.window.dataLayer.push( { event: 'search', search_term: 'pi', results_count: 9 } );
	p.window.dataLayer.push( { event: 'search', search_term: 'pizz', results_count: 4 } );
	p.window.dataLayer.push( { event: 'search', search_term: 'pizza', results_count: 3 } );
	p.window.dataLayer.push( { event: 'search', search_term: 'wings', results_count: 0 } );
	p.fireWindow( 'pagehide' );

	assert.deepEqual( p.beacons[ 0 ].body.e, [ [ 's', 'pizza', 3 ], [ 's', 'wings', 0 ] ] );
} );

test( 'the queue is capped at 30 events and the body stays under 2 KB', () => {
	const p = page();
	for ( let i = 0; i < 50; i++ ) {
		p.window.dataLayer.push( viewItem( 1000000 + i ) );
	}
	p.fireWindow( 'pagehide' );
	assert.equal( p.beacons[ 0 ].body.e.length, 30 );
	assert.ok( p.beacons[ 0 ].bytes <= 2000 );
} );

test( 'aggregate mode honours Global Privacy Control and Do Not Track', () => {
	const gpc = page( { nav: { globalPrivacyControl: true } } );
	gpc.fireWindow( 'pagehide' );
	assert.equal( gpc.beacons.length, 0 );

	const dnt = page( { nav: { doNotTrack: '1' } } );
	dnt.fireWindow( 'pagehide' );
	assert.equal( dnt.beacons.length, 0 );
} );

test( 'consent_required mode waits for analytics consent', () => {
	const store = { value: null, getItem: () => store.value };
	const p = page( { cfg: { m: 'c' }, localStorage: store } );
	p.fireWindow( 'pagehide' );
	assert.equal( p.beacons.length, 0, 'no decision yet → nothing sent' );

	store.value = JSON.stringify( { analytics_storage: true } );
	p.fireWindow( 'pagehide' );
	assert.equal( p.beacons.length, 1, 'accepted during the page → sent at pagehide' );
} );

test( 'logged-in visitors authenticate with the wp_rest nonce', () => {
	const p = page( { cfg: { n: 'abc123' } } );
	p.fireWindow( 'pagehide' );
	assert.equal( p.beacons[ 0 ].url, `${ ENDPOINT }?_wpnonce=abc123` );
} );

test( 'without sendBeacon or config the script is inert', () => {
	assert.doesNotThrow( () => loadScript( SCRIPT, '', { navigator: {}, lafkaInsightsCfg: { u: ENDPOINT } } ) );
	assert.doesNotThrow( () => loadScript( SCRIPT, '', { navigator: { sendBeacon() {} } } ) );
} );

test( 'the minified build that ships behaves like the source', () => {
	const p = page( { script: 'assets/js/lafka-insights.min.js', dataLayer: [ { event: 'page_context', page_type: 'shop' }, viewItem( 7 ) ] } );
	p.fireWindow( 'pagehide' );
	p.fireWindow( 'pagehide' );
	assert.equal( p.beacons.length, 1 );
	assert.equal( p.beacons[ 0 ].body.t, 'shop' );
	assert.deepEqual( p.beacons[ 0 ].body.e, [ [ 'v', '7' ] ] );
} );

test( 'the shipped build stays within the 2 KB budget', () => {
	const min = readFileSync( new URL( '../../assets/js/lafka-insights.min.js', import.meta.url ) );
	assert.ok( min.length <= 2048, `lafka-insights.min.js is ${ min.length } bytes` );
} );
