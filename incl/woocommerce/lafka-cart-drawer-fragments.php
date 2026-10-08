<?php
/**
 * Cart drawer fragments — single source of truth (SSOT) for the slide-in
 * drawer's line-item rows and the subtotal + free-delivery total block.
 *
 * The plugin owns the drawer markup. Two callables render it:
 *
 *   - lafka_cart_drawer_render_item( $key, $item ) — one <li> row, or the
 *     empty-state <li> when called without an item (the empty state is folded
 *     in so the <ul> can swap empty<->filled in place on refresh).
 *   - lafka_cart_drawer_render_total()             — the full
 *     div.lafka-cart-drawer__total block: subtotal + the rich .lafka-fdp
 *     free-delivery progress component, emitted directly server-side.
 *
 * Both are called by the theme partial (partials/cart-drawer.php) on initial
 * server render AND by the woocommerce_add_to_cart_fragments filter below on
 * AJAX refresh, so the row + total markup live once, are translated once
 * (text domain 'lafka-plugin'), and the two paths can never desync — the
 * exact failure mode behind the v6.14.0 empty-state and v9.32.0 first-add
 * drawer bugs. This mirrors the lafka_cart_drawer_render_upsell SSOT pattern.
 *
 * Because the rich .lafka-fdp markup is now emitted in BOTH paths (and even
 * for an empty initial cart), lafka-fdp-tracker.js no longer needs to
 * post-process a plain-text threshold into the rich component; it keeps only
 * its dataLayer/analytics duties (cart snapshot + free_delivery_unlocked).
 *
 * Drawer markup partial lives in lafka-theme/partials/cart-drawer.php (W4-T14).
 *
 * The drawer fires WooCommerce's mini-cart actions where core's mini-cart does
 * (woocommerce_before_mini_cart_contents / woocommerce_mini_cart_contents around
 * the rows here; the widget_shopping_cart button actions and
 * woocommerce_before_mini_cart / woocommerce_after_mini_cart in the drawer
 * partials), and its row details go through the cart-item filters core's
 * mini-cart uses (woocommerce_widget_cart_item_visible, _cart_item_name,
 * _cart_item_thumbnail, _cart_item_subtotal), so extensions that hook the
 * mini-cart work here.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_cart_drawer_render_item' ) ) {
	/**
	 * Render ONE cart-drawer line-item <li>, or the empty-state <li> when
	 * $cart_item is omitted/empty. Single source of truth for the row markup +
	 * strings: both the theme partial and the AJAX fragment call this, so the
	 * two paths stay byte-identical.
	 *
	 * @param string                   $cart_item_key WC cart-item key ('' for the empty state).
	 * @param array<string,mixed>|null $cart_item     WC cart-item array, or null/empty for the empty state.
	 * @return void
	 */
	function lafka_cart_drawer_render_item( string $cart_item_key = '', $cart_item = null ): void {
		// Empty state — folded into the items renderer so the <ul> can swap
		// empty<->filled in place on fragment refresh (the v6.14.0 first-add /
		// v9.32.0 remove-last sync fix lives here, once).
		if ( empty( $cart_item ) || ! is_array( $cart_item ) ) {
			?>
			<li class="lafka-cart-drawer__empty" data-lafka-cart-empty>
				<span class="lafka-cart-drawer__empty-icon" aria-hidden="true">🛒</span>
				<span class="lafka-cart-drawer__empty-title"><?php esc_html_e( 'Your cart is empty', 'lafka-plugin' ); ?></span>
				<span class="lafka-cart-drawer__empty-hint"><?php esc_html_e( 'Add something delicious to get started.', 'lafka-plugin' ); ?></span>
				<a class="lafka-cart-drawer__empty-cta" href="<?php echo esc_url( lafka_get_menu_url() ); ?>"><?php esc_html_e( 'Browse the menu', 'lafka-plugin' ); ?></a>
			</li>
			<?php
			return;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
			return;
		}
		// Bundles, composites and the like hide their child lines here exactly as
		// they do in core's mini-cart.
		if ( ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
			return;
		}
		$name  = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key );
		$thumb = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image( 'woocommerce_gallery_thumbnail', array( 'loading' => 'lazy' ) ), $cart_item, $cart_item_key );
		$price = lafka_cart_drawer_line_price_html( $cart_item, $cart_item_key );

		// GX4: the theme opted in to the stepper row (theme support / filter).
		if ( lafka_cart_drawer_stepper_enabled() ) {
			lafka_cart_drawer_render_stepper_item( $cart_item_key, $cart_item, (string) $name, (string) $thumb, (string) $price );
			return;
		}
		?>
		<li class="lafka-cart-drawer__item" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
			<span class="lafka-cart-drawer__thumb">
				<?php
				echo wp_kses( $thumb, lafka_kses_allowed_image_html() );
				?>
			</span>
			<span class="lafka-cart-drawer__name"><?php echo wp_kses_post( $name ); ?></span>
			<span class="lafka-cart-drawer__qty">×<?php echo esc_html( (string) (int) $cart_item['quantity'] ); ?></span>
			<span class="lafka-cart-drawer__price"><?php echo wp_kses_post( $price ); ?></span>
			<a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" class="lafka-cart-drawer__remove remove_from_cart_button" role="button" data-product_id="<?php echo esc_attr( (string) ( $cart_item['product_id'] ?? '' ) ); ?>" data-cart_item_key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s product name */ __( 'Remove %s from cart', 'lafka-plugin' ), wp_strip_all_tags( $name ) ) ); ?>">×</a>
		</li>
		<?php
	}
}

