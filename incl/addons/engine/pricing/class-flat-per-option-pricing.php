<?php
/**
 * Flat per option pricing — each option has its own scalar price, same
 * across sizes. Matches the existing Lafka default behavior.
 *
 * Storage shape after expand():
 *   each option's price stays as the operator-entered scalar
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Per_Option_Pricing extends Lafka_Abstract_Pricing_Strategy {

	public function id(): string {
		return Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION;
	}

	public function label(): string {
		return __( 'Flat per option', 'lafka-plugin' );
	}

	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		// Per-option scalars are the canonical storage shape — no expansion.
		return $group;
	}

	public function validate( Lafka_Addon_Group $group ): array {
		// Empty prices on excluded options are fine; '' on an included option
		// is not strictly an error (treated as 0 by cart math) so we don't
		// raise a hard error here.
		return array();
	}
}
