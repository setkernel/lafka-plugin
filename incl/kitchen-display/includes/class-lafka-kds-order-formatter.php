<?php
/**
 * Lafka_KDS_Order_Formatter — shapes a WC_Order into the JSON-friendly
 * structure consumed by the KDS frontend (and the customer status poll).
 *
 * Extracted from the AJAX layer in v9.7.2 so each concern (line items,
 * scheduling, ETA, payment, delivery) lives in its own focused method that
 * can be unit-tested without standing up the full AJAX request flow.
 *
 * @package Lafka_Kitchen_Display
 * @since   9.7.2
 */

defined( 'ABSPATH' ) || exit;

class Lafka_KDS_Order_Formatter {

	/**
	 * Compose the full per-order payload. Public entry point; the AJAX layer
	 * calls this once per order returned by `wc_get_orders()`.
	 *
	 * @param WC_Order $order
	 * @return array<string, mixed>
	 */
	public function format( $order ): array {
		$order_type = Lafka_Kitchen_Display::get_order_type( $order );
		$payment    = $this->get_payment_data( $order );

		return array(
			'id'                   => $order->get_id(),
			'number'               => $order->get_order_number(),
			'status'               => $order->get_status(),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'order_type'           => $order_type,
			'is_paid_online'       => $payment['is_paid_online'],
			'payment_label'        => $payment['label'],
			'customer_name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customer_phone'       => $order->get_billing_phone(),
			'items'                => $this->get_items_data( $order ),
			'customer_note'        => $order->get_customer_note(),
			'scheduled'            => $this->get_scheduled_string( $order ),
			'eta'                  => $this->get_meta_int_or_null( $order, '_lafka_kds_eta' ),
			'eta_minutes'          => $this->get_meta_int_or_null( $order, '_lafka_kds_eta_minutes' ),
			'accepted_at'          => $this->get_meta_int_or_null( $order, '_lafka_kds_accepted_at' ),
			'total'                => $order->get_total(),
			'currency_symbol'      => $this->get_currency_symbol( $order ),
			'delivery_address'     => 'delivery' === $order_type ? $this->get_delivery_address( $order ) : '',
			'special_instructions' => (string) $order->get_meta( '_lafka_special_instructions' ),
			'allergen_info'        => (string) $order->get_meta( '_lafka_allergen_info' ),
		);
	}

	/**
	 * Build the `items[]` array — name, qty, primary product category (for KDS
	 * grouping), and formatted line-item meta (variation attributes + addons).
	 *
	 * @param WC_Order $order
	 * @return list<array<string, mixed>>
	 */
	public function get_items_data( $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'category' => $this->get_primary_category( $item ),
				'meta'     => $this->get_item_meta( $item ),
			);
		}
		return $items;
	}

	/**
	 * Returns the first product_cat term name for an order item's product, or
	 * '' if the product is gone, the categories call errored, or there are no
	 * categories. KDS uses this to group items on the ticket.
	 *
	 * @param WC_Order_Item_Product $item
	 */
	private function get_primary_category( $item ): string {
		$product = $item->get_product();
		if ( ! $product ) {
			return '';
		}
		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $categories ) || empty( $categories ) ) {
			return '';
		}
		return (string) $categories[0];
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return list<array{key:string, value:string}>
	 */
	private function get_item_meta( $item ): array {
		$out  = array();
		$meta = $item->get_formatted_meta_data( '_', true );
		foreach ( $meta as $entry ) {
			$out[] = array(
				'key'   => wp_strip_all_tags( $entry->display_key ),
				'value' => wp_strip_all_tags( $entry->display_value ),
			);
		}
		return $out;
	}

	/**
	 * Payment details: whether the order is already paid online vs. cash on
	 * pickup/delivery, plus the human-readable label the KDS card displays.
	 *
	 * @param WC_Order $order
	 * @return array{is_paid_online:bool, label:string}
	 */
	public function get_payment_data( $order ): array {
		$method         = $order->get_payment_method();
		$is_paid_online = ! in_array( $method, array( 'cod', 'cheque', '' ), true );
		return array(
			'is_paid_online' => $is_paid_online,
			'label'          => $is_paid_online
				? __( 'Paid Online', 'lafka-plugin' )
				: __( 'Cash on Delivery', 'lafka-plugin' ),
		);
	}

	/**
	 * Concatenate the lafka_order_date + lafka_order_time meta into a single
	 * "YYYY-MM-DD HH:MM" string when the customer scheduled a future order.
	 * Returns '' for ASAP orders so the KDS card hides the "Scheduled:" row.
	 *
	 * @param WC_Order $order
	 */
	public function get_scheduled_string( $order ): string {
		$date = $order->get_meta( 'lafka_order_date' );
		$time = $order->get_meta( 'lafka_order_time' );
		return ( $date && $time ) ? trim( $date . ' ' . $time ) : '';
	}

	/**
	 * Comma-separated shipping address built from WC's standard address parts.
	 * Empty parts are filtered so unset address-line-2 doesn't yield "X, , Y".
	 *
	 * @param WC_Order $order
	 */
	public function get_delivery_address( $order ): string {
		$parts = array_filter(
			array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
				$order->get_shipping_postcode(),
			)
		);
		return trim( implode( ', ', $parts ) );
	}

	/**
	 * WC stores currency symbols HTML-entity-encoded (e.g. `&#36;`). The KDS
	 * JS uses `textContent` to escape user-supplied data, which would render
	 * `&#36;` literally. Decode here so the JS receives a plain UTF-8 char.
	 *
	 * @param WC_Order $order
	 */
	public function get_currency_symbol( $order ): string {
		return html_entity_decode(
			get_woocommerce_currency_symbol( $order->get_currency() ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Coerce a numeric order-meta value to int, returning null when unset.
	 * Used by `_lafka_kds_eta`, `_lafka_kds_eta_minutes`, `_lafka_kds_accepted_at`
	 * — all of which the KDS UI treats as "absent" rather than "zero".
	 *
	 * @param WC_Order $order
	 */
	private function get_meta_int_or_null( $order, string $key ): ?int {
		$raw = $order->get_meta( $key );
		return $raw ? (int) $raw : null;
	}
}