if ( ! function_exists( 'lafka_cart_drawer_line_price_html' ) ) {
	/**
	 * A drawer line's price, through WooCommerce's own cart-item pipeline:
	 * WC_Cart::get_product_subtotal() run through woocommerce_cart_item_subtotal,
	 * the filter every cart template and extension uses. While a row is being
	 * drawn, lafka_cart_drawer_paid_price_html() (priority 5 on that filter)
	 * swaps the base value for the line's calculated totals, so the lines always
	 * add up to the drawer subtotal and a discount (a BOGO deal, a coupon) shows
	 * as the original price struck through before the price paid; filters at
	 * priority 10 and later (subscriptions, bookings, "from" prices) still apply.
	 *
	 * @param array<string,mixed> $cart_item     WC cart-item array.
	 * @param string              $cart_item_key Cart-item key.
	 * @return string Price HTML.
	 */
	function lafka_cart_drawer_line_price_html( array $cart_item, string $cart_item_key = '' ): string {
		$cart = function_exists( 'WC' ) && WC() && isset( WC()->cart ) ? WC()->cart : null;
		if ( ! is_object( $cart ) ) {
			return '';
		}

		$GLOBALS['lafka_cart_drawer_row'] = true;
		$html                             = (string) apply_filters(
			'woocommerce_cart_item_subtotal',
			$cart->get_product_subtotal( $cart_item['data'], $cart_item['quantity'] ),
			$cart_item,
			$cart_item_key
		);
		unset( $GLOBALS['lafka_cart_drawer_row'] );

		return $html;
	}
}

