/**
 * Behavioural tests for assets/js/lafka-dl-client.js: the script runs in a
 * VM against a tiny DOM double and the test asserts what lands in dataLayer.
 *
 * Run: npm test (node --test, no dependencies).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const SOURCE = readFileSync( new URL( '../../assets/js/lafka-dl-client.js', import.meta.url ), 'utf8' );

/** Minimal element: attributes, `hidden`, parent/children, closest + querySelectorAll for `tag[attr]` selectors. */
class El {
	constructor( tag, attrs = {}, children = [] ) {
		this.tag = tag;
		this.attrs = attrs;
		this.hidden = 'hidden' in attrs;
		this.value = attrs.value || '';
		this.name = attrs.name || '';
		this.parent = null;
		this.children = children;
		children.forEach( ( c ) => ( c.parent = this ) );
	}
	getAttribute( name ) {
		return name in this.attrs ? String( this.attrs[ name ] ) : null;
	}
	matches( selector ) {
		const m = /^([a-z]*)\[([^\]=]+)\]$/.exec( selector );
		if ( ! m ) {
			throw new Error( 'Unsupported selector ' + selector );
		}
		if ( m[ 1 ] && m[ 1 ] !== this.tag ) {
			return false;
		}
		return 'hidden' === m[ 2 ] ? this.hidden : m[ 2 ] in this.attrs;
	}
	closest( selector ) {
		for ( let el = this; el; el = el.parent ) {
			if ( el.matches && el.matches( selector ) ) {
				return el;
			}
		}
		return null;
	}
	querySelectorAll( selector ) {
		const out = [];
		const walk = ( el ) => el.children.forEach( ( c ) => {
			if ( c.matches( selector ) ) {
				out.push( c );
			}
			walk( c );
		} );
		walk( this );
		return out;
	}
	querySelector( selector ) {
		return this.querySelectorAll( selector )[ 0 ] || null;
	}
}

/** Load the client against a page body; returns the dataLayer + helpers. */
function load( body, extraWindow = {} ) {
	const listeners = {};
	const root = new El( 'body', {}, body );
	const document = {
		addEventListener: ( type, fn ) => ( listeners[ type ] = listeners[ type ] || [] ).push( fn ),
		querySelector: ( s ) => root.querySelector( s ),
		querySelectorAll: ( s ) => root.querySelectorAll( s ),
	};
	const timers = [];
	const window = { ...extraWindow };
	vm.runInNewContext( SOURCE, {
		window,
		document,
		setTimeout: ( fn ) => timers.push( fn ),
		clearTimeout: () => timers.splice( 0 ),
	} );
	return {
		// Plain copy: objects built inside the VM have foreign prototypes,
		// which deepStrictEqual would reject.
		get dataLayer() {
			return JSON.parse( JSON.stringify( window.dataLayer ) );
		},
		fire: ( type, target ) => ( listeners[ type ] || [] ).forEach( ( fn ) => fn( { target } ) ),
		flushTimers: () => timers.splice( 0 ).forEach( ( fn ) => fn() ),
	};
}

const card = ( id, attrs = {} ) => new El( 'li', attrs, [ new El( 'a', { 'data-lafka-item-id': id } ) ] );

test( 'typing in the menu search field pushes a search event with the visible result count', () => {
	const input = new El( 'input', { 'data-lafka-menu-search-input': '' } );
	const form = new El( 'form', { 'data-lafka-menu-search': '' }, [ input ] );
	const page = load( [ form, card( '1' ), card( '2' ), card( '3', { hidden: '' } ) ] );

	input.value = 'pizza';
	page.fire( 'input', input );
	page.flushTimers();

	assert.deepEqual( page.dataLayer, [ { event: 'search', search_term: 'pizza', results_count: 2 } ] );
} );

test( 'terms shorter than two characters are not reported', () => {
	const input = new El( 'input', { 'data-lafka-menu-search-input': '' } );
	const page = load( [ input ] );

	input.value = 'p';
	page.fire( 'input', input );
	page.flushTimers();

	assert.deepEqual( page.dataLayer, [] );
} );

test( 'shipping and payment choices carry the localized checkout items', () => {
	const items = [ { item_id: '10', item_name: 'Margherita', price: 12.99, quantity: 1 } ];
	const page = load( [], { lafkaDlCheckout: { currency: 'USD', value: 12.99, items } } );


	page.fire( 'change', new El( 'input', { name: 'shipping_method[0]', value: 'flat_rate:1' } ) );
	page.fire( 'change', new El( 'input', { name: 'payment_method', value: 'cod' } ) );

	const events = page.dataLayer.filter( ( e ) => e.event );
	assert.deepEqual( events.map( ( e ) => e.event ), [ 'add_shipping_info', 'add_payment_info' ] );
	for ( const e of events ) {
		assert.deepEqual( e.ecommerce.items, items );
		assert.equal( e.ecommerce.currency, 'USD' );
		assert.equal( e.ecommerce.value, 12.99 );
	}
	assert.equal( events[ 0 ].ecommerce.shipping_tier, 'flat_rate:1' );
	assert.equal( events[ 1 ].ecommerce.payment_type, 'cod' );
} );

test( 'a product link click pushes select_item', () => {
	const link = new El( 'a', { 'data-lafka-item-id': '15', 'data-lafka-item-name': 'Pepperoni', 'data-lafka-list-name': 'Menu', 'data-lafka-item-price': '11.5' } );
	const page = load( [ link ] );

	page.fire( 'click', link );

	const event = page.dataLayer.find( ( e ) => 'select_item' === e.event );
	assert.equal( event.ecommerce.item_list_name, 'Menu' );
	assert.deepEqual( event.ecommerce.items[ 0 ], { item_id: '15', item_name: 'Pepperoni', item_category: '', price: 11.5, quantity: 1 } );
} );
