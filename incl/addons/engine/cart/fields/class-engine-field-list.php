<?php
/**
 * Lafka_Engine_Field_List — checkbox + radiobutton handling.
 *
 * Both checkbox and radiobutton submit a list-shaped value (array of option
 * IDs for checkbox, single ID for radiobutton). Validation enforces the
 * `required` and `limit` constraints; get_cart_item_data() materializes one
 * cart-line entry per selected option, keyed off stable option IDs with
 * fallback to label-slug for legacy data.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Field_List extends Lafka_Engine_Field {

	/**
	 * @return bool|WP_Error
	 */
	public function validate() {
		if ( ! empty( $this->addon['required'] ) ) {
			$is_empty = false;
			if ( is_array( $this->value ) ) {
				$filtered = array_filter(
					$this->value,
					static function ( $v ) {
						return is_array( $v ) ? ! empty( $v ) : '' !== trim( (string) $v );
					}
				);
				$is_empty = empty( $filtered );
			} else {
				$is_empty = '' === trim( (string) $this->value );
			}
			if ( $is_empty ) {
				return new WP_Error(
					'lafka_addon_required',
					sprintf(
						/* translators: %s: addon name */
						esc_html__( '"%s" is a required field.', 'lafka-plugin' ),
						$this->addon['name']
					)
				);
			}
		}

		if ( ! empty( $this->addon['limit'] ) && is_array( $this->value ) && count( $this->value ) > (int) $this->addon['limit'] ) {
			return new WP_Error(
				'lafka_addon_over_limit',
				sprintf(
					/* translators: 1: limit count, 2: addon name */
					esc_html__( 'Select up to %1$d "%2$s".', 'lafka-plugin' ),
					(int) $this->addon['limit'],
					$this->addon['name']
				)
			);
		}

		return true;
	}

	/**
	 * @return array<int, array{name: string, value: string, price: mixed, image?: string}>|false
	 */
	public function get_cart_item_data() {
		$cart_item_data = array();
		$value          = $this->value;

		if ( empty( $value ) ) {
			return false;
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		// Deeply nested submission shape (form arrays as arrays-of-arrays).
		if ( is_array( current( $value ) ) ) {
			$value = current( $value );
		}

		$value_lower = array_map( 'strtolower', array_map( 'strval', $value ) );

		foreach ( $this->addon['options'] as $option ) {
			$option_id = ! empty( $option['id'] ) ? $option['id'] : sanitize_title( $option['label'] ?? '' );

			$matched = in_array( strtolower( (string) $option_id ), $value_lower, true )
				|| in_array( strtolower( sanitize_title( $option['label'] ?? '' ) ), $value_lower, true );

			if ( $matched ) {
				$cart_item_data[] = array(
					'name'  => $this->addon['name'],
					'image' => $option['image'] ?? '',
					'value' => $option['label'] ?? '',
					'price' => $this->get_option_price( $option ),
				);
			}
		}

		return $cart_item_data;
	}
}
