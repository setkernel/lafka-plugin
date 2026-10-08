/**
 * Classic cart / checkout delivery helpers.
 *
 *   - The branch info bar ("Delivery from … To …") follows the address the
 *     customer types (the `delivery_address` fragment of update_order_review).
 *   - The delivery pin map (Lafka Shipping Settings → "Pick Precise Delivery
 *     Address from Map"): after each checkout update the typed address is
 *     geocoded once (window.lafkaMaps: Google with a key, else the plugin's
 *     cached OpenStreetMap geocoder — never while typing). A precise match
 *     becomes the pin; otherwise the customer places it: click the map, drag
 *     the pin, or "Use my location". In "when the address cannot be found"
 *     mode a precise match hides the map. The pin travels in the hidden
 *     #lafka_picked_delivery_geocoded field; the server checks it against the
 *     delivery zones (Lafka_Shipping_Areas::validate_checkout_field_process()).
 *     A pin the customer placed is kept until they edit the address.
 *
 * With distance-priced delivery (`lafka_distance`) the pin decides the price,
 * so a pin that differs from the one the last rates were priced with asks
 * WooCommerce for fresh rates (`settings.repriceOnPin`).
 *
 * (The client-side rate filtering for the retired Lafka shipping method —
 * `lafka_shipping_areas_method` rates, per-instance zone/radius/minimum
 * checks and the "lowest cost only" option — was removed: WooCommerce
 * Distance Rate Shipping replaced that method in 9.1.0 and no rate carries
 * its id.)
 *
 * Params: window.lafkaCheckoutMap = { orderType, mode, i18n }.
 */