if ( ! function_exists( 'lafka_cart_drawer_paid_price_html' ) ) {
	add_filter( 'woocommerce_cart_item_subtotal', 'lafka_cart_drawer_paid_price_html', 5, 2 );
	/**
	 * woocommerce_cart_item_subtotal, drawer rows only: the price paid for the
	 * line from the cart's calculated totals, with the original struck through
	 * when a deal lowered it. WooCommerce's get_product_subtotal() is price x
	 * quantity from the product, which on a page load still holds the pre-deal
	 * price (the deal is applied inside calculate_totals). Falls back to
	 * WooCommerce's value when the item carries no calculated totals.
	 *
	 * @param string              $html      WooCommerce's subtotal HTML.
	 * @param array<string,mixed> $cart_item WC cart-item array.
	 * @return string
	 */
	function lafka_cart_drawer_paid_price_html( $html, $cart_item ) {
		if ( empty( $GLOBALS['lafka_cart_drawer_row'] ) || ! is_array( $cart_item ) ) {
			return $html;
		}
		if ( ! isset( $cart_item['line_total'], $cart_item['line_subtotal'] ) || ! function_exists( 'wc_price' ) ) {
			return $html;
		}
		$cart = function_exists( 'WC' ) && WC() && isset( WC()->cart ) ? WC()->cart : null;

		$incl     = is_object( $cart ) && method_exists( $cart, 'display_prices_including_tax' ) && $cart->display_prices_including_tax();
		$subtotal = (float) $cart_item['line_subtotal'];
		$paid     = (float) $cart_item['line_total'] + ( $incl ? (float) ( $cart_item['line_tax'] ?? 0 ) : 0.0 );

		// A deal that lowers the product's own price (BOGO blends it) leaves no
		// trace in line_subtotal; its original unit price is kept on the item.
		$original = $subtotal;
		if ( ! empty( $cart_item['_bogo_50'] ) && isset( $cart_item['_bogo_original_price'] ) ) {
			$original = max( $original, (float) $cart_item['_bogo_original_price'] * (int) $cart_item['quantity'] );
		}
		if ( $incl && $subtotal > 0 ) {
			$original *= 1 + ( (float) ( $cart_item['line_subtotal_tax'] ?? 0 ) / $subtotal );
		}

		$paid_html = ( $original - $paid > 0.005 && function_exists( 'wc_format_sale_price' ) )
			? wc_format_sale_price( $original, $paid )
			: wc_price( $paid );

		/**
		 * Filter a cart-drawer line's price HTML.
		 *
		 * @since 10.3.0
		 * @param string              $html      Price HTML.
		 * @param array<string,mixed> $cart_item Cart item.
		 */
		return (string) apply_filters( 'lafka_cart_drawer_line_price_html', $paid_html, $cart_item );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_render_items' ) ) {
	/**
	 * The drawer's items list, ul.lafka-cart-drawer__items (the WC fragment
	 * target): the rows, or the empty state, inside core's mini-cart item
	 * actions. One renderer for the drawer partials and the AJAX fragment.
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_cart_drawer_render_items(): void {
		echo '<ul class="lafka-cart-drawer__items">';
		/** This action is documented in WooCommerce's cart/mini-cart.php. */
		do_action( 'woocommerce_before_mini_cart_contents' );
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			lafka_cart_drawer_render_item();
		} else {
			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				lafka_cart_drawer_render_item( (string) $cart_item_key, $cart_item );
			}
		}
		/** This action is documented in WooCommerce's cart/mini-cart.php. */
		do_action( 'woocommerce_mini_cart_contents' );
		echo '</ul>';
	}
}

