;(function ( $ ) {
	'use strict';

	/**
	 * Swatch form behaviour; availability follows the WooCommerce variation hooks.
	 */
	$.fn.lafka_wcs_variation_swatches_form = function () {
		return this.each( function() {
			var $form = $( this ),
				selected = [];

			$form
				.addClass( 'swatches-support' )
				.on( 'click', '.swatch', function ( e ) {
					e.preventDefault();
					var $el = $( this ),
						$select = $el.closest( '.value' ).find( 'select' ),
						attribute_name = $select.data( 'attribute_name' ) || $select.attr( 'name' ),
						value = $el.data( 'value' );

					$select.trigger( 'focusin' );

					// Check if this combination is available
					if ( ! $select.find( 'option[value="' + value + '"]' ).length ) {
						$el.siblings( '.swatch' ).removeClass( 'selected' );
						$select.val( '' ).trigger( 'change' );
						$form.trigger( 'lafka-wcs_no_matching_variations', [$el] );
						return;
					}

					if ( selected.indexOf( attribute_name ) === -1 ) {
						selected.push(attribute_name);
					}

					if ( $el.hasClass( 'selected' ) ) {
						$select.val( '' );
						$el.removeClass( 'selected' );

						delete selected[selected.indexOf(attribute_name)];
					} else {
						$el.addClass( 'selected' ).siblings( '.selected' ).removeClass( 'selected' );
						$select.val( value );
					}

					$select.trigger( 'change' );
				} )
				.on( 'click', '.reset_variations', function () {
					$( this ).closest( '.variations_form' ).find( '.swatch.selected' ).removeClass( 'selected' );
					selected = [];
				} )
				.on( 'lafka-wcs_no_matching_variations', function() {
                    if(typeof wc_add_to_cart_variation_params !== 'undefined') {
                        window.alert(wc_add_to_cart_variation_params.i18n_no_matching_variations_text);
                    }
				} );
		} );
	};

	$(function () {
		$(document).ready(function () {
			$(document.body).find('.variations').lafka_wcs_variation_swatches_form();
			$(document.body).trigger('lafka-wcs_initialized');
		});
	});
})( jQuery );