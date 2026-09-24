/**
 * Load a front-end script into a linkedom page and collect its dataLayer.
 */
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

/**
 * @param {string} script Path relative to the plugin root.
 * @param {string} html   Page body markup.
 * @param {object} [globals] Extra window globals (e.g. clarity, dataLayer).
 */
export function loadScript( script, html, globals = {} ) {
	const source = readFileSync( new URL( '../../../' + script, import.meta.url ), 'utf8' );
	const dom = parseHTML( `<!doctype html><html><head></head><body>${ html }</body></html>` );
	const document = dom.document;
	const listeners = {};
	// A plain window: the page's globals plus captured window-level listeners
	// (scroll / load / popstate) that tests fire by hand.
	const window = {
		document,
		Event: dom.Event,
		location: new URL( 'https://shop.example.test/menu/?x=1' ),
		innerHeight: 0,
		pageYOffset: 0,
		addEventListener: ( type, fn ) => ( listeners[ type ] = listeners[ type ] || [] ).push( fn ),
		requestAnimationFrame: ( fn ) => fn(),
		...globals,
	};

	vm.runInNewContext( source, {
		window,
		document,
		URL,
		IntersectionObserver: window.IntersectionObserver,
		MutationObserver: window.MutationObserver,
	} );

	return {
		window,
		document,
		/** Plain copy of the dataLayer (VM objects have foreign prototypes). */
		get dataLayer() {
			return JSON.parse( JSON.stringify( window.dataLayer || [] ) );
		},
		click( selector ) {
			document.querySelector( selector ).dispatchEvent( new window.Event( 'click', { bubbles: true } ) );
		},
		change( selector ) {
			document.querySelector( selector ).dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		},
		fireWindow( type ) {
			( listeners[ type ] || [] ).forEach( ( fn ) => fn() );
		},
	};
}

/** Event objects only (drops the { ecommerce: null } resets). */
export const events = ( dataLayer ) => dataLayer.filter( ( entry ) => entry.event );
