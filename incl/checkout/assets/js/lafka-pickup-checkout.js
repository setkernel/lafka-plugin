/**
 * Classic checkout: short form for pickup orders (Lafka_Pickup_Checkout).
 *
 * The server decides requiredness per request; this keeps the form in step
 * while the customer changes the shipping or payment method: on a pickup order
 * paid with a method that does not check the billing address, the tagged
 * address rows are hidden and marked optional; otherwise they are shown with
 * their original required markers. An "add your address" link lets a pickup
 * customer reveal the address to get a delivery quote.
 *
 * Config: window.lafkaPickupCheckout (printed after the checkout form).
 */
( function () {
	'use strict';

	var doc = window.document;
	var wantsAddress = false;
	var TOGGLE_CLASS = 'lafka-pickup-address-toggle';

	function config() {
		return window.lafkaPickupCheckout || null;
	}

	function methodId( value ) {
		return String( value || '' ).split( ':' )[ 0 ];
	}

	function chosenShippingMethods() {
		// A chosen radio per package, or the hidden input WooCommerce prints
		// when a package has a single rate.
		var out = [];
		var inputs = doc.querySelectorAll(
			'input[name^="shipping_method["]:checked, input[type="hidden"][name^="shipping_method["]'
		);
		for ( var i = 0; i < inputs.length; i++ ) {
			out.push( inputs[ i ].value );
		}
		return out;
	}

	function isPickup( cfg ) {
		if ( 'pickup' === cfg.orderType ) {
			return true;
		}
		if ( 'delivery' === cfg.orderType ) {
			return false;
		}
		var chosen = chosenShippingMethods();
		if ( ! chosen.length ) {
			return false;
		}
		for ( var i = 0; i < chosen.length; i++ ) {
			if ( -1 === ( cfg.pickupMethods || [] ).indexOf( methodId( chosen[ i ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	function gatewayNeedsAddress( cfg ) {
		// No payment step (free order): nothing to verify.
		if ( ! doc.querySelector( 'input[name="payment_method"]' ) ) {
			return false;
		}
		var checked = doc.querySelector( 'input[name="payment_method"]:checked' );
		if ( ! checked ) {
			return true;
		}
		return -1 !== ( cfg.addressGateways || [] ).indexOf( checked.value );
	}

	function setRequired( row, required, cfg ) {
		if ( required ) {
			row.classList.add( 'validate-required' );
		} else {
			row.classList.remove( 'validate-required', 'woocommerce-invalid', 'woocommerce-invalid-required-field' );
		}
		var label = row.querySelector( 'label' );
		if ( ! label ) {
			return;
		}
		// Every marker WooCommerce may have printed or added (the server
		// render, and address-i18n.js on country changes): <abbr class=
		// "required"> (older), <span class="required" aria-hidden="true">
		// (WC 9+), and <span class="optional">. Exactly one survives.
		var marks = label.querySelectorAll( '.required, .optional' );
		for ( var i = 0; i < marks.length; i++ ) {
			marks[ i ].parentNode.removeChild( marks[ i ] );
		}
		label.classList.toggle( 'required_field', required );
		var mark = doc.createElement( 'span' );
		if ( required ) {
			mark.className = 'required';
			mark.setAttribute( 'aria-hidden', 'true' );
			mark.textContent = '*';
		} else {
			mark.className = 'optional';
			mark.textContent = ( cfg.i18n && cfg.i18n.optional ) || '(optional)';
		}
		label.appendChild( mark );
	}

	function rows( cfg ) {
		var out = [];
		( cfg.fields || [] ).forEach( function ( field ) {
			var row = doc.getElementById( field.id + '_field' );
			if ( row ) {
				out.push( { row: row, required: !! field.required } );
			}
		} );
		return out;
	}

	function syncToggle( show, list, cfg ) {
		var existing = doc.querySelector( '.' + TOGGLE_CLASS );
		// No "Want delivery?" when this order cannot be delivered (e.g. under the
		// delivery minimum): the address would never bring a delivery rate.
		if ( ! show || false === cfg.addressToggle ) {
			if ( existing ) {
				existing.parentNode.removeChild( existing );
			}
			return;
		}
		if ( existing || ! list.length ) {
			return;
		}
		var wrap = doc.createElement( 'p' );
		wrap.className = 'form-row form-row-wide ' + TOGGLE_CLASS;
		var button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = TOGGLE_CLASS + '__button';
		button.textContent = ( cfg.i18n && cfg.i18n.addAddress ) || 'Want delivery? Add your address';
		wrap.appendChild( button );
		list[ 0 ].row.parentNode.insertBefore( wrap, list[ 0 ].row );
	}

	function update() {
		var cfg = config();
		if ( ! cfg || ! cfg.enabled ) {
			return;
		}
		var slim = isPickup( cfg ) && ! gatewayNeedsAddress( cfg );
		var hide = slim && ! wantsAddress;
		var list = rows( cfg );

		list.forEach( function ( item ) {
			item.row.style.display = hide ? 'none' : '';
			setRequired( item.row, item.required && ! slim, cfg );
		} );
		syncToggle( hide, list, cfg );
	}

	doc.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest || ! target.closest( '.' + TOGGLE_CLASS + '__button' ) ) {
			return;
		}
		event.preventDefault();
		wantsAddress = true;
		update();
		var street = doc.getElementById( 'billing_address_1' );
		if ( street && street.focus ) {
			street.focus();
		}
	} );

	// WooCommerce only refreshes the order review (and so the shipping rates)
	// for address text typed key by key: its checkout.js marks a field dirty on
	// keydown. Browser autofill, paste-and-tap and password managers fire
	// change/input without keydown, so the street + postcode arrived but the
	// delivery rate never appeared. Ask for a refresh on any address change.
	var ADDRESS_FIELD = /^(billing|shipping)_(address_1|address_2|city|postcode|state|country)$/;
	var refreshTimer = null;

	function requestTotalsRefresh() {
		if ( ! window.jQuery ) {
			return;
		}
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function () {
			window.jQuery( doc.body ).trigger( 'update_checkout' );
		}, 300 );
	}

	function radioGroup( input ) {
		return doc.querySelectorAll( 'input[type="radio"][name="' + input.getAttribute( 'name' ) + '"]' );
	}

	// "Want delivery?" means delivery: once a delivery rate shows up for the
	// address the customer revealed, choose it for them (once — they can still
	// switch back to pickup).
	var autoChoseDelivery = false;
	function chooseDeliveryIfAsked( cfg ) {
		if ( ! wantsAddress || autoChoseDelivery || ! isPickup( cfg ) || 'pickup' === cfg.orderType ) {
			return;
		}
		var radios = doc.querySelectorAll( 'input[type="radio"][name^="shipping_method["]' );
		for ( var i = 0; i < radios.length; i++ ) {
			if ( -1 !== ( cfg.pickupMethods || [] ).indexOf( methodId( radios[ i ].value ) ) ) {
				continue;
			}
			var target = radios[ i ];
			var group = radioGroup( target );
			for ( var j = 0; j < group.length; j++ ) {
				group[ j ].checked = group[ j ] === target;
				if ( group[ j ] === target ) {
					group[ j ].setAttribute( 'checked', 'checked' );
				} else {
					group[ j ].removeAttribute( 'checked' );
				}
			}
			autoChoseDelivery = true;
			// Bubbles to WooCommerce's shipping-method handler, which refreshes totals.
			target.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			return;
		}
	}

	doc.addEventListener( 'change', function ( event ) {
		var name = ( event.target && event.target.name ) || '';
		if ( 0 === name.indexOf( 'shipping_method[' ) || 'payment_method' === name ) {
			update();
		} else if ( ADDRESS_FIELD.test( name ) ) {
			requestTotalsRefresh();
		}
	} );

	function afterCheckoutRefresh() {
		update();
		var cfg = config();
		if ( cfg && cfg.enabled ) {
			chooseDeliveryIfAsked( cfg );
		}
	}

	// WooCommerce re-renders the shipping + payment lists over AJAX and fires
	// these on jQuery( document.body ). address-i18n.js re-marks fields
	// required on country_to_state_changed; re-apply after it has run.
	if ( window.jQuery ) {
		window.jQuery( doc.body ).on( 'updated_checkout', afterCheckoutRefresh );
		window.jQuery( doc.body ).on( 'payment_method_selected', update );
		window.jQuery( doc.body ).on( 'country_to_state_changed', function () {
			window.setTimeout( update, 0 );
		} );
	}

	if ( 'loading' === doc.readyState ) {
		doc.addEventListener( 'DOMContentLoaded', update );
	} else {
		update();
	}
} )();
