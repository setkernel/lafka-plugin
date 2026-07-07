<?php
/**
 * Block Cart/Checkout mode shim.
 *
 * Lafka now fully supports the WooCommerce block Cart & Checkout (NX1-04b:
 * order_type/branch fields, timeslot picker, free-delivery progress, addon line
 * items and every ordering gate on the Store API path). The checkout experience
 * is governed by Lafka_Checkout_Mode (`lafka_checkout_mode` = 'blocks'|'classic'):
 *
 *   · BLOCKS mode (fresh-install default) — this shim LEAVES the block Cart/Checkout
 *     pages ALONE. Lafka's block components render on top of them.
 *   · CLASSIC mode (existing installs, migrated for byte-identical behaviour, and
 *     anyone who opts back to classic) — this shim rewrites the default, unedited
 *     block Cart/Checkout pages to the classic shortcodes Lafka has always
 *     supported, exactly as it did before NX1-04b.
 *
 * Reversible: before rewriting a page to the shortcode, the shim saves the page's
 * original block content in post meta, so switching back to blocks restores the
 * exact original markup. Only the shim's OWN shortcode output is ever restored —
 * any operator customisation is left untouched (matches must be exact).
 *
 * Self-limiting: only rewrites pages whose content is the unedited default block
 * markup produced by WC's install_pages (classic direction) or exactly the shim's
 * own shortcode (blocks direction). Runs on admin_init; the mode-toggle handler on
 * the Modules screen clears the done-flag so a mode change re-evaluates the pages.
 *
 * @package Lafka\Plugin\Compat
 * @since   8.7.2
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Block_Cart_Shim {

	private const STATUS_OPTION      = 'lafka_block_cart_shim_done';
	private const ORIGINAL_META      = '_lafka_shim_original_content';

	/**
	 * Install the shim on `admin_init` so it runs once per admin request, before
	 * the merchant lands on the cart/checkout.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_swap_pages' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_notice' ) );
	}

	/**
	 * The cart/checkout page pairs the shim manages.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function page_pairs(): array {
		return array(
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
	}

	/**
	 * Reconcile the cart/checkout pages with the active checkout mode.
	 */
	public static function maybe_swap_pages(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::STATUS_OPTION ) ) {
			return;
		}

		$classic = ! class_exists( 'Lafka_Checkout_Mode' ) || Lafka_Checkout_Mode::is_classic();

		$swapped = $classic ? self::apply_classic() : self::apply_blocks();

		// Flag complete even if nothing was swapped — don't re-check every admin
		// page-load. The Modules mode-toggle clears this flag on a mode change.
		update_option( self::STATUS_OPTION, time() );

		if ( ! empty( $swapped ) ) {
			set_transient( 'lafka_block_cart_shim_notice', $swapped, DAY_IN_SECONDS );
		}
	}

	/**
	 * Classic mode: rewrite unedited default block pages to the classic shortcodes,
	 * preserving the original block content for a later switch back to blocks.
	 *
	 * @return string[] Labels of pages swapped.
	 */
	private static function apply_classic(): array {
		$swapped = array();

		foreach ( self::page_pairs() as $pair ) {
			$page = self::get_page( $pair['option'] );
			if ( ! $page ) {
				continue;
			}
			// Only swap unedited default block content (leading whitespace tolerated).
			if ( false === strpos( ltrim( $page->post_content ), '<!-- ' . $pair['block'] ) ) {
				continue;
			}

			update_post_meta( $page->ID, self::ORIGINAL_META, $page->post_content );
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => $pair['shortcode'],
				)
			);
			$swapped[] = $pair['label'];
		}

		return $swapped;
	}

	/**
	 * Blocks mode: leave native block pages alone, but if a page carries exactly the
	 * shim's own shortcode (a prior classic rewrite), restore its saved original
	 * block content so the block Cart/Checkout renders again.
	 *
	 * @return string[] Labels of pages restored.
	 */
	private static function apply_blocks(): array {
		$restored = array();

		foreach ( self::page_pairs() as $pair ) {
			$page = self::get_page( $pair['option'] );
			if ( ! $page ) {
				continue;
			}
			// Only restore a page we previously shimmed: content must be exactly our
			// shortcode AND a saved original must exist.
			if ( trim( $page->post_content ) !== $pair['shortcode'] ) {
				continue;
			}
			$original = get_post_meta( $page->ID, self::ORIGINAL_META, true );
			if ( ! is_string( $original ) || '' === $original ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => $original,
				)
			);
			delete_post_meta( $page->ID, self::ORIGINAL_META );
			$restored[] = $pair['label'];
		}

		return $restored;
	}

	/**
	 * Resolve a WC page by option id, guarding type.
	 *
	 * @param string $option_name WC page-id option.
	 * @return \WP_Post|null
	 */
	private static function get_page( string $option_name ): ?\WP_Post {
		$page_id = (int) get_option( $option_name );
		if ( ! $page_id ) {
			return null;
		}
		$page = get_post( $page_id );

		return ( $page && 'page' === $page->post_type ) ? $page : null;
	}

	/**
	 * Clear the done-flag so the next admin_init re-reconciles the pages. Called by
	 * the Modules mode-toggle handler after the mode changes.
	 */
	public static function reset(): void {
		delete_option( self::STATUS_OPTION );
	}

	public static function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$swapped = get_transient( 'lafka_block_cart_shim_notice' );
		if ( ! is_array( $swapped ) || empty( $swapped ) ) {
			return;
		}
		$pages   = esc_html( implode( ' & ', $swapped ) );
		$classic = ! class_exists( 'Lafka_Checkout_Mode' ) || Lafka_Checkout_Mode::is_classic();

		echo '<div class="notice notice-info is-dismissible">';
		echo '<p><strong>Lafka:</strong> ';
		if ( $classic ) {
			printf(
				/* translators: %s: list of swapped page labels (e.g. "Cart & Checkout"). */
				esc_html__( 'Updated %s page(s) to the classic WooCommerce shortcodes for the classic checkout experience.', 'lafka-plugin' ),
				$pages // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			);
		} else {
			printf(
				/* translators: %s: list of restored page labels (e.g. "Cart & Checkout"). */
				esc_html__( 'Restored the block %s page(s) for the block checkout experience.', 'lafka-plugin' ),
				$pages // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			);
		}
		echo '</p></div>';
		delete_transient( 'lafka_block_cart_shim_notice' );
	}
}

Lafka_Block_Cart_Shim::init();
