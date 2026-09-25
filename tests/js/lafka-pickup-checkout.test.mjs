/**
 * incl/checkout/assets/js/lafka-pickup-checkout.js — the classic checkout
 * hides the billing address on a pickup order paid offline and brings it back
 * (with its required markers) for delivery or a card payment.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'incl/checkout/assets/js/lafka-pickup-checkout.js';

const CONFIG = {
	enabled: true,
	orderType: '',
	pickupMethods: [ 'local_pickup', 'pickup_location' ],
	addressGateways: [ 'card_gateway' ],
	fields: [
		{ id: 'billing_address_1', required: true },
		{ id: 'billing_address_2', required: false },
	],
	i18n: { required: 'required', optional: '(optional)', addAddress: 'Want delivery? Add your address' },
};

function page( { shipping = 'local_pickup:9', gateway = 'cod', config = CONFIG } = {} ) {
	return loadScript(
		SCRIPT,
		`<form class="checkout">
			<p id="billing_first_name_field" class="form-row validate-required"><label>First name <abbr class="required">*</abbr></label><input id="billing_first_name"></p>
			<p id="billing_address_1_field" class="form-row validate-required lafka-pickup-slim-field"><label>Street address <abbr class="required">*</abbr></label><input id="billing_address_1"></p>
			<p id="billing_address_2_field" class="form-row lafka-pickup-slim-field"><label>Apartment <span class="optional">(optional)</span></label><input id="billing_address_2"></p>
			<ul>
				<li><input type="radio" name="shipping_method[0]" id="pickup" value="local_pickup:9" ${ 'local_pickup:9' === shipping ? 'checked' : '' }></li>
				<li><input type="radio" name="shipping_method[0]" id="delivery" value="distance_rate:8" ${ 'distance_rate:8' === shipping ? 'checked' : '' }></li>
			</ul>
			<input type="radio" name="payment_method" id="cod" value="cod" ${ 'cod' === gateway ? 'checked' : '' }>
			<input type="radio" name="payment_method" id="card" value="card_gateway" ${ 'card_gateway' === gateway ? 'checked' : '' }>
		</form>`,
		{ lafkaPickupCheckout: config }
	);
}

const row = ( p, id ) => p.document.getElementById( id + '_field' );
const hidden = ( p, id ) => 'none' === row( p, id ).style.display;
const mark = ( p, id ) => row( p, id ).querySelector( 'label abbr.required, label span.optional' ).textContent;

// linkedom tracks checkedness through the `checked` attribute (what :checked
// matches), so a click on a radio is modelled by moving the attribute.
function choose( p, id ) {
	for ( const input of p.document.querySelectorAll( `input[name="${ p.document.getElementById( id ).getAttribute( 'name' ) }"]` ) ) {
		if ( input.id === id ) {
			input.setAttribute( 'checked', '' );
		} else {
			input.removeAttribute( 'checked' );
		}
	}
	p.change( '#' + id );
}

test( 'pickup paid in cash hides the address and marks it optional', () => {
	const p = page();

	assert.ok( hidden( p, 'billing_address_1' ) );
	assert.ok( hidden( p, 'billing_address_2' ) );
	assert.ok( ! row( p, 'billing_address_1' ).classList.contains( 'validate-required' ) );
	assert.equal( mark( p, 'billing_address_1' ), '(optional)' );
	assert.ok( ! hidden( p, 'billing_first_name' ), 'Name, phone and email are never touched.' );
} );

test( 'switching to delivery brings the address back as required', () => {
	const p = page();

	choose( p, 'delivery' );

	assert.ok( ! hidden( p, 'billing_address_1' ) );
	assert.ok( row( p, 'billing_address_1' ).classList.contains( 'validate-required' ) );
	assert.equal( mark( p, 'billing_address_1' ), '*' );
	assert.equal( mark( p, 'billing_address_2' ), '(optional)', 'An optional field stays optional.' );
	assert.equal( p.document.querySelector( '.lafka-pickup-address-toggle' ), null );
} );

test( 'a gateway that verifies the address keeps it on pickup', () => {
	const p = page( { gateway: 'card_gateway' } );
	assert.ok( ! hidden( p, 'billing_address_1' ) );
	assert.equal( mark( p, 'billing_address_1' ), '*' );

	choose( p, 'cod' );
	assert.ok( hidden( p, 'billing_address_1' ) );
} );

test( 'a pickup customer can reveal the address to get a delivery quote', () => {
	const p = page();

	p.click( '.lafka-pickup-address-toggle__button' );

	assert.ok( ! hidden( p, 'billing_address_1' ) );
	assert.equal( mark( p, 'billing_address_1' ), '(optional)', 'Still a pickup order until delivery is chosen.' );
	assert.equal( p.document.querySelector( '.lafka-pickup-address-toggle' ), null );
} );

test( 'the Lafka order type overrides the shipping method', () => {
	const p = page( { shipping: 'distance_rate:8', config: { ...CONFIG, orderType: 'pickup' } } );

	assert.ok( hidden( p, 'billing_address_1' ) );
} );

test( 'without config nothing changes', () => {
	const p = page( { config: null } );

	assert.ok( ! hidden( p, 'billing_address_1' ) );
	assert.equal( mark( p, 'billing_address_1' ), '*' );
} );
