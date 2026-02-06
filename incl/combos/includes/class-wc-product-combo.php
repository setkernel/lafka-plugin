<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Combo Class.
 *
 * @class    WC_Product_Combo
 * @version  6.7.7
 */
class WC_Product_Combo extends WC_Product {

	/**
	 * Group mode options data.
	 * @see 'WC_Product_Combo::get_group_mode_options'.
	 * @var array
	 */
	private static $group_mode_options_data = null;

	/**
	 * Layout options data.
	 * @see 'WC_Product_Combo::get_layout_options'.
	 * @var array
	 */
	private static $layout_options_data = null;

	/**
	 * Array of combo-type extended product data fields used in CRUD and runtime operations.
	 * @var array
	 */
	private $extended_data = array(
		'min_combo_size'                 => '',
		'max_combo_size'                 => '',
		'layout'                          => 'default',
		'group_mode'                      => 'parent',
		'combo_stock_quantity'           => '',
		'combined_items_stock_status'      => '',
		'combined_items_stock_sync_status' => '',
		'editable_in_cart'                => false,
		'aggregate_weight'                => false,
		'sold_individually_context'       => 'product',
		'add_to_cart_form_location'       => 'default',
		'min_raw_price'                   => '',
		'min_raw_regular_price'           => '',
		'max_raw_price'                   => '',
		'max_raw_regular_price'           => ''
	);

	/**
	 * Array of combined item data objects.
	 * @var array
	 */
	private $combined_data_items = null;

	/**
	 * Combined item data objects that need deleting are stored here.
	 * @var array
	 */
	private $combined_data_items_delete_queue = array();

	/**
	 * Indicates whether combined data items have temporary IDs (saving needed).
	 * @var array
	 */
	private $combined_data_items_save_pending = false;

	/**
	 * Array of form data for consumption by the front-end script.
	 * @var array
	 */
	private $combo_form_data = array();

	/**
	 * Runtime cache for combo prices.
	 * @var array
	 */
	private $combo_price_cache = array();

	/**
	 * Combo object instance context.
	 */
	private $object_context = '';

	/**
	 * Storage of 'contains' keys, most set during sync.
	 * @var array
	 */
	private $contains = array();

	/**
	 * True if the combo is in sync with combined items.
	 * @var boolean
	 */
	private $is_synced = false;

	/**
	 * True if the combo is currently syncing.
	 * @var boolean
	 */
	private $is_syncing = false;

	/**
	 * The type of data store to use.
	 * @var string
	 */
	private $data_store_type = 'combo';

	/**
	 * Back-compat product type identifier.
	 * @var string
	 */
	public $product_type = 'combo';

	/**
	 * Name-Your-Price status flag.
	 * @var boolean
	 */
	public $is_nyp = false;

	/**
	 * Composited cart item reference (CP compatibility).
	 * @var mixed
	 */
	public $composited_cart_item;

	/**
	 * Constructor.
	 *
	 * @param  mixed  $product
	 */
	public function __construct( $product = 0 ) {

		// Initialize the data store type. Yes, WC 3.0 decouples the data store from the product class.
		if ( ( $product instanceof WC_Product ) && false === $product->is_type( 'combo' ) ) {
			$this->data_store_type = $product->get_type();
		}

		// Initialize private properties.
		$this->load_defaults();

		// Define/load type-specific data.
		$this->load_extended_data();

		// Load product data.
		parent::__construct( $product );
	}

	/**
	 * Get internal type.
	 *
	 * @since  5.1.0
	 *
	 * @return string
	 */
	public function get_type() {
		return 'combo';
	}

	/**
	 * Get data store type.
	 *
	 * @since  5.6.0
	 *
	 * @return string
	 */
	public function get_data_store_type() {
		return $this->data_store_type;
	}

	/**
	 * Load property and runtime cache defaults to trigger a re-sync.
	 *
	 * @since 5.2.0
	 */
	public function load_defaults( $reset_objects = false ) {

		$this->contains = array(
			'priced_individually'               => null,
			'shipped_individually'              => null,
			'assembled'                         => null,
			'optional'                          => false,
			'mandatory'                         => false,
			'on_backorder'                      => false,
			'subscriptions'                     => false,
			'subscriptions_priced_individually' => false,
			'subscriptions_priced_variably'     => false,
			'multiple_subscriptions'            => false,
			'nyp'                               => false,
			'non_purchasable'                   => false,
			'options'                           => false,
			'out_of_stock'                      => false, // Not including optional and zero min qty items (combo can still be purchased).
			'out_of_stock_strict'               => false, // Including optional and zero min qty items (admin needs to be aware).
			'sold_in_multiples'                 => false,
			'sold_individually'                 => false,
			'discounted'                        => false,
			'discounted_mandatory'              => false,
			'configurable_quantities'           => false,
			'hidden'                            => false,
			'visible'                           => false
		);

		$this->is_synced          = false;
		$this->combo_form_data   = array();
		$this->combo_price_cache = array();

		if ( $reset_objects ) {
			$this->combined_data_items = null;
		}
	}

	/**
	 * Define type-specific data.
	 *
	 * @since  5.2.0
	 */
	private function load_extended_data() {

		// Back-compat.
		$this->product_type = 'combo';

		// Define type-specific fields and let WC use our data store to read the data.
		$this->data = array_merge( $this->data, $this->extended_data );
	}

	/**
	 * Sync combo props with combined item objects.
	 *
	 * @since  5.5.0
	 *
	 * @param  bool  $force
	 * @return bool
	 */
	public function sync( $force = false ) {

		if ( $this->is_synced && false === $force ) {
			return false;
		}

		$this->is_syncing = true;

		$combined_items = $this->get_combined_items();
		$group_mode    = $this->get_group_mode();
		$is_front_end  = WC_LafkaCombos_Helpers::is_front_end();

		if ( ! empty( $combined_items ) ) {

			// Scan combined items and sync combo properties.
			foreach ( $combined_items as $combined_item ) {

				$min_quantity = $combined_item->get_quantity( 'min', array( 'context' => 'sync', 'check_optional' => true ) );
				$max_quantity = $combined_item->get_quantity( 'max', array( 'context' => 'sync' ) );

				if ( $min_quantity !== $max_quantity ) {
					$this->contains[ 'configurable_quantities' ] = true;
				}

				if ( $combined_item->is_sold_individually() ) {
					$this->contains[ 'sold_individually' ] = true;
				} else {
					$this->contains[ 'sold_in_multiples' ] = true;
				}

				if ( $combined_item->is_optional() ) {
					$this->contains[ 'optional' ]                = true;
					$this->contains[ 'configurable_quantities' ] = true;
				} elseif ( $min_quantity > 0 ) {
					$this->contains[ 'mandatory' ] = true;
				}

				if ( ! $this->contains[ 'out_of_stock_strict' ] && false === $combined_item->has_enough_stock( $min_quantity ) ) {
					$this->contains[ 'out_of_stock_strict' ] = true;
					if ( false === $combined_item->is_optional() && $min_quantity !== 0 ) {
						$this->contains[ 'out_of_stock' ] = true;
					}
				}

				if ( ! $this->contains[ 'on_backorder' ] && $combined_item->is_on_backorder() && $combined_item->product->backorders_require_notification() && false === $combined_item->is_optional() && $min_quantity !== 0 ) {
					$this->contains[ 'on_backorder' ] = true;
				}

				if ( false === $combined_item->is_purchasable() && false === $combined_item->is_optional() && $min_quantity !== 0 ) {
					$this->contains[ 'non_purchasable' ] = true;
				}

				if ( ( ! $this->contains[ 'discounted' ] || ! $this->contains[ 'discounted_mandatory' ] ) && $combined_item->get_discount( 'sync' ) > 0 ) {
					$this->contains[ 'discounted' ] = true;
					if ( false === $combined_item->is_optional() && $min_quantity !== 0 ) {
						$this->contains[ 'discounted_mandatory' ] = true;
					}
				}

				if ( ! $this->contains[ 'nyp' ] && $combined_item->is_nyp() ) {
					$this->contains[ 'nyp' ] = true;
				}

				if ( $combined_item->is_subscription() ) {

					if ( $this->contains[ 'subscriptions' ] ) {
						$this->contains[ 'multiple_subscriptions' ] = true;
					}

					$this->contains[ 'subscriptions' ] = true;

					if ( $combined_item->is_priced_individually() ) {
						$this->contains[ 'subscriptions_priced_individually' ] = true;
					}

					// If it's a variable sub with a variable price, show 'From:' string before Combo price.
					if ( $combined_item->is_variable_subscription() ) {
						$combined_item->add_price_filters();
						if ( $combined_item->product->get_variation_price( 'min' ) !== $combined_item->product->get_variation_price( 'max' ) || $combined_item->product->get_meta( '_min_variation_period', true ) !== $combined_item->product->get_meta( '_max_variation_period', true ) || $combined_item->product->get_meta( '_min_variation_period_interval', true ) !== $combined_item->product->get_meta( '_max_variation_period_interval', true ) ) {
							$this->contains[ 'subscriptions_priced_variably' ] = true;
						}
						$combined_item->remove_price_filters();
					}
				}

				// Significant cost due to get_product_addons - skip this in the admin area since it is only used to modify add to cart button behaviour.
				if ( $is_front_end ) {
					if ( false === $combined_item->is_optional() ) {
						if ( ! $this->contains[ 'options' ] && $combined_item->requires_input() ) {
							$this->contains[ 'options' ] = true;
						}
					}
				}

				if ( $combined_item->is_visible() ) {
					$this->contains[ 'visible' ] = true;
				} else {
					$this->contains[ 'hidden' ] = true;
				}
			}
		}

		/**
		 * Give third parties a chance to modify the content flags of this combo.
		 *
		 * @since  6.5.2
		 *
		 * @param  array              $contains
		 * @param  WC_Product_Combo  $this
		 */
		$this->contains = apply_filters( 'woocommerce_combos_synced_contents_data', $this->contains, $this );

		// Allow adding to cart via ajax if no user input is required.
		if ( $is_front_end ) {
			// Is a child selection required by the chosen group mode?
			if ( false === $this->contains[ 'mandatory' ] && false === self::group_mode_has( $group_mode, 'parent_item' ) ) {
				$this->contains[ 'options' ] = true;
			}
			// Any addons at combo level?
			if ( ! $this->contains[ 'options' ] && WC_LafkaCombos()->compatibility->has_addons( $this, true ) ) {
				$this->contains[ 'options' ] = true;
			}
		}

		if ( ! $this->contains[ 'options' ] ) {
			$this->supports[] = 'ajax_add_to_cart';
		}

		// Set this now to avoid infinite loops.
		$this->is_synced  = true;
		$this->is_syncing = false;

		/*
		 * Sync combined items stock status.
		 */
		$this->sync_stock();

		/*
		 * Sync min/max raw prices.
		 */
		$this->sync_raw_prices();

		/**
		 * 'woocommerce_combos_synced_combo' action.
		 *
		 * @param  WC_Product_Combo  $this
		 */
		do_action( 'woocommerce_combos_synced_combo', $this );

		return true;
	}

