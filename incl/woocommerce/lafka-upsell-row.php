<?php
/**
 * Upsell row renderer ("Make it a meal").
 *
 * Resolves 4 product IDs:
 *   1. Operator-curated via lafka_upsell_<top-cat-slug>_<n> (Customizer panel from W4-T5)
 *   2. Fallback: top 4 best-sellers via lafka_pdp_get_bestseller_ids()
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_get_upsell_ids' ) ) {
    function lafka_pdp_get_upsell_ids( int $product_id ): array {
        $cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        if ( is_wp_error( $cats ) || empty( $cats ) ) {
            return lafka_pdp_get_upsell_fallback_ids();
        }

        $top_slug = $cats[0]->slug;
        $ancestors = get_ancestors( $cats[0]->term_id, 'product_cat', 'taxonomy' );
        if ( ! empty( $ancestors ) ) {
            $root_term = get_term( end( $ancestors ), 'product_cat' );
            if ( $root_term && ! is_wp_error( $root_term ) ) {
                $top_slug = $root_term->slug;
            }
        }

        $ids = array();
        for ( $i = 1; $i <= 4; $i++ ) {
            $key = 'lafka_upsell_' . sanitize_key( $top_slug ) . '_' . $i;
            $val = (int) get_theme_mod( $key, 0 );
            if ( $val > 0 ) {
                $ids[] = $val;
            }
        }

        if ( empty( $ids ) ) {
            return lafka_pdp_get_upsell_fallback_ids();
        }
        return $ids;
    }
}

if ( ! function_exists( 'lafka_pdp_get_upsell_fallback_ids' ) ) {
    function lafka_pdp_get_upsell_fallback_ids(): array {
        if ( function_exists( 'lafka_pdp_get_bestseller_ids' ) ) {
            $ids = lafka_pdp_get_bestseller_ids();
            if ( ! empty( $ids ) ) {
                return array_slice( $ids, 0, 4 );
            }
        }
        $q = wc_get_products( array(
            'limit' => 4, 'status' => 'publish',
            'stock_status' => 'instock', 'return' => 'ids',
        ) );
        return is_array( $q ) ? $q : array();
    }
}

if ( ! function_exists( 'lafka_pdp_render_upsell_row' ) ) {
    function lafka_pdp_render_upsell_row( int $product_id ): void {
        $ids = lafka_pdp_get_upsell_ids( $product_id );
        $ids = array_values( array_diff( $ids, array( $product_id ) ) );
        $ids = array_slice( $ids, 0, 4 );
        if ( empty( $ids ) ) {
            return;
        }
        ?>
        <div class="lafka-pdp-upsell">
            <h3 class="lafka-pdp-upsell__heading"><?php esc_html_e( 'Make it a meal', 'lafka-plugin' ); ?></h3>
            <div class="lafka-pdp-upsell__grid">
                <?php foreach ( $ids as $id ): ?>
                    <?php
                    $product = wc_get_product( $id );
                    if ( ! $product || ! $product->is_visible() ) { continue; }
                    $url   = get_permalink( $id );
                    $img   = get_the_post_thumbnail( $id, 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) );
                    $price = $product->get_price_html();
                    $type  = $product->get_type();
                    ?>
                    <article class="lafka-pdp-upsell__card">
                        <a class="lafka-pdp-upsell__link" href="<?php echo esc_url( $url ); ?>">
                            <?php echo $img; // escaped by core ?>
                            <span class="lafka-pdp-upsell__name"><?php echo esc_html( $product->get_name() ); ?></span>
                            <span class="lafka-pdp-upsell__price"><?php echo wp_kses_post( $price ); ?></span>
                        </a>
                        <button type="button"
                            class="lafka-pdp-upsell__add"
                            data-product-id="<?php echo esc_attr( (string) $id ); ?>"
                            data-product-type="<?php echo esc_attr( $type ); ?>"
                            data-permalink="<?php echo esc_url( $url ); ?>">
                            <?php esc_html_e( '+ Add', 'lafka-plugin' ); ?>
                        </button>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
