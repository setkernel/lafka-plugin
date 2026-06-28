<?php
/**
 * Phase 1B: WooCommerce ecommerce dataLayer events.
 *
 * Pushes GA4-shape ecommerce events to `window.dataLayer` from server-rendered
 * <script> tags (for view-type events) and from WC hooks that mirror to JS
 * (for interaction events). GTM is the routing layer — this module never
 * calls gtag() directly. Operator wires GA4 / Meta / Clarity inside GTM.
 *
 * Events shipped:
 *   - view_item              (single product page)
 *   - view_item_list         (category archive + /menu/ + related)
 *   - select_item            (product link click — client-side)
 *   - add_to_cart            (woocommerce_add_to_cart hook + AJAX fragment)
 *   - remove_from_cart       (woocommerce_cart_item_removed hook + JS mirror)
 *   - view_cart              (/cart/ page load)
 *   - begin_checkout         (/checkout/ page load)
 *   - add_shipping_info      (checkout shipping radio change — client-side)
 *   - add_payment_info       (checkout payment radio change — client-side)
 *   - purchase               (woocommerce_thankyou — once per order, gated by meta)
 *   - search                 (/menu/ search input — client-side)
 *
 * Architecture:
 *   - All events fire via dataLayer.push() — never gtag().
 *   - Consent gating happens in GTM (Consent Mode v2 wired in Phase 1A);
 *     this module always pushes, GTM filters at tag-fire time.
 *   - Item payload helper lafka_dl_item_payload() is the single source of
 *     truth for GA4 item shape so server PHP and AJAX JSON output stay in sync.
 *   - All emitted JS payloads go through wp_json_encode().
 *   - Client JS (lafka-dl-client.js) is enqueued conditional on an analytics
 *     ID being configured so unconfigured sites don't pay the request cost.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.24.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// Shared analytics gate — single source of truth for "is a dataLayer-consuming
// destination configured?". Lives here because this is the earliest-loaded
// analytics module (see lafka-plugin.php require order); every other dataLayer
// enqueue/emit path delegates to it through a function_exists guard so the
// destination list can never drift across modules again.
// ============================================================================

if ( ! function_exists( 'lafka_analytics_has_datalayer_destination' ) ) {
	/**
	 * True when at least one dataLayer-consuming destination is configured:
	 * GTM, GA4, Microsoft Clarity, or the Meta Pixel.
	 *
	 * This is the canonical gate for every piece of dataLayer machinery —
	 * lafka-dl-client.js, lafka-custom-events.js, the server-rendered
	 * page_context push, and the store-events bundle all consult it (directly,
	 * or via lafka_analytics_is_active()). Adding a future dataLayer
	 * destination is therefore a one-line edit here.
	 *
	 * The Cloudflare Web Analytics beacon is deliberately NOT part of this
	 * gate: it is cookieless, never routes anything through window.dataLayer,
	 * and is gated independently in lafka-cf-analytics.php via
	 * lafka_analytics_cf_beacon_token(). The union of the two lives in
	 * lafka_analytics_is_active() for the cheap server-rendered pushes.
	 *
	 * @return bool
	 */
	function lafka_analytics_has_datalayer_destination(): bool {
		if ( function_exists( 'lafka_analytics_gtm_id' ) && '' !== lafka_analytics_gtm_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_ga4_id' ) && '' !== lafka_analytics_ga4_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_clarity_id' ) && '' !== lafka_analytics_clarity_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_meta_pixel_id' ) && '' !== lafka_analytics_meta_pixel_id() ) {
			return true;
		}
		return false;
	}
}

// ============================================================================
// Payload helpers — single source of truth for GA4 ecommerce shape.
// ============================================================================

if ( ! function_exists( 'lafka_dl_currency' ) ) {
	/**
	 * Active WooCommerce store currency.
	 *
	 * Wrapped in a function so tests can stub it without booting WC.
	 *
	 * @return string ISO-4217 code (e.g. 'USD', 'EUR').
	 */
	function lafka_dl_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$code = get_woocommerce_currency();
			return is_string( $code ) ? $code : 'USD';
		}
		return 'USD';
	}
}

