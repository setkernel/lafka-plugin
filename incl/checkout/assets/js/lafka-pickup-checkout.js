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
		var marks = label.querySelectorAll( 'abbr.required, span.optional' );
		for ( var i = 0; i < marks.length; i++ ) {
			marks[ i ].parentNode.removeChild( marks[ i ] );
		}
		var mark = doc.createElement( required ? 'abbr' : 'span' );
		if ( required ) {
			mark.className = 'required';
			mark.setAttribute( 'title', ( cfg.i18n && cfg.i18n.required ) || 'required' );
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
		if ( ! show ) {
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

	doc.addEventListener( 'change', function ( event ) {
		var name = ( event.target && event.target.name ) || '';
		if ( 0 === name.indexOf( 'shipping_method[' ) || 'payment_method' === name ) {
			update();
		}
	} );

	// WooCommerce re-renders the shipping + payment lists over AJAX and fires
	// these on jQuery( document.body ).
	if ( window.jQuery ) {
		window.jQuery( doc.body ).on( 'updated_checkout payment_method_selected', update );
	}

	if ( 'loading' === doc.readyState ) {
		doc.addEventListener( 'DOMContentLoaded', update );
	} else {
		update();
	}
} )();
