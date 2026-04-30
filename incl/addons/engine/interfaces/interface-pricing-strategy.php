<?php
/**
 * Lafka_Pricing_Strategy — contract every pricing mode implements.
 *
 * Lafka has four pricing modes (flat_group, flat_per_option, flat_per_size,
 * matrix) plus a legacy passthrough. Each mode is its own class with a
 * unique id() and a defined set of methods that the resolver / admin form /
 * cart math invoke generically.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Pricing_Strategy {

	/**
	 * Strategy id matching Lafka_Addon_Schema::PRICING_* constants.
	 */
	public function id(): string;

	/**
	 * Operator-facing label (i18n-aware where applicable).
	 */
	public function label(): string;

	/**
	 * Apply this strategy to the group: take the canonical group data and
	 * EXPAND any group-level price config into per-option prices, so
	 * downstream readers (cart, display) see one consistent shape regardless
	 * of mode.
	 *
	 * Mutation rules (returns a new Addon_Group, doesn't modify input):
	 *   - flat_group         → every option's price = $group_flat_price scalar
	 *   - flat_per_option    → no change (option prices are already per-option scalars)
	 *   - flat_per_size      → every option's price = nested matrix from $group_size_prices
	 *   - matrix             → no change (option prices are already nested matrices)
	 *   - legacy             → no change (whatever is there)
	 *
	 * @param Lafka_Addon_Group $group
	 * @return Lafka_Addon_Group
	 */
	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group;

	/**
	 * Validate group data for this strategy. Returns array of error messages
	 * (empty = valid).
	 *
	 * @param Lafka_Addon_Group $group
	 * @return string[]
	 */
	public function validate( Lafka_Addon_Group $group ): array;
}