if ( ! function_exists( 'lafka_dl_item_payload' ) ) {
	/**
	 * Build a GA4-shape item array for a WC product.
	 *
	 * Required keys per GA4 enhanced ecommerce spec:
	 *   - item_id     (string)  — product SKU or post ID
	 *   - item_name   (string)  — display name
	 *   - item_category (string) — first WC product category, or '' if none
	 *   - price       (float)   — single-unit price as float
	 *   - quantity    (int)     — caller-supplied; defaults to 1
	 *
	 * Tests assert the exact keys, so adding new keys is a contract change.
	 *
	 * @param object $product WC_Product (or duck-typed equivalent with get_id/get_name/get_price).
	 * @param int    $qty
	 * @return array<string, mixed>
	 */
	function lafka_dl_item_payload( $product, int $qty = 1 ): array {
		if ( ! is_object( $product ) ) {
			return array();
		}

		$id    = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$name  = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
		$price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;

		$category = '';
		if ( $id > 0 && function_exists( 'wc_get_product_category_list' ) ) {
			// wc_get_product_category_list returns HTML — strip to first category.
			$cat_html = wc_get_product_category_list( $id, ', ' );
			if ( is_string( $cat_html ) && '' !== $cat_html ) {
				$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $cat_html ) : strip_tags( $cat_html );
				$parts    = array_map( 'trim', explode( ',', $stripped ) );
				$category = $parts[0] ?? '';
			}
		}

		$payload = array(
			'item_id'       => (string) ( $id > 0 ? $id : '' ),
			'item_name'     => $name,
			'item_category' => $category,
			'price'         => round( $price, 2 ),
			'quantity'      => max( 1, $qty ),
		);

		/**
		 * Filter a single GA4 item payload before it is pushed to the dataLayer.
		 *
		 * @param array<string, mixed> $payload
		 * @param object               $product
		 * @param int                  $qty
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$payload = (array) apply_filters( 'lafka_dl_item_payload', $payload, $product, $qty );
		}
		return $payload;
	}
}

if ( ! function_exists( 'lafka_dl_items_from_cart' ) ) {
	/**
	 * Build an array of GA4 item payloads for every line in the current cart.
	 *
	 * Returns [] when the cart is empty or WC is not loaded — callers must
	 * branch on emptiness before emitting a `view_cart` / `begin_checkout`
	 * payload to avoid pushing an event with `items: []` (which GA4 still
	 * counts but adds noise to the report).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function lafka_dl_items_from_cart(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->cart ) ) {
			return array();
		}
		$contents = $wc->cart->get_cart();
		if ( ! is_array( $contents ) ) {
			return array();
		}
		$items = array();
		foreach ( $contents as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			$qty     = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			if ( $product ) {
				$items[] = lafka_dl_item_payload( $product, $qty );
			}
		}
		return $items;
	}
}

if ( ! function_exists( 'lafka_dl_cart_value' ) ) {
	/**
	 * Numeric cart total (subtotal, ex-tax / ex-shipping) — float.
	 *
	 * Pulled from WC cart totals; falls back to summing item rows.
	 *
	 * @return float
	 */
	function lafka_dl_cart_value(): float {
		if ( ! function_exists( 'WC' ) ) {
			return 0.0;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->cart ) ) {
			return 0.0;
		}
		if ( method_exists( $wc->cart, 'get_subtotal' ) ) {
			$subtotal = (float) $wc->cart->get_subtotal();
			if ( $subtotal > 0 ) {
				return round( $subtotal, 2 );
			}
		}
		// Fallback — sum item rows from get_cart().
		$total = 0.0;
		$rows  = $wc->cart->get_cart();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$qty   = isset( $row['quantity'] ) ? (int) $row['quantity'] : 1;
				$price = ( isset( $row['data'] ) && is_object( $row['data'] ) && method_exists( $row['data'], 'get_price' ) )
					? (float) $row['data']->get_price()
					: 0.0;
				$total += $qty * $price;
			}
		}
		return round( $total, 2 );
	}
}

