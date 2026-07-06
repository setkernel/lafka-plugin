<?php
/**
 * Lafka_Engine_Store_Api — carry addon selections through the WooCommerce
 * Store API (block cart/checkout, headless) add-to-cart path.
 *
 * NX1-04c. The addon engine captures selections from the CLASSIC PDP $_POST in
 * Lafka_Engine_Cart::add_cart_item_data() and prices them via the pricing
 * strategies. None of that reaches the Store API: CartAddItem never fires
 * woocommerce_add_cart_item_data, so a block-cart / headless client cannot add an
 * item with addons and the block cart line item shows none.
 *
 * This adapter is the single bridge. It does NOT re-parse or re-price anything:
 * it maps a documented request shape onto the exact $post_data array the classic
 * hook consumes, then delegates to Lafka_Engine_Cart's own field-class pipeline.
 * Everything downstream — price application (woocommerce_add_cart_item →
 * Lafka_Engine_Cart::add_cart_item()), cart/checkout line-item display
 * (woocommerce_get_item_data → Lafka_Engine_Cart::get_item_data(), which the
 * Store API CartItemSchema renders into item.item_data), and order-item meta
 * (woocommerce_checkout_create_order_line_item → Lafka_Engine_Cart::order_line_item(),
 * which the Store API OrderController fires via WC_Checkout::create_order_line_items())
 * — is the SAME code the classic path runs, so totals + meta are byte-identical.
 *
 * Request shape (documented):
 *
 *   POST /wp-json/wc/store/v1/cart/add-item
 *   {
 *     "id": <product-or-variation id>,
 *     "quantity": 1,
 *     "extensions": {
 *       "lafka": {
 *         "addons": {
 *           "<engine field-name>": <value>,
 *           ...
 *         }
 *       }
 *     }
 *   }
 *
 * <engine field-name> is the stable Lafka_Engine_Helper field-name computed for
 * the owner (parent) product; a leading "addon-" is tolerated. <value> matches
 * the classic form value: an array of option ids (checkbox), a single option id
 * (radiobutton), or an { option-key: text } map (textarea).
 *
 * Two responsibilities:
 *   1. ADD-TO-CART — woocommerce_store_api_add_to_cart_data: read the selections,
 *      map them to $post_data, build cart_item_data['addons'] via the engine cart.
 *   2. VALIDATION — feed the SAME $post_data to the engine's own
 *      woocommerce_add_to_cart_validation hook (via the lafka_addons_request_post_data
 *      filter) so required / limit / min-max checks run on the block path exactly as
 *      on the classic path; a failure surfaces as a Store API error with the
 *      classic notice text (WooCommerce converts the notice to a RouteException).
 *
 * @package Lafka_Addons_Engine
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Engine_Store_Api' ) ) {

	/**
	 * Store API add-to-cart adapter for the addon engine.
	 */
	class Lafka_Engine_Store_Api {

		/**
		 * Extension namespace carrying Lafka data on Store API requests. Matches
		 * the namespace Lafka_Store_Api (NX1-04a) already registers, so the whole
		 * Lafka Store API surface lives under one coherent extension key.
		 */
		const REQUEST_NAMESPACE = 'lafka';

		/**
		 * Sub-key under the namespace holding the addon selections map.
		 */
		const REQUEST_KEY = 'addons';

		/**
		 * The engine cart instance whose field-class pipeline we delegate to. The
		 * global instance is reused (never re-constructed) so its cart-lifecycle
		 * hooks are not double-registered — a second registration would double the
		 * addon price on woocommerce_add_cart_item.
		 *
		 * @var Lafka_Engine_Cart
		 */
		private Lafka_Engine_Cart $engine_cart;

		/**
		 * Request-scoped $post_data mapped from the current add-item's selections,
		 * exposed to the engine's classic validation hook via
		 * lafka_addons_request_post_data. Null when the current add carries no addon
		 * selections (so the engine falls back to $_POST, unchanged for classic).
		 *
		 * @var array|null
		 */
		private ?array $pending_post_data = null;

		/**
		 * @param Lafka_Engine_Cart $engine_cart The shared engine cart instance.
		 */
		public function __construct( Lafka_Engine_Cart $engine_cart ) {
			$this->engine_cart = $engine_cart;

			add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'inject_addon_selections' ), 10, 2 );
			add_filter( 'lafka_addons_request_post_data', array( $this, 'inject_request_post_data' ) );
			add_action( 'woocommerce_add_to_cart', array( $this, 'clear_pending' ), 999 );
		}

		/**
		 * Store API add-to-cart hook: read addon selections from the request and
		 * fold them into cart_item_data so the whole classic cart lifecycle
		 * (pricing, display, order meta) engages on the block path unchanged.
		 *
		 * Runs BEFORE CartController::add_to_cart, so the $post_data it stashes is
		 * available when the engine's woocommerce_add_to_cart_validation hook fires
		 * a moment later inside add_to_cart.
		 *
		 * @param array $data    Store API add-to-cart data { id, quantity, variation, cart_item_data }.
		 * @param mixed $request WP_REST_Request for the add-item call.
		 * @return array
		 */
		public function inject_addon_selections( $data, $request ) {
			// Reset first so a prior add-item in a batch never leaks its post_data
			// into this one's validation.
			$this->pending_post_data = null;

			if ( ! is_array( $data ) ) {
				return $data;
			}

			$selections = self::extract_selections( $request );
			if ( empty( $selections ) ) {
				return $data;
			}

			$product_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
			$owner_id   = self::resolve_owner_product_id( $product_id );
			if ( $owner_id <= 0 ) {
				return $data;
			}

			$post_data               = $this->map_selections_to_post_data( $owner_id, $selections );
			$this->pending_post_data = $post_data;

			$addons = $this->build_addons( $owner_id, $post_data );
			if ( ! empty( $addons ) ) {
				$cart_item_data           = isset( $data['cart_item_data'] ) && is_array( $data['cart_item_data'] ) ? $data['cart_item_data'] : array();
				$cart_item_data['addons'] = $addons;
				$data['cart_item_data']   = $cart_item_data;
			}

			return $data;
		}

		/**
		 * Expose the request-scoped $post_data to the engine's classic hooks so the
		 * SAME validation runs on the block path. Returns the passed-through value
		 * (so the engine falls back to $_POST) whenever no addon selections ride the
		 * current request — zero behaviour change for classic add-to-cart.
		 *
		 * @param mixed $post_data Engine-supplied default (null → $_POST fallback).
		 * @return mixed
		 */
		public function inject_request_post_data( $post_data ) {
			return is_array( $this->pending_post_data ) ? $this->pending_post_data : $post_data;
		}

		/**
		 * Drop the request-scoped $post_data once the add completes.
		 *
		 * @return void
		 */
		public function clear_pending() {
			$this->pending_post_data = null;
		}

		/**
		 * Build cart_item_data['addons'] by delegating to the engine cart's own
		 * field-class parser with the mapped $post_data. No option matching or
		 * value shaping is duplicated here — Lafka_Engine_Cart::add_cart_item_data()
		 * runs Lafka_Engine_Field_Factory + the field classes exactly as it does for
		 * the classic POST. Any WP_Error a field raises becomes a Store API error.
		 *
		 * @param int   $owner_id  Owner (parent) product id that carries the addons.
		 * @param array $post_data Mapped classic-shape $post_data.
		 * @return array
		 */
		private function build_addons( int $owner_id, array $post_data ): array {
			try {
				$meta = $this->engine_cart->add_cart_item_data( array(), $owner_id, $post_data );
			} catch ( \Exception $e ) {
				self::throw_store_api_error( 'lafka_invalid_addon', $e->getMessage() );
			}

			return isset( $meta['addons'] ) && is_array( $meta['addons'] ) ? $meta['addons'] : array();
		}

		/**
		 * Read the addon selections map from the request's `lafka` extension data.
		 * Accepts a WP_REST_Request (ArrayAccess) or a plain array (tests). Returns
		 * an empty array when no well-formed selections are present.
		 *
		 * @param mixed $request WP_REST_Request or array with an `extensions` key.
		 * @return array<string, mixed>
		 */
		public static function extract_selections( $request ): array {
			$extensions = null;

			if ( is_array( $request ) ) {
				$extensions = $request['extensions'] ?? null;
			} elseif ( is_object( $request ) && $request instanceof ArrayAccess ) {
				$extensions = $request['extensions'] ?? null;
			}

			if ( ! is_array( $extensions ) ) {
				return array();
			}

			$namespaced = $extensions[ self::REQUEST_NAMESPACE ] ?? null;
			if ( is_object( $namespaced ) ) {
				$namespaced = (array) $namespaced;
			}
			if ( ! is_array( $namespaced ) ) {
				return array();
			}

			$selections = $namespaced[ self::REQUEST_KEY ] ?? null;
			if ( is_object( $selections ) ) {
				$selections = (array) $selections;
			}

			return is_array( $selections ) ? $selections : array();
		}

		/**
		 * Map { field-name: value } selections onto the exact $post_data array the
		 * classic add-to-cart hook consumes: an `add-to-cart` owner id plus one
		 * `addon-{field-name}` key per selection. Values are wp_slash()'d so the
		 * engine's per-field wp_unslash() reproduces the original — parity with the
		 * WP-slashed classic $_POST superglobal.
		 *
		 * @param int                  $owner_id   Owner (parent) product id.
		 * @param array<string, mixed> $selections field-name → submitted value.
		 * @return array
		 */
		public function map_selections_to_post_data( int $owner_id, array $selections ): array {
			$post_data = array( 'add-to-cart' => $owner_id );

			foreach ( $selections as $field => $value ) {
				$raw = trim( (string) preg_replace( '/^addon-/', '', (string) $field ) );
				if ( '' === $raw ) {
					continue;
				}
				$field = sanitize_title( $raw );
				if ( '' === $field ) {
					continue;
				}
				$post_data[ 'addon-' . $field ] = wp_slash( $value );
			}

			return $post_data;
		}

		/**
		 * Resolve the product id that owns the addon groups. For a variation the
		 * addons live on the parent, mirroring the Store API's own
		 * CartController::get_product_id() and the classic add-to-cart field prefix.
		 *
		 * @param int $product_id The request `id` (product or variation).
		 * @return int Owner product id, or 0 when unresolvable.
		 */
		private static function resolve_owner_product_id( int $product_id ): int {
			if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
				return 0;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return 0;
			}

			return $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		}

		/**
		 * Raise a Store API RouteException (falling back to a generic exception when
		 * the Store API class is unavailable) so an invalid addon selection surfaces
		 * as a proper Store API error response rather than a fatal.
		 *
		 * @param string $code    Machine error code.
		 * @param string $message Customer-facing message (classic notice text).
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Store API error.
		 * @throws \RuntimeException Fallback when the Store API is unavailable.
		 */
		private static function throw_store_api_error( string $code, string $message ): void {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), 400 );
			}

			throw new \RuntimeException( esc_html( $message ) );
		}
	}
}
