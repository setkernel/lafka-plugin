<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin AJAX meta-box handlers.
 *
 * @class    WC_LafkaCombos_Admin_Ajax
 * @version  6.7.3
 */
class WC_LafkaCombos_Admin_Ajax {

	/**
	 * Used by 'ajax_search_combined_variations'.
	 * @var int
	 */
	private static $searching_variations_of;

	/**
	 * Hook in.
	 */
	public static function init() {

		/*
		 * Notices.
		 */

		// Dismiss notices.
		add_action( 'wp_ajax_woocommerce_dismiss_combo_notice', array( __CLASS__ , 'dismiss_notice' ) );

		/*
		 * Edit-Product screens.
		 */

		// Ajax add combined product.
		add_action( 'wp_ajax_woocommerce_add_combined_product', array( __CLASS__, 'ajax_add_combined_product' ) );

		// Ajax search combined item variations.
		add_action( 'wp_ajax_woocommerce_search_combined_variations', array( __CLASS__, 'ajax_search_combined_variations' ) );

		/*
		 * Edit-Order screens.
		 */

		// Ajax handler used to fetch form content for populating "Configure/Edit" combo order item modals.
		add_action( 'wp_ajax_woocommerce_configure_combo_order_item', array( __CLASS__, 'ajax_combo_order_item_form' ) );

		// Ajax handler for editing combos in manual/editable orders.
		add_action( 'wp_ajax_woocommerce_edit_combo_in_order', array( __CLASS__, 'ajax_edit_combo_in_order' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Notices.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Dismisses notices.
	 *
	 * @since  5.8.0
	 *
	 * @return void
	 */
	public static function dismiss_notice() {

		$failure = array(
			'result' => 'failure'
		);

		if ( ! check_ajax_referer( 'wc_pb_dismiss_notice_nonce', 'security', false ) ) {
			wp_send_json( $failure );
		}

		if ( empty( $_POST[ 'notice' ] ) ) {
			wp_send_json( $failure );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json( $failure );
		}

		$dismissed = WC_LafkaCombos_Admin_Notices::dismiss_notice( wc_clean( $_POST[ 'notice' ] ) );

		if ( ! $dismissed ) {
			wp_send_json( $failure );
		}

		$response = array(
			'result' => 'success'
		);

		wp_send_json( $response );
	}

	/*
	|--------------------------------------------------------------------------
	| Edit-Product.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Ajax search for combined variations.
	 */
	public static function ajax_search_combined_variations() {

		if ( ! empty( $_GET[ 'include' ] ) ) {
			if ( $product = wc_get_product( absint( $_GET[ 'include' ] ) ) ) {
				self::$searching_variations_of = $product->get_id();
				$_GET[ 'include' ] = $product->get_children();
			} else {
				self::$searching_variations_of = 0;
				$_GET[ 'include' ] = array();
			}
		}

		add_filter( 'woocommerce_json_search_found_products', array( __CLASS__, 'tweak_variation_titles' ) );
		WC_AJAX::json_search_products( '', true );
		remove_filter( 'woocommerce_json_search_found_products', array( __CLASS__, 'tweak_variation_titles' ) );
	}

	/**
	 * Tweak variation titles for consistency across different WC versions.
	 *
	 * @param  array  $search_results
	 * @return array
	 */
	public static function tweak_variation_titles( $search_results ) {

		if ( ! empty( $search_results ) ) {

			// Bug in WC -- parent IDs are always included when the 'include' parameter is specified.
			if ( self::$searching_variations_of ) {
				$search_results = array_diff_key( $search_results, array( self::$searching_variations_of => 1 ) );
			}

			$search_result_objects = array_map( 'wc_get_product', array_keys( $search_results ) );

			foreach ( $search_result_objects as $variation ) {
				if ( $variation && $variation->is_type( 'variation' ) ) {
					$variation_id                    = $variation->get_id();
					$search_results[ $variation_id ] = rawurldecode( WC_LafkaCombos_Helpers::get_product_variation_title( $variation, 'flat' ) );
				}
			}
		}

		return $search_results;
	}

	/**
	 * Handles adding combined products via ajax.
	 */
	public static function ajax_add_combined_product() {

		check_ajax_referer( 'wc_combos_add_combined_product', 'security' );

		$loop               = isset( $_POST[ 'id' ] ) ? intval( $_POST[ 'id' ] ) : 0;
		$post_id            = isset( $_POST[ 'post_id' ] ) ? intval( $_POST[ 'post_id' ] ) : 0;
		$product_id         = isset( $_POST[ 'product_id' ] ) ? intval( $_POST[ 'product_id' ] ) : 0;
		$item_id            = false;
		$toggle             = 'open';
		$tabs               = WC_LafkaCombos_Meta_Box_Product_Data::get_combined_product_tabs();
		$product            = wc_get_product( $product_id );
		$title              = $product->get_title();
		$sku                = $product->get_sku();
		$stock_status       = 'in_stock';
		$item_data          = array();
		$response           = array(
			'markup'  => '',
			'message' => ''
		);

		if ( $product ) {

			if ( in_array( $product->get_type(), array( 'simple', 'variable', 'subscription', 'variable-subscription' ) ) ) {

				if ( ! $product->is_in_stock() ) {
					$stock_status       = 'out_of_stock';
				} elseif ( $product->is_on_backorder( 1 ) ) {
					$stock_status       = 'on_backorder';
				}

				ob_start();
				include( WC_LafkaCombos_ABSPATH . 'includes/admin/meta-boxes/views/html-combined-product.php' );
				$response[ 'markup' ] = ob_get_clean();

			} else {
				$response[ 'message' ] = __( 'The selected product cannot be combined. Please select a simple product, a variable product, or a simple/variable subscription.', 'lafka-plugin' );
			}

		} else {
			$response[ 'message' ] = __( 'The selected product is invalid.', 'lafka-plugin' );
		}

		wp_send_json( $response );
	}

	/*
	|--------------------------------------------------------------------------
	| Edit-Order.
	|--------------------------------------------------------------------------
	*/

	/**
	 * True when displaying content in an edit-composite order item modal.
	 *
	 * @since  3.14.0
	 *
	 * @return void
	 */
	public static function is_combo_edit_request() {
		return doing_action( 'wp_ajax_woocommerce_edit_combo_in_order' );
	}

	/**
	 * Form content used to populate "Configure/Edit" combo order item modals.
	 *
	 * @since  5.8.0
	 *
	 * @return void
	 */
	public static function ajax_combo_order_item_form() {

		global $product;

		$failure = array(
			'result' => 'failure'
		);

		if ( ! check_ajax_referer( 'wc_combos_edit_combo', 'security', false ) ) {
			wp_send_json( $failure );
		}

		if ( empty( $_POST[ 'order_id' ] ) || empty( $_POST[ 'item_id' ] ) ) {
			wp_send_json( $failure );
		}

		$order   = wc_get_order( wc_clean( $_POST[ 'order_id' ] ) );
		$item_id = absint( wc_clean( $_POST[ 'item_id' ] ) );

		if ( ! ( $order instanceof WC_Order ) ) {
			wp_send_json( $failure );
		}

		$item = $order->get_item( $item_id );

		if ( ! ( $item instanceof WC_Order_Item ) ) {
			wp_send_json( $failure );
		}

		$product       = $item->get_product();
		$combined_items = $product ? $product->get_combined_items() : false;

		if ( empty( $combined_items ) ) {
			wp_send_json( $failure );
		}

		// Initialize form state based on the actual configuration of the combo.
		$configuration = WC_LafkaCombos_Order::get_current_combo_configuration( $item, $order );

		if ( ! empty( $configuration ) ) {
			$_REQUEST = array_merge( $_REQUEST, WC_LafkaCombos()->cart->rebuild_posted_combo_form_data( $configuration ) );
		}

		// Force tabular layout.
		$product->set_layout( 'tabular' );

		// Hide prices.
		add_filter( 'woocommerce_combined_item_is_priced_individually', '__return_false' );
		// Hide descriptions.
		add_filter( 'woocommerce_combined_item_description', '__return_false' );

		ob_start();
		include( WC_LafkaCombos_ABSPATH . 'includes/admin/meta-boxes/views/html-combo-edit-form.php' );
		$html = ob_get_clean();

		$response = array(
			'result' => 'success',
			'html'   => $html
		);

		wp_send_json( $response );
	}

	/**
	 * Validates edited/configured combos and returns updated order items.
	 *
	 * @since  5.8.0
	 *
	 * @return void
	 */
	public static function ajax_edit_combo_in_order() {

		$failure = array(
			'result' => 'failure'
		);

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json( $failure );
		}

		if ( ! check_ajax_referer( 'wc_combos_edit_combo', 'security', false ) ) {
			wp_send_json( $failure );
		}

		if ( empty( $_POST[ 'order_id' ] ) || empty( $_POST[ 'item_id' ] ) ) {
			wp_send_json( $failure );
		}

		$order   = wc_get_order( wc_clean( $_POST[ 'order_id' ] ) );
		$item_id = absint( wc_clean( $_POST[ 'item_id' ] ) );

		if ( ! ( $order instanceof WC_Order ) ) {
			wp_send_json( $failure );
		}

		$item = $order->get_item( $item_id );

		if ( ! ( $item instanceof WC_Order_Item ) ) {
			wp_send_json( $failure );
		}

		$product = $item->get_product();

		if ( ! ( $product instanceof WC_Product_Combo ) ) {
			wp_send_json( $failure );
		}

		if ( ! empty( $_POST[ 'fields' ] ) ) {
			parse_str( $_POST[ 'fields' ], $posted_form_fields ); // @phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$_POST = array_merge( $_POST, $posted_form_fields );
		}

		$posted_configuration  = WC_LafkaCombos()->cart->get_posted_combo_configuration( $product );
		$current_configuration = WC_LafkaCombos_Order::get_current_combo_configuration( $item, $order );

		// Compare posted against current configuration.
		if ( $posted_configuration !== $current_configuration ) {

			$added_to_order = WC_LafkaCombos()->order->add_combo_to_order( $product, $order, $item->get_quantity(), array(

				/**
				 * 'woocommerce_editing_combo_in_order_configuration' filter.
				 *
				 * Use this filter to modify the posted configuration.
				 *
				 * @param  $config   array
				 * @param  $product  WC_Product_Combo
				 * @param  $item     WC_Order_Item
				 * @param  $order    WC_Order
				 */
				'configuration' => apply_filters( 'woocommerce_editing_combo_in_order_configuration', $posted_configuration, $product, $item, $order )
			) );

			// Invalid configuration?
			if ( is_wp_error( $added_to_order ) ) {

				$message = __( 'The submitted configuration is invalid.', 'lafka-plugin' );
				$data    = $added_to_order->get_error_data();
				$notice  = isset( $data[ 'notices' ] ) ? current( $data[ 'notices' ] ) : '';

				if ( $notice ) {
					$notice_text = WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.9' ) ? $notice[ 'notice' ] : $notice;
					$message     = sprintf( _x( '%1$s %2$s', 'edit combo in order: formatted validation message', 'lafka-plugin' ), $message, html_entity_decode( $notice_text ) );
				}

				$response = array(
					'result' => 'failure',
					'error'  => $message
				);

				wp_send_json( $response );

			// Adjust stock and remove old items.
			} else {

				$new_container_item = $order->get_item( $added_to_order );

				/**
				 * 'woocommerce_editing_combo_in_order' action.
				 *
				 * @since  5.9.2
				 *
				 * @param  WC_Order_Item_Product  $new_item
				 * @param  WC_Order_Item_Product  $old_item
				 */
				do_action( 'woocommerce_editing_combo_in_order', $new_container_item, $item, $order );

				$combined_items_to_remove = wc_pc_get_combined_order_items( $item, $order );
				$items_to_remove         = array( $item ) + $combined_items_to_remove;

				/*
				 * Adjust stock.
				 */
				if ( WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ) {

					if ( $item_reduced_stock = $item->get_meta( '_reduced_stock', true ) ) {
						$new_container_item->add_meta_data( '_reduced_stock', $item_reduced_stock, true );
						$new_container_item->save();
					}

					$stock_map   = array();
					$changes_map = array();
					$product_ids = array();

					foreach ( $combined_items_to_remove as $combined_item_to_remove ) {

						$combined_item_id = $combined_item_to_remove->get_meta( '_combined_item_id', true );
						$product_id      = $combined_item_to_remove->get_product_id();

						if ( $variation_id = $combined_item_to_remove->get_variation_id() ) {
							$product_id = $variation_id;
						}

						$product_ids[ $combined_item_id ] = $product_id;

						// Store change to add in order note.
						$changes_map[ $combined_item_id ] = array(
							'id'      => $product_id,
							'actions' => array(
								'remove' => array(
									'title' => $combined_item_to_remove->get_name(),
									'sku'   => '#' . $product_id
								)
							)
						);

						$changed_stock = wc_maybe_adjust_line_item_product_stock( $combined_item_to_remove, 0 );

						if ( $changed_stock && ! is_wp_error( $changed_stock ) ) {

							$product             = $combined_item_to_remove->get_product();
							$product_sku         = $product->get_sku();
							$stock_managed_by_id = $product->get_stock_managed_by_id();

							if ( ! $product_sku ) {
								$product_sku = '#' . $product->get_id();
							}

							// Associate change with stock.
							$changes_map[ $combined_item_id ][ 'actions' ][ 'remove' ][ 'stock_managed_by_id' ] = $stock_managed_by_id;
							$changes_map[ $combined_item_id ][ 'actions' ][ 'remove' ][ 'sku' ]                 = $product_sku;

							if ( isset( $stock_map[ $stock_managed_by_id ] ) ) {
								$stock_map[ $stock_managed_by_id ][ 'to' ] = $changed_stock[ 'to' ];
							} else {
								$stock_map[ $stock_managed_by_id ] = array(
									'from' => $changed_stock[ 'from' ],
									'to'   => $changed_stock[ 'to' ]
								);
							}
						}
					}

					$combined_order_items = wc_pc_get_combined_order_items( $new_container_item, $order );

					foreach ( $combined_order_items as $order_item_id => $order_item ) {

						$combined_item_id = $order_item->get_meta( '_combined_item_id', true );
						$product         = $order_item->get_product();
						$product_id      = $product->get_id();
						$action          = 'add';

						$product_ids[ $combined_item_id ] = $product_id;

						// Store change to add in order note.
						if ( isset( $changes_map[ $combined_item_id ] ) ) {

							// If the selection didn't change, log it as an adjustment.
							if ( $product_id === $changes_map[ $combined_item_id ][ 'id' ] ) {

								$action = 'adjust';

								$changes_map[ $combined_item_id ][ 'actions' ] = array(
									'adjust' => array(
										'title' => $order_item->get_name(),
										'sku'   => '#' . $product_id
									)
								);

							// Otherwise, log another 'add' action.
							} else {

								$changes_map[ $combined_item_id ][ 'actions' ][ 'add' ] = array(
									'title' => $order_item->get_name(),
									'sku'   => '#' . $product_id
								);
							}

						// If we're seeing this combined item for the first, time, log an 'add' action.
						} else {

							$changes_map[ $combined_item_id ] = array(
								'id'      => $product_id,
								'actions' => array(
									'add' => array(
										'title' => $order_item->get_name(),
										'sku'   => '#' . $product_id
									)
								)
							);
						}

						if ( $product && $product->managing_stock() ) {

							$product_sku         = $product->get_sku();
							$stock_managed_by_id = $product->get_stock_managed_by_id();
							$qty                 = $order_item->get_quantity();

							if ( ! $product_sku ) {
								$product_sku = '#' . $product->get_id();
							}

							// Associate change with stock.
							$changes_map[ $combined_item_id ][ 'actions' ][ $action ][ 'stock_managed_by_id' ] = $stock_managed_by_id;
							$changes_map[ $combined_item_id ][ 'actions' ][ $action ][ 'sku' ]                 = $product_sku;

							$old_stock = $product->get_stock_quantity();
							$new_stock = wc_update_product_stock( $product, $qty, 'decrease' );

							if ( isset( $stock_map[ $stock_managed_by_id ] ) ) {
								$stock_map[ $stock_managed_by_id ][ 'to' ] = $new_stock;
							} else {
								$stock_map[ $stock_managed_by_id ] = array(
									'from'    => $old_stock,
									'to'      => $new_stock
								);
							}

							$order_item->add_meta_data( '_reduced_stock', $qty, true );
							$order_item->save();
						}
					}

					$duplicate_product_ids              = array_diff_assoc( $product_ids, array_unique( $product_ids ) );
					$duplicate_product_combined_item_ids = array_keys( array_intersect( $product_ids, $duplicate_product_ids ) );

					$stock_strings = array(
						'add'    => array(),
						'remove' => array(),
						'adjust' => array()
					);

					foreach ( $changes_map as $item_id => $item_changes ) {

						$actions = array( 'add', 'remove', 'adjust' );

						foreach ( $actions as $action ) {

							if ( isset( $item_changes[ 'actions' ][ $action ] ) ) {

								$stock_changes        = isset( $item_changes[ 'actions' ][ $action ][ 'stock_managed_by_id' ] ) && isset( $stock_map[ $item_changes[ 'actions' ][ $action ][ 'stock_managed_by_id' ] ] ) ? $stock_map[ $item_changes[ 'actions' ][ $action ][ 'stock_managed_by_id' ] ] : false;
								$stock_from_to_string = $stock_changes && $stock_changes[ 'from' ] && $stock_changes[ 'from' ] !== $stock_changes[ 'to' ] ? ( $stock_changes[ 'from' ] . '&rarr;' . $stock_changes[ 'to' ] ) : '';

								if ( in_array( $item_id, $duplicate_product_combined_item_ids ) ) {
									$stock_id = sprintf( _x( '%1$s:%2$s', 'combined items stock change note sku with id format', 'lafka-plugin' ), $item_changes[ 'actions' ][ $action ][ 'sku' ], $item_id );
								} else {
									$stock_id = $item_changes[ 'actions' ][ $action ][ 'sku' ];
								}

								if ( $stock_from_to_string ) {
									$stock_strings[ $action ][] = sprintf( _x( '%1$s (%2$s) &ndash; %3$s', 'combined items stock change note format', 'lafka-plugin' ), $item_changes[ 'actions' ][ $action ][ 'title' ], $stock_id, $stock_from_to_string );
								} else {
									$stock_strings[ $action ][] = sprintf( _x( '%1$s (%2$s)', 'combined items change note format', 'lafka-plugin' ), $item_changes[ 'actions' ][ $action ][ 'title' ], $stock_id );
								}
							}
						}
					}

					if ( ! empty( $stock_strings[ 'remove' ] ) ) {
						$order->add_order_note( sprintf( __( 'Deleted combined line items: %s', 'lafka-plugin' ), implode( ', ', $stock_strings[ 'remove' ] ) ), false, true );
					}

					if ( ! empty( $stock_strings[ 'add' ] ) ) {
						$order->add_order_note( sprintf( __( 'Added combined line items: %s', 'lafka-plugin' ), implode( ', ', $stock_strings[ 'add' ] ) ), false, true );
					}

					if ( ! empty( $stock_strings[ 'adjust' ] ) ) {
						$order->add_order_note( sprintf( __( 'Adjusted combined line items: %s', 'lafka-plugin' ), implode( ', ', $stock_strings[ 'adjust' ] ) ), false, true );
					}
				}

				/*
				 * Remove old items.
				 */
				foreach ( $items_to_remove as $remove_item ) {
					$order->remove_item( $remove_item->get_id() );
					$remove_item->delete();
				}

				/*
				 * Recalculate totals.
				 */
				if ( isset( $_POST[ 'country' ], $_POST[ 'state' ], $_POST[ 'postcode' ], $_POST[ 'city' ] ) ) {

					$calculate_tax_args = array(
						'country'  => strtoupper( wc_clean( $_POST[ 'country' ] ) ),
						'state'    => strtoupper( wc_clean( $_POST[ 'state' ] ) ),
						'postcode' => strtoupper( wc_clean( $_POST[ 'postcode' ] ) ),
						'city'     => strtoupper( wc_clean( $_POST[ 'city' ] ) ),
					);

					$order->calculate_taxes( $calculate_tax_args );
					$order->calculate_totals( false );

				} else {
					$order->save();
				}
			}
		}

		ob_start();
		include ( WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-items.php' );
		$html = ob_get_clean();

		if ( WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ) {

			ob_start();
			$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			include ( WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-notes.php' );
			$notes_html = ob_get_clean();
			$response = array(
				'result'     => 'success',
				'html'       => $html,
				'notes_html' => $notes_html
			);

		} else {
			$response = array(
				'result'     => 'success',
				'html'       => $html,
			);
		}

		wp_send_json( $response );
	}
}

WC_LafkaCombos_Admin_Ajax::init();
