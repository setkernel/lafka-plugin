/* jshint -W041 */

/*-----------------------------------------------------------------*/
/*  Global script variable.                                        */
/*-----------------------------------------------------------------*/

var wc_pb_combo_scripts = {};

/*-----------------------------------------------------------------*/
/*  Global utility variables + functions.                          */
/*-----------------------------------------------------------------*/

/**
 * Converts numbers to formatted price strings. Respects WC price format settings.
 */
function wc_pb_price_format( price, plain ) {

	plain = typeof( plain ) === 'undefined' ? false : plain;

	return wc_pb_woocommerce_number_format( wc_pb_number_format( price ), plain );
}

/**
 * Formats price strings according to WC settings.
 */
function wc_pb_woocommerce_number_format( price, plain ) {

	var remove     = wc_combo_params.currency_format_decimal_sep,
		position   = wc_combo_params.currency_position,
		symbol     = wc_combo_params.currency_symbol,
		trim_zeros = wc_combo_params.currency_format_trim_zeros,
		decimals   = wc_combo_params.currency_format_num_decimals;

	plain = typeof( plain ) === 'undefined' ? false : plain;

	if ( trim_zeros == 'yes' && decimals > 0 ) {
		for ( var i = 0; i < decimals; i++ ) { remove = remove + '0'; }
		price = price.replace( remove, '' );
	}

	var formatted_price  = String( price ),
		formatted_symbol = plain ? symbol : '<span class="woocommerce-Price-currencySymbol">' + symbol + '</span>';

	if ( 'left' === position ) {
		formatted_price = formatted_symbol + formatted_price;
	} else if ( 'right' === position ) {
		formatted_price = formatted_price + formatted_symbol;
	} else if ( 'left_space' === position ) {
		formatted_price = formatted_symbol + ' ' + formatted_price;
	} else if ( 'right_space' === position ) {
		formatted_price = formatted_price + ' ' + formatted_symbol;
	}

	formatted_price = plain ? formatted_price : '<span class="woocommerce-Price-amount amount">' + formatted_price + '</span>';

	return formatted_price;
}

/**
 * Formats price values according to WC settings.
 */
function wc_pb_number_format( number ) {

	var decimals      = wc_combo_params.currency_format_num_decimals,
		decimal_sep   = wc_combo_params.currency_format_decimal_sep,
		thousands_sep = wc_combo_params.currency_format_thousand_sep;

	var n = number, c = isNaN( decimals = Math.abs( decimals ) ) ? 2 : decimals;
	var d = decimal_sep == undefined ? ',' : decimal_sep;
	var t = thousands_sep == undefined ? '.' : thousands_sep, s = n < 0 ? '-' : '';
	var i = parseInt( n = Math.abs( +n || 0 ).toFixed( c ), 10 ) + '', j = ( j = i.length ) > 3 ? j % 3 : 0;

	return s + ( j ? i.substr( 0, j ) + t : '' ) + i.substr( j ).replace( /(\d{3})(?=\d)/g, '$1' + t ) + ( c ? d + Math.abs( n - i ).toFixed( c ).slice( 2 ) : '' );
}

/**
 * Rounds price values according to WC settings.
 */
function wc_pb_number_round( number, decimals ) {

	var precision         = typeof( decimals ) === 'undefined' ? wc_combo_params.currency_format_num_decimals : parseInt( decimals, 10 ),
		factor            = Math.pow( 10, precision ),
		tempNumber        = number * factor,
		roundedTempNumber = Math.round( tempNumber );

	return roundedTempNumber / factor;
}

/**
 * i18n-friendly string joining.
 */
function wc_pb_format_list( arr, args ) {

	var formatted = '',
		count     = arr.length,
		plain     = args && args.plain,
		plain_sep = args && args.plain_sep;

	if ( count > 0 ) {

		var loop = 0,
			item = '';

		for ( var i = 0; i < count; i++ ) {

			loop++;
			item = plain ? arr[ i ] : wc_combo_params.i18n_string_list_item.replace( '%s', arr[ i ] );

			if ( count == 1 || loop == 1 ) {
				formatted = item;
			} else if ( loop === count && ! plain_sep ) {
				formatted = wc_combo_params.i18n_string_list_last_sep.replace( '%s', formatted ).replace( '%v', item );
			} else {
				formatted = wc_combo_params.i18n_string_list_sep.replace( '%s', formatted ).replace( '%v', item );
			}
		}
	}

	return formatted;
}

/**
 * Combo script object getter.
 */
jQuery.fn.wc_get_combo_script = function() {

	var $combo_form = jQuery( this );

	if ( ! $combo_form.hasClass( 'combo_form' ) ) {
		return false;
	}

	var script_id = $combo_form.data( 'script_id' );

	if ( typeof( wc_pb_combo_scripts[ script_id ] ) !== 'undefined' ) {
		return wc_pb_combo_scripts[ script_id ];
	}

	return false;
};

/*-----------------------------------------------------------------*/
/*  Encapsulation.                                                 */
/*-----------------------------------------------------------------*/

