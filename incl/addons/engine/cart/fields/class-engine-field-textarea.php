<?php
/**
 * Lafka_Engine_Field_Textarea — custom-text fields per option.
 *
 * Each option in the addon definition becomes its own free-text input. The
 * submitted value is keyed by option ID (stable) with a fallback to legacy
 * label-slug keys for data captured before stable IDs landed in v8.6.0.
 *
 * Required + min/max character validation runs per option.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Field_Textarea extends Lafka_Engine_Field {

	/**
	 * @return bool|WP_Error
	 */
	public function validate() {
		foreach ( $this->addon['options'] as $key => $option ) {
			$option_key = $this->resolve_option_key( $option, $key );
			$posted     = $this->value[ $option_key ] ?? '';

			if ( ! empty( $this->addon['required'] ) ) {
				if ( '' === $posted || ( is_array( $posted ) && empty( $posted ) ) ) {
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

			if ( ! empty( $option['min'] ) && ! empty( $posted ) && mb_strlen( (string) $posted, 'UTF-8' ) < (int) $option['min'] ) {
				return new WP_Error(
					'lafka_addon_min_length',
					sprintf(
						/* translators: 1: addon name, 2: option label, 3: min length */
						esc_html__( 'The minimum allowed length for "%1$s - %2$s" is %3$s.', 'lafka-plugin' ),
						$this->addon['name'],
						$option['label'] ?? '',
						$option['min']
					)
				);
			}

			if ( ! empty( $option['max'] ) && ! empty( $posted ) && mb_strlen( (string) $posted, 'UTF-8' ) > (int) $option['max'] ) {
				return new WP_Error(
					'lafka_addon_max_length',
					sprintf(
						/* translators: 1: addon name, 2: option label, 3: max length */
						esc_html__( 'The maximum allowed length for "%1$s - %2$s" is %3$s.', 'lafka-plugin' ),
						$this->addon['name'],
						$option['label'] ?? '',
						$option['max']
					)
				);
			}
		}

		return true;
	}

	/**
	 * @return array<int, array{name: string, value: string, price: mixed}>|false
	 */
	public function get_cart_item_data() {
		$cart_item_data = array();

		foreach ( $this->addon['options'] as $key => $option ) {
			$option_key = $this->resolve_option_key( $option, $key );
			$posted     = $this->value[ $option_key ] ?? '';

			if ( '' === $posted ) {
				continue;
			}

			$cart_item_data[] = array(
				'name'  => $this->get_option_label( $option ),
				'value' => wp_kses_post( $posted ),
				'price' => $this->get_option_price( $option ),
			);
		}

		return $cart_item_data ?: false;
	}

	/**
	 * Pick the right submission key for an option: stable ID first, falling
	 * back to the legacy label-slug key when the value was submitted under
	 * the old shape (data captured before stable IDs landed in v8.6.0).
	 *
	 * @param array      $option
	 * @param int|string $iter_key Iteration index, used when neither id nor label exist.
	 */
	private function resolve_option_key( array $option, $iter_key ): string {
		$primary = ! empty( $option['id'] )
			? (string) $option['id']
			: ( empty( $option['label'] ) ? (string) $iter_key : sanitize_title( $option['label'] ) );

		if ( ! is_array( $this->value ) || isset( $this->value[ $primary ] ) ) {
			return $primary;
		}

		// Fallback: legacy label-slug key for data submitted before IDs existed.
		if ( ! empty( $option['id'] ) ) {
			$legacy = empty( $option['label'] ) ? (string) $iter_key : sanitize_title( $option['label'] );
			if ( isset( $this->value[ $legacy ] ) ) {
				return $legacy;
			}
		}

		return $primary;
	}
}
