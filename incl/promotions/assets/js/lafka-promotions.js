/*
 * Lafka_Promotions — dismissible banner.
 *
 * Reads the dismissal key + DISMISS_DAYS from window.LAFKA_PROMO
 * (wp_localize_script payload from class-lafka-promotions.php). The key is
 * built ONCE in PHP (Lafka_Promotions::dismiss_key()) and shared with the
 * pre-paint head check, so the two can never disagree. A dismissal is kept
 * for DISMISS_DAYS in localStorage; a new promo key re-arms the banner.
 *
 * The in-flow banner is rendered visible (no layout shift after load); a
 * recent dismissal is hidden before paint by the head check. The legacy
 * fixed overlay keeps its slide-in. Closing hides the banner for real
 * (`hidden`, whatever its placement) and moves keyboard focus to the next
 * control on the page instead of dropping it on <body>.
 */

( function () {
	'use strict';

	if ( typeof window === 'undefined' || ! window.LAFKA_PROMO ) {
		return;
	}

	var PROMO_KEY = String( window.LAFKA_PROMO.promoKey || '' );
	var DISMISS_KEY = String( window.LAFKA_PROMO.dismissKey || 'lafka_bogo_dismissed_' + PROMO_KEY );
	var DISMISS_DAYS = parseInt( window.LAFKA_PROMO.dismissDays, 10 ) || 7;
	var DAY_MS = 86400000;
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function isDismissed() {
		try {
			var ts = window.localStorage.getItem( DISMISS_KEY );
			if ( ! ts ) {
				return false;
			}
			return ( Date.now() - parseInt( ts, 10 ) ) < ( DISMISS_DAYS * DAY_MS );
		} catch {
			return false;
		}
	}

	function nextFocusable( banner ) {
		var all = document.querySelectorAll( FOCUSABLE );
		for ( var i = 0; i < all.length; i++ ) {
			var el = all[ i ];
			if ( banner.contains( el ) ) {
				continue;
			}
			if ( ! ( banner.compareDocumentPosition( el ) & 4 ) ) {
				continue; // Not after the banner.
			}
			if ( el.closest && el.closest( '[hidden], [inert], [aria-hidden="true"]' ) ) {
				continue;
			}
			return el;
		}
		return null;
	}

	function dismiss( banner ) {
		try {
			window.localStorage.setItem( DISMISS_KEY, Date.now().toString() );
		} catch {
			// Private mode / storage full — the banner just won't stay dismissed.
		}
		var hadFocus = banner.contains( document.activeElement );
		var next = hadFocus ? nextFocusable( banner ) : null;
		banner.classList.remove( 'is-visible' );
		banner.hidden = true;
		document.documentElement.classList.add( 'lafka-bogo-dismissed' );
		if ( hadFocus ) {
			if ( next && typeof next.focus === 'function' ) {
				next.focus();
			} else {
				var main = document.querySelector( 'main, #content, [role="main"]' );
				if ( main ) {
					if ( ! main.hasAttribute( 'tabindex' ) ) {
						main.setAttribute( 'tabindex', '-1' );
					}
					main.focus();
				}
			}
		}
	}

	function init() {
		var banner = document.getElementById( 'lafka-bogo-banner' );
		if ( ! banner ) {
			return;
		}
		var closeBtn = banner.querySelector( '.lafka-bogo-close' );
		if ( ! closeBtn ) {
			return;
		}

		if ( isDismissed() ) {
			banner.hidden = true;
			return;
		}

		if ( banner.classList.contains( 'lafka-bogo-banner--fixed' ) ) {
			banner.removeAttribute( 'hidden' );
			// rAF so the slide-in transition fires after the unhide.
			window.requestAnimationFrame( function () {
				banner.classList.add( 'is-visible' );
			} );
		}

		closeBtn.addEventListener( 'click', function () {
			dismiss( banner );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
