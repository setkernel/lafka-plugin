<?php
/**
 * Last-order card.
 *
 * Sets a 365-day cookie on woocommerce_thankyou with a JSON summary of the
 * order's line items. Reader returns null/array. Renderer echoes a "Your usual?"
 * card on PDP for returning visitors. Reorder AJAX endpoint re-adds the
 * order's items to the cart.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

const LAFKA_PDP_LAST_ORDER_COOKIE = 'lafka_recent_order';

if ( ! function_exists( 'lafka_pdp_extract_item_variation' ) ) {
    /**
     * Build the variation attribute map for an order line item.
     *
     * Keys are normalised to the `attribute_*` form WooCommerce expects when a
     * line is re-added to the cart, so the same selection (size, options, etc.)
     * is preserved on reorder.
     *
     * @param object $item Order line item ( WC_Order_Item_Product ).
     * @return array<string,string> Variation attributes keyed by `attribute_*`.
     */
    function lafka_pdp_extract_item_variation( $item ): array {
        $variation = array();
        foreach ( $item->get_meta_data() as $meta ) {
            $key = (string) $meta->key;
            if ( strpos( $key, 'pa_' ) === 0 || strpos( $key, 'attribute_' ) === 0 ) {
                $variation[ 'attribute_' . str_replace( 'attribute_', '', $key ) ] = $meta->value;
            }
        }
        return $variation;
    }
}

if ( ! function_exists( 'lafka_pdp_recent_order_signature' ) ) {
    /**
     * Deterministic HMAC over a stored recent-order payload.
     *
     * The recent-order cookie is fully client-controlled, so it must not be
     * trusted on its own. Only the server, holding wp_salt( 'auth' ), can mint a
     * valid signature for a given order id + line-item list. The reorder
     * endpoint recomputes this value and rejects any cookie whose signature does
     * not match, which prevents a guest from forging or tampering with the
     * payload to prime the cart from a hand-crafted (or another customer's)
     * order. The signature covers both the order id and the items so neither can
     * be altered after the fact.
     *
     * @param int                       $order_id Order the payload describes.
     * @param array<int,array<string,mixed>> $items Stored line-item summaries.
     * @return string Hex-encoded HMAC-SHA256 signature.
     */
    function lafka_pdp_recent_order_signature( int $order_id, array $items ): string {
        $canonical = wp_json_encode(
            array(
                'order_id' => $order_id,
                'items'    => $items,
            )
        );
        return hash_hmac( 'sha256', (string) $canonical, wp_salt( 'auth' ) );
    }
}

if ( ! function_exists( 'lafka_pdp_set_last_order_cookie' ) ) {
    function lafka_pdp_set_last_order_cookie( int $order_id ): void {
        if ( headers_sent() ) {
            return;
        }
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            return;
        }

        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = array(
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'qty'          => (int) $item->get_quantity(),
                'name'         => $item->get_name(),
                'variation'    => lafka_pdp_extract_item_variation( $item ),
            );
            if ( count( $items ) >= 6 ) {
                break;
            }
        }
        if ( empty( $items ) ) {
            return;
        }

        $payload = array(
            'order_id' => (int) $order_id,
            'items'    => $items,
        );
        // Tamper-proof the client-side cookie. Only the server can mint this
        // signature, so the reorder endpoint can detect and reject a forged or
        // edited cookie before acting on its contents.
        $payload['sig'] = lafka_pdp_recent_order_signature( (int) $order_id, $items );
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            return;
        }

        setcookie(
            LAFKA_PDP_LAST_ORDER_COOKIE,
            $json,
            array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
            ) 
        );
        $_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] = $json;
    }
    add_action( 'woocommerce_thankyou', 'lafka_pdp_set_last_order_cookie' );
}

if ( ! function_exists( 'lafka_pdp_get_last_order' ) ) {
    function lafka_pdp_get_last_order(): ?array {
        if ( is_user_logged_in() ) {
            $orders = wc_get_orders(
                array(
					'customer_id' => get_current_user_id(),
					'status'      => array( 'completed', 'processing' ),
					'orderby'     => 'date',
					'order'       => 'DESC',
					'limit'       => 1,
                ) 
            );
            if ( ! empty( $orders ) ) {
                $order = $orders[0];
                $items = array();
                foreach ( $order->get_items() as $item ) {
                    $items[] = array(
                        'product_id'   => $item->get_product_id(),
                        'variation_id' => $item->get_variation_id(),
                        'qty'          => (int) $item->get_quantity(),
                        'name'         => $item->get_name(),
                        'variation'    => lafka_pdp_extract_item_variation( $item ),
                    );
                }
                return array(
					'order_id' => $order->get_id(),
					'items' => $items,
				);
            }
        }

        if ( ! empty( $_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] ) ) {
            $raw = wp_unslash( $_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] );
            $parsed = json_decode( (string) $raw, true );
            if ( is_array( $parsed ) && isset( $parsed['items'] ) && is_array( $parsed['items'] ) ) {
                return $parsed;
            }
        }
        return null;
    }
}

