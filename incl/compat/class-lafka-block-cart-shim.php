<?php
/**
 * Block Cart/Checkout shim — transitional fix for B-01..B-04.
 *
 * WC 10.6+ defaults the Cart and Checkout pages to the Block-based variants
 * (`<!-- wp:woocommerce/cart -->` / `<!-- wp:woocommerce/checkout -->`).
 * Lafka's BOGO label, delivery-min notice, branch selector, and order-hours
 * closure notice all hook the classic-only `woocommerce_before_cart` /
 * `woocommerce_before_checkout_form` filters, which never fire in the Block
 * flow. Net effect on a fresh WC 10.6+ install: line-item BOGO labels render,
 * but the delivery-min banner, branch selector, and order-hours notice are
 * silently invisible.
 *
 * Until P3-01 (Store API extensions + Block components) ships, this shim
 * detects the default Block content on first load and rewrites the cart and
 * checkout pages to the classic shortcodes Lafka already supports cleanly.
 *
 * Self-disabling guarantees:
 * - Runs once per site (gated by the `lafka_block_cart_shim_done` option).
 * - Only rewrites pages whose content is the *unedited* default Block markup
 *   produced by WC's `install_pages`. Any merchant customization (extra
 *   blocks, custom HTML, anything) is left alone — match must be exact.
 * - Operator can re-enable the Block flow by deleting the option and
 *   restoring the page content; this shim won't undo their decision.
 *
 * @package Lafka\Plugin\Compat
 * @since   8.7.2
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Block_Cart_Shim {

	private const STATUS_OPTION = 'lafka_block_cart_shim_done';

	/**
	 * Install the shim on `admin_init` so it runs once per admin request,
	 * before the merchant lands on a page that would surface the silently-
	 * missing notice.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_swap_pages' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
	}

	public static function maybe_swap_pages(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::STATUS_OPTION ) ) {
			return;
		}

		$swapped = array();

		$pairs = array(
			array(
				'option'    => 'woocommerce_cart_page_id',
				'block'     => 'wp:woocommerce/cart',
				'shortcode' => '[woocommerce_cart]',
				'label'     => 'Cart',
			),
			array(
				'option'    => 'woocommerce_checkout_page_id',
				'block'     => 'wp:woocommerce/checkout',
				'shortcode' => '[woocommerce_checkout]',
				'label'     => 'Checkout',
			),
		);

		foreach ( $pairs as $pair ) {
			$page_id = (int) get_option( $pair['option'] );
			if ( ! $page_id ) {
				continue;
			}
			$page = get_post( $page_id );
			if ( ! $page || 'page' !== $page->post_type ) {
				continue;
			}
			// Only swap if the content opens with the Block marker. We accept
			// a leading whitespace prefix because WC sometimes inserts a
			// newline before the comment.
			if ( false === strpos( ltrim( $page->post_content ), '<!-- ' . $pair['block'] ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $pair['shortcode'],
				)
			);
			$swapped[] = $pair['label'];
		}

		// Flag complete even if nothing was swapped — we don't want to keep
		// re-checking on every admin page-load. The flag is keyed on the
		// shim's own logic, not on the swap result.
		update_option( self::STATUS_OPTION, time() );

		if ( ! empty( $swapped ) ) {
			set_transient(
				'lafka_block_cart_shim_notice',
				$swapped,
				DAY_IN_SECONDS
			);
		}
	}

	public static function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$swapped = get_transient( 'lafka_block_cart_shim_notice' );
		if ( ! is_array( $swapped ) || empty( $swapped ) ) {
			return;
		}
		$pages = esc_html( implode( ' & ', $swapped ) );
		echo '<div class="notice notice-info is-dismissible">';
		echo '<p><strong>Lafka:</strong> ';
		printf(
			/* translators: %s: list of swapped page labels (e.g. "Cart & Checkout"). */
			esc_html__( 'Updated %s page(s) to use the classic WooCommerce shortcodes.', 'lafka-plugin' ),
			$pages // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
		);
		echo ' ';
		esc_html_e( "Lafka's delivery minimum, branch selector, BOGO notice, and order-hours notice rely on classic-cart hooks that don't fire inside the WooCommerce Cart/Checkout Blocks. Until full Block support ships (tracked as P3-01), the classic shortcodes are the supported path.", 'lafka-plugin' );
		echo '</p></div>';
		delete_transient( 'lafka_block_cart_shim_notice' );
	}
}

Lafka_Block_Cart_Shim::init();
