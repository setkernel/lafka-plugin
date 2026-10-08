<?php
/**
 * Common scaffolding for pricing strategies. Concrete classes override id(),
 * label(), expand(), validate() — base provides shared validation helpers.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_Abstract_Pricing_Strategy implements Lafka_Pricing_Strategy {

	/**
	 * Resolve the taxonomy slug from $group->attribute (a WC attribute_taxonomy
	 * ID). Returns empty string if WC is unavailable or attribute is invalid.
	 */
	protected function resolve_taxonomy_slug( Lafka_Addon_Group $group ): string {
		if ( $group->attribute <= 0 ) {
			return '';
		}
		if ( ! function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
			return '';
		}
		$slug = wc_attribute_taxonomy_name_by_id( $group->attribute );
		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * @return string[]
	 */
	public function validate( Lafka_Addon_Group $group ): array {
		return array();
	}
}