( function ( $, window, document ) {
	'use strict';

	const settings = window.lafkaCheckoutMap || {};
	const i18n = settings.i18n || {};
	const state = { map: null, marker: null, request: 0, priced: '' };

	const pinField = () => document.getElementById( 'lafka_picked_delivery_geocoded' );
	const placedField = () => document.getElementById( 'lafka_is_location_clicked' );
	const pinBox = () => $( '#lafka_pick_delivery_address_field' );

	/**
	 * A WooCommerce state name for a country + state code.
	 *
	 * @param {string} country Country code.
	 * @param {string} code    State code.
	 * @return {string} Name (the code when unknown).
	 */
	function stateName( country, code ) {
		if ( ! code || 'undefined' === typeof window.wc_country_select_params ) {
			return code || '';
		}
		try {
			const states = JSON.parse( window.wc_country_select_params.countries.replace( /&quot;/g, '"' ) );
			return states[ country ] && states[ country ][ code ] ? states[ country ][ code ] : code;
		} catch {
			return code;
		}
	}

	/**
	 * Keep the branch bar's "To:" address in step with the checkout form.
	 *
	 * @param {Object} address The delivery_address fragment.
	 */
	function updateBranchBar( address ) {
		const bar = $( '.lafka-change-branch-full-address' );
		if ( bar.length ) {
			bar.text(
				[ address.address_1, address.address_2, address.postcode, address.city, stateName( address.country, address.state ), address.country_label ]
					.filter( Boolean )
					.join( ', ' )
			);
		}
	}

	/**
	 * One geocodable line from the delivery_address fragment.
	 *
	 * @param {Object} address Fragment.
	 * @return {string} Address ('' without a street).
	 */
	function addressLine( address ) {
		if ( ! address || ! address.address_1 ) {
			return '';
		}
		return [ address.address_1, address.address_2, address.city, stateName( address.country, address.state ), address.postcode, address.country_label || address.country ]
			.filter( Boolean )
			.join( ', ' );
	}

	/** @param {string} text Status line ('' clears it). */
	function say( text ) {
		pinBox().find( '.lafka-map-message' ).text( text || '' );
	}

	/** @param {boolean} placed Whether a pin is set. */
	function headings( placed ) {
		pinBox().find( 'h3.lafka-address-not-found' ).toggle( ! placed );
		pinBox().find( 'h3.lafka-address-marked' ).toggle( placed );
	}

	/** The address changed: the old pin no longer applies. */
	function forgetPin() {
		if ( pinField() ) {
			pinField().value = '';
		}
		if ( placedField() ) {
			placedField().value = '';
		}
	}

	/**
	 * Show the pin map (created on first use).
	 *
	 * @param {Object} maps window.lafkaMaps.
	 * @return {?Object} Map adapter.
	 */
	function showMap( maps ) {
		pinBox().removeClass( 'hidden' ).show();
		if ( ! state.map ) {
			state.map = maps.map( document.getElementById( 'lafka-pick-delivery-address-checkout-map' ), maps.defaults );
			if ( state.map ) {
				state.map.onClick( ( point ) => placePin( point, true ) );
			}
		} else {
			state.map.refresh();
		}
		return state.map;
	}

	/**
	 * Set the delivery pin.
	 *
	 * @param {{lat: number, lng: number}} point    Point.
	 * @param {boolean}                    customer The customer placed it.
	 */
	function placePin( point, customer ) {
		if ( state.map ) {
			if ( state.marker ) {
				state.marker.set( point );
			} else {
				state.marker = state.map.marker( point, { draggable: true, onMove: ( moved ) => placePin( moved, true ) } );
			}
		}
		const value = JSON.stringify( { lat: point.lat, lng: point.lng } );
		pinField().value = value;
		if ( customer ) {
			placedField().value = 'clicked';
		}
		headings( true );
		say( '' );
		if ( settings.repriceOnPin && value !== state.priced ) {
			state.priced = value;
			$( document.body ).trigger( 'update_checkout' );
		}
	}

	/** Remove the pin from the map and the form. */
	function clearPin() {
		if ( state.marker ) {
			state.marker.remove();
			state.marker = null;
		}
		forgetPin();
		headings( false );
	}

	/**
	 * Apply a geocode of the typed address.
	 *
	 * @param {Object}  maps   window.lafkaMaps.
	 * @param {?Object} result Geocode result.
	 */
	function applyGeocode( maps, result ) {
		if ( result && result.precise && 'when_fail' === settings.mode ) {
			// Found: no map needed; the match is the delivery point.
			pinBox().hide();
			pinField().value = JSON.stringify( { lat: result.lat, lng: result.lng } );
			placedField().value = '';
			return;
		}
		const map = showMap( maps );
		if ( ! map ) {
			return;
		}
		if ( result && result.precise ) {
			placePin( result, false );
			map.view( result, 16 );
		} else {
			clearPin();
			if ( result ) {
				map.view( result, 13 );
			} else {
				map.view( maps.defaults, maps.defaults.zoom );
			}
		}
	}

	/**
	 * After each checkout update: follow the address, then the pin.
	 *
	 * @param {Object} fragments update_order_review fragments.
	 */
	function refresh( fragments ) {
		if ( 'pickup' === settings.orderType ) {
			return;
		}
		const address = fragments ? fragments.delivery_address : null;
		if ( address ) {
			updateBranchBar( address );
		}
		if ( ! document.getElementById( 'lafka-pick-delivery-address-checkout-map' ) || ! pinField() || ! window.lafkaMaps ) {
			return;
		}
		const request = ++state.request;
		window.lafkaMaps.ready().then( ( maps ) => {
			const pinned = maps.point( pinField().value );
			if ( pinned && placedField().value ) {
				// The customer's own pin stays until the address is edited.
				if ( showMap( maps ) ) {
					placePin( pinned, true );
				}
				return;
			}
			const line = addressLine( address );
			if ( ! line ) {
				applyGeocode( maps, null );
				return;
			}
			maps.geocode( line ).then(
				( result ) => {
					if ( request === state.request ) {
						applyGeocode( maps, result );
					}
				},
				( error ) => {
					if ( request === state.request ) {
						applyGeocode( maps, null );
						say( error.message );
					}
				}
			);
		} );
	}

	/**
	 * Fill an empty street address from a reverse geocode of the pin.
	 *
	 * @param {Object} result Reverse-geocode result.
	 */
	function fillAddress( result ) {
		const prefix = $( '#ship-to-different-address-checkbox' ).is( ':checked' ) ? '#shipping_' : '#billing_';
		const street = $( prefix + 'address_1' );
		if ( ! result || ! result.address || ! street.length || street.val() ) {
			return;
		}
		street.val( result.address.address_1 );
		[ 'city', 'postcode' ].forEach( ( key ) => {
			const input = $( prefix + key );
			if ( input.length && ! input.val() && result.address[ key ] ) {
				input.val( result.address[ key ] );
			}
		} );
		$( document.body ).trigger( 'update_checkout' );
	}

	/** "Use my location": the browser's position becomes the pin. */
	function useMyLocation() {
		const maps = window.lafkaMaps;
		if ( ! maps ) {
			return;
		}
		say( i18n.locating || '' );
		maps.ready()
			.then( () => maps.locate() )
			.then( ( point ) => {
				const map = showMap( maps );
				placePin( point, true );
				if ( map ) {
					map.view( point, 17 );
				}
				return maps.reverse( point ).then( fillAddress, () => {} );
			} )
			.catch( ( error ) => say( error.message ) );
	}

	$( document ).ajaxStart( function () {
		const overlay = { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } };
		$( '#lafka_pick_delivery_address_field, .lafka-change-branch' ).block( overlay );
	} );
	$( document ).ajaxComplete( function () {
		$( '#lafka_pick_delivery_address_field, .lafka-change-branch' ).unblock();
	} );

	$( document.body ).on( 'updated_checkout', ( event, data ) => {
		// The rates just arrived priced with whatever pin the form holds.
		state.priced = pinField() ? pinField().value : '';
		refresh( data && data.fragments ? data.fragments : null );
	} );
	$( document.body ).on( 'click', '.lafka-map-locate', ( event ) => {
		event.preventDefault();
		useMyLocation();
	} );

	$( function () {
		$( 'form.checkout' ).on( 'keydown', '.address-field input', forgetPin );
		$( 'form.checkout' ).on( 'change', '.address-field select', forgetPin );
	} );
} )( window.jQuery, window, document );