	/**
	 * Sync product combo raw price meta.
	 *
	 * @since  5.5.0
	 *
	 * @return boolean
	 */
	private function sync_raw_prices() {

		$min_raw_price         = $this->get_price( 'sync' );
		$min_raw_regular_price = $this->get_regular_price( 'sync' );
		$max_raw_price         = $this->get_price( 'sync' );
		$max_raw_regular_price = $this->get_regular_price( 'sync' );

		if ( $this->is_nyp() ) {
			$max_raw_price = $max_raw_regular_price = INF;
		}

		$combined_items = $this->get_combined_items( 'edit' );

		if ( ! empty( $combined_items ) ) {
			foreach ( $combined_items as $combined_item ) {

				if ( $combined_item->is_priced_individually() ) {

					$min_quantity = $combined_item->get_quantity( 'min', array( 'context' => 'price', 'check_optional' => true ) );
					$max_quantity = $combined_item->get_quantity( 'max', array( 'context' => 'price' ) );

					$min_raw_price         += $min_quantity * (float) $combined_item->min_price;
					$min_raw_regular_price += $min_quantity * (float) $combined_item->min_regular_price;

					if ( ! $max_quantity ) {
						$max_raw_price = $max_raw_regular_price = INF;
					}

					$item_max_raw_price         = INF !== $combined_item->max_price ? (float) $combined_item->max_price : INF;
					$item_max_raw_regular_price = INF !== $combined_item->max_regular_price ? (float) $combined_item->max_regular_price : INF;

					if ( INF !== $max_raw_price ) {
						if ( INF !== $item_max_raw_price ) {
							$max_raw_price         += $max_quantity * $item_max_raw_price;
							$max_raw_regular_price += $max_quantity * $item_max_raw_regular_price;
						} else {
							$max_raw_price = $max_raw_regular_price = INF;
						}
					}
				}
			}

			// Calculate the min combined item price and use it when the active group mode requires a child selection.
			if ( false === self::group_mode_has( $this->get_group_mode( 'edit' ), 'parent_item' ) && false === $this->contains[ 'mandatory' ] ) {

				$min_item_price = null;

				foreach ( $combined_items as $combined_item ) {
					$min_quantity = max( $combined_item->get_quantity( 'min' ), 1 );
					if ( is_null( $min_item_price ) || $min_quantity * (float) $combined_item->min_price < $min_item_price ) {
						$min_item_price = $min_quantity * (float) $combined_item->min_price;
					}
				}

				if ( $min_item_price > 0 ) {
					$min_raw_price = $min_item_price;
				}
			}
		}

		/**
		 * 'woocommerce_combo_min/max_raw_[regular_]price' filters.
		 *
		 * @since  5.8.1
		 *
		 * @param  mixed              $price
		 * @param  WC_Product_Combo  $this
		 */
		$min_raw_price         = apply_filters( 'woocommerce_combo_min_raw_price', $min_raw_price, $this );
		$min_raw_regular_price = apply_filters( 'woocommerce_combo_min_raw_regular_price', $min_raw_regular_price, $this );
		$max_raw_price         = apply_filters( 'woocommerce_combo_max_raw_price', $max_raw_price, $this );
		$max_raw_regular_price = apply_filters( 'woocommerce_combo_max_raw_regular_price', $max_raw_regular_price, $this );

		$raw_price_meta_changed = false;

		if ( $this->get_min_raw_price( 'sync' ) !== $min_raw_price || $this->get_min_raw_regular_price( 'sync' ) !== $min_raw_regular_price || $this->get_max_raw_price( 'sync' ) !== $max_raw_price || $this->get_max_raw_regular_price( 'sync' ) !== $max_raw_regular_price ) {
			$raw_price_meta_changed = true;
		}

		$this->set_min_raw_price( $min_raw_price );
		$this->set_min_raw_regular_price( $min_raw_regular_price );
		$this->set_max_raw_price( $max_raw_price );
		$this->set_max_raw_regular_price( $max_raw_regular_price );

		if ( $raw_price_meta_changed ) {

			if ( 'combo' === $this->get_data_store_type() ) {
				$this->data_store->save_raw_price_props( $this );
			}

			return true;
		}

		return false;
	}

	/**
	 * Syncs stock data. Reads data from combined data items, avoiding overhead of 'WC_Combined_Item'.
	 *
	 * @since  6.5.0
	 *
	 * @return bool
	 */
	public function sync_stock() {

		$props_to_save          = array();
		$combined_items_in_stock = true;

		/*
		 * Sync 'combined_items_stock_status' prop.
		 */
		foreach ( $this->get_combined_data_items( 'edit' ) as $combined_data_item ) {

			$combined_item_stock_status = $combined_data_item->get_meta( 'stock_status' );

			if ( is_null( $combined_item_stock_status ) ) {
				$combined_item              = $this->get_combined_item( $combined_data_item, 'edit' );
				$combined_item_stock_status = $combined_item && $combined_item->exists() ? $combined_item->get_stock_status() : null;
			}

			if ( 'out_of_stock' === $combined_item_stock_status && 'no' === $combined_data_item->get_meta( 'optional' ) && $combined_data_item->get_meta( 'quantity_min' ) > 0 ) {
				$combined_items_in_stock = false;
			}
		}

		/**
		 * 'woocommerce_synced_combined_items_stock_status' filter.
		 *
		 * @since  6.5.0
		 *
		 * @param  string             $combined_items_stock_status
		 * @param  WC_Product_Combo  $this
		 */
		$combined_items_stock_status = apply_filters( 'woocommerce_synced_combined_items_stock_status', $combined_items_in_stock ? 'instock' : 'outofstock', $this );

		if ( $combined_items_stock_status !== $this->get_combined_items_stock_status( 'edit' ) ) {
			$this->set_combined_items_stock_status( $combined_items_stock_status );
			$props_to_save[] = 'combined_items_stock_status';
		}

		/*
		 * Sync 'combo_stock_quantity' prop.
		 */

		$combo_stock_quantity = '';

		if ( 'outofstock' === parent::get_stock_status( 'edit' ) || 'outofstock' === $combined_items_stock_status ) {
			$combo_stock_quantity = 0;
		} else {

			// Find parent quantity.
			$parent_stock_quantity = '';

			if ( ! $this->backorders_allowed() && $this->managing_stock() ) {
				$parent_stock_quantity = $this->get_stock_quantity( 'edit' );
				$parent_stock_quantity = null === $parent_stock_quantity ? '' : $parent_stock_quantity;
			}

			// Find combined items stock quantity based on the least stocked item.
			$combined_items_stock_quantity = '';

			foreach ( $this->get_combined_data_items( 'edit' ) as $combined_data_item ) {

				$combined_item_min_qty = $combined_data_item->get_meta( 'quantity_min' );

				if ( 'yes' === $combined_data_item->get_meta( 'optional' ) || 0 === $combined_item_min_qty ) {
					continue;
				}

				$combined_item_stock_quantity = $combined_data_item->get_meta( 'max_stock' );

				// Infinite qty? Move on.
				if ( '' === $combined_item_stock_quantity ) {
					continue;
				}

				// No stock? Break.
				if ( 0 === $combined_item_stock_quantity ) {
					$combined_items_stock_quantity = 0;
					break;
				}

				// How many times could this combo be purchased if it only contained this item?
				$combined_item_parent_stock_quantity = intval( floor( $combined_item_stock_quantity / $combined_item_min_qty ) );

				if ( '' === $combined_items_stock_quantity || $combined_item_parent_stock_quantity < $combined_items_stock_quantity ) {
					$combined_items_stock_quantity = $combined_item_parent_stock_quantity;
				}
			}

			if ( '' === $parent_stock_quantity && '' === $combined_items_stock_quantity ) {
				$combo_stock_quantity = '';
			} elseif ( 0 === $parent_stock_quantity || 0 === $combined_items_stock_quantity ) {
				$combo_stock_quantity = 0;
			} elseif ( '' === $parent_stock_quantity ) {
				$combo_stock_quantity = $combined_items_stock_quantity;
			} elseif ( '' === $combined_items_stock_quantity ) {
				$combo_stock_quantity = $parent_stock_quantity;
			} else {
				$combo_stock_quantity = intval( min( $combined_items_stock_quantity, $parent_stock_quantity ) );
			}
		}

		/**
		 * 'woocommerce_synced_combo_stock_quantity' filter.
		 *
		 * @since  6.5.0
		 *
		 * @param  int                $combo_stock_quantity
		 * @param  WC_Product_Combo  $this
		 */
		$combo_stock_quantity = apply_filters( 'woocommerce_synced_combo_stock_quantity', $combo_stock_quantity, $this );

		if ( $combo_stock_quantity !== $this->get_combo_stock_quantity( 'edit' ) ) {
			$this->set_combo_stock_quantity( $combo_stock_quantity );
			$props_to_save[] = 'combo_stock_quantity';
		}

		/*
		 * Sync 'combined_items_stock_sync_status' prop.
		 */

		if ( 'unsynced' === $this->get_combined_items_stock_sync_status() ) {
			$this->set_combined_items_stock_sync_status( 'synced' );
			$props_to_save[] = 'combined_items_stock_sync_status';
		}

		if ( 'combo' === $this->get_data_store_type() ) {
			$this->data_store->save_stock_sync_props( $this, $props_to_save );
		}

		return ! empty( $props_to_save );
	}

	/**
	 * Returns form data passed to JS.
	 *
	 * @since  6.4.0
	 *
	 * @return array
	 */
	public function get_combo_form_data() {

		if ( empty( $this->combo_form_data ) ) {

			$data = array();

			$raw_combo_price_min = $this->get_combo_price( 'min', true );
			$raw_combo_price_max = $this->get_combo_price( 'max', true );

			$group_mode = $this->get_group_mode();

			$data[ 'layout' ] = $this->get_layout();

			$data[ 'hide_total_on_validation_fail' ] = 'no';

			$data[ 'zero_items_allowed' ] = self::group_mode_has( $group_mode, 'parent_item' ) ? 'yes' : 'no';

			$data[ 'raw_combo_price_min' ] = (float) $raw_combo_price_min;
			$data[ 'raw_combo_price_max' ] = '' === $raw_combo_price_max ? '' : (float) $raw_combo_price_max;

			$data[ 'is_purchasable' ]    = $this->is_purchasable() ? 'yes' : 'no';
			$data[ 'show_free_string' ]  = 'no';
			$data[ 'show_total_string' ] = 'no';

			$data[ 'prices' ]         = array();
			$data[ 'regular_prices' ] = array();

			$data[ 'prices_tax' ] = array();

			$data[ 'addons_prices' ]         = array();
			$data[ 'regular_addons_prices' ] = array();

			$data[ 'quantities' ] = array();

			$data[ 'product_ids' ] = array();

			$data[ 'is_sold_individually' ] = array();

			$data[ 'recurring_prices' ]         = array();
			$data[ 'regular_recurring_prices' ] = array();

			$data[ 'recurring_html' ] = array();
			$data[ 'recurring_keys' ] = array();

			$data[ 'base_price' ]         = $this->get_price();
			$data[ 'base_regular_price' ] = $this->get_regular_price();
			$data[ 'base_price_tax' ]     = WC_LafkaCombos_Product_Prices::get_tax_ratios( $this );

			$totals = new stdClass;

			$totals->price          = 0.0;
			$totals->regular_price  = 0.0;
			$totals->price_incl_tax = 0.0;
			$totals->price_excl_tax = 0.0;

			$data[ 'base_price_totals' ] = $totals;
			$data[ 'subtotals' ]         = $totals;
			$data[ 'totals' ]            = $totals;
			$data[ 'recurring_totals' ]  = $totals;

			$combined_items = $this->get_combined_items();

			if ( empty( $combined_items ) ) {
				return;
			}

			foreach ( $combined_items as $combined_item ) {

				if ( ! $combined_item->is_purchasable() ) {
					continue;
				}

				$min_quantity = $combined_item->get_quantity( 'min', array( 'context' => 'sync', 'check_optional' => true ) );
				$max_quantity = $combined_item->get_quantity( 'max', array( 'context' => 'sync' ) );

				$data[ 'has_variable_quantity' ][ $combined_item->get_id() ] = $min_quantity !== $max_quantity ? 'yes' : 'no';

				$data[ 'quantities_available' ][ $combined_item->get_id() ]            = '';
				$data[ 'is_in_stock' ][ $combined_item->get_id() ]                     = '';
				$data[ 'backorders_allowed' ][ $combined_item->get_id() ]              = '';
				$data[ 'backorders_require_notification' ][ $combined_item->get_id() ] = '';

				if ( $combined_item->get_product()->is_type( 'simple' ) ) {

					$data[ 'quantities_available' ][ $combined_item->get_id() ]            = $combined_item->get_stock_quantity();
					$data[ 'is_in_stock' ][ $combined_item->get_id() ]                     = $combined_item->is_in_stock() ? 'yes' : 'no';
					$data[ 'backorders_allowed' ][ $combined_item->get_id() ]              = $combined_item->is_on_backorder() || $combined_item->get_product()->backorders_allowed() ? 'yes' : 'no';
					$data[ 'backorders_require_notification' ][ $combined_item->get_id() ] = $combined_item->is_on_backorder() || $combined_item->get_product()->backorders_require_notification() ? 'yes' : 'no';
				}

				$data[ 'is_nyp' ][ $combined_item->get_id() ] = $combined_item->is_nyp() ? 'yes' : 'no';

				$data[ 'product_ids' ][ $combined_item->get_id() ] = $combined_item->get_product_id();

				$data[ 'is_sold_individually' ][ $combined_item->get_id() ]   = $combined_item->is_sold_individually() ? 'yes' : 'no';
				$data[ 'is_priced_individually' ][ $combined_item->get_id() ] = $combined_item->is_priced_individually() ? 'yes' : 'no';

				$data[ 'prices' ][ $combined_item->get_id() ]         = $combined_item->get_price( 'min' );
				$data[ 'regular_prices' ][ $combined_item->get_id() ] = $combined_item->get_regular_price( 'min' );

				$data[ 'prices_tax' ][ $combined_item->get_id() ] = WC_LafkaCombos_Product_Prices::get_tax_ratios( $combined_item->product );

				$data[ 'addons_prices' ][ $combined_item->get_id() ]         = '';
				$data[ 'regular_addons_prices' ][ $combined_item->get_id() ] = '';

				$data[ 'combined_item_' . $combined_item->get_id() . '_totals' ]           = $totals;
				$data[ 'combined_item_' . $combined_item->get_id() . '_recurring_totals' ] = $totals;

				$data[ 'quantities' ][ $combined_item->get_id() ] = '';

				$data[ 'recurring_prices' ][ $combined_item->get_id() ]         = '';
				$data[ 'regular_recurring_prices' ][ $combined_item->get_id() ] = '';

				// Store sub recurring key for summation (variable sub keys are stored in variations data).
				$data[ 'recurring_html' ][ $combined_item->get_id() ] = '';
				$data[ 'recurring_keys' ][ $combined_item->get_id() ] = '';

				if ( $combined_item->is_priced_individually() && $combined_item->is_subscription() && ! $combined_item->is_variable_subscription() ) {

					$data[ 'recurring_prices' ][ $combined_item->get_id() ]         = $combined_item->get_recurring_price( 'min' );
					$data[ 'regular_recurring_prices' ][ $combined_item->get_id() ] = $combined_item->get_regular_recurring_price( 'min' );

					$data[ 'recurring_keys' ][ $combined_item->get_id() ] = str_replace( '_synced', '', WC_Subscriptions_Cart::get_recurring_cart_key( array( 'data' => $combined_item->product ), ' ' ) );
					$data[ 'recurring_html' ][ $combined_item->get_id() ] = WC_LafkaCombos_Product_Prices::get_recurring_price_html_component( $combined_item->product );
				}
			}

			if ( $this->contains( 'subscriptions_priced_individually' ) ) {
				$data[ 'price_string_recurring' ]          = '<span class="combined_subscriptions_price_html">%r</span>';
				$data[ 'price_string_recurring_up_front' ] = sprintf( _x( '%1$s<span class="combined_subscriptions_price_html"> one time%2$s</span>', 'subscription price html', 'lafka-plugin' ), '%s', '%r' );;
			}

			$group_mode              = $this->get_group_mode();
			$group_mode_options_data = self::get_group_mode_options_data();

			$data[ 'group_mode_features' ] = ! empty( $group_mode_options_data[ $group_mode ][ 'features' ] ) && is_array( $group_mode_options_data[ $group_mode ][ 'features' ] ) ? $group_mode_options_data[ $group_mode ][ 'features' ] : array();

			/**
			 * 'woocommerce_combo_price_data' filter.
			 *
			 * Filter price data - to be encoded and passed to JS.
			 *
			 * @param  array              $combo_price_data
			 * @param  WC_Product_Combo  $this
			 */
			$this->combo_form_data = apply_filters( 'woocommerce_combo_price_data', $data, $this );
		}

		return $this->combo_form_data;
	}

