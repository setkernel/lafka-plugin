/**
 * assets/js/lafka-consent-mirror.js — Insights consent_required mode: every
 * consent decision the banner pushes is mirrored into the lafka_consent
 * cookie (read server-side) and WooCommerce Order Attribution.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

function page( script = 'assets/js/lafka-consent-mirror.js' ) {
	const cookies = [];
	const tracking = [];
	const p = loadScript( script, '', {
		location: new URL( 'https://shop.example.test/menu/' ),
		wc_order_attribution: { setOrderTracking: ( allow ) => tracking.push( allow ) },
	} );
	Object.defineProperty( p.document, 'cookie', {
		set( v ) {
			cookies.push( v );
		},
		get: () => '',
		configurable: true,
	} );
	return { p, cookies, tracking };
}

test( 'a granted decision sets the consent cookie and starts Woo attribution', () => {
	const { p, cookies, tracking } = page();
	p.window.dataLayer.push( { event: 'consent_update', consent_state: { analytics_storage: true, ad_storage: false } } );

	assert.deepEqual( cookies, [ 'lafka_consent=1;path=/;max-age=15552000;SameSite=Lax;Secure' ] );
	assert.deepEqual( tracking, [ true ] );
} );

test( 'a refusal records 0 and stops Woo attribution', () => {
	const { p, cookies, tracking } = page();
	p.window.dataLayer.push( { event: 'consent_update', consent_state: { analytics_storage: false } } );
	assert.equal( cookies[ 0 ].split( ';' )[ 0 ], 'lafka_consent=0' );
	assert.deepEqual( tracking, [ false ] );
} );

test( 'other pushes pass through untouched', () => {
	const { p, cookies } = page();
	const length = p.window.dataLayer.push( { event: 'view_item' }, [ 'consent', 'default', {} ] );
	assert.equal( length, 2 );
	assert.equal( cookies.length, 0 );
} );

test( 'the minified build behaves the same', () => {
	const { p, cookies } = page( 'assets/js/lafka-consent-mirror.min.js' );
	p.window.dataLayer.push( { event: 'consent_update', consent_state: { analytics_storage: true } } );
	assert.equal( cookies[ 0 ].split( ';' )[ 0 ], 'lafka_consent=1' );
} );
