<?php
/**
 * Lafka_Engine_Field — abstract base for addon field types in the cart layer.
 *
 * Each field implementation knows how to:
 *   - validate() the submitted value against the addon definition
 *   - get_cart_item_data() shape it into the cart_item['addons'][] entries
 *
 * Replaces the legacy Lafka_Product_Addon_Field base. Same surface contract.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_Engine_Field {

	public array $addon;
	/** @var mixed */
	public $value;

	public function __construct( array $addon, $value = '' ) {
		$this->addon = $addon;
		$this->value = $value;
	}

	/**
	 * Default: always passes. Subclasses override.
	 *
	 * @return bool|WP_Error
	 */
	public function validate() {
		return true;
	}

	/**
	 * Default: nothing added to cart. Subclasses override.
	 *
	 * @return array<int, array{name: string, value: string, price: mixed, image?: string}>|false
	 */
	public function get_cart_item_data() {
		return false;
	}

	public function get_field_name(): string {
		return 'addon-' . sanitize_title( $this->addon['field-name'] ?? '' );
	}

	public function get_option_label( array $option ): string {
		return ! empty( $option['label'] )
			? sanitize_text_field( $this->addon['name'] ) . ' - ' . sanitize_text_field( $option['label'] )
			: sanitize_text_field( $this->addon['name'] );
	}

	/**
	 * @return string|array
	 */
	public function get_option_price( array $option ) {
		return $option['price'] ?? '';
	}
}
