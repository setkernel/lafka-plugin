/**
 * Loyalty points on the block checkout (Lafka_Loyalty_Redeem): a panel in the
 * order summary. Build-free plain ES (wp.element + WooCommerce Blocks
 * globals). The state comes from the `lafka-loyalty` cart extension; using or
 * returning points goes back through extensionCartUpdate and the cart
 * recalculates with WooCommerce's own coupon. Emits namespaced `lafka-loyalty`
 * markup; the theme owns the styling. The coupon that carries the points is
 * listed as "Loyalty points" in the cart and checkout totals (WooCommerce's
 * `coupons` checkout filter), as on the classic checkout, not by its code.
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
	const ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	const extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;
	if ( ! wp.plugins.registerPlugin || ! ExperimentalOrderMeta || ! extensionCartUpdate ) {
		return;
	}

	function Panel( props ) {
		const data = ( props && props.extensions && props.extensions[ 'lafka-loyalty' ] ) || {};
		const [ points, setPoints ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		if ( ! data.applies || props.context === 'woocommerce/cart' ) {
			return null;
		}
		const text = data.text || {};

		const send = function ( payload ) {
			setBusy( true );
			setError( '' );
			extensionCartUpdate( { namespace: 'lafka-loyalty', data: payload } )
				.catch( function ( failure ) {
					setError( ( failure && failure.message ) || '' );
				} )
				.finally( function () {
					setBusy( false );
				} );
		};

		const line = function ( className, content ) {
			return el( 'p', { className: className }, content );
		};

		const body = [];
		if ( ! data.logged_in ) {
			body.push( line( 'lafka-loyalty__text', data.signup ? text.signup : text.login ) );
		} else {
			body.push( line( 'lafka-loyalty__text', text.earn ) );
			body.push( line( 'lafka-loyalty__text lafka-loyalty__balance', text.balance ) );
			if ( data.applied > 0 ) {
				body.push( line( 'lafka-loyalty__applied', text.used ) );
				body.push(
					el(
						'button',
						{
							type: 'button',
							className: 'lafka-btn lafka-btn--ghost',
							disabled: busy,
							onClick: function () {
								send( { action: 'remove' } );
							},
						},
						text.keep
					)
				);
			} else if ( data.can_redeem ) {
				const value = points === '' ? String( data.max ) : points;
				body.push(
					el(
						'div',
						{ className: 'lafka-loyalty__form' },
						el( 'label', { className: 'lafka-loyalty__label', htmlFor: 'lafka-loyalty-points' }, text.points ),
						el( 'input', {
							type: 'number',
							id: 'lafka-loyalty-points',
							className: 'input-text',
							min: data.min,
							max: data.max,
							step: data.step,
							inputMode: 'numeric',
							value: value,
							onChange: function ( event ) {
								setPoints( event.target.value );
							},
						} ),
						el(
							'button',
							{
								type: 'button',
								className: 'lafka-btn lafka-btn--primary',
								disabled: busy,
								onClick: function () {
									send( { action: 'apply', points: parseInt( value, 10 ) || 0 } );
								},
							},
							text.use
						)
					)
				);
				body.push( line( 'lafka-loyalty__hint', text.hint ) );
			} else {
				body.push( line( 'lafka-loyalty__hint', data.balance < data.min ? text.need : text.cap ) );
			}
			if ( error ) {
				body.push( el( 'p', { className: 'lafka-loyalty__error', role: 'alert' }, error ) );
			}
		}

		return el(
			'div',
			{ className: 'lafka-loyalty lafka-card', role: 'group', 'aria-label': text.title },
			el( 'p', { className: 'lafka-loyalty__title' }, text.title ),
			...body
		);
	}

	// The redemption coupon reads "Loyalty points", not its code.
	if ( wc.blocksCheckout.registerCheckoutFilters ) {
		wc.blocksCheckout.registerCheckoutFilters( 'lafka-loyalty', {
			coupons: function ( coupons ) {
				const cartStore = wp.data && wp.data.select ? wp.data.select( 'wc/store/cart' ) : null;
				const cart = cartStore && cartStore.getCartData ? cartStore.getCartData() : null;
				const data = ( cart && cart.extensions && cart.extensions[ 'lafka-loyalty' ] ) || {};
				const code = String( data.coupon || '' ).toLowerCase();
				const label = ( data.text && data.text.title ) || '';
				if ( ! code || ! label || ! Array.isArray( coupons ) ) {
					return coupons;
				}
				return coupons.map( function ( coupon ) {
					return coupon && String( coupon.code ).toLowerCase() === code ? Object.assign( {}, coupon, { label: label } ) : coupon;
				} );
			},
		} );
	}

	wp.plugins.registerPlugin( 'lafka-loyalty', {
		render: function () {
			return el( ExperimentalOrderMeta, null, el( Panel ) );
		},
		scope: 'woocommerce-checkout',
	} );
}() );
