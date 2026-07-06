/**
 * Lafka block Cart/Checkout components (NX1-04b) — build-free, plain ES.
 *
 * No JSX, no build step: everything is wp.element.createElement + WooCommerce
 * Blocks globals (wc.blocksCheckout SlotFills + extensionCartUpdate, wp.plugins).
 * The server (NX1-04a gates) is the authority — if this script fails to load the
 * checkout still submits and invalid states are rejected server-side.
 *
 *   · Free-delivery progress — SlotFill on the block CART, reading the `lafka`
 *     cart-extension exposed by NX1-04a (threshold / remaining).
 *   · Timeslot picker — SlotFill on the block CHECKOUT, driven by the existing
 *     `time_slots_for_date` AJAX endpoint, pushing the selection through the
 *     `lafka` cart/extensions update callback.
 *
 * Emits stable, namespaced `lafka-` markup only. The theme owns all styling.
 */
( function () {
	'use strict';

	var wp = window.wp;
	var wc = window.wc;
	if ( ! wp || ! wp.element || ! wp.plugins || ! wc || ! wc.blocksCheckout ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;

	if ( ! registerPlugin || ! ExperimentalOrderMeta ) {
		return;
	}

	var settings =
		wc.wcSettings && wc.wcSettings.getSetting
			? wc.wcSettings.getSetting( 'lafka-checkout_data', {} )
			: {};
	var i18n = settings.i18n || {};

	function money( amount ) {
		var symbol = settings.currencySymbol || '';
		var value = Math.round( ( parseFloat( amount ) || 0 ) * 100 ) / 100;
		return symbol + value.toFixed( 2 );
	}

	/* ------------------------------------------------------------------ *
	 *  Free-delivery progress (block cart)
	 * ------------------------------------------------------------------ */

	function FreeDeliveryProgress( props ) {
		var lafka = ( props && props.extensions && props.extensions.lafka ) || {};
		var threshold = parseFloat( lafka.free_delivery_threshold ) || 0;
		var remaining = parseFloat( lafka.free_delivery_remaining ) || 0;

		if ( ! ( threshold > 0 ) ) {
			return null;
		}

		var reached = remaining <= 0;
		var pct = Math.max(
			0,
			Math.min( 100, Math.round( ( ( threshold - remaining ) / threshold ) * 100 ) )
		);
		var message = reached
			? i18n.freeDeliveryReached || 'You have unlocked free delivery!'
			: ( i18n.freeDeliveryRemaining || 'Add %s more for free delivery' ).replace(
					'%s',
					money( remaining )
			  );

		return el(
			'div',
			{
				className:
					'lafka-block-free-delivery' +
					( reached ? ' lafka-block-free-delivery--reached' : '' ),
			},
			el( 'p', { className: 'lafka-block-free-delivery__label' }, message ),
			el(
				'div',
				{ className: 'lafka-block-free-delivery__track', role: 'presentation' },
				el( 'div', {
					className: 'lafka-block-free-delivery__bar',
					style: { width: pct + '%' },
				} )
			)
		);
	}

	function renderFreeDelivery() {
		return el( ExperimentalOrderMeta, null, el( FreeDeliveryProgress ) );
	}

	registerPlugin( 'lafka-free-delivery', {
		render: renderFreeDelivery,
		scope: 'woocommerce-cart',
	} );

	/* ------------------------------------------------------------------ *
	 *  Timeslot picker (block checkout)
	 * ------------------------------------------------------------------ */

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function dateOptions( daysAhead ) {
		var out = [];
		var base = new Date();
		for ( var i = 0; i <= daysAhead; i++ ) {
			var d = new Date( base.getFullYear(), base.getMonth(), base.getDate() + i );
			var ymd = d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
			out.push( ymd );
		}
		return out;
	}

	function fetchSlots( date ) {
		var body = new window.URLSearchParams();
		body.append( 'action', 'time_slots_for_date' );
		body.append( 'date', date );
		body.append( '_ajax_nonce', ( settings.timeslot && settings.timeslot.nonce ) || '' );

		return window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				return json && json.success && Array.isArray( json.data ) ? json.data : [];
			} )
			.catch( function () {
				return [];
			} );
	}

	function pushSelection( date, slot ) {
		if ( ! extensionCartUpdate ) {
			return;
		}
		extensionCartUpdate( {
			namespace: 'lafka',
			data: {
				checkout_date: date || '',
				checkout_timeslot: slot || '',
			},
		} );
	}

	function TimeslotPicker() {
		var cfg = settings.timeslot || {};
		var dates = dateOptions( parseInt( cfg.daysAhead, 10 ) || 30 );

		var dateState = useState( '' );
		var date = dateState[ 0 ];
		var setDate = dateState[ 1 ];

		var slotState = useState( '' );
		var slot = slotState[ 0 ];
		var setSlot = slotState[ 1 ];

		var slotsState = useState( [] );
		var slots = slotsState[ 0 ];
		var setSlots = slotsState[ 1 ];

		var loadingState = useState( false );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];

		useEffect(
			function () {
				if ( ! date ) {
					setSlots( [] );
					return undefined;
				}
				var active = true;
				setLoading( true );
				fetchSlots( date ).then( function ( list ) {
					if ( ! active ) {
						return;
					}
					setSlots( list );
					setLoading( false );
				} );
				return function () {
					active = false;
				};
			},
			[ date ]
		);

		function onDateChange( event ) {
			var value = event.target.value;
			setDate( value );
			setSlot( '' );
			pushSelection( value, '' );
		}

		function onSlotChange( event ) {
			var value = event.target.value;
			setSlot( value );
			pushSelection( date, value );
		}

		var dateSelect = el(
			'select',
			{
				className: 'lafka-block-timeslot__date',
				value: date,
				onChange: onDateChange,
			},
			[ el( 'option', { key: '', value: '' }, i18n.chooseDate || 'Choose a date' ) ].concat(
				dates.map( function ( d ) {
					return el( 'option', { key: d, value: d }, d );
				} )
			)
		);

		var slotChildren = [
			el( 'option', { key: '', value: '' }, i18n.chooseTime || 'Choose a time' ),
		].concat(
			slots.map( function ( s ) {
				return el(
					'option',
					{ key: s.id, value: s.id, disabled: !! s.disabled },
					s.text || s.id
				);
			} )
		);

		var slotSelect = el(
			'select',
			{
				className: 'lafka-block-timeslot__time',
				value: slot,
				onChange: onSlotChange,
				disabled: ! date || loading,
			},
			slotChildren
		);

		var note = null;
		if ( loading ) {
			note = el(
				'p',
				{ className: 'lafka-block-timeslot__note' },
				i18n.loadingSlots || 'Loading times…'
			);
		} else if ( date && ! slots.length ) {
			note = el(
				'p',
				{ className: 'lafka-block-timeslot__note' },
				i18n.noSlots || 'No times available for this date.'
			);
		}

		return el(
			'div',
			{ className: 'lafka-block-timeslot' },
			el(
				'h3',
				{ className: 'lafka-block-timeslot__heading' },
				i18n.timeslotHeading || 'Delivery / pickup time'
			),
			el(
				'div',
				{ className: 'lafka-block-timeslot__row' },
				dateSelect,
				slotSelect
			),
			note
		);
	}

	function renderTimeslot() {
		return el( ExperimentalOrderMeta, null, el( TimeslotPicker ) );
	}

	if ( settings.timeslot && settings.timeslot.enabled ) {
		registerPlugin( 'lafka-timeslot', {
			render: renderTimeslot,
			scope: 'woocommerce-checkout',
		} );
	}
} )();
