<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-related functions and filters.
 *
 * @class    WC_LafkaCombos_MMI_Product
 * @version  6.6.0
 */
class WC_LafkaCombos_MMI_Product {

	/**
	 * Setup hooks.
	 */
	public static function init() {

		// Change combined item quantities.
		add_filter( 'woocommerce_combined_item_quantity', array( __CLASS__, 'combined_item_quantity' ), 10, 3 );
		add_filter( 'woocommerce_combined_item_quantity_max', array( __CLASS__, 'combined_item_quantity_max' ), 10, 3 );

		// When min/max qty constraints are present, require input.
		add_filter( 'woocommerce_combo_requires_input', array( __CLASS__, 'min_max_combo_requires_input' ), 10, 2 );

		// Make sure the combined items stock status takes the min combo size into account.
		add_filter( 'woocommerce_synced_combined_items_stock_status', array( __CLASS__, 'synced_combined_items_stock_status' ), 10, 2 );

		// Make sure the combo stock quantity the min combo size into account.
		add_filter( 'woocommerce_synced_combo_stock_quantity', array( __CLASS__, 'synced_combo_stock_quantity' ), 10, 2 );

		// Make sure the combo thinks it has 'mandatory' contents when the min combo size is > 0.
		add_filter( 'woocommerce_combos_synced_contents_data', array( __CLASS__, 'synced_contents_data' ), 10, 2 );
	}

	/*
	|--------------------------------------------------------------------------
	| Application layer functions.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Indicates if a combo has min/max size rules in effect.
	 *
	 * @since  6.5.0
	 *
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function has_limited_combo_size( $combo ) {

		$has_limited_combo_size = false;

		$min_qty = $combo->get_min_combo_size();
		$max_qty = $combo->get_max_combo_size();

		if ( $min_qty || $max_qty ) {

			if ( $min_qty === $max_qty ) {

				$combo_size = $min_qty;
				$total_items = 0;

				foreach ( $combo->get_combined_items() as $combined_item ) {

					$item_qty_min = $combined_item->get_quantity( 'min', array( 'check_optional' => true ) );
					$item_qty_max = $combined_item->get_quantity( 'max' );

					// If the combo has configurable quantities, then we have to assume that the combo size rule is in effect.
					if ( $item_qty_min !== $item_qty_max ) {
						$total_items = 0;
						break;
					}

					$total_items += $item_qty_min;
				}

				// If the combo doesn't have configurable quantities and its combo size rule can't be satisfied, activate it to make sure the store owner sees their error.
				if ( absint( $total_items ) !== absint( $combo_size ) ) {
					$has_limited_combo_size = true;
				}

			} else {
				$has_limited_combo_size = true;
			}
		}

		return $has_limited_combo_size;
	}

	/**
	 * Find the price-optimized set of combined item quantities that meet the min item count constraint while honoring the initial min/max item quantity constraints.
	 *
	 * @param  WC_Product  $product
	 * @return array
	 */
	public static function get_min_price_quantities( $combo ) {

		$result = WC_LafkaCombos_Helpers::cache_get( 'min_price_quantities_' . $combo->get_id() );

		if ( is_null( $result ) ) {

			$quantities = array(
				'min' => array(),
				'max' => array()
			);

			$pricing_data  = array();
			$combined_items = $combo->get_combined_items();

			if ( ! empty( $combined_items ) ) {
				foreach ( $combined_items as $combined_item ) {
					$pricing_data[ $combined_item->get_id() ][ 'price' ] = $combined_item->get_price();
					$quantities[ 'min' ][ $combined_item->get_id() ] = $combined_item->get_quantity( 'min', array( 'check_optional' => true ) );
					$quantities[ 'max' ][ $combined_item->get_id() ] = $combined_item->get_quantity( 'max' );
				}
			}

			if ( ! empty( $pricing_data ) ) {

				$min_qty = $combo->get_min_combo_size();;

				// Slots filled due to item min quantities.
				$filled_slots = 0;

				foreach ( $quantities[ 'min' ] as $item_min_qty ) {
					$filled_slots += $item_min_qty;
				}

				// Fill in the remaining box slots with cheapest combination of items.
				if ( $filled_slots < $min_qty ) {

					// Sort by cheapest.
					uasort( $pricing_data, array( __CLASS__, 'sort_by_price' ) );

					// Fill additional slots.
					foreach ( $pricing_data as $combined_item_id => $data ) {

						$slots_to_fill = $min_qty - $filled_slots;

						if ( $filled_slots >= $min_qty ) {
							break;
						}

						$combined_item = $combined_items[ $combined_item_id ];

						if ( false === $combined_item->is_purchasable() ) {
							continue;
						}

						$max_items_to_use = $quantities[ 'max' ][ $combined_item_id ];
						$min_items_to_use = $quantities[ 'min' ][ $combined_item_id ];

						$items_to_use = '' !== $max_items_to_use ? min( $max_items_to_use - $min_items_to_use, $slots_to_fill ) : $slots_to_fill;

						$filled_slots += $items_to_use;

						$quantities[ 'min' ][ $combined_item_id ] += $items_to_use;
					}
				}
			}

			$result = $quantities[ 'min' ];
			WC_LafkaCombos_Helpers::cache_set( 'min_price_quantities_' . $combo->get_id(), $result );
		}

		return $result;
	}