if ( ! function_exists( 'lafka_dl_emit_push' ) ) {
	/**
	 * Echo a <script> tag that pushes a single event onto the dataLayer.
	 *
	 * Always emits an `ecommerce: null` clear immediately before the push —
	 * Google's documented pattern to prevent stale ecommerce data leaking
	 * between events on a single page (e.g. PDP → AJAX add-to-cart).
	 *
	 * @param string               $event_name GA4 event name (snake_case, ≤40 chars).
	 * @param array<string, mixed> $payload
	 */
	function lafka_dl_emit_push( string $event_name, array $payload ): void {
		$envelope = array(
			'event'     => $event_name,
			'ecommerce' => $payload,
		);
		// Clear stale ecommerce, then push the new event.
		echo "<script>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "window.dataLayer.push({ecommerce: null});\n";
		echo 'window.dataLayer.push(' . wp_json_encode( $envelope ) . ");\n";
		echo "</script>\n";
	}
}

// ============================================================================
// Server-side view events (synchronous, server-rendered <script>).
// ============================================================================

if ( ! function_exists( 'lafka_dl_emit_view_item' ) ) {
	/**
	 * `view_item` — emit on single-product page load.
	 *
	 * Hooked on woocommerce_before_single_product_summary (priority 5, well
	 * before the title/price emit so the dataLayer push happens in the natural
	 * head-to-body flow).
	 *
	 * Only fires on singular product pages — checked twice (function_exists
	 * guards + conditional inside) so a misfired call from a non-product
	 * template can't poison the dataLayer with a wrong event.
	 */
	function lafka_dl_emit_view_item(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		if ( ! $product ) {
			return;
		}
		$item    = lafka_dl_item_payload( $product, 1 );
		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => isset( $item['price'] ) ? (float) $item['price'] : 0.0,
			'items'    => array( $item ),
		);
		lafka_dl_emit_push( 'view_item', $payload );
	}
}

if ( ! function_exists( 'lafka_dl_resolve_list_label' ) ) {
	/**
	 * Resolve `item_list_id` + `item_list_name` for the current view.
	 *
	 * Distinguishes:
	 *   - /menu/ landing  → ('menu_page', 'Menu page')
	 *   - product category archive → ('category_{slug}', '{Name}')
	 *   - product tag archive      → ('tag_{slug}', '{Name}')
	 *   - shop page                → ('shop', 'Shop')
	 *   - fallback                 → ('related', 'Related products')
	 *
	 * @return array{0:string,1:string}
	 */
	function lafka_dl_resolve_list_label(): array {
		// /menu/ — a custom WP page in the Lafka stack (see project memory).
		if ( function_exists( 'is_page' ) && is_page( 'menu' ) ) {
			return array( 'menu_page', 'Menu page' );
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			$slug = ( is_object( $term ) && isset( $term->slug ) ) ? (string) $term->slug : 'unknown';
			$name = ( is_object( $term ) && isset( $term->name ) ) ? (string) $term->name : 'Category';
			return array( 'category_' . $slug, $name );
		}
		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
			$slug = ( is_object( $term ) && isset( $term->slug ) ) ? (string) $term->slug : 'unknown';
			$name = ( is_object( $term ) && isset( $term->name ) ) ? (string) $term->name : 'Tag';
			return array( 'tag_' . $slug, $name );
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return array( 'shop', 'Shop' );
		}
		return array( 'related', 'Related products' );
	}
}

if ( ! function_exists( 'lafka_dl_emit_view_item_list' ) ) {
	/**
	 * `view_item_list` — emit on category archive / /menu/ / shop page load.
	 *
	 * Uses the main query's posts as the item list (the visible product list
	 * the user is browsing). Skips emit on singular product views — those
	 * are handled by view_item.
	 *
	 * Caps the items array at 50 to avoid blowing past GA4's 500-event-param
	 * size budget on category archives with thousands of products.
	 */
	function lafka_dl_emit_view_item_list(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return; // PDP handles view_item.
		}
		$is_menu     = function_exists( 'is_page' ) && is_page( 'menu' );
		$is_category = function_exists( 'is_product_category' ) && is_product_category();
		$is_tag      = function_exists( 'is_product_tag' ) && is_product_tag();
		$is_shop     = function_exists( 'is_shop' ) && is_shop();
		if ( ! ( $is_menu || $is_category || $is_tag || $is_shop ) ) {
			return;
		}

		list( $list_id, $list_name ) = lafka_dl_resolve_list_label();

		// Pull product IDs from the main query, capped for safety.
		$items = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$args = array(
				'status' => 'publish',
				'limit'  => 50,
			);
			if ( $is_category ) {
				$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
				if ( is_object( $term ) && isset( $term->slug ) ) {
					$args['category'] = array( $term->slug );
				}
			}
			$products = wc_get_products( $args );
			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					$items[] = lafka_dl_item_payload( $product, 1 );
				}
			}
		}

		$payload = array(
			'item_list_id'   => $list_id,
			'item_list_name' => $list_name,
			'items'          => $items,
		);
		lafka_dl_emit_push( 'view_item_list', $payload );
	}
}

