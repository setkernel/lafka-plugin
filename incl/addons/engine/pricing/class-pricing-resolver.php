<?php
/**
 * Picks a pricing strategy for a given group based on its pricing_mode field.
 *
 * Built-in strategies are registered at construction. Third parties can hook
 * `lafka_addons_register_pricing_strategy` to add their own:
 *
 *     add_filter( 'lafka_addons_register_pricing_strategy', function( $strategies ) {
 *         $strategies['my_custom'] = new My_Custom_Strategy();
 *         return $strategies;
 *     });
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legacy passthrough — preserves whatever shape the data is in. Used for
 * groups that haven't been migrated to a specific mode.
 */
class Lafka_Legacy_Pricing extends Lafka_Abstract_Pricing_Strategy {
	public function id(): string {
		return Lafka_Addon_Schema::PRICING_LEGACY;
	}
	public function label(): string {
		return __( 'Legacy (no transformation)', 'lafka-plugin' );
	}
	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		return $group;
	}
}

class Lafka_Pricing_Resolver {

	/** @var array<string, Lafka_Pricing_Strategy> */
	private array $strategies;

	public function __construct() {
		$built_in = array(
			Lafka_Addon_Schema::PRICING_FLAT_GROUP      => new Lafka_Flat_Group_Pricing(),
			Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION => new Lafka_Flat_Per_Option_Pricing(),
			Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE   => new Lafka_Flat_Per_Size_Pricing(),
			Lafka_Addon_Schema::PRICING_MATRIX          => new Lafka_Matrix_Pricing(),
			Lafka_Addon_Schema::PRICING_LEGACY          => new Lafka_Legacy_Pricing(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$this->strategies = apply_filters( 'lafka_addons_register_pricing_strategy', $built_in );
		} else {
			$this->strategies = $built_in;
		}
	}

	public function for_group( Lafka_Addon_Group $group ): Lafka_Pricing_Strategy {
		$mode = $group->pricing_mode;
		if ( isset( $this->strategies[ $mode ] ) ) {
			return $this->strategies[ $mode ];
		}
		return $this->strategies[ Lafka_Addon_Schema::PRICING_LEGACY ];
	}

	/**
	 * @return array<string, Lafka_Pricing_Strategy>
	 */
	public function all_strategies(): array {
		return $this->strategies;
	}
}