	/**
	 * Min/max combo price.
	 *
	 * @param  string   $min_or_max
	 * @param  boolean  $display
	 * @return mixed
	 */
	public function get_combo_price( $min_or_max = 'min', $display = false ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'calc'       => $display ? 'display' : '',
			'prop'       => 'price'
		) );
	}

	/**
	 * Min/max combo regular price.
	 *
	 * @param  string   $min_or_max
	 * @param  boolean  $display
	 * @return mixed
	 */
	public function get_combo_regular_price( $min_or_max = 'min', $display = false ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'calc'       => $display ? 'display' : '',
			'prop'       => 'regular_price',
			'strict'     => true
		) );
	}

	/**
	 * Min/max combo price including tax.
	 *
	 * @param  string   $min_or_max
	 * @param  integer  $qty
	 * @return mixed
	 */
	public function get_combo_price_including_tax( $min_or_max = 'min', $qty = 1 ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'qty'        => $qty,
			'calc'       => 'incl_tax',
			'prop'       => 'price'
		) );
	}

	/**
	 * Min/max combo price excluding tax.
	 *
	 * @param  string   $min_or_max
	 * @param  integer  $qty
	 * @return mixed
	 */
	public function get_combo_price_excluding_tax( $min_or_max = 'min', $qty = 1 ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'qty'        => $qty,
			'calc'       => 'excl_tax',
			'prop'       => 'price'
		) );
	}

	/**
	 * Min/max regular combo price including tax.
	 *
	 * @since  5.5.0
	 *
	 * @param  string   $min_or_max
	 * @param  integer  $qty
	 * @return mixed
	 */
	public function get_combo_regular_price_including_tax( $min_or_max = 'min', $qty = 1 ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'qty'        => $qty,
			'calc'       => 'incl_tax',
			'prop'       => 'regular_price',
			'strict'     => true
		) );
	}

	/**
	 * Min/max regular combo price excluding tax.
	 *
	 * @since  5.5.0
	 *
	 * @param  string   $min_or_max
	 * @param  integer  $qty
	 * @return mixed
	 */
	public function get_combo_regular_price_excluding_tax( $min_or_max = 'min', $qty = 1 ) {
		return $this->calculate_price( array(
			'min_or_max' => $min_or_max,
			'qty'        => $qty,
			'calc'       => 'excl_tax',
			'prop'       => 'regular_price',
			'strict'     => true
		) );
	}

	/**
	 * Calculates combo prices.
	 *
	 * @since  5.5.0
	 *
	 * @param  array  $args
	 * @return mixed
	 */
	public function calculate_price( $args ) {

		$min_or_max = isset( $args[ 'min_or_max' ] ) && in_array( $args[ 'min_or_max' ] , array( 'min', 'max' ) ) ? $args[ 'min_or_max' ] : 'min';
		$qty        = isset( $args[ 'qty' ] ) ? absint( $args[ 'qty' ] ) : 1;
		$price_prop = isset( $args[ 'prop' ] ) && in_array( $args[ 'prop' ] , array( 'price', 'regular_price' ) ) ? $args[ 'prop' ] : 'price';
		$price_calc = isset( $args[ 'calc' ] ) && in_array( $args[ 'calc' ] , array( 'incl_tax', 'excl_tax', 'display', '' ) ) ? $args[ 'calc' ] : '';
		$strict     = isset( $args[ 'strict' ] ) && $args[ 'strict' ] && 'regular_price' === $price_prop;

		if ( $this->contains( 'priced_individually' ) ) {

			$cache_key = md5( json_encode( apply_filters( 'woocommerce_combo_prices_hash', array(
				'prop'       => $price_prop,
				'min_or_max' => $min_or_max,
				'calc'       => $price_calc,
				'qty'        => $qty,
				'strict'     => $strict,
			), $this ) ) );


			if ( isset( $this->combo_price_cache[ $cache_key ] ) ) {
				$price = $this->combo_price_cache[ $cache_key ];
			} else {

				$raw_price_fn = 'get_' . $min_or_max . '_raw_' . $price_prop;

				if ( '' === $this->$raw_price_fn() || INF === $this->$raw_price_fn() ) {
					$price = '';
				} else {

					$price_fn = 'get_' . $price_prop;
					$price    = wc_format_decimal( WC_LafkaCombos_Product_Prices::get_product_price( $this, array(
						'price' => $this->$price_fn(),
						'qty'   => $qty,
						'calc'  => $price_calc,
					) ), wc_pc_price_num_decimals() );

					$combined_items = $this->get_combined_items();

					if ( ! empty( $combined_items ) ) {
						foreach ( $combined_items as $combined_item ) {

							if ( false === $combined_item->is_purchasable() ) {
								continue;
							}

							if ( false === $combined_item->is_priced_individually() ) {
								continue;
							}

							$combined_item_qty = $qty * $combined_item->get_quantity( $min_or_max, array( 'context' => 'price', 'check_optional' => $min_or_max === 'min' ) );

							if ( $combined_item_qty ) {

								$price += wc_format_decimal( $combined_item->calculate_price( array(
									'min_or_max' => $min_or_max,
									'qty'        => $combined_item_qty,
									'strict'     => $strict,
									'calc'       => $price_calc,
									'prop'       => $price_prop
								) ), wc_pc_price_num_decimals() );
							}
						}

						$group_mode = $this->get_group_mode( 'edit' );

						// Calculate the min combined item price and use it when the parent item is meant to be hidden and all items are optional.
						if ( 'min' === $min_or_max && false === self::group_mode_has( $group_mode, 'parent_item' ) && false === $this->contains( 'mandatory' ) ) {

							$min_price = null;

							foreach ( $combined_items as $combined_item ) {

								if ( false === $combined_item->is_purchasable() ) {
									continue;
								}

								if ( false === $combined_item->is_priced_individually() ) {
									continue;
								}

								$quantity = max( $combined_item->get_quantity( 'min' ), 1 );

								$combined_item_price = $combined_item->calculate_price( array(
									'min_or_max' => $min_or_max,
									'qty'        => $quantity,
									'strict'     => $strict,
									'calc'       => $price_calc,
									'prop'       => $price_prop
								) );

								if ( is_null( $min_price ) || $combined_item_price < $min_price ) {
									$min_price = $combined_item_price;
								}
							}

							if ( $min_price > 0 ) {
								$price = $min_price;
							}
						}
					}
				}

				$this->combo_price_cache[ $cache_key ] = $price;
			}

		} else {

			$price_fn = 'get_' . $price_prop;
			$price    = WC_LafkaCombos_Product_Prices::get_product_price( $this, array(
				'price' => $this->$price_fn(),
				'qty'   => $qty,
				'calc'  => $price_calc,
			) );
		}

		return $price;
	}

	/**
	 * Prices incl. or excl. tax are calculated based on the combined products prices, so get_price_suffix() must be overridden when individually-priced items exist.
	 *
	 * @return string
	 */
	public function get_price_suffix( $price = '', $qty = 1 ) {

		if ( ! $this->contains( 'priced_individually' ) ) {
			return parent::get_price_suffix();
		}

		$suffix      = get_option( 'woocommerce_price_display_suffix' );
		$suffix_html = '';

		if ( $suffix && wc_tax_enabled() ) {

			if ( 'range' === $price && strstr( $suffix, '{' ) ) {
				$suffix = false;
				$price  = '';
			}

			if ( $suffix ) {

				$replacements = array(
					'{price_including_tax}' => wc_price( $this->get_combo_price_including_tax( 'min', $qty ) ),
					'{price_excluding_tax}' => wc_price( $this->get_combo_price_excluding_tax( 'min', $qty ) )
				);

				$suffix_html = str_replace( array_keys( $replacements ), array_values( $replacements ), ' <small class="woocommerce-price-suffix">' . wp_kses_post( $suffix ) . '</small>' );
			}
		}

		/**
		 * 'woocommerce_get_price_suffix' filter.
		 *
		 * @param  string             $suffix_html
		 * @param  WC_Product_Combo  $this
		 * @param  mixed              $price
		 * @param  int                $qty
		 */
		return apply_filters( 'woocommerce_get_price_suffix', $suffix_html, $this, $price, $qty );
	}

	/**
	 * Calculate subscriptions price html component by breaking up combined subs into recurring scheme groups and adding up all prices in each group.
	 *
	 * @return string
	 */
	public function apply_subs_price_html( $price ) {

		$combined_items = $this->get_combined_items();

		if ( ! empty( $combined_items ) ) {

			$subs_details            = array();
			$subs_details_html       = array();
			$non_optional_subs_exist = false;
			$from_string             = wc_get_price_html_from_text();
			$has_payment_up_front    = $this->get_combo_regular_price( 'min' ) > 0;
			$is_range                = false !== strpos( $price, $from_string );

			foreach ( $combined_items as $combined_item_id => $combined_item ) {

				if ( $combined_item->is_subscription() && $combined_item->is_priced_individually() ) {

					$combined_product    = $combined_item->product;
					$combined_product_id = $combined_item->get_product_id();

					if ( $combined_item->is_variable_subscription() ) {
						$product = $combined_item->min_price_product;
					} else {
						$product = $combined_product;
					}

					$sub_string = str_replace( '_synced', '', WC_Subscriptions_Cart::get_recurring_cart_key( array( 'data' => $product ), ' ' ) );

					if ( ! isset( $subs_details[ $sub_string ][ 'combined_items' ] ) ) {
						$subs_details[ $sub_string ][ 'combined_items' ] = array();
					}

					if ( ! isset( $subs_details[ $sub_string ][ 'price' ] ) ) {
						$subs_details[ $sub_string ][ 'price' ]         = 0;
						$subs_details[ $sub_string ][ 'regular_price' ] = 0;
						$subs_details[ $sub_string ][ 'is_range' ]      = false;
					}

					$subs_details[ $sub_string ][ 'combined_items' ][] = $combined_item_id;

					$subs_details[ $sub_string ][ 'price' ]         += $combined_item->get_quantity( 'min', array( 'context' => 'price', 'check_optional' => true ) ) * WC_LafkaCombos_Product_Prices::get_product_price( $product, array( 'price' => $combined_item->min_recurring_price, 'calc' => 'display' ) );
					$subs_details[ $sub_string ][ 'regular_price' ] += $combined_item->get_quantity( 'min', array( 'context' => 'price', 'check_optional' => true ) ) * WC_LafkaCombos_Product_Prices::get_product_price( $product, array( 'price' => $combined_item->min_regular_recurring_price, 'calc' => 'display' ) );

					if ( $combined_item->is_variable_subscription() ) {

						$combined_item->add_price_filters();

						if ( $combined_item->has_variable_subscription_price() ) {
							$subs_details[ $sub_string ][ 'is_range' ] = true;
						}

						$combined_item->remove_price_filters();
					}

					if ( ! isset( $subs_details[ $sub_string ][ 'price_html' ] ) ) {
						$subs_details[ $sub_string ][ 'price_html' ] = WC_LafkaCombos_Product_Prices::get_recurring_price_html_component( $product );
					}
				}
			}

			if ( ! empty( $subs_details ) ) {

				foreach ( $subs_details as $sub_details ) {

					if ( $sub_details[ 'is_range' ] ) {
						$is_range = true;
					}

					if ( $sub_details[ 'regular_price' ] > 0 ) {

						$sub_price_html = wc_price( $sub_details[ 'price' ] );

						if ( $sub_details[ 'price' ] !== $sub_details[ 'regular_price' ] ) {

							$sub_regular_price_html = wc_price( $sub_details[ 'regular_price' ] );
							$sub_price_html         = wc_format_sale_price( $sub_regular_price_html, $sub_price_html );
						}

						$sub_price_details_html = sprintf( $sub_details[ 'price_html' ], $sub_price_html );
						$subs_details_html[]    = '<span class="combined_sub_price_html">' . $sub_price_details_html . '</span>';
					}
				}

				$subs_price_html       = '';
				$subs_details_html_len = count( $subs_details_html );

				foreach ( $subs_details_html as $i => $sub_details_html ) {
					if ( $i === $subs_details_html_len - 1 || ( $i === 0 && ! $has_payment_up_front ) ) {
						if ( $i > 0 || $has_payment_up_front ) {
							$subs_price_html = sprintf( _x( '%1$s, and</br>%2$s', 'subscription price html', 'lafka-plugin' ), $subs_price_html, $sub_details_html );
						} else {
							$subs_price_html = $sub_details_html;
						}
					} else {
						$subs_price_html = sprintf( _x( '%1$s,</br>%2$s', 'subscription price html', 'lafka-plugin' ), $subs_price_html, $sub_details_html );
					}
				}

				if ( $subs_price_html ) {

					if ( $has_payment_up_front ) {
						$price = sprintf( _x( '%1$s<span class="combined_subscriptions_price_html"> one time%2$s</span>', 'subscription price html', 'lafka-plugin' ), $price, $subs_price_html );
					} else {
						$price = '<span class="combined_subscriptions_price_html">' . $subs_price_html . '</span>';
					}

					if ( $is_range && false === strpos( $price, $from_string ) ) {
						$price = sprintf( _x( '%1$s%2$s', 'Price range: from', 'lafka-plugin' ), $from_string, $price );
					}
				}
			}
		}

		return $price;
	}

	/**
	 * Returns range style html price string without min and max.
	 *
	 * @param  mixed  $price
	 * @return string
	 */
	public function get_price_html( $price = '' ) {

		if ( ! $this->is_purchasable() ) {
			/**
			 * 'woocommerce_combo_empty_price_html' filter.
			 *
			 * @param  string             $price_html
			 * @param  WC_Product_Combo  $this
			 */
			return apply_filters( 'woocommerce_combo_empty_price_html', '', $this );
		}

		if ( $this->contains( 'priced_individually' ) ) {

			// Get the price.
			if ( '' === $this->get_combo_price( 'min' ) ) {
				$price = apply_filters( 'woocommerce_combo_empty_price_html', '', $this );
			} else {

				$has_indefinite_max_price = $this->contains( 'configurable_quantities' ) || $this->contains( 'subscriptions_priced_variably' ) || INF === $this->get_max_raw_price();

				/**
				 * 'woocommerce_combo_force_old_style_price_html' filter.
				 *
				 * Used to suppress the range-style display of combo price html strings.
				 *
				 * @param  boolean            $force_suppress_range_format
				 * @param  WC_Product_Combo  $this
				 */
				$suppress_range_price_html = $has_indefinite_max_price || apply_filters( 'woocommerce_combo_force_old_style_price_html', false, $this );

				$price_min = $this->get_combo_price( 'min', true );
				$price_max = $this->get_combo_price( 'max', true );

				if ( $suppress_range_price_html ) {

					$price = wc_price( $price_min );

					$regular_price_min = $this->get_combo_regular_price( 'min', true );

					if ( $regular_price_min !== $price_min ) {

						$regular_price = wc_price( $regular_price_min );

						if ( $price_min !== $price_max ) {
							$price = sprintf( _x( '%1$s%2$s', 'Price range: from', 'lafka-plugin' ), wc_get_price_html_from_text(), wc_format_sale_price( $regular_price, $price ) . $this->get_price_suffix() );
						} else {
							$price = wc_format_sale_price( $regular_price, $price ) . $this->get_price_suffix();
						}

						/**
						 * 'woocommerce_combo_sale_price_html' filter.
						 *
						 * @param  string             $sale_price_html
						 * @param  WC_Product_Combo  $this
						 */
						$price = apply_filters( 'woocommerce_combo_sale_price_html', $price, $this );

					} elseif ( 0.0 === $price_min && 0.0 === $price_max ) {

						$free_string = apply_filters( 'woocommerce_combo_show_free_string', false, $this ) ? __( 'Free!', 'woocommerce' ) : $price;
						$price       = apply_filters( 'woocommerce_combo_free_price_html', $free_string, $this );

					} else {

						if ( $price_min !== $price_max || $has_indefinite_max_price ) {
							$price = sprintf( _x( '%1$s%2$s', 'Price range: from', 'lafka-plugin' ), wc_get_price_html_from_text(), $price . $this->get_price_suffix() );
						} else {
							$price = $price . $this->get_price_suffix();
						}

						/**
						 * 'woocommerce_combo_price_html' filter.
						 *
						 * @param  string             $price_html
						 * @param  WC_Product_Combo  $this
						 */
						$price = apply_filters( 'woocommerce_combo_price_html', $price, $this );
					}

				} else {

					$is_range = false;

					if ( $price_min !== $price_max ) {
						$price    = wc_format_price_range( $price_min, $price_max );
						$is_range = true;
					} else {
						$price = wc_price( $price_min );
					}

					$regular_price_min = $this->get_combo_regular_price( 'min', true );
					$regular_price_max = $this->get_combo_regular_price( 'max', true );

					if ( $regular_price_max !== $price_max || $regular_price_min !== $price_min ) {

						if ( $regular_price_min !== $regular_price_max ) {
							$regular_price = wc_format_price_range( min( $regular_price_min, $regular_price_max ), max( $regular_price_min, $regular_price_max ) );
							$is_range = true;
						} else {
							$regular_price = wc_price( $regular_price_min );
						}

						/** Documented above. */
						$price = apply_filters( 'woocommerce_combo_sale_price_html', wc_format_sale_price( $regular_price, $price ) . $this->get_price_suffix( $is_range ? 'range' : '' ), $this );

					} elseif ( 0.0 === $price_min && 0.0 === $price_max ) {

						$free_string = apply_filters( 'woocommerce_combo_show_free_string', false, $this ) ? __( 'Free!', 'woocommerce' ) : $price;
						$price       = apply_filters( 'woocommerce_combo_free_price_html', $free_string, $this );

					} else {
						/** Documented above. */
						$price = apply_filters( 'woocommerce_combo_price_html', $price . $this->get_price_suffix( $is_range ? 'range' : '' ), $this );
					}
				}
			}

			/**
			 * 'woocommerce_get_combo_price_html' filter.
			 *
			 * @param  string             $price_html
			 * @param  WC_Product_Combo  $this
			 */
			$price = apply_filters( 'woocommerce_get_combo_price_html', $price, $this );

			if ( $this->contains( 'subscriptions_priced_individually' ) ) {
				$price = $this->apply_subs_price_html( $price );
			}

			/** WC core filter. */
			return apply_filters( 'woocommerce_get_price_html', $price, $this );

		} else {

			return parent::get_price_html();
		}
	}

	/**
	 * Availability of combo based on combo-level stock and combined-items-level stock.
	 *
	 * @return array
	 */
	public function get_availability() {

		$availability = parent::get_availability();

		// If a child does not have enough stock, let people know.
		if ( parent::is_in_stock() && 'outofstock' === $this->get_combined_items_stock_status() ) {

			$availability[ 'availability' ] = __( 'Insufficient stock', 'lafka-plugin' );
			$availability[ 'class' ]        = 'out-of-stock';

		// If a child is on backorder, the parent should appear to be on backorder, too.
		} elseif ( parent::is_in_stock() && $this->contains( 'on_backorder' ) ) {

			$availability[ 'availability' ] = __( 'Available on backorder', 'woocommerce' );
			$availability[ 'class' ]        = 'available-on-backorder';

		// Add remaining quantity data if the quantities of the children are static, and at least one child exists that manages stock and displays quantity in the availability string.
		} elseif ( ! $this->contains( 'configurable_quantities' ) && 'no_amount' !== ( $stock_format = get_option( 'woocommerce_stock_format' ) ) && apply_filters( 'woocommerce_combo_display_combined_items_stock_quantity', $this->managing_stock(), $this ) ) {

			$combo_stock_quantity = $this->get_combo_stock_quantity();

			// Only override if not managing stock, or if the container level quantity is higher.
			if ( '' !== $combo_stock_quantity && ( ! $this->managing_stock() || $this->get_stock_quantity() > $combo_stock_quantity ) ) {
				add_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'filter_stock_quantity' ), 1000 );
				$availability[ 'availability' ] = wc_format_stock_for_display( $this );
				remove_filter( 'woocommerce_product_get_stock_quantity', array( $this, 'filter_stock_quantity' ), 1000 );
			}
		}

		return apply_filters( 'woocommerce_get_combo_availability', $availability, $this );
	}

	/**
	 * Get the add to url used mainly in loops.
	 *
	 * @return 	string
	 */
	public function add_to_cart_url() {

		$url = $this->is_purchasable() && $this->is_in_stock() && ! $this->has_options() ? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $this->get_id() ) ) : get_permalink( $this->get_id() );

		/** WC core filter. */
		return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
	}

	/**
	 * Get the add to cart button text.
	 *
	 * @return 	string
	 */
	public function add_to_cart_text() {

		$text = __( 'Read more', 'woocommerce' );

		if ( $this->is_purchasable() && $this->is_in_stock() ) {

			if ( $this->has_options() ) {
				$text =  __( 'Select options', 'woocommerce' );
			} else {
				$text =  __( 'Add to cart', 'woocommerce' );
			}
		}

		/** WC core filter. */
		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Get the add to cart button text for the single page.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {

		$text = __( 'Add to cart', 'woocommerce' );

		if ( isset( $_GET[ 'update-combo' ] ) ) {

			$updating_cart_key = wc_clean( $_GET[ 'update-combo' ] );

			if ( isset( WC()->cart->cart_contents[ $updating_cart_key ] ) ) {
				$text = __( 'Update Cart', 'lafka-plugin' );
			}
		}

		/** WC core filter. */
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', $text, $this );
	}

	/**
	 * Wrapper for get_permalink that adds combo configuration data to the URL.
	 *
	 * @return string
	 */
	public function get_permalink() {

		$permalink     = get_permalink( $this->get_id() );
		$fn_args_count = func_num_args();

		if ( 1 === $fn_args_count ) {

			$cart_item = func_get_arg( 0 );

			if ( is_array( $cart_item ) && isset( $cart_item[ 'stamp' ] ) && is_array( $cart_item[ 'stamp' ] ) ) {

				$config_data = isset( $cart_item[ 'stamp' ] ) ? $cart_item[ 'stamp' ] : array();
				$args        = apply_filters( 'woocommerce_combo_cart_permalink_args', WC_LafkaCombos()->cart->rebuild_posted_combo_form_data( $config_data ), $cart_item, $this );

				// Filter and encode keys and values so this is not broken by add_query_arg.
				$args_data = array_map( 'urlencode', $args );
				$args_keys = array_map( 'urlencode', array_keys( $args ) );

				if ( ! empty( $args ) ) {
					$permalink = add_query_arg( array_combine( $args_keys, $args_data ), $permalink );
				}
			}
		}

		return $permalink;
	}

	/*
	|--------------------------------------------------------------------------
	| CRUD Getters
	|--------------------------------------------------------------------------
	|
	| Methods for getting data from the product object.
	*/

	/**
	 * Min combo size.
	 *
	 * @since  6.6.0
	 *
	 * @param  string  $context
	 * @return int|''
	 */
	public function get_min_combo_size( $context = 'view' ) {

		$value = $this->get_prop( 'min_combo_size', $context );
		$value = '' !== $value ? absint( $value ) : '';

		return $value;
	}

	/**
	 * Max combo size.
	 *
	 * @since  6.6.0
	 *
	 * @param  string  $context
	 * @return int|''
	 */
	public function get_max_combo_size( $context = 'view' ) {

		$value = $this->get_prop( 'max_combo_size', $context );
		$value = '' !== $value ? absint( $value ) : '';

		return $value;
	}

	/**
	 * Cart/order items grouping mode.
	 *
	 * @since  5.5.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_group_mode( $context = 'view' ) {

		$value = $this->get_prop( 'group_mode', $context );

		if ( 'view' === $context ) {
			if ( false === $this->validate_group_mode( $value ) ) {
				$value = 'parent';
			}
		}

		return $value;
	}

	/**
	 * Return the stock sync status.
	 *
	 * @since  6.5.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_combined_items_stock_sync_status( $context = 'edit' ) {
		return $this->get_prop( 'combined_items_stock_sync_status', 'edit' );
	}

	/**
	 * Combo quantity available for purchase, taking combined item stock limitations into account.
	 *
	 * @since  6.5.0
	 *
	 * @param  string  $context
	 * @return int|''
	 */
	public function get_combo_stock_quantity( $context = 'view' ) {

		if ( 'view' === $context && 'unsynced' === $this->get_prop( 'combined_items_stock_sync_status', 'edit' ) ) {
			$this->sync_stock();
		}

		$value = $this->get_prop( 'combo_stock_quantity', $context );
		$value = '' !== $value ? absint( $value ) : '';

		return $value;
	}

	/**
	 * Return the stock status.
	 *
	 * @since  5.5.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_combined_items_stock_status( $context = 'view' ) {

		if ( 'view' === $context && 'unsynced' === $this->get_prop( 'combined_items_stock_sync_status', 'edit' ) ) {
			$this->sync_stock();
		}

		return $this->get_prop( 'combined_items_stock_status', $context );
	}

	/**
	 * Returns the base active price of the combo.
	 *
	 * @since  5.2.0
	 *
	 * @param  string $context
	 * @return mixed
	 */
	public function get_price( $context = 'view' ) {
		$value = $this->get_prop( 'price', $context );
		return in_array( $context, array( 'view', 'sync' ) ) && $this->contains( 'priced_individually' ) ? (float) $value : $value;
	}

	/**
	 * Returns the base regular price of the combo.
	 *
	 * @since  5.2.0
	 *
	 * @param  string $context
	 * @return mixed
	 */
	public function get_regular_price( $context = 'view' ) {
		$value = $this->get_prop( 'regular_price', $context );
		return in_array( $context, array( 'view', 'sync' ) ) && $this->contains( 'priced_individually' ) ? (float) $value : $value;
	}

	/**
	 * Returns the base sale price of the combo.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return mixed
	 */
	public function get_sale_price( $context = 'view' ) {
		$value = $this->get_prop( 'sale_price', $context );
		return in_array( $context, array( 'view', 'sync' ) ) && $this->contains( 'priced_individually' ) && '' !== $value ? (float) $value : $value;
	}

	/**
	 * "Form Location" getter.
	 *
	 * @since  5.7.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_add_to_cart_form_location( $context = 'view' ) {
		return $this->get_prop( 'add_to_cart_form_location', $context );
	}

	/**
	 * "Layout" getter.
	 *
	 * @since  5.0.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_layout( $context = 'any' ) {
		return $this->get_prop( 'layout', $context );
	}

	/**
	 * "Edit in cart" getter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return boolean
	 */
	public function get_editable_in_cart( $context = 'any' ) {
		return $this->get_prop( 'editable_in_cart', $context );
	}

	/**
	 * "Aggregate weight" getter.
	 *
	 * @since  6.0.0
	 *
	 * @param  string  $context
	 * @return boolean
	 */
	public function get_aggregate_weight( $context = 'any' ) {
		return $this->get_prop( 'aggregate_weight', $context );
	}

	/**
	 * "Sold Individually" option context.
	 * Returns 'product' or 'configuration'.
	 *
	 * @since  5.0.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_sold_individually_context( $context = 'any' ) {
		return $this->get_prop( 'sold_individually_context', $context );
	}

	/**
	 * Minimum raw combo price getter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_min_raw_price( $context = 'view' ) {
		if ( 'sync' !== $context ) {
			$this->sync();
		}
		$value = $this->get_prop( 'min_raw_price', $context );
		return in_array( $context, array( 'view', 'sync' ) ) && $this->contains( 'priced_individually' ) && '' !== $value ? (float) $value : $value;
	}

	/**
	 * Minimum raw regular combo price getter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_min_raw_regular_price( $context = 'view' ) {
		if ( 'sync' !== $context ) {
			$this->sync();
		}
		$value = $this->get_prop( 'min_raw_regular_price', $context );
		return in_array( $context, array( 'view', 'sync' ) ) && $this->contains( 'priced_individually' ) && '' !== $value ? (float) $value : $value;
	}

	/**
	 * Maximum raw combo price getter.
	 * INF is 9999999999.0 in 'edit' (DB) context.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_max_raw_price( $context = 'view' ) {
		if ( 'sync' !== $context ) {
			$this->sync();
		}
		$value = $this->get_prop( 'max_raw_price', $context );
		$value = 'edit' !== $context && $this->contains( 'priced_individually' ) && '' !== $value && INF !== $value ? (float) $value : $value;
		$value = 'edit' === $context && INF === $value ? 9999999999.0 : $value;
		return $value;
	}

	/**
	 * Maximum raw regular combo price getter.
	 * INF is 9999999999.0 in 'edit' (DB) context.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 * @return string
	 */
	public function get_max_raw_regular_price( $context = 'view' ) {
		if ( 'sync' !== $context ) {
			$this->sync();
		}
		$value = $this->get_prop( 'max_raw_regular_price', $context );
		$value = 'edit' !== $context && $this->contains( 'priced_individually' ) && '' !== $value && INF !== $value ? (float) $value : $value;
		$value = 'edit' === $context && INF === $value ? 9999999999.0 : $value;
		return $value;
	}

	/**
	 * Returns combined item data objects.
	 *
	 * @since  5.1.0
	 *
	 * @param  string  $context
	 * @return array
	 */
	public function get_combined_data_items( $context = 'view' ) {

		if ( ! is_array( $this->combined_data_items ) ) {

			$use_cache   = ! defined( 'WC_LafkaCombos_DEBUG_OBJECT_CACHE' ) && 'combo' === $this->get_data_store_type() && $this->get_id() && ! $this->has_combined_data_item_changes();
			$cache_key   = WC_Cache_Helper::get_cache_prefix( 'combined_data_items' ) . $this->get_id();
			$cached_data = $use_cache ? wp_cache_get( $cache_key, 'combined_data_items' ) : false;

			if ( false !== $cached_data ) {
				$this->combined_data_items = $cached_data;
			}

			if ( ! is_array( $this->combined_data_items ) ) {

				$this->combined_data_items = array();

				if ( $id = $this->get_id() ) {

					$args = array(
						'combo_id' => $id,
						'return'    => 'objects',
						'order_by'  => array( 'menu_order' => 'ASC' )
					);

					$this->combined_data_items = WC_LafkaCombos_DB::query_combined_items( $args );

					if ( $use_cache ) {
						wp_cache_set( $cache_key, $this->combined_data_items, 'combined_data_items' );
					}
				}
			}
		}

		if ( has_filter( 'woocommerce_combined_data_items' ) ) {
			_deprecated_function( 'The "woocommerce_combined_data_items" filter', '5.5.0', 'the "woocommerce_combined_items" filter' );
		}

		return 'view' === $context ? apply_filters( 'woocommerce_combined_data_items', $this->combined_data_items, $this ) : $this->combined_data_items;
	}

	/**
	 * Returns combined item ids.
	 *
	 * @since  5.0.0
	 *
	 * @param  string  $context
	 * @return array
	 */
	public function get_combined_item_ids( $context = 'view' ) {

		$combined_item_ids = array();

		foreach ( $this->get_combined_data_items( $context ) as $combined_data_item ) {
			$combined_item_ids[] = $combined_data_item->get_id();
		}

		/**
		 * 'woocommerce_combined_item_ids' filter.
		 *
		 * @param  array              $ids
		 * @param  WC_Product_Combo  $this
		 */
		return 'view' === $context ? apply_filters( 'woocommerce_combined_item_ids', $combined_item_ids, $this ) : $combined_item_ids;
	}

	/**
	 * Gets all combined items.
	 *
	 * @param  string  $context
	 * @return array
	 */
	public function get_combined_items( $context = 'view' ) {

		$combined_items       = array();
		$combined_data_items  = $this->get_combined_data_items( $context );
		$combined_product_ids = array();

		foreach ( $combined_data_items as $combined_data_item ) {
			$combined_product_ids[] = $combined_data_item->get_product_id();
		}

		if ( 'combo' === $this->get_data_store_type() ) {
			$this->data_store->preload_combined_product_data( $combined_product_ids );
		}

		foreach ( $combined_data_items as $combined_data_item ) {

			$combined_item = $this->get_combined_item( $combined_data_item, $context );

			if ( $combined_item && $combined_item->exists() ) {

				if ( 'view' === $context && ( 'draft' === $combined_item->get_product()->get_status() ) ) {
					continue;
				}

				$combined_items[ $combined_data_item->get_id() ] = $combined_item;
			}
		}

		/**
		 * 'woocommerce_combined_items' filter.
		 *
		 * @param  array              $combined_items
		 * @param  WC_Product_Combo  $this
		 */
		return 'view' === $context ? apply_filters( 'woocommerce_combined_items', $combined_items, $this ) : $combined_items;
	}

	/**
	 * Checks if a specific combined item exists.
	 *
	 * @param  int     $combined_item_id
	 * @param  string  $context
	 * @return boolean
	 */
	public function has_combined_item( $combined_item_id, $context = 'view' ) {

		if ( 'view' === $context ) {
			$has_combined_item = WC_LafkaCombos_Helpers::cache_get( 'has_combined_item_' . $this->get_id() . '_' . $combined_item_id );
			if ( ! is_null( $has_combined_item ) ) {
				return $has_combined_item;
			}
		}

		$has_combined_item = false;
		$combined_item_ids = $this->get_combined_item_ids( $context );

		if ( in_array( $combined_item_id, $combined_item_ids ) ) {
			$has_combined_item = true;
		}

		WC_LafkaCombos_Helpers::cache_set( 'has_combined_item_' . $this->get_id() . '_' . $combined_item_id, $has_combined_item );

		return $has_combined_item;
	}

	/**
	 * Gets a specific combined item.
	 *
	 * @param  WC_Combined_Item_Data|int  $combined_data_item
	 * @param  string                    $context
	 * @return WC_Combined_Item
	 */
	public function get_combined_item( $combined_data_item, $context = 'view', $hash = array() ) {

		if ( $combined_data_item instanceof WC_Combined_Item_Data ) {
			$combined_item_id = $combined_data_item->get_id();
		} else {
			$combined_item_id = $combined_data_item = absint( $combined_data_item );
		}

		$combined_item = false;

		if ( $this->has_combined_item( $combined_item_id, $context ) ) {

			$cache_group  = 'wc_combined_item_' . $combined_item_id . '_' . $this->get_id();
			$cache_key    = md5( json_encode( apply_filters( 'woocommerce_combined_item_hash', $hash, $this ) ) );

			$combined_item = WC_LafkaCombos_Helpers::cache_get( $cache_key, $cache_group );

			if ( $this->has_combined_data_item_changes() || defined( 'WC_LafkaCombos_DEBUG_RUNTIME_CACHE' ) || null === $combined_item ) {

				$combined_item = new WC_Combined_Item( $combined_data_item, $this );

				WC_LafkaCombos_Helpers::cache_set( $cache_key, $combined_item, $cache_group );
			}
		}

		return $combined_item;
	}

	/*
	|--------------------------------------------------------------------------
	| CRUD Setters
	|--------------------------------------------------------------------------
	|
	| Functions for setting product data. These do not update anything in the
	| database itself and only change what is stored in the class object.
	*/

	/**
	 * Set min combo size.
	 *
	 * @since  6.6.0
	 *
	 * @param  int|''  $quantity
	 */
	public function set_min_combo_size( $min_combo_size ) {
		$this->set_prop( 'min_combo_size', $min_combo_size );
	}

	/**
	 * Set max combo size.
	 *
	 * @since  6.6.0
	 *
	 * @param int|''  $quantity
	 */
	public function set_max_combo_size( $max_combo_size ) {
		$this->set_prop( 'max_combo_size', $max_combo_size );
	}

	/**
	 * Set cart/order items group mode.
	 *
	 * @param string  $mode
	 */
	public function set_group_mode( $mode = '' ) {
		$this->set_prop( 'group_mode', in_array( $mode, array_keys( self::get_group_mode_options() ) ) ? $mode : 'parent' );
	}

	/**
	 * Set stock sync status.
	 *
	 * @param string  $status
	 */
	public function set_combined_items_stock_sync_status( $status = '' ) {
		$this->set_prop( 'combined_items_stock_sync_status', in_array( $status, array( 'synced', 'unsynced' ) ) ? $status : 'unsynced' );
	}

	/**
	 * Set combo stock quantity.
	 * Quantity available for purchase, taking combined item stock limitations into account.
	 *
	 * @param int|''  $quantity
	 */
	public function set_combo_stock_quantity( $quantity ) {
		$this->set_prop( 'combo_stock_quantity', $quantity );
	}

	/**
	 * Set stock status.
	 *
	 * @param string  $status
	 */
	public function set_combined_items_stock_status( $status = '' ) {
		$this->set_prop( 'combined_items_stock_status', in_array( $status, array( 'instock', 'outofstock' ) ) ? $status : 'instock' );
	}

	/**
	 * "Form Location" setter.
	 *
	 * @since  5.7.0
	 *
	 * @param  string  $value
	 */
	public function	set_add_to_cart_form_location( $value ) {
		$value = in_array( $value, array_keys( self::get_add_to_cart_form_location_options() ) ) ? $value : 'default';
		return $this->set_prop( 'add_to_cart_form_location', $value );
	}

	/**
	 * "Layout" setter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $layout
	 */
	public function set_layout( $layout ) {
		$layout = array_key_exists( $layout, self::get_layout_options() ) ? $layout : 'default';
		$this->set_prop( 'layout', $layout );
	}

	/**
	 * "Edit in cart" setter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $editable_in_cart
	 */
	public function set_editable_in_cart( $editable_in_cart ) {

		$editable_in_cart = wc_string_to_bool( $editable_in_cart );
		$this->set_prop( 'editable_in_cart', $editable_in_cart );

		if ( $editable_in_cart ) {
			if ( ! in_array( 'edit_in_cart', $this->supports ) ) {
				$this->supports[] = 'edit_in_cart';
			}
		} else {
			foreach ( $this->supports as $key => $value ) {
				if ( 'edit_in_cart' === $value ) {
					unset( $this->supports[ $key ] );
				}
			}
		}
	}

	/**
	 * "Aggregate weight" setter.
	 *
	 * @since  6.0.0
	 *
	 * @param  string  $aggregate_weight
	 */
	public function set_aggregate_weight( $aggregate_weight ) {
		$aggregate_weight = wc_string_to_bool( $aggregate_weight );
		$this->set_prop( 'aggregate_weight', $aggregate_weight );
	}

	/**
	 * "Sold individually" context setter.
	 *
	 * @since  5.2.0
	 *
	 * @param  string  $context
	 */
	public function set_sold_individually_context( $context ) {
		$context = in_array( $context, array( 'product', 'configuration' ) ) ? $context : 'product';
		$this->set_prop( 'sold_individually_context', $context );
	}

	/**
	 * Minimum raw combo price setter.
	 *
	 * @since  5.2.0
	 *
	 * @param  mixed  $value
	 */
	public function set_min_raw_price( $value ) {
		$value = wc_format_decimal( $value );
		$this->set_prop( 'min_raw_price', $value );
	}

	/**
	 * Minimum raw regular combo price setter.
	 *
	 * @since  5.2.0
	 *
	 * @param  mixed  $value
	 */
	public function set_min_raw_regular_price( $value ) {
		$value = wc_format_decimal( $value );
		$this->set_prop( 'min_raw_regular_price', $value );
	}

	/**
	 * Maximum raw combo price setter.
	 * Convert 9999999999.0 to INF.
	 *
	 * @since  5.2.0
	 *
	 * @param  mixed  $value
	 */
	public function set_max_raw_price( $value ) {
		$value = INF !== $value ? wc_format_decimal( $value ) : INF;
		$value = 9999999999.0 === (float) $value ? INF : $value;
		$this->set_prop( 'max_raw_price', $value );
	}

	/**
	 * Maximum raw regular combo price setter.
	 * Convert 9999999999.0 to INF.
	 *
	 * @since  5.2.0
	 *
	 * @param  mixed  $value
	 */
	public function set_max_raw_regular_price( $value ) {
		$value = INF !== $value ? wc_format_decimal( $value ) : INF;
		$value = 9999999999.0 === (float) $value ? INF : $value;
		$this->set_prop( 'max_raw_regular_price', $value );
	}

	/**
	 * Sets combined item data objects.
	 * Expects each data element in array format - @see 'WC_Combined_Item_Data::get_data()'.
	 * Until 'save_items' is called, all items get a temporary index-based ID (unit-testing only!).
	 *
	 * @since  5.2.0
	 *
	 * @param  array  $data
	 */
	public function set_combined_data_items( $data ) {

		if ( is_array( $data ) ) {

			$existing_item_ids = array();
			$update_item_ids   = array();

			$combined_data_items = $this->get_combined_data_items( 'edit' );

			// Get real IDs.
			if ( ! empty( $combined_data_items ) ) {
				if ( $this->has_combined_data_item_changes() ) {
					foreach ( $this->combined_data_items as $combined_data_item_key => $combined_data_item ) {
						$existing_item_ids[] = $combined_data_item->get_meta( 'real_id' );
					}
				} else {
					foreach ( $this->combined_data_items as $combined_data_item_key => $combined_data_item ) {
						$existing_item_ids[] = $combined_data_item->get_id();
						$combined_data_item->update_meta( 'real_id', $combined_data_item->get_id() );
					}
				}
			}

			// Find existing IDs to update.
			if ( ! empty( $data ) ) {
				foreach ( $data as $item_key => $item_data ) {
					// Ignore items without a valid combined product ID.
					if ( empty( $item_data[ 'product_id' ] ) ) {
						unset( $data[ $item_key ] );
					// If an item with the same ID exists, modify it.
					} elseif ( isset( $item_data[ 'combined_item_id' ] ) && $item_data[ 'combined_item_id' ] > 0 && in_array( $item_data[ 'combined_item_id' ], $existing_item_ids ) ) {
						$update_item_ids[] = $item_data[ 'combined_item_id' ];
					// Otherwise, add a new one that will be created after saving.
					} else {
						$data[ $item_key ][ 'combined_item_id' ] = 0;
					}
				}
			}

			// Find existing IDs to remove.
			$remove_item_ids = array_diff( $existing_item_ids, $update_item_ids );

			// Remove items and delete them later.
			if ( ! empty( $this->combined_data_items ) ) {
				foreach ( $this->combined_data_items as $combined_data_item_key => $combined_data_item ) {

					$real_item_id = $this->has_combined_data_item_changes() ? $combined_data_item->get_meta( 'real_id' ) : $combined_data_item->get_id();

					if ( in_array( $real_item_id, $remove_item_ids ) ) {

						unset( $this->combined_data_items[ $combined_data_item_key ] );
						// Put item in the delete queue if saved in the DB.
						if ( $real_item_id > 0 ) {
							// Put back real ID.
							$combined_data_item->set_id( $real_item_id );
							$this->combined_data_items_delete_queue[] = $combined_data_item;
						}
					}
				}
			}

			// Modify/add items.
			if ( ! empty( $data ) ) {
				foreach ( $data as $item_data ) {

					$item_data[ 'combo_id' ] = $this->get_id();

					// Modify existing item.
					if ( in_array( $item_data[ 'combined_item_id' ], $update_item_ids ) ) {

						foreach ( $this->combined_data_items as $combined_data_item_key => $combined_data_item ) {

							$real_item_id = $this->has_combined_data_item_changes() ? $combined_data_item->get_meta( 'real_id' ) : $combined_data_item->get_id();

							if ( $item_data[ 'combined_item_id' ] === $real_item_id ) {
								$combined_data_item->set_all( $item_data );
							}
						}

					// Add new item.
					} else {
						$new_item = new WC_Combined_Item_Data( $item_data );
						$new_item->update_meta( 'real_id', 0 );
						$this->combined_data_items[] = $new_item;
					}
				}
			}

			// Modify all item IDs to temp values until saved.
			$temp_id = 0;
			if ( ! empty( $this->combined_data_items ) ) {
				foreach ( $this->combined_data_items as $combined_data_item_key => $combined_data_item ) {
					$temp_id++;
					$combined_data_item->set_id( $temp_id );
				}
			}

			$this->combined_data_items_save_pending = true;
			$this->load_defaults();
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Conditionals
	|--------------------------------------------------------------------------
	*/

	/**
	 * Equivalent of 'get_changes', but boolean and for combined data items only.
	 *
	 * @since  6.3.2
	 *
	 * @return boolean
	 */
	public function has_combined_data_item_changes() {
		return $this->combined_data_items_save_pending;
	}

	/**
	 * Getter of combo 'contains' properties.
	 *
	 * @since  5.0.0
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function contains( $key ) {

		if ( 'subscription' === $key ) {
			$key = 'subscriptions';
		}

		// Prevent infinite loops in some edge cases.
		if ( ! $this->is_synced() && 'subscriptions' === $key ) {

			$contains = false;

			if ( $combined_items = $this->get_combined_items() ) {

				// Scan combined items and sync combo properties.
				foreach ( $combined_items as $combined_item ) {
					if ( $combined_item->is_subscription() ) {
						$contains = true;
						break;
					}
				}
			}

			return $contains;
		}

		if ( 'priced_individually' === $key ) {

			if ( is_null( $this->contains[ $key ] ) ) {

				$priced_items_exist = false;

				// Any items priced individually?
				$combined_data_items = $this->get_combined_data_items();

				if ( ! empty( $combined_data_items ) ) {
					foreach ( $combined_data_items as $combined_data_item ) {
						if ( 'yes' === $combined_data_item->get_meta( 'priced_individually' ) ) {
							$priced_items_exist = true;
							break;
						}
					}
				}

				/**
				 * 'woocommerce_combo_contains_priced_items' filter.
				 *
				 * @param  boolean            $priced_items_exist
				 * @param  WC_Product_Combo  $this
				 */
				$this->contains[ 'priced_individually' ] = apply_filters( 'woocommerce_combo_contains_priced_items', $priced_items_exist, $this );
			}

		} elseif ( 'shipped_individually' === $key ) {

			if ( is_null( $this->contains[ $key ] ) ) {

				$shipped_items_exist = false;

				// Any items shipped individually?
				$combined_data_items = $this->get_combined_data_items();

				if ( ! empty( $combined_data_items ) ) {
					foreach ( $combined_data_items as $combined_data_item ) {
						if ( 'yes' === $combined_data_item->get_meta( 'shipped_individually' ) ) {
							$shipped_items_exist = true;
							break;
						}
					}
				}

				/**
				 * 'woocommerce_combo_contains_shipped_items' filter.
				 *
				 * @param  boolean            $shipped_items_exist
				 * @param  WC_Product_Combo  $this
				 */
				$this->contains[ 'shipped_individually' ] = apply_filters( 'woocommerce_combo_contains_shipped_items', $shipped_items_exist, $this );
			}

		} elseif ( 'assembled' === $key ) {

			if ( is_null( $this->contains[ $key ] ) ) {

				$assembled_items_exist = false;

				if ( false === $this->get_virtual( 'edit' ) ) {

					// Any items assembled?
					$combined_data_items = $this->get_combined_data_items();

					if ( ! empty( $combined_data_items ) ) {
						foreach ( $combined_data_items as $combined_data_item ) {
							if ( 'no' === $combined_data_item->get_meta( 'shipped_individually' ) ) {
								$assembled_items_exist = true;
								break;
							}
						}
					}
				}

				/**
				 * 'woocommerce_combo_contains_shipped_items' filter.
				 *
				 * @param  boolean            $assembled_items_exist
				 * @param  WC_Product_Combo  $this
				 */
				$this->contains[ 'assembled' ] = apply_filters( 'woocommerce_combo_contains_assembled_items', $assembled_items_exist, $this );
			}

		} else {
			$this->sync();
		}

		// Back-compat.
		if ( 'priced_indefinitely' === $key ) {
			return $this->contains[ 'configurable_quantities' ] || $this->contains[ 'subscriptions_priced_variably' ];
		}

		return isset( $this->contains[ $key ] ) ? $this->contains[ $key ] : null;
	}

	/**
	 * Indicates if the combo props are in sync with combined items.
	 *
	 * @return boolean
	 */
	public function is_synced() {
		return $this->is_synced;
	}

	/**
	 * Whether this instance is currently syncing.
	 *
	 * @since  6.2.5
	 *
	 * @return boolean
	 */
	public function is_syncing() {
		return $this->is_syncing;
	}

	/**
	 * A combo is purchasable if it contains (purchasable) combined items.
	 *
	 * @return boolean
	 */
	public function is_purchasable() {

		$purchasable = true;

		// Not purchasable while updating DB.
		if ( defined( 'WC_LafkaCombos_UPDATING' ) ) {
			$purchasable = false;
		// Products must exist of course.
		} if ( ! $this->exists() ) {
			$purchasable = false;
		// When priced statically a price needs to be set.
		} elseif ( false === $this->contains( 'priced_individually' ) && '' === $this->get_price() ) {
			$purchasable = false;
		// Check the product is published.
		} elseif ( 'publish' !== $this->get_status() && ! current_user_can( 'edit_post', $this->get_id() ) ) {
			$purchasable = false;
		// Check if the product contains anything.
		} elseif ( 0 === sizeof( $this->get_combined_data_items() ) ) {
			$purchasable = false;
		// Check if all non-optional contents are purchasable.
		} elseif ( $this->contains( 'non_purchasable' ) ) {
			$purchasable = false;
		// Only purchasable if "Mixed Checkout" is enabled for WCS.
		} elseif ( $this->contains( 'subscriptions' ) && class_exists( 'WC_Subscriptions_Admin' ) && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
			$purchasable = false;
		}

		/** WC core filter. */
		return apply_filters( 'woocommerce_is_purchasable', $purchasable, $this );
	}

	/**
	 * Override on_sale status of product combos. If a combined item is on sale or has a discount applied, then the combo appears as on sale.
	 *
	 * @param  string  $context
	 * @return boolean
	 */
	public function is_on_sale( $context = 'view' ) {

		$is_on_sale = false;

		if ( 'update-price' !== $context && $this->contains( 'priced_individually' ) && 'cart' !== $this->get_object_context() ) {
			$is_on_sale = parent::is_on_sale( $context ) || ( $this->contains( 'discounted_mandatory' ) && $this->get_min_raw_regular_price( $context ) > 0 );
		} else {
			$is_on_sale = parent::is_on_sale( $context );
		}

		/**
		 * 'woocommerce_product_is_on_sale' filter.
		 *
		 * @param  boolean            $is_on_sale
		 * @param  WC_Product_Combo  $this
		 */
		return 'view' === $context ? apply_filters( 'woocommerce_product_is_on_sale', $is_on_sale, $this ) : $is_on_sale;
	}

	/**
	 * Sets Combo object instance context.
	 *
	 * @since 5.13.0
	 *
	 * @param string $context
	 */
	public function set_object_context( $context ) {
		$this->object_context = $context;
	}

	/**
	 * Retrieves Combo object instance context.
	 *
	 * @since 5.13.0
	 *
	 * @return string
	 */
	public function get_object_context() {
		return $this->object_context;
	}

	/**
	 * True if the product container is in stock.
	 *
	 * @return boolean
	 */
	public function is_parent_in_stock() {
		return parent::is_in_stock();
	}

	/**
	 * True if the product is in stock and all combined items are in stock.
	 *
	 * @return boolean
	 */
	public function is_in_stock() {

		$is_in_stock = parent::is_in_stock() && 'instock' === $this->get_combined_items_stock_status();

		return apply_filters( 'woocommerce_combo_is_in_stock', $is_in_stock, $this );
	}

	/**
	 * Returns whether or not the product is visible in the catalog.
	 *
	 * @return boolean
	 */
	public function is_visible() {

		$visible = 'visible' === $this->get_catalog_visibility() || ( is_search() && 'search' === $this->get_catalog_visibility() ) || ( ! is_search() && 'catalog' === $this->get_catalog_visibility() );

		if ( 'trash' === $this->get_status() ) {
			$visible = false;
		} elseif ( 'publish' !== $this->get_status() && ! current_user_can( 'edit_post', $this->get_id() ) ) {
			$visible = false;
		}

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $this->is_parent_in_stock() ) {
			$visible = false;
		}

		return apply_filters( 'woocommerce_product_is_visible', $visible, $this->get_id() );
	}

	/**
	 * A combo appears "on backorder" if the container is on backorder, or if a combined item is on backorder (and requires notification).
	 *
	 * @return boolean
	 */
	public function is_on_backorder( $qty_in_cart = 0 ) {
		return parent::is_on_backorder() || $this->contains( 'on_backorder' );
	}

	/**
	 * Combo is a NYP product.
	 *
	 * @return boolean
	 */
	public function is_nyp() {

		if ( ! isset( $this->is_nyp ) ) {
			$this->is_nyp = WC_LafkaCombos()->compatibility->is_nyp( $this );
		}

		return $this->is_nyp;
	}

	/**
	 * Indicates whether the product configuration can be edited in the cart.
	 * Optionally pass a cart item array to check.
	 *
	 * @param  array   $cart_item
	 * @return boolean
	 */
	public function is_editable_in_cart( $cart_item = false ) {
		/**
		 * 'woocommerce_combo_is_editable_in_cart' filter.
		 *
		 * @param  boolean            $is
		 * @param  WC_Product_Combo  $this
		 * @param  array              $cart_item
		 */
		return apply_filters( 'woocommerce_combo_is_editable_in_cart', method_exists( $this, 'supports' ) && $this->supports( 'edit_in_cart' ) && $this->is_in_stock(), $this, $cart_item );
	}

	/**
	 * A combo on backorder requires notification if the container is defined like this, or a combined item is on backorder and requires notification.
	 *
	 * @return boolean
	 */
	public function backorders_require_notification() {
		return parent::backorders_require_notification() || $this->contains( 'on_backorder' );
	}

	/**
	 * Returns whether or not the combo has any attributes set. Takes into account the attributes of all combined products.
	 *
	 * @return boolean
	 */
	public function has_attributes() {

		$has_attributes = false;

		// Check combo for attributes.
		if ( parent::has_attributes() ) {

			$has_attributes = true;

		// Check all combined products for attributes.
		} else {

			$combined_items = $this->get_combined_items();

			if ( ! empty( $combined_items ) ) {

				foreach ( $combined_items as $combined_item ) {

					/**
					 * 'woocommerce_combo_show_combined_product_attributes' filter.
					 *
					 * @param  boolean            $show_attributes
					 * @param  WC_Product_Combo  $this
					 */
					$show_combined_product_attributes = apply_filters( 'woocommerce_combo_show_combined_product_attributes', $combined_item->is_visible(), $this, $combined_item );

					if ( ! $show_combined_product_attributes ) {
						continue;
					}

					$combined_product = $combined_item->product;

					if ( $combined_product->has_attributes() ) {
						$has_attributes = true;
						break;
					}
				}
			}
		}

		return $has_attributes;
	}

	/**
	 * A combo requires user input if: ( is nyp ) or ( has required addons ) or ( has items with variables ).
	 *
	 * @return boolean
	 */
	public function requires_input() {

		$requires_input = false;

		if ( $this->is_nyp || $this->contains( 'options' ) ) {
			$requires_input = true;
		}

		/**
		 * 'woocommerce_combo_requires_input' filter.
		 *
		 * @param  boolean            $requires_input
		 * @param  WC_Product_Combo  $this
		 */
		return apply_filters( 'woocommerce_combo_requires_input', $requires_input, $this );
	}

	/**
	 * Returns whether or not the product has additional options that must be selected before adding to cart.
	 *
	 * @since  5.12.0
	 *
	 * @return boolean
	 */
	public function has_options() {
		return $this->requires_input();
	}

	/*
	|--------------------------------------------------------------------------
	| Other CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validate props before saving.
	 *
	 * @since 5.5.0
	 */
	public function validate_props() {

		parent::validate_props();

		if ( false === $this->validate_group_mode() ) {
			$this->set_group_mode( 'parent' );
		}

		if ( $this->get_min_combo_size( 'edit' ) > 0 && $this->get_max_combo_size( 'edit' ) > 0 && $this->get_min_combo_size( 'edit' ) > $this->get_max_combo_size( 'edit' ) ) {
			$this->set_max_combo_size( $this->get_min_combo_size( 'edit' ) );
		}
	}

	/**
	 * Validate Group Mode before saving.
	 *
	 * @since 5.5.0
	 */
	public function validate_group_mode( $group_mode = null ) {

		$is_valid   = true;
		$group_mode = is_null( $group_mode ) ? $this->get_group_mode( 'edit' ) : $group_mode;

		if ( false === self::group_mode_has( $group_mode, 'parent_item' ) ) {
			if ( false === $this->get_virtual( 'edit' ) || $this->get_regular_price( 'edit' ) > 0 || $this->contains( 'assembled' ) ) {
				$is_valid = false;
			}
		}

		return $is_valid;
	}

	/**
	 * Alias for 'set_props'.
	 *
	 * @since 5.2.0
	 */
	public function set( $properties ) {
		return $this->set_props( $properties );
	}

	/**
	 * Override 'save' to handle combined items saving.
	 *
	 * @since 5.2.0
	 */
	public function save() {

		// Save combo props.
		if ( $this->get_type() === $this->get_data_store_type() && parent::save() ) {
			// Save combined items.
			$this->save_items();
			// Save combo props that depend on items.
			$this->sync( true );
		}

		return $this->get_id();
	}

	/**
	 * Saves combined data items.
	 *
	 * @since 5.2.0
	 */
	public function save_items() {

		if ( $this->has_combined_data_item_changes() ) {

			foreach ( $this->combined_data_items_delete_queue as $item ) {
				$item->delete();
			}

			$combined_data_items = $this->get_combined_data_items( 'edit' );

			if ( ! empty( $combined_data_items ) ) {

				foreach ( $combined_data_items as $item ) {

					// Update.
					if ( $real_id = $item->get_meta( 'real_id' ) ) {
						$item->set_id( $real_id );
					// Create.
					} else {
						$item->set_id( 0 );
					}

					// Update combo ID.
					$item->set_combo_id( $this->get_id() );

					$item->delete_meta( 'real_id' );
					$item->save();
					$item->update_meta( 'real_id', $item->get_id() );

					// Delete runtime cache.
					WC_LafkaCombos_Helpers::cache_invalidate( 'wc_combined_item_' . $item->get_id() . '_' . $this->get_id() );
				}

			} else {
				$this->set_status( 'draft' );
				parent::save();
			}

			$this->combined_data_items_save_pending = false;
			$this->load_defaults();
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Callbacks.
	|--------------------------------------------------------------------------
	*/

	public function filter_stock_quantity( $qty ) {
		return $this->get_combo_stock_quantity();
	}

	/*
	|--------------------------------------------------------------------------
	| Static methods.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Supported "Form Location" options.
	 *
	 * @since  5.7.0
	 *
	 * @return array
	 */
	public static function get_add_to_cart_form_location_options() {

		$options = array(
			'default'      => array(
				'title'       => __( 'Default', 'lafka-plugin' ),
				'description' => __( 'The add-to-cart form is displayed inside the single-product summary.', 'lafka-plugin' )
			),
			'after_summary' => array(
				'title'       => __( 'Before Tabs', 'lafka-plugin' ),
				'description' => __( 'The add-to-cart form is displayed before the single-product tabs. Usually allocates the entire page width for displaying form content. Note that some themes may not support this option.', 'lafka-plugin' )
			)
		);

		return apply_filters( 'woocommerce_combo_add_to_cart_form_location_options', $options );
	}

	/**
	 * Supported layouts.
	 *
	 * @return array
	 */
	public static function get_layout_options() {
		if ( is_null( self::$layout_options_data ) ) {
			self::$layout_options_data = apply_filters( 'woocommerce_combos_supported_layouts', array(
				'default' => __( 'Standard', 'lafka-plugin' ),
				'tabular' => __( 'Tabular', 'lafka-plugin' ),
				'grid'    => __( 'Grid', 'lafka-plugin' )
			) );
		}
		return self::$layout_options_data;
	}

	/**
	 * Supported group modes.
	 *
	 * @param  boolean  $visible
	 * @return array
	 */
	public static function get_group_mode_options( $visible = false ) {
		$group_mode_options_data = self::get_group_mode_options_data();
		$group_mode_options_data = $visible ? array_filter( $group_mode_options_data, array( __CLASS__, 'filter_invisible_group_modes' ) ) : $group_mode_options_data;
		return array_combine( array_keys( $group_mode_options_data ), wp_list_pluck( $group_mode_options_data, 'title' ) );
	}

	/**
	 * Filters-out invisible group modes.
	 *
	 * @param  array  $group_mode_data
	 * @return boolean
	 */
	private static function filter_invisible_group_modes( $group_mode_data ) {
		return ! isset( $group_mode_data[ 'is_visible' ] ) || $group_mode_data[ 'is_visible' ];
	}

	/**
	 * Indicates whether a specific feature is supported by a group mode.
	 *
	 * @param  string     $group_mode
	 * @param  string     $feature
	 * @param  int|false  $combined_item_id
	 * @return bool
	 */
	public static function group_mode_has( $group_mode, $feature ) {

		$group_mode_options_data = self::get_group_mode_options_data();
		$group_mode_features     = isset( $group_mode_options_data[ $group_mode ][ 'features' ] ) ? $group_mode_options_data[ $group_mode ][ 'features' ] : false;

		return is_array( $group_mode_features ) && in_array( $feature, $group_mode_features );
	}

	/**
	 * Group mode data. Details:
	 *
	 * - 'parent_item':                  Container/parent line item visible in cart/order templates.
	 * - 'child_item_indent':            Combined/child line items indented in cart/order templates.
	 * - 'aggregated_prices':            Combined/child cart item prices are aggregated into their container/parent.
	 * - 'aggregated_subtotals':         Combined/child cart/order item subtotals are aggregated into their container/parent.
	 * - 'child_item_meta':              "Part of" meta appended to combined/child cart/order line items.
	 * - 'parent_cart_widget_item_meta': "Includes" meta appended to container/parent cart widget line items.
	 * - 'parent_cart_item_meta':        "Includes" meta appended to container/parent cart line items.
	 * - 'component_multiselect':        Replaces the parent title with configuration details in all applicable templates.
	 * - 'faked_parent_item':            First combined/child line item acting as container/parent.
	 *
	 * Using the first child as a "fake" container:
	 *
	 * 'child'    => array(
	 *		'title'    => __( 'First child', 'lafka-plugin' ),
	 *		'features' => array( 'faked_parent_item', 'child_item_indent' )
	 *	)
	 *
	 * @return array
	 */
	private static function get_group_mode_options_data() {

		if ( is_null( self::$group_mode_options_data ) ) {

			self::$group_mode_options_data = apply_filters( 'woocommerce_combos_group_mode_options_data', array(
				'parent'   => array(
					'title'      => __( 'Grouped', 'lafka-plugin' ),
					'features'   => array( 'parent_item', 'child_item_indent', 'aggregated_prices', 'aggregated_subtotals', 'parent_cart_widget_item_meta' ),
					'is_visible' => true
				),
				'noindent' => array(
					'title'      => __( 'Flat', 'lafka-plugin' ),
					'features'   => array( 'parent_item', 'child_item_meta' ),
					'is_visible' => true
				),
				'none'     => array(
					'title'      => __( 'None', 'lafka-plugin' ),
					'features'   => array( 'child_item_meta' ),
					'is_visible' => true
				)
			) );
		}

		return self::$group_mode_options_data;
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated methods.
	|--------------------------------------------------------------------------
	*/

	public function sync_combined_items_stock_status() {
		_deprecated_function( __METHOD__ . '()', '6.5.0', __CLASS__ . '::sync_stock()' );
		return $this->sync_stock();
	}
	public function get_combo_price_data() {
		_deprecated_function( __METHOD__ . '()', '6.4.0', __CLASS__ . '::get_combo_form_data()' );
		return $this->get_combo_form_data();
	}
	public static function get_supported_layout_options() {
		_deprecated_function( __METHOD__ . '()', '5.5.0', __CLASS__ . '::get_layout_options()' );
		return self::get_layout_options();
	}
	public function maybe_sync_combo() {
		_deprecated_function( __METHOD__ . '()', '5.5.0', __CLASS__ . '::sync()' );
		$this->sync();
	}
	public function sync_combo() {
		_deprecated_function( __METHOD__ . '()', '5.5.0', __CLASS__ . '::sync( true )' );
		$this->sync( true );
	}
	public function get_combined_item_quantities( $context = 'reference', $min_or_max = '' ) {
		_deprecated_function( __METHOD__ . '()', '5.5.0', 'WC_Combined_Item::get_quantity()' );

		$combined_item_quantities = array(
			'reference' => array(
				'min' => array(),
				'max' => array()
			),
			'optimal'   => array(
				'min' => array(),
				'max' => array()
			),
			'worst'     => array(
				'min' => array(),
				'max' => array()
			),
			'required'  => array(
				'min' => array(),
				'max' => array()
			)
		);

		foreach ( $this->get_combined_items() as $combined_item ) {

			$min_qty = $combined_item->is_optional() ? 0 : $combined_item->get_quantity( 'min' );
			$max_qty = $combined_item->get_quantity( 'max' );

			$combined_item_quantities[ 'reference' ][ 'min' ][ $combined_item->get_id() ] = $min_qty;
			$combined_item_quantities[ 'reference' ][ 'max' ][ $combined_item->get_id() ] = $max_qty;
		}

		$combined_item_quantities[ 'optimal' ]  = apply_filters( 'woocommerce_combined_item_optimal_price_quantities', $combined_item_quantities[ 'reference' ], $this );
		$combined_item_quantities[ 'worst' ]    = apply_filters( 'woocommerce_combined_item_worst_price_quantities', $combined_item_quantities[ 'reference' ], $this );
		$combined_item_quantities[ 'required' ] = apply_filters( 'woocommerce_combined_item_required_quantities', $combined_item_quantities[ 'reference' ], $this );

		return '' === $min_or_max ? $combined_item_quantities[ $context ] : $combined_item_quantities[ $context ][ $min_or_max ];
	}
	public function get_combo_variation_attributes() {
		_deprecated_function( __METHOD__ . '()', '5.2.0', 'WC_Combined_Item::get_product_variation_attributes()' );

		$this->sync();

		$combined_items = $this->get_combined_items();

		if ( empty( $combined_items ) ) {
			return array();
		}

		$combo_attributes = array();

		foreach ( $combined_items as $combined_item ) {
			$combo_attributes[ $combined_item->get_id() ] = $combined_item->get_product_variation_attributes();
		}

		return $combo_attributes;
	}
	public function get_selected_combo_variation_attributes() {
		_deprecated_function( __METHOD__ . '()', '5.2.0', 'WC_Combined_Item::get_selected_product_variation_attributes()' );

		$this->sync();

		$combined_items = $this->get_combined_items();

		if ( empty( $combined_items ) ) {
			return array();
		}

		$seleted_combo_attributes = array();

		foreach ( $combined_items as $combined_item ) {
			$seleted_combo_attributes[ $combined_item->get_id() ] = $combined_item->get_selected_product_variation_attributes();
		}

		return $seleted_combo_attributes;
	}
	public function get_available_combo_variations() {
		_deprecated_function( __METHOD__ . '()', '5.2.0', 'WC_Combined_Item::get_product_variations()' );

		$this->sync();

		$combined_items = $this->get_combined_items();

		if ( empty( $combined_items ) ) {
			return array();
		}

		$combo_variations = array();

		foreach ( $combined_items as $combined_item ) {
			$combo_variations[ $combined_item->get_id() ] = $combined_item->get_product_variations();
		}

		return $combo_variations;
	}
	public function get_base_price() {
		_deprecated_function( __METHOD__ . '()', '5.1.0', __CLASS__ . '::get_price()' );
		return $this->get_price( 'edit' );
	}
	public function get_base_regular_price() {
		_deprecated_function( __METHOD__ . '()', '5.1.0', __CLASS__ . '::get_regular_price()' );
		return $this->get_regular_price( 'edit' );
	}
	public function get_base_sale_price() {
		_deprecated_function( __METHOD__ . '()', '5.1.0', __CLASS__ . '::get_sale_price()' );
		return $this->get_sale_price( 'edit' );
	}
	public function is_priced_per_product() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		return $this->contains( 'priced_individually' );
	}
	public function is_shipped_per_product() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		return $this->contains( 'shipped_individually' );
	}
	public function all_items_in_stock() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		return 'instock' === $this->get_combined_items_stock_status();
	}
	public function contains_sub() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		return $this->contains( 'subscriptions' );
	}
	public function contains_nyp() {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		return $this->contains( 'nyp' );
	}
	public function contains_optional( $exclusively = false ) {
		_deprecated_function( __METHOD__ . '()', '5.0.0', __CLASS__ . '::contains()' );
		if ( $exclusively ) {
			return false === $this->contains( 'mandatory' ) && $this->contains( 'optional' );
		}
		return $this->contains( 'optional' );
	}
}
