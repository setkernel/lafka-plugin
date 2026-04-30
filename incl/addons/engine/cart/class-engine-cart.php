<?php
/**
 * Lafka_Engine_Cart — cart hooks for the addon engine.
 *
 * Owns the seven cart-lifecycle filters/actions:
 *   - woocommerce_add_cart_item                    (apply addon prices)
 *   - woocommerce_get_cart_item_from_session       (restore addons on reload)
 *   - woocommerce_get_item_data                    (cart/mini-cart display)
 *   - woocommerce_add_cart_item_data               ($_POST → cart item)
 *   - woocommerce_add_to_cart_validation           (required/limit checks)
 *   - woocommerce_checkout_create_order_line_item  (write addon meta to order)
 *   - woocommerce_order_again_cart_item_data       (re-add from past order)
 *
 * Replaces Lafka_Product_Addon_Cart. Behavior preserved at the wire level
 * so checkout, order persistence, and emails are byte-identical to v8.14.x —
 * the only change is the namespace + use of Lafka_Engine_Helper / Field
 * classes under the hood.
 *
 * The variation-specific price walk (`apply_attribute_specific_price`)
 * stays here for now; v8.16.x can refactor to integrate with the strategy
 * resolver, once we're confident no edge cases regress.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Cart {

	public function __construct() {
		add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 20 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_cart_item' ), 999, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 3 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 're_add_cart_item_data' ), 10, 3 );
	}

	/**
	 * Apply addon prices on top of the product base price.
	 *
	 * @param array $cart_item
	 * @return array
	 */
	public function add_cart_item( $cart_item ): array {
		if ( empty( $cart_item['addons'] ) || ! apply_filters( 'lafka_product_addons_adjust_price', true, $cart_item ) ) {
			return $cart_item;
		}

		$price = (float) $cart_item['data']->get_price( 'edit' );

		// Smart Coupons self-declared gift amount compat.
		if ( empty( $price ) && ! empty( $_POST['credit_called'] ) ) {
			$id = $cart_item['data']->get_id();
			if ( isset( $_POST['credit_called'][ $id ] ) ) {
				$price = (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['credit_called'][ $id ] ) ) );
			}
		}
		if ( empty( $price ) && ! empty( $cart_item['credit_amount'] ) ) {
			$price = (float) $cart_item['credit_amount'];
		}

		$cart_item['addons'] = $this->apply_attribute_specific_price( $cart_item['addons'], $cart_item );
		foreach ( $cart_item['addons'] as $addon ) {
			if ( 0 !== $addon['price'] ) {
				$price += (float) $addon['price'];
			}
		}

		$cart_item['data']->set_price( $price );
		return $cart_item;
	}

	/**
	 * @param array $cart_item
	 * @param array $values
	 * @return array
	 */
	public function get_cart_item_from_session( $cart_item, $values ): array {
		if ( ! empty( $values['addons'] ) ) {
			$cart_item['addons'] = $values['addons'];
			$cart_item           = $this->add_cart_item( $cart_item );
		}
		return $cart_item;
	}

	/**
	 * Format addons for cart and mini-cart display.
	 *
	 * @param array $other_data
	 * @param array $cart_item
	 * @return array
	 */
	public function get_item_data( $other_data, $cart_item ): array {
		if ( empty( $cart_item['addons'] ) ) {
			return $other_data;
		}

		$last_used_name = '';
		foreach ( $cart_item['addons'] as $addon ) {
			$value       = $addon['value'];
			$addon_price = $this->coerce_price_to_scalar( $addon['price'] );

			if ( 0.0 !== (float) $addon_price && apply_filters( 'lafka_addons_add_price_to_name', true ) ) {
				$value .= ' ' . wc_price( Lafka_Engine_Helper::get_product_addon_price_for_display( $addon_price, $cart_item['data'] ) );
			}

			$name = $addon['name'] !== $last_used_name ? $addon['name'] : '';
			$last_used_name = $addon['name'];

			$other_data[] = array(
				'name'    => $name,
				'value'   => $value,
				'display' => $addon['display'] ?? '',
			);
		}

		return $other_data;
	}

	/**
	 * @param array $cart_item_meta
	 * @param int   $product_id
	 * @param mixed $post_data Optional. When omitted, $_POST is used.
	 * @return array
	 * @throws Exception When a field validation returns WP_Error.
	 */
	public function add_cart_item_data( $cart_item_meta, $product_id, $post_data = null ): array {
		if ( null === $post_data && isset( $_POST ) ) {
			$post_data = $_POST;
		}

		// Grouped products: $product_id we get is the parent's id; the actual
		// product being added is the one in `add-to-cart`.
		if ( ! empty( $post_data['add-to-cart'] ) && $this->is_grouped_product( (int) $post_data['add-to-cart'] ) ) {
			$product_id = (int) $post_data['add-to-cart'];
		}

		$product_addons = Lafka_Engine_Helper::get_product_addons( $product_id );

		$cart_item_meta = (array) $cart_item_meta;
		if ( empty( $cart_item_meta['addons'] ) ) {
			$cart_item_meta['addons'] = array();
		}

		if ( ! is_array( $product_addons ) || empty( $product_addons ) ) {
			return $cart_item_meta;
		}

		foreach ( $product_addons as $addon ) {
			$value = $post_data[ 'addon-' . $addon['field-name'] ] ?? '';
			$value = is_array( $value ) ? array_map( 'wp_unslash', $value ) : wp_unslash( $value );

			$field = Lafka_Engine_Field_Factory::create( $addon, $value );
			if ( ! $field ) {
				continue;
			}

			$data = $field->get_cart_item_data();
			if ( is_wp_error( $data ) ) {
				throw new Exception( $data->get_error_message() );
			}
			if ( $data ) {
				$cart_item_meta['addons'] = array_merge(
					$cart_item_meta['addons'],
					(array) apply_filters( 'lafka_product_addon_cart_item_data', $data, $addon, $product_id, $post_data )
				);
			}
		}

		return $cart_item_meta;
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $qty
	 * @return bool
	 */
	public function validate_add_cart_item( $passed, $product_id, $qty, $post_data = null ): bool {
		if ( null === $post_data && isset( $_POST ) ) {
			$post_data = $_POST;
		}

		$product_addons = Lafka_Engine_Helper::get_product_addons( $product_id );
		if ( ! is_array( $product_addons ) || empty( $product_addons ) ) {
			return $passed;
		}

		foreach ( $product_addons as $addon ) {
			$value = $post_data[ 'addon-' . $addon['field-name'] ] ?? '';
			$value = is_array( $value ) ? array_map( 'wp_unslash', $value ) : wp_unslash( $value );

			$field = Lafka_Engine_Field_Factory::create( $addon, $value );
			if ( ! $field ) {
				continue;
			}

			$result = $field->validate();
			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
				return false;
			}

			do_action( 'woocommerce_validate_posted_addon_data', $addon );
		}

		return $passed;
	}

	/**
	 * Persist addon selections onto the order line item meta.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 */
	public function order_line_item( $item, $cart_item_key, $values ): void {
		if ( empty( $values['addons'] ) ) {
			return;
		}

		foreach ( $values['addons'] as $addon ) {
			$key         = $addon['name'];
			$addon_price = $this->coerce_price_to_scalar( $addon['price'] );

			if ( 0.0 !== (float) $addon_price && apply_filters( 'lafka_addons_add_price_to_name', true ) ) {
				$key .= ' (' . wp_strip_all_tags(
					(string) wc_price( Lafka_Engine_Helper::get_product_addon_price_for_display( $addon_price, $values['data'] ) )
				) . ')';
			}

			$item->add_meta_data( $key, $addon['value'] );
		}
	}

	/**
	 * Re-order: rebuild cart_item['addons'] from the previous order's line-item meta.
	 *
	 * @param array          $cart_item_meta
	 * @param array          $product
	 * @param WC_Order|null  $order
	 * @return array
	 */
	public function re_add_cart_item_data( $cart_item_meta, $product, $order ): array {
		// Skip validation while reconstructing — past orders are trusted by definition.
		remove_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_cart_item' ), 999 );

		$product_addons = Lafka_Engine_Helper::get_product_addons( $product['product_id'] );

		if ( empty( $cart_item_meta['addons'] ) ) {
			$cart_item_meta['addons'] = array();
		}

		if ( ! is_array( $product_addons ) || empty( $product_addons ) ) {
			return $cart_item_meta;
		}

		foreach ( $product_addons as $addon ) {
			$value = $this->reconstruct_value_from_order_meta( $addon, $product );
			if ( empty( $value ) ) {
				continue;
			}

			$field = Lafka_Engine_Field_Factory::create( $addon, $value );
			if ( ! $field ) {
				continue;
			}

			$data = $field->get_cart_item_data();
			if ( is_wp_error( $data ) ) {
				wc_add_notice( $data->get_error_message(), 'error' );
				continue;
			}
			if ( $data ) {
				$cart_item_meta['addons'] = array_merge(
					$cart_item_meta['addons'],
					(array) apply_filters( 'lafka_product_addon_reorder_cart_item_data', $data, $addon, $product['product_id'], $_POST )
				);
			}
		}

		return $cart_item_meta;
	}

	/**
	 * Walk an order item's meta and reconstruct what the customer originally
	 * submitted for one addon. checkbox/radiobutton accumulate scalar values;
	 * textarea reconstructs the per-option dict shape.
	 *
	 * @param array          $addon
	 * @param WC_Order_Item  $product
	 * @return array|string
	 */
	private function reconstruct_value_from_order_meta( array $addon, $product ) {
		$value = array();

		if ( in_array( $addon['type'], array( 'checkbox', 'radiobutton' ), true ) ) {
			foreach ( $product->get_meta_data() as $meta ) {
				if ( 0 !== stripos( (string) $meta->key, $addon['name'] ) ) {
					continue;
				}
				if ( is_array( $meta->value ) ) {
					foreach ( $meta->value as $entry ) {
						$value[] = sanitize_title( (string) $entry );
					}
				} else {
					$value[] = sanitize_title( (string) $meta->value );
				}
			}
		} elseif ( 'textarea' === $addon['type'] ) {
			foreach ( $product->get_meta_data() as $meta ) {
				foreach ( $addon['options'] as $option ) {
					if ( 0 === stripos( (string) $meta->key, $addon['name'] ) && stristr( (string) $meta->key, (string) ( $option['label'] ?? '' ) ) ) {
						$value[ sanitize_title( $option['label'] ?? '' ) ] = $meta->value;
					}
				}
			}
		}

		return $value;
	}

	/**
	 * Resolve a possibly-array addon price against the cart item's variation
	 * attributes, walking down to the leaf scalar that matches the chosen
	 * variation. Falls back to the first scalar in the matrix when no
	 * variation matches — that's the safety net that keeps us from `(float)`
	 * casting an array to 1.0 and silently overcharging by $1 per addon.
	 *
	 * @param array $addons
	 * @param array $cart_item
	 * @return array
	 */
	public function apply_attribute_specific_price( array $addons, array $cart_item ): array {
		foreach ( $addons as $key => $addon ) {
			if ( ! isset( $addon['price'] ) || ! is_array( $addon['price'] ) ) {
				continue;
			}

			$matched = false;
			if ( ! empty( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) {
				foreach ( $cart_item['variation'] as $prefixed_name => $variation_value ) {
					$variation_name = str_replace( 'attribute_', '', $prefixed_name );
					if ( isset( $addon['price'][ $variation_name ][ $variation_value ] ) && is_scalar( $addon['price'][ $variation_name ][ $variation_value ] ) ) {
						$addon['price'] = $addon['price'][ $variation_name ][ $variation_value ];
						$addons[ $key ] = $addon;
						$matched        = true;
						break;
					}
				}
			}

			if ( ! $matched && is_array( $addon['price'] ) ) {
				$addon['price'] = $this->walk_to_scalar_price( $addon['price'] );
				$addons[ $key ] = $addon;
			}
		}
		return $addons;
	}

	/**
	 * Coerce a possibly-nested matrix to a scalar by walking the first key
	 * at each level. Depth-bounded against corrupt data. Returns 0 when the
	 * walk doesn't terminate in a scalar.
	 *
	 * @param mixed $price
	 * @return string|int|float
	 */
	private function walk_to_scalar_price( $price ) {
		$depth = 0;
		while ( is_array( $price ) && ! empty( $price ) && $depth < 10 ) {
			$price = reset( $price );
			++$depth;
		}
		return is_scalar( $price ) ? $price : 0;
	}

	/**
	 * Defensive scalar coercion used at display + order-write sites where
	 * apply_attribute_specific_price is supposed to have already run, but
	 * session restore or third-party manipulation can leave a nested array.
	 *
	 * @param mixed $price
	 * @return string|int|float
	 */
	private function coerce_price_to_scalar( $price ) {
		if ( is_array( $price ) ) {
			return $this->walk_to_scalar_price( $price );
		}
		return $price;
	}

	private function is_grouped_product( int $product_id ): bool {
		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product && $product->is_type( 'grouped' );
	}
}
