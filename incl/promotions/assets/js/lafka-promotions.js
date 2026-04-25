/*
 * Lafka_Promotions — dismissible banner.
 *
 * Extracted from inline <script> in lafka-child/functions.php (P2-01).
 * Reads PROMO_KEY + DISMISS_DAYS from window.LAFKA_PROMO (wp_localize_script
 * payload from class-lafka-promotions.php). Banner stays dismissed for
 * DISMISS_DAYS via localStorage; PROMO_KEY rotation re-arms the banner
 * for everyone (intentional — new promo, new key).
 */

(function () {
	'use strict';

	if ( typeof window === 'undefined' || ! window.LAFKA_PROMO ) {
		return;
	}

	var PROMO_KEY    = String( window.LAFKA_PROMO.promoKey || '' );
	var DISMISS_DAYS = parseInt( window.LAFKA_PROMO.dismissDays, 10 ) || 7;
	var DISMISS_KEY  = 'lafka_bogo_dismissed_' + PROMO_KEY;
	var DAY_MS       = 86400000;

	function isDismissed() {
		try {
			var ts = window.localStorage.getItem( DISMISS_KEY );
			if ( ! ts ) return false;
			return ( Date.now() - parseInt( ts, 10 ) ) < ( DISMISS_DAYS * DAY_MS );
		} catch ( e ) {
			return false;
		}
	}

	function dismiss( banner ) {
		try { window.localStorage.setItem( DISMISS_KEY, Date.now().toString() ); } catch ( e ) {}
		banner.classList.remove( 'is-visible' );
	}

	function init() {
		var banner = document.getElementById( 'lafka-bogo-banner' );
		if ( ! banner ) return;

		var closeBtn = banner.querySelector( '.lafka-bogo-close' );
		if ( ! closeBtn ) return;

		if ( isDismissed() ) {
			return;
		}

		banner.removeAttribute( 'hidden' );
		// rAF so the transition fires after the unhide.
		window.requestAnimationFrame( function () {
			banner.classList.add( 'is-visible' );
		} );

		closeBtn.addEventListener( 'click', function () {
			dismiss( banner );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
