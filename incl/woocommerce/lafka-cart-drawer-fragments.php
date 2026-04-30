<?php
/**
 * Cart drawer fragments — auto-refresh the slide-in drawer when cart changes.
 *
 * Adds two new keys to woocommerce_add_to_cart_fragments:
 *   - ul.lafka-cart-drawer__items    — re-renders the line-item list
 *   - div.lafka-cart-drawer__total   — re-renders subtotal + free-delivery threshold
 *
 * Drawer markup partial lives in lafka-child/partials/cart-drawer.php (W4-T14).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_cart_drawer_fragments' ) ) {
    function lafka_pdp_cart_drawer_fragments( array $fragments ): array {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $fragments;
        }

        ob_start();
        echo '<ul class="lafka-cart-drawer__items">';
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( ! $product ) { continue; }
            $name  = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key );
            $thumb = $product->get_image( 'woocommerce_gallery_thumbnail', array( 'loading' => 'lazy' ) );
            $price = WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] );
            ?>
            <li class="lafka-cart-drawer__item" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
                <span class="lafka-cart-drawer__thumb"><?php echo $thumb; ?></span>
                <span class="lafka-cart-drawer__name"><?php echo wp_kses_post( $name ); ?></span>
                <span class="lafka-cart-drawer__qty">×<?php echo esc_html( (string) (int) $cart_item['quantity'] ); ?></span>
                <span class="lafka-cart-drawer__price"><?php echo wp_kses_post( $price ); ?></span>
                <button type="button" class="lafka-cart-drawer__remove" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php esc_attr_e( 'Remove item', 'lafka-plugin' ); ?>">×</button>
            </li>
            <?php
        }
        echo '</ul>';
        $fragments['ul.lafka-cart-drawer__items'] = ob_get_clean();

        ob_start();
        $total = (float) WC()->cart->get_cart_contents_total();
        // Free-delivery threshold comes from the PDP Customizer (default 0 =
        // disabled, no hint rendered). Filter retained as a runtime override
        // for child plugins that want to vary by package type or user role.
        $threshold_setting = function_exists( 'get_theme_mod' )
            ? (float) get_theme_mod( 'lafka_pdp_free_delivery_threshold', 0 )
            : 0.0;
        $free_threshold = (float) apply_filters( 'lafka_pdp_free_delivery_threshold', $threshold_setting );
        $remaining      = max( 0, $free_threshold - $total );
        ?>
        <div class="lafka-cart-drawer__total">
            <div class="lafka-cart-drawer__subtotal">
                <span><?php esc_html_e( 'Subtotal', 'lafka-plugin' ); ?></span>
                <strong><?php echo wp_kses_post( wc_price( $total ) ); ?></strong>
            </div>
            <?php if ( $free_threshold > 0 && $remaining > 0 ): ?>
                <div class="lafka-cart-drawer__threshold">
                    <?php
                    printf(
                        esc_html__( 'Add %s more for free delivery', 'lafka-plugin' ),
                        wp_kses_post( wc_price( $remaining ) )
                    );
                    ?>
                </div>
            <?php elseif ( $free_threshold > 0 ): ?>
                <div class="lafka-cart-drawer__threshold lafka-cart-drawer__threshold--reached">
                    <?php esc_html_e( '✓ Free delivery unlocked', 'lafka-plugin' ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $fragments['div.lafka-cart-drawer__total'] = ob_get_clean();

        return $fragments;
    }
    add_filter( 'woocommerce_add_to_cart_fragments', 'lafka_pdp_cart_drawer_fragments' );
}