	/**
	 * Find the worst-price set of combined item quantities that meet the max item count constraint while honoring the initial min/max item quantity constraints.
	 *
	 * @param  WC_Product  $product
	 * @return array
	 */
	public static function get_max_price_quantities( $combo ) {

		$result = WC_LafkaCombos_Helpers::cache_get( 'max_price_quantities_' . $combo->get_id() );

		/*
		 * Max items count defined: Put the min quantities in the box, then keep adding items giving preference to the most expensive ones, while honoring their max quantity constraints.
		 */
		if ( is_null( $result ) ) {

			$quantities = array(
				'min' => array(),
				'max' => array()
			);

			$pricing_data  = array();
			$combined_items = $combo->get_combined_items();

			if ( ! empty( $combined_items ) ) {
				foreach ( $combined_items as $combined_item ) {
					$pricing_data[ $combined_item->get_id() ][ 'price' ] = $combined_item->get_price();
					$quantities[ 'min' ][ $combined_item->get_id() ]     = $combined_item->get_quantity( 'min', array( 'check_optional' => true ) );
					$quantities[ 'max' ][ $combined_item->get_id() ]     = $combined_item->get_quantity( 'max' );
				}
			}

			$max_qty = $combo->get_max_combo_size();

			if ( ! empty( $pricing_data ) ) {

				// Sort by most expensive.
				uasort( $pricing_data, array( __CLASS__, 'sort_by_price' ) );
				$reverse_pricing_data = array_reverse( $pricing_data, true );

				// Slots filled due to item min quantities.
				$filled_slots = 0;

				foreach ( $quantities[ 'min' ] as $item_min_qty ) {
					$filled_slots += $item_min_qty;
				}
			}

			// Fill in the remaining box slots with most expensive combination of items.
			if ( $filled_slots < $max_qty ) {

				// Fill additional slots.
				foreach ( $reverse_pricing_data as $combined_item_id => $data ) {

					$slots_to_fill = $max_qty - $filled_slots;


					if ( $filled_slots >= $max_qty ) {
						$quantities[ 'max' ][ $combined_item_id ] = $quantities[ 'min' ][ $combined_item_id ];
						continue;
					}

					$combined_item = $combined_items[ $combined_item_id ];

					if ( false === $combined_item->is_purchasable() ) {
						continue;
					}

					$max_items_to_use = $quantities[ 'max' ][ $combined_item_id ];
					$min_items_to_use = $quantities[ 'min' ][ $combined_item_id ];

					$items_to_use = '' !== $max_items_to_use ? min( $max_items_to_use - $min_items_to_use, $slots_to_fill ) : $slots_to_fill;

					$filled_slots += $items_to_use;

					$quantities[ 'max' ][ $combined_item_id ] = $quantities[ 'min' ][ $combined_item_id ] + $items_to_use;
				}
			}

			$result = $quantities[ 'max' ];
			WC_LafkaCombos_Helpers::cache_set( 'max_price_quantities_' . $combo->get_id(), $result );
		}

		return $result;
	}