if ( ! function_exists( 'lafka_cart_drawer_stepper_enabled' ) ) {
	/**
	 * Whether drawer rows carry the quantity stepper (GX4). A theme opts in
	 * with add_theme_support( 'lafka-drawer-stepper' ); the filter can force
	 * it either way. Off by default, so other themes keep today's row.
	 *
	 * @return bool
	 */
	function lafka_cart_drawer_stepper_enabled(): bool {
		$enabled = (bool) current_theme_supports( 'lafka-drawer-stepper' );

		/**
		 * Filter whether cart-drawer rows carry the quantity stepper.
		 *
		 * @since 10.2.0
		 * @param bool $enabled Theme support for 'lafka-drawer-stepper'.
		 */
		return (bool) apply_filters( 'lafka_cart_drawer_stepper_enabled', $enabled );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_item_details' ) ) {
	/**
	 * One line of the item's chosen options (size, crust, add-ons), joined
	 * with " · ". Plain text; '' when the item has none.
	 *
	 * @param array<string,mixed> $cart_item WC cart-item array.
	 * @return string
	 */
	function lafka_cart_drawer_item_details( array $cart_item ): string {
		$flat  = function_exists( 'wc_get_formatted_cart_item_data' ) ? (string) wc_get_formatted_cart_item_data( $cart_item, true ) : '';
		$lines = array();
		foreach ( preg_split( '/\R/', $flat ) as $line ) {
			$line = trim( html_entity_decode( wp_strip_all_tags( (string) $line ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		/**
		 * Filter the cart-drawer options line of an item (plain text).
		 *
		 * @since 10.2.0
		 * @param string              $details   Options joined with " · ".
		 * @param array<string,mixed> $cart_item Cart item.
		 */
		return (string) apply_filters( 'lafka_cart_drawer_item_details', implode( ' · ', $lines ), $cart_item );
	}
}

if ( ! function_exists( 'lafka_cart_drawer_render_stepper_item' ) ) {
	/**
	 * The stepper variant of a drawer row: thumbnail, name + options line,
	 * line price, a labelled − / count / + group (wc-ajax=lafka_cart_set_qty,
	 * see lafka-cart-drawer-qty.php) and a worded Remove that keeps
	 * WooCommerce's remove_from_cart_button AJAX. The count keeps the
	 * .lafka-cart-drawer__qty class the theme's cart-count sync reads.
	 *
	 * @param string              $cart_item_key Cart-item key.
	 * @param array<string,mixed> $cart_item     Cart item.
	 * @param string              $name          Item name (woocommerce_cart_item_name, may hold HTML).
	 * @param string              $thumb         Thumbnail HTML (WC core).
	 * @param string              $price         Line price HTML.
	 * @return void
	 */
	function lafka_cart_drawer_render_stepper_item( string $cart_item_key, array $cart_item, string $name, string $thumb, string $price ): void {
		$product    = $cart_item['data'];
		$qty        = (int) $cart_item['quantity'];
		$plain_name = wp_strip_all_tags( $name );
		$details    = lafka_cart_drawer_item_details( $cart_item );
		$max        = is_object( $product ) && method_exists( $product, 'get_max_purchase_quantity' ) ? (int) $product->get_max_purchase_quantity() : -1;
		$at_max     = $max > 0 && $qty >= $max;
		?>
		<li class="lafka-cart-drawer__item lafka-cart-drawer__item--stepper" data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>">
			<span class="lafka-cart-drawer__thumb">
				<?php
				echo wp_kses( $thumb, lafka_kses_allowed_image_html() );
				?>
			</span>
			<?php // __info, not __body: that is the drawer's scroll container class (O-01). ?>
			<div class="lafka-cart-drawer__info">
				<h3 class="lafka-cart-drawer__name"><?php echo wp_kses_post( $name ); ?></h3>
				<?php if ( '' !== $details ) : ?>
					<p class="lafka-cart-drawer__details"><?php echo esc_html( $details ); ?></p>
				<?php endif; ?>
				<span class="lafka-cart-drawer__price"><?php echo wp_kses_post( $price ); ?></span>
			</div>
			<div class="lafka-cart-drawer__stepper" role="group" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'Quantity of %s', 'lafka-plugin' ), $plain_name ) ); ?>" data-lafka-qty data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>" data-name="<?php echo esc_attr( $plain_name ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'lafka-cart-qty' ) ); ?>"<?php echo $max > 0 ? ' data-max="' . esc_attr( (string) $max ) . '"' : ''; ?>>
				<button type="button" class="lafka-cart-drawer__step lafka-cart-drawer__step--less" data-lafka-qty-step="-1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'One less %s', 'lafka-plugin' ), $plain_name ) ); ?>"<?php echo $qty <= 1 ? ' disabled' : ''; ?>><span aria-hidden="true">−</span></button>
				<output class="lafka-cart-drawer__qty" aria-live="polite"><?php echo esc_html( (string) $qty ); ?></output>
				<button type="button" class="lafka-cart-drawer__step lafka-cart-drawer__step--more" data-lafka-qty-step="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'One more %s', 'lafka-plugin' ), $plain_name ) ); ?>"<?php echo $at_max ? ' disabled' : ''; ?>><span aria-hidden="true">+</span></button>
			</div>
			<a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" class="lafka-cart-drawer__remove remove_from_cart_button" role="button" data-product_id="<?php echo esc_attr( (string) ( $cart_item['product_id'] ?? '' ) ); ?>" data-cart_item_key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s product name */ __( 'Remove %s from cart', 'lafka-plugin' ), $plain_name ) ); ?>"><?php esc_html_e( 'Remove', 'lafka-plugin' ); ?></a>
		</li>
		<?php
	}
}

if ( ! function_exists( 'lafka_cart_drawer_render_total' ) ) {
	/**
	 * Render the drawer's subtotal + free-delivery total block. Emits the full
	 * div.lafka-cart-drawer__total wrapper (the WC fragment target). Single
	 * source of truth: the theme partial and the AJAX fragment both call this,
	 * so the rich .lafka-fdp free-delivery progress component is emitted
	 * directly in BOTH paths — no JS post-processing of a plain-text threshold
	 * is needed, and an empty initial cart still ships a .lafka-fdp (closes the
	 * first-add gap where the JS had no .lafka-fdp to read its threshold from).
	 *
	 * @return void
	 */
	function lafka_cart_drawer_render_total(): void {
		echo '<div class="lafka-cart-drawer__total">';

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			echo '</div>';
			return;
		}

		$total = (float) WC()->cart->get_cart_contents_total();
		// Shown on the same tax basis as the line prices (lafka_cart_drawer_line_price_html).
		$shown = $total;
		if ( method_exists( WC()->cart, 'display_prices_including_tax' ) && WC()->cart->display_prices_including_tax() && method_exists( WC()->cart, 'get_cart_contents_tax' ) ) {
			$shown += (float) WC()->cart->get_cart_contents_tax();
		}
		?>
		<div class="lafka-cart-drawer__subtotal">
			<span><?php esc_html_e( 'Subtotal', 'lafka-plugin' ); ?></span>
			<strong><?php echo wp_kses_post( wc_price( $shown ) ); ?></strong>
		</div>
		<?php
		// SSOT: the one free-delivery threshold accessor (the zone's Free Shipping
		// amount, else the operator option), so the drawer hint can never disagree
		// with the rule the shipping methods enforce. 0 = disabled, no progress.
		$threshold = lafka_get_free_delivery_threshold();

		// Threshold disabled — render no progress component (matches the
		// free-delivery-progress.php gate). The .lafka-cart-drawer__total
		// wrapper still ships as the fragment target.
		if ( $threshold > 0 ) {
			$remaining = max( 0, $threshold - $total );
			$reached   = ( $remaining <= 0 );
			// Progress percentage — capped 0..100 so a runaway cart never overshoots.
			$pct   = (int) min( 100, max( 0, round( ( $total / $threshold ) * 100 ) ) );
			$state = $reached ? 'reached' : 'below';

			if ( $reached ) {
				$title = esc_html__( 'Free delivery unlocked ✓', 'lafka-plugin' );
			} else {
				$title = sprintf(
					/* translators: %s — amount remaining to qualify for free delivery, formatted (e.g. "$7.50"). */
					esc_html__( 'Add %s more for free delivery!', 'lafka-plugin' ),
					wp_kses_post( wc_price( $remaining ) )
				);
			}

			$sub = sprintf(
				/* translators: 1: cart subtotal formatted; 2: threshold formatted; 3: percentage 0-100. */
				esc_html__( '%1$s / %2$s · %3$d%%', 'lafka-plugin' ),
				wp_kses_post( wc_price( $total ) ),
				wp_kses_post( wc_price( $threshold ) ),
				$pct
			);
			?>
			<div
				class="lafka-fdp lafka-fdp--drawer"
				data-lafka-fdp
				data-state="<?php echo esc_attr( $state ); ?>"
				data-threshold="<?php echo esc_attr( (string) $threshold ); ?>"
				data-value="<?php echo esc_attr( (string) $total ); ?>"
				data-remaining="<?php echo esc_attr( (string) $remaining ); ?>"
				data-pct="<?php echo esc_attr( (string) $pct ); ?>"
				role="status"
				aria-live="polite"
			>
				<div class="lafka-fdp__label">
					<span class="lafka-fdp__title"><?php echo wp_kses_post( $title ); ?></span>
				</div>
				<div class="lafka-fdp__bar" aria-hidden="true">
					<div class="lafka-fdp__fill" style="width: <?php echo esc_attr( (string) $pct ); ?>%;"></div>
				</div>
				<div class="lafka-fdp__sub"><?php echo wp_kses_post( $sub ); ?></div>
			</div>
			<?php
		}

		echo '</div>';
	}
}

if ( ! function_exists( 'lafka_pdp_cart_drawer_fragments' ) ) {
	function lafka_pdp_cart_drawer_fragments( array $fragments ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $fragments;
		}

		// Items list — render through the same callable the theme partial uses
		// on initial load (SSOT), so the AJAX swap is byte-identical.
		ob_start();
		lafka_cart_drawer_render_items();
		$fragments['ul.lafka-cart-drawer__items'] = (string) ob_get_clean();

		// Subtotal + free-delivery total — same callable as initial render.
		ob_start();
		lafka_cart_drawer_render_total();
		$fragments['div.lafka-cart-drawer__total'] = (string) ob_get_clean();

		return $fragments;
	}
	add_filter( 'woocommerce_add_to_cart_fragments', 'lafka_pdp_cart_drawer_fragments' );
}