if ( ! function_exists( 'lafka_dl_emit_view_cart' ) ) {
	/**
	 * `view_cart` — emit on /cart/ page load.
	 */
	function lafka_dl_emit_view_cart(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$items = lafka_dl_items_from_cart();
		if ( empty( $items ) ) {
			return;
		}
		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => lafka_dl_cart_value(),
			'items'    => $items,
		);
		lafka_dl_emit_push( 'view_cart', $payload );
	}
}

if ( ! function_exists( 'lafka_dl_emit_begin_checkout' ) ) {
	/**
	 * `begin_checkout` — emit on /checkout/ page load.
	 *
	 * Skips emit when the cart is empty (checkout redirects to cart, but the
	 * view_cart on that destination already handles signal).
	 */
	function lafka_dl_emit_begin_checkout(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Don't fire on /order-received/ — that's a checkout-endpoint URL but
		// represents `purchase`, not `begin_checkout`.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		$items = lafka_dl_items_from_cart();
		if ( empty( $items ) ) {
			return;
		}
		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => lafka_dl_cart_value(),
			'items'    => $items,
		);
		lafka_dl_emit_push( 'begin_checkout', $payload );
	}
}

if ( ! function_exists( 'lafka_dl_emit_purchase' ) ) {
	/**
	 * `purchase` — emit on woocommerce_thankyou, once per order.
	 *
	 * Idempotent: stores `_lafka_dl_purchase_fired` order meta after the first
	 * emit so refresh / re-render of /order-received/ doesn't double-count.
	 * Per Google's GA4 spec, purchase must fire exactly once per transaction.
	 *
	 * @param int $order_id
	 */
	function lafka_dl_emit_purchase( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		// Idempotency gate.
		if ( function_exists( 'get_post_meta' ) ) {
			$fired = get_post_meta( $order_id, '_lafka_dl_purchase_fired', true );
			if ( '1' === (string) $fired ) {
				return;
			}
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$items    = array();
		$line_get = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
		if ( is_array( $line_get ) ) {
			foreach ( $line_get as $line ) {
				$product = method_exists( $line, 'get_product' ) ? $line->get_product() : null;
				$qty     = method_exists( $line, 'get_quantity' ) ? (int) $line->get_quantity() : 1;
				if ( $product ) {
					$items[] = lafka_dl_item_payload( $product, $qty );
				}
			}
		}

		$currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : lafka_dl_currency();
		$total    = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
		$tax      = method_exists( $order, 'get_total_tax' ) ? (float) $order->get_total_tax() : 0.0;
		$shipping = method_exists( $order, 'get_shipping_total' ) ? (float) $order->get_shipping_total() : 0.0;

		$payload = array(
			'transaction_id' => (string) $order_id,
			'currency'       => $currency,
			'value'          => round( $total, 2 ),
			'tax'            => round( $tax, 2 ),
			'shipping'       => round( $shipping, 2 ),
			'items'          => $items,
		);

		lafka_dl_emit_push( 'purchase', $payload );

		// Lock so this order can't double-fire across page refreshes.
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $order_id, '_lafka_dl_purchase_fired', 1 );
		}
	}
}

// ============================================================================
// Interaction events (server-side hook + JS mirror for AJAX scenarios).
// ============================================================================