( function( $ ) {

	/**
	 * Main combo object.
	 */
	function WC_LafkaCombos_Combo( data ) {

		var combo                    = this;

		this.combo_id                = data.combo_id;

		this.$combo_form             = data.$combo_form;
		this.$combo_data             = data.$combo_data;
		this.$combo_wrap             = data.$combo_data.find( '.combo_wrap' );
		this.$combined_items           = data.$combo_form.find( '.combined_product' );

		this.$combo_availability     = data.$combo_data.find( '.combo_availability' );
		this.$combo_price            = data.$combo_data.find( '.combo_price' );
		this.$combo_button           = data.$combo_data.find( '.combo_button' );
		this.$combo_error            = data.$combo_data.find( '.combo_error' );
		this.$combo_error_content    = this.$combo_error.find( 'ul.msg' );
		this.$combo_quantity         = this.$combo_button.find( 'input.qty' );

		this.$nyp                     = this.$combo_data.find( '.nyp' );

		this.$addons_totals           = this.$combo_data.find( '#product-addons-total' );
		this.show_addons_totals       = false;

		this.combined_items            = {};

		this.price_data               = data.$combo_data.data( 'combo_form_data' );

		this.$initial_stock_status    = false;

		this.update_combo_timer      = false;
		this.update_price_timer       = false;

		this.validation_messages      = [];

		this.is_initialized           = false;

		this.composite_data           = data.composite_data;

		this.dirty_subtotals          = false;

		this.filters                  = false;

		this.api                      = {

			/**
			 * Get the current combo totals.
			 *
			 * @return object
			 */
			get_combo_totals: function() {

				return combo.price_data.totals;
			},

			/**
			 * Get the current combined item totals.
			 *
			 * @return object
			 */
			get_combined_item_totals: function( combined_item_id ) {

				return combo.price_data[ 'combined_item_' + combined_item_id + '_totals' ];
			},

			/**
			 * Get the current combined item recurring totals.
			 *
			 * @return object
			 */
			get_combined_item_recurring_totals: function( combined_item_id ) {

				return combo.price_data[ 'combined_item_' + combined_item_id + '_recurring_totals' ];
			},

			/**
			 * Get the current validation status of the combo.
			 *
			 * @return string ('pass' | 'fail')
			 */
			get_combo_validation_status: function() {

				return combo.passes_validation() ? 'pass' : 'fail';
			},

			/**
			 * Get the current validation messages for the combo.
			 *
			 * @return array
			 */
			get_combo_validation_messages: function() {

				return combo.get_validation_messages();
			},

			/**
			 * Get the current stock status of the combo.
			 *
			 * @return string ('in-stock' | 'out-of-stock')
			 */
			get_combo_stock_status: function() {

				var availability = combo.$combo_wrap.find( 'p.out-of-stock' ).not( '.inactive' );

				return availability.length > 0 ? 'out-of-stock' : 'in-stock';
			},

			/**
			 * Get the current availability string of the combo.
			 *
			 * @return string
			 */
			get_combo_availability: function() {

				var availability = combo.$combo_wrap.find( 'p.stock' );

				if ( availability.hasClass( 'inactive' ) ) {
					if ( false !== combo.$initial_stock_status ) {
						availability = combo.$initial_stock_status.clone().wrap( '<div></div>' ).parent().html();
					} else {
						availability = '';
					}
				} else {
					availability = availability.clone().removeAttr( 'style' ).wrap( '<div></div>' ).parent().html();
				}

				return availability;
			},

			/**
			 * Gets combo configuration details.
			 *
			 * @return object | false
			 */
			get_combo_configuration: function() {

				var combo_config = {};

				if ( combo.combined_items.length === 0 ) {
					return false;
				}

				$.each( combo.combined_items, function( index, combined_item ) {

					var combined_item_config = {
						title:         combined_item.get_title(),
						product_title: combined_item.get_product_title(),
						product_id:    combined_item.get_product_id(),
						variation_id:  combined_item.get_variation_id(),
						quantity:      combo.price_data.quantities[ combined_item.combined_item_id ],
						product_type:  combined_item.get_product_type(),
					};

					combo_config[ combined_item.combined_item_id ] = combined_item_config;
				} );

				return combo_config;
			}
		};

		/**
		 * Object initialization.
		 */
		this.initialize = function() {

			/**
			 * Initial states and loading.
			 */

			// Filters API.
			this.filters = new WC_LafkaCombos_Filters_Manager();

			// Addons compatibility.
			if ( this.has_addons() ) {

				// Totals visible?
				if ( 1 == this.$addons_totals.data( 'show-sub-total' ) || ( this.is_composited() && this.composite_data.component.show_addons_totals ) ) {
					// Ensure addons ajax is not triggered at all, as we calculate tax on the client side.
					this.$addons_totals.data( 'show-sub-total', 0 );
					this.$combo_price.after( this.$addons_totals );
					this.show_addons_totals = true;
				}

			} else {
				this.$addons_totals = false;
			}

			// Save initial availability.
			if ( this.$combo_wrap.find( 'p.stock' ).length > 0 ) {
				this.$initial_stock_status = this.$combo_wrap.find( 'p.stock' ).clone();
			}

			// Back-compat.
			if ( ! this.price_data ) {
				this.price_data = data.$combo_data.data( 'combo_price_data' );
			} else if ( ! this.$combo_data.data( 'combo_price_data' ) ) {
				this.$combo_data.data( 'combo_price_data', this.price_data );
			}

			// Price suffix data.
			this.price_data.suffix_exists              = wc_combo_params.price_display_suffix !== '';
			this.price_data.suffix                     = wc_combo_params.price_display_suffix !== '' ? ' <small class="woocommerce-price-suffix">' + wc_combo_params.price_display_suffix + '</small>' : '';
			this.price_data.suffix_contains_price_incl = wc_combo_params.price_display_suffix.indexOf( '{price_including_tax}' ) > -1;
			this.price_data.suffix_contains_price_excl = wc_combo_params.price_display_suffix.indexOf( '{price_excluding_tax}' ) > -1;

			// Delete redundant form inputs.
			this.$combo_button.find( 'input[name*="combo_variation"], input[name*="combo_attribute"]' ).remove();

			/**
			 * Bind combo event handlers.
			 */

			this.bind_event_handlers();
			this.viewport_resized();

			/**
			 * Init Combined Items.
			 */

			this.init_combined_items();

			/**
			 * Init Composite Products integration.
			 */

			if ( this.is_composited() ) {
				this.init_composite();
			}

			/**
			 * Initialize.
			 */

			this.$combo_data.trigger( 'woocommerce-product-combo-initializing', [ this ] );

			$.each( this.combined_items, function( index, combined_item ) {
				combined_item.init_scripts();
			} );

			this.update_combo_task();

			this.is_initialized = true;

			this.$combo_form.addClass( 'initialized' );

			this.$combo_data.trigger( 'woocommerce-product-combo-initialized', [ this ] );
		};

		/**
		 * Shuts down events, actions and filters managed by this script object.
		 */
		this.shutdown = function() {

			this.$combo_form.find( '*' ).off();

			if ( false !== this.composite_data ) {
				this.remove_composite_hooks();
			}
		};

		/**
		 * Composite Products app integration.
		 */
		this.init_composite = function() {

			/**
			 * Add/remove hooks on the 'component_scripts_initialized' action.
			 */
			this.composite_data.composite.actions.add_action( 'component_scripts_initialized_' + this.composite_data.component.step_id, this.component_scripts_initialized_action, 10, this );
		};

		/**
		 * Add hooks on the 'component_scripts_initialized' action.
		 */
		this.component_scripts_initialized_action = function() {

			var is_combo_selected = false;

			// Composite Products < 4.0 compatibility.
			if ( typeof this.composite_data.component.component_selection_model.selected_product !== 'undefined' ) {
				is_combo_selected = parseInt( this.composite_data.component.component_selection_model.selected_product, 10 ) === parseInt( this.combo_id, 10 );
			} else {
				is_combo_selected = parseInt( this.composite_data.component.component_selection_model.get( 'selected_product' ), 10 ) === parseInt( this.combo_id, 10 );
			}

			if ( is_combo_selected ) {
				this.add_composite_hooks();
			} else {
				this.remove_composite_hooks();
			}
		};

		/**
		 * Composite Products app integration - add actions and filters.
		 */
		this.add_composite_hooks = function() {

			/**
			 * Filter validation state.
			 */
			this.composite_data.composite.filters.add_filter( 'component_is_valid', this.cp_component_is_valid_filter, 10, this );

			/**
			 * Filter title in summary.
			 */
			this.composite_data.composite.filters.add_filter( 'component_selection_formatted_title', this.cp_component_selection_formatted_title_filter, 10, this );
			this.composite_data.composite.filters.add_filter( 'component_selection_meta', this.cp_component_selection_meta_filter, 10, this );

			/**
			 * Filter totals.
			 */
			this.composite_data.composite.filters.add_filter( 'component_totals', this.cp_component_totals_filter, 10, this );

			/**
			 * Filter component configuration data.
			 */
			this.composite_data.composite.filters.add_filter( 'component_configuration', this.cp_component_configuration_filter, 10, this );

			/**
			 * Add validation messages.
			 */
			this.composite_data.composite.actions.add_action( 'validate_step', this.cp_validation_messages_action, 10, this );
		};

		/**
		 * Composite Products app integration - remove actions and filters.
		 */
		this.remove_composite_hooks = function() {

			this.composite_data.composite.filters.remove_filter( 'component_is_valid', this.cp_component_is_valid_filter );
			this.composite_data.composite.filters.remove_filter( 'component_selection_formatted_title', this.cp_component_selection_formatted_title_filter );
			this.composite_data.composite.filters.remove_filter( 'component_selection_meta', this.cp_component_selection_meta_filter );
			this.composite_data.composite.filters.remove_filter( 'component_totals', this.cp_component_totals_filter );
			this.composite_data.composite.filters.remove_filter( 'component_configuration', this.cp_component_configuration_filter );

			this.composite_data.composite.actions.remove_action( 'component_scripts_initialized_' + this.composite_data.component.step_id, this.component_scripts_initialized_action );
			this.composite_data.composite.actions.remove_action( 'validate_step', this.cp_validation_messages_action );
		};

		/**
		 * Appends combo configuration data to component config data.
		 */
		this.cp_component_configuration_filter = function( configuration_data, component ) {

			if ( component.step_id === this.composite_data.component.step_id && parseInt( component.get_selected_product(), 10 ) === parseInt( combo.combo_id, 10 ) ) {
				configuration_data.combined_items = combo.api.get_combo_configuration();
			}

			return configuration_data;
		};

		/**
		 * Filters the component totals to pass on the calculated combo totals.
		 */
		this.cp_component_totals_filter = function( totals, component, qty ) {

			if ( component.step_id === this.composite_data.component.step_id && parseInt( component.get_selected_product(), 10 ) === parseInt( combo.combo_id, 10 ) ) {

				var price_data       = $.extend( true, {}, combo.price_data ),
					addons_raw_price = combo.has_addons() ? combo.get_addons_raw_price() : 0;

				qty = typeof( qty ) === 'undefined' ? component.get_selected_quantity() : qty;

				if ( addons_raw_price > 0 ) {
					// Recalculate price html with add-ons price and qty embedded.
					price_data.base_price = Number( price_data.base_price ) + Number( addons_raw_price );
				}

				price_data = combo.calculate_subtotals( false, price_data, qty );
				price_data = combo.calculate_totals( price_data );

				return price_data.totals;
			}

			return totals;
		};

		/**
		 * Filters the summary view title to include combined product details.
		 */
		this.cp_component_selection_formatted_title_filter = function( formatted_title, raw_title, qty, formatted_meta, component ) {

			if ( component.step_id === this.composite_data.component.step_id && parseInt( component.get_selected_product(), 10 ) === parseInt( this.combo_id, 10 ) ) {

				var combined_products_count = 0;

				$.each( this.combined_items, function( index, combined_item ) {
					if ( combined_item.$combined_item_cart.data( 'quantity' ) > 0 ) {
						combined_products_count++;
					}
				} );

				if ( this.group_mode_supports( 'component_multiselect' ) ) {
					if ( combined_products_count === 0 ) {
						formatted_title = wc_composite_params.i18n_no_selection;
					} else {

						var contents = this.cp_get_formatted_contents( component );

						if ( contents ) {
							formatted_title = contents;
						}
					}
				}
			}

			return formatted_title;
		};

		/**
		 * Filters the summary view title to include combined product details.
		 */
		this.cp_component_selection_meta_filter = function( meta, component ) {

			if ( component.step_id === this.composite_data.component.step_id && parseInt( component.get_selected_product(), 10 ) === parseInt( this.combo_id, 10 ) ) {

				var combined_products_count = 0;

				$.each( this.combined_items, function( index, combined_item ) {
					if ( combined_item.$combined_item_cart.data( 'quantity' ) > 0 ) {
						combined_products_count++;
					}
				} );

				if ( combined_products_count !== 0 && false === this.group_mode_supports( 'component_multiselect' ) ) {

					var selected_combined_products = this.cp_get_formatted_contents( component );

					if ( selected_combined_products !== '' ) {
						meta.push( { meta_key: wc_combo_params.i18n_contents, meta_value: selected_combined_products } );
					}
				}
			}

			return meta;
		};

		/**
		 * Formatted combo contents for display in Composite Products summary views.
		 */
		this.cp_get_formatted_contents = function( component ) {

			var formatted_contents   = '',
				combined_item_details = [],
				combo_qty           = component.get_selected_quantity();

			$.each( this.combined_items, function( index, combined_item ) {

				if ( combined_item.$self.hasClass( 'combined_item_hidden' ) ) {
					return true;
				}

				if ( combined_item.$combined_item_cart.data( 'quantity' ) > 0 ) {

					var $item_image             = combined_item.$combined_item_image.find( 'img' ).first(),
						item_image              = $item_image.length > 0 ? $item_image.get( 0 ).outerHTML : false,
						item_quantity           = parseInt( combined_item.$combined_item_cart.data( 'quantity' ) * combo_qty, 10 ),
						item_meta               = wc_cp_get_variation_data( combined_item.$combined_item_cart.find( '.variations' ) ),
						formatted_item_title    = combined_item.$combined_item_cart.data( 'title' ),
						formatted_item_quantity = item_quantity > 1 ? '<strong>' + wc_composite_params.i18n_qty_string.replace( '%s', item_quantity ) + '</strong>' : '',
						formatted_item_meta     = '';

					if ( item_meta.length > 0 ) {

						$.each( item_meta, function( index, meta ) {
							formatted_item_meta = formatted_item_meta + '<span class="combined_meta_element"><span class="combined_meta_key">' + meta.meta_key + ':</span> <span class="combined_meta_value">' + meta.meta_value + '</span>';
							if ( index !== item_meta.length - 1 ) {
								formatted_item_meta = formatted_item_meta + '<span class="combined_meta_value_sep">, </span>';
							}
							formatted_item_meta = formatted_item_meta + '</span>';
						} );

						formatted_item_title = wc_combo_params.i18n_title_meta_string.replace( '%t', formatted_item_title ).replace( '%m', '<span class="content_combined_product_meta">' + formatted_item_meta + '</span>' );
					}

					formatted_item_title = wc_composite_params.i18n_title_string.replace( '%t', formatted_item_title ).replace( '%q', formatted_item_quantity ).replace( '%p', '' );

					combined_item_details.push( { title: formatted_item_title, image: item_image } );
				}
			} );

			if ( combined_item_details.length > 0 ) {

				formatted_contents = formatted_contents + '<span class="content_combined_product_details_wrapper">';

				$.each( combined_item_details, function( index, details ) {
					formatted_contents = formatted_contents + '<span class="content_combined_product_details">' + ( details.image ? '<span class="content_combined_product_image">' + details.image + '</span>' : '' ) + '<span class="content_combined_product_title">' + details.title + '</span></span>';
				} );

				formatted_contents = formatted_contents + '</span>';
			}

			return formatted_contents;
		};

		/**
		 * Filters the validation state of the component containing this combo.
		 */
		this.cp_component_is_valid_filter = function( is_valid, check_scenarios, component ) {

			if ( component.step_id === this.composite_data.component.step_id ) {
				if ( parseInt( component.get_selected_product( check_scenarios ), 10 ) === parseInt( this.combo_id, 10 ) && component.get_selected_quantity() > 0 && component.is_visible() ) {
					is_valid = this.passes_validation();
				}
			}

			return is_valid;
		};

		/**
		 * Adds validation messages to the component containing this combo.
		 */
		this.cp_validation_messages_action = function( step, is_valid ) {

			if ( step.step_id === this.composite_data.component.step_id && false === is_valid && parseInt( step.get_selected_product(), 10 ) === parseInt( this.combo_id, 10 ) ) {

				var validation_messages = this.get_validation_messages();

				$.each( validation_messages, function( index, message ) {
					step.add_validation_message( message );
					step.add_validation_message( message, 'composite' );
				} );
			}
		};

		/**
		 * WC front-end ajax URL.
		 */
		this.get_ajax_url = function( action ) {

			return woocommerce_params.wc_ajax_url.toString().replace( '%%endpoint%%', action );
		};

		/**
		 * Handler for viewport resizing.
		 */
		this.viewport_resized = function() {

			if ( this.is_composited() ) {
				return;
			}

			var form_width = this.$combo_form.width();

			if ( form_width <= wc_combo_params.responsive_breakpoint ) {
				this.$combo_form.addClass( 'small_width' );
			} else {
				this.$combo_form.removeClass( 'small_width' );
			}
		};

		/**
		 * Attach combo-level event handlers.
		 */
		this.bind_event_handlers = function() {

			// Add responsive class to combo form.
			$( window ).on( 'resize', function() {

				clearTimeout( combo.viewport_resize_timer );

				combo.viewport_resize_timer = setTimeout( function() {
					combo.viewport_resized();
				}, 50 );
			} );

			// PAO compatibility.
			if ( combo.has_addons() ) {
				combo.$combo_data.on( 'updated_addons', combo.updated_addons_handler );
			}

			// CP compatibility.
			if ( combo.is_composited() ) {
				combo.$combo_quantity.on( 'input change', function() {
					combo.$combo_data.trigger( 'woocommerce-product-combo-update' );
				} );
			}

			this.$combo_data

				// NYP compatibility.
				.on( 'woocommerce-nyp-updated-item', function( event ) {

					if ( combo.$nyp.is( ':visible' ) ) {

						combo.price_data.base_regular_price = combo.$nyp.data( 'price' );
						combo.price_data.base_price         = combo.price_data.base_regular_price;

						if ( combo.is_initialized ) {
							combo.dirty_subtotals = true;
							combo.update_totals();
						}
					}

					event.stopPropagation();
				} )

				.on( 'woocommerce-product-combo-validation-status-changed', function( event, combo ) {
					combo.updated_totals();
				} )

				.on( 'click', '.combo_add_to_cart_button', function( event ) {

					if ( $( this ).hasClass( 'disabled' ) ) {

						event.preventDefault();
						window.alert( wc_combo_params.i18n_validation_alert );

					} else {

						$.each( combo.combined_items, function( index, combined_item ) {

							if ( combined_item.has_required_addons() && combined_item.is_optional() && false === combined_item.is_selected() ) {
								combined_item.$required_addons.prop( 'required', false );
							}
						} );
					}
				} )

				.on( 'woocommerce-product-combo-update-totals', function( event, force, _combo ) {

					var target_combo = typeof( _combo ) === 'undefined' ? combo : _combo;

					force = typeof( force ) === 'undefined' ? false : force;

					if ( force ) {
						target_combo.dirty_subtotals = true;
					}

					target_combo.update_totals();
				} )

				.on( 'woocommerce-combined-item-totals-changed', function( event, combined_item ) {

					if ( combined_item.has_addons() ) {
						combined_item.render_addons_totals();
					}
				} )

				.on( 'woocommerce-product-combo-update', function( event, triggered_by ) {

					var target_combo = typeof( triggered_by ) === 'undefined' ? combo : triggered_by.get_combo();

					if ( triggered_by ) {
						target_combo.update_combo( triggered_by );
					} else {
						target_combo.update_combo();
					}
				} );
		};

		/**
		 * Initialize combined item objects.
		 */
		this.init_combined_items = function() {

			combo.$combined_items.each( function( index ) {

				combo.combined_items[ index ] = new WC_LafkaCombos_Combined_Item( combo, $( this ), index );

				combo.bind_combined_item_event_handlers( combo.combined_items[ index ] );
			} );
		};

		/**
		 * Attach combined-item-level event handlers.
		 */
		this.bind_combined_item_event_handlers = function( combined_item ) {

			combined_item.$self

				/**
				 * Update totals upon changing quantities.
				 */
				.on( 'input change', 'input.combined_qty', function( event ) {

					var $input = $( this ),
						qty    = parseFloat( $input.val() ),
						min    = parseFloat( $input.attr( 'min' ) ),
						max    = parseFloat( $input.attr( 'max' ) );

					if ( wc_combo_params.force_min_max_qty_input === 'yes' && 'change' === event.type ) {

						if ( min >= 0 && ( qty < min || isNaN( qty ) ) ) {
							qty = min;
						}

						if ( max > 0 && qty > max ) {
							qty = max;
						}

						$input.val( qty );
					}

					// A zero quantity item is considered optional by NYP.
					if( combined_item.is_nyp() && ! combined_item.is_optional() && min === 0 ) {
						combined_item.$nyp.data( 'optional_status', qty > 0 ? true : false );
					}

					combined_item.update_selection_title();

					combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );
				} )

				.on( 'change', '.combined_product_optional_checkbox input', function( event ) {

					if ( $( this ).is( ':checked' ) ) {

						combined_item.$combined_item_content.css( {
							height:   '',
							display: 'block',
							position: 'absolute',
						} );

						var height = combined_item.$combined_item_content.get( 0 ).getBoundingClientRect().height;

						if ( typeof height === 'undefined' ) {
							height = combined_item.$combined_item_content.outerHeight();
						}

						combined_item.$combined_item_content.css( {
							height:   '',
							position: '',
							display:  'none'
						} );

						if ( height ) {
							combined_item.$combined_item_content.addClass( 'combined_item_cart_content--populated' );
							combined_item.$combined_item_content.slideDown( 200 );
						}

						combined_item.set_selected( true );

						// Tabular mini-extension compat.
						combined_item.$self.find( '.combined_item_qty_col .quantity' ).removeClass( 'quantity_hidden' );

						if( combined_item.is_nyp() ) {
							combined_item.$nyp.trigger( 'wc-nyp-update', [ { 'force': true } ] );
						}

						// Allow variations script to flip images in combined_product_images div.
						combined_item.$combined_item_cart.find( '.variations select:eq(0)' ).trigger( 'change' );

					} else {

						combined_item.$combined_item_content.slideUp( 200 );
						combined_item.set_selected( false );

						// Tabular mini-extension compat.
						combined_item.$self.find( '.combined_item_qty_col .quantity' ).addClass( 'quantity_hidden' );

						if ( ! combined_item.has_single_variation() ) {
							// Reset image in combined_product_images div.
							combined_item.maybe_add_wc_core_gallery_class();
							combined_item.$combined_item_cart.trigger( 'reset_image' );
							combined_item.maybe_remove_wc_core_gallery_class();
						}
					}

					combined_item.update_selection_title();

					combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );

					event.stopPropagation();
				} )

				.on( 'found_variation', function( event, variation ) {

					combined_item.variation_id = variation.variation_id.toString();

					// Put variation price data in price table.
					combo.price_data.prices[ combined_item.combined_item_id ]                   = Number( variation.price );
					combo.price_data.regular_prices[ combined_item.combined_item_id ]           = Number( variation.regular_price );

					combo.price_data.prices_tax[ combined_item.combined_item_id ]               = variation.price_tax;

					// Put variation recurring component data in price table.
					combo.price_data.recurring_prices[ combined_item.combined_item_id ]         = Number( variation.recurring_price );
					combo.price_data.regular_recurring_prices[ combined_item.combined_item_id ] = Number( variation.regular_recurring_price );

					combo.price_data.recurring_html[ combined_item.combined_item_id ]           = variation.recurring_html;
					combo.price_data.recurring_keys[ combined_item.combined_item_id ]           = variation.recurring_key;

					// Update availability data.
					combo.price_data.quantities_available[ combined_item.combined_item_id ]            = variation.avail_qty;
					combo.price_data.is_in_stock[ combined_item.combined_item_id ]                     = variation.is_in_stock ? 'yes' : 'no'; // Boolean value coming from WC.
					combo.price_data.backorders_allowed[ combined_item.combined_item_id ]              = variation.backorders_allowed ? 'yes' : 'no'; // Boolean value coming from WC.
					combo.price_data.backorders_require_notification[ combined_item.combined_item_id ] = variation.backorders_require_notification;

					// Remove .images class from combined_product_images div in order to avoid styling issues.
					combined_item.maybe_remove_wc_core_gallery_class();

					// If the combined item is optional and not selected, reset the variable product image.
					if ( combined_item.is_optional() && ! combined_item.has_single_variation() && ! combined_item.$self.find( '.combined_product_optional_checkbox input' ).is( ':checked' ) ) {
						combined_item.maybe_add_wc_core_gallery_class();
						combined_item.$combined_item_cart.trigger( 'reset_image' );
						combined_item.maybe_remove_wc_core_gallery_class();
					}

					combined_item.update_selection_title();

					combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );

					event.stopPropagation();
				} )

				.on( 'reset_image', function() {
					// Remove .images class from combined_product_images div in order to avoid styling issues.
					combined_item.maybe_remove_wc_core_gallery_class();

				} )

				.on( 'woocommerce-product-addons-update', function( event ) {
					event.stopPropagation();
				} )

				.on( 'woocommerce_variation_select_focusin', function( event ) {
					event.stopPropagation();
				} )

				.on( 'woocommerce_variation_has_changed', function( event ) {

					if ( combined_item.$reset_combined_variations ) {
						if ( combined_item.variation_id ) {
							combined_item.$reset_combined_variations.slideDown( 200 );
						} else {
							combined_item.$reset_combined_variations.slideUp( 200 );
						}
					}

					event.stopPropagation();
				} )

				.on( 'woocommerce_variation_select_change', function( event ) {

					combined_item.variation_id = '';

					combo.price_data.quantities_available[ combined_item.combined_item_id ]            = '';
					combo.price_data.is_in_stock[ combined_item.combined_item_id ]                     = '';
					combo.price_data.backorders_allowed[ combined_item.combined_item_id ]              = '';
					combo.price_data.backorders_require_notification[ combined_item.combined_item_id ] = '';

					// Add .images class to combined_product_images div (required by the variations script to flip images).
					if ( combined_item.is_selected() ) {
						combined_item.maybe_add_wc_core_gallery_class();
					}

					if ( combined_item.$attribute_select ) {
						combined_item.$attribute_select.each( function() {

							if ( $( this ).val() === '' ) {

								// Prevent from appearing as out of stock.
								combined_item.$combined_item_cart.find( '.combined_item_wrap .stock' ).addClass( 'disabled' );
								// Trigger combo update.
								combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );
								return false;
							}
						} );
					}

					event.stopPropagation();

				} );


			if ( combined_item.has_addons() ) {

				combined_item.$combined_item_cart

					/**
					 * Calculate taxes and render addons totals on the client side.
					 * We already prevented Add-ons from firing an ajax request in 'WC_LafkaCombos_Combined_Item'.
					 */
					.on( 'updated_addons', function( event ) {

						// Always restore totals state because PAO empties it before the 'updated_addons' event.
						combined_item.$addons_totals.html( combined_item.addons_totals_html );

						combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );

						event.stopPropagation();
					} );
			}

			if ( combined_item.is_nyp() ) {

				combined_item.$combined_item_cart

					.on( 'woocommerce-nyp-updated-item', function( event ) {

						if ( combined_item.$nyp.is( ':visible' ) ) {

							var nyp_price = combined_item.$nyp.data( 'price' );

							combo.price_data.prices[ combined_item.combined_item_id ]         = nyp_price;
							combo.price_data.regular_prices[ combined_item.combined_item_id ] = nyp_price;

							combo.$combo_data.trigger( 'woocommerce-product-combo-update', [ combined_item ] );
						}

						event.stopPropagation();
					} );
			}
		};

		/**
		 * Returns the quantity of this combo.
		 */
		this.get_quantity = function() {
			var qty = combo.$combo_quantity.length > 0 ? combo.$combo_quantity.val() : 1;
			return isNaN( qty ) ? 1 : parseInt( qty, 10 );
		};

		/**
		 * Returns an availability string for the combined items.
		 */
		this.get_combined_items_availability = function() {

			var insufficiently_stocked_items      = [],
			    insufficiently_stocked_items_list = true,
			    backordered_items                 = [],
			    backordered_items_list            = true;

			$.each( combo.combined_items, function( index, combined_item ) {

				if ( combined_item.has_insufficient_stock() ) {

					insufficiently_stocked_items.push( combined_item.get_title( true ) );

					if ( ! combined_item.is_visible() || ! combined_item.get_title( true ) ) {
						insufficiently_stocked_items_list = false;
						return false;
					}
				}

			} );

			if ( insufficiently_stocked_items.length > 0 ) {

				if ( insufficiently_stocked_items_list ) {
					return wc_combo_params.i18n_insufficient_stock_list.replace( '%s', wc_pb_format_list( insufficiently_stocked_items, { plain: true, plain_sep: true } ) );
				} else {
					return wc_combo_params.i18n_insufficient_stock_status;
				}

			}

			$.each( combo.combined_items, function( index, combined_item ) {

				if ( combined_item.is_backordered() ) {

					backordered_items.push( combined_item.get_title( true ) );

					if ( ! combined_item.is_visible() || ! combined_item.get_title( true ) ) {
						backordered_items_list = false;
						return false;
					}
				}

			} );

			if ( backordered_items.length > 0 ) {

				if ( backordered_items_list ) {
					return wc_combo_params.i18n_on_backorder_list.replace( '%s', wc_pb_format_list( backordered_items, { plain: true, plain_sep: true } ) );
				} else {
					return wc_combo_params.i18n_on_backorder_status;
				}

			}

			return '';
		};

		/**
		 * Schedules an update of the combo totals.
		 */
		this.update_combo = function( triggered_by ) {

			clearTimeout( combo.update_combo_timer );

			combo.update_combo_timer = setTimeout( function() {
				combo.update_combo_task( triggered_by );
			}, 5 );
		};

		/**
		 * Updates the combo totals.
		 */
		this.update_combo_task = function( triggered_by ) {

			var has_insufficient_stock     = false,
				combined_items_availability = false,
				validation_status          = false === combo.is_initialized ? '' : combo.api.get_combo_validation_status(),
				unset_count                = 0,
				unset_titles               = [],
				total_items_qty            = 0,
				nyp_error_count            = 0,
				nyp_error_titles           = [];

			/*
			 * Validate combo.
			 */

			// Reset validation messages.
			combo.validation_messages = [];

			// Validate combined items and prepare price data for totals calculation.
			$.each( combo.combined_items, function( index, combined_item ) {

				var combined_item_qty = combined_item.is_selected() ? combined_item.get_quantity() : 0;

				// Add item qty to total.
				total_items_qty += combined_item_qty;

				// Check variable products.
				if ( combined_item.is_variable_product_type() && combined_item.get_variation_id() === '' ) {
					if ( combined_item_qty > 0 ) {
						unset_count++;
						if ( combined_item.is_visible() && combined_item.get_title( true ) ) {
							unset_titles.push( combined_item.get_title( true ) );
						}
					}
				}

				// Check NYP validity.
				if( combined_item.is_nyp() && ! combined_item.is_nyp_valid() ) {
					nyp_error_count++;
					if ( combined_item.is_visible() && combined_item.get_title( true ) ) {
						nyp_error_titles.push( combined_item.get_title( true ) );
					}
				}

			} );

			if ( unset_count > 0 ) {

				var select_options_message = '';

				if ( unset_count === unset_titles.length && unset_count < 5 ) {
					select_options_message = wc_combo_params.i18n_select_options_for.replace( '%s', wc_pb_format_list( unset_titles ) );
				} else {
					select_options_message = wc_combo_params.i18n_select_options;
				}

				combo.add_validation_message( select_options_message );
			}

			if ( nyp_error_count > 0 ) {

				var nyp_amount_message = '';

				if ( nyp_error_count === nyp_error_titles.length && nyp_error_count < 5 ) {
					nyp_amount_message = wc_combo_params.i18n_enter_valid_price_for.replace( '%s', wc_pb_format_list( nyp_error_titles ) );
				} else {
					nyp_amount_message = wc_combo_params.i18n_enter_valid_price;
				}

				combo.add_validation_message( nyp_amount_message );
			}

			if ( 0 === total_items_qty && 'no' === combo.price_data.zero_items_allowed ) {
				combo.add_validation_message( wc_combo_params.i18n_zero_qty_error );
			}

			// Combo not purchasable?
			if ( combo.price_data.is_purchasable !== 'yes' ) {
				// Show 'i18n_unavailable_text' message.
				combo.add_validation_message( wc_combo_params.i18n_unavailable_text );
			} else {
				// Validate 3rd party constraints.
				combo.$combo_data.trigger( 'woocommerce-product-combo-validate', [ combo ] );
			}

			// Validation status changed?
			if ( validation_status !== combo.api.get_combo_validation_status() ) {
				combo.$combo_data.trigger( 'woocommerce-product-combo-validation-status-changed', [ combo ] );
			}

			/*
			 * Calculate totals.
			 */

			if ( combo.price_data.is_purchasable === 'yes' ) {
				combo.update_totals( triggered_by );
			}

			/*
			 * Stock handling.
			 */

			$.each( combo.combined_items, function( index, combined_item ) {
				if ( combined_item.has_insufficient_stock() ) {
					has_insufficient_stock = true;
				}
			} );


			/*
			 * Validation result handling.
			 */

			if ( combo.passes_validation() ) {

				// Show add-to-cart button.
				if ( has_insufficient_stock ) {
					combo.$combo_button.find( 'button' ).addClass( 'disabled' );
				} else {
					combo.$combo_button.find( 'button' ).removeClass( 'disabled' );
				}

				// Hide validation messages.
				setTimeout( function() {
					combo.$combo_error.slideUp( 200 );
				}, 1 );

				combo.$combo_wrap.trigger( 'woocommerce-product-combo-show' );

			} else {
				combo.hide_combo();
			}

			/**
			 * Override combo availability.
			 */

			 combined_items_availability = combo.get_combined_items_availability();

			if ( combined_items_availability ) {
				combo.$combo_availability.html( combined_items_availability );
				combo.$combo_availability.slideDown( 200 );
			} else {
				if ( combo.$initial_stock_status ) {
					combo.$combo_availability.html( combo.$initial_stock_status );
				} else {
					if ( combo.is_composited() ) {
						combo.$combo_availability.find( 'p.stock' ).addClass( 'inactive' );
					}
					combo.$combo_availability.slideUp( 200 );
				}
			}

			// If composited, run 'component_selection_content_changed' action to update all models/views.
			if ( combo.is_composited() ) {

				// CP > 4.0+.
				if ( typeof combo.composite_data.component.component_selection_model.set_stock_status === 'function' ) {
					combo.composite_data.component.component_selection_model.set_stock_status( has_insufficient_stock ? 'out-of-stock' : 'in-stock' );
				}

				combo.composite_data.composite.actions.do_action( 'component_selection_content_changed', [ combo.composite_data.component ] );
			}

			combo.$combo_data.trigger( 'woocommerce-product-combo-updated', [ combo ] );
		};

		/**
		 * Hide the add-to-cart button and show validation messages.
		 */
		this.hide_combo = function( hide_message ) {

			var messages = $( '<ul/>' );

			if ( typeof( hide_message ) === 'undefined' ) {

				var hide_messages = combo.get_validation_messages();

				if ( hide_messages.length > 0 ) {
					$.each( hide_messages, function( i, message ) {
						messages.append( $( '<li/>' ).html( message ) );
					} );
				} else {
					messages.append( $( '<li/>' ).html( wc_combo_params.i18n_unavailable_text ) );
				}

			} else {
				messages.append( $( '<li/>' ).html( hide_message.toString() ) );
			}

			combo.$combo_error_content.html( messages.html() );
			setTimeout( function() {
				combo.$combo_error.slideDown( 200 );
			}, 1 );
			combo.$combo_button.find( 'button' ).addClass( 'disabled' );

			combo.$combo_wrap.trigger( 'woocommerce-product-combo-hide' );
		};

		/**
		 * Updates the 'price_data' property with the latest values.
		 */
		this.update_price_data = function() {

			$.each( combo.combined_items, function( index, combined_item ) {

				var cart            = combined_item.$combined_item_cart,
				    combined_item_id = combined_item.combined_item_id,
				    item_quantity   = combined_item.get_quantity();

				combo.price_data.quantities[ combined_item_id ] = 0;

				// Set quantity based on optional flag.
				if ( combined_item.is_selected() && item_quantity > 0 ) {
					combo.price_data.quantities[ combined_item_id ] = parseInt( item_quantity, 10 );
				}

				// Store quantity for easy access by 3rd parties.
				cart.data( 'quantity', combo.price_data.quantities[ combined_item_id ] );

				// Check variable products.
				if ( combined_item.is_variable_product_type() && combined_item.get_variation_id() === '' ) {
					combo.price_data.prices[ combined_item_id ]                   = 0.0;
					combo.price_data.regular_prices[ combined_item_id ]           = 0.0;
					combo.price_data.recurring_prices[ combined_item_id ]         = 0.0;
					combo.price_data.regular_recurring_prices[ combined_item_id ] = 0.0;
					combo.price_data.prices_tax[ combined_item_id ]               = false;
				}

				combo.price_data.prices[ combined_item_id ]                   = Number( combo.price_data.prices[ combined_item_id ] );
				combo.price_data.regular_prices[ combined_item_id ]           = Number( combo.price_data.regular_prices[ combined_item_id ] );

				combo.price_data.recurring_prices[ combined_item_id ]         = Number( combo.price_data.recurring_prices[ combined_item_id ] );
				combo.price_data.regular_recurring_prices[ combined_item_id ] = Number( combo.price_data.regular_recurring_prices[ combined_item_id ] );

				// Calculate addons prices.
				if ( combined_item.has_addons() ) {
					combined_item.update_addons_prices();
				}

				combo.price_data.addons_prices[ combined_item_id ]            = Number( combo.price_data.addons_prices[ combined_item_id ] );
				combo.price_data.regular_addons_prices[ combined_item_id ]    = Number( combo.price_data.regular_addons_prices[ combined_item_id ] );
			} );
		};

		/**
		 * Calculates and updates combo subtotals.
		 */
		this.update_totals = function( triggered_by ) {

			this.update_price_data();
			this.calculate_subtotals( triggered_by );

			if ( combo.dirty_subtotals || false === combo.is_initialized ) {
				combo.dirty_subtotals = false;
				combo.calculate_totals();
			}
		};

		/**
		 * Calculates totals by applying tax ratios to raw prices.
		 */
		this.get_taxed_totals = function( price, regular_price, tax_ratios, qty ) {

			qty = typeof( qty ) === 'undefined' ? 1 : qty;

			var tax_ratio_incl = tax_ratios && typeof( tax_ratios.incl ) !== 'undefined' ? Number( tax_ratios.incl ) : false,
				tax_ratio_excl = tax_ratios && typeof( tax_ratios.excl ) !== 'undefined' ? Number( tax_ratios.excl ) : false,
				totals         = {
					price:          qty * price,
					regular_price:  qty * regular_price,
					price_incl_tax: qty * price,
					price_excl_tax: qty * price
				};

			if ( tax_ratio_incl && tax_ratio_excl ) {

				totals.price_incl_tax = wc_pb_number_round( totals.price * tax_ratio_incl );
				totals.price_excl_tax = wc_pb_number_round( totals.price * tax_ratio_excl );

				if ( wc_combo_params.tax_display_shop === 'incl' ) {
					totals.price         = totals.price_incl_tax;
					totals.regular_price = wc_pb_number_round( totals.regular_price * tax_ratio_incl );
				} else {
					totals.price         = totals.price_excl_tax;
					totals.regular_price = wc_pb_number_round( totals.regular_price * tax_ratio_excl );
				}
			}

			return totals;
		};

		/**
		 * Calculates combined item subtotals (combo totals) and updates the corresponding 'price_data' fields.
		 */
		this.calculate_subtotals = function( triggered_by, price_data_array, qty ) {

			var price_data = typeof( price_data_array ) === 'undefined' ? combo.price_data : price_data_array;

			qty          = typeof( qty ) === 'undefined' ? 1 : parseInt( qty, 10 );
			triggered_by = typeof( triggered_by ) === 'undefined' ? false : triggered_by;

			// Base.
			if ( false === triggered_by ) {

				var base_price            = Number( price_data.base_price ),
					base_regular_price    = Number( price_data.base_regular_price ),
					base_price_tax_ratios = price_data.base_price_tax;

				price_data.base_price_totals = this.get_taxed_totals( base_price, base_regular_price, base_price_tax_ratios, qty );
			}

			// Items.
			$.each( combo.combined_items, function( index, combined_item ) {

				if ( false !== triggered_by && triggered_by.combined_item_id !== combined_item.combined_item_id ) {
					return true;
				}

				var product_qty             = combined_item.is_sold_individually() && price_data.quantities[ combined_item.combined_item_id ] > 0 ? 1 : price_data.quantities[ combined_item.combined_item_id ] * qty,
					product_id              = combined_item.get_product_type() === 'variable' ? combined_item.get_variation_id() : combined_item.get_product_id(),
					tax_ratios              = price_data.prices_tax[ combined_item.combined_item_id ],
					regular_price           = price_data.regular_prices[ combined_item.combined_item_id ] + price_data.regular_addons_prices[ combined_item.combined_item_id ],
					price                   = price_data.prices[ combined_item.combined_item_id ] + price_data.addons_prices[ combined_item.combined_item_id ],
					regular_recurring_price = price_data.regular_recurring_prices[ combined_item.combined_item_id ] + price_data.regular_addons_prices[ combined_item.combined_item_id ],
					recurring_price         = price_data.recurring_prices[ combined_item.combined_item_id ] + price_data.addons_prices[ combined_item.combined_item_id ],
					totals                  = {
						price:          0.0,
						regular_price:  0.0,
						price_incl_tax: 0.0,
						price_excl_tax: 0.0
					},
					recurring_totals        = {
						price:          0.0,
						regular_price:  0.0,
						price_incl_tax: 0.0,
						price_excl_tax: 0.0
					};

				if ( wc_combo_params.calc_taxes === 'yes' ) {

					if ( product_id > 0 && product_qty > 0 ) {

						if ( price > 0 || regular_price > 0 ) {
							totals = combo.get_taxed_totals( price, regular_price, tax_ratios, product_qty );
						}

						if ( recurring_price > 0 || regular_recurring_price > 0 ) {
							recurring_totals = combo.get_taxed_totals( recurring_price, regular_recurring_price, tax_ratios, product_qty );
						}
					}

				} else {

					totals.price          = product_qty * price;
					totals.regular_price  = product_qty * regular_price;
					totals.price_incl_tax = product_qty * price;
					totals.price_excl_tax = product_qty * price;

					recurring_totals.price          = product_qty * recurring_price;
					recurring_totals.regular_price  = product_qty * regular_recurring_price;
					recurring_totals.price_incl_tax = product_qty * recurring_price;
					recurring_totals.price_excl_tax = product_qty * recurring_price;
				}

				// Filter combined item totals.
				totals = combo.filters.apply_filters( 'combined_item_totals', [ totals, combined_item, qty ] );

				// Filter combined item totals.
				recurring_totals = combo.filters.apply_filters( 'combined_item_recurring_totals', [ recurring_totals, combined_item, qty ] );

				var item_totals_changed = false;

				if ( combo.totals_changed( price_data[ 'combined_item_' + combined_item.combined_item_id + '_totals' ], totals ) ) {
					item_totals_changed    = true;
					combo.dirty_subtotals = true;
					price_data[ 'combined_item_' + combined_item.combined_item_id + '_totals' ] = totals;
				}

				if ( combo.totals_changed( price_data[ 'combined_item_' + combined_item.combined_item_id + '_recurring_totals' ], recurring_totals ) ) {
					item_totals_changed    = true;
					combo.dirty_subtotals = true;
					price_data[ 'combined_item_' + combined_item.combined_item_id + '_recurring_totals' ] = recurring_totals;
				}

				if ( item_totals_changed ) {
					combo.$combo_data.trigger( 'woocommerce-combined-item-totals-changed', [ combined_item ] );
				}

			} );

			if ( typeof( price_data_array ) !== 'undefined' ) {
				return price_data;
			}
		};

		/**
		 * Adds combo subtotals and calculates combo totals.
		 */
		this.calculate_totals = function( price_data_array ) {

			if ( typeof( price_data_array ) === 'undefined' ) {
				combo.$combo_data.trigger( 'woocommerce-product-combo-calculate-totals', [ combo ] );
			}

			var price_data     = typeof( price_data_array ) === 'undefined' ? combo.price_data : price_data_array,
				totals_changed = false;

			// Non-recurring (sub)totals.
			var subtotals, totals = {
				price:          wc_pb_number_round( price_data.base_price_totals.price ),
				regular_price:  wc_pb_number_round( price_data.base_price_totals.regular_price ),
				price_incl_tax: wc_pb_number_round( price_data.base_price_totals.price_incl_tax ),
				price_excl_tax: wc_pb_number_round( price_data.base_price_totals.price_excl_tax )
			};

			$.each( combo.combined_items, function( index, combined_item ) {

				if ( combined_item.is_unavailable() ) {
					return true;
				}

				var item_totals = price_data[ 'combined_item_' + combined_item.combined_item_id + '_totals' ];

				if ( typeof item_totals !== 'undefined' ) {

					totals.price          += wc_pb_number_round( item_totals.price );
					totals.regular_price  += wc_pb_number_round( item_totals.regular_price );
					totals.price_incl_tax += wc_pb_number_round( item_totals.price_incl_tax );
					totals.price_excl_tax += wc_pb_number_round( item_totals.price_excl_tax );
				}

			} );

			// Recurring (sub)totals, grouped by recurring id.
			var combined_subs     = combo.get_combined_subscriptions(),
				recurring_totals = {};

			if ( combined_subs ) {

				$.each( combined_subs, function( index, combined_sub ) {

					var combined_item_id = combined_sub.combined_item_id;

					if ( price_data.quantities[ combined_item_id ] === 0 ) {
						return true;
					}

					if ( combined_sub.get_product_type() === 'variable-subscription' && combined_sub.get_variation_id() === '' ) {
						return true;
					}

					var recurring_key         = price_data.recurring_keys[ combined_item_id ],
						recurring_item_totals = price_data[ 'combined_item_' + combined_item_id + '_recurring_totals' ];

					if ( typeof( recurring_totals[ recurring_key ] ) === 'undefined' ) {

						recurring_totals[ recurring_key ] = {
							html:           price_data.recurring_html[ combined_item_id ],
							price:          recurring_item_totals.price,
							regular_price:  recurring_item_totals.regular_price,
							price_incl_tax: recurring_item_totals.price_incl_tax,
							price_excl_tax: recurring_item_totals.price_excl_tax
						};

					} else {

						recurring_totals[ recurring_key ].price          += recurring_item_totals.price;
						recurring_totals[ recurring_key ].regular_price  += recurring_item_totals.regular_price;
						recurring_totals[ recurring_key ].price_incl_tax += recurring_item_totals.price_incl_tax;
						recurring_totals[ recurring_key ].price_excl_tax += recurring_item_totals.price_excl_tax;
					}

				} );
			}

			subtotals = totals;

			// Filter the totals.
			totals = combo.filters.apply_filters( 'combo_totals', [ totals, price_data, combo ] );

			totals_changed = combo.totals_changed( price_data.totals, totals );

			if ( ! totals_changed && combined_subs ) {

				var recurring_totals_pre  = JSON.stringify( price_data.recurring_totals ),
					reccuring_totals_post = JSON.stringify( recurring_totals );

				if ( recurring_totals_pre !== reccuring_totals_post ) {
					totals_changed = true;
				}
			}

			// Render.
			if ( totals_changed || false === combo.is_initialized ) {

				price_data.subtotals        = subtotals;
				price_data.totals           = totals;
				price_data.recurring_totals = recurring_totals;

				if ( typeof( price_data_array ) === 'undefined' ) {
					this.updated_totals();
				}
			}

			return price_data;
		};

		/**
		 * Schedules a UI combo price string refresh.
		 */
		this.updated_totals = function() {

			clearTimeout( combo.update_price_timer );

			combo.update_price_timer = setTimeout( function() {
				combo.updated_totals_task();
			}, 5 );
		};

		/**
		 * Build the non-recurring price html component.
		 */
		this.get_price_html = function( price_data_array ) {

			var price_data    = typeof( price_data_array ) === 'undefined' ? combo.price_data : price_data_array,
				recalc_totals = false,
				qty           = combo.is_composited() ? combo.composite_data.component.get_selected_quantity() : 1,
				tag           = 'p';

			if ( combo.has_addons() ) {

				price_data    = $.extend( true, {}, price_data );
				recalc_totals = true;

				var addons_raw_price         = price_data.addons_price ? price_data.addons_price : combo.get_addons_raw_price(),
					addons_raw_regular_price = price_data.addons_regular_price ? price_data.addons_regular_price : addons_raw_price;

				// Recalculate price html with add-ons price embedded in base price.
				if ( addons_raw_price > 0 ) {
					price_data.base_price = Number( price_data.base_price ) + Number( addons_raw_price );
				}

				if ( addons_raw_regular_price > 0 ) {
					price_data.base_regular_price = Number( price_data.base_regular_price ) + Number( addons_raw_regular_price );
				}
			}

			if ( combo.is_composited() ) {

				tag = 'span';

				if ( 'yes' === price_data.composited_totals_incl_qty ) {
					recalc_totals = true;
				}
			}

			if ( recalc_totals ) {
				// Recalculate price html with qty embedded.
				price_data = combo.calculate_subtotals( false, price_data, qty );
				price_data = combo.calculate_totals( price_data );
			}

			var	combo_price_html = '',
				total_string      = 'yes' === price_data.show_total_string && wc_combo_params.i18n_total ? '<span class="total">' + wc_combo_params.i18n_total + '</span>' : '';

			// Non-recurring price html data.
			var formatted_price         = price_data.totals.price === 0.0 && price_data.show_free_string === 'yes' ? wc_combo_params.i18n_free : wc_pb_price_format( price_data.totals.price ),
				formatted_regular_price = wc_pb_price_format( price_data.totals.regular_price ),
				formatted_suffix        = combo.get_formatted_price_suffix( price_data );

			if ( price_data.totals.regular_price > price_data.totals.price ) {
				formatted_price = wc_combo_params.i18n_strikeout_price_string.replace( '%f', formatted_regular_price ).replace( '%t', formatted_price );
			}

			combo_price_html = wc_combo_params.i18n_price_format.replace( '%t', total_string ).replace( '%p', formatted_price ).replace( '%s', formatted_suffix );

			var combo_recurring_price_html = combo.get_recurring_price_html();

			if ( ! combo_recurring_price_html ) {

				combo_price_html = '<' + tag + ' class="price">' + combo_price_html + '</' + tag + '>';

			} else {

				var has_up_front_price_component = price_data.totals.regular_price > 0;

				if ( ! has_up_front_price_component ) {
					combo_price_html = '<' + tag + ' class="price">' + price_data.price_string_recurring.replace( '%r', combo_recurring_price_html ) + '</' + tag + '>';
				} else {
					combo_price_html = '<' + tag + ' class="price">' + price_data.price_string_recurring_up_front.replace( '%s', combo_price_html ).replace( '%r', combo_recurring_price_html ) + '</' + tag + '>';
				}
			}

			return combo_price_html;
		};

		/**
		 * Builds the recurring price html component for combos that contain subscription products.
		 */
		this.get_recurring_price_html = function( price_data_array ) {

			var price_data = typeof( price_data_array ) === 'undefined' ? combo.price_data : price_data_array;

			var combo_recurring_price_html = '',
				combined_subs                = combo.get_combined_subscriptions();

			if ( combined_subs ) {

				var has_up_front_price_component = price_data.totals.regular_price > 0,
				    recurring_totals_data = [];

				for ( var recurring_total_key in price_data.recurring_totals ) {

					if ( ! price_data.recurring_totals.hasOwnProperty( recurring_total_key ) ) {
						continue;
					}

					recurring_totals_data.push( price_data.recurring_totals[ recurring_total_key ] );
				}

				$.each( recurring_totals_data, function( recurring_component_index, recurring_component_data ) {

					var formatted_recurring_price         = recurring_component_data.price == 0 ? wc_combo_params.i18n_free : wc_pb_price_format( recurring_component_data.price ),
						formatted_regular_recurring_price = wc_pb_price_format( recurring_component_data.regular_price ),
						formatted_recurring_price_html    = '',
						formatted_suffix                  = combo.get_formatted_price_suffix( price_data, {
							price_incl_tax: recurring_component_data.price_incl_tax,
							price_excl_tax: recurring_component_data.price_excl_tax
						} );

					if ( recurring_component_data.regular_price > recurring_component_data.price ) {
						formatted_recurring_price = wc_combo_params.i18n_strikeout_price_string.replace( '%f', formatted_regular_recurring_price ).replace( '%t', formatted_recurring_price );
					}

					formatted_recurring_price_html = wc_combo_params.i18n_price_format.replace( '%t', '' ).replace( '%p', formatted_recurring_price ).replace( '%s', formatted_suffix );
					formatted_recurring_price_html = '<span class="combined_sub_price_html">' + recurring_component_data.html.replace( '%s', formatted_recurring_price_html ) + '</span>';

					if ( recurring_component_index === recurring_totals_data.length - 1 || ( recurring_component_index === 0 && ! has_up_front_price_component ) ) {
						if ( recurring_component_index > 0 || has_up_front_price_component ) {
							combo_recurring_price_html = wc_combo_params.i18n_recurring_price_join_last.replace( '%r', combo_recurring_price_html ).replace( '%c', formatted_recurring_price_html );
						} else {
							combo_recurring_price_html = formatted_recurring_price_html;
						}
					} else {
						combo_recurring_price_html = wc_combo_params.i18n_recurring_price_join.replace( '%r', combo_recurring_price_html ).replace( '%c', formatted_recurring_price_html );
					}

				} );
			}

			return combo_recurring_price_html;
		};

		/**
		 * Determines whether to show a combo price html string.
		 */
		this.show_price_html = function() {

			if ( combo.showing_price_html ) {
				return true;
			}

			var show_price = wc_pb_number_round( combo.price_data.totals.price ) !== wc_pb_number_round( combo.price_data.raw_combo_price_min ) || combo.price_data.raw_combo_price_min !== combo.price_data.raw_combo_price_max;

			if ( combo.get_combined_subscriptions() ) {
				$.each( combo.combined_items, function( index, combined_item ) {
					if ( combo.price_data.recurring_prices[ combined_item.combined_item_id ] > 0 && combo.price_data.quantities[ combined_item.combined_item_id ] > 0 ) {
						if ( combined_item.is_subscription( 'variable' ) || combined_item.is_optional() || combined_item.$self.find( '.quantity input[type!=hidden]' ).length ) {
							show_price = true;
							return false;
						}
					}
				} );
			}

			if ( show_price ) {
				$.each( combo.combined_items, function( index, combined_item ) {
					if ( combined_item.is_unavailable() && combined_item.is_required() ) {
						show_price = false;
						return false;
					}
				} );
			}

			if ( ! show_price ) {
				$.each( combo.combined_items, function( index, combined_item ) {
					if ( 'yes' === combo.price_data.has_variable_quantity[ combined_item.combined_item_id ] && combo.price_data[ 'combined_item_' + combined_item.combined_item_id + '_totals' ].price > 0 ) {
						show_price = true;
					}
				} );
			}

			if ( combo.is_composited() ) {

				if ( ! show_price ) {
					if ( combo.composite_data.composite.api.is_component_priced_individually( this.composite_data.component.step_id ) ) {
						show_price = true;
					}
				}

				if ( show_price ) {
					if ( false === this.composite_data.component.is_selected_product_price_visible() ) {
						show_price = false;
					} else if ( false === combo.composite_data.composite.api.is_component_priced_individually( this.composite_data.component.step_id ) ) {
						show_price = false;
					}
				}
			}

			if ( show_price ) {
				combo.showing_price_html = true;
			}

			return show_price;
		};

		/**
		 * Refreshes the combo price string in the UI.
		 */
		this.updated_totals_task = function() {

			var show_price = combo.show_price_html();

			if ( ( combo.passes_validation() || 'no' === combo.price_data.hide_total_on_validation_fail ) && show_price ) {

				var combo_price_html = combo.get_price_html();

				// Pass the price string through a filter.
				combo_price_html = combo.filters.apply_filters( 'combo_total_price_html', [ combo_price_html, combo ] );

				combo.$combo_price.html( combo_price_html );

				combo.$combo_price.slideDown( 200 );

			} else {
				combo.$combo_price.slideUp( 200 );
			}

			combo.$combo_data.trigger( 'woocommerce-product-combo-updated-totals', [ combo ] );
		};

		this.updated_addons_handler = function() {
			combo.updated_totals_task();
		};

		this.has_addons = function() {
			return this.$addons_totals && this.$addons_totals.length > 0;
		};

		this.has_pct_addons = function( combined_item ) {

			var is_combined_item  = typeof( combined_item ) !== 'undefined',
				obj              = is_combined_item ? combined_item : this,
				has              = false;

			if ( ! obj.has_addons ) {
				return has;
			}

			var addons = obj.$addons_totals.data( 'price_data' );

			$.each( addons, function( i, addon ) {
				if ( 'percentage_based' === addon.price_type ) {
					has = true;
					return false;
				}

			} );

			return has;
		};

		this.get_addons_raw_price = function( combined_item, price_prop ) {

			var is_combined_item  = typeof( combined_item ) !== 'undefined',
				price_type       = 'regular' === price_prop ? 'regular': '',
				obj              = is_combined_item ? combined_item : this,
				qty              = is_combined_item ? combined_item.get_quantity() : 1,
				tax_ratios       = is_combined_item ? combo.price_data.prices_tax[ combined_item.combined_item_id ] : combo.price_data.base_price_tax,
				addons_raw_price = 0.0;

			if ( ! obj.has_addons() ) {
				return 0;
			}

			if ( ! qty ) {
				return 0;
			}

			if ( is_combined_item && combined_item.is_variable_product_type() && combined_item.get_variation_id() === '' ) {
				return 0;
			}

			if ( combo.is_composited() ) {
				qty = combo.composite_data.component.get_selected_quantity();
			}

			var addons = obj.$addons_totals.data( 'price_data' );

			$.each( addons, function( i, addon ) {

				if ( addon.is_custom_price ) {

					var addon_raw_price = 0.0,
						tax_ratio_incl  = tax_ratios && typeof( tax_ratios.incl ) !== 'undefined' ? Number( tax_ratios.incl ) : false,
						tax_ratio_excl  = tax_ratios && typeof( tax_ratios.excl ) !== 'undefined' ? Number( tax_ratios.excl ) : false;

					if ( 'incl' === wc_combo_params.tax_display_shop && 'no' === wc_combo_params.prices_include_tax ) {
						addon_raw_price = addon.cost_raw / ( tax_ratio_incl ? tax_ratio_incl : 1 );
					} else if ( 'excl' === wc_combo_params.tax_display_shop && 'yes' === wc_combo_params.prices_include_tax ) {
						addon_raw_price = addon.cost_raw / ( tax_ratio_excl ? tax_ratio_excl : 1 );
					} else {
						addon_raw_price = addon.cost_raw;
					}

					addons_raw_price += addon_raw_price / qty;

				} else {

					if ( 'quantity_based' === addon.price_type ) {
						addons_raw_price += addon.cost_raw_pu;
					} else if ( 'flat_fee' === addon.price_type ) {
						addons_raw_price += addon.cost_raw / qty;
					} else if ( 'percentage_based' === addon.price_type ) {

						var raw_price;

						if ( 'regular' === price_type ) {
							raw_price = is_combined_item ? combo.price_data.regular_prices[ combined_item.combined_item_id ] : combo.price_data.base_regular_price;
						} else {
							raw_price = is_combined_item ? combo.price_data.prices[ combined_item.combined_item_id ] : combo.price_data.base_price;
						}

						addons_raw_price += addon.cost_raw_pct * raw_price;
					}
				}

			} );

			return addons_raw_price;
		};

		/**
		 * Comparison of totals.
		 */
		this.totals_changed = function( totals_pre, totals_post ) {

			if ( typeof( totals_pre ) === 'undefined' || totals_pre.price !== totals_post.price || totals_pre.regular_price !== totals_post.regular_price || totals_pre.price_incl_tax !== totals_post.price_incl_tax || totals_pre.price_excl_tax !== totals_post.price_excl_tax ) {
				return true;
			}

			return false;
		};

		/**
		 * True if the combo is part of a composite product.
		 */
		this.is_composited = function() {
			return false !== this.composite_data;
		};

		/**
		 * Replace totals in price suffix.
		 */
		this.get_formatted_price_suffix = function( price_data_array, totals ) {

			var price_data = typeof( price_data_array ) === 'undefined' ? combo.price_data : price_data_array,
				suffix = '';

			totals = typeof( totals ) === 'undefined' ? price_data.totals : totals;

			if ( price_data.suffix_exists ) {

				suffix = price_data.suffix;

				if ( price_data.suffix_contains_price_incl ) {
					suffix = suffix.replace( '{price_including_tax}', wc_pb_price_format( totals.price_incl_tax ) );
				}

				if ( price_data.suffix_contains_price_excl ) {
					suffix = suffix.replace( '{price_excluding_tax}', wc_pb_price_format( totals.price_excl_tax ) );
				}
			}

			return suffix;
		};

		/**
		 * Find and return WC_LafkaCombos_Combined_Item objects that are subs.
		 */
		this.get_combined_subscriptions = function( type ) {

			var combined_subs = {},
				has_sub      = false;

			$.each( combo.combined_items, function( index, combined_item ) {

				if ( combined_item.is_subscription( type ) && combined_item.is_priced_individually() ) {

					combined_subs[ index ] = combined_item;
					has_sub               = true;
				}

			} );

			if ( has_sub ) {
				return combined_subs;
			}

			return false;
		};

		/**
		 * Adds a validation message.
		 */
		this.add_validation_message = function( message ) {

			this.validation_messages.push( message.toString() );
		};

		/**
		 * Validation messages getter.
		 */
		this.get_validation_messages = function() {

			return this.validation_messages;
		};

		/**
		 * Validation state getter.
		 */
		this.passes_validation = function() {

			if ( this.validation_messages.length > 0 ) {
				return false;
			}

			return true;
		};

		/**
		 * Check group mode feature support.
		 */
		this.group_mode_supports = function( $feature ) {
			return $.inArray( $feature, this.price_data.group_mode_features ) > -1;
		};
	}

	/**
	 * Combined Item object.
	 */
	function WC_LafkaCombos_Combined_Item( combo, $combined_item, index ) {

		this.initialize = function() {

			this.$self                          = $combined_item;
			this.$combined_item_cart             = $combined_item.find( '.cart' );
			this.$combined_item_content          = $combined_item.find( '.combined_item_optional_content, .combined_item_cart_content' );
			this.$combined_item_image            = $combined_item.find( '.combined_product_images' );
			this.$combined_item_title            = $combined_item.find( '.combined_product_title_inner' );
			this.$combined_item_qty              = $combined_item.find( 'input.combined_qty' );

			this.$addons_totals                 = $combined_item.find( '#product-addons-total' );
			this.$required_addons               = false;
			this.$nyp                           = $combined_item.find( '.nyp' );

			this.$attribute_select              = false;
			this.$attribute_select_config       = false;

			this.$reset_combined_variations      = false;

			this.render_addons_totals_timer     = false;
			this.show_addons_totals             = false;
			this.addons_totals_html             = '';

			this.combined_item_index             = index;
			this.combined_item_id                = this.$combined_item_cart.data( 'combined_item_id' );
			this.combined_item_title             = this.$combined_item_cart.data( 'title' );
			this.combined_item_title_raw         = this.combined_item_title ? $( '<div/>' ).html( this.combined_item_title ).text() : '';
			this.combined_item_product_title     = this.$combined_item_cart.data( 'product_title' );
			this.combined_item_product_title_raw = this.combined_item_title ? $( '<div/>' ).html( this.combined_item_title ).text() : '';
			this.combined_item_optional_suffix   = typeof( this.$combined_item_cart.data( 'optional_suffix' ) ) === 'undefined' ? wc_combo_params.i18n_optional : this.$combined_item_cart.data( 'optional_suffix' );

			this.product_type                   = this.$combined_item_cart.data( 'type' );
			this.product_id                     = typeof( combo.price_data.product_ids[ this.combined_item_id ] ) === 'undefined' ? '' : combo.price_data.product_ids[ this.combined_item_id ].toString();
			this.nyp                            = typeof( combo.price_data.product_ids[ this.combined_item_id ] ) === 'undefined' ? false : combo.price_data.is_nyp[ this.combined_item_id ] === 'yes';
			this.sold_individually              = typeof( combo.price_data.product_ids[ this.combined_item_id ] ) === 'undefined' ? false : combo.price_data.is_sold_individually[ this.combined_item_id ] === 'yes';
			this.priced_individually            = typeof( combo.price_data.product_ids[ this.combined_item_id ] ) === 'undefined' ? false : combo.price_data.is_priced_individually[ this.combined_item_id ] === 'yes';
			this.variation_id                   = '';

			this.has_wc_core_gallery_class      = this.$combined_item_image.hasClass( 'images' );

			if ( typeof( this.combined_item_id ) === 'undefined' ) {
				this.combined_item_id = this.$combined_item_cart.attr( 'data-combined-item-id' );
			}

			this.initialize_addons();
		};

		this.initialize_addons = function() {

			if ( this.has_addons() ) {

				// Totals visible?
				if ( 1 == this.$addons_totals.data( 'show-sub-total' ) ) {
					// Ensure addons ajax is not triggered at all, as we calculate tax on the client side.
					this.$addons_totals.data( 'show-sub-total', 0 );
					this.show_addons_totals = true;
				}

				this.$required_addons = this.$combined_item_cart.find( '.wc-pao-required-addon [required]' );

			} else {
				this.$addons_totals = false;
			}
		};

		this.get_combo = function() {
			return combo;
		};

		this.get_title = function( strip_tags ) {
			strip_tags = typeof( strip_tags ) === 'undefined' ? false : strip_tags;
			return strip_tags ? this.combined_item_title_raw : this.combined_item_title;
		};

		this.get_product_title = function( strip_tags ) {
			strip_tags = typeof( strip_tags ) === 'undefined' ? false : strip_tags;
			return strip_tags ? this.combined_item_product_title_raw : this.combined_item_product_title;
		};

		this.get_optional_suffix = function() {
			return this.combined_item_optional_suffix;
		};

		this.get_product_id = function() {
			return this.product_id;
		};

		this.get_variation_id = function() {
			return this.variation_id;
		};

		this.get_variation_data = function() {
			return this.$combined_item_cart.data( 'product_variations' );
		};

		this.get_product_type = function() {
			return this.product_type;
		};

		this.is_variable_product_type = function() {
			return this.product_type === 'variable' || this.product_type === 'variable-subscription';
		};

		this.get_quantity = function() {
			var qty = this.$combined_item_qty.val();
			return isNaN( qty ) ? 0 : parseInt( qty, 10 );
		};

		this.get_selected_quantity = function() {
			return combo.price_data.quantities[ this.combined_item_id ];
		};

		this.get_available_quantity = function() {
			return combo.price_data.quantities_available[ this.combined_item_id ];
		};

		this.is_in_stock = function() {
			return 'no' !== combo.price_data.is_in_stock[ this.combined_item_id ];
		};

		this.has_insufficient_stock = function() {

			if ( ! this.is_selected() || 0 === this.get_selected_quantity() || ( this.is_variable_product_type() && '' === this.get_variation_id() ) ) {
				return false;
			}

			if ( ! this.is_in_stock() || ( '' !== this.get_available_quantity() && this.get_selected_quantity() > this.get_available_quantity() ) ) {
				if ( ! this.backorders_allowed() ) {
					return true;
				}
			}

			return false;
		};

		this.is_backordered = function() {

			if ( ! this.is_selected() || 0 === this.get_selected_quantity() || ( this.is_variable_product_type() && '' === this.get_variation_id() ) ) {
				return false;
			}

			if ( '' === this.get_available_quantity() || this.get_selected_quantity() > this.get_available_quantity() ) {
				if ( this.backorders_allowed() && this.backorders_require_notification() ) {
					return true;
				}
			}

			return false;
		};

		this.backorders_allowed = function() {
			return 'yes' === combo.price_data.backorders_allowed[ this.combined_item_id ];
		};

		this.backorders_require_notification = function() {
			return 'yes' === combo.price_data.backorders_require_notification[ this.combined_item_id ];
		};

		this.is_optional = function() {
			return ( this.$combined_item_cart.data( 'optional' ) === 'yes' || this.$combined_item_cart.data( 'optional' ) === 1 );
		};

		this.is_unavailable = function() {
			return 'yes' === this.$combined_item_cart.data( 'custom_data' ).is_unavailable;
		};

		this.is_required = function() {
			return ! this.is_optional() && 'no' !== this.$combined_item_cart.data( 'custom_data' ).is_required;
		};

		this.is_visible = function() {
			return ( this.$combined_item_cart.data( 'visible' ) === 'yes' || this.$combined_item_cart.data( 'visible' ) === 1 );
		};

		this.is_selected = function() {

			var selected = true;

			if ( this.is_optional() ) {
				if ( this.$combined_item_cart.data( 'optional_status' ) === false ) {
					selected = false;
				}
			}

			return selected;
		};

		this.set_selected = function( status ) {

			if ( this.is_optional() ) {
				this.$combined_item_cart.data( 'optional_status', status );

				if( this.is_nyp() ) {
					this.$nyp.data( 'optional_status', status );
				}
			}
		};

		this.init_scripts = function() {

			// Init PhotoSwipe if present.
			if ( typeof PhotoSwipe !== 'undefined' && 'yes' === wc_combo_params.photoswipe_enabled ) {
				this.init_photoswipe();
			}

			// Init dependencies.
			this.$self.find( '.combined_product_optional_checkbox input' ).trigger( 'change' );
			this.$self.find( 'input.combined_qty' ).trigger( 'change' );

			if ( this.is_variable_product_type() && ! this.$combined_item_cart.hasClass( 'variations_form' ) ) {

				// Variations reset wrapper.
				this.$reset_combined_variations = this.$combined_item_cart.find( '.reset_combined_variations' );

				if ( this.$reset_combined_variations.length === 0 ) {
					this.$reset_combined_variations = false;
				}

				// Initialize variations script.
				this.$combined_item_cart.addClass( 'variations_form' ).wc_variation_form();

				// Set cached selects.
				this.$attribute_select        = this.$combined_item_cart.find( '.variations .attribute_options select' );
				this.$attribute_select_config = this.$attribute_select.filter( function() {
					return false === $( this ).parent().hasClass( 'combined_variation_attribute_options_wrapper' );
				} );

				// Trigger change event.
				if ( this.$attribute_select.length > 0 ) {
					this.$attribute_select.first().trigger( 'change' );
				}
			}

			this.$self.find( 'div' ).stop( true, true );
			this.update_selection_title();
		};

		this.init_photoswipe = function() {

			if ( $.fn.wc_product_gallery ) {
				this.$combined_item_image.wc_product_gallery( { zoom_enabled: 'yes' === wc_combo_params.zoom_enabled, flexslider_enabled: false } );
			} else {
				window.console.warn( 'Failed to initialize PhotoSwipe for combined item images. Your theme declares PhotoSwipe support, but function \'$.fn.wc_product_gallery\' is undefined.' );
			}

			var $placeholder = this.$combined_item_image.find( 'a.placeholder_image' );

			if ( $placeholder.length > 0 ) {
				$placeholder.on( 'click', function() {
					return false;
				} );
			}
		};

		this.update_selection_title = function( reset ) {

			if ( this.$combined_item_title.length === 0 ) {
				return false;
			}

			var combined_item_qty_val = parseInt( this.get_quantity(), 10 );

			if ( isNaN( combined_item_qty_val ) ) {
				return false;
			}

			reset = typeof( reset ) === 'undefined' ? false : reset;

			if ( reset ) {
				combined_item_qty_val = parseInt( this.$combined_item_qty.attr( 'min' ), 10 );
			}

			if ( 'tabular' === combo.price_data.layout ) {
				combined_item_qty_val = 1;
			}

			var selection_title           = this.combined_item_title,
				selection_qty_string      = combined_item_qty_val > 1 ? wc_combo_params.i18n_qty_string.replace( '%s', combined_item_qty_val ) : '',
				selection_optional_string = ( this.is_optional() && this.get_optional_suffix() !== '' ) ? wc_combo_params.i18n_optional_string.replace( '%s', this.get_optional_suffix() ) : '',
				selection_title_incl_qty  = wc_combo_params.i18n_title_string.replace( '%t', selection_title ).replace( '%q', selection_qty_string ).replace( '%o', selection_optional_string );

			this.$combined_item_title.html( selection_title_incl_qty );
		};

		this.reset_selection_title = function() {
			this.update_selection_title( true );
		};

		this.is_subscription = function( type ) {

			if ( 'simple' === type ) {
				return this.product_type === 'subscription';
			} else if ( 'variable' === type ) {
				return this.product_type === 'variable-subscription';
			} else {
				return this.product_type === 'subscription' || this.product_type === 'variable-subscription';
			}
		};

		this.has_addons = function() {
			return this.$addons_totals && this.$addons_totals.length > 0;
		};

		this.has_required_addons = function() {
			return this.$required_addons && this.$required_addons.length > 0;
		};

		this.update_addons_prices = function() {

			var addons_price         = combo.get_addons_raw_price( this ),
				regular_addons_price = combo.has_pct_addons( this ) ? combo.get_addons_raw_price( this, 'regular' ) : addons_price;

			if ( combo.price_data.addons_prices[ this.combined_item_id ] !== addons_price || combo.price_data.regular_addons_prices[ this.combined_item_id ] !== regular_addons_price ) {
				combo.price_data.addons_prices[ this.combined_item_id ]         = addons_price;
				combo.price_data.regular_addons_prices[ this.combined_item_id ] = regular_addons_price;
			}
		};

		this.render_addons_totals = function() {

			var combined_item = this;

			clearTimeout( this.render_addons_totals_timer );

			this.render_addons_totals_timer = setTimeout( function() {
				combined_item.render_addons_totals_task();
			}, 10 );
		};

		this.render_addons_totals_task = function() {

			if ( ! this.has_addons ) {
				return;
			}

			var addons_price = combo.price_data.addons_prices[ this.combined_item_id ];

			if ( this.show_addons_totals ) {

				if ( ! this.is_variable_product_type() || this.get_variation_id() !== '' ) {

					var qty           = this.get_quantity(),
						tax_ratios    = combo.price_data.prices_tax[ this.combined_item_id ],
						addons_totals = combo.get_taxed_totals( addons_price, addons_price, tax_ratios, qty );

					if ( addons_totals.price > 0 ) {

						var price              = Number( combo.price_data.prices[ this.combined_item_id ] ),
							total              = price + Number( addons_price ),
							totals             = combo.get_taxed_totals( total, total, tax_ratios, qty ),
							price_html         = wc_pb_price_format( totals.price ),
							price_html_suffix  = combo.get_formatted_price_suffix( combo.price_data, totals ),
							addons_totals_html = '<span class="price">' + '<span class="subtotal">' + wc_combo_params.i18n_subtotal + '</span>' + price_html + price_html_suffix + '</span>';

						// Save for later use.
						this.addons_totals_html = addons_totals_html;

						this.$addons_totals.html( addons_totals_html ).slideDown( 200 );

					} else {
						this.$addons_totals.slideUp( 200 );
					}

				} else {
					this.$addons_totals.slideUp( 200 );
				}
			}
		};

		this.has_single_variation = function() {

			if ( typeof this.get_variation_data() !== 'undefined' ) {
				return 1 === this.get_variation_data().length;
			}

			return false;
		};

		this.is_nyp = function() {
			return this.nyp;
		};

		this.is_nyp_valid = function() {

			var status = true;

			if ( $.fn.wc_nyp_get_script_object ) {

				var nyp_script = this.$nyp.wc_nyp_get_script_object();

				if ( nyp_script && false === nyp_script.isValid() ) {
					status = false;
				}
			}

			return status;

		};

		this.is_sold_individually = function() {
			return this.sold_individually;
		};

		this.is_priced_individually = function() {
			return this.priced_individually;
		};

		this.maybe_add_wc_core_gallery_class = function() {
			if ( ! this.has_wc_core_gallery_class ) {
				this.$combined_item_image.addClass( 'images' );
			}
		};

		this.maybe_remove_wc_core_gallery_class = function() {
			if ( ! this.has_wc_core_gallery_class ) {
				this.$combined_item_image.removeClass( 'images' );
			}
		};

		this.initialize();
	}

	/**
	 * Filters API.
	 */
	function WC_LafkaCombos_Filters_Manager() {

		/*
		 *--------------------------*
		 *                          *
		 *   Filters Reference      *
		 *                          *
		 *--------------------------*
		 *
		 *
		 * Filter 'combo_subtotals_data':
		 *
		 * Filters the combo price data array after calculating subtotals.
		 *
		 * @param  array   price_data   Price data array.
		 * @param  object  combo       Combo object.
		 * @return array
		 *
		 * @hooked void
		 *
		 *
		 *
		 * Filter 'combo_total_price_html':
		 *
		 * Filters the price html total.
		 *
		 * @param  string  totals   Markup to display.
		 * @param  object  combo       Combo object.
		 * @return string
		 *
		 * @hooked void
		 */

		var manager   = this,
			filters   = {},
			functions = {

				add_filter: function( hook, callback, priority, context ) {

					var hookObject = {
						callback : callback,
						priority : priority,
						context : context
					};

					var hooks = filters[ hook ];
					if ( hooks ) {
						hooks.push( hookObject );
						hooks = this.sort_filters( hooks );
					} else {
						hooks = [ hookObject ];
					}

					filters[ hook ] = hooks;
				},

				remove_filter: function( hook, callback, context ) {

					var handlers, handler, i;

					if ( ! filters[ hook ] ) {
						return;
					}
					if ( ! callback ) {
						filters[ hook ] = [];
					} else {
						handlers = filters[ hook ];
						if ( ! context ) {
							for ( i = handlers.length; i--; ) {
								if ( handlers[ i ].callback === callback ) {
									handlers.splice( i, 1 );
								}
							}
						} else {
							for ( i = handlers.length; i--; ) {
								handler = handlers[ i ];
								if ( handler.callback === callback && handler.context === context) {
									handlers.splice( i, 1 );
								}
							}
						}
					}
				},

				sort_filters: function( hooks ) {

					var tmpHook, j, prevHook;
					for ( var i = 1, len = hooks.length; i < len; i++ ) {
						tmpHook = hooks[ i ];
						j = i;
						while( ( prevHook = hooks[ j - 1 ] ) &&  prevHook.priority > tmpHook.priority ) {
							hooks[ j ] = hooks[ j - 1 ];
							--j;
						}
						hooks[ j ] = tmpHook;
					}

					return hooks;
				},

				apply_filters: function( hook, args ) {

					var handlers = filters[ hook ], i, len;

					if ( ! handlers ) {
						return args[ 0 ];
					}

					len = handlers.length;

					for ( i = 0; i < len; i++ ) {
						args[ 0 ] = handlers[ i ].callback.apply( handlers[ i ].context, args );
					}

					return args[ 0 ];
				}

			};

		/**
		 * Adds a filter.
		 */
		this.add_filter = function( filter, callback, priority, context ) {

			if ( typeof filter === 'string' && typeof callback === 'function' ) {
				priority = parseInt( ( priority || 10 ), 10 );
				functions.add_filter( filter, callback, priority, context );
			}

			return manager;
		};

		/**
		 * Applies all filter callbacks.
		 */
		this.apply_filters = function( filter, args ) {

			if ( typeof filter === 'string' ) {
				return functions.apply_filters( filter, args );
			}
		};

		/**
		 * Removes the specified filter callback.
		 */
		this.remove_filter = function( filter, callback ) {

			if ( typeof filter === 'string' ) {
				functions.remove_filter( filter, callback );
			}

			return manager;
		};

	}

	/*-----------------------------------------------------------------*/
	/*  Initialization.                                                */
	/*-----------------------------------------------------------------*/

	jQuery( function( $ ) {

		/**
		 * QuickView compatibility.
		 */
		$( 'body' ).on( 'quick-view-displayed', function() {
			$( '.quick-view .combo_form .combo_data' ).each( function() {

				var $combo_data    = $( this ),
					$composite_form = $combo_data.closest( '.composite_form' );

				// If part of a composite, let the composite initialize it.
				if ( $composite_form.length === 0 ) {
					$combo_data.wc_pb_combo_form();
				}

			} );
		} );

		/**
		 * Script initialization on '.combo_data' jQuery objects.
		 */
		$.fn.wc_pb_combo_form = function() {

			if ( ! $( this ).hasClass( 'combo_data' ) ) {
				return true;
			}

			var $combo_data = $( this ),
				container_id = $combo_data.data( 'combo_id' );

			if ( typeof( container_id ) === 'undefined' ) {
				container_id = $combo_data.attr( 'data-combo-id' );

				if ( container_id ) {
					$combo_data.data( 'combo_id', container_id );
				} else {
					return false;
				}
			}

			var $combo_form     = $combo_data.closest( '.combo_form' ),
				$composite_form  = $combo_form.closest( '.composite_form' ),
				composite_data   = false,
				combo_script_id = container_id;

			// If part of a composite product, get a unique id for the script object and prepare variables for integration code.
			if ( $composite_form.length > 0 ) {

				var $component   = $combo_form.closest( '.component' ),
					component_id = $component.data( 'item_id' );

				if ( component_id > 0 && $.fn.wc_get_composite_script ) {

					var composite_script = $composite_form.wc_get_composite_script();

					if ( false !== composite_script ) {

						var component = composite_script.api.get_step( component_id );

						if ( false !== component ) {
							composite_data = {
								composite: composite_script,
								component: component
							};
							combo_script_id = component_id;
						}
					}
				}
			}

			if ( typeof( wc_pb_combo_scripts[ combo_script_id ] ) !== 'undefined' ) {
				wc_pb_combo_scripts[ combo_script_id ].shutdown();
			}

			wc_pb_combo_scripts[ combo_script_id ] = new WC_LafkaCombos_Combo( { $combo_form: $combo_form, $combo_data: $combo_data, combo_id: container_id, composite_data: composite_data } );

			$combo_form.data( 'script_id', combo_script_id );

			wc_pb_combo_scripts[ combo_script_id ].initialize();
		};

		/*
		 * Initialize form script.
		 */
		$( '.combo_form .combo_data' ).each( function() {

			var $combo_data    = $( this ),
				$composite_form = $combo_data.closest( '.composite_form' );

			// If part of a composite, let the composite initialize it.
			if ( $composite_form.length === 0 ) {
				$combo_data.wc_pb_combo_form();
			}

		} );

	} );

} ) ( jQuery );
