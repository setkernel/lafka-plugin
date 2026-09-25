/**
 * incl/promotions/assets/js/lafka-promotions.js — the BOGO banner's close
 * button hides the in-flow banner for real (H-02), remembers the dismissal
 * under the PHP-built key the pre-paint head check reads (H-05), and hands
 * keyboard focus to the next control instead of dropping it on <body>.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'incl/promotions/assets/js/lafka-promotions.js';

const BANNER = `<a class="skip-link" href="#main">Skip</a>
	<div id="lafka-bogo-banner" class="lafka-bogo-banner--inline" role="region" aria-label="Promotion">
		<div class="lafka-bogo-inner"><a class="lafka-bogo-link" href="/menu/">Buy 1, get 1 50% off</a></div>
		<button type="button" class="lafka-bogo-close" aria-label="Close banner">×</button>
	</div>
	<header><a class="logo" href="/">Home</a></header>
	<main id="main"></main>`;

function page( stored = {}, promo = {} ) {
	const writes = {};
	const focused = [];
	const localStorage = {
		getItem: ( key ) => ( key in stored ? stored[ key ] : null ),
		setItem: ( key, value ) => ( writes[ key ] = value ),
	};
	const p = loadScript( SCRIPT, BANNER, {
		LAFKA_PROMO: { promoKey: 'spring', dismissKey: 'lafka_bogo_dismissed_spring', dismissDays: 7, ...promo },
		localStorage,
	} );
	const proto = Object.getPrototypeOf( p.document.createElement( 'a' ) );
	let owner = proto;
	while ( owner && ! Object.prototype.hasOwnProperty.call( owner, 'focus' ) ) {
		owner = Object.getPrototypeOf( owner );
	}
	( owner || proto ).focus = function () {
		focused.push( this );
	};
	return { ...p, writes, focused, banner: p.document.getElementById( 'lafka-bogo-banner' ) };
}

test( 'close hides the in-flow banner and remembers it under the shared key', () => {
	const p = page();
	assert.equal( p.banner.hasAttribute( 'hidden' ), false, 'Rendered visible: nothing is revealed after load.' );

	p.click( '.lafka-bogo-close' );

	assert.equal( p.banner.hidden, true );
	assert.ok( p.writes.lafka_bogo_dismissed_spring, 'The key the pre-paint check reads.' );
	assert.ok( p.document.documentElement.classList.contains( 'lafka-bogo-dismissed' ) );
} );

test( 'a keyboard user closing the banner lands on the next control after it', () => {
	const p = page();
	const close = p.document.querySelector( '.lafka-bogo-close' );
	Object.defineProperty( p.document, 'activeElement', { configurable: true, get: () => close } );

	p.click( '.lafka-bogo-close' );

	assert.equal( p.focused.length, 1 );
	assert.equal( p.focused[ 0 ].className, 'logo', 'Not the skip link before the banner, not <body>.' );
} );

test( 'a recent dismissal keeps the banner hidden', () => {
	const p = page( { lafka_bogo_dismissed_spring: String( Date.now() - 1000 ) } );

	assert.equal( p.banner.hidden, true );
} );

test( 'an expired dismissal shows the banner again', () => {
	const p = page( { lafka_bogo_dismissed_spring: String( Date.now() - 8 * 86400000 ) } );

	assert.equal( p.banner.hasAttribute( 'hidden' ), false );
} );
