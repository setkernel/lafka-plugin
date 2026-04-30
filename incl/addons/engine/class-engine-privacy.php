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
 * Engine v2 writes addon order-item meta with the `_lafka_addon_` prefix.
 * Phase 7 will land the cart/display layer that actually writes these; this
 * class is in place ahead of time so the privacy contract is intact the
 * moment Phase 7 ships.
 *
 * Registered via WP filters at hook time, paginated in batches of 25 orders
 * per request so customers with long histories don't time out.
 *
 * @package Lafka_Addons_Engine
 * @since   8.14.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Privacy {

	const EXPORTER_ID    = 'lafka-addons';
	const META_PREFIX    = '_lafka_addon_';
	const PAGE_SIZE      = 25;

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
				foreach ( $item->get_meta_data() as $meta ) {
					if ( ! $this->is_addon_meta_key( (string) $meta->key ) ) {
						continue;
					}
					$item->delete_meta_data( $meta->key );
					$removed++;
				}
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
	 * Pull every meta on an order item whose key matches the addon prefix,
	 * shaped for the GDPR exporter's "name/value" pair format.
	 *
	 * @param WC_Order_Item $item
	 * @return array<int, array{name: string, value: string}>
	 */
	private function collect_addon_meta( $item ): array {
		$out = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$key = (string) $meta->key;
			if ( ! $this->is_addon_meta_key( $key ) ) {
				continue;
			}
			$out[] = array(
				'name'  => $this->humanize_meta_key( $key ),
				'value' => is_scalar( $meta->value ) ? (string) $meta->value : wp_json_encode( $meta->value ),
			);
		}
		return $out;
	}

	private function is_addon_meta_key( string $key ): bool {
		return 0 === strpos( $key, self::META_PREFIX );
	}

	private function humanize_meta_key( string $key ): string {
		return ucwords( str_replace( array( self::META_PREFIX, '_' ), array( '', ' ' ), $key ) );
	}
}
