/* global jQuery, lafkaAddonsEngineAdmin */
/**
 * Lafka Addons Engine — admin editor JS.
 *
 * Wires the v2 editor:
 *   - Toggling pricing mode shows/hides the relevant group-level inputs.
 *   - Toggling options source shows/hides the attribute selector + sync btn.
 *   - "Sync options" button POSTs to admin-ajax and rebuilds the option rows.
 *   - "Add option" / "Add group" / "Remove option" / "Remove group" wired
 *     to <template> elements emitted server-side.
 *   - Live group-title display mirror.
 *   - Categories visibility toggles with the "All products" checkbox.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '.lafka-engine-form' );
		if ( ! $form.length ) {
			return;
		}

		// Category visibility toggle.
		$form.on( 'change', '#lafka_addon_applies_to_all', function () {
			$form.find( '.lafka-engine-categories' ).toggle( ! $( this ).is( ':checked' ) );
		} );

		// Group-level events: pricing mode + source toggles.
		$form.on( 'change', '[data-lafka-pricing]', function () {
			var $group = $( this ).closest( '[data-lafka-group]' );
			var mode   = $( this ).val();

			$group.find( '.lafka-engine-flat-group-input' ).toggle( mode === 'flat_group' );
			$group.find( '.lafka-engine-per-size-input' ).toggle( mode === 'flat_per_size' );
			$group.find( '.lafka-engine-matrix-hint' ).toggle( mode === 'matrix' );
			$group.find( '.lafka-engine-size-section' ).toggle( mode === 'flat_per_size' || mode === 'matrix' );
		} );

		$form.on( 'change', '[data-lafka-source]', function () {
			var $group = $( this ).closest( '[data-lafka-group]' );
			$group.find( '.lafka-engine-source-attribute' ).toggle( $( this ).val() === 'attribute' );
		} );

		// Group title live display.
		$form.on( 'input', '[data-lafka-group-name]', function () {
			var $group = $( this ).closest( '[data-lafka-group]' );
			var label  = $( this ).val() || '—';
			$group.find( '.lafka-engine-group__title-display' ).text( label );
		} );

		// Add new group.
		$form.on( 'click', '[data-lafka-add-group]', function ( e ) {
			e.preventDefault();
			var template = document.getElementById( 'lafka-engine-group-template' );
			if ( ! template ) {
				return;
			}
			var nextIndex = $form.find( '[data-lafka-group]' ).length;
			var html = template.innerHTML.replace( /__GROUP_INDEX__/g, nextIndex );
			$form.find( '.lafka-engine-groups' ).append( html );
		} );

		// Remove group.
		$form.on( 'click', '[data-lafka-remove-group]', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( lafkaAddonsEngineAdmin.i18n.confirmRemoveGrp ) ) {
				return;
			}
			$( this ).closest( '[data-lafka-group]' ).remove();
		} );

		// Add new option row.
		$form.on( 'click', '[data-lafka-add-option]', function ( e ) {
			e.preventDefault();
			var $group     = $( this ).closest( '[data-lafka-group]' );
			var groupIndex = $group.attr( 'data-group-index' );
			var $rows      = $group.find( '[data-lafka-option-rows]' );
			var nextIndex  = $rows.children( '[data-lafka-option-row]' ).length;
			var template   = document.getElementById( 'lafka-engine-option-row-template' );
			if ( ! template ) {
				return;
			}
			var html = template.innerHTML
				.replace( /__GROUP_INDEX__/g, groupIndex )
				.replace( /__OPTION_INDEX__/g, nextIndex );
			$rows.append( html );
		} );

		// Remove option row.
		$form.on( 'click', '[data-lafka-remove-option]', function ( e ) {
			e.preventDefault();
			if ( ! window.confirm( lafkaAddonsEngineAdmin.i18n.confirmRemoveOpt ) ) {
				return;
			}
			$( this ).closest( '[data-lafka-option-row]' ).remove();
		} );

		// Sync options from attribute.
		$form.on( 'click', '[data-lafka-sync-attribute]', function ( e ) {
			e.preventDefault();
			var $btn   = $( this );
			var $group = $btn.closest( '[data-lafka-group]' );
			var taxonomy = $group.find( '[data-lafka-source-attribute]' ).val();
			if ( ! taxonomy ) {
				window.alert( lafkaAddonsEngineAdmin.i18n.syncFailed );
				return;
			}

			// Capture existing options so the server can preserve prices/include flags.
			var existing = [];
			$group.find( '[data-lafka-option-row]' ).each( function () {
				var $row     = $( this );
				var label    = $row.find( 'input[name$="[label]"]' ).val() || '';
				var included = $row.find( 'input[type=checkbox][name$="[included]"]:checked' ).length > 0;
				var price    = $row.find( 'input[name$="[price]"]' ).val() || '';
				existing.push( { label: label, included: included, price: price } );
			} );

			$btn.prop( 'disabled', true ).text( '…' );

			$.post( lafkaAddonsEngineAdmin.ajaxUrl, {
				action:   'lafka_engine_sync_attribute',
				nonce:    lafkaAddonsEngineAdmin.syncNonce,
				taxonomy: taxonomy,
				existing: existing
			} ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.alert( ( response && response.data && response.data.message ) || lafkaAddonsEngineAdmin.i18n.syncFailed );
					return;
				}
				rebuildOptionRows( $group, response.data.options || [] );
			} ).fail( function () {
				window.alert( lafkaAddonsEngineAdmin.i18n.syncFailed );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( lafkaAddonsEngineAdmin.i18n.syncOptions || 'Sync options' );
			} );
		} );

		function rebuildOptionRows( $group, options ) {
			var $rows      = $group.find( '[data-lafka-option-rows]' );
			var groupIndex = $group.attr( 'data-group-index' );
			var template   = document.getElementById( 'lafka-engine-option-row-template' );
			if ( ! template ) {
				return;
			}
			$rows.empty();
			options.forEach( function ( option, idx ) {
				var html = template.innerHTML
					.replace( /__GROUP_INDEX__/g, groupIndex )
					.replace( /__OPTION_INDEX__/g, idx );
				var $row = $( html );
				$row.find( 'input[name$="[id]"]' ).val( option.id || '' );
				$row.find( 'input[name$="[label]"]' ).val( option.label || '' );
				$row.find( '.lafka-engine-option-label-readonly' ).text( option.label || '' );
				$row.find( 'input[type=checkbox][name$="[included]"]' ).prop( 'checked', option.included !== false );
				$row.find( 'input[name$="[price]"]' ).val( option.price || '' );
				$rows.append( $row );
			} );
		}
	} );
} )( jQuery );