	/**
	 * Sort array data by price.
	 *
	 * @param  array $a
	 * @param  array $b
	 * @return -1|0|1
	 */
	public static function sort_by_price( $a, $b ) {

		if ( $a[ 'price' ] == $b[ 'price' ] ) {
			return 0;
		}

		return ( $a[ 'price' ] < $b[ 'price' ] ) ? -1 : 1;
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Filter combined item min quantities used in sync/price context.
	 *
	 * @param  int              $qty
	 * @param  WC_Combined_Item  $combined_item
	 * @param  array            $args
	 * @return int
	 */
	public static function combined_item_quantity( $qty, $combined_item, $args = array() ) {

		if ( isset( $args[ 'context' ] ) && in_array( $args[ 'context' ], array( 'price' ) ) ) {

			$combo  = $combined_item->get_combo();
			$min_qty = $combo ? WC_LafkaCombos_Helpers::cache_get( 'min_qty_' . $combo->get_id() ) : '';

			if ( is_null( $min_qty ) ) {
				$min_qty = $combo->get_min_combo_size();
				WC_LafkaCombos_Helpers::cache_set( 'min_qty_' . $combo->get_id(), $min_qty );
			}

			if ( $min_qty ) {

				$quantities = self::get_min_price_quantities( $combo );

				if ( isset( $quantities[ $combined_item->get_id() ] ) ) {
					$qty = $quantities[ $combined_item->get_id() ];
				}
			}
		}

		return $qty;
	}

	/**
	 * Filter combined item max quantities used in sync/price context.
	 *
	 * @param  int              $qty
	 * @param  WC_Combined_Item  $combined_item
	 * @param  array            $args
	 * @return int
	 */
	public static function combined_item_quantity_max( $qty, $combined_item, $args = array() ) {

		if ( isset( $args[ 'context' ] ) && in_array( $args[ 'context' ], array( 'price' ) ) ) {

			$combo  = $combined_item->get_combo();
			$min_qty = $combo ? WC_LafkaCombos_Helpers::cache_get( 'min_qty_' . $combo->get_id() ) : '';

			if ( is_null( $min_qty ) ) {
				$min_qty = $combo->get_min_combo_size();
				WC_LafkaCombos_Helpers::cache_set( 'min_qty_' . $combo->get_id(), $min_qty );
			}

			if ( $min_qty ) {

				if ( 'price' === $args[ 'context' ] ) {
					$quantities = self::get_max_price_quantities( $combo );
				}

				if ( isset( $quantities[ $combined_item->get_id() ] ) ) {
					$qty = $quantities[ $combined_item->get_id() ];
				}
			}
		}

		return $qty;
	}

	/**
	 * When min/max qty constraints are present and the quantity of items in the combo can be adjusted, require input.
	 *
	 * @param  bool               $requires_input
	 * @param  WC_Product_Combo  $combo
	 */
	public static function min_max_combo_requires_input( $requires_input, $combo ) {

		if ( false === $requires_input ) {
			if ( self::has_limited_combo_size( $combo ) ) {
				$requires_input = true;
			}
		}

		return $requires_input;
	}

	/**
	 * Makes sure the combined items stock status takes the min combo size into account.
	 *
	 * @since  6.5.0
	 *
	 * @param  string             $combined_items_stock_status
	 * @param  WC_Product_Combo  $combo
	 * @return string
	 */
	public static function synced_combined_items_stock_status( $combined_items_stock_status, $combo ) {

		// If already out of stock, exit early.
		if ( 'outofstock' === $combined_items_stock_status ) {
			return $combined_items_stock_status;
		}

		$min_combo_size = $combo->get_min_combo_size();

		if ( $min_combo_size ) {

			$stock_available = 0;
			foreach ( $combo->get_combined_data_items( 'edit' ) as $combined_data_item ) {

				$item_stock_available = $combined_data_item->get_meta( 'max_stock' );

				if ( '' === $item_stock_available ) {
					$stock_available = '';
					break;
				}

				$stock_available += $item_stock_available;
			}

			if ( '' !== $stock_available && $stock_available < $min_combo_size ) {
				$combined_items_stock_status = 'outofstock';
			}
		}

		return $combined_items_stock_status;
	}

	/**
	 * Makes sure the combo stock quantity takes the min combo size into account.
	 *
	 * @since  6.5.0
	 *
	 * @param  string             $combo_stock_quantity
	 * @param  WC_Product_Combo  $combo
	 * @return string
	 */
	public static function synced_combo_stock_quantity( $combo_stock_quantity, $combo ) {

		// If already out of stock, exit early.
		if ( 0 === $combo_stock_quantity ) {
			return $combo_stock_quantity;
		}

		$min_combo_size = $combo->get_min_combo_size();

		if ( $min_combo_size ) {

			$stock_available = 0;
			foreach ( $combo->get_combined_data_items( 'edit' ) as $combined_data_item ) {

				$item_stock_available = $combined_data_item->get_meta( 'max_stock' );

				if ( '' === $item_stock_available ) {
					$stock_available = '';
					break;
				}

				$stock_available += $item_stock_available;
			}

			if ( '' === $stock_available ) {
				return $combo_stock_quantity;
			}

			$times_purchasable = intval( floor( $stock_available / $min_combo_size ) );

			if ( '' === $combo_stock_quantity || $times_purchasable < $combo_stock_quantity ) {
				$combo_stock_quantity = $times_purchasable;
			}
		}

		return $combo_stock_quantity;
	}

	/**
	 * Make sure the combo thinks it has 'mandatory' contents when the min combo size is > 0.
	 *
	 * @since  6.5.2
	 *
	 * @param  array              $data
	 * @param  WC_Product_Combo  $combo
	 * @return string
	 */
	public static function synced_contents_data( $data, $combo ) {

		$min_combo_size = $combo->get_min_combo_size();

		if ( $min_combo_size ) {
			$data[ 'mandatory' ] = true;
		}

		return $data;
	}
}

WC_LafkaCombos_MMI_Product::init();
