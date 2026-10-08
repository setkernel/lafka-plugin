/**
 * Lafka Shipping Settings: show a dependent setting only when the setting it
 * depends on makes it meaningful.
 *
 *   "Mandatory to pick address"  only when the checkout pin map is on;
 *   "Hide Address Fields"        only when pickup is an order type.
 *
 * (The handlers for the retired Lafka shipping method's zone modal were
 * removed with that method: WooCommerce Distance Rate Shipping replaced it in
 * 9.1.0, and no screen renders its fields.)
 */
( function ( $ ) {
	'use strict';

	/**
	 * Toggle the settings row of `dependent` whenever `controller` changes.
	 *
	 * @param {string}   controller Selector of the controlling field.
	 * @param {string}   dependent  Selector of the dependent field.
	 * @param {Function} shown      Receives the controller value; true shows the row.
	 */
	function dependsOn( controller, dependent, shown ) {
		const form = $( '#lafka-plugin-shipping-areas-form' );
		const field = form.find( controller );
		const row = form.find( dependent ).closest( 'tr' );
		if ( ! field.length || ! row.length ) {
			return;
		}
		field
			.on( 'change', function () {
				row.toggle( shown( $( this ).val() ) );
			} )
			.trigger( 'change' );
	}

	$( function () {
		dependsOn( '#pick_delivery_address', '#mandatory_pickup_delivery', ( value ) => '' !== value );
		dependsOn( '#order_type', '#hide_address_fields', ( value ) => 'delivery_pickup' === value || 'pickup' === value );
	} );
} )( window.jQuery );
