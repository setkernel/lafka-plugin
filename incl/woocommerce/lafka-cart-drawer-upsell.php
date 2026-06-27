<?php
/**
 * Cart-drawer "Complete your meal" upsell.
 *
 * Renders up to 3 one-tap add suggestions in the slide-in cart drawer — the
 * highest-intent upsell moment (right after add-to-cart). Reuses the bestseller
 * / upsell-fallback logic, prioritises one-tap-able SIMPLE products (drinks /
 * sides / garlic fingers), and excludes anything already in the cart. The row
 * is wired into the woocommerce_add_to_cart_fragments refresh so it updates on
 * every cart change.
 *
 * Adds via WooCommerce's native ajax_add_to_cart (fires `added_to_cart`, which
 * the drawer already listens to), so no bespoke add path.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.32.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_cart_drawer_get_upsell_ids' ) ) {
	/**
	 * Up to 3 one-tap-able suggestions not already in the cart.
	 *
	 * @return int[]
	 */
	function lafka_cart_drawer_get_upsell_ids(): array {
		$in_cart = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $ci ) {
				$in_cart[] = (int) ( $ci['product_id'] ?? 0 );
			}
		}
		$out      = array();
		$is_addable = static function ( $id ) {
			$p = wc_get_product( $id );
			// One-tap add needs a SIMPLE, purchasable, in-stock product (drinks /
			// sides / garlic fingers) — variable products need the PDP.
			return $p && $p->is_visible() && $p->is_purchasable() && $p->is_in_stock() && ! $p->is_type( 'variable' );
		};
		$consider = static function ( $id ) use ( &$out, $in_cart, $is_addable ) {
			$id = (int) $id;
			if ( $id && ! in_array( $id, $in_cart, true ) && ! in_array( $id, $out, true ) && $is_addable( $id ) ) {
				$out[] = $id;
			}
		};

		// 1. Bestseller-driven fallbacks first (highest intent).
		if ( function_exists( 'lafka_pdp_get_upsell_fallback_ids' ) ) {
			foreach ( (array) lafka_pdp_get_upsell_fallback_ids() as $id ) {
				if ( count( $out ) >= 3 ) {
					break;
				}
				$consider( $id );
			}
		}
		// 2. Fill from popular SIMPLE products (most top sellers are variable).
		if ( count( $out ) < 3 ) {
			$more = wc_get_products(
				array(
					'limit'        => 12,
					'status'       => 'publish',
					'type'         => 'simple',
					'stock_status' => 'instock',
					'return'       => 'ids',
					'exclude'      => array_merge( $in_cart, $out ),
					'orderby'      => 'popularity',
				)
			);
			foreach ( (array) $more as $id ) {
				if ( count( $out ) >= 3 ) {
					break;
				}
				$consider( $id );
			}
		}
		return array_slice( $out, 0, 3 );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_render_upsell' ) ) {
	/**
	 * Output the upsell container. Always emits the wrapper (the WC fragment
	 * target) so the refresh can fill/empty it as the cart changes.
	 *
	 * @return void
	 */
	function lafka_cart_drawer_render_upsell(): void {
		echo '<div class="lafka-cart-drawer__upsell" data-lafka-drawer-upsell>';
		$ids = ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() )
			? lafka_cart_drawer_get_upsell_ids()
			: array();
		if ( ! empty( $ids ) ) {
			echo '<p class="lafka-cart-drawer__upsell-heading">' . esc_html__( 'Complete your meal', 'lafka-plugin' ) . '</p>';
			echo '<ul class="lafka-cart-drawer__upsell-list" role="list">';
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				$img = get_the_post_thumbnail(
                    $id,
                    'woocommerce_gallery_thumbnail',
                    array(
						'loading' => 'lazy',
						'class' => 'lafka-cart-drawer__upsell-img',
                    ) 
                );
				?>
				<li class="lafka-cart-drawer__upsell-item">
					<a class="lafka-cart-drawer__upsell-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
						<?php
						if ( '' !== $img ) {
							echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP-core thumbnail markup, pre-escaped.
						} else {
							echo '<span class="lafka-cart-drawer__upsell-img lafka-cart-drawer__upsell-img--ph" aria-hidden="true"></span>';
						}
						?>
						<span class="lafka-cart-drawer__upsell-name"><?php echo esc_html( $p->get_name() ); ?></span>
						<span class="lafka-cart-drawer__upsell-price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
					</a>
					<a href="<?php echo esc_url( $p->add_to_cart_url() ); ?>"
						data-quantity="1"
						data-product_id="<?php echo esc_attr( (string) $id ); ?>"
						class="lafka-cart-drawer__upsell-add add_to_cart_button ajax_add_to_cart"
						rel="nofollow"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s product */ __( 'Add %s to your order', 'lafka-plugin' ), wp_strip_all_tags( $p->get_name() ) ) ); ?>">
						<?php esc_html_e( '+ Add', 'lafka-plugin' ); ?>
					</a>
				</li>
				<?php
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}

// Refresh the upsell on every cart change (so in-cart items drop out).
if ( ! function_exists( 'lafka_cart_drawer_upsell_fragment' ) ) {
	add_filter( 'woocommerce_add_to_cart_fragments', 'lafka_cart_drawer_upsell_fragment' );
	function lafka_cart_drawer_upsell_fragment( array $fragments ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $fragments;
		}
		ob_start();
		lafka_cart_drawer_render_upsell();
		$fragments['div.lafka-cart-drawer__upsell'] = (string) ob_get_clean();
		return $fragments;
	}
}

// Ensure WooCommerce's ajax add-to-cart script is present wherever the drawer
// can appear, so the upsell "+ Add" works site-wide (not just shop pages).
if ( ! function_exists( 'lafka_cart_drawer_upsell_enqueue' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_cart_drawer_upsell_enqueue', 20 );
	function lafka_cart_drawer_upsell_enqueue(): void {
		if ( is_admin() || ! function_exists( 'WC' ) ) {
			return;
		}
		wp_enqueue_script( 'wc-add-to-cart' );
	}
}
