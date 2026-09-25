/**
 * incl/addons/assets/js/addons.js — T-19 add-on group disclosure: the
 * heading's <button aria-expanded> toggles its own state and the group's
 * data-collapsed; plain headings (themes without the opt-in) are untouched.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { loadScript } from './support/dom.mjs';

const SCRIPT = 'incl/addons/assets/js/addons.js';

// addons.js wraps its pricing code in jQuery( document ).ready(); a no-op
// stand-in is enough here — the disclosure code is plain DOM.
const jQuery = () => ( { ready: () => {} } );

const GROUPS = `
	<div class="product-addon product-addon-toppings">
		<h3 class="addon-name"><button type="button" class="lafka-addon-toggle" aria-expanded="true" aria-controls="lafka-addon-body-1">Toppings <abbr class="required">*</abbr></button></h3>
		<div class="lafka-addon-body" id="lafka-addon-body-1"><p class="form-row">x</p></div>
	</div>
	<div class="product-addon product-addon-sauce">
		<h3 class="addon-name">Sauce</h3>
	</div>`;

test( 'the button collapses and expands its group', () => {
	const p = loadScript( SCRIPT, GROUPS, { jQuery } );
	const button = p.document.querySelector( '.lafka-addon-toggle' );
	const group = p.document.querySelector( '.product-addon-toppings' );

	p.click( '.lafka-addon-toggle' );
	assert.equal( button.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal( group.getAttribute( 'data-collapsed' ), 'true' );

	p.click( '.lafka-addon-toggle' );
	assert.equal( button.getAttribute( 'aria-expanded' ), 'true' );
	assert.equal( group.getAttribute( 'data-collapsed' ), 'false' );
} );

test( 'a click on the required marker inside the button still toggles', () => {
	const p = loadScript( SCRIPT, GROUPS, { jQuery } );
	p.click( '.lafka-addon-toggle abbr' );
	assert.equal( p.document.querySelector( '.lafka-addon-toggle' ).getAttribute( 'aria-expanded' ), 'false' );
} );

test( 'plain headings are left alone', () => {
	const p = loadScript( SCRIPT, GROUPS, { jQuery } );
	p.click( '.product-addon-sauce .addon-name' );
	assert.equal( p.document.querySelector( '.product-addon-sauce' ).getAttribute( 'data-collapsed' ), null );
} );
