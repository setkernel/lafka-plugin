/**
 * Consent banner inline script (incl/analytics/lafka-analytics-emitter.php):
 * while the banner is open it publishes its height as --lafka-consent-banner-h
 * (and html.lafka-consent-open) so the theme's fixed-bottom bars — the mobile
 * sticky add-to-cart above all — sit above it instead of underneath.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

const PHP = readFileSync( new URL( '../../incl/analytics/lafka-analytics-emitter.php', import.meta.url ), 'utf8' );
const SCRIPT = PHP.match( /<script id="lafka-consent-banner-js">([\s\S]*?)<\/script>/ )[ 1 ];

const MARKUP = `
<div class="lafka-consent-banner" id="lafka-consent-banner" hidden>
	<button type="button" data-lafka-consent="reject">Reject</button>
	<button type="button" data-lafka-consent="accept">Accept all</button>
</div>
<div class="lafka-consent-modal" id="lafka-consent-modal" hidden></div>`;

function load( { stored = null, height = 148 } = {} ) {
	const { document, Event } = parseHTML( `<!doctype html><html><head></head><body>${ MARKUP }</body></html>` );
	document.getElementById( 'lafka-consent-banner' ).getBoundingClientRect = () => ( { height } );
	let observed = null;
	const window = {
		document,
		dataLayer: [],
		localStorage: {
			getItem: () => stored,
			setItem: ( key, value ) => ( stored = value ),
		},
		ResizeObserver: class {
			constructor( cb ) {
				this.cb = cb;
			}
			observe( el ) {
				observed = { cb: this.cb, el, disconnected: false, io: this };
			}
			disconnect() {
				observed.disconnected = true;
			}
		},
		addEventListener() {},
		removeEventListener() {},
	};
	vm.runInNewContext( SCRIPT, { window, document, JSON, Date, Math } );
	const root = document.documentElement;
	return {
		document,
		root,
		offset: () => root.style.getPropertyValue( '--lafka-consent-banner-h' ),
		open: () => root.classList.contains( 'lafka-consent-open' ),
		click: ( action ) =>
			document.querySelector( `[data-lafka-consent="${ action }"]` ).dispatchEvent( new Event( 'click', { bubbles: true } ) ),
		resize: ( h ) => {
			document.getElementById( 'lafka-consent-banner' ).getBoundingClientRect = () => ( { height: h } );
			observed.cb();
		},
		observer: () => observed,
	};
}

test( 'an open banner publishes its height for the fixed-bottom bars', () => {
	const page = load( { height: 147.2 } );

	assert.equal( page.offset(), '148px' );
	assert.ok( page.open() );
} );

test( 'the offset follows the banner when it reflows (rotation, text wrap)', () => {
	const page = load();

	page.resize( 96 );

	assert.equal( page.offset(), '96px' );
} );

test( 'deciding closes the banner and releases the bars', () => {
	const page = load();

	page.click( 'accept' );

	assert.equal( page.offset(), '0px' );
	assert.ok( ! page.open() );
	assert.ok( page.observer().disconnected );
} );

test( 'a returning visitor never sees the banner or an offset', () => {
	const page = load( { stored: JSON.stringify( { analytics_storage: false } ) } );

	assert.equal( page.offset(), '' );
	assert.ok( ! page.open() );
} );
