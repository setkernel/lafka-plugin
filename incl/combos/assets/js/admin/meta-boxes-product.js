/* global wc_combos_admin_params */
/* global woocommerce_admin_meta_boxes */

jQuery( function( $ ) {

	function Combined_Item( $el ) {

		var self = this;

		this.$element                        = $el;
		this.$content                        = $el.find( 'div.item-data' );
		this.$discount                       = this.$content.find( '.discount' );
		this.$visibility                     = this.$content.find( '.item_visibility' );
		this.$price_visibility               = this.$content.find( '.price_visibility' );
		this.$allowed_variations             = this.$content.find( 'div.allowed_variations' );
		this.$default_variation_attributes   = this.$content.find( 'div.default_variation_attributes' );
		this.$custom_title                   = this.$content.find( 'div.custom_title' );
		this.$custom_description             = this.$content.find( 'div.custom_description' );
		this.$override_title                 = this.$content.find( '.override_title' );
		this.$override_description           = this.$content.find( '.override_description' );
		this.$hide_thumbnail                 = this.$content.find( '.hide_thumbnail' );

		this.$section_links                  = this.$content.find( '.subsubsub a' );
		this.$sections                       = this.$content.find( '.options_group' );

		this.$priced_individually_input      = this.$content.find( '.priced_individually input' );
		this.$override_variations_input      = this.$content.find( '.override_variations input' );
		this.$override_defaults_input        = this.$content.find( '.override_default_variation_attributes input' );
		this.$override_title_input           = this.$override_title.find( 'input' );
		this.$override_description_input     = this.$override_description.find( 'input' );

		this.$price_visibility_product_input = this.$price_visibility.find( 'input.price_visibility_product' );
		this.$price_visibility_cart_input    = this.$price_visibility.find( 'input.price_visibility_cart' );
		this.$price_visibility_order_input   = this.$price_visibility.find( 'input.price_visibility_order' );

		this.$visibility_product_input       = this.$visibility.find( 'input.visibility_product' );
		this.$visibility_cart_input          = this.$visibility.find( 'input.visibility_cart' );
		this.$visibility_order_input         = this.$visibility.find( 'input.visibility_order' );

		this.priced_individually_input_changed = function() {
			if ( self.$priced_individually_input.is( ':checked' ) ) {
				self.$discount.show();
				self.$price_visibility.show();
			} else {
				self.$discount.hide();
				self.$price_visibility.hide();
			}
		};

		this.override_variations_input_changed = function() {
			if ( self.$override_variations_input.is( ':checked' ) ) {
				self.$allowed_variations.show();
			} else {
				self.$allowed_variations.hide();
			}
		};

		this.override_defaults_input_changed = function() {
			if ( self.$override_defaults_input.is( ':checked' ) ) {
				self.$default_variation_attributes.show();
			} else {
				self.$default_variation_attributes.hide();
			}
		};

		this.override_title_input_changed = function() {
			if ( self.$override_title_input.is( ':checked' ) ) {
				self.$custom_title.show();
			} else {
				self.$custom_title.hide();
			}
		};

		this.override_description_input_changed = function() {
			if ( self.$override_description_input.is( ':checked' ) ) {
				self.$custom_description.show();
			} else {
				self.$custom_description.hide();
			}
		};

		this.visibility_product_input_changed = function() {
			if ( self.$visibility_product_input.is( ':checked' ) ) {

				self.$override_title.show();
				self.$override_description.show();
				self.$hide_thumbnail.show();

				self.override_title_input_changed();
				self.override_description_input_changed();

			} else {

				self.$override_title.hide();
				self.$override_description.hide();
				self.$hide_thumbnail.hide();

				self.$custom_description.hide();
				self.$custom_title.hide();
			}
		};

		this.toggled_visibility = function( visibility_class ) {

			if ( self[ '$visibility_' + visibility_class + '_input' ].is( ':checked' ) ) {
				self[ '$price_visibility_' + visibility_class + '_input' ].css( 'opacity', 1 );
			} else {
				self[ '$price_visibility_' + visibility_class + '_input' ].css( 'opacity', 0.5 );
			}

		};

		this.section_changed = function( $section_link ) {

			self.$section_links.removeClass( 'current' );
			$section_link.addClass( 'current' );

			self.$sections.addClass( 'options_group_hidden' );
			self.$content.find( '.options_group_' + $section_link.data( 'tab' ) ).removeClass( 'options_group_hidden' );
		};

		this.initialize = function() {

			self.priced_individually_input_changed();
			self.override_variations_input_changed();
			self.override_defaults_input_changed();
			self.override_title_input_changed();
			self.override_description_input_changed();
			self.visibility_product_input_changed();

			self.toggled_visibility( 'product' );
			self.toggled_visibility( 'cart' );
			self.toggled_visibility( 'order' );

			self.$element.sw_select2();
		};

		this.initialize();
	}

	var $edit_in_cart                 = $( 'p._wc_pb_edit_in_cart_field' ),
		$product_type_select          = $( 'select#product-type' ),
		$group_mode_select            = $( 'select#_wc_pb_group_mode' ),
		$combined_products_panel       = $( '#combined_product_data' ),
		$combined_products_wrapper     = $combined_products_panel.find( '.wc-metaboxes-wrapper' ),
		$combined_products_toolbar     = $combined_products_panel.find( '.toolbar' ),
		$combined_products_container   = $( '.wc-combined-items' ),
		$combined_products             = $( '.wc-combined-item', $combined_products_container ),
		$combined_product_search       = $( '#combined_product', $combined_products_panel ),
		combined_product_objects       = {},
		combined_products_add_count    = $combined_products.length,
		block_params                  = {
			message: 	null,
			overlayCSS: {
				background: '#fff',
				opacity: 	0.6
			}
		};

	$.fn.wc_combos_select2 = function() {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	};

	// Combo type move stock msg up.
	$( '.combo_stock_msg' ).appendTo( '._manage_stock_field .description' );

	// Hide the default "Sold Individually" field.
	$( '#_sold_individually' ).closest( '.form-field' ).addClass( 'hide_if_combo' );

	// Hide the "Grouping" field.
	$( '#linked_product_data .grouping.show_if_simple, #linked_product_data .form-field.show_if_grouped' ).addClass( 'hide_if_combo' );

	// Simple type options are valid for combos.
	$( '.show_if_simple:not(.hide_if_combo)' ).addClass( 'show_if_combo' );

	/*
	 * WC core event handling.
	 */

	// Combo type specific options.
	$( 'body' ).on( 'woocommerce-product-type-change', function( event, select_val ) {

		if ( 'combo' === select_val ) {

			$( '.show_if_external' ).hide();
			$( '.show_if_combo' ).show();

			$( 'input#_manage_stock' ).trigger( 'change' );

			$( '#_nyp' ).trigger( 'change' );
		}

	} );

	// On submit, post two inputs to determine if 'max_input_vars' kicks in: One at the start of the form (control) and one at the end (test).
	$( 'form#post' ).on( 'submit', function() {

		if ( 'combo' === $product_type_select.val() ) {

			var $form        = $( this ),
			    $control_var = $( '<input type="hidden" name="pb_post_control_var" value="1"/>' ),
			    $test_var    = $( '<input type="hidden" name="pb_post_test_var" value="1"/>' );

			$form.prepend( $control_var );
			$form.append( $test_var );
		}
	} );

	// Show/hide 'Edit in cart' option.
	$group_mode_select.on( 'change', function() {
		if ( $.inArray( $group_mode_select.val(), wc_combos_admin_params.group_modes_with_parent ) === -1 ) {
			$edit_in_cart.hide();
		} else {
			$edit_in_cart.show();
		}
	} );

	// Downloadable support.
	$( 'input#_downloadable' ).on( 'change', function() {
		$product_type_select.trigger( 'change' );
	} );

	// Trigger product type change.
	$product_type_select.trigger( 'change' );

	// Trigger group mode change.
	$group_mode_select.trigger( 'change' );

	init_event_handlers();

	init_combined_products();

	init_combo_shipping();

	init_nux();

	init_expanding_button();

	function init_event_handlers() {

		// Add Product.
		$combined_product_search

			.on( 'change', function() {

				var combined_product_ids = $combined_product_search.val(),
					combined_product_id  = combined_product_ids && combined_product_ids.length > 0 ? combined_product_ids.shift() : false;

				if ( ! combined_product_id ) {
					return false;
				}

				$combined_product_search.val( [] ).trigger( 'change' );

				$combined_products_panel.block( block_params );

				combined_products_add_count++;

				var data = {
					action: 	'woocommerce_add_combined_product',
					post_id: 	woocommerce_admin_meta_boxes.post_id,
					id: 		combined_products_add_count,
					product_id: combined_product_id,
					security: 	wc_combos_admin_params.add_combined_product_nonce
				};

				setTimeout( function() {

					$.post( woocommerce_admin_meta_boxes.ajax_url, data, function ( response ) {

						if ( '' !== response.markup ) {

							$combined_products_container.append( response.markup );

							var $added   = $( '.wc-combined-item', $combined_products_container ).last(),
								added_id = 'combined_item_' + combined_products_add_count;

							$added.data( 'combined_item_id', added_id );
							combined_product_objects[ added_id ] = new Combined_Item( $added );

							$combined_products_panel.triggerHandler( 'wc-combined-products-changed' );

							$added.find( '.woocommerce-help-tip' ).tipTip( {
								'attribute' : 'data-tip',
								'fadeIn' : 50,
								'fadeOut' : 50,
								'delay' : 200
							} );

							$added.wc_combos_select2();

							$combined_products_panel.trigger( 'wc-combos-added-combined-product' );

						} else if ( response.message !== '' ) {
							window.alert( response.message );
						}

						// Open and close to resolve "sticky" modal issue.
						if ( 'yes' === wc_combos_admin_params.is_wc_version_gte_3_2 ) {
							$combined_product_search.selectWoo( 'open' );
							$combined_product_search.selectWoo( 'close' );
						} else {
							$combined_product_search.select2( 'open' );
							$combined_product_search.select2( 'close' );
						}

						$combined_products_panel.unblock();

					} );

				}, 250 );

				return false;

			} );

		$combined_products_wrapper

			// Expand all.
			.on( 'click', '.expand_all', function() {

				if ( $( this ).hasClass( 'disabled' ) ) {
					return false;
				}

				$.each( combined_product_objects, function( index, combined_product_object ) {
					combined_product_object.$element.addClass( 'open' ).removeClass( 'closed' );
				} );

				return false;
			} )

			// Close all.
			.on( 'click', '.close_all', function() {

				if ( $( this ).hasClass( 'disabled' ) ) {
					return false;
				}

				$.each( combined_product_objects, function( index, combined_product_object ) {
					combined_product_object.$element.addClass( 'closed' ).removeClass( 'open' );
				} );

				return false;
			} );

		$combined_products_panel

			// Update menu order and toolbar states.
			.on( 'wc-combined-products-changed', function() {

				$combined_products = $( '.wc-combined-item', $combined_products_container );

				$combined_products.each( function( index, el ) {
					$( '.item_menu_order', el ).val( index );
				} );

				update_toolbar_state();

			} )

			// Remove onboarding elements when adding combined product.
			.one( 'wc-combos-added-combined-product', function() {
				$combined_products_wrapper.removeClass( 'wc-combo-metaboxes-wrapper--boarding' );
			} );

		$combined_products_container

			// Remove Item.
			.on( 'click', 'a.remove_row', function( e ) {

				var $el   = $( this ).closest( '.wc-combined-item' ),
					el_id = $el.data( 'combined_item_id' );

				$el.find( '*' ).off();
				$el.remove();

				delete combined_product_objects[ el_id ];

				$combined_products_panel.triggerHandler( 'wc-combined-products-changed' );

				e.preventDefault();

			} )

			// Priced individually.
			.on( 'change', '.priced_individually input', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.priced_individually_input_changed();
			} )

			// Variation filtering options.
			.on( 'change', '.override_variations input', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.override_variations_input_changed();
			} )

			// Selection defaults options.
			.on( 'change', '.override_default_variation_attributes input', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.override_defaults_input_changed();
			} )

			// Custom title options.
			.on( 'change', '.override_title input', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.override_title_input_changed();
			} )

			// Custom description options.
			.on( 'change', '.override_description input', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.override_description_input_changed();
			} )

			// Visibility.
			.on( 'change', 'input.visibility_product', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.visibility_product_input_changed();
				combined_product.toggled_visibility( 'product' );
			} )

			.on( 'change', 'input.visibility_cart', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.toggled_visibility( 'cart' );
			} )

			.on( 'change', 'input.visibility_order', function() {

				var $el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.toggled_visibility( 'order' );
			} )

			// Sections.
			.on( 'click', '.subsubsub a', function( event ) {

				var $section_link   = $( this ),
					$el             = $( this ).closest( '.wc-combined-item' ),
					el_id           = $el.data( 'combined_item_id' ),
					combined_product = combined_product_objects[ el_id ];

				combined_product.section_changed( $section_link );

				event.preventDefault();

			} );

	}

	function init_combined_products() {

		// Create objects.
		$combined_products.each( function( index ) {

			var $el   = $( this ),
				el_id = 'combined_item_' + index;

			$el.data( 'combined_item_id', el_id );
			combined_product_objects[ el_id ] = new Combined_Item( $el );
		} );

		// Item ordering.
		$combined_products_container.sortable( {
			items: '.wc-combined-item',
			cursor: 'move',
			axis: 'y',
			handle: '.sort-item',
			scrollSensitivity: 40,
			forcePlaceholderSize: true,
			helper: 'clone',
			opacity: 0.65,
			placeholder: 'wc-metabox-sortable-placeholder',
			start:function( event, ui ){
				ui.item.css( 'background-color','#f6f6f6' );
			},
			stop:function( event, ui ){
				ui.item.removeAttr( 'style' );
				$combined_products_panel.triggerHandler( 'wc-combined-products-changed' );
			}
		} );

		// Expand/collapse toolbar state.
		update_toolbar_state();
	}

	function init_nux() {

		if ( 'yes' === wc_combos_admin_params.is_first_combo ) {
			$product_type_select.val( 'combo' ).trigger( 'change' ).trigger( 'focus' );
			setTimeout( function() {
				$( '.combined_products_tab a' ).trigger( 'click' );
			}, 500 );
		}
	}

	function update_toolbar_state() {

		if ( $combined_products.length > 0 ) {
			$combined_products_wrapper.removeClass( 'no-items' );
			$combined_products_toolbar.removeClass( 'disabled' );
		} else {
			$combined_products_wrapper.addClass( 'no-items' );
			$combined_products_toolbar.addClass( 'disabled' );
		}
	}

	function init_expanding_button() {

		var focus_timer,
			$button = $combined_products_panel.find( '.sw-expanding-button' ),
			$body   = $( document.body );

		$button.sw_select2();

		$button.on( 'click', function( e ) {

			e.stopPropagation();

			clearTimeout( focus_timer );

			var $this  = $( this ),
				$input = $this.find( '.select2-search__field' );

			$this.addClass( 'sw-expanding-button--open' );

			focus_timer = setTimeout( function() {
				$input.trigger( 'focus' );
			}, 700 );

			$combined_product_search.one( 'change', function() {
				$this.removeClass( 'sw-expanding-button--open' );
			} );

		} );

		$body.on( 'click', '.select2-container', function( e ) {
			e.stopPropagation();
		} );

		$body.on( 'click', function() {
			$button.removeClass( 'sw-expanding-button--open' );
		} );
	}

	function init_combo_shipping() {

		var $shipping_data_container = $combined_products_panel.parent().find( '#shipping_product_data' ),
			$virtual_checkbox        = $( 'input#_virtual' ),
			$combo_type_container   = $shipping_data_container.find( '.options_group.combo_type' ),
			$combo_type_options     = $combo_type_container.find( '.combo_type_options li' ),
			virtual_state            = $( 'input#_virtual:checked' ).length ? true : false;

		// Move Combo type options group first.
		$combo_type_container.detach().prependTo( $shipping_data_container );

		// Move "Assembled Weight" to the Weight field.
		$shipping_data_container.find( '.form-field._weight_field' ).after( $combo_type_container.find( '.form-field.combo_aggregate_weight_field' ) );

		// Save virtual state.
		$virtual_checkbox.on( 'change', function() {
			if ( 'combo' !== $product_type_select.val() && 'composite' !== $product_type_select.val() ) {
				virtual_state = $( this ).prop( 'checked' ) ? true : false;
			}
		} );

		$( 'body' ).on( 'woocommerce-product-type-change', function( event, select_val ) {

			if ( 'combo' !== select_val ) {
				// Restore virtual state.
				if ( 'simple' === select_val ) {
					$virtual_checkbox.prop( 'checked', virtual_state ).trigger( 'change' );
				}
			}

		} );

		// Toggle container shipping class.
		// Container classes are removed conditionaly using inline JS. @see WC_LafkaCombos_Meta_Box_Product_Data::js_handle_container_classes()
		$combo_type_options.on( 'click', function() {

			var $option = $( this ),
				$input  = $option.find( 'input' ),
				value   = $input.prop( 'checked', 'checked' ).val();

			// Highlight selected.
			$combo_type_options.removeClass( 'selected' );
			$option.addClass( 'selected' );

			if ( 'assembled' === value ) {
				$shipping_data_container.removeClass( 'combo_unassembled' );
				$combined_products_panel.removeClass( 'combo_unassembled' );
			} else if ( 'unassembled' === value ) {
				$shipping_data_container.addClass( 'combo_unassembled' );
				$combined_products_panel.addClass( 'combo_unassembled' );
			}

		} );
	}

} );
