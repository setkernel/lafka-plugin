<?php
/**
 * Required-addons probe for listing quick-add (GX4).
 *
 * A menu row can add a product in two taps (Add → size), but only when the
 * customer has nothing else they must decide. A product with a required
 * add-on group (e.g. "Pick your 2 pizzas" on a combo) has to go to its
 * product page instead, where the group is shown and validated. Optional
 * groups (toppings) never block a quick add.
 *
 * Public API:
 *   · lafka_product_has_required_addons( int $product_id ): bool — false when
 *     the product add-ons module is off; cached per request.
 *   · filter `lafka_product_has_required_addons` ( bool $has, int $product_id ).
 *   · Store API: `extensions.lafka.has_required_addons` on the product
 *     endpoint (incl/store-api/lafka-store-api-product.php).
 *
 * A group that is required but offers no option (every option excluded) is
 * not shown by the engine, so it does not count.
 *
 * @package Lafka\Plugin\Addons
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Required_Addons' ) ) {

	/**
	 * Per-request cache behind lafka_product_has_required_addons().
	 */
	final class Lafka_Required_Addons {

		/**
		 * Product id => answer.
		 *
		 * @var array<int, bool>
		 */
		private static array $cache = array();

		/**
		 * Whether a product has at least one required add-on group.
		 *
		 * @param int $product_id Product id.
		 * @return bool
		 */
		public static function has_required( int $product_id ): bool {
			if ( $product_id <= 0 ) {
				return false;
			}
			if ( isset( self::$cache[ $product_id ] ) ) {
				return self::$cache[ $product_id ];
			}

			$has = false;
			if ( self::module_active() ) {
				foreach ( Lafka_Engine_Helper::get_product_addons( $product_id ) as $addon ) {
					if ( is_array( $addon ) && Lafka_Engine_Helper::is_addon_required( $addon ) ) {
						$has = true;
						break;
					}
				}
			}

			/**
			 * Filter whether a product has a required add-on group (and so needs
			 * its product page instead of a listing quick add).
			 *
			 * @since 10.2.0
			 * @param bool $has        Whether a required group is offered.
			 * @param int  $product_id Product id.
			 */
			self::$cache[ $product_id ] = (bool) apply_filters( 'lafka_product_has_required_addons', $has, $product_id );

			return self::$cache[ $product_id ];
		}

		/**
		 * Forget cached answers (tests; long-running CLI jobs).
		 *
		 * @return void
		 */
		public static function flush(): void {
			self::$cache = array();
		}

		/**
		 * Whether the product add-ons module is on and its engine loaded.
		 *
		 * @return bool
		 */
		private static function module_active(): bool {
			return function_exists( 'is_lafka_product_addons' ) && is_lafka_product_addons()
				&& class_exists( 'Lafka_Engine_Helper' );
		}
	}
}

if ( ! function_exists( 'lafka_product_has_required_addons' ) ) {
	/**
	 * Whether a product has a required add-on group.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	function lafka_product_has_required_addons( int $product_id ): bool {
		return Lafka_Required_Addons::has_required( $product_id );
	}
}
