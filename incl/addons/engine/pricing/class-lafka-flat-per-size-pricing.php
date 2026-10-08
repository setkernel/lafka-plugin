<?php
/**
 * Flat per size pricing — every option in the group costs the same per-size
 * price, but different sizes can have different prices.
 *
 * Example: small=$0.50, medium=$1.00, large=$1.50 — applies uniformly to
 * every topping in the group.
 *
 * Storage shape after expand():
 *   each option's price = nested matrix [taxonomy_slug => [size_slug => scalar]]
 *
 * Reuses the existing nested-array storage so cart math + display layer
 * (apply_attribute_specific_price, walk_to_scalar_price) need zero changes.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Per_Size_Pricing extends Lafka_Abstract_Pricing_Strategy {

	public function id(): string {
		return Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE;
	}

	public function label(): string {
		return __( 'Flat per size', 'lafka-plugin' );
	}

	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		$taxonomy = $this->resolve_taxonomy_slug( $group );
		if ( '' === $taxonomy || empty( $group->group_size_prices ) ) {
			return $group;
		}

		// Filter out sizes the operator deselected.
		$size_prices = array();
		foreach ( $group->group_size_prices as $size_slug => $price ) {
			if ( ! $group->includes_size( (string) $size_slug ) ) {
				continue;
			}
			$size_prices[ (string) $size_slug ] = (string) $price;
		}
		if ( empty( $size_prices ) ) {
			return $group;
		}

		$matrix = array( $taxonomy => $size_prices );

		$expanded_opts = array();
		foreach ( $group->options as $option ) {
			$expanded_opts[] = $option->with_price( $matrix );
		}
		return $group->with_options( $expanded_opts );
	}

	public function validate( Lafka_Addon_Group $group ): array {
		$errors = array();
		if ( 1 !== $group->variations || $group->attribute <= 0 ) {
			$errors[] = sprintf(
				/* translators: %s: group name */
				__( '"%s" needs Variations enabled with an attribute selected for flat-per-size pricing.', 'lafka-plugin' ),
				$group->name
			);
		}
		if ( empty( $group->group_size_prices ) ) {
			$errors[] = sprintf(
				/* translators: %s: group name */
				__( '"%s" requires at least one per-size price.', 'lafka-plugin' ),
				$group->name
			);
		}
		return $errors;
	}
}
