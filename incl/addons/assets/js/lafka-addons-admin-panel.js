/**
 * Lafka Product Addons — Admin Panel JS
 *
 * Extracted from inline <script> blocks in:
 *   - html-addon-panel.php   (main addon editor logic)
 *   - html-global-admin-add.php (metabox toggle for global add-on page)
 *
 * All PHP-interpolated values are passed via wp_localize_script()
 * as the global object `lafka_addons_admin_params`.
 *
 * @since 8.6.0
 */

/* global jQuery, lafka_addons_admin_params */
(function ( $ ) {
	'use strict';

	var params = lafka_addons_admin_params;

	// ──────────────────────────────────────────────
	// 1. Global Add-on page — metabox open / close
	// ──────────────────────────────────────────────
	function openclose() {
		$( '.wc-metabox' ).toggleClass( 'closed' ).toggleClass( 'open' );
	}

	$( '.wc-metaboxes-wrapper' )
		.on( 'click', '.wc-metabox h3', function ( event ) {
			if ( $( event.target ).filter( ':input, option' ).length ) {
				return;
			}
			$( this ).next( '.wc-metabox-content' ).toggle();
			openclose();
		})
		.on( 'click', '.expand_all', function () {
			$( this ).closest( '.wc-metaboxes-wrapper' ).find( '.wc-metabox > table' ).show();
			openclose();
			return false;
		})
		.on( 'click', '.close_all', function () {
			$( this ).closest( '.wc-metaboxes-wrapper' ).find( '.wc-metabox > table' ).hide();
			openclose();
			return false;
		});

	$( '.wc-metabox.closed' ).each( function () {
		$( this ).find( '.wc-metabox-content' ).hide();
	});

	// ──────────────────────────────────────────────
	// 2. Addon panel — validation, dynamic rows, etc.
	// ──────────────────────────────────────────────
	$( function () {

		// --- Validate addon names on save ---
		$( '.product_page_global_addons' ).on( 'click', 'input[type="submit"]', function ( e ) {
			$( '.lafka_product_addons' ).find( '.lafka_product_addon' ).each( function () {
				if ( 0 === $( this ).find( '.addon_name input' ).val().length ) {
					e.preventDefault();
					alert( params.empty_name_message );
					return false;
				}
			});
		});

		$( '#product_addons_data' )
			// --- Update group name in header on change ---
			.on( 'change', '.addon_name input', function () {
				if ( $( this ).val() ) {
					$( this ).closest( '.lafka_product_addon' ).find( 'span.group_name' ).text( '"' + $( this ).val() + '"' );
				} else {
					$( this ).closest( '.lafka_product_addon' ).find( 'span.group_name' ).text( '' );
				}
			})

			// --- Type change: show/hide min-max, price cols, update column title ---
			.on( 'change', 'select.product_addon_type', function () {
				var value = $( this ).val();
				var column_title;

				if ( value === 'textarea' ) {
					$( this ).closest( '.lafka_product_addon' ).find( 'td.minmax_column, th.minmax_column' ).show();
				} else {
					$( this ).closest( '.lafka_product_addon' ).find( 'td.minmax_column, th.minmax_column' ).hide();
				}

				if ( value === 'custom_price' ) {
					$( this ).closest( '.lafka_product_addon' ).find( 'td.price_column, th.price_column' ).hide();
				} else {
					$( this ).closest( '.lafka_product_addon' ).find( 'td.price_column, th.price_column' ).show();
				}

				switch ( value ) {
					case 'textarea':
						column_title = params.min_max_characters_label;
						break;
					default:
						column_title = params.min_max_label;
						break;
				}

				$( this ).closest( '.lafka_product_addon' ).find( 'th.minmax_column .column-title' )
					.replaceWith( '<span class="column-title">' + column_title + '</span>' );

				// Disable remove buttons when only one option
				var removeButtons = $( this ).closest( '.lafka_product_addon' ).find( 'button.remove_addon_option' );
				if ( 2 > removeButtons.length ) {
					removeButtons.attr( 'disabled', 'disabled' );
				} else {
					removeButtons.removeAttr( 'disabled' );
				}
			})

			// --- Add new option row ---
			.on( 'click', 'button.add_addon_option', function () {
				var loop = $( this ).closest( '.lafka_product_addon' ).index( '.lafka_product_addon' );
				var html = params.new_option_html;

				html = html.replace( /{loop}/g, loop );

				var addon_type = $( this ).closest( '.lafka_product_addon.wc-metabox' ).find( 'select.product_addon_type' ).val();
				if ( addon_type === 'checkbox' ) {
					html = html.replace( 'type="radio"', 'type="checkbox"' );
				} else if ( addon_type === 'radiobutton' ) {
					html = html.replace( 'type="checkbox"', 'type="radio"' );
				}

				$( this ).closest( '.lafka_product_addon .data' ).find( 'tbody' ).append( html );
				$( 'select.product_addon_type' ).trigger( 'change' );
				lafka_handle_admin_variation_addons_prices( $( this ).parents( '.wc-metabox-content' ) );

				return false;
			})

			// --- Add new addon group ---
			.on( 'click', '.add_new_addon', function () {
				var loop        = $( '.lafka_product_addons .lafka_product_addon' ).length;
				var total_addons = $( '.lafka_product_addons .lafka_product_addon' ).length;

				if ( total_addons >= 1 ) {
					$( '.lafka-product-add-ons-toolbar--open-close' ).show();
				}

				var html = params.new_addon_html;
				html = html.replace( /{loop}/g, loop );

				$( '.lafka_product_addons' ).append( html );
				$( 'select.product_addon_type' ).trigger( 'change' );
				lafka_handle_admin_variation_addons();
				lafka_handle_admin_variation_addons_prices( $( '.wc-metabox-content' ) );

				return false;
			})

			// --- Remove addon group ---
			.on( 'click', '.remove_addon', function () {
				var answer = confirm( params.remove_addon_confirm );

				if ( answer ) {
					var addon = $( this ).closest( '.lafka_product_addon' );
					$( addon ).find( 'input' ).val( '' );
					$( addon ).remove();
				}

				$( '.lafka_product_addons .lafka_product_addon' ).each( function ( index ) {
					var this_index = index;

					$( this ).find( '.product_addon_position' ).val( this_index );
					$( this ).find( 'select, input, textarea' ).prop( 'name', function ( i, val ) {
						return val.replace( /\[[0-9]+\]/g, '[' + this_index + ']' );
					});
				});

				return false;
			})

			// --- Variation checkbox toggle ---
			.on( 'click', '.lafka-addon-variation-checkbox', function () {
				lafka_handle_admin_variation_addons();
				lafka_handle_admin_variation_addons_prices( $( this ).parents( '.wc-metabox-content' ) );
			})

			// --- Variation attribute select change ---
			.on( 'change', '.lafka-addon-attributes-select', function () {
				lafka_handle_admin_variation_addons_prices( $( this ).parents( '.wc-metabox-content' ) );
			});

		// ── Initialise ──

		// Initial state of min/max fields
		$( 'select.product_addon_type' ).each( function () {
			if ( $( this ).val() === 'textarea' ) {
				$( this ).closest( '.lafka_product_addon' ).find( 'td.minmax_column, th.minmax_column' ).show();
			} else {
				$( this ).closest( '.lafka_product_addon' ).find( 'td.minmax_column, th.minmax_column' ).hide();
			}
		});

		lafka_handle_admin_variation_addons();

		// ── Sortable addons ──
		$( '.lafka_product_addons' ).sortable({
			items: '.lafka_product_addon',
			cursor: 'move',
			axis: 'y',
			handle: 'h3',
			scrollSensitivity: 40,
			helper: function ( e, ui ) {
				return ui;
			},
			start: function ( event, ui ) {
				ui.item.css( 'border-style', 'dashed' );
			},
			stop: function ( event, ui ) {
				ui.item.removeAttr( 'style' );
				addon_row_indexes();
			}
		});

		function addon_row_indexes() {
			$( '.lafka_product_addons .lafka_product_addon' ).each( function ( index, el ) {
				$( '.product_addon_position', el ).val( parseInt( $( el ).index( '.lafka_product_addons .lafka_product_addon' ) ) );
			});
		}

		// ── Sortable options within addon ──
		$( '.lafka_product_addon .data table tbody' ).sortable({
			items: 'tr',
			cursor: 'move',
			axis: 'y',
			scrollSensitivity: 40,
			helper: function ( e, ui ) {
				ui.children().each( function () {
					$( this ).width( $( this ).width() );
				});
				return ui;
			},
			start: function ( event, ui ) {
				ui.item.css( 'background-color', '#f6f6f6' );
			},
			stop: function ( event, ui ) {
				ui.item.removeAttr( 'style' );
			}
		});

		// ── Remove option ──
		$( '.lafka_product_addons.wc-metaboxes' ).on( 'click', 'button.remove_addon_option', function () {
			var answer = confirm( params.remove_option_confirm );

			if ( answer ) {
				var addOn = $( this ).closest( '.lafka_product_addon' );
				$( this ).closest( 'tr' ).remove();
				addOn.find( 'select.product_addon_type' ).trigger( 'change' );
			}

			return false;
		});

		// ── Show / hide expand/close toolbar ──
		var total_add_ons = $( '.lafka_product_addons .lafka_product_addon' ).length;
		if ( total_add_ons > 1 ) {
			$( '.lafka-product-add-ons-toolbar--open-close' ).show();
		}

		// ── Default value management (checkbox / radio) ──
		$( '.lafka_product_addons.wc-metaboxes' ).on( 'change', '.lafka-is-default-switch', function () {
			var $all_radio_option_values = $( this ).closest( '.lafka_product_addon.wc-metabox' ).find( 'input.lafka-is-default-value' );

			if ( $all_radio_option_values.length ) {
				$all_radio_option_values.each( function () {
					var $switch_input = $( this ).siblings( 'input.lafka-is-default-switch' );
					if ( $switch_input.length ) {
						$( this ).val( $switch_input.is( ':checked' ) ? 1 : 0 );
					}
				});
			}
		});

		// ── Switch input types for checkbox / radio ──
		$( '.lafka_product_addons.wc-metaboxes' ).on( 'change', 'select.product_addon_type', function () {
			var $selected_type = $( this ).val();
			$( this ).closest( '.lafka_product_addon.wc-metabox' ).find( 'input.lafka-is-default-switch' ).each( function () {
				if ( $selected_type === 'checkbox' ) {
					$( this ).attr( 'type', 'checkbox' );
				} else if ( $selected_type === 'radiobutton' ) {
					$( this ).attr( 'type', 'radio' );
				}
				$( this ).trigger( 'change' );
			});
		});

		// ── Variation helpers ──

		function lafka_handle_admin_variation_addons() {
			var $variation_checkbox_elements = $( document.body ).find( 'input.lafka-addon-variation-checkbox' );
			var $attributes_select_rows      = $( document.body ).find( 'tr.lafka-addon-attributes-select-row' );

			$variation_checkbox_elements.each( function ( index ) {
				if ( $( this ).prop( 'checked' ) ) {
					$( $attributes_select_rows.get( index ) ).show();
				} else {
					$( $attributes_select_rows.get( index ) ).hide();
				}
			});
		}

		function lafka_handle_admin_variation_addons_prices( $current_addon_tables ) {
			$current_addon_tables.each( function () {
				var $addon_attr_select = $( this ).find( 'select.lafka-addon-attributes-select' );
				var attribute_values   = $addon_attr_select.children( 'option' ).filter( ':selected' ).data( 'attribute-values' );
				var loop               = $( this ).closest( '.lafka_product_addon' ).index( '.lafka_product_addon' );

				var $price_column_labels = $( this ).find( 'th.price_column' );
				var $price_input_cells   = $( this ).find( 'td.price_column' );
				var is_variation         = $( this ).find( 'input.lafka-addon-variation-checkbox' ).prop( 'checked' );

				var current_values_buffer     = [];
				var current_values_buffer_row = [];
				var i = 0;
				var j = 0;

				$price_input_cells.each( function ( input_index ) {
					current_values_buffer_row[ j ] = $( this ).find( 'input' ).val();
					j++;
					if ( ( input_index + 1 ) % $price_column_labels.length === 0 ) {
						current_values_buffer[ i ] = current_values_buffer_row;
						current_values_buffer_row  = [];
						i++;
						j = 0;
					}
				});

				$price_column_labels.remove();
				$price_input_cells.remove();

				var $image_column      = $( this ).find( 'th.image_column' );
				var price_labels_html  = '';

				if ( is_variation ) {
					for ( var attribute_name in attribute_values ) {
						if ( attribute_values.hasOwnProperty( attribute_name ) ) {
							for ( var key in attribute_values[ attribute_name ] ) {
								if ( attribute_values[ attribute_name ].hasOwnProperty( key ) ) {
									price_labels_html += '<th class="price_column">' + params.price_label + ' ' + attribute_values[ attribute_name ][ key ] + '</th>';
								}
							}
						}
					}
				} else {
					price_labels_html += '<th class="price_column">' + params.price_label + '</th>';
				}

				$image_column.after( price_labels_html );

				var $input_column = $( this ).find( 'td.image_column' );
				i = 0;
				j = 0;

				$input_column.each( function () {
					var price_inputs_html = '';

					if ( is_variation ) {
						for ( var attribute_name in attribute_values ) {
							if ( attribute_values.hasOwnProperty( attribute_name ) ) {
								for ( var key in attribute_values[ attribute_name ] ) {
									if ( attribute_values[ attribute_name ].hasOwnProperty( key ) ) {
										var buffer_value = '';
										if ( current_values_buffer[ i ] !== undefined && current_values_buffer[ i ][ j ] !== undefined ) {
											buffer_value = current_values_buffer[ i ][ j ];
										}
										price_inputs_html += '<td class="price_column"><input type="text" name="product_addon_option_price[' + loop + '][' + attribute_name + '][' + key + '][]" value="' + buffer_value + '" placeholder="0.00" class="wc_input_price"></td>';
										j++;
									}
								}
							}
						}
					} else {
						var buffer_val = '';
						if ( current_values_buffer[ i ] !== undefined && current_values_buffer[ i ][ 0 ] !== undefined ) {
							buffer_val = current_values_buffer[ i ][ 0 ];
						}
						price_inputs_html += '<td class="price_column"><input type="text" name="product_addon_option_price[' + loop + '][]" value="' + buffer_val + '" placeholder="0.00" class="wc_input_price"></td>';
					}

					$( this ).after( price_inputs_html );
					i++;
					j = 0;
				});
			});
		}

	}); // end jQuery ready

})( jQuery );