if ( ! function_exists( 'lafka_dl_emit_add_to_cart' ) ) {
	/**
	 * `add_to_cart` — fire on woocommerce_add_to_cart for non-AJAX adds.
	 *
	 * AJAX adds are handled by the fragment filter
	 * lafka_dl_inject_ajax_add_to_cart() so the JS callback that processes
	 * the fragment can push the event in the same tick.
	 *
	 * Stores the payload in a transient keyed by session so the next page
	 * load can flush it to the dataLayer (server-side AJAX-less add-to-cart
	 * triggers a full page reload to /cart/ or /product/, depending on flow).
	 *
	 * @param string $cart_item_key
	 * @param int    $product_id
	 * @param int    $quantity
	 * @param int    $variation_id
	 * @param array  $variation
	 * @param array  $cart_item_data
	 */
	function lafka_dl_emit_add_to_cart( $cart_item_key, $product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ): void {
		// Variation overrides parent when set.
		$id = (int) ( $variation_id > 0 ? $variation_id : $product_id );
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return;
		}
		$qty  = max( 1, (int) $quantity );
		$item = lafka_dl_item_payload( $product, $qty );

		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => isset( $item['price'] ) ? (float) $item['price'] * $qty : 0.0,
			'items'    => array( $item ),
		);

		// During an AJAX request, the fragment filter has already wired the
		// event into the response — we don't need to also emit a <script>
		// here (the page won't re-render anyway). Only emit synchronously
		// for non-AJAX flows.
		$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		if ( $is_ajax ) {
			return;
		}

		// Queue for emit on the next request: server-side add-to-cart typically
		// redirects, so we can't echo a <script> here. Store on the session.
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && ! empty( $wc->session ) && method_exists( $wc->session, 'set' ) ) {
				$queue   = $wc->session->get( '_lafka_dl_pending_events', array() );
				$queue   = is_array( $queue ) ? $queue : array();
				$queue[] = array(
					'event' => 'add_to_cart',
					'payload' => $payload,
				);
				$wc->session->set( '_lafka_dl_pending_events', $queue );
				return;
			}
		}
	}
}

if ( ! function_exists( 'lafka_dl_emit_remove_from_cart' ) ) {
	/**
	 * `remove_from_cart` — fire on woocommerce_cart_item_removed.
	 *
	 * Queues to the WC session for emit on the next page load; the cart
	 * remove action redirects, so we can't echo a <script> inline.
	 *
	 * @param string $cart_item_key
	 * @param object $cart WC_Cart instance.
	 */
	function lafka_dl_emit_remove_from_cart( $cart_item_key, $cart = null ): void {
		// Try to get the removed line via WC()->cart->removed_cart_contents.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->cart ) ) {
			return;
		}
		$removed = property_exists( $wc->cart, 'removed_cart_contents' ) ? $wc->cart->removed_cart_contents : array();
		if ( ! is_array( $removed ) || ! isset( $removed[ $cart_item_key ] ) ) {
			return;
		}
		$line    = $removed[ $cart_item_key ];
		$product = isset( $line['data'] ) ? $line['data'] : null;
		if ( ! $product ) {
			$pid     = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
			$product = $pid > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		}
		if ( ! $product ) {
			return;
		}
		$qty     = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
		$item    = lafka_dl_item_payload( $product, $qty );
		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => isset( $item['price'] ) ? (float) $item['price'] * $qty : 0.0,
			'items'    => array( $item ),
		);

		if ( ! empty( $wc->session ) && method_exists( $wc->session, 'set' ) ) {
			$queue   = $wc->session->get( '_lafka_dl_pending_events', array() );
			$queue   = is_array( $queue ) ? $queue : array();
			$queue[] = array(
				'event' => 'remove_from_cart',
				'payload' => $payload,
			);
			$wc->session->set( '_lafka_dl_pending_events', $queue );
		}
	}
}

if ( ! function_exists( 'lafka_dl_emit_pending_session_events' ) ) {
	/**
	 * Flush any queued events from the WC session into the dataLayer.
	 *
	 * Fires on wp_footer priority 5. Pops the queue so each event fires once.
	 */
	function lafka_dl_emit_pending_session_events(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) || ! method_exists( $wc->session, 'get' ) ) {
			return;
		}
		$queue = $wc->session->get( '_lafka_dl_pending_events', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['event'] ) || empty( $entry['payload'] ) ) {
				continue;
			}
			lafka_dl_emit_push( (string) $entry['event'], (array) $entry['payload'] );
		}
		// Drain the queue so the next request starts clean.
		if ( method_exists( $wc->session, 'set' ) ) {
			$wc->session->set( '_lafka_dl_pending_events', array() );
		}
	}
}

