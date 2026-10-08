/**
 * The location popup ("Location Confirmation Popup"): the customer picks
 * delivery or pickup and a branch before ordering.
 *
 *   Pickup    every branch that offers pickup is listed; no map or address
 *             is needed, so a pickup-only store loads no map library at all.
 *   Delivery  the customer's address is located (window.lafkaMaps): with a
 *             Google Maps key, Places suggests addresses while typing; without
 *             one the customer types the address and presses "Find" (one
 *             lookup through the plugin's cached OpenStreetMap geocoder — no
 *             search-as-you-type), or uses their current location. Only the
 *             branches whose delivery radius or delivery zones cover the
 *             address are offered.
 *
 * The chosen branch, order type and address are posted to
 * wp_ajax_lafka_select_branch (Lafka_Branch_Locations::select_branch()),
 * which re-checks everything server-side.
 *
 * Params: window.lafka_branch_locations_front (options, branch data, messages).
 */
( function ( $, window, document ) {
	'use strict';

	const params = window.lafka_branch_locations_front || {};
	const body = () => $( document.body );
	const form = () => $( '#lafka_select_branch_form' );
	const message = ( text ) => form().find( '.lafka-branch-select-message' ).html( text || '' );

	/**
	 * Every branch with its delivery data (or [] when unreadable).
	 *
	 * @return {Array<Object>} Branches.
	 */
	function branches() {
		try {
			const list = JSON.parse( params.branch_locations_json_data );
			return Array.isArray( list ) ? list : [];
		} catch {
			return [];
		}
	}

	/**
	 * Whether a branch delivers to a point: inside its radius, or inside one
	 * of its delivery zones.
	 *
	 * @param {Object}                     branch Branch data.
	 * @param {{lat: number, lng: number}} point  Customer location.
	 * @return {boolean} Covered.
	 */
	function deliversTo( branch, point ) {
		const maps = window.lafkaMaps;
		const origin = maps ? maps.point( branch.branch_address_geocoded ) : null;
		if ( ! origin ) {
			return false;
		}
		const radius = parseFloat( branch.distance_restriction );
		if ( isFinite( radius ) ) {
			const metres = maps.distance( point, origin );
			if ( ( 'imperial' === branch.distance_unit ? metres / 1609.344 : metres / 1000 ) < radius ) {
				return true;
			}
		}
		return ( branch.shipping_areas || [] ).some( ( area ) => maps.contains( point, area.area_polygon_coordinates ) );
	}

	/**
	 * The ids of the branches that can take this order type (and, for
	 * delivery, reach the customer's location).
	 *
	 * @param {string} orderType 'delivery' or 'pickup'.
	 * @return {Array<number>} Branch ids.
	 */
	function suitableBranches( orderType ) {
		const point = window.lafkaMaps ? window.lafkaMaps.point( $( '#lafka_user_geocoded_location' ).val() ) : null;
		return branches()
			.filter( ( branch ) => 'delivery_pickup' === branch.order_type || orderType === branch.order_type )
			.filter( ( branch ) => 'pickup' === orderType || ( point && deliversTo( branch, point ) ) )
			.map( ( branch ) => parseInt( branch.id, 10 ) );
	}

	/**
	 * Show only these branches (images or dropdown); a single one is chosen.
	 *
	 * @param {Array<number>} ids Branch ids.
	 */
	function showBranches( ids ) {
		const images = body().find( '.lafka-branch-select-images' );
		const select = body().find( '#lafka_branch_select' );
		images.find( '.lafka-branch-select-image' ).hide();
		select.children().prop( 'hidden', true );
		select.val( '' );
		ids.forEach( ( id ) => {
			if ( images.length ) {
				images.find( '.lafka-branch-' + id ).show();
				if ( 1 === ids.length ) {
					images.find( '.lafka-branch-' + id + ' a' ).trigger( 'click' );
				}
			} else if ( select.length ) {
				select.children( 'option[value="' + id + '"]' ).prop( 'hidden', false );
			}
		} );
		body().find( '.lafka-branch-selection' ).show();
	}

	/** Forget the located address and the chosen branch. */
	function resetAddress() {
		[ 'country', 'address_1', 'city', 'state', 'postcode', 'geocoded_location' ].forEach( ( key ) => $( '#lafka_user_' + key ).val( '' ) );
		body().find( '.lafka-branch-select-image' ).removeClass( 'lafka-branch-selected-image' );
		body().find( '#lafka_selected_branch_id' ).val( '' );
		message( '' );
	}

	/**
	 * The WooCommerce state code for a located address: the first state of
	 * the country whose code or name matches one of its regions.
	 *
	 * @param {Object} result Geocode result.
	 * @return {string} State code ('' when none matches).
	 */
	function wcState( result ) {
		if ( 'undefined' === typeof window.wc_country_select_params || ! result.address.country ) {
			return '';
		}
		let states;
		try {
			states = JSON.parse( window.wc_country_select_params.countries.replace( /&quot;/g, '"' ) )[ result.address.country ] || {};
		} catch {
			return '';
		}
		const country = result.address.country;
		const regions = ( result.regions || [] ).filter( ( region ) => region.short || region.long );
		const codes = Object.keys( states );
		if ( 'US' === country ) {
			return codes.find( ( code ) => regions.length && code === regions[ 0 ].short ) || '';
		}
		return (
			codes.find( ( code ) =>
				regions.some(
					( region ) =>
						( region.short && ( code === region.short || code === country + '-' + region.short ) ) ||
						( region.long && String( states[ code ] ).toLowerCase().includes( region.long.toLowerCase() ) )
				)
			) || ''
		);
	}

	/**
	 * Use a located address: fill the hidden fields and offer the branches
	 * that deliver there.
	 *
	 * @param {Object}                      result Geocode result.
	 * @param {{lat: number, lng: number}=} exact  The customer's own position, when known.
	 */
	function useAddress( result, exact ) {
		const point = exact || { lat: result.lat, lng: result.lng };
		const regions = result.regions || [];
		let city = result.address.city;
		if ( ! city ) {
			const named = regions.find( ( region ) => region.long );
			city = named ? named.long : '';
		}
		if ( 'Santiago' === city && regions[ 2 ] && regions[ 2 ].long ) {
			city += ' ' + regions[ 2 ].long;
		}
		$( '#lafka_user_country' ).val( result.address.country );
		$( '#lafka_user_address_1' ).val( result.address.address_1 );
		$( '#lafka_user_city' ).val( city );
		$( '#lafka_user_state' ).val( wcState( result ) || ( regions[ 0 ] ? regions[ 0 ].long : '' ) );
		$( '#lafka_user_postcode' ).val( result.address.postcode );
		$( '#lafka_user_geocoded_location' ).val( encodeURIComponent( JSON.stringify( point ) ) );

		if ( ! result.address.country ) {
			body().find( '.lafka-branch-selection' ).hide();
			return;
		}
		const ids = suitableBranches( 'delivery' );
		if ( ids.length ) {
			showBranches( ids );
		} else {
			resetAddress();
			body().find( '.lafka-branch-selection' ).hide();
			message( params.error_message_no_suitable_branches );
		}
	}

	/**
	 * Whether a located address is precise enough to deliver to.
	 *
	 * @param {Object} result Geocode result.
	 * @return {boolean} Precise enough.
	 */
	function preciseEnough( result ) {
		return 'address' === result.level || ( params.allow_partial_address && 'street' === result.level );
	}

	/** Keyless: look up the typed address once ("Find" or Enter). */
	function findTypedAddress() {
		const maps = window.lafkaMaps;
		const query = String( $( '#lafka_branch_select_user_address' ).val() || '' ).trim();
		if ( ! maps || ! query ) {
			message( params.error_message_no_address );
			return;
		}
		message( params.please_wait_message + '…' );
		maps.geocode( query ).then(
			( result ) => {
				if ( ! result ) {
					message( params.error_message_not_found );
				} else if ( ! preciseEnough( result ) ) {
					message( params.error_message_precise_address );
				} else {
					message( '' );
					useAddress( result );
				}
			},
			( error ) => message( params.error_message_geocoder_failed + ': ' + error.message )
		);
	}

	/** With a Google key: Places suggestions while typing. */
	function initAutocomplete( input ) {
		const maps = window.google && window.google.maps;
		if ( ! maps || ! maps.places ) {
			return;
		}
		const area = String( params.autocomplete_area || '' ).trim().split( ',' );
		const countries = params.autocomplete_countries || [];
		const options = { fields: [ 'address_components', 'geometry', 'name', 'types' ] };
		if ( area.length > 1 ) {
			options.bounds = { east: Number( area[ 0 ] ), north: Number( area[ 1 ] ), south: Number( area[ 2 ] ), west: Number( area[ 3 ] ) };
			options.strictBounds = true;
		}
		if ( countries.length && countries[ 0 ].length ) {
			options.componentRestrictions = { country: countries };
		}
		const autocomplete = new maps.places.Autocomplete( input, options );
		autocomplete.addListener( 'place_changed', () => {
			const place = autocomplete.getPlace();
			if ( ! place || ! place.geometry ) {
				return;
			}
			const types = place.types || [];
			const result = window.lafkaMaps.fromGoogle( place );
			const street = ( place.address_components || [] ).some( ( component ) => component.types.includes( 'route' ) );
			if ( types.includes( 'street_address' ) || types.includes( 'establishment' ) || ( params.allow_partial_address && street ) ) {
				useAddress( result );
			} else {
				message( params.error_message_precise_address );
			}
		} );
	}

	/** "Use current location": the browser position, then its address. */
	function useCurrentLocation() {
		const maps = window.lafkaMaps;
		if ( ! maps ) {
			return;
		}
		message( params.please_wait_message + '…' );
		maps.ready()
			.then( () => maps.locate() )
			.then( ( point ) =>
				maps.reverse( point ).then( ( result ) => {
					if ( ! result ) {
						message( params.error_message_not_found );
						return;
					}
					$( '#lafka_branch_select_user_address' ).val( result.label );
					message( '' );
					useAddress( result, point );
				} )
			)
			.catch( ( error ) => message( params.error_message_geocoder_failed + ': ' + error.message ) );
	}

	/**
	 * Switch the popup to an order type.
	 *
	 * @param {string} orderType 'delivery' or 'pickup'.
	 */
	function chooseOrderType( orderType ) {
		const delivery = 'delivery' === orderType;
		resetAddress();
		body().find( '.lafka-branch-delivery' ).toggleClass( 'lafka-selected', delivery );
		body().find( '.lafka-branch-pickup' ).toggleClass( 'lafka-selected', ! delivery );
		const hint = delivery ? params.info_message_select_branch_delivery : params.info_message_select_branch_pickup;
		body().find( '.lafka-branch-select-tip, .lafka_branch_select_label' ).html( hint );
		$( '#lafka_branch_order_type' ).val( orderType );
		body().find( '.lafka-branch-user-address' ).toggle( delivery );
		if ( delivery ) {
			body().find( '.lafka-branch-selection' ).hide();
		} else {
			showBranches( suitableBranches( 'pickup' ) );
		}
	}

	/** Wire the popup and open it (first visit of the browser session). */
	function openPopup() {
		const input = document.getElementById( 'lafka_branch_select_user_address' );
		if ( input && window.lafkaMaps ) {
			window.lafkaMaps.ready().then( ( maps ) => {
				if ( 'google' === maps.provider ) {
					initAutocomplete( input );
				}
			} );
		}

		// Only the order types the store offers are printed; reveal them.
		body().find( '.lafka-branch-delivery, .lafka-branch-pickup' ).show();
		body().find( '.lafka-branch-delivery' ).on( 'click', () => chooseOrderType( 'delivery' ) );
		body().find( '.lafka-branch-pickup' ).on( 'click', () => chooseOrderType( 'pickup' ) );
		body()
			.find( '.lafka-branch-select-image a' )
			.on( 'click', function () {
				body().find( '.lafka-branch-select-image' ).removeClass( 'lafka-branch-selected-image' );
				$( this ).parent( '.lafka-branch-select-image' ).addClass( 'lafka-branch-selected-image' );
				$( '#lafka_selected_branch_id' ).val( $( this ).data( 'branchId' ) );
				message( '' );
			} );
		body().find( '#lafka_branch_select' ).on( 'click', () => message( '' ) );
		$( '#lafka_branch_select_user_address' ).on( 'keydown', ( event ) => {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				if ( 'google' !== ( window.lafkaMaps && window.lafkaMaps.provider ) ) {
					findTypedAddress();
				}
				return;
			}
			body().find( '.lafka-branch-selection' ).hide();
			resetAddress();
		} );
		body().find( '.lafka-branch-find-address' ).on( 'click', findTypedAddress );
		body().find( '.lafka-branch-auto-locate' ).on( 'click', useCurrentLocation );

		const types = { delivery_pickup: 'delivery', delivery: 'delivery', pickup: 'pickup' };
		chooseOrderType( types[ params.order_type ] || 'delivery' );

		const closable = ( params.closable_because_of_closed_branches && body().find( '.lafka-all-stores-closed' ).length ) || params.closable_because_of_option;
		$.magnificPopup.open( {
			items: { src: '#lafka_select_branch_modal' },
			modal: ! closable,
			focus: '#lafka_branch_select_user_address',
			callbacks: {
				close() {
					window.sessionStorage.setItem( 'lafka_branch_selection_closed', '1' );
				},
			},
		} );
	}

	/**
	 * Client-side completeness check before posting.
	 *
	 * @param {jQuery} $form The popup form.
	 * @return {boolean} Ready to post.
	 */
	function complete( $form ) {
		const branch = $form.find( '#lafka_branch_select' ).val() || $form.find( '#lafka_selected_branch_id' ).val();
		message( '' );
		if ( 'delivery' === $form.find( '#lafka_branch_order_type' ).val() && ! ( $form.find( '#lafka_branch_select_user_address' ).val() && $form.find( '#lafka_user_country' ).val() ) ) {
			message( params.error_message_no_address );
			return false;
		}
		if ( ! branch ) {
			message( params.error_message_select_branch );
			return false;
		}
		return true;
	}

	$( function () {
		if ( ! params.has_session_value && '1' !== window.sessionStorage.getItem( 'lafka_branch_selection_closed' ) ) {
			openPopup();
		}

		body().on( 'click', '.lafka-branch-select-submit', function () {
			window.sessionStorage.removeItem( 'lafka_branch_selection_closed' );
			const modal = body().find( '#lafka_select_branch_modal' );
			const $form = $( this ).closest( 'form' );
			if ( ! complete( $form ) ) {
				return false;
			}
			modal.block( {
				message: '<p>' + params.please_wait_message + '...</p>',
				css: { border: 'none', padding: '15px', backgroundColor: '#000', borderRadius: '10px', opacity: 0.5, color: '#fff' },
			} );
			$.post(
				params.ajax_url,
				{
					_ajax_nonce: $form.find( '#_wpnonce' ).val(),
					action: 'lafka_select_branch',
					dataType: 'json',
					fields: encodeURIComponent( $form.find( 'input, select' ).serialize() ),
				},
				( response ) => {
					if ( response.success ) {
						window.location.reload();
						return;
					}
					message( response.data && response.data[ 0 ] ? response.data[ 0 ].message : params.error_message_json_parse );
					modal.unblock();
				}
			);
			return false;
		} );

		body().on( 'click', '.lafka-change-branch a', function () {
			window.sessionStorage.removeItem( 'lafka_branch_selection_closed' );
			$.post( params.ajax_url, { _ajax_nonce: $( this ).data( 'nonce' ), action: 'lafka_change_branch', dataType: 'json' }, ( response ) => {
				if ( response.success ) {
					window.location.reload();
				}
			} );
		} );

		body().trigger( 'updated_wc_div' );
	} );

	// Firefox restores typed checkout values on reload, which would hide a
	// branch change; start the checkout form clean.
	$( window ).on( 'load', () => {
		if ( window.navigator.userAgent.toLowerCase().includes( 'firefox' ) ) {
			const checkout = $( 'form.woocommerce-checkout' );
			if ( checkout.length ) {
				checkout[ 0 ].reset();
			}
		}
	} );
} )( window.jQuery, window, document );
