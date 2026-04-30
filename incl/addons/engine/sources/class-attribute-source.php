<?php
/**
 * Attribute options source — load addon options from a WooCommerce product
 * attribute taxonomy's terms.
 *
 * Operators add an attribute (e.g., `pa_premium_toppings`) with terms (Cheese,
 * Truffle, Bacon) once. Then any number of addon groups can `sync` against
 * that attribute and have their option list auto-populated. Per-option
 * settings (price, included flag) are preserved across syncs by label match.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Attribute_Source extends Lafka_Abstract_Options_Source {

	public function id(): string {
		return Lafka_Addon_Schema::SOURCE_ATTRIBUTE;
	}

	public function label(): string {
		return __( 'From product attribute', 'lafka-plugin' );
	}

	public function get_options( Lafka_Addon_Group $group ): array {
		$taxonomy = $group->options_source_attribute;
		if ( '' === $taxonomy || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $taxonomy ) ) {
			return $group->options;
		}
		$terms = $this->fetch_terms( $taxonomy );
		if ( empty( $terms ) ) {
			return $group->options;
		}

		$existing_by_label = $this->index_by_label( $group->options );
		$options           = array();
		foreach ( $terms as $term ) {
			$existing = $existing_by_label[ strtolower( $term->name ) ] ?? null;
			if ( $existing ) {
				$options[] = $existing;
			} else {
				$options[] = Lafka_Addon_Option::from_array( array( 'label' => $term->name ) );
			}
		}
		return $options;
	}

	public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		$synced_options = $this->get_options( $group );
		if ( $synced_options === $group->options ) {
			return $group;
		}
		return $group->with_options( $synced_options );
	}

	/**
	 * @return object[]
	 */
	private function fetch_terms( string $taxonomy ): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
			return array();
		}
		return is_array( $terms ) ? $terms : array();
	}
}
