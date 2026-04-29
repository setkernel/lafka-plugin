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
        $json = wp_json_encode( $payload );
        if ( false === $json ) {
            return;
        }

        setcookie( LAFKA_PDP_LAST_ORDER_COOKIE, $json, array(
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ) );
        $_COOKIE[ LAFKA_PDP_LAST_ORDER_COOKIE ] = $json;
    }
    add_action( 'woocommerce_thankyou', 'lafka_pdp_set_last_order_cookie' );
}

if ( ! function_exists( 'lafka_pdp_get_last_order' ) ) {
    function lafka_pdp_get_last_order(): ?array {
        if ( is_user_logged_in() ) {
            $orders = wc_get_orders( array(
                'customer_id' => get_current_user_id(),
                'status'      => array( 'completed', 'processing' ),
                'orderby'     => 'date',
                'order'       => 'DESC',
                'limit'       => 1,
            ) );
            if ( ! empty( $orders ) ) {
                $order = $orders[0];
                $items = array();
                foreach ( $order->get_items() as $item ) {
                    $items[] = array(
                        'product_id'   => $item->get_product_id(),
                        'variation_id' => $item->get_variation_id(),
                        'qty'          => (int) $item->get_quantity(),
                        'name'         => $item->get_name(),
                    );
                }
                return array( 'order_id' => $order->get_id(), 'items' => $items );
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
                <?php foreach ( $items as $i ): ?>
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

        $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Missing order_id' ), 400 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
        }

        if ( is_user_logged_in() && (int) $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }
        if ( ! is_user_logged_in() ) {
            $last = lafka_pdp_get_last_order();
            if ( ! $last || (int) ( $last['order_id'] ?? 0 ) !== $order_id ) {
                wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
            }
        }

        foreach ( $order->get_items() as $item ) {
            $product_id   = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $qty          = max( 1, (int) $item->get_quantity() );
            $variation    = array();
            foreach ( $item->get_meta_data() as $meta ) {
                $key = $meta->key;
                if ( strpos( $key, 'pa_' ) === 0 || strpos( $key, 'attribute_' ) === 0 ) {
                    $variation[ 'attribute_' . str_replace( 'attribute_', '', $key ) ] = $meta->value;
                }
            }
            WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation );
        }

        WC_AJAX::get_refreshed_fragments();
    }
    add_action( 'wp_ajax_lafka_pdp_reorder',        'lafka_pdp_reorder_ajax' );
    add_action( 'wp_ajax_nopriv_lafka_pdp_reorder', 'lafka_pdp_reorder_ajax' );
}
