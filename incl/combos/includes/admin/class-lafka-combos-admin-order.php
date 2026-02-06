<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Combos edit-order functions and filters.
 *
 * @class    WC_LafkaCombos_Admin_Order
 * @version  6.1.5
 */
class WC_LafkaCombos_Admin_Order {

	/**
	 * Order object to use in 'display_edit_button'.
	 * @var WC_Order
	 */
	protected static $order;

	/**
	 * Setup Admin class.
	 */
	public static function init() {

		// Auto-populate combined order-items for Combos that don't require configuration.
		add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'add_combined_items' ), 10, 3 );

		// Save order object to use in 'display_edit_button'.
		add_action( 'woocommerce_admin_order_item_headers', array( __CLASS__, 'set_order' ) );

		// Display "Configure/Edit" button next to configurable combo container items in the edit-order screen.
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'display_edit_button' ), 10, 3 );

		// Add JS template.
		add_action( 'admin_footer', array( __CLASS__, 'add_js_template' ) );
	}

	/**
	 * Whether a combo is configurable in admin-order context.
	 *
	 * If a combined item:
	 *
	 * - is optional;
	 * - is variable and has attributes that require user input;
	 * - has configurable quantities,
	 *
	 * then the combo is configurable.
	 *
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	public static function is_combo_configurable( $combo ) {

		$is_configurable = false;
		$combined_items   = $combo->get_combined_items();

		foreach ( $combined_items as $combined_item ) {

			if ( $combined_item->is_optional() ) {
				$is_configurable = true;
			} elseif ( $combined_item->get_quantity( 'min' ) !== $combined_item->get_quantity( 'max' ) ) {
				$is_configurable = true;
			} elseif ( ( $configurable_attributes = $combined_item->get_product_variation_attributes( true ) ) && sizeof( $configurable_attributes ) > 0 ) {
				$is_configurable = true;
			}

			if ( $is_configurable ) {
				break;
			}
		}

		return $is_configurable;
	}

	/*
	|--------------------------------------------------------------------------
	| Filter hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Auto-populate combined order-items for Combos that don't require configuration.
	 *
	 * @param  $item_id  int
	 * @param  $item     WC_Order_Item
	 * @param  $order    WC_Order
	 * @return void
	 */
	public static function add_combined_items( $item_id, $item, $order ) {

		if ( 'line_item' === $item->get_type() ) {

			$product = $item->get_product();

			if ( $product && $product->is_type( 'combo' ) ) {

				/**
				 * 'woocommerce_auto_add_combined_items' filter.
				 *
				 * In some cases you might want to auto-add a default configuration that's "good enough" and work from there, e.g. adjust quantities or remove items.
				 *
				 * @param  $auto_add  boolean
				 * @param  $product   WC_Product_Combo
				 * @param  $item      WC_Order_Item
				 * @param  $order     WC_Order
				 */
				if ( apply_filters( 'woocommerce_auto_add_combined_items', false === self::is_combo_configurable( $product ), $product, $item, $order ) ) {

					$added_to_order = WC_LafkaCombos()->order->add_combo_to_order( $product, $order, $item->get_quantity(), array(

						/**
						 * 'woocommerce_auto_added_combo_configuration' filter.
						 *
						 * See 'woocommerce_auto_add_combined_items' filter above. Use this filter to define the default configuration you want to use.
						 *
						 * @param  $config   array
						 * @param  $product  WC_Product_Combo
						 * @param  $item     WC_Order_Item
						 * @param  $order    WC_Order
						 */
						'configuration' => apply_filters( 'woocommerce_auto_added_combo_configuration', WC_LafkaCombos()->cart->get_posted_combo_configuration( $product ), $product, $item, $order )
					) );

					if ( $added_to_order ) {

						if ( WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ) {

							$new_container_item = $order->get_item( $added_to_order );

							if ( $item_reduced_stock = $item->get_meta( '_reduced_stock', true ) ) {
								$new_container_item->add_meta_data( '_reduced_stock', $item_reduced_stock, true );
								$new_container_item->save();
							}

							$combined_order_items = wc_pc_get_combined_order_items( $new_container_item, $order );
							$product_ids = array();
							$order_notes         = array();

							foreach ( $combined_order_items as $order_item_id => $order_item ) {

								$combined_item_id = $order_item->get_meta( '_combined_item_id', true );
								$product_id      = $order_item->get_product_id();

								if ( $variation_id = $order_item->get_variation_id() ) {
									$product_id = $variation_id;
								}

								$product_ids[ $combined_item_id ] = $product_id;
							}

							$duplicate_product_ids              = array_diff_assoc( $product_ids, array_unique( $product_ids ) );
							$duplicate_product_combined_item_ids = array_keys( array_intersect( $product_ids, $duplicate_product_ids ) );

							foreach ( $combined_order_items as $order_item_id => $order_item ) {

								$combined_item_id     = $order_item->get_meta( '_combined_item_id', true );
								$combined_product     = $order_item->get_product();
								$combined_product_sku = $combined_product->get_sku();

								if ( ! $combined_product_sku ) {
									$combined_product_sku = '#' . $combined_product->get_id();
								}

								if ( in_array( $combined_item_id, $duplicate_product_combined_item_ids ) ) {
									$stock_id = sprintf( _x( '%1$s:%2$s', 'combined items stock change note sku with id format', 'lafka-plugin' ), $combined_product_sku, $item_id );
								} else {
									$stock_id = $combined_product_sku;
								}

								$order_note = sprintf( _x( '%1$s (%2$s)', 'combined items stock change note format', 'lafka-plugin' ), $order_item->get_name(), $stock_id );

								if ( $combined_product->managing_stock() ) {

									$qty           = $order_item->get_quantity();
									$old_stock     = $combined_product->get_stock_quantity();
									$new_stock     = wc_update_product_stock( $combined_product, $qty, 'decrease' );
									$stock_from_to = $old_stock . '&rarr;' . $new_stock;
									$order_note    = sprintf( _x( '%1$s (%2$s) &ndash; %3$s', 'combined items stock change note format', 'lafka-plugin' ), $order_item->get_name(), $stock_id, $stock_from_to );

									$order_item->add_meta_data( '_reduced_stock', $qty, true );
									$order_item->save();
								}

								$order_notes[] = $order_note;
							}

							if ( ! empty( $order_notes ) ) {
								$order->add_order_note( sprintf( __( 'Added combined line items: %s', 'lafka-plugin' ), implode( ', ', $order_notes ) ), false, true );
							}
						}

						$order->remove_item( $item_id );
						$order->save();
					}
				}
			}
		}
	}

	/**
	 * Save order object to use in 'display_edit_button'.
	 *
	 * Although the order object can be retrieved via 'WC_Order_Item::get_order', we've seen a significant performance hit when using that method.
	 *
	 * @param  WC_Order  $order
	 */
	public static function set_order( $order ) {
		self::$order = $order;
	}

	/**
	 * Display "Configure/Edit" button next to configurable combo container items in the edit-order screen.
	 *
	 * @param  $item_id  int
	 * @param  $item     WC_Order_Item
	 * @param  $order    WC_Product
	 * @return void
	 */
	public static function display_edit_button( $item_id, $item, $product ) {

		if ( self::$order && self::$order->is_editable() && 'line_item' === $item->get_type() ) {

			if ( $product && $product->is_type( 'combo' ) ) {

				// Is this part of a Composite?
				if ( WC_LafkaCombos()->compatibility->is_composited_order_item( $item, self::$order ) ) {
					return;
				}

				/**
				 * 'woocommerce_is_combo_container_order_item_editable' filter.
				 *
				 * @param  $auto_add  boolean
				 * @param  $product   WC_Product_Combo
				 * @param  $item      WC_Order_Item
				 * @param  $order     WC_Order
				 */
				if ( apply_filters( 'woocommerce_is_combo_container_order_item_editable', self::is_combo_configurable( $product ), $product, $item, self::$order ) ) {

					// Already configured?
					$is_configured = wc_pc_is_combo_container_order_item( $item, self::$order );

					?>
					<div class="configure_order_item">
						<button class="<?php echo $is_configured ? 'edit_combo' : 'configure_combo' ?> button"><?php

							if ( $is_configured ) {
								esc_html_e( 'Edit', 'lafka-plugin' );
							} else {
								esc_html_e( 'Configure', 'lafka-plugin' );
							}

						 ?></button>
					</div>
					<?php
				}
			}
		}
	}

	/**
	 * JS template of modal for configuring/editing combos.
	 */
	public static function add_js_template() {

		if ( wp_script_is( 'wc-pb-admin-order-panel' ) ) {
			?>
			<script type="text/template" id="tmpl-wc-modal-edit-combo">
				<div class="wc-backbone-modal">
					<div class="wc-backbone-modal-content">
						<section class="wc-backbone-modal-main" role="main">
							<header class="wc-backbone-modal-header">
								<h1>{{{ data.action }}}</h1>
								<button class="modal-close modal-close-link dashicons dashicons-no-alt">
									<span class="screen-reader-text">Close modal panel</span>
								</button>
							</header>
							<article>
								<form action="" method="post">
								</form>
							</article>
							<footer>
								<div class="inner">
									<button id="btn-ok" class="button button-primary button-large"><?php _e( 'Done', 'lafka-plugin' ); ?></button>
								</div>
							</footer>
						</section>
					</div>
				</div>
				<div class="wc-backbone-modal-backdrop modal-close"></div>
			</script>
			<?php
		}
	}
}

WC_LafkaCombos_Admin_Order::init();
