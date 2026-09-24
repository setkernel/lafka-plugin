/**
 * assets/js/lafka-custom-events.js — interaction events land in dataLayer
 * with the section of the page they happened in.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript, events } from './support/dom.mjs';

const SCRIPT = 'assets/js/lafka-custom-events.js';

const page = ( html ) => loadScript( SCRIPT, html, { dataLayer: [] } );

test( 'phone and email clicks carry the number/address and the page section', () => {
	const p = page( `
		<header class="site-header"><a id="tel" href="tel:+1 555 0100">Call</a></header>
		<footer><a id="mail" href="mailto:hi@example.test?subject=Order">Mail</a></footer>
		<div class="lafka-announce-bar"><span><a id="tel2" href="tel:5550199">Call</a></span></div>` );

	p.click( '#tel' );
	p.click( '#mail' );
	p.click( '#tel2' );

	assert.deepEqual( events( p.dataLayer ), [
		{ event: 'phone_click', phone_number: '+1 555 0100', source: 'header' },
		{ event: 'email_click', email: 'hi@example.test', source: 'footer' },
		{ event: 'phone_click', phone_number: '5550199', source: 'announce_bar' },
	] );
} );

test( 'menu chips report filter_apply with their type', () => {
	const p = page( `
		<div class="lafka-menu">
			<button class="lafka-menu__chip" data-filter-value="vegan">Vegan</button>
			<button class="lafka-menu__category-chip"><span id="cat">Pizzas</span></button>
		</div>` );

	p.click( '.lafka-menu__chip' );
	p.click( '#cat' );

	assert.deepEqual( events( p.dataLayer ), [
		{ event: 'filter_apply', filter_value: 'vegan', filter_type: 'dietary' },
		{ event: 'filter_apply', filter_value: 'Pizzas', filter_type: 'category' },
	] );
} );

test( 'directions and foreign links are told apart from internal ones', () => {
	const p = page( `
		<div class="lafka-contact">
			<a id="maps" href="https://maps.google.com/?q=1">Map</a>
			<a id="dir" href="/visit">Get directions</a>
		</div>
		<a id="out" href="https://social.example.org/our-page">Follow</a>
		<a id="sub" href="https://blog.example.test/post">Blog</a>
		<a id="rel" href="/menu/pizzas/">Pizzas</a>` );

	for ( const id of [ '#maps', '#dir', '#out', '#sub', '#rel' ] ) {
		p.click( id );
	}

	assert.deepEqual( events( p.dataLayer ), [
		{ event: 'get_directions_click', source: 'contact' },
		{ event: 'get_directions_click', source: 'contact' },
		{ event: 'outbound_link', destination_host: 'social.example.org', source: 'unknown' },
	] );
} );

test( 'scroll milestones fire once each, and again after a history navigation', () => {
	const p = page( '<main></main>' );
	// A 2000px page in a 1000px viewport (layout metrics jsdom-style DOMs lack).
	for ( const el of [ p.document.documentElement, p.document.body ] ) {
		Object.defineProperty( el, 'scrollHeight', { value: 2000 } );
		Object.defineProperty( el, 'offsetHeight', { value: 2000 } );
	}
	p.window.innerHeight = 1000;

	p.window.pageYOffset = 600; // 60 %
	p.fireWindow( 'scroll' );
	p.fireWindow( 'scroll' );
	p.window.pageYOffset = 1000; // 100 %
	p.fireWindow( 'scroll' );

	const percents = () => events( p.dataLayer ).map( ( e ) => e.percent );
	assert.deepEqual( percents(), [ 25, 50, 75, 100 ] );
	assert.equal( events( p.dataLayer )[ 0 ].page_path, '/menu/?x=1' );

	p.fireWindow( 'popstate' );
	p.fireWindow( 'scroll' );
	assert.deepEqual( percents(), [ 25, 50, 75, 100, 25, 50, 75, 100 ] );
} );

test( 'nothing is pushed (and nothing throws) without a dataLayer', () => {
	const p = loadScript( SCRIPT, '<a id="tel" href="tel:1">x</a>' );
	p.click( '#tel' );
	assert.deepEqual( p.dataLayer, [] );
} );
