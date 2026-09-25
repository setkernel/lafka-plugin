/**
 * assets/js/lafka-diag.js — the inline JS error beacon (A6).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { loadScript } from './support/dom.mjs';

const ENDPOINT = 'https://shop.example.test/wp-json/lafka/v1/diag';
const OWN = 'https://shop.example.test/wp-content/plugins/lafka-plugin/assets/js/cart.js?ver=3';

function page( { cfg = {}, session = {}, script = 'assets/js/lafka-diag.js' } = {} ) {
	const beacons = [];
	const p = loadScript( script, '', {
		lafkaDiagCfg: { u: ENDPOINT, s: 1, t: 'checkout', ...cfg },
		navigator: {
			sendBeacon: ( url, body ) => {
				beacons.push( { url, body: JSON.parse( body ) } );
				return true;
			},
		},
		sessionStorage: session,
	} );
	p.beacons = beacons;
	p.error = ( message, filename = OWN, lineno = 10, colno = 2 ) => p.fireWindow( 'error', { message, filename, lineno, colno } );
	return p;
}

test( 'an uncaught error from a same-origin script is reported once', () => {
	const p = page();
	p.error( 'TypeError: x is undefined' );
	p.error( 'TypeError: x is undefined' );

	assert.deepEqual( p.beacons, [
		{ url: ENDPOINT, body: { m: 'TypeError: x is undefined', f: OWN, l: 10, c: 2, t: 'checkout' } },
	] );
} );

test( 'opaque, third-party and extension errors are ignored', () => {
	const p = page();
	p.error( 'Script error.', '' );
	p.error( 'boom', 'https://cdn.other.example/lib.js' );
	p.error( 'boom', 'chrome-extension://abcdef/content.js' );
	p.error( 'boom', 'https://shop.example.test.evil.example/x.js' );
	p.error( '' );
	assert.equal( p.beacons.length, 0 );
} );

test( 'at most 3 reports per page', () => {
	const p = page();
	for ( let i = 0; i < 6; i++ ) {
		p.error( `error ${ i }` );
	}
	assert.equal( p.beacons.length, 3 );
} );

test( 'at most 10 reports per tab session', () => {
	const session = { lafka_diag: 9 };
	const p = page( { session } );
	p.error( 'tenth' );
	p.error( 'eleventh' );
	assert.equal( p.beacons.length, 1 );
	assert.equal( Number( session.lafka_diag ), 10 );
} );

test( 'unhandled rejections report the first same-origin stack frame', () => {
	const p = page();
	const reason = { message: 'fetch failed', stack: `Error: fetch failed\n    at load (${ OWN }:44:7)\n    at https://shop.example.test/x.js:1:1` };
	p.fireWindow( 'unhandledrejection', { reason } );
	p.fireWindow( 'unhandledrejection', { reason: 'plain string, no stack' } );

	assert.equal( p.beacons.length, 1 );
	assert.deepEqual( p.beacons[ 0 ].body, { m: 'fetch failed', f: OWN, l: 44, c: 7, t: 'checkout' } );
} );

test( 'sampling at 0 sends nothing', () => {
	const p = page( { cfg: { s: 0 } } );
	p.error( 'boom' );
	assert.equal( p.beacons.length, 0 );
} );

test( 'the inlined build is at most 700 bytes and behaves like the source', () => {
	const min = readFileSync( new URL( '../../assets/js/lafka-diag.min.js', import.meta.url ) );
	assert.ok( min.length <= 700, `lafka-diag.min.js is ${ min.length } bytes` );

	const p = page( { script: 'assets/js/lafka-diag.min.js' } );
	p.error( 'boom' );
	p.error( 'Script error.' );
	assert.equal( p.beacons.length, 1 );
} );
