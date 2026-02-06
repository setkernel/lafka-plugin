<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composite Products Compatibility.
 *
 * @version  6.5.0
 */
class WC_LafkaCombos_CP_Compatibility {

	/**
	 * Context-setting Component.
	 *
	 * @var WC_CP_Component
	 */
	private static $current_component = false;

	/**
	 * Add hooks.
	 */
	public static function init() {

		/*
		 * Form Data.
		 */

		add_filter( 'woocommerce_rebuild_posted_composite_form_data', array( __CLASS__, 'rebuild_composited_combo_form_data' ), 10, 3 );
		add_filter( 'woocommerce_posted_composite_configuration', array( __CLASS__, 'get_composited_combo_configuration' ), 10, 3 );

		/*
		 * Prices.
		 */

		add_filter( 'woocommerce_get_composited_product_price', array( __CLASS__, 'composited_combo_price' ), 10, 3 );

		// Create composite context for combined cart items - 'filters' method implementation.
		if ( 'filters' === WC_LafkaCombos_Product_Prices::get_combined_cart_item_discount_method() ) {
			add_filter( 'woocommerce_combined_cart_item', array( __CLASS__, 'combined_cart_item_reference' ) );
		}

		add_filter( 'woocommerce_combos_update_price_meta', array( __CLASS__, 'combos_update_price_meta' ), 10, 2 );
		add_filter( 'woocommerce_combined_item_discount', array( __CLASS__, 'combined_item_discount' ), 10, 3 );

		/*
		 * Shipping.
		 */

		// Inheritance.
		add_filter( 'woocommerce_combined_item_is_priced_individually', array( __CLASS__, 'combined_item_is_priced_individually' ), 10, 2 );
		add_filter( 'woocommerce_combo_contains_shipped_items', array( __CLASS__, 'combo_contains_shipped_items' ), 10, 2 );
		add_filter( 'woocommerce_combined_item_is_shipped_individually', array( __CLASS__, 'combined_item_is_shipped_individually' ), 10, 2 );
		add_filter( 'woocommerce_combined_item_has_combined_weight', array( __CLASS__, 'combined_item_has_combined_weight' ), 10, 4 );

		// Value & weight aggregation in packages.
		add_filter( 'woocommerce_combo_container_cart_item', array( __CLASS__, 'composited_combo_container_cart_item' ), 10, 3 );
		add_filter( 'woocommerce_composited_package_item', array( __CLASS__, 'composited_combo_container_package_item' ), 10, 3 );

		/*
		 * Templates.
		 */

		// Composited Combo template.
		add_action( 'woocommerce_composited_product_combo', array( __CLASS__, 'composited_product_combo' ), 10 );

		/*
		 * Cart and Orders.
		 */

		// Validate combo type component selections.
		add_action( 'woocommerce_composite_component_validation_add_to_cart', array( __CLASS__, 'validate_component_configuration' ), 10, 8 );
		add_action( 'woocommerce_composite_component_validation_add_to_order', array( __CLASS__, 'validate_component_configuration' ), 10, 8 );

		// Apply component prefix to combo input fields.
		add_filter( 'woocommerce_product_combo_field_prefix', array( __CLASS__, 'combo_field_prefix' ), 10, 2 );

		// Hook into composited product add-to-cart action to add combined items since 'woocommerce-add-to-cart' action cannot be used recursively.
		add_action( 'woocommerce_composited_add_to_cart', array( __CLASS__, 'add_combo_to_cart' ), 10, 6 );

		// Link combined cart/order items with composite.
		add_filter( 'woocommerce_cart_item_is_child_of_composite', array( __CLASS__, 'combined_cart_item_is_child_of_composite' ), 10, 5 );
		add_filter( 'woocommerce_order_item_is_child_of_composite', array( __CLASS__, 'combined_order_item_is_child_of_composite' ), 10, 4 );

		// Tweak the appearance of combo container items in various templates.
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'composited_combo_in_cart_item_title' ), 9, 3 );
		add_filter( 'woocommerce_composite_container_cart_item_data_value', array( __CLASS__, 'composited_combo_cart_item_data_value' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'composited_combo_in_cart_item_quantity' ), 11, 2 );
		add_filter( 'woocommerce_composited_cart_item_quantity_html', array( __CLASS__, 'composited_combo_checkout_item_quantity' ), 10, 2 );
		add_filter( 'woocommerce_order_item_visible', array( __CLASS__, 'composited_combo_order_item_visible' ), 10, 2 );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'composited_combo_order_table_item_title' ), 9, 2 );
		add_filter( 'woocommerce_component_order_item_meta_description', array( __CLASS__, 'composited_combo_order_item_description' ), 10, 3 );
		add_filter( 'woocommerce_composited_order_item_quantity_html', array( __CLASS__, 'composited_combo_order_table_item_quantity' ), 11, 2 );

		// Disable edit-in-cart feature if part of a composite.
		add_filter( 'woocommerce_combo_is_editable_in_cart', array( __CLASS__, 'composited_combo_not_editable_in_cart' ), 10, 3 );

		// Use custom callback to add combos to orders in 'WC_CP_Order::add_composite_to_order'.
		add_filter( 'woocommerce_add_component_to_order_callback', array( __CLASS__, 'add_composited_combo_to_order_callback' ), 10, 6 );
	}

	/*
	|--------------------------------------------------------------------------
	| Permalink Args.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add form data for composited combos to support cart-item editing and order-item editing in CP.
	 *
	 * @since  5.8.0
	 *
	 * @param  array  $form_data
	 * @param  array  $configuration
	 * @return array
	 *
	 */
	public static function rebuild_composited_combo_form_data( $form_data, $configuration ) {

		if ( ! empty( $configuration ) && is_array( $configuration ) ) {
			foreach ( $configuration as $component_id => $component_configuration ) {

				if ( isset( $component_configuration[ 'type' ] ) && $component_configuration[ 'type' ] === 'combo' && ! empty( $component_configuration[ 'stamp' ] ) && is_array( $component_configuration[ 'stamp' ] ) ) {

					$combo_args = WC_LafkaCombos()->cart->rebuild_posted_combo_form_data( $component_configuration[ 'stamp' ] );

					foreach ( $combo_args as $key => $value ) {
						$form_data[ 'component_' . $component_id . '_' . $key ] = $value;
					}
				}
			}
		}

		return $form_data;
	}

	/**
	 * Get posted data for composited combos.
	 *
	 * @since  5.8.0
	 *
	 * @param  array                 $configuration
	 * @param  WC_Product_Composite  $composite
	 * @return array
	 *
	 */
	public static function get_composited_combo_configuration( $configuration, $composite ) {

		if ( empty( $configuration ) || ! is_array( $configuration ) ) {
			return $configuration;
		}

		foreach ( $configuration as $component_id => $component_configuration ) {

			if ( empty( $component_configuration[ 'product_id' ] ) ) {
				continue;
			}

			$component_option = $composite->get_component_option( $component_id, $component_configuration[ 'product_id' ] );

			if ( ! $component_option ) {
				continue;
			}

			$composited_product = $component_option->get_product();

			if ( ! $composited_product->is_type( 'combo' ) ) {
				continue;
			}

			WC_LafkaCombos_Compatibility::$combo_prefix = $component_id;

			$configuration[ $component_id ][ 'stamp' ] = WC_LafkaCombos()->cart->get_posted_combo_configuration( $composited_product );

			if ( doing_filter( 'woocommerce_add_cart_item_data' ) ) {
				foreach ( $configuration[ $component_id ][ 'stamp' ] as $combined_item_id => $combined_item_configuration ) {
					$configuration[ $component_id ][ 'stamp' ][ $combined_item_id ] = apply_filters( 'woocommerce_combined_item_cart_item_identifier', $combined_item_configuration, $combined_item_id, $composited_product->get_id() );
				}
			}

			WC_LafkaCombos_Compatibility::$combo_prefix = '';
		}

		return $configuration;
	}

	/*
	|--------------------------------------------------------------------------
	| Prices.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Composited combo price.
	 *
	 * @param  double         $price
	 * @param  array          $args
	 * @param  WC_CP_Product  $composited_product
	 * @return double
	 */
	public static function composited_combo_price( $price, $args, $composited_product ) {

		$product = $composited_product->get_product();

		if ( 'combo' === $product->get_type() ) {

			$composited_product->add_filters();

			$price = $product->calculate_price( $args );

			if ( '' === $price ) {
				if ( $product->contains( 'priced_individually' ) && isset( $args[ 'min_or_max' ] ) && 'max' === $args[ 'min_or_max' ] && INF === $product->get_max_raw_price() ) {
					$price = INF;
				} else {
					$price = 0.0;
				}
			}

			$composited_product->remove_filters();
		}

		return $price;
	}

	/**
	 * Create component reference to aggregate discount of component into combined item - 'filters' method implementation.
	 *
	 * @see combined_item_discount
	 *
	 * @param  string  $cart_item
	 * @return void
	 */
	public static function combined_cart_item_reference( $cart_item ) {

		$combined_cart_item_ref = WC_LafkaCombos_Cart::get_product_cart_prop( $cart_item[ 'data' ], 'combined_cart_item' );

		if ( $combined_cart_item_ref ) {

			if ( $combo_container_item = wc_pc_get_combined_cart_item_container( $cart_item ) ) {

				if ( $composite_container_item = wc_cp_get_composited_cart_item_container( $combo_container_item ) ) {

					$combo           = $combo_container_item[ 'data' ];
					$composite        = $composite_container_item[ 'data' ];
					$component_id     = $combo_container_item[ 'composite_item' ];
					$component_option = $composite->get_component_option( $component_id, $combo->get_id() );

					if ( $component_option ) {
						$combined_cart_item_ref->composited_cart_item = $component_option;
					}
				}
			}
		}

		return $cart_item;
	}

	/**
	 * Filters 'woocommerce_combined_item_discount' to include component + combined item discounts.
	 *
	 * @param  mixed            $combined_discount
	 * @param  WC_Combined_Item  $combined_item
	 * @param  string           $context
	 * @return mixed
	 */
	public static function combined_item_discount( $combined_discount, $combined_item, $context ) {

		if ( 'cart' !== $context ) {
			return $combined_discount;
		}

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combined_item->composited_cart_item ) ) {
			$component_option = $combined_item->composited_cart_item;
		} elseif ( $combined_item->get_combo() && isset( $combined_item->get_combo()->composited_cart_item ) ) {
			$component_option = $combined_item->get_combo()->composited_cart_item;
		}

		if ( $component_option && ( $component_option instanceof WC_CP_Product ) ) {

			$discount = $component_option->get_discount();

			if ( ! $combined_discount ) {
				return $discount;
			}

			// If discount is allowed on the component sale price use both the component + combined item discount. Else, use the component discount.
			if ( $component_option->is_discount_allowed_on_sale_price() ) {

				// If component discount is set use both component + combined item discount. Else, use only the combined item discount.
				if ( $discount ) {
					$combined_discount = $discount + $combined_discount - ( $combined_discount * $discount ) / 100;
				}

			} else {

				if ( $discount ) {
					$combined_discount = $discount;
				}
			}
		}

		return $combined_discount;
	}

	/**
	 * Component discounts should not trigger combo price updates.
	 *
	 * @param  boolean            $is
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function combos_update_price_meta( $update, $combo ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combo->composited_cart_item ) ) {
			$component_option = $combo->composited_cart_item;
		}

		if ( $component_option ) {
			$update = false;
		}

		return $update;
	}

	/**
	 * If a component is not priced individually, this should force combined items to return a zero price.
	 *
	 * @since  6.2.0
	 *
	 * @param  boolean          $is
	 * @param  WC_Combined_Item  $combined_item
	 * @return boolean
	 */
	public static function combined_item_is_priced_individually( $is_priced_individually, $combined_item ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combined_item->composited_cart_item ) ) {
			$component_option = $combined_item->composited_cart_item;
		} elseif ( $combined_item->get_combo() && isset( $combined_item->get_combo()->composited_cart_item ) ) {
			$component_option = $combined_item->get_combo()->composited_cart_item;
		}

		if ( $component_option ) {
			if ( ! $component_option->is_priced_individually() ) {
				$is_priced_individually = false;
			}
		}

		return $is_priced_individually;
	}

	/**
	 * If a component is not priced individually, this should force combined items to return a zero price.
	 *
	 * @since  6.2.0
	 *
	 * @param  boolean            $contains
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function combo_contains_priced_items( $contains, $combo ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combo->composited_cart_item ) ) {
			$component_option = $combo->composited_cart_item;
		}

		if ( $component_option ) {
			if ( ! $component_option->is_priced_individually() ) {
				$contains = false;
			}
		}

		return $contains;
	}

	/**
	 * If a component is not shipped individually, this should force combined items to comply.
	 *
	 * @since  6.2.0
	 *
	 * @param  boolean          $is
	 * @param  WC_Combined_Item  $combined_item
	 * @return boolean
	 */
	public static function combined_item_is_shipped_individually( $is_shipped_individually, $combined_item ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combined_item->composited_cart_item ) ) {
			$component_option = $combined_item->composited_cart_item;
		} elseif ( $combined_item->get_combo() && isset( $combined_item->get_combo()->composited_cart_item ) ) {
			$component_option = $combined_item->get_combo()->composited_cart_item;
		}

		if ( $component_option ) {
			if ( ! $component_option->is_shipped_individually() ) {
				$is_shipped_individually = false;
			}
		}

		return $is_shipped_individually;
	}

	/**
	 * If a component is not shipped individually, this should force combined items to comply.
	 *
	 * @since  6.2.0
	 *
	 * @param  boolean            $has
	 * @param  WC_Product         $combined_product
	 * @param  int                $combined_item_id
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function combined_item_has_combined_weight( $has, $combined_cart_item, $combined_item_id, $combo ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combo->composited_cart_item ) ) {
			$component_option = $combo->composited_cart_item;
		}

		if ( $component_option ) {
			if ( ! $component_option->is_shipped_individually() && ! $component_option->is_weight_aggregated() ) {
				$has = false;
			}
		}

		return $has;
	}

	/**
	 * If a component is not shipped individually, this should force combined items to comply.
	 *
	 * @since  6.2.0
	 *
	 * @param  boolean            $is
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function combo_contains_shipped_items( $contains, $combo ) {

		$component_option = false;

		if ( is_callable( array( 'WC_CP_Products', 'get_filtered_component_option' ) ) && WC_CP_Products::get_filtered_component_option() ) {
			$component_option = WC_CP_Products::get_filtered_component_option();
		} elseif ( isset( $combo->composited_cart_item ) ) {
			$component_option = $combo->composited_cart_item;
		}

		if ( $component_option ) {
			if ( ! $component_option->is_shipped_individually() ) {
				$contains = false;
			}
		}

		return $contains;
	}

	/*
	|--------------------------------------------------------------------------
	| Templates.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hook into 'woocommerce_composited_product_combo' to show combo type product content.
	 *
	 * @since  5.10.0
	 *
	 * @param  WC_CP_Product  $component_option
	 * @return void
	 */
	public static function composited_product_combo( $component_option ) {

		$product = $component_option->get_product();

		if ( $product->contains( 'subscriptions' ) ) {

			?><div class="woocommerce-error"><?php
				echo __( 'This item cannot be purchased at the moment.', 'lafka-plugin' );
			?></div><?php

			return false;
		}

		if ( class_exists( 'WC_CP_Admin_Ajax' ) && WC_CP_Admin_Ajax::is_composite_edit_request() ) {
			$product->set_layout( 'tabular' );
		}

		$product_id   = $product->get_id();
		$component    = $component_option->get_component();
		$component_id = $component_option->get_component_id();
		$composite    = $component_option->get_composite();
		$composite_id = $component_option->get_composite_id();

		WC_LafkaCombos_Compatibility::$compat_product = $product;
		WC_LafkaCombos_Compatibility::$combo_prefix  = $component_id;

		$quantity_min = $component_option->get_quantity_min();
		$quantity_max = $component_option->get_quantity_max( true );

		$form_classes = array();

		if ( ! $product->is_in_stock() ) {
			$form_classes[] = 'combo_out_of_stock';
		}

		if ( 'outofstock' === $product->get_combined_items_stock_status() ) {
			$form_classes[] = 'combo_insufficient_stock';
		}

		$form_data = $product->get_combo_form_data();

		wc_get_template( 'composited-product/combo-product.php', array(
			'product'            => $product,
			'quantity_min'       => $quantity_min,
			'quantity_max'       => $quantity_max,
			'combo_form_data'   => $form_data,
			'combined_items'      => $product->get_combined_items(),
			'component_id'       => $component_id,
			'composited_product' => $component_option,
			'composite_product'  => $composite,
			'classes'            => implode( ' ', $form_classes ),
			// Back-compat:
			'product_id'         => $product_id,
			'combo_price_data'  => $form_data,
		), false, WC_LafkaCombos()->plugin_path() . '/templates/' );

		WC_LafkaCombos_Compatibility::$compat_product = '';
		WC_LafkaCombos_Compatibility::$combo_prefix  = '';
	}

	/*
	|--------------------------------------------------------------------------
	| Cart and Orders.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hook into 'woocommerce_composite_component_add_to_cart_validation' to validate composited combos.
	 *
	 * @param  WC_CP_Component  $component
	 * @param  array            $component_validation_data
	 * @param  int              $composite_quantity
	 * @param  array            $configuration
	 * @param  string           $context
	 * @return void
	 */
	public static function validate_component_configuration( $component, $component_validation_data, $composite_quantity, $configuration, $context ) {

		$component_id       = $component->get_id();
		$component_option   = $component->get_option( $component_validation_data[ 'product_id' ] );

		if ( ! $component_option ) {
			return;
		}

		$composited_product = $component_option->get_product();

		if ( ! $composited_product || ! $composited_product->is_type( 'combo' ) ) {
			return;
		}

		// Disallow combos with subscriptions.
		if ( $composited_product->contains( 'subscriptions' ) ) {

			$reason = sprintf( __( '&quot;%s&quot; cannot be purchased.', 'lafka-plugin' ), $composited_product->get_title() );

			if ( 'add-to-cart' === $context ) {
				$notice = sprintf( __( '&quot;%1$s&quot; cannot be added to your cart. %2$s', 'lafka-plugin' ), $component->get_composite()->get_title(), $reason );
			} elseif ( 'cart' === $context ) {
				$notice = sprintf( __( '&quot;%1$s&quot; cannot be purchased. %2$s', 'lafka-plugin' ), $component->get_composite()->get_title(), $reason );
			} else {
				$notice = $reason;
			}

			throw new Exception( $notice );
		}

		if ( ! isset( $component_validation_data[ 'quantity' ] ) || ! $component_validation_data[ 'quantity' ] > 0 ) {
			return;
		}

		$combo_configuration = array();

		WC_LafkaCombos_Compatibility::$combo_prefix = $component_id;

		if ( isset( $configuration[ $component_id ][ 'stamp' ] ) ) {
			$combo_configuration = $configuration[ $component_id ][ 'stamp' ];
		} else {
			$combo_configuration = WC_LafkaCombos()->cart->get_posted_combo_configuration( $composited_product );
		}

		add_filter( 'woocommerce_add_error', array( __CLASS__, 'component_combo_error_message_context' ) );
		self::$current_component = $component;

		$is_valid = WC_LafkaCombos()->cart->validate_combo_configuration( $composited_product, $component_validation_data[ 'quantity' ], $combo_configuration, $context );

		remove_filter( 'woocommerce_add_error', array( __CLASS__, 'component_combo_error_message_context' ) );
		self::$current_component = false;

		WC_LafkaCombos_Compatibility::$combo_prefix = '';

		if ( ! $is_valid ) {
			throw new Exception();
		}
	}

	/**
	 * Sets a prefix for unique combos.
	 *
	 * @param  string  $prefix
	 * @param  int     $product_id
	 * @return string
	 */
	public static function combo_field_prefix( $prefix, $product_id ) {

		if ( ! empty( WC_LafkaCombos_Compatibility::$combo_prefix ) ) {
			$prefix = 'component_' . WC_LafkaCombos_Compatibility::$combo_prefix . '_';
		}

		return $prefix;
	}

	/**
	 * Hook into 'woocommerce_composited_add_to_cart' to trigger 'WC_LafkaCombos()->cart->combo_add_to_cart()'.
	 *
	 * @param  string  $cart_item_key
	 * @param  int     $product_id
	 * @param  int     $quantity
	 * @param  int     $variation_id
	 * @param  array   $variation
	 * @param  array   $cart_item_data
	 */
	public static function add_combo_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		WC_LafkaCombos()->cart->combo_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data );
	}

	/**
	 * Used to link combined cart items with the composite container product.
	 *
	 * @param  boolean  $is_child
	 * @param  string   $cart_item_key
	 * @param  array    $cart_item_data
	 * @param  string   $composite_key
	 * @param  array    $composite_data
	 * @return boolean
	 */
	public static function combined_cart_item_is_child_of_composite( $is_child, $cart_item_key, $cart_item_data, $composite_key, $composite_data ) {

		if ( $parent = wc_pc_get_combined_cart_item_container( $cart_item_data ) ) {
			if ( isset( $parent[ 'composite_parent' ] ) && $parent[ 'composite_parent' ] === $composite_key ) {
				$is_child = true;
			}
		}

		return $is_child;
	}

	/**
	 * Used to link combined order items with the composite container product.
	 *
	 * @param  boolean   $is_child
	 * @param  array     $order_item
	 * @param  array     $composite_item
	 * @param  WC_Order  $order
	 * @return boolean
	 */
	public static function combined_order_item_is_child_of_composite( $is_child, $order_item, $composite_item, $order ) {

		if ( $parent = wc_pc_get_combined_order_item_container( $order_item, $order ) ) {
			if ( isset( $parent[ 'composite_parent' ] ) && $parent[ 'composite_parent' ] === $composite_item[ 'composite_cart_key' ] ) {
				$is_child = true;
			}
		}

		return $is_child;
	}

	/**
	 * Edit composited combo container cart title.
	 *
	 * @param  string  $content
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return string
	 */
	public static function composited_combo_in_cart_item_title( $content, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) && wc_cp_is_composited_cart_item( $cart_item ) ) {

			$hide_title = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'component_multiselect' );

			/**
			 * 'woocommerce_composited_combo_container_cart_item_hide_title' filter.
			 *
			 * @param  boolean  $hide_title
			 * @param  array    $cart_item
			 * @param  string   $cart_item_key
			 */
			$hide_title = apply_filters( 'woocommerce_composited_combo_container_cart_item_hide_title', $hide_title, $cart_item, $cart_item_key );

			if ( $hide_title ) {

				$combined_cart_items = wc_pc_get_combined_cart_items( $cart_item );

				if ( empty( $combined_cart_items ) ) {
					$content = __( 'No selection', 'lafka-plugin' );
				} else {
					$content = '';
				}
			}
		}

		return $content;
	}

	public static function composited_combo_cart_item_data_value( $title, $cart_item, $cart_item_key ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) && wc_cp_is_composited_cart_item( $cart_item ) ) {

			$hide_title = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'component_multiselect' );

			/**
			 * 'woocommerce_composited_combo_container_cart_item_hide_title' filter.
			 *
			 * @param  boolean  $hide_title
			 * @param  array    $cart_item
			 * @param  string   $cart_item_key
			 */
			$hide_title = apply_filters( 'woocommerce_composited_combo_container_cart_item_hide_title', $hide_title, $cart_item, $cart_item_key );

			if ( $hide_title ) {

				$combined_cart_items = wc_pc_get_combined_cart_items( $cart_item );

				if ( empty( $combined_cart_items ) ) {

					$title = __( 'No selection', 'lafka-plugin' );

				} else {

					$title       = '';
					$combo_meta = WC_LafkaCombos()->display->get_combo_container_cart_item_data( $cart_item );

					foreach ( $combo_meta as $meta ) {
						$title .= $meta[ 'value' ] . '<br/>';
					}

				}
			}
		}

		return $title;
	}

	/**
	 * Aggregate value and weight of combined items in shipping packages when an unassembled combo is composited.
	 *
	 * @param  array                 $cart_item
	 * @param  WC_Product_Composite  $container_cart_item_key
	 * @return array
	 */
	public static function composited_combo_container_cart_item( $cart_item, $combo ) {

		if ( $container_cart_item = wc_cp_get_composited_cart_item_container( $cart_item ) ) {

			$component_id     = $cart_item[ 'composite_item' ];
			$component_option = $container_cart_item[ 'data' ]->get_component_option( $component_id, $cart_item[ 'product_id' ] );

			if ( ! $component_option ) {
				return $cart_item;
			}

			$cart_item[ 'data' ]->composited_value = is_callable( array( 'WC_CP_Products', 'get_composited_cart_item_discount_method' ) ) && 'props' === WC_CP_Products::get_composited_cart_item_discount_method() ? $cart_item[ 'data' ]->get_price( 'edit' ) : $component_option->get_raw_price( $cart_item[ 'data' ], 'cart' );

			// If the combo doesn't need shipping at this point, it means it's unassembled.
			if ( false === $cart_item[ 'data' ]->needs_shipping() ) {
				if ( false === $component_option->is_shipped_individually() ) {
					$cart_item[ 'data' ]->composited_weight = 0.0;
					$cart_item[ 'data' ]->set_aggregate_weight( 'yes' );
				}
			}
		}

		return $cart_item;
	}

	/**
	 * Aggregate value and weight of combined items in shipping packages when a combo is composited in an assembled composite.
	 *
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @param  string  $container_cart_item_key
	 * @return array
	 */
	public static function composited_combo_container_package_item( $cart_item, $cart_item_key, $container_cart_item_key ) {

		// If this isn't an assembled Composite, get out.
		if ( ! isset( $cart_item[ 'data' ]->composited_value ) ) {
			return $cart_item;
		}

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) ) {

			$composited_combo_value  = isset( $cart_item[ 'data' ]->composited_value ) ? $cart_item[ 'data' ]->composited_value : 0.0;
			$composited_combo_weight = isset( $cart_item[ 'data' ]->composited_weight ) ? $cart_item[ 'data' ]->composited_weight : 0.0;

			$combo     = clone $cart_item[ 'data' ];
			$combo_qty = $cart_item[ 'quantity' ];

			// Aggregate weights and prices.

			$combined_weight = 0.0;
			$combined_value  = 0.0;
			$combo_totals  = array(
				'line_subtotal'     => $cart_item[ 'line_subtotal' ],
				'line_total'        => $cart_item[ 'line_total' ],
				'line_subtotal_tax' => $cart_item[ 'line_subtotal_tax' ],
				'line_tax'          => $cart_item[ 'line_tax' ],
				'line_tax_data'     => $cart_item[ 'line_tax_data' ]
			);

			foreach ( wc_pc_get_combined_cart_items( $cart_item, WC()->cart->cart_contents, true ) as $child_item_key ) {

				$child_cart_item_data   = WC()->cart->cart_contents[ $child_item_key ];
				$combined_product        = $child_cart_item_data[ 'data' ];
				$combined_product_qty    = $child_cart_item_data[ 'quantity' ];
				$combined_product_value  = WC_LafkaCombos_Cart::has_product_cart_prop( $combined_product, 'combined_value' ) ? WC_LafkaCombos_Cart::get_product_cart_prop( $combined_product, 'combined_value' ) : 0.0;
				$combined_product_weight = WC_LafkaCombos_Cart::has_product_cart_prop( $combined_product, 'combined_weight' ) ? WC_LafkaCombos_Cart::get_product_cart_prop( $combined_product, 'combined_weight' ) : 0.0;

				// Aggregate price.
				if ( $combined_product_value ) {

					$combined_value += $combined_product_value * $combined_product_qty;

					$combo_totals[ 'line_subtotal' ]     += $child_cart_item_data[ 'line_subtotal' ];
					$combo_totals[ 'line_total' ]        += $child_cart_item_data[ 'line_total' ];
					$combo_totals[ 'line_subtotal_tax' ] += $child_cart_item_data[ 'line_subtotal_tax' ];
					$combo_totals[ 'line_tax' ]          += $child_cart_item_data[ 'line_tax' ];

					$child_item_line_tax_data = $child_cart_item_data[ 'line_tax_data' ];

					$combo_totals[ 'line_tax_data' ][ 'total' ]    = array_merge( $combo_totals[ 'line_tax_data' ][ 'total' ], $child_item_line_tax_data[ 'total' ] );
					$combo_totals[ 'line_tax_data' ][ 'subtotal' ] = array_merge( $combo_totals[ 'line_tax_data' ][ 'subtotal' ], $child_item_line_tax_data[ 'subtotal' ] );
				}

				// Aggregate weight.
				if ( $combined_product_weight ) {
					$combined_weight += $combined_product_weight * $combined_product_qty;
				}
			}

			$cart_item = array_merge( $cart_item, $combo_totals );

			$combo->composited_value  = (float) $composited_combo_value + $combined_value / $combo_qty;
			$combo->composited_weight = (float) $composited_combo_weight + $combined_weight / $combo_qty;

			$cart_item[ 'data' ] = $combo;
		}

		return $cart_item;
	}

	/**
	 * Combos are not directly editable in cart if part of a composite.
	 * They inherit the setting of their container and can only be edited within that scope of their container - @see 'composited_combo_permalink_args()'.
	 *
	 * @param  boolean            $editable
	 * @param  WC_Product_Combo  $combo
	 * @param  array              $cart_item
	 * @return boolean
	 */
	public static function composited_combo_not_editable_in_cart( $editable, $combo, $cart_item ) {

		if ( is_array( $cart_item ) && wc_cp_is_composited_cart_item( $cart_item ) ) {
			$editable = false;
		}

		return $editable;
	}

	/**
	 * Add some contextual info to combo validation messages.
	 *
	 * @param  string $message
	 * @return string
	 */
	public static function component_combo_error_message_context( $message ) {

		if ( false !== self::$current_component ) {
			$message = sprintf( __( 'Please check your &quot;%1$s&quot; configuration: %2$s', 'lafka-plugin' ), self::$current_component->get_title( true ), $message );
		}

		return $message;
	}

	/**
	 * Edit composited combo container cart qty.
	 *
	 * @param  int     $quantity
	 * @param  string  $cart_item_key
	 * @return int
	 */
	public static function composited_combo_in_cart_item_quantity( $quantity, $cart_item_key ) {

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {

			$cart_item = WC()->cart->cart_contents[ $cart_item_key ];

			if ( wc_pc_is_combo_container_cart_item( $cart_item ) && wc_cp_is_composited_cart_item( $cart_item ) ) {

				$hide_qty = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'component_multiselect' );

				/**
				 * 'woocommerce_composited_combo_container_cart_item_hide_quantity' filter.
				 *
				 * @param  boolean  $hide_qty
				 * @param  array    $cart_item
				 * @param  string   $cart_item_key
				 */
				if ( apply_filters( 'woocommerce_composited_combo_container_cart_item_hide_quantity', $hide_qty, $cart_item, $cart_item_key ) ) {
					$quantity = '';
				}
			}
		}

		return $quantity;
	}

	/**
	 * Edit composited combo container cart qty.
	 *
	 * @param  int     $quantity
	 * @param  string  $cart_item
	 * @param  string  $cart_item_key
	 * @return int
	 */
	public static function composited_combo_checkout_item_quantity( $quantity, $cart_item, $cart_item_key = false ) {

		if ( wc_pc_is_combo_container_cart_item( $cart_item ) && wc_cp_is_composited_cart_item( $cart_item ) ) {

			$hide_qty = WC_Product_Combo::group_mode_has( $cart_item[ 'data' ]->get_group_mode(), 'component_multiselect' );

			/**
			 * 'woocommerce_composited_combo_container_cart_item_hide_quantity' filter.
			 *
			 * @param  boolean  $hide_qty
			 * @param  array    $cart_item
			 * @param  string   $cart_item_key
			 */
			if ( apply_filters( 'woocommerce_composited_combo_container_cart_item_hide_quantity', $hide_qty, $cart_item, $cart_item_key ) ) {
				$quantity = '';
			}
		}

		return $quantity;
	}

	/**
	 * Visibility of composited combo containers in orders.
	 * Hide containers without children and a zero price (all optional).
	 *
	 * @param  boolean  $visible
	 * @param  array    $order_item
	 * @return boolean
	 */
	public static function composited_combo_order_item_visible( $visible, $order_item ) {

		if ( wc_pc_is_combo_container_order_item( $order_item ) && wc_cp_maybe_is_composited_order_item( $order_item ) ) {

			if ( ! empty( $order_item[ 'combo_group_mode' ] ) && WC_Product_Combo::group_mode_has( $order_item[ 'combo_group_mode' ], 'component_multiselect' ) ) {

				$combined_items = maybe_unserialize( $order_item[ 'combined_items' ] );

				if ( empty( $combined_items ) && $order_item[ 'line_subtotal' ] == 0 ) {
					$visible = false;
				}
			}
		}

		return $visible;
	}

	/**
	 * Edit composited combo container order item title.
	 *
	 * @param  string  $content
	 * @param  array   $order_item
	 * @return string
	 */
	public static function composited_combo_order_table_item_title( $content, $order_item ) {

		if ( wc_pc_is_combo_container_order_item( $order_item ) && wc_cp_maybe_is_composited_order_item( $order_item ) ) {

			$hide_title = ! empty( $order_item[ 'combo_group_mode' ] ) && WC_Product_Combo::group_mode_has( $order_item[ 'combo_group_mode' ], 'component_multiselect' );

			/**
			 * 'woocommerce_composited_combo_container_order_item_hide_title' filter.
			 *
			 * @param  boolean  $hide_title
			 * @param  array    $order_item
			 */
			if ( apply_filters( 'woocommerce_composited_combo_container_order_item_hide_title', $hide_title, $order_item ) ) {
				$content = '';
			}
		}

		return $content;
	}

	/**
	 * Edit composited combo container order item qty.
	 *
	 * @param  string  $content
	 * @param  array   $order_item
	 * @return string
	 */
	public static function composited_combo_order_table_item_quantity( $quantity, $order_item ) {

		if ( wc_pc_is_combo_container_order_item( $order_item ) && wc_cp_maybe_is_composited_order_item( $order_item ) ) {

			$hide_qty = ! empty( $order_item[ 'combo_group_mode' ] ) && WC_Product_Combo::group_mode_has( $order_item[ 'combo_group_mode' ], 'component_multiselect' );

			/**
			 * 'woocommerce_composited_combo_container_order_item_hide_quantity' filter.
			 *
			 * @param  boolean  $hide_qty
			 * @param  array    $order_item
			 */
			if ( apply_filters( 'woocommerce_composited_combo_container_order_item_hide_quantity', $hide_qty, $order_item ) ) {
				$quantity = '';
			}
		}

		return $quantity;
	}

	/**
	 * Prevents combo container item meta from showing up.
	 *
	 * @since  5.8.0
	 *
	 * @param  string         $desc
	 * @param  array          $desc_array
	 * @param  WC_Order_Item  $item
	 * @return string
	 */
	public static function composited_combo_order_item_description( $desc, $desc_array, $order_item ) {

		$hide_title = ! empty( $order_item[ 'combo_group_mode' ] ) && WC_Product_Combo::group_mode_has( $order_item[ 'combo_group_mode' ], 'component_multiselect' );

		/**
		 * 'woocommerce_composited_combo_container_order_item_hide_title' filter.
		 *
		 * @param  boolean  $hide_title
		 * @param  array    $order_item
		 */
		if ( apply_filters( 'woocommerce_composited_combo_container_order_item_hide_title', $hide_title, $order_item ) ) {
			$desc = '';
		}

		return $desc;
	}

	/**
	 * Use custom callback to add combos to orders in 'WC_CP_Order::add_composite_to_order'.
	 *
	 * @since  5.8.0
	 *
	 * @param  array                 $callback
	 * @param  WC_CP_Component       $component
	 * @param  WC_Product_Composite  $composite
	 * @param  WC_Order              $order
	 * @param  integer               $quantity
	 * @param  array                 $args
	 */
	public static function add_composited_combo_to_order_callback( $callback, $component, $composite, $order, $quantity, $args ) {

		$component_configuration = $args[ 'configuration' ][ $component->get_id() ];

		if ( empty( $component_configuration[ 'stamp' ] ) ) {
			return $callback;
		}

		$component_option_id = $component_configuration[ 'product_id' ];
		$component_option    = $component->get_option( $component_option_id );

		if ( $component_option->get_product()->is_type( 'combo' ) ) {
			return array( __CLASS__, 'add_composited_combo_to_order' );
		}

		return $callback;
	}

	/**
	 * Custom callback for adding combos to orders in 'WC_CP_Order::add_composite_to_order'.
	 *
	 * @since  5.8.0
	 *
	 * @param  array                 $callback
	 * @param  WC_CP_Component       $component
	 * @param  WC_Product_Composite  $composite
	 * @param  WC_Order              $order
	 * @param  integer               $quantity
	 * @param  array                 $args
	 */
	public static function add_composited_combo_to_order( $component, $composite, $order, $quantity, $args ) {

		$component_configuration = $args[ 'configuration' ][ $component->get_id() ];
		$component_option_id     = $component_configuration[ 'product_id' ];
		$component_quantity      = isset( $component_configuration[ 'quantity' ] ) ? absint( $component_configuration[ 'quantity' ] ) : $component->get_quantity();
		$component_option        = $component->get_option( $component_option_id );

		$combo_args = array(
			'configuration' => $component_configuration[ 'stamp' ]
		);

		return WC_LafkaCombos()->order->add_combo_to_order( $component_option->get_product(), $order, $quantity = 1, $combo_args );
	}
}

WC_LafkaCombos_CP_Compatibility::init();
