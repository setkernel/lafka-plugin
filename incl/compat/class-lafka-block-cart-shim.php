<?php
/**
 * Cart/Checkout page switch (blocks <-> classic shortcodes).
 *
 * Lafka fully supports both the WooCommerce block Cart & Checkout and the classic
 * shortcode ones (order_type/branch fields, timeslot picker, free-delivery
 * progress, addon line items and every ordering gate on the Store API path). The
 * operator's choice is Lafka_Checkout_Mode (`lafka_checkout_mode` =
 * 'blocks'|'classic').
 *
 * The pages are the merchant's: nothing here ever edits them on its own. Changing
 * their content is an explicit operator action on the Lafka Modules screen:
 *
 *   - "Switch the pages" (apply): rewrites the Cart/Checkout pages whose content
 *     is the unedited default for the OTHER mode — block markup to the classic
 *     shortcodes, or the shim's own shortcode back to the block markup it saved —
 *     once, and keeps the previous content in post meta.
 *   - "Undo" restores exactly that previous content.
 *
 * Pages the merchant edited are never touched (matches must be exact), and
 * runtime code reads the EFFECTIVE mode from the page content
 * (Lafka_Checkout_Mode), so an un-switched page simply keeps working as it is.
 *
 * @package Lafka\Plugin\Compat
 * @since   8.7.2
 * @since   10.4.0 Explicit operator action with undo; no automatic page edits.
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Block_Cart_Shim {

	private const ORIGINAL_META = '_lafka_shim_original_content';
	private const UNDO_META     = '_lafka_shim_undo_content';

	public const APPLY_ACTION = 'lafka_checkout_pages_apply';
	public const UNDO_ACTION  = 'lafka_checkout_pages_undo';
	public const CAPABILITY   = 'manage_woocommerce';

	/**
	 * Register the two admin-post handlers behind the Modules screen buttons.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::APPLY_ACTION, array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( __CLASS__, 'handle_undo' ) );
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
	 * Which pages a switch to $mode would change, and which can be undone.
	 *
	 * @param string $mode 'blocks' or 'classic'.
	 * @return array{pending:string[],undo:string[]} Page labels.
	 */
	public static function status( string $mode ): array {
		$pending = array();
		$undo    = array();
		foreach ( self::page_pairs() as $pair ) {
			$page = self::get_page( $pair['option'] );
			if ( ! $page ) {
				continue;
			}
			if ( self::can_switch( $page, $pair, $mode ) ) {
				$pending[] = $pair['label'];
			}
			if ( '' !== (string) get_post_meta( $page->ID, self::UNDO_META, true ) ) {
				$undo[] = $pair['label'];
			}
		}

		return array(
			'pending' => $pending,
			'undo'    => $undo,
		);
	}

	/**
	 * Whether a page is in the exact shape that a switch to $mode replaces.
	 *
	 * @param \WP_Post             $page Page.
	 * @param array<string,string> $pair Page pair.
	 * @param string               $mode Target mode.
	 * @return bool
	 */
	private static function can_switch( \WP_Post $page, array $pair, string $mode ): bool {
		if ( 'classic' === $mode ) {
			// Only unedited default block content (leading whitespace tolerated).
			return 0 === strpos( ltrim( $page->post_content ), '<!-- ' . $pair['block'] );
		}
		// Blocks: only a page that carries exactly our shortcode AND a saved original.
		$original = get_post_meta( $page->ID, self::ORIGINAL_META, true );

		return trim( $page->post_content ) === $pair['shortcode'] && is_string( $original ) && '' !== $original;
	}

	/**
	 * Switch the pages to $mode. Keeps each page's previous content for undo().
	 *
	 * @param string $mode 'blocks' or 'classic'.
	 * @return string[] Labels of the pages switched.
	 */
	public static function apply( string $mode ): array {
		$switched = array();
		foreach ( self::page_pairs() as $pair ) {
			$page = self::get_page( $pair['option'] );
			if ( ! $page || ! self::can_switch( $page, $pair, $mode ) ) {
				continue;
			}

			update_post_meta( $page->ID, self::UNDO_META, $page->post_content );
			if ( 'classic' === $mode ) {
				update_post_meta( $page->ID, self::ORIGINAL_META, $page->post_content );
				$new = $pair['shortcode'];
			} else {
				$new = (string) get_post_meta( $page->ID, self::ORIGINAL_META, true );
				delete_post_meta( $page->ID, self::ORIGINAL_META );
			}
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => wp_slash( $new ),
				)
			);
			$switched[] = $pair['label'];
		}

		return $switched;
	}

	/**
	 * Put back what each page held before its last switch.
	 *
	 * @return string[] Labels of the pages restored.
	 */
	public static function undo(): array {
		$restored = array();
		foreach ( self::page_pairs() as $pair ) {
			$page = self::get_page( $pair['option'] );
			if ( ! $page ) {
				continue;
			}
			$previous = get_post_meta( $page->ID, self::UNDO_META, true );
			if ( ! is_string( $previous ) || '' === $previous ) {
				continue;
			}
			$current_is_shortcode = trim( $page->post_content ) === $pair['shortcode'];
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => wp_slash( $previous ),
				)
			);
			delete_post_meta( $page->ID, self::UNDO_META );
			// Keep the saved block original in step with the page: gone when the page
			// is blocks again (undoing a switch to classic), saved when it is the
			// shortcode again (undoing a switch to blocks).
			if ( $current_is_shortcode ) {
				delete_post_meta( $page->ID, self::ORIGINAL_META );
			} elseif ( trim( $previous ) === $pair['shortcode'] ) {
				update_post_meta( $page->ID, self::ORIGINAL_META, $page->post_content );
			}
			$restored[] = $pair['label'];
		}

		return $restored;
	}

	/**
	 * admin-post handler: switch the pages to the configured checkout mode.
	 */
	public static function handle_apply(): void {
		self::guard( self::APPLY_ACTION );
		$mode     = class_exists( 'Lafka_Checkout_Mode' ) ? Lafka_Checkout_Mode::get_mode() : 'classic';
		$switched = self::apply( $mode );
		self::redirect( $switched ? 'switched' : 'nothing' );
	}

	/**
	 * admin-post handler: restore the pages' previous content.
	 */
	public static function handle_undo(): void {
		self::guard( self::UNDO_ACTION );
		$restored = self::undo();
		self::redirect( $restored ? 'undone' : 'nothing' );
	}

	/**
	 * Capability + nonce gate for the two handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change the Cart and Checkout pages.', 'lafka-plugin' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the Modules screen with the outcome in the URL.
	 *
	 * @param string $result 'switched', 'undone' or 'nothing'.
	 */
	private static function redirect( string $result ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'lafka-modules',
					'lafka_checkout_pages' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
}

Lafka_Block_Cart_Shim::init();
