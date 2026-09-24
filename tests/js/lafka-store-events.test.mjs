/**
 * assets/js/lafka-store-events.js — restaurant funnel signals.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript, events } from './support/dom.mjs';

const SCRIPT = 'assets/js/lafka-store-events.js';

test( 'order-channel and fulfilment choices are reported with their source', () => {
	const p = loadScript(
		SCRIPT,
		`<a id="direct" data-lafka-order-channel="direct" data-lafka-order-source="hero"><span id="inner">Order</span></a>
		 <a id="app" data-lafka-order-channel="ubereats">App</a>
		 <button id="pickup" data-lafka-fulfilment="pickup" data-lafka-fulfilment-source="menu_tabs">Pickup</button>`
	);

	p.click( '#inner' );
	p.click( '#app' );
	p.click( '#pickup' );

	assert.deepEqual( events( p.dataLayer ), [
		{ event: 'order_channel_click', order_channel: 'direct', order_source: 'hero' },
		{ event: 'order_channel_click', order_channel: 'ubereats', order_source: 'unknown' },
		{ event: 'select_fulfilment', fulfilment_method: 'pickup', fulfilment_source: 'menu_tabs' },
	] );
} );

test( 'add-on choices report the option and its price', () => {
	const p = loadScript(
		SCRIPT,
		`<div class="product-addon" data-product-id="10" data-addon-name="Extra Toppings">
			<input id="cheese" type="checkbox" value="demo-topping-cheese" data-price="1.5">
		 </div>
		 <input id="loose" type="checkbox" value="x">`
	);

	p.change( '#cheese' );
	p.change( '#loose' );

	assert.deepEqual( events( p.dataLayer ), [
		{ event: 'select_addon', product_id: '10', addon_name: 'Extra Toppings', addon_value: 'demo-topping-cheese', price_delta: 1.5 },
	] );
} );

test( 'the closed-store card reports one view', () => {
	let observed = null;
	class FakeObserver {
		constructor( cb ) {
			this.cb = cb;
		}
		observe( el ) {
			observed = { cb: this.cb, el, io: this };
		}
		unobserve() {
			observed.unobserved = true;
		}
	}
	const p = loadScript( SCRIPT, '<div class="lafka-store-closed-card" data-lafka-closed-context="pdp"></div>', { IntersectionObserver: FakeObserver } );

	observed.cb( [ { isIntersecting: true, target: observed.el } ] );

	assert.deepEqual( events( p.dataLayer ), [ { event: 'store_closed_view', closed_context: 'pdp' } ] );
	assert.equal( observed.unobserved, true );
} );
