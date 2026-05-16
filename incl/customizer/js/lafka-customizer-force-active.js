/**
 * Force-active the lafka_restaurant_info panel and its sections.
 *
 * WP Customizer's JS-side _isContextuallyActive() evaluator cascades
 * panel/section visibility from control.active(). On this site, some
 * external influence (third-party plugin, admin theme, or browser
 * extension) is initialising the controls under this panel with
 * active=false. The PHP-side active_callback=__return_true gets
 * overwritten by JS re-evaluation, collapsing the panel.
 *
 * This script runs after wp.customize.bind('ready') and:
 *   1. Locks panel.active to true
 *   2. Locks each section.active to true
 *   3. Locks each control.active under the panel to true
 *   4. Re-runs the visibility binding so the panel re-appears
 *
 * The .validate hook keeps active=true permanently — any future
 * .active.set(false) call returns false from validate, no-ops the change.
 *
 * @package Lafka\Plugin\Customizer
 * @since   9.16.0
 */

( function ( api ) {
	'use strict';

	if ( ! api ) {
		return;
	}

	var TARGET_PANEL = 'lafka_restaurant_info';

	api.bind( 'ready', function () {
		var panel = api.panel( TARGET_PANEL );
		if ( ! panel ) {
			return;
		}

		// Lock panel active=true. The validate hook intercepts any later
		// .set(false) call and returns true instead.
		var lockTrue = function ( /* value */ ) {
			return true;
		};

		panel.active.validate = lockTrue;
		panel.active.set( true );

		// Lock each section that lives under this panel.
		api.section.each( function ( section ) {
			if ( section.panel.get() === TARGET_PANEL ) {
				section.active.validate = lockTrue;
				section.active.set( true );
			}
		} );

		// Lock each control whose section is under this panel.
		api.control.each( function ( control ) {
			var sectionId = control.section.get();
			var section = api.section( sectionId );
			if ( section && section.panel.get() === TARGET_PANEL ) {
				control.active.validate = lockTrue;
				control.active.set( true );
			}
		} );

		// Defensive: if the panel container was already hidden by the
		// time we ran, show it back. Future set(false) is blocked by
		// the validate hook above.
		panel.container.show();
	} );
} )( window.wp && window.wp.customize );