if ( ! function_exists( 'lafka_pdp_render_last_order_card' ) ) {
    function lafka_pdp_render_last_order_card(): void {
        $last = lafka_pdp_get_last_order();
        if ( ! $last || empty( $last['items'] ) ) {
            return;
        }
        $items = array_slice( $last['items'], 0, 3 );

        ?>
        <div class="lafka-pdp-last-order" data-order-id="<?php echo esc_attr( (string) $last['order_id'] ); ?>">
            <div class="lafka-pdp-last-order__header">
                <span class="lafka-pdp-last-order__heading"><?php esc_html_e( 'Your usual?', 'lafka-plugin' ); ?></span>
            </div>
            <ul class="lafka-pdp-last-order__items">
                <?php foreach ( $items as $i ) : ?>
                    <li><?php echo esc_html( $i['name'] ); ?></li>
                <?php endforeach; ?>
            </ul>
            <button
                type="button"
                class="lafka-pdp-last-order__reorder"
                data-lafka-reorder="<?php echo esc_attr( (string) $last['order_id'] ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'lafka_pdp_reorder' ) ); ?>">
                <?php esc_html_e( 'Reorder', 'lafka-plugin' ); ?>
            </button>
        </div>
        <?php
    }
}

if ( ! function_exists( 'lafka_pdp_reorder_ajax' ) ) {
    function lafka_pdp_reorder_ajax(): void {
        check_ajax_referer( 'lafka_pdp_reorder', 'nonce' );

        if ( is_user_logged_in() ) {
            // Authenticated users: the order is loaded from the DB only after we
            // confirm it belongs to the current user, so reading its live line
            // items is safe.
            $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
            if ( ! $order_id ) {
                wp_send_json_error( array( 'message' => 'Missing order_id' ), 400 );
            }
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
            }
            if ( (int) $order->get_customer_id() !== get_current_user_id() ) {
                wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
            }

            foreach ( $order->get_items() as $item ) {
                $product_id   = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $qty          = max( 1, (int) $item->get_quantity() );
                $variation    = lafka_pdp_extract_item_variation( $item );
                WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
            }

            WC_AJAX::get_refreshed_fragments();
            return;
        }

        // Guests have no authenticated ownership signal, and the recent-order
        // cookie is fully client-controlled, so we must NOT fetch any order from
        // the DB by a request-supplied ID. Doing so was an IDOR: an attacker
        // could forge the cookie and enumerate sequential order IDs to dump any
        // order's line items. Instead the cart is rebuilt purely from the items
        // captured in the cookie, which can only ever describe this visitor's
        // own previous order. The POSTed order_id is ignored entirely here.
        $last = lafka_pdp_get_last_order();
        if ( ! $last || empty( $last['items'] ) || ! is_array( $last['items'] ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        // Authorisation for guests is derived from the server-minted HMAC, NOT
        // from the (forgeable) cookie itself. A signature only exists if this
        // browser actually completed a checkout, so an attacker cannot hand-craft
        // a payload — neither to prime their own cart nor to probe the store. If
        // the signature is missing or does not validate, refuse to act on the
        // cookie at all.
        $signature = isset( $last['sig'] ) && is_string( $last['sig'] ) ? $last['sig'] : '';
        $cookie_order_id = isset( $last['order_id'] ) ? absint( $last['order_id'] ) : 0;
        $expected_signature = lafka_pdp_recent_order_signature( $cookie_order_id, $last['items'] );
        if ( '' === $signature || ! hash_equals( $expected_signature, $signature ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $added = false;
        foreach ( $last['items'] as $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }
            $product_id = isset( $line['product_id'] ) ? absint( $line['product_id'] ) : 0;
            if ( ! $product_id ) {
                continue;
            }
            $variation_id = isset( $line['variation_id'] ) ? absint( $line['variation_id'] ) : 0;
            $qty          = isset( $line['qty'] ) ? max( 1, absint( $line['qty'] ) ) : 1;
            $variation    = array();
            if ( isset( $line['variation'] ) && is_array( $line['variation'] ) ) {
                foreach ( $line['variation'] as $vkey => $vval ) {
                    $variation[ sanitize_text_field( (string) $vkey ) ] = sanitize_text_field( (string) $vval );
                }
            }
            if ( WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation ) ) {
                $added = true;
            }
        }

        if ( ! $added ) {
            wp_send_json_error( array( 'message' => 'Nothing to reorder' ), 400 );
        }

        WC_AJAX::get_refreshed_fragments();
    }
    add_action( 'wp_ajax_lafka_pdp_reorder', 'lafka_pdp_reorder_ajax' );
    add_action( 'wp_ajax_nopriv_lafka_pdp_reorder', 'lafka_pdp_reorder_ajax' );
}
