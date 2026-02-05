/* global wc_pb_min_max_items_params */

;( function ( $, window, document ) {

	function init_script( combo ) {

		if ( typeof( combo.price_data.size_min ) === 'undefined' || typeof( combo.price_data.size_max ) === 'undefined' ) {
			return;
		}

		combo.min_max_validation = {

			min: combo.price_data.size_min,
			max: combo.price_data.size_max,

			bind_validation_handler: function() {

				var min_max_validation = this;

				combo.$combo_data.on( 'woocommerce-product-combo-validate', function( event, combo ) {

					var total_qty         = 0,
					    qty_error_status  = '',
					    qty_error_prompt  = '',
					    passed_validation = true;

					// Count items.
					$.each( combo.combined_items, function( index, combined_item ) {
						if ( combined_item.is_selected() ) {
							total_qty += combined_item.get_quantity();
						}
					} );

					// Validate.
					if ( min_max_validation.min !== '' && total_qty < parseInt( min_max_validation.min ) ) {

						passed_validation = false;

						if ( min_max_validation.min === 1 ) {

							if ( min_max_validation.min === min_max_validation.max ) {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_zero_max_qty_error_singular;
							} else {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_qty_error_singular;
							}

						} else {

							if ( min_max_validation.min === min_max_validation.max ) {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_max_qty_error_plural;
							} else {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_qty_error_plural;
							}

							qty_error_prompt = qty_error_prompt.replace( '%q', parseInt( min_max_validation.min ) );
						}

					} else if ( min_max_validation.max !== '' && total_qty > parseInt( min_max_validation.max ) ) {

						passed_validation = false;

						if ( min_max_validation.max === 1 ) {

							if ( min_max_validation.min === min_max_validation.max ) {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_max_qty_error_singular;
							} else {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_max_qty_error_singular;
							}

						} else {

							if ( min_max_validation.min === min_max_validation.max ) {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_min_max_qty_error_plural;
							} else {
								qty_error_prompt = wc_pb_min_max_items_params.i18n_max_qty_error_plural;
							}

							qty_error_prompt = qty_error_prompt.replace( '%q', parseInt( min_max_validation.max ) );
						}
					}

					// Add notice.
					if ( ! passed_validation ) {

						if ( total_qty === 0 ) {

							qty_error_status = '';

							if ( 'no' === combo.price_data.zero_items_allowed ) {

								var validation_messages         = combo.get_validation_messages(),
									cleaned_validation_messages = [];

								for ( var i = 0; i <= validation_messages.length - 1; i++ ) {
									if ( validation_messages[ i ] !== wc_combo_params.i18n_zero_qty_error ) {
										cleaned_validation_messages.push( validation_messages[ i ] );
									}
								}

								combo.validation_messages = cleaned_validation_messages;
							}

						} else if ( total_qty === 1 ) {
							qty_error_status = wc_pb_min_max_items_params.i18n_qty_error_singular;
						} else {
							qty_error_status = wc_pb_min_max_items_params.i18n_qty_error_plural;
						}

						qty_error_status = qty_error_status.replace( '%s', total_qty );

						if ( combo.validation_messages.length > 0 || '' === qty_error_status ) {
							combo.add_validation_message( qty_error_prompt );
						} else {
							combo.add_validation_message( '<span class="status_msg">' + '<span class="combined_items_selection_msg">' + qty_error_prompt + '</span>' + '<span class="combined_items_selection_status">' + qty_error_status + '</span>' + '</span>' );
						}
					}

				} );
			}

		};

		combo.min_max_validation.bind_validation_handler();
	}

	$( 'body .component' ).on( 'wc-composite-component-loaded', function( event, component ) {
		if ( component.get_selected_product_type() === 'combo' ) {
			var combo = component.get_combo_script();
			if ( combo ) {
				init_script( combo );
				combo.update_combo_task();
			}
		}
	} );

	$( '.combo_form .combo_data' ).each( function() {

		$( this ).on( 'woocommerce-product-combo-initializing', function( event, combo ) {
			if ( ! combo.is_composited() ) {
				init_script( combo );
			}
		} );
	} );

} ) ( jQuery, window, document );
