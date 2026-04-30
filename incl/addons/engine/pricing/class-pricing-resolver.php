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

class Lafka_Pricing_Resolver {

	/** @var array<string, Lafka_Pricing_Strategy> */
	private array $strategies;

	public function __construct() {
		$built_in = array(
			Lafka_Addon_Schema::PRICING_FLAT_GROUP      => new Lafka_Flat_Group_Pricing(),
			Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION => new Lafka_Flat_Per_Option_Pricing(),
			Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE   => new Lafka_Flat_Per_Size_Pricing(),
			Lafka_Addon_Schema::PRICING_MATRIX          => new Lafka_Matrix_Pricing(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$this->strategies = apply_filters( 'lafka_addons_register_pricing_strategy', $built_in );
		} else {
			$this->strategies = $built_in;
		}
	}

	/**
	 * Resolve the strategy for a group. Falls back to flat-per-option (the
	 * canonical default mode for fresh groups) when the stored pricing_mode
	 * isn't recognized — keeps the system safe against unknown values
	 * without raising an exception in the hot path.
	 */
	public function for_group( Lafka_Addon_Group $group ): Lafka_Pricing_Strategy {
		$mode = $group->pricing_mode;
		if ( isset( $this->strategies[ $mode ] ) ) {
			return $this->strategies[ $mode ];
		}
		return $this->strategies[ Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION ];
	}

	/**
	 * @return array<string, Lafka_Pricing_Strategy>
	 */
	public function all_strategies(): array {
		return $this->strategies;
	}
}
