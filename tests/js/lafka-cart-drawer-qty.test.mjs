/**
 * assets/js/lafka-cart-drawer-qty.js — the drawer's − / + stepper posts to
 * wc-ajax=lafka_cart_set_qty, swaps in WooCommerce's refreshed fragments,
 * keeps focus on the stepper and says the new quantity out loud.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'assets/js/lafka-cart-drawer-qty.js';

const CONFIG = {
	url: '/?wc-ajax=lafka_cart_set_qty',
	refreshUrl: '/?wc-ajax=get_refreshed_fragments',
	i18n: { quantity: '%1$s: %2$s', removed: '%s removed from your order', failed: 'Sorry, that did not work.' },
};

function row( qty, nonce = 'n1', max = false ) {
	return `<li class="lafka-cart-drawer__item" data-cart-key="k1">
		<div class="lafka-cart-drawer__stepper" role="group" data-lafka-qty data-cart-key="k1" data-name="Wings" data-nonce="${ nonce }">
			<button type="button" data-lafka-qty-step="-1"${ qty <= 1 ? ' disabled' : '' }>−</button>
			<output class="lafka-cart-drawer__qty">${ qty }</output>
			<button type="button" data-lafka-qty-step="1"${ max ? ' disabled' : '' }>+</button>
		</div>
	</li>`;
}

const items = ( qty, nonce, max ) => `<ul class="lafka-cart-drawer__items">${ row( qty, nonce, max ) }</ul>`;

function page( responses, extra = {} ) {
	const calls = [];
	const triggered = [];
	const stored = {};
	const focused = [];
	const p = loadScript( SCRIPT, items( 2 ) + '<div class="lafka-cart-drawer__total">$20</div>', {
		lafkaCartQty: CONFIG,
		fetch: ( url, options ) => {
			calls.push( { url, body: options.body, method: options.method } );
			const next = responses.shift();
			return Promise.resolve( { ok: true, json: () => Promise.resolve( next ) } );
		},
		jQuery: () => ( { trigger: ( name ) => triggered.push( name ) } ),
		wc_cart_fragments_params: { fragment_name: 'wc_fragments_x', cart_hash_key: 'wc_cart_hash_x' },
		sessionStorage: { setItem: ( key, value ) => ( stored[ key ] = value ) },
		...extra,
	} );
	// Record focus on any element (linkedom has no activeElement).
	const proto = Object.getPrototypeOf( p.document.createElement( 'button' ) );
	let owner = proto;
	while ( owner && ! Object.prototype.hasOwnProperty.call( owner, 'focus' ) ) {
		owner = Object.getPrototypeOf( owner );
	}
	( owner || proto ).focus = function () {
		focused.push( this );
	};
	return { ...p, calls, triggered, stored, focused };
}

const settle = async () => {
	for ( let i = 0; i < 6; i++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
};

const liveText = ( p ) => {
	const node = p.document.querySelector( '[role="status"][aria-live="polite"]' );
	return node ? node.textContent : '';
};

test( 'plus posts the new quantity and swaps in the refreshed rows', async () => {
	const p = page( [ { fragments: { 'ul.lafka-cart-drawer__items': items( 3, 'n2' ), 'div.lafka-cart-drawer__total': '<div class="lafka-cart-drawer__total">$30</div>' }, cart_hash: 'h3' } ] );

	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.equal( p.calls.length, 1 );
	assert.equal( p.calls[ 0 ].url, CONFIG.url );
	assert.equal( p.calls[ 0 ].method, 'POST' );
	assert.equal( p.calls[ 0 ].body, 'cart_item_key=k1&quantity=3&nonce=n1' );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__qty' ).textContent, '3' );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__total' ).textContent, '$30' );
	assert.equal( liveText( p ), 'Wings: 3' );
	assert.deepEqual( p.triggered, [ 'wc_fragments_refreshed' ] );
	assert.equal( p.stored.wc_cart_hash_x, 'h3', "WooCommerce's fragment cache follows the change." );
	assert.equal( p.focused.at( -1 ).getAttribute( 'data-lafka-qty-step' ), '1', 'Focus stays on the button that was pressed.' );
} );

test( 'minus posts one less', async () => {
	const p = page( [ { fragments: { 'ul.lafka-cart-drawer__items': items( 1, 'n2' ) }, cart_hash: 'h1' } ] );

	p.click( '[data-lafka-qty-step="-1"]' );
	await settle();

	assert.equal( p.calls[ 0 ].body, 'cart_item_key=k1&quantity=1&nonce=n1' );
	assert.equal( liveText( p ), 'Wings: 1' );
	assert.equal( p.focused.at( -1 ).getAttribute( 'data-lafka-qty-step' ), '1', 'The pressed minus is now disabled, so focus moves to plus.' );
} );

test( 'an expired nonce refreshes the rows once and retries with the fresh nonce', async () => {
	const p = page( [
		{ success: false, data: { code: 'invalid_nonce', message: 'Your session has expired.' } },
		{ fragments: { 'ul.lafka-cart-drawer__items': items( 2, 'fresh' ) }, cart_hash: 'h2' },
		{ fragments: { 'ul.lafka-cart-drawer__items': items( 3, 'fresh' ) }, cart_hash: 'h3' },
	] );

	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.deepEqual( p.calls.map( ( c ) => c.url ), [ CONFIG.url, CONFIG.refreshUrl, CONFIG.url ] );
	assert.equal( p.calls[ 2 ].body, 'cart_item_key=k1&quantity=3&nonce=fresh' );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__qty' ).textContent, '3' );
} );

test( 'a refused change leaves the row and says why', async () => {
	const p = page( [ { success: false, data: { code: 'not_enough_stock', message: 'Only 2 are available.' } } ] );

	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.equal( p.calls.length, 1 );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__qty' ).textContent, '2' );
	assert.equal( liveText( p ), 'Only 2 are available.' );
	assert.equal( p.document.querySelector( '[data-lafka-qty]' ).hasAttribute( 'aria-busy' ), false );
} );

test( 'a disabled step does nothing', async () => {
	const p = page( [] );
	p.document.querySelector( '[data-lafka-qty-step="1"]' ).setAttribute( 'disabled', '' );

	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.equal( p.calls.length, 0 );
} );

test( 'without its config the script stays inert', async () => {
	const p = page( [], { lafkaCartQty: undefined } );

	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.equal( p.calls.length, 0 );
} );

test( 'rapid taps are all counted: the cart ends on the last quantity asked for (O-17)', async () => {
	const p = page( [
		{ fragments: { 'ul.lafka-cart-drawer__items': items( 3, 'n2' ) }, cart_hash: 'h3' },
		{ fragments: { 'ul.lafka-cart-drawer__items': items( 5, 'n3' ) }, cart_hash: 'h5' },
	] );

	p.click( '[data-lafka-qty-step="1"]' );
	p.click( '[data-lafka-qty-step="1"]' );
	p.click( '[data-lafka-qty-step="1"]' );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__qty' ).textContent, '5', 'Each tap shows at once.' );
	await settle();

	assert.deepEqual( p.calls.map( ( c ) => c.body ), [ 'cart_item_key=k1&quantity=3&nonce=n1', 'cart_item_key=k1&quantity=5&nonce=n2' ], 'One request at a time; taps made meanwhile are coalesced.' );
	assert.equal( p.document.querySelector( '.lafka-cart-drawer__qty' ).textContent, '5' );
	assert.equal( liveText( p ), 'Wings: 5' );
} );

test( 'taps never go below one or past the purchase limit', async () => {
	const p = page( [ { fragments: { 'ul.lafka-cart-drawer__items': items( 3, 'n2', true ) }, cart_hash: 'h3' } ] );
	p.document.querySelector( '[data-lafka-qty]' ).setAttribute( 'data-max', '3' );

	p.click( '[data-lafka-qty-step="1"]' );
	p.click( '[data-lafka-qty-step="1"]' );
	await settle();

	assert.deepEqual( p.calls.map( ( c ) => c.body ), [ 'cart_item_key=k1&quantity=3&nonce=n1' ] );
	assert.equal( p.document.querySelector( '[data-lafka-qty-step="1"]' ).hasAttribute( 'disabled' ), true );
} );
