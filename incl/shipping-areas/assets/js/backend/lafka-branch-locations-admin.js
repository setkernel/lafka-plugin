/**
 * Products → Lafka Branch Locations (add / edit a branch), plus the branch
 * settings that ride along on the product editor and Lafka Shipping Settings.
 *
 *   - Address geocoding: type the address and press "Geocode", or click the
 *     map (the click is the branch's point; its address fills the field).
 *     The point goes into the hidden #lafka_branch_address_geocoded input as
 *     URL-encoded JSON { lat, lng }; only a geocoded branch can be chosen by
 *     customers. Works with either map provider (window.lafkaMaps).
 *   - Branch image (media frame), Select2 fields, the weekly order-hours
 *     editor (jquery.schedule) and the holidays calendar (flatpickr).
 *   - After "Add New Branch Location" (an AJAX save) the form is reset.
 *
 * Params: window.lafka_branch_location_properties (labels + options).
 */
( function ( $, window, document ) {
	'use strict';

	const props = window.lafka_branch_location_properties || {};
	let branchMap = null;
	let branchMarker = null;

	/** @return {?HTMLInputElement} The address field. */
	const addressField = () => document.getElementById( 'lafka_branch_address' );
	/** @return {?HTMLInputElement} The hidden geocoded point. */
	const pointField = () => document.getElementById( 'lafka_branch_address_geocoded' );

	/**
	 * Show `point` as the branch location and store it.
	 *
	 * @param {{lat: number, lng: number}} point Point.
	 * @param {boolean}                    store Write the hidden field too.
	 */
	function showPoint( point, store ) {
		if ( ! branchMap ) {
			return;
		}
		if ( branchMarker ) {
			branchMarker.set( point );
		} else {
			branchMarker = branchMap.marker( point, { draggable: true, onMove: ( moved ) => showPoint( moved, true ) } );
		}
		branchMap.view( point, 15 );
		if ( store && pointField() ) {
			pointField().value = encodeURIComponent( JSON.stringify( { lat: point.lat, lng: point.lng } ) );
		}
	}

	/**
	 * Forget the location (and, with `address`, the typed address too).
	 *
	 * @param {boolean} address Clear the address field as well.
	 */
	function clearPoint( address ) {
		if ( address && addressField() ) {
			addressField().value = '';
		}
		if ( pointField() ) {
			pointField().value = '';
		}
		if ( branchMarker ) {
			branchMarker.remove();
			branchMarker = null;
		}
		if ( branchMap && window.lafkaMaps ) {
			branchMap.view( window.lafkaMaps.defaults, window.lafkaMaps.defaults.zoom );
		}
	}

	/** Geocode the typed address. */
	function geocodeAddress() {
		const maps = window.lafkaMaps;
		const field = addressField();
		if ( ! maps || ! field || ! field.value.trim() ) {
			return;
		}
		maps.geocode( field.value ).then(
			( result ) => {
				if ( ! result ) {
					window.alert( maps.i18n.notFound || props.geocode_error );
				} else if ( ! result.precise ) {
					window.alert( props.geocode_approximate );
				} else {
					showPoint( result, true );
				}
			},
			( error ) => window.alert( props.geocode_error + ': ' + error.message )
		);
	}

	/**
	 * A map click: that point is the branch; fill the address from it.
	 *
	 * @param {{lat: number, lng: number}} point Clicked point.
	 */
	function pickPoint( point ) {
		showPoint( point, true );
		window.lafkaMaps.reverse( point ).then(
			( result ) => {
				if ( result && result.label && addressField() ) {
					addressField().value = result.label;
				}
			},
			() => {}
		);
	}

	/** Build the map under the address field (branch term screens only). */
	function initMap() {
		const container = document.getElementById( 'lafka_geocode_branch_location_map' );
		const field = addressField();
		if ( ! container || ! field || ! window.lafkaMaps ) {
			return;
		}

		const buttons = document.createElement( 'span' );
		buttons.className = 'lafka-geocode-branch-buttons';
		[
			[ props.geocode_label, 'lafka-geocode-branch-address-submit', geocodeAddress ],
			[ props.clear_label, 'lafka-geocode-branch-address-clear', () => clearPoint( true ) ],
		].forEach( ( [ label, className, action ] ) => {
			const button = document.createElement( 'input' );
			button.type = 'button';
			button.value = label;
			button.className = 'button lafka-geocode-branch-button ' + className;
			button.addEventListener( 'click', action );
			buttons.appendChild( button );
		} );
		field.insertAdjacentElement( 'afterend', buttons );
		field.addEventListener( 'keydown', ( event ) => {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				geocodeAddress();
			}
		} );
		field.addEventListener( 'change', () => {
			if ( ! field.value ) {
				clearPoint( false );
			}
		} );

		window.lafkaMaps.ready().then( ( maps ) => {
			branchMap = maps.map( container, maps.defaults );
			if ( ! branchMap ) {
				return;
			}
			branchMap.onClick( pickPoint );
			const saved = maps.point( pointField() ? pointField().value : '' );
			if ( saved ) {
				showPoint( saved, false );
			}
		} );
	}

	/** Put the branch image back to the placeholder. */
	function resetImage() {
		$( '#lafka_branch_location_img' ).find( 'img' ).attr( 'src', props.placeholder_image_src );
		$( '#lafka_branch_location_img_id' ).val( '' );
		$( '.lafka_branch_location_img_remove_image_button' ).hide();
	}

	/** Pick the first option of a select. */
	function firstOption( selector ) {
		const select = $( selector );
		select.val( select.find( 'option:first' ).val() );
	}

	/** After "Add New Branch Location" saved over AJAX, empty the form. */
	function resetAddForm() {
		clearPoint( true );
		resetImage();
		$( '#lafka_branch_user' ).val( null ).trigger( 'change' );
		firstOption( '#lafka_branch_order_type' );
		$( '#lafka_branch_shipping_areas' ).val( null ).trigger( 'change' );
		$( '#lafka_branch_distance_restriction' ).val( '' );
		firstOption( '#lafka_branch_distance_unit' );
		$( '#lafka_branch_override_datetime_global' ).prop( 'checked', false ).trigger( 'change' );
		$( '#lafka_branch_datetime_mandatory' ).prop( 'checked', false ).trigger( 'change' );
		$( '#lafka_branch_datetime_days_ahead' ).val( '30' );
		$( '#lafka_branch_datetime_timeslot_duration' ).val( '60' );
		$( '#lafka_branch_override_order_hours_global' ).prop( 'checked', false ).trigger( 'change' );
		$( '#lafka_branch_timezone' ).val( 'default' ).trigger( 'change' );
		$( '#lafka_branch_order_hours_force_override_check' ).prop( 'checked', false );
		firstOption( '#lafka_branch_order_hours_force_override_status' );
		$( '#lafka_branch_order_hours_schedule' ).val( '' );
		$( '#lafka_branch_order_hours_container' ).jqs( 'reset' );
		const holidays = $( '#lafka_branch_order_hours_holidays_calendar' );
		if ( holidays.length ) {
			holidays.flatpickr( { mode: 'multiple' } ).clear();
		}
	}

	/** Branch image: media frame upload / remove. */
	function initImage() {
		if ( ! $( '#lafka_branch_location_img_id' ).val() ) {
			$( '.lafka_branch_location_img_remove_image_button' ).hide();
		}
		$( document ).on( 'click', '.lafka_branch_location_img_upload_image_button', function ( event ) {
			event.preventDefault();
			const frame = wp.media( {
				title: props.choose_image_label,
				button: { text: props.use_image_label },
				multiple: false,
			} );
			frame.on( 'select', function () {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				const size = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail : attachment.sizes.full;
				$( '#lafka_branch_location_img_id' ).val( attachment.id );
				$( '#lafka_branch_location_img' ).find( 'img' ).attr( 'src', size.url );
				$( '.lafka_branch_location_img_remove_image_button' ).show();
			} );
			frame.open();
		} );
		$( document ).on( 'click', '.lafka_branch_location_img_remove_image_button', function () {
			resetImage();
			return false;
		} );
	}

	/** The weekly order-hours editor and the holidays calendar. */
	function initOrderHours() {
		let schedule;
		try {
			const parsed = JSON.parse( $( '#lafka_branch_order_hours_schedule' ).val() );
			schedule = parsed && 'object' === typeof parsed ? parsed : [];
		} catch {
			schedule = [];
		}
		$( '#lafka_branch_order_hours_container' ).jqs( { data: schedule, periodOptions: false } );
		$( 'body.taxonomy-lafka_branch_location' )
			.find( 'form#addtag input:submit, form#edittag input:submit' )
			.on( 'click', function () {
				$( '#lafka_branch_order_hours_schedule' ).val( $( '#lafka_branch_order_hours_container' ).jqs( 'export' ) );
			} );
		$( '#lafka_branch_order_hours_holidays_calendar' ).flatpickr( { mode: 'multiple' } );
	}

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( 'edit-lafka_branch_location' === new URLSearchParams( settings.data ).get( 'screen' ) ) {
			resetAddForm();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		initMap();
		initImage();
		$( '.lafka-admin-select2' ).select2();
		$( '#autocomplete_countries' ).select2( { maximumSelectionLength: 5 } );
		if ( ! props.products_by_branches ) {
			$( '#tagsdiv-lafka_branch_location' ).hide();
		}
		initOrderHours();
	} );
} )( window.jQuery, window, document );
