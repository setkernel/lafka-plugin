/**
 * Tips on the block checkout (Lafka_Tips): a picker in the order summary.
 * Build-free plain ES (wp.element + WooCommerce Blocks globals). The options
 * come from the `lafka-tips` cart extension; a choice goes back through
 * extensionCartUpdate and the cart recalculates with the tip as a fee. Emits
 * namespaced `lafka-tips` markup; the theme owns the styling.
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

	function send( tip, amount ) {
		return extensionCartUpdate( { namespace: 'lafka-tips', data: { tip: tip, amount: amount } } );
	}

	function TipPicker( props ) {
		const data = ( props && props.extensions && props.extensions[ 'lafka-tips' ] ) || {};
		const [ custom, setCustom ] = useState( data.amount ? String( data.amount ) : '' );
		if ( ! data.applies ) {
			return null;
		}
		const selected = data.selected || 'none';
		const text = data.text || {};

		const button = function ( value, label, note ) {
			const active = selected === value;
			return el(
				'button',
				{
					type: 'button',
					key: value,
					className: 'lafka-tips__option' + ( active ? ' is-selected' : '' ),
					'aria-pressed': active ? 'true' : 'false',
					onClick: function () {
						send( value, value === 'amount' ? parseFloat( custom ) || 0 : 0 );
					},
				},
				label,
				note ? el( 'span', { className: 'lafka-tips__amount' }, note ) : null
			);
		};

		const buttons = [ button( 'none', text.none ) ].concat(
			( data.options || [] ).map( function ( option ) {
				return button( option.value, option.label, option.amount );
			} )
		);

		return el(
			'div',
			{ className: 'lafka-tips', role: 'group', 'aria-label': data.label },
			el( 'p', { className: 'lafka-tips__title' }, data.label ),
			el( 'div', { className: 'lafka-tips__options' }, buttons ),
			data.custom
				? el(
					'form',
					{
						className: 'lafka-tips__custom',
						onSubmit: function ( event ) {
							event.preventDefault();
							send( 'amount', parseFloat( custom ) || 0 );
						},
					},
					el( 'input', {
						type: 'number',
						min: 0,
						max: 1000,
						step: '0.01',
						inputMode: 'decimal',
						value: custom,
						placeholder: text.other,
						'aria-label': text.amount,
						onChange: function ( event ) {
							setCustom( event.target.value );
						},
					} ),
					el( 'button', { type: 'submit', className: 'lafka-tips__option' + ( selected === 'amount' ? ' is-selected' : '' ) }, text.add )
				)
				: null
		);
	}

	wp.plugins.registerPlugin( 'lafka-tips', {
		render: function () {
			return el( ExperimentalOrderMeta, null, el( TipPicker ) );
		},
		scope: 'woocommerce-checkout',
	} );
}() );
