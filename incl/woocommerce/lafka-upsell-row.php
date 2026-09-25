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

if ( ! function_exists( 'lafka_pdp_upsell_root_term' ) ) {
    /**
     * The top-level product category of a product's first category (or null).
     *
     * @param int $product_id Product id.
     * @return object|null Term-like object with slug + term_id.
     */
    function lafka_pdp_upsell_root_term( int $product_id ) {
        $cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        if ( is_wp_error( $cats ) || empty( $cats ) || ! is_object( $cats[0] ) ) {
            return null;
        }
        $root      = $cats[0];
        $ancestors = get_ancestors( (int) $cats[0]->term_id, 'product_cat', 'taxonomy' );
        if ( ! empty( $ancestors ) ) {
            $root_term = get_term( end( $ancestors ), 'product_cat' );
            if ( $root_term && ! is_wp_error( $root_term ) ) {
                $root = $root_term;
            }
        }
        return $root;
    }
}

if ( ! function_exists( 'lafka_pdp_get_upsell_ids' ) ) {
    /**
     * Upsell candidates for a product: the operator's picks for its top-level
     * category, else best sellers / recent products from OTHER top-level
     * categories (GX QA M-17: a poutine page no longer offers four poutines,
     * and every page no longer shows the same row). Filter
     * `lafka_pdp_upsell_ids` ($ids, $product_id) has the last word.
     *
     * @param int $product_id Product id.
     * @return int[]
     */
    function lafka_pdp_get_upsell_ids( int $product_id ): array {
        $root = lafka_pdp_upsell_root_term( $product_id );
        $ids  = array();
        if ( $root && isset( $root->slug ) ) {
            for ( $i = 1; $i <= 4; $i++ ) {
                $key = 'lafka_upsell_' . sanitize_key( (string) $root->slug ) . '_' . $i;
                $val = (int) get_theme_mod( $key, 0 );
                if ( $val > 0 ) {
                    $ids[] = $val;
                }
            }
        }

        if ( empty( $ids ) ) {
            $ids = lafka_pdp_get_upsell_fallback_ids( 16 );
            if ( $root && ! empty( $root->term_id ) ) {
                $root_id = (int) $root->term_id;
                $ids     = array_values(
                    array_filter(
                        $ids,
                        static function ( $id ) use ( $root_id, $product_id ) {
                            if ( (int) $id === $product_id ) {
                                return false;
                            }
                            $other = lafka_pdp_upsell_root_term( (int) $id );
                            return ! $other || (int) $other->term_id !== $root_id;
                        }
                    )
                );
            }
        }
        return array_values( array_map( 'intval', (array) apply_filters( 'lafka_pdp_upsell_ids', $ids, $product_id ) ) );
    }
}

if ( ! function_exists( 'lafka_pdp_get_upsell_fallback_ids' ) ) {
    /**
     * Resolve up to 8 candidate IDs for the upsell row.
     *
     * Combines: top bestsellers (up to 10) + recent in-stock products to
     * fill any remaining slots. Returns more than 4 because the renderer
     * filters out the current PDP product before slicing — without padding,
     * a top-3 list with current product as #1 would render only 2 cards.
     */
    function lafka_pdp_get_upsell_fallback_ids( int $want = 8 ): array {
        $ids = function_exists( 'lafka_pdp_get_bestseller_ids' )
            ? (array) lafka_pdp_get_bestseller_ids()
            : array();

        if ( count( $ids ) < $want ) {
            $more = wc_get_products(
                array(
					'limit'        => $want - count( $ids ),
					'status'       => 'publish',
					'stock_status' => 'instock',
					'return'       => 'ids',
					'exclude'      => $ids,
					'orderby'      => 'date',
					'order'        => 'DESC',
                ) 
            );
            if ( is_array( $more ) ) {
                $ids = array_merge( $ids, $more );
            }
        }

        return array_values( array_unique( array_map( 'intval', $ids ) ) );
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
            <h2 class="lafka-pdp-upsell__heading"><?php esc_html_e( 'Make it a meal', 'lafka-plugin' ); ?></h2>
            <div class="lafka-pdp-upsell__grid">
                <?php foreach ( $ids as $id ) : ?>
                    <?php
                    $product = wc_get_product( $id );
                    if ( ! $product || ! $product->is_visible() ) {
						continue; }
                    $url   = get_permalink( $id );
                    $img   = get_the_post_thumbnail( $id, 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) );
                    $price = $product->get_price_html();
                    $type  = $product->get_type();
                    ?>
                    <article class="lafka-pdp-upsell__card">
                        <a class="lafka-pdp-upsell__link" href="<?php echo esc_url( $url ); ?>">
                            <?php
                            // v9.21.0: get_the_post_thumbnail() returns empty string when a product
                            // has no featured image. Render an explicit placeholder div so the card's
                            // layout box never collapses (CSS paints a soft-tinted square via ::before
                            // when no <img> is present — but having an inline node also helps in case
                            // the cascade strips the pseudo).
                            if ( '' !== $img ) {
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() returns trusted WP-core HTML with attributes pre-escaped.
                                echo $img;
                            } else {
                                echo '<span class="lafka-pdp-upsell__placeholder" aria-hidden="true"></span>';
                            }
                            ?>
                            <span class="lafka-pdp-upsell__name"><?php echo esc_html( $product->get_name() ); ?></span>
                            <span class="lafka-pdp-upsell__price"><?php echo wp_kses_post( $price ); ?></span>
                        </a>
                        <button type="button"
                            class="lafka-pdp-upsell__add"
                            data-product-id="<?php echo esc_attr( (string) $id ); ?>"
                            data-product-type="<?php echo esc_attr( $type ); ?>"
                            data-permalink="<?php echo esc_url( $url ); ?>">
                            <?php esc_html_e( 'Add', 'lafka-plugin' ); ?><span class="screen-reader-text"> <?php echo esc_html( $product->get_name() ); ?></span>
                        </button>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