if ( ! function_exists( 'lafka_dl_inject_ajax_add_to_cart' ) ) {
	/**
	 * Inject the GA4 add_to_cart payload into WC's AJAX response.
	 *
	 * WC's wc-ajax=add_to_cart endpoint returns a JSON envelope with cart
	 * fragments. We add a `lafka_dl_event` key carrying the dataLayer push
	 * so the client JS (lafka-dl-client.js) can pick it up in the same tick
	 * that the fragment refresh happens.
	 *
	 * @param array $fragments
	 * @param int   $product_id  Optional: WC passes this on some versions.
	 * @return array
	 */
	function lafka_dl_inject_ajax_add_to_cart( $fragments, $product_id = 0 ) {
		if ( ! is_array( $fragments ) ) {
			$fragments = array();
		}

		// Resolve the product from POST['product_id'] when WC didn't pass it.
		if ( ! $product_id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! $product_id && isset( $_POST['add-to-cart'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$product_id = (int) $_POST['add-to-cart'];
			}
		}
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return $fragments;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $fragments;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$qty  = isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1;
		$item = lafka_dl_item_payload( $product, $qty );

		$payload = array(
			'currency' => lafka_dl_currency(),
			'value'    => isset( $item['price'] ) ? (float) $item['price'] * $qty : 0.0,
			'items'    => array( $item ),
		);

		$fragments['lafka_dl_event'] = array(
			'event'   => 'add_to_cart',
			'payload' => $payload,
		);
		return $fragments;
	}
}

// ============================================================================
// Client-side JS enqueue — conditional on an analytics ID being set.
// ============================================================================

if ( ! function_exists( 'lafka_dl_enqueue_client' ) ) {
	/**
	 * Enqueue the dataLayer client JS only when at least one analytics ID is
	 * configured. Unconfigured sites pay zero request cost.
	 */
	function lafka_dl_enqueue_client(): void {
		// Skip enqueue when no dataLayer-consuming destination is wired.
		// Delegates to the shared gate so this path can never drift from the
		// custom-events / page_context / store-events enqueue rules.
		$has_id = false;
		if ( function_exists( 'lafka_analytics_has_datalayer_destination' ) ) {
			$has_id = lafka_analytics_has_datalayer_destination();
		}
		if ( ! $has_id ) {
			return;
		}

		$src     = plugins_url( 'assets/js/lafka-dl-client.js', LAFKA_PLUGIN_FILE );
		$rel     = 'assets/js/lafka-dl-client.js';
		$version = function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $rel ) : '9.24.0';

		wp_enqueue_script(
			'lafka-dl-client',
			$src,
			array(),
			$version,
			true
		);
	}
}

// ============================================================================
// Hook registration.
// ============================================================================

if ( function_exists( 'add_action' ) ) {
	// View events — server-rendered <script> emit.
	add_action( 'woocommerce_before_single_product_summary', 'lafka_dl_emit_view_item', 5 );
	add_action( 'woocommerce_before_main_content', 'lafka_dl_emit_view_item_list', 5 );
	add_action( 'woocommerce_before_cart', 'lafka_dl_emit_view_cart', 5 );
	add_action( 'woocommerce_before_checkout_form', 'lafka_dl_emit_begin_checkout', 5 );
	add_action( 'woocommerce_thankyou', 'lafka_dl_emit_purchase', 10 );

	// Interaction events — queue to session, flush on next page load.
	add_action( 'woocommerce_add_to_cart', 'lafka_dl_emit_add_to_cart', 10, 6 );
	add_action( 'woocommerce_cart_item_removed', 'lafka_dl_emit_remove_from_cart', 10, 2 );

	// Session flush — fires near the top of wp_footer so the events land
	// before any JS that might depend on them (e.g. GTM page-view).
	add_action( 'wp_footer', 'lafka_dl_emit_pending_session_events', 5 );

	// Enqueue client JS conditional on analytics config.
	add_action( 'wp_enqueue_scripts', 'lafka_dl_enqueue_client', 20 );
}

if ( function_exists( 'add_filter' ) ) {
	// AJAX add-to-cart payload mirrored into the fragment response.
	add_filter( 'woocommerce_add_to_cart_fragments', 'lafka_dl_inject_ajax_add_to_cart', 10, 2 );
}
