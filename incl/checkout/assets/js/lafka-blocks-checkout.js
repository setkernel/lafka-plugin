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
 *   · Deal nudge — SlotFill on the block CART showing the Deals module's
 *     "add one more item for the deal" message (cart extension `lafka_deals`).
 *   · Delivery notices — SlotFill under the shipping options on both blocks: the
 *     quote guard's "enter your address" and the "no delivery rate" reason.
 *   · Timeslot picker — SlotFill on the block CHECKOUT, driven by the existing
 *     `time_slots_for_date` AJAX endpoint, pushing the selection through the
 *     `lafka` cart/extensions update callback.
 *   · Delivery quote after the address — WooCommerce saves the address while it
 *     is typed; the server prices a new address only when asked, so this sends
 *     `quote_delivery` once the customer has left the address fields.
 *
 * Emits stable, namespaced `lafka-` markup only. The theme owns all styling.
 */
( function () {
	'use strict';

	const wp = window.wp;
	const wc = window.wc;
	if ( ! wp || ! wp.element || ! wp.plugins || ! wc || ! wc.blocksCheckout ) {
		return;
	}

	const el = wp.element.createElement;
	const useState = wp.element.useState;
	const useEffect = wp.element.useEffect;
	const registerPlugin = wp.plugins.registerPlugin;
	const ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	const extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;

	if ( ! registerPlugin || ! ExperimentalOrderMeta ) {
		return;
	}

	const settings =
		wc.wcSettings && wc.wcSettings.getSetting
			? wc.wcSettings.getSetting( 'lafka-checkout_data', {} )
			: {};
	const i18n = settings.i18n || {};

	function money( amount ) {
		return window.lafka.money.format( amount );
	}

	/* ------------------------------------------------------------------ *
	 *  Free-delivery progress (block cart)
	 * ------------------------------------------------------------------ */

	function FreeDeliveryProgress( props ) {
		const lafka = ( props && props.extensions && props.extensions.lafka ) || {};
		const threshold = parseFloat( lafka.free_delivery_threshold ) || 0;
		const remaining = parseFloat( lafka.free_delivery_remaining ) || 0;

		if ( ! props || props.context !== 'woocommerce/cart' || ! ( threshold > 0 ) ) {
			return null;
		}

		const reached = remaining <= 0;
		const pct = Math.max(
			0,
			Math.min( 100, Math.round( ( ( threshold - remaining ) / threshold ) * 100 ) )
		);
		const message = reached
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

	// WooCommerce mounts one PluginArea for the cart and the checkout, scoped
	// 'woocommerce-checkout' (a 'woocommerce-cart' scope never renders); the
	// progress bar shows on the cart only (props.context).
	registerPlugin( 'lafka-free-delivery', {
		render: renderFreeDelivery,
		scope: 'woocommerce-checkout',
	} );

	/* ------------------------------------------------------------------ *
	 *  Deal nudge (block cart)
	 * ------------------------------------------------------------------ */

	// The Deals module resolves the nudge server-side (message, button label,
	// link to the prefilled builder) and exposes it on the cart extension.
	function DealNudge( props ) {
		const deals = ( props && props.extensions && props.extensions.lafka_deals ) || {};
		const nudge = deals.nudge;
		if ( ! props || props.context !== 'woocommerce/cart' || ! nudge || ! nudge.message || ! nudge.url ) {
			return null;
		}
		return el(
			'div',
			{
				className: 'lafka-deal-nudge lafka-card lafka-card--sm lafka-card--flat',
				role: 'status',
				'data-lafka-deal-nudge': String( nudge.deal_id || '' ),
			},
			el( 'p', { className: 'lafka-deal-nudge__text' }, String( nudge.message ) ),
			el(
				'a',
				{
					className: 'lafka-deal-nudge__cta lafka-btn lafka-btn--primary lafka-btn--sm',
					href: String( nudge.url ),
				},
				String( nudge.cta || '' )
			)
		);
	}

	registerPlugin( 'lafka-deal-nudge', {
		render: function () {
			return el( ExperimentalOrderMeta, null, el( DealNudge ) );
		},
		// WooCommerce mounts one PluginArea for the cart and the checkout,
		// scoped 'woocommerce-checkout'; the nudge itself is for the cart only.
		scope: 'woocommerce-checkout',
	} );

	/* ------------------------------------------------------------------ *
	 *  Delivery quote guard notice (block cart + checkout)
	 * ------------------------------------------------------------------ */

	// While the destination lacks a street address + postcode the server
	// withholds delivery rates (Lafka_Delivery_Quote_Guard) and flags it on the
	// `lafka` cart extension; this tells the customer why delivery is missing.
	function DeliveryAddressNotice( props ) {
		const lafka = ( props && props.extensions && props.extensions.lafka ) || {};
		if ( lafka.delivery_quote_pending ) {
			return el(
				'p',
				{ className: 'lafka-block-delivery-quote-notice', role: 'status' },
				i18n.deliveryQuotePending || 'Checking the delivery price for your address…'
			);
		}
		// No delivery rate on offer (out of range, address not found): the same
		// specific sentence the classic cart and checkout show, in place of
		// WooCommerce's generic "no shipping options" text.
		if ( lafka.delivery_unavailable_message ) {
			return el(
				'p',
				{
					className:
						'lafka-block-delivery-quote-notice lafka-block-delivery-unavailable',
					role: 'alert',
				},
				String( lafka.delivery_unavailable_message )
			);
		}
		if ( ! lafka.delivery_address_required || ! lafka.delivery_address_message ) {
			return null;
		}
		return el(
			'p',
			{ className: 'lafka-block-delivery-quote-notice', role: 'status' },
			String( lafka.delivery_address_message )
		);
	}

	const ShippingSlot = wc.blocksCheckout.ExperimentalOrderShippingPackages || ExperimentalOrderMeta;

	function renderDeliveryAddressNotice() {
		return el( ShippingSlot, null, el( DeliveryAddressNotice ) );
	}

	// One registration serves the cart and the checkout (one PluginArea).
	registerPlugin( 'lafka-delivery-quote', {
		render: renderDeliveryAddressNotice,
		scope: 'woocommerce-checkout',
	} );

	/* ------------------------------------------------------------------ *
	 *  Delivery quote once the address is finished (block checkout)
	 * ------------------------------------------------------------------ */

	// WooCommerce saves the address on every pause in typing; the server does
	// not look up an address it has never seen while that happens (the cart
	// extension says `delivery_quote_pending`). Once the customer is out of the
	// address fields and the save has landed, ask for the price, once per address.
	const ADDRESS_FIELD = /^(shipping|billing)-(address_1|address_2|city|state|postcode|country)$/;
	let quotedAddress = '';

	function maybeQuoteDelivery() {
		const cartStore = wp.data && wp.data.select ? wp.data.select( 'wc/store/cart' ) : null;
		if ( ! cartStore || ! extensionCartUpdate || ! cartStore.getCartData ) {
			return;
		}
		const cart = cartStore.getCartData();
		const lafka = ( cart && cart.extensions && cart.extensions.lafka ) || {};
		if ( ! lafka.delivery_quote_pending || cartStore.isCustomerDataUpdating() ) {
			return;
		}
		const active = document.activeElement;
		if ( active && active.id && ADDRESS_FIELD.test( active.id ) ) {
			return;
		}
		const address = cartStore.getCustomerData().shippingAddress || {};
		const key = [ address.country, address.state, address.postcode, address.city, address.address_1 ].join( '|' );
		if ( key === quotedAddress ) {
			return;
		}
		quotedAddress = key;
		extensionCartUpdate( { namespace: 'lafka', data: { quote_delivery: true } } );
	}

	if ( wp.data && wp.data.subscribe ) {
		wp.data.subscribe( maybeQuoteDelivery, 'wc/store/cart' );
		document.addEventListener( 'focusout', function () {
			window.setTimeout( maybeQuoteDelivery, 0 );
		} );
	}

	/* ------------------------------------------------------------------ *
	 *  Timeslot picker (block checkout)
	 * ------------------------------------------------------------------ */

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function dateOptions( daysAhead ) {
		const out = [];
		const base = new Date();
		for ( let i = 0; i <= daysAhead; i++ ) {
			const d = new Date( base.getFullYear(), base.getMonth(), base.getDate() + i );
			const ymd = d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
			out.push( ymd );
		}
		return out;
	}

	function fetchSlots( date ) {
		const body = new window.URLSearchParams();
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
		const cfg = settings.timeslot || {};
		const dates = dateOptions( parseInt( cfg.daysAhead, 10 ) || 30 );

		const dateState = useState( '' );
		const date = dateState[ 0 ];
		const setDate = dateState[ 1 ];

		const slotState = useState( '' );
		const slot = slotState[ 0 ];
		const setSlot = slotState[ 1 ];

		const slotsState = useState( [] );
		const slots = slotsState[ 0 ];
		const setSlots = slotsState[ 1 ];

		const loadingState = useState( false );
		const loading = loadingState[ 0 ];
		const setLoading = loadingState[ 1 ];

		useEffect(
			function () {
				if ( ! date ) {
					setSlots( [] );
					return undefined;
				}
				let active = true;
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
			const value = event.target.value;
			setDate( value );
			setSlot( '' );
			pushSelection( value, '' );
		}

		function onSlotChange( event ) {
			const value = event.target.value;
			setSlot( value );
			pushSelection( date, value );
		}

		const dateSelect = el(
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

		const slotChildren = [
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

		const slotSelect = el(
			'select',
			{
				className: 'lafka-block-timeslot__time',
				value: slot,
				onChange: onSlotChange,
				disabled: ! date || loading,
			},
			slotChildren
		);

		let note = null;
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
