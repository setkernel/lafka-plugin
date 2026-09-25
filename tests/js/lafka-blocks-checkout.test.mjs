/**
 * incl/checkout/assets/js/lafka-blocks-checkout.js — block cart/checkout
 * components, driven through stub wp/wc globals (no React needed: the stub
 * createElement returns a plain tree we can walk and call).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const SOURCE = readFileSync( new URL( '../../incl/checkout/assets/js/lafka-blocks-checkout.js', import.meta.url ), 'utf8' );

function load( settings = {} ) {
	const plugins = {};
	const el = ( type, props, ...children ) => ( { type, props: props || {}, children } );
	const window = {
		wp: {
			element: { createElement: el, useState: ( v ) => [ v, () => {} ], useEffect: () => {} },
			plugins: { registerPlugin: ( name, cfg ) => ( plugins[ name ] = cfg ) },
		},
		wc: {
			blocksCheckout: {
				ExperimentalOrderMeta: 'OrderMeta',
				ExperimentalOrderShippingPackages: 'ShippingPackages',
				extensionCartUpdate: () => {},
			},
			wcSettings: { getSetting: () => settings },
		},
	};
	vm.runInNewContext( SOURCE, { window } );
	return plugins;
}

/** Render a registered plugin's slot fill with the given cart extensions. */
function renderFill( plugin, extensions ) {
	const slot = plugin.render();
	const component = slot.children[ 0 ];
	return { slot: slot.type, out: component.type( { extensions } ) };
}

test( 'the delivery-address notice is registered on the block cart and checkout shipping slot', () => {
	const plugins = load();

	assert.equal( plugins[ 'lafka-delivery-quote-cart' ].scope, 'woocommerce-cart' );
	assert.equal( plugins[ 'lafka-delivery-quote-checkout' ].scope, 'woocommerce-checkout' );
	assert.equal( renderFill( plugins[ 'lafka-delivery-quote-cart' ], {} ).slot, 'ShippingPackages' );
} );

test( 'the notice shows the server message only while delivery is withheld', () => {
	const plugin = load()[ 'lafka-delivery-quote-checkout' ];

	const shown = renderFill( plugin, {
		lafka: { delivery_address_required: true, delivery_address_message: 'Enter your street address to see the delivery cost.' },
	} ).out;
	assert.equal( shown.type, 'p' );
	assert.equal( shown.props.role, 'status' );
	assert.deepEqual( shown.children, [ 'Enter your street address to see the delivery cost.' ] );

	assert.equal( renderFill( plugin, { lafka: { delivery_address_required: false, delivery_address_message: '' } } ).out, null );
	assert.equal( renderFill( plugin, undefined ).out, null );
} );
