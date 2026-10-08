/**
 * "Your usual?" card, guest path: post the signed reorder request, then send
 * the customer to the cart. (Logged-in customers get WooCommerce's own
 * "order again" link instead, which restores add-ons too.)
 *
 * The button carries everything it needs: data-lafka-reorder (order id),
 * data-nonce, data-ajax-url, data-cart-url and data-fail-text.
 */
( function () {
	'use strict';

	function fail( button ) {
		button.disabled = false;
		button.removeAttribute( 'aria-busy' );
		button.textContent = button.getAttribute( 'data-fail-text' ) || button.textContent;
	}

	document.addEventListener( 'click', function ( event ) {
		const button = event.target && event.target.closest ? event.target.closest( 'button[data-lafka-reorder]' ) : null;
		if ( ! button || button.disabled ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		const body = new URLSearchParams();
		body.set( 'action', 'lafka_pdp_reorder' );
		body.set( 'nonce', button.getAttribute( 'data-nonce' ) || '' );
		body.set( 'order_id', button.getAttribute( 'data-lafka-reorder' ) || '' );

		window.fetch( button.getAttribute( 'data-ajax-url' ), {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && ! ( result.data && false === result.data.success ) ) {
					window.location.href = button.getAttribute( 'data-cart-url' );
					return;
				}
				fail( button );
			} )
			.catch( function () {
				fail( button );
			} );
	} );
}() );
