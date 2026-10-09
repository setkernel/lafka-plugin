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
		$in_cart   = array();
		$cart_cats = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $ci ) {
				$in_cart[] = (int) ( $ci['product_id'] ?? 0 );
				$product   = $ci['data'] ?? null;
				if ( is_object( $product ) && method_exists( $product, 'get_category_ids' ) ) {
					$cart_cats = array_merge( $cart_cats, array_map( 'intval', (array) $product->get_category_ids() ) );
				}
			}
		}
		$deal_cats = lafka_cart_drawer_upsell_deal_category_ids();
		$pool      = array(); // id => whether its category is new to the cart.
		// Closed and not taking add-to-cart: nothing to suggest (the products stay
		// purchasable in WooCommerce; the order-hours gate refuses the add).
		$cart_blocked = class_exists( 'Lafka_Order_Hours' ) && Lafka_Order_Hours::is_add_to_cart_blocked();
		$is_addable   = static function ( $p ) use ( $deal_cats, $cart_blocked ) {
			// One-tap add needs a purchasable, in-stock product WooCommerce can
			// add from a list (drinks / sides / garlic fingers): variable products
			// and Deals need their page. Deals and combos are not "a little
			// extra" (O-23).
			if ( ! $p || ! $p->is_visible() || ! $p->is_purchasable() || ! $p->is_in_stock() || ! $p->supports( 'ajax_add_to_cart' ) || $cart_blocked ) {
				return false;
			}
			foreach ( array( 'bundle', 'grouped', 'composite', 'woosb' ) as $type ) {
				if ( $p->is_type( $type ) ) {
					return false;
				}
			}
			$cats = method_exists( $p, 'get_category_ids' ) ? array_map( 'intval', (array) $p->get_category_ids() ) : array();

			return array() === array_intersect( $cats, $deal_cats );
		};
		$consider     = static function ( $id ) use ( &$pool, $in_cart, $cart_cats, $is_addable ) {
			$id = (int) $id;
			if ( ! $id || in_array( $id, $in_cart, true ) || isset( $pool[ $id ] ) || count( $pool ) >= 8 ) {
				return;
			}
			$p = wc_get_product( $id );
			if ( $is_addable( $p ) ) {
				$cats        = method_exists( $p, 'get_category_ids' ) ? array_map( 'intval', (array) $p->get_category_ids() ) : array();
				$pool[ $id ] = array() === array_intersect( $cats, $cart_cats );
			}
		};

		// 1. Bestseller-driven fallbacks first (highest intent).
		if ( function_exists( 'lafka_pdp_get_upsell_fallback_ids' ) ) {
			foreach ( (array) lafka_pdp_get_upsell_fallback_ids() as $id ) {
				$consider( $id );
			}
		}
		// 2. Fill from popular SIMPLE products (most top sellers are variable).
		if ( count( $pool ) < 8 ) {
			$more = wc_get_products(
				array(
					'limit'        => 12,
					'status'       => 'publish',
					'type'         => 'simple',
					'stock_status' => 'instock',
					'return'       => 'ids',
					'exclude'      => array_merge( $in_cart, array_keys( $pool ) ),
					'orderby'      => 'popularity',
				)
			);
			foreach ( (array) $more as $id ) {
				$consider( $id );
			}
		}

		// Rotation: the row changes as the order changes instead of always
		// showing the same three (stable for one cart, so a refresh never
		// reshuffles what the customer is looking at).
		$sorted = $in_cart;
		sort( $sorted );
		$seed   = (int) crc32( implode( ',', $sorted ) );
		$rotate = static function ( array $ids ) use ( $seed ): array {
			$ids = array_slice( $ids, 0, 6 );
			if ( count( $ids ) < 2 ) {
				return $ids;
			}
			$offset = $seed % count( $ids );

			return array_merge( array_slice( $ids, $offset ), array_slice( $ids, 0, $offset ) );
		};

		// Relevance: something from a category the order does not have yet
		// (a drink or a dip next to a pizza) before more of the same.
		$fresh  = $rotate( array_keys( array_filter( $pool ) ) );
		$same   = $rotate( array_keys( array_diff_key( $pool, array_filter( $pool ) ) ) );
		$window = array_merge( $fresh, $same );

		/**
		 * Filter the drawer upsell product ids (max 3 are shown).
		 *
		 * @since 10.3.0
		 * @param int[] $ids     Suggested product ids.
		 * @param int[] $in_cart Product ids in the cart.
		 */
		return array_slice( array_values( array_map( 'intval', (array) apply_filters( 'lafka_cart_drawer_upsell_ids', array_slice( $window, 0, 3 ), $in_cart ) ) ), 0, 3 );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_upsell_deal_category_ids' ) ) {
	/**
	 * Product categories that hold deals / combos, never suggested as "a
	 * little extra": the menu's deals category as the Lafka theme resolves it
	 * (its Customizer pick, else its automatic slug rule). Filter
	 * `lafka_cart_drawer_upsell_excluded_categories`.
	 *
	 * @return int[]
	 */
	function lafka_cart_drawer_upsell_deal_category_ids(): array {
		$ids  = array();
		$deal = function_exists( 'lafka_get_deals_category_id' ) ? (int) lafka_get_deals_category_id() : 0;
		if ( $deal > 0 ) {
			$ids[] = $deal;
		}

		/**
		 * Filter the categories the drawer upsell never suggests from.
		 *
		 * @since 10.3.0
		 * @param int[] $ids product_cat term ids.
		 */
		return array_values( array_unique( array_map( 'intval', (array) apply_filters( 'lafka_cart_drawer_upsell_excluded_categories', $ids ) ) ) );
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
		/**
		 * Fires first inside the drawer's upsell wrapper, which refreshes on every
		 * cart change: a place for a short cart message (the Deals nudge).
		 *
		 * @since 10.4.0
		 */
		do_action( 'lafka_cart_drawer_upsell_start' );
		$ids = ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() )
			? lafka_cart_drawer_get_upsell_ids()
			: array();
		if ( ! empty( $ids ) ) {
			$default = __( 'Complete your meal', 'lafka-plugin' );
			/**
			 * Filter the drawer upsell heading (plain text; empty keeps the default).
			 *
			 * @since 10.2.0
			 * @param string $heading Heading text.
			 */
			$heading = trim( (string) apply_filters( 'lafka_cart_drawer_upsell_heading', $default ) );
			echo '<p class="lafka-cart-drawer__upsell-heading">' . esc_html( '' !== $heading ? $heading : $default ) . '</p>';
			echo '<ul class="lafka-cart-drawer__upsell-list" role="list">';
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				/**
				 * Filter a short plain-text note under an upsell row's name
				 * (e.g. the product's short description). '' shows none.
				 *
				 * @since 10.2.0
				 * @param string     $note    Note text.
				 * @param WC_Product $product Suggested product.
				 */
				$note = trim( wp_strip_all_tags( (string) apply_filters( 'lafka_cart_drawer_upsell_row_note', '', $p ) ) );
				/**
				 * Filter the upsell add-button label (plain text).
				 *
				 * @since 10.2.0
				 * @param string     $label   Button label.
				 * @param WC_Product $product Suggested product.
				 */
				$add_label = trim( (string) apply_filters( 'lafka_cart_drawer_upsell_add_label', __( '+ Add', 'lafka-plugin' ), $p ) );
				$img       = get_the_post_thumbnail(
					$id,
					'woocommerce_gallery_thumbnail',
					array(
						'loading' => 'lazy',
						'class'   => 'lafka-cart-drawer__upsell-img',
					)
				);
				?>
				<li class="lafka-cart-drawer__upsell-item lafka-card lafka-card--sm lafka-card--flat">
					<a class="lafka-cart-drawer__upsell-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
						<?php
						if ( '' !== $img ) {
							echo wp_kses( $img, lafka_kses_allowed_image_html() );
						} else {
							echo '<span class="lafka-cart-drawer__upsell-img lafka-cart-drawer__upsell-img--ph" aria-hidden="true"></span>';
						}
						?>
						<span class="lafka-cart-drawer__upsell-name"><?php echo esc_html( $p->get_name() ); ?></span>
						<?php if ( '' !== $note ) : ?>
							<span class="lafka-cart-drawer__upsell-note"><?php echo esc_html( $note ); ?></span>
						<?php endif; ?>
						<span class="lafka-cart-drawer__upsell-price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
					</a>
					<a href="<?php echo esc_url( $p->add_to_cart_url() ); ?>"
						data-quantity="1"
						data-product_id="<?php echo esc_attr( (string) $id ); ?>"
						class="lafka-cart-drawer__upsell-add lafka-btn lafka-btn--primary lafka-btn--sm add_to_cart_button ajax_add_to_cart"
						rel="nofollow"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s product */ __( 'Add %s to your order', 'lafka-plugin' ), wp_strip_all_tags( $p->get_name() ) ) ); ?>">
						<?php echo esc_html( '' !== $add_label ? $add_label : __( '+ Add', 'lafka-plugin' ) ); ?>
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
