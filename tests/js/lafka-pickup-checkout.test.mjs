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

/**
 * A stand-in for jQuery( document.body ): records bound handlers and the
 * events the script triggers (update_checkout).
 */
function fakeJQuery() {
	const handlers = {};
	const triggered = [];
	const jq = () => ( {
		on( events, fn ) {
			events.split( ' ' ).forEach( ( e ) => ( handlers[ e ] = handlers[ e ] || [] ).push( fn ) );
			return this;
		},
		trigger( e ) {
			triggered.push( e );
		},
	} );
	return { jq, handlers, triggered, fire: ( e ) => ( handlers[ e ] || [] ).forEach( ( fn ) => fn() ) };
}

function page( { shipping = 'local_pickup:9', gateway = 'cod', config = CONFIG, rates = [ 'local_pickup:9', 'distance_rate:8' ], jquery = null } = {} ) {
	const radios =
		rates.length === 1
			? `<input type="hidden" name="shipping_method[0]" id="only" value="${ rates[ 0 ] }">`
			: rates
					.map( ( r ) => `<li><input type="radio" name="shipping_method[0]" id="${ r.startsWith( 'local' ) ? 'pickup' : 'delivery' }" value="${ r }" ${ r === shipping ? 'checked' : '' }></li>` )
					.join( '' );
	const globals = { lafkaPickupCheckout: config, setTimeout: ( fn ) => fn(), clearTimeout: () => {} };
	if ( jquery ) {
		globals.jQuery = jquery.jq;
	}
	return loadScript(
		SCRIPT,
		`<form class="checkout">
			<p id="billing_first_name_field" class="form-row validate-required"><label>First name <abbr class="required">*</abbr></label><input id="billing_first_name" name="billing_first_name"></p>
			<p id="billing_address_1_field" class="form-row validate-required lafka-pickup-slim-field"><label>Street address <abbr class="required">*</abbr></label><input id="billing_address_1" name="billing_address_1"></p>
			<p id="billing_address_2_field" class="form-row lafka-pickup-slim-field"><label>Apartment <span class="optional">(optional)</span></label><input id="billing_address_2" name="billing_address_2"></p>
			<ul id="shipping_method">${ radios }</ul>
			<input type="radio" name="payment_method" id="cod" value="cod" ${ 'cod' === gateway ? 'checked' : '' }>
			<input type="radio" name="payment_method" id="card" value="card_gateway" ${ 'card_gateway' === gateway ? 'checked' : '' }>
		</form>`,
		globals
	);
}

const row = ( p, id ) => p.document.getElementById( id + '_field' );
const hidden = ( p, id ) => 'none' === row( p, id ).style.display;
// Every required/optional marker in the label, joined — exactly one is expected.
const mark = ( p, id ) => [ ...row( p, id ).querySelectorAll( 'label .required, label .optional' ) ].map( ( m ) => m.textContent ).join( '' );

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

/* ---------------------------------------------------------------------- *
 *  Click-test regressions (classic checkout, 375px)
 * ---------------------------------------------------------------------- */

test( 'a revealed field never shows both the star and "(optional)"', () => {
	const jquery = fakeJQuery();
	const p = page( { jquery, rates: [ 'local_pickup:9' ] } );
	p.click( '.lafka-pickup-address-toggle__button' );

	// WooCommerce's address-i18n.js re-marks locale-required fields with the
	// WC 9 marker <span class="required" aria-hidden="true">, then fires
	// country_to_state_changed.
	const label = row( p, 'billing_address_1' ).querySelector( 'label' );
	label.insertAdjacentHTML( 'beforeend', '<span class="required" aria-hidden="true">*</span>' );
	jquery.fire( 'country_to_state_changed' );

	assert.equal( mark( p, 'billing_address_1' ), '(optional)' );
} );

