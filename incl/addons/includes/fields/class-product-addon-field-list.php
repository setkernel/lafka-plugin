<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * Checkbox/radios field
 */
class Lafka_Product_Addon_Field_List extends Lafka_Product_Addon_Field {

	/**
	 * Validate an addon
	 *
	 * @return bool|WP_Error
	 */
	public function validate() {
		if ( ! empty( $this->addon['required'] ) ) {
			if ( ! $this->value || ( is_array($this->value) && sizeof( $this->value ) ) == 0 ) {
				return new WP_Error( 'error', sprintf( esc_html__( '"%s" is a required field.', 'lafka-plugin' ), $this->addon['name'] ) );
			}
		}

		if ( ! empty( $this->addon['limit'] ) ) {
			if ( is_array( $this->value ) && sizeof( $this->value ) > intval( $this->addon['limit'] ) ) {
				return new WP_Error( 'error', sprintf( esc_html__( 'Select up to %d "%s".', 'lafka-plugin' ), $this->addon['limit'], $this->addon['name'] ) );
			}
		}

		return true;
	}

	/**
	 * Process this field after being posted
	 *
	 * @return array|WP_Error Array on success and WP_Error on failure
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

		if ( is_array( current( $value ) ) ) {
			$value = current( $value );
		}

		foreach ( $this->addon['options'] as $option ) {
			$option_id    = ! empty( $option['id'] ) ? $option['id'] : sanitize_title( $option['label'] );
			$value_lower  = array_map( 'strtolower', array_values( $value ) );
			// Match by stable ID first, then fall back to label slug for legacy data.
			$matched = in_array( strtolower( $option_id ), $value_lower, true )
			        || in_array( strtolower( sanitize_title( $option['label'] ) ), $value_lower, true );
			if ( $matched ) {
				$cart_item_data[] = array(
					'name'  => $this->addon['name'],
					'image' => $option['image'] ?? '',
					'value' => $option['label'],
					'price' => $this->get_option_price( $option )
				);
			}
		}

		return $cart_item_data;
	}
}