<?php
/**
 * Lafka_Bundles_Addons_Compatibility — bridges the addon engine v2 with the
 * official WooCommerce Product Bundles plugin.
 *
 * Lets toppings/addons attach to a bundled product on a Bundle PDP, e.g. a
 * "pizza + 2L drink" combo where the pizza needs topping selection.
 *
 * Modelled on `WC_PB_Addons_Compatibility` (upstream WC PB v8.5.6) but uses
 * Lafka's engine v2 classes — `Lafka_Engine_Cart`, `Lafka_Engine_Display`,
 * `Lafka_Engine_Helper` — instead of the upstream WC Product Add-Ons globals.
 *
 * This bridge replaces the addons↔combos compatibility module that lived in
 * the deleted Lafka Combos fork. v9.0.0 removed that fork in favour of WC PB;
 * this module restores the addons-on-bundled-products capability for v9.6.0+.
 *
 * Scope (v1):
 *   - Render addons UI inside each bundled item on the Bundle PDP.
 *   - Validate per-bundled-item addon selections at add-to-cart.
 *   - Persist addon selections inside the parent bundle's stamp so distinct
 *     addon configurations create distinct cart rows.
 *   - Restore addons array on bundled-item cart data hydration so the engine's
 *     existing `woocommerce_checkout_create_order_line_item` hook writes
 *     addon meta onto each bundled order line item.
 *
 * Out of scope (v1):
 *   - Admin "Edit bundle in order" addon flow (upstream uses `Product_Addon_Cart->order_line_item`).
 *   - Discount-aware addon-price aggregation when bundled items are individually priced AND discounted.
 *   - File-upload addon types inside bundles.
 *
 * @package Lafka_Addons_Engine
 * @since   9.6.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Bundles_Addons_Compatibility {

	/**
	 * Active bundled-item id for the current render/validate/cart operation.
	 * Cleared back to '' after each scoped call. Read by:
	 *   - field_prefix() to make addon field-names unique per bundled item
	 *   - cache_key_extra() to disambiguate the engine helper's per-request cache
	 */
	public static string $addons_prefix = '';

	/**
	 * Register WC PB hooks. Caller must verify `class_exists( 'WC_Bundled_Item' )`
	 * before invoking this. See `Lafka_Product_Addons::init_classes()`.
	 */
	public static function init(): void {
		// Render addons UI inside each bundled item.
		add_action( 'woocommerce_bundled_product_add_to_cart', array( __CLASS__, 'render_addons' ), 10, 2 );
		add_action( 'woocommerce_bundled_single_variation', array( __CLASS__, 'render_addons' ), 15, 2 );

		// Scope addon field names per bundled item via the engine's existing prefix filter.
		add_filter( 'product_addons_field_prefix', array( __CLASS__, 'field_prefix' ), 10, 2 );

		// Per-request cache key disambiguation: different bundled items must not
		// share a cached addon list keyed only by post_id.
		add_filter( 'lafka_product_addons_cache_key_extra', array( __CLASS__, 'cache_key_extra' ), 10, 3 );

		// Validate bundled-item addon submissions.
		add_filter( 'woocommerce_bundled_item_add_to_cart_validation', array( __CLASS__, 'validate_addons' ), 10, 5 );

		// Capture addon selections into the bundled-item stamp so distinct
		// configurations make distinct bundle cart rows.
		add_filter( 'woocommerce_bundled_item_cart_item_identifier', array( __CLASS__, 'stamp_addons' ), 10, 2 );

		// Wrap the per-bundled-item add-to-cart so the engine's auto-hooked
		// `woocommerce_add_cart_item_data` filter doesn't double-process: the
		// addon data is already serialized into the parent's stamp by this point.
		add_action( 'woocommerce_bundled_item_before_add_to_cart', array( __CLASS__, 'before_bundled_add_to_cart' ), 10, 5 );
		add_action( 'woocommerce_bundled_item_after_add_to_cart', array( __CLASS__, 'after_bundled_add_to_cart' ), 10, 5 );

		// Hydrate the bundled cart-item data with addons from the parent's stamp.
		add_filter( 'woocommerce_bundled_item_cart_data', array( __CLASS__, 'restore_addons_from_parent_stamp' ), 10, 2 );
	}

	/**
	 * Render the engine's addons UI for a single bundled item.
	 *
	 * Called twice for variable bundled items (`woocommerce_bundled_product_add_to_cart`
	 * and `woocommerce_bundled_single_variation`). Skips the simple-product path
	 * for variables to avoid emitting two forms — mirrors upstream behaviour.
	 *
	 * @param int             $product_id   The bundled product id.
	 * @param WC_Bundled_Item $bundled_item The bundled item wrapper.
	 */
	public static function render_addons( $product_id, $bundled_item ): void {
		$Lafka_Engine_Display = $GLOBALS['Lafka_Engine_Display'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( ! $Lafka_Engine_Display instanceof Lafka_Engine_Display ) {
			return;
		}

		if ( ! is_object( $bundled_item ) || ! method_exists( $bundled_item, 'get_product' ) ) {
			return;
		}

		$bundled_product = $bundled_item->get_product();
		if ( ! $bundled_product instanceof WC_Product ) {
			return;
		}

		// Variable bundled items render addons only after variation selection.
		if ( $bundled_product->is_type( 'variable' ) && doing_action( 'woocommerce_bundled_product_add_to_cart' ) ) {
			return;
		}

		// Swap $product global so the engine's templates see the bundled product.
		global $product;
		$product_bak = $product ?? null;
		$product     = $bundled_product;

		self::$addons_prefix = (string) $bundled_item->get_id();

		$Lafka_Engine_Display->display( $product_id );

		self::$addons_prefix = '';
		$product             = $product_bak;
	}

	/**
	 * Inject the bundled-item id into the engine's addon field-name prefix.
	 *
	 * The engine's helper applies this filter inside `assign_field_names()`, so
	 * field-names like `42-toppings-0` become `7-42-toppings-0` when bundled
	 * item #7 wraps product #42.
	 *
	 * @param string $prefix     The default prefix (`{post_id}-`).
	 * @param int    $product_id The product whose addons are being prefixed.
	 * @return string
	 */
	public static function field_prefix( $prefix, $product_id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '' !== self::$addons_prefix ) {
			return self::$addons_prefix . '-' . $prefix;
		}
		return (string) $prefix;
	}

	/**
	 * Disambiguate the engine's per-request addon-list cache by bundled-item id.
	 *
	 * Without this, the engine helper's `$product_addons_cache` keyed only by
	 * `{post_id}|default|...` would serve bundled-item #2's addons from
	 * bundled-item #1's cached entry — with the wrong prefix baked in.
	 *
	 * @param string $extra      Existing extra cache-key fragment.
	 * @param int    $post_id    The product id whose addons are being cached.
	 * @param string $prefix     Explicit prefix arg, if any (usually empty here).
	 * @return string
	 */
	public static function cache_key_extra( $extra, $post_id, $prefix ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '' !== self::$addons_prefix ) {
			return $extra . '|bundle:' . self::$addons_prefix;
		}
		return (string) $extra;
	}

	/**
	 * Validate addon submissions for a bundled item via the engine.
	 *
	 * @param bool            $passed
	 * @param int             $bundle_id
	 * @param WC_Bundled_Item $bundled_item
	 * @param int             $quantity
	 * @param int             $variation_id
	 * @return bool
	 */
	public static function validate_addons( $passed, $bundle_id, $bundled_item, $quantity, $variation_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Order-again submissions skip revalidation: the cart data is being
		// rebuilt from a saved order, not from a fresh user submission.
		if (
			isset( $_GET['order_again'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'woocommerce-order_again' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return (bool) $passed;
		}

		$Lafka_Engine_Cart = $GLOBALS['Lafka_Engine_Cart'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( ! $Lafka_Engine_Cart instanceof Lafka_Engine_Cart ) {
			return (bool) $passed;
		}

		self::$addons_prefix = (string) $bundled_item->get_id();
		$valid               = $Lafka_Engine_Cart->validate_add_cart_item( true, $bundled_item->get_product_id(), $quantity );
		self::$addons_prefix = '';

		return (bool) $passed && $valid;
	}

	/**
	 * Add the addons array to the bundled-item stamp.
	 *
	 * The bundled-item stamp is hashed by WC PB to compute the parent bundle's
	 * cart key. Including addons here means two of the same bundle with
	 * different topping selections end up as two distinct cart rows.
	 *
	 * @param array  $stamp           The bundled-item stamp built by WC PB.
	 * @param string $bundled_item_id The bundled-item id (string from WC PB).
	 * @return array
	 */
	public static function stamp_addons( $stamp, $bundled_item_id ): array {
		$Lafka_Engine_Cart = $GLOBALS['Lafka_Engine_Cart'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( ! $Lafka_Engine_Cart instanceof Lafka_Engine_Cart ) {
			return (array) $stamp;
		}

		if ( empty( $stamp['product_id'] ) ) {
			return (array) $stamp;
		}

		self::$addons_prefix = (string) $bundled_item_id;

		try {
			$cart_item_data = $Lafka_Engine_Cart->add_cart_item_data( array(), (int) $stamp['product_id'] );
		} catch ( Exception $e ) {
			$cart_item_data = array();
		}

		self::$addons_prefix = '';

		if ( ! empty( $cart_item_data['addons'] ) ) {
			$stamp['addons'] = $cart_item_data['addons'];
		}

		return (array) $stamp;
	}

	/**
	 * Detach the engine's auto-hooked cart-item-data filter while the bundled
	 * add-to-cart is running. The addon data is already in the parent stamp;
	 * letting the engine re-process per-bundled-item would duplicate it.
	 */
	public static function before_bundled_add_to_cart( $product_id, $quantity, $variation_id, $variations, $bundled_item_cart_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$Lafka_Engine_Cart = $GLOBALS['Lafka_Engine_Cart'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( ! $Lafka_Engine_Cart instanceof Lafka_Engine_Cart ) {
			return;
		}

		self::$addons_prefix = ! empty( $bundled_item_cart_data['bundled_item_id'] )
			? (string) $bundled_item_cart_data['bundled_item_id']
			: '';

		remove_filter( 'woocommerce_add_cart_item_data', array( $Lafka_Engine_Cart, 'add_cart_item_data' ), 10 );
	}

	/**
	 * Re-attach the engine's auto-hooked cart-item-data filter after the
	 * bundled add-to-cart completes, so non-bundled add-to-cart submissions
	 * keep working.
	 */
	public static function after_bundled_add_to_cart( $product_id, $quantity, $variation_id, $variations, $bundled_item_cart_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$Lafka_Engine_Cart = $GLOBALS['Lafka_Engine_Cart'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( ! $Lafka_Engine_Cart instanceof Lafka_Engine_Cart ) {
			return;
		}

		self::$addons_prefix = '';
		add_filter( 'woocommerce_add_cart_item_data', array( $Lafka_Engine_Cart, 'add_cart_item_data' ), 10, 2 );
	}

	/**
	 * Pull the addons array back from the parent stamp into the bundled
	 * cart-item data so the engine's `order_line_item` hook (and the
	 * `get_item_data` cart display path) see addons on each bundled child.
	 *
	 * @param array $bundled_item_cart_data The bundled child's cart data.
	 * @param array $cart_item_data         The parent bundle's cart data.
	 * @return array
	 */
	public static function restore_addons_from_parent_stamp( $bundled_item_cart_data, $cart_item_data ): array {
		if (
			isset( $bundled_item_cart_data['bundled_item_id'] )
			&& isset( $cart_item_data['stamp'][ $bundled_item_cart_data['bundled_item_id'] ]['addons'] )
		) {
			$bundled_item_cart_data['addons'] = $cart_item_data['stamp'][ $bundled_item_cart_data['bundled_item_id'] ]['addons'];
		}
		return (array) $bundled_item_cart_data;
	}
}
