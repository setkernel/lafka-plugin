/**
 * Installable app: registers the site's one service worker (the theme's file,
 * also used for Web Push), lets it keep the menu's files for offline use, and
 * offers "Add to home screen" to repeat visitors.
 *
 * The card appears only when ALL of these hold: the setting is on, the site is
 * not already running as an app, the visitor has not dismissed it in the last
 * `snooze` days, this is the `visits`-th visit or the visitor has ordered, and
 * no other prompt (Web Push, review, exit intent, cookie consent, a dialog) is
 * on screen. It is the lowest-priority prompt: if another one opens while the
 * card is up, the card steps aside.
 *
 *   Chrome / Android  the browser's `beforeinstallprompt`, held until the card's
 *                     button is pressed.
 *   iOS               a one-time hint to use Share > Add to Home Screen.
 *
 * Configuration: window.lafkaPwa (wp_add_inline_script):
 * { swUrl, scope, menuPath, prompt, visits, snooze, ordered }
 *
 * @since 10.4.0
 */
( function ( w, d ) {
	'use strict';

	const cfg = w.lafkaPwa;
	if ( ! cfg || ! cfg.swUrl ) {
		return;
	}
	const nav = w.navigator;
	const KEY_VISITS = 'lafka_pwa_visits';
	const KEY_ORDERED = 'lafka_pwa_ordered';
	const KEY_DISMISSED = 'lafka_pwa_dismissed_at';
	const KEY_IOS_SEEN = 'lafka_pwa_ios_hint_seen';
	const KEY_SESSION = 'lafka_pwa_session';
	const OTHER_PROMPTS = '.lafka-push-prompt[data-visible="true"], .lafka-review-banner[data-visible="true"], .lafka-exit-toast, html.lafka-consent-open, dialog[open]';

	function read( store, key ) {
		try {
			return w[ store ].getItem( key );
		} catch {
			return null;
		}
	}

	function write( store, key, value ) {
		try {
			w[ store ].setItem( key, value );
		} catch {
			// Private browsing: the card just cannot remember.
		}
	}

	function track( event, params ) {
		if ( w.lafka && typeof w.lafka.track === 'function' ) {
			w.lafka.track( event, params );
		}
	}

	function standalone() {
		return ( w.matchMedia && w.matchMedia( '(display-mode: standalone)' ).matches ) || nav.standalone === true;
	}

	// ---- The service worker ------------------------------------------------

	/**
	 * Tell the worker which static files this menu page uses, so the menu can
	 * be shown offline after one visit (it refreshes them on every visit).
	 *
	 * @param {ServiceWorkerRegistration} reg Registration.
	 */
	function shareMenuFiles( reg ) {
		const path = w.location.pathname.replace( /\/?$/, '/' );
		if ( path !== cfg.menuPath || ! reg.active ) {
			return;
		}
		const urls = new Set();
		w.performance.getEntriesByType( 'resource' ).forEach( ( entry ) => urls.add( entry.name ) );
		d.querySelectorAll( 'img' ).forEach( ( img ) => {
			if ( img.currentSrc || img.src ) {
				urls.add( img.currentSrc || img.src );
			}
		} );
		reg.active.postMessage( { type: 'lafka-cache-files', urls: Array.from( urls ) } );
	}

	function registerWorker() {
		if ( ! ( 'serviceWorker' in nav ) ) {
			return;
		}
		nav.serviceWorker
			.register( cfg.swUrl, { scope: cfg.scope || '/' } )
			.then( () => nav.serviceWorker.ready )
			.then( shareMenuFiles )
			.catch( () => {} );
	}

	// ---- The card ----------------------------------------------------------

	const card = d.querySelector( '.lafka-install-card' );
	let deferred = null;
	let shown = false;
	let yielded = false;

	function isIos() {
		const ua = nav.userAgent || '';
		return /iPad|iPhone|iPod/.test( ua ) || ( nav.platform === 'MacIntel' && nav.maxTouchPoints > 1 );
	}

	function snoozed() {
		const at = parseInt( read( 'localStorage', KEY_DISMISSED ) || '0', 10 );
		return at > 0 && Date.now() - at < cfg.snooze * 86400000;
	}

	function eligible() {
		const visits = parseInt( read( 'localStorage', KEY_VISITS ) || '0', 10 );
		return cfg.ordered || '1' === read( 'localStorage', KEY_ORDERED ) || visits >= cfg.visits;
	}

	function otherPromptOpen() {
		return null !== d.querySelector( OTHER_PROMPTS );
	}

	function hide() {
		if ( ! card ) {
			return;
		}
		card.removeAttribute( 'data-visible' );
		card.hidden = true;
		shown = false;
	}

	function dismiss( remember ) {
		hide();
		if ( remember ) {
			write( 'localStorage', KEY_DISMISSED, String( Date.now() ) );
		}
	}

	function show( ios ) {
		card.querySelectorAll( '[data-lafka-install-android]' ).forEach( ( el ) => {
			el.hidden = ios;
		} );
		card.querySelectorAll( '[data-lafka-install-ios]' ).forEach( ( el ) => {
			el.hidden = ! ios;
		} );
		card.hidden = false;
		void card.offsetWidth;
		card.setAttribute( 'data-visible', 'true' );
		shown = true;
		track( 'pwa_install_prompt_shown', { platform: ios ? 'ios' : 'android' } );
		if ( ios ) {
			write( 'localStorage', KEY_IOS_SEEN, '1' );
		}
	}

	function bindCard() {
		card.querySelector( '.lafka-install-card__close' ).addEventListener( 'click', () => {
			track( 'pwa_install_prompt_dismiss', {} );
			dismiss( true );
		} );
		card.querySelectorAll( '.lafka-install-card__later' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				track( 'pwa_install_prompt_dismiss', {} );
				dismiss( true );
			} );
		} );
		card.querySelector( '.lafka-install-card__install' ).addEventListener( 'click', () => {
			if ( ! deferred ) {
				return;
			}
			const event = deferred;
			deferred = null;
			hide();
			event.prompt();
			event.userChoice.then( ( choice ) => {
				track( 'pwa_install_prompt_' + ( 'accepted' === choice.outcome ? 'accept' : 'dismiss' ), {} );
				if ( 'accepted' !== choice.outcome ) {
					write( 'localStorage', KEY_DISMISSED, String( Date.now() ) );
				}
			} );
		} );
		d.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key && shown ) {
				dismiss( true );
			}
		} );
		// The lowest-priority prompt: step aside, without snoozing, when another opens.
		new w.MutationObserver( () => {
			if ( shown && otherPromptOpen() ) {
				yielded = true;
				hide();
			}
		} ).observe( d.body, { subtree: true, childList: true, attributes: true, attributeFilter: [ 'data-visible', 'class', 'open' ] } );
	}

	function attempt( tries ) {
		if ( shown || yielded || ! eligible() || snoozed() || standalone() ) {
			return;
		}
		if ( otherPromptOpen() ) {
			if ( tries < 6 ) {
				w.setTimeout( () => attempt( tries + 1 ), 5000 );
			}
			return;
		}
		if ( deferred ) {
			show( false );
		} else if ( isIos() && '1' !== read( 'localStorage', KEY_IOS_SEEN ) ) {
			show( true );
		}
	}

	function initCard() {
		if ( ! cfg.prompt || ! card || standalone() ) {
			return;
		}
		if ( cfg.ordered ) {
			write( 'localStorage', KEY_ORDERED, '1' );
		}
		bindCard();
		w.addEventListener( 'beforeinstallprompt', ( event ) => {
			event.preventDefault();
			deferred = event;
			w.setTimeout( () => attempt( 0 ), 3000 );
		} );
		w.addEventListener( 'appinstalled', () => {
			deferred = null;
			hide();
			track( 'pwa_installed', {} );
			write( 'localStorage', KEY_DISMISSED, String( Date.now() ) );
		} );
		if ( isIos() ) {
			w.setTimeout( () => attempt( 0 ), 3000 );
		}
	}

	// ---- Boot --------------------------------------------------------------

	// One visit = one browsing session.
	if ( ! read( 'sessionStorage', KEY_SESSION ) ) {
		write( 'sessionStorage', KEY_SESSION, '1' );
		write( 'localStorage', KEY_VISITS, String( ( parseInt( read( 'localStorage', KEY_VISITS ) || '0', 10 ) || 0 ) + 1 ) );
	}

	initCard();
	if ( 'complete' === d.readyState ) {
		registerWorker();
	} else {
		w.addEventListener( 'load', registerWorker );
	}
}( window, document ) );
