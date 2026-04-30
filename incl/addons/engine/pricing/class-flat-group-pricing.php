<?php
/**
 * Flat group pricing — every option in the group costs the same fixed price
 * regardless of which option is selected or which size variation applies.
 *
 * Storage shape after expand():
 *   each option's price = $group_flat_price scalar
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Flat_Group_Pricing extends Lafka_Abstract_Pricing_Strategy {

	public function id(): string {
		return Lafka_Addon_Schema::PRICING_FLAT_GROUP;
	}

	public function label(): string {
		return __( 'Flat for whole group', 'lafka-plugin' );
	}

	public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		$price         = $group->group_flat_price;
		$expanded_opts = array();
		foreach ( $group->options as $option ) {
			$expanded_opts[] = $option->with_price( $price );
		}
		return $group->with_options( $expanded_opts );
	}

	public function validate( Lafka_Addon_Group $group ): array {
		$errors = array();
		if ( '' === trim( $group->group_flat_price ) ) {
			$errors[] = sprintf(
				/* translators: %s: group name */
				__( '"%s" requires a flat price.', 'lafka-plugin' ),
				$group->name
			);
		}
		return $errors;
	}
}
