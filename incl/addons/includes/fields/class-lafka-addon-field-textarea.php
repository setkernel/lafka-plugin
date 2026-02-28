<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * Custom fields (text)
 */
class Lafka_Addon_Field_Textarea extends Lafka_Product_Addon_Field {

	/**
	 * Validate an addon
	 * @return bool|WP_Error
	 */
	public function validate() {
		foreach ( $this->addon['options'] as $key => $option ) {
			$option_key = ! empty( $option['id'] ) ? $option['id'] : ( empty( $option['label'] ) ? $key : sanitize_title( $option['label'] ) );
			// Fallback: also check legacy label-based key if ID key not found.
			if ( ! isset( $this->value[ $option_key ] ) && ! empty( $option['id'] ) ) {
				$legacy_key = empty( $option['label'] ) ? $key : sanitize_title( $option['label'] );
				if ( isset( $this->value[ $legacy_key ] ) ) {
					$option_key = $legacy_key;
				}
			}
			$posted     = isset( $this->value[ $option_key ] ) ? $this->value[ $option_key ] : '';

			// Required addon checks
			if ( ! empty( $this->addon['required'] ) ) {
				if ( $posted === "" || ( is_array($posted) && sizeof( $posted ) == 0 ) ) {
					return new WP_Error( 'error', sprintf( __( '"%s" is a required field.', 'lafka-plugin' ), $this->addon['name'] ) );
				}
			}

			// Min/max character length checks for textarea
			if ( ! empty( $option['min'] ) && ! empty( $posted ) && mb_strlen( $posted, 'UTF-8' ) < $option['min'] ) {
				return new WP_Error( 'error', sprintf( __( 'The minimum allowed length for "%s - %s" is %s.', 'lafka-plugin' ), $this->addon['name'], $option['label'], $option['min'] ) );
			}

			if ( ! empty( $option['max'] ) && ! empty( $posted ) && mb_strlen( $posted, 'UTF-8' ) > $option['max'] ) {
				return new WP_Error( 'error', sprintf( __( 'The maximum allowed length for "%s - %s" is %s.', 'lafka-plugin' ), $this->addon['name'], $option['label'], $option['max'] ) );
			}
		}
		return true;
	}

	/**
	 * Process this field after being posted
	 * @return array on success, WP_ERROR on failure
	 */
	public function get_cart_item_data() {
		$cart_item_data           = array();

		foreach ( $this->addon['options'] as $key => $option ) {
			$option_key = ! empty( $option['id'] ) ? $option['id'] : ( empty( $option['label'] ) ? $key : sanitize_title( $option['label'] ) );
			// Fallback: also check legacy label-based key if ID key not found.
			if ( ! isset( $this->value[ $option_key ] ) && ! empty( $option['id'] ) ) {
				$legacy_key = empty( $option['label'] ) ? $key : sanitize_title( $option['label'] );
				if ( isset( $this->value[ $legacy_key ] ) ) {
					$option_key = $legacy_key;
				}
			}
			$posted     = isset( $this->value[ $option_key ] ) ? $this->value[ $option_key ] : '';

			if ( '' === $posted ) {
				continue;
			}

			$label = $this->get_option_label( $option );
			$price = $this->get_option_price( $option );

			$cart_item_data[] = array(
				'name'   => $label,
				'value'  => wp_kses_post( $posted ),
				'price'  => $price,
			);
		}

		return $cart_item_data;
	}

}
