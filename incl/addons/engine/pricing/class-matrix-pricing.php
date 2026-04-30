<?php
/**
 * Matrix pricing — full per-option × per-size price grid. The original
 * Lafka per-attribute pricing behavior, now formalized as one of four modes.
 *
 * Storage shape: each option's price is already a nested matrix
 *   [taxonomy_slug => [size_slug => scalar]]
 * which the cart math handles natively.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Matrix_Pricing extends Lafka_Abstract_Pricing_Strategy {

	public function id(): string {
		return Lafka_Addon_Schema::PRICING_MATRIX;
	}

	public function label(): string {
		return __( 'Full matrix (option × size)', 'lafka-plugin' );
	}

	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		// Per-option matrices are already in canonical storage shape.
		return $group;
	}

	public function validate( Lafka_Addon_Group $group ): array {
		$errors = array();
		if ( 1 !== $group->variations || $group->attribute <= 0 ) {
			$errors[] = sprintf(
				/* translators: %s: group name */
				__( '"%s" needs Variations enabled with an attribute selected for matrix pricing.', 'lafka-plugin' ),
				$group->name
			);
		}
		return $errors;
	}
}
