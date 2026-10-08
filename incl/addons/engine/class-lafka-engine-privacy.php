<?php
/**
 * Lafka_Engine_Privacy — GDPR exporter + eraser for addon order-item data.
 *
 * Scope: addon CPT and product config are admin-authored, not personal data.
 * The only user-keyed addon footprint lives on WC order items: when a customer
 * ordered a pizza with toppings, the chosen addon labels + any custom-text
 * values are saved as order-item meta. Those records belong to the customer
 * and must respond to export/erase requests.
 *
 * The cart stores each selection under its customer-facing display key
 * ("Extra Toppings ($1.50)" => "Extra Cheese") and lists those keys in the
 * hidden `_lafka_addon_keys` item meta. Orders placed before that marker
 * existed are matched by the product's current add-on group names — the
 * same rule the re-order flow uses to read selections back.
 *
 * Registered via WP filters at hook time, paginated in batches of 25 orders
 * per request so customers with long histories don't time out.
 *
 * @package Lafka_Addons_Engine
 * @since   8.14.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Privacy {

	const EXPORTER_ID = 'lafka-addons';
	const KEYS_META   = '_lafka_addon_keys';
	const PAGE_SIZE   = 25;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function register_exporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Lafka Add-on Selections', 'lafka-plugin' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers[ self::EXPORTER_ID ] = array(
			'eraser_friendly_name' => __( 'Lafka Add-on Selections', 'lafka-plugin' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export every addon-tagged order item meta belonging to the email.
	 *
	 * @param string $email_address
	 * @param int    $page  1-indexed.
	 * @return array{data: array, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$data   = array();
		$orders = $this->get_orders_for_email( $email_address, $page );

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				$addon_meta = $this->collect_addon_meta( $item );
				if ( empty( $addon_meta ) ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'lafka_addons_orders',
					'group_label' => __( 'Add-on Selections', 'lafka-plugin' ),
					'item_id'     => 'order-item-' . (int) $item_id,
					'data'        => $addon_meta,
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase every addon-tagged order item meta belonging to the email.
	 *
	 * @param string $email_address
	 * @param int    $page  1-indexed.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$removed  = 0;
		$retained = 0;
		$orders   = $this->get_orders_for_email( $email_address, $page );

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$keys = $this->addon_meta_keys( $item );
				if ( empty( $keys ) ) {
					continue;
				}
				foreach ( $keys as $key ) {
					$item->delete_meta_data( $key );
					++$removed;
				}
				$item->delete_meta_data( self::KEYS_META );
				$item->save();
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array(),
			'done'           => count( $orders ) < self::PAGE_SIZE,
		);
	}

	/**
	 * @return WC_Order[]
	 */
	private function get_orders_for_email( string $email, int $page ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'         => self::PAGE_SIZE,
				'page'          => max( 1, $page ),
				'billing_email' => $email,
				'type'          => 'shop_order',
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * The add-on selections on an order item, shaped for the GDPR exporter's
	 * "name/value" pair format.
	 *
	 * @param WC_Order_Item $item
	 * @return array<int, array{name: string, value: string}>
	 */
	private function collect_addon_meta( $item ): array {
		$keys = $this->addon_meta_keys( $item );
		$out  = array();
		foreach ( $item->get_meta_data() as $meta ) {
			if ( ! in_array( (string) $meta->key, $keys, true ) ) {
				continue;
			}
			$out[] = array(
				'name'  => (string) $meta->key,
				'value' => is_scalar( $meta->value ) ? (string) $meta->value : wp_json_encode( $meta->value ),
			);
		}
		return $out;
	}

	/**
	 * Meta keys on an order item that hold add-on selections.
	 *
	 * @param WC_Order_Item $item
	 * @return string[]
	 */
	private function addon_meta_keys( $item ): array {
		$present = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$present[] = (string) $meta->key;
		}

		$recorded = $item->get_meta( self::KEYS_META );
		if ( is_array( $recorded ) ) {
			return array_values( array_intersect( $present, array_map( 'strval', $recorded ) ) );
		}

		// Orders from before the marker: match the product's add-on group names.
		$names = array();
		if ( class_exists( 'Lafka_Engine_Helper' ) && method_exists( $item, 'get_product_id' ) ) {
			foreach ( Lafka_Engine_Helper::get_product_addons( (int) $item->get_product_id() ) as $addon ) {
				if ( '' !== (string) ( $addon['name'] ?? '' ) ) {
					$names[] = (string) $addon['name'];
				}
			}
		}

		$keys = array();
		foreach ( $present as $key ) {
			foreach ( $names as $name ) {
				if ( 0 === stripos( $key, $name ) ) {
					$keys[] = $key;
					break;
				}
			}
		}
		return $keys;
	}
}
