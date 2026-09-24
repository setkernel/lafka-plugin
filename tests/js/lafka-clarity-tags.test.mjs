/**
 * assets/js/lafka-clarity-tags.js — mirrors dataLayer signals into Clarity
 * tags without breaking the dataLayer for GTM.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'assets/js/lafka-clarity-tags.js';

function withClarity( dataLayer ) {
	const calls = [];
	const p = loadScript( SCRIPT, '', { dataLayer, clarity: ( ...args ) => calls.push( args ) } );
	return { p, calls };
}

test( 'events pushed before the script loaded are replayed into Clarity tags', () => {
	const { calls } = withClarity( [
		{ event: 'page_context', page_type: 'menu', fulfilment_method: 'pickup', store_open: false, cart_value_band: 'under_25', customer_is_repeat: true },
	] );

	assert.deepEqual( calls, [
		[ 'set', 'page_type', 'menu' ],
		[ 'set', 'fulfilment_method', 'pickup' ],
		[ 'set', 'store_open', 'closed' ],
		[ 'set', 'cart_value_band', 'under_25' ],
		[ 'set', 'repeat_customer', 'yes' ],
	] );
} );

test( 'later pushes still reach the dataLayer and set funnel tags', () => {
	const dataLayer = [];
	const { p, calls } = withClarity( dataLayer );

	const length = p.window.dataLayer.push( { event: 'view_item' }, { event: 'purchase', ecommerce: { transaction_id: 77 } } );

	assert.equal( length, 2, 'push() must still return the new length (GTM relies on the real push).' );
	assert.deepEqual( dataLayer.map( ( e ) => e.event ), [ 'view_item', 'purchase' ] );
	assert.deepEqual( calls, [
		[ 'set', 'funnel_step', 'pdp' ],
		[ 'set', 'funnel_step', 'purchase' ],
		[ 'identify', 'order_77' ],
	] );
} );

test( 'without Clarity the dataLayer keeps working', () => {
	const dataLayer = [];
	const p = loadScript( SCRIPT, '', { dataLayer } );

	p.window.dataLayer.push( { event: 'add_to_cart' } );

	assert.equal( dataLayer.length, 1 );
} );