test( 'a required field gets exactly the WooCommerce star', () => {
	const p = page( { shipping: 'distance_rate:8' } );

	assert.equal( mark( p, 'billing_address_1' ), '*' );
	const star = row( p, 'billing_address_1' ).querySelector( 'label .required' );
	assert.equal( star.getAttribute( 'aria-hidden' ), 'true' );
} );

test( 'an address filled without keystrokes (autofill, paste) still refreshes the rates', () => {
	const jquery = fakeJQuery();
	const p = page( { jquery, rates: [ 'local_pickup:9' ] } );
	p.click( '.lafka-pickup-address-toggle__button' );

	p.change( '#billing_address_1' );

	assert.deepEqual( jquery.triggered, [ 'update_checkout' ] );
} );

test( '"Want delivery?" picks the delivery rate once it appears, only once', () => {
	const jquery = fakeJQuery();
	const p = page( { jquery, rates: [ 'local_pickup:9' ] } );
	p.click( '.lafka-pickup-address-toggle__button' );

	// The order review comes back with a delivery rate for the new address.
	p.document.getElementById( 'shipping_method' ).innerHTML =
		'<li><input type="radio" name="shipping_method[0]" id="pickup" value="local_pickup:9" checked></li>' +
		'<li><input type="radio" name="shipping_method[0]" id="delivery" value="distance_rate:8"></li>';
	let changed = 0;
	p.document.getElementById( 'delivery' ).addEventListener( 'change', () => changed++ );
	jquery.fire( 'updated_checkout' );

	assert.ok( p.document.getElementById( 'delivery' ).hasAttribute( 'checked' ) );
	assert.ok( ! p.document.getElementById( 'pickup' ).hasAttribute( 'checked' ) );
	assert.equal( changed, 1, 'WooCommerce hears the change and refreshes totals.' );
	assert.equal( mark( p, 'billing_address_1' ), '*', 'Delivery needs the address.' );

	// The customer switches back to pickup: no second auto-pick.
	choose( p, 'pickup' );
	jquery.fire( 'updated_checkout' );
	assert.ok( p.document.getElementById( 'pickup' ).hasAttribute( 'checked' ) );
} );

test( 'without "Want delivery?" a pickup customer is never switched to delivery', () => {
	const jquery = fakeJQuery();
	const p = page( { jquery } );

	jquery.fire( 'updated_checkout' );

	assert.ok( p.document.getElementById( 'pickup' ).hasAttribute( 'checked' ) );
	assert.ok( hidden( p, 'billing_address_1' ) );
} );

// Regression (post-GX order-path merge, live click-test): a $25.99 cart under a
// $30 delivery minimum offered "Want delivery? Add your address"; the customer
// typed a full address, the delivery rate never came (the minimum removes it
// server-side) and the order went through as pickup. The server now says when
// delivery is impossible (addressToggle: false) and the promise is not shown.
test( 'no "Want delivery?" when the order cannot be delivered (under the delivery minimum)', () => {
	const p = page( { config: { ...CONFIG, addressToggle: false }, rates: [ 'local_pickup:9' ] } );

	assert.ok( hidden( p, 'billing_address_1' ), 'Pickup still hides the address.' );
	assert.equal( p.document.querySelector( '.lafka-pickup-address-toggle' ), null );
} );

test( 'above the minimum the promise stands: reveal, address, the delivery rate is chosen', () => {
	const jquery = fakeJQuery();
	const p = page( { config: { ...CONFIG, addressToggle: true }, jquery } );

	p.click( '.lafka-pickup-address-toggle__button' );
	assert.ok( ! hidden( p, 'billing_address_1' ) );
	// WooCommerce refreshes the review with the delivery rate for the address.
	jquery.fire( 'updated_checkout' );

	assert.ok( p.document.getElementById( 'delivery' ).hasAttribute( 'checked' ), 'Delivery is chosen for the customer who asked for it.' );
	assert.equal( mark( p, 'billing_address_1' ), '*', 'The street is required again.' );
} );
