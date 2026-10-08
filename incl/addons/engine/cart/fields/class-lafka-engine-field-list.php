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

		// Every submitted value must be one of the options on offer. A crafted
		// post naming an option the operator excluded (or that never existed)
		// is rejected rather than silently dropped.
		foreach ( $this->submitted_values() as $submitted ) {
			if ( null === $this->find_option( $submitted ) ) {
				return new WP_Error(
					'lafka_addon_invalid_option',
					sprintf(
						/* translators: %s: addon name */
						esc_html__( 'Please choose a valid option for "%s".', 'lafka-plugin' ),
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
		$value          = $this->submitted_values();

		if ( empty( $value ) ) {
			return false;
		}

		$value_lower = array_map( 'strtolower', $value );

		foreach ( $this->addon['options'] as $option ) {
			if ( $this->option_matches( $option, $value_lower ) ) {
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

	/**
	 * The submitted value normalised to a flat list of non-empty strings.
	 * Accepts a scalar (radio), a list (checkbox) or the nested
	 * arrays-of-arrays shape some form serialisers produce.
	 *
	 * @return string[]
	 */
	private function submitted_values(): array {
		$value = $this->value;
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}
		if ( is_array( current( $value ) ) ) {
			$value = current( $value );
		}

		$out = array();
		foreach ( $value as $entry ) {
			if ( is_scalar( $entry ) && '' !== trim( (string) $entry ) ) {
				$out[] = (string) $entry;
			}
		}
		return $out;
	}

	/**
	 * The offered option a submitted value refers to (by stable id, or the
	 * legacy label slug), or null when none matches.
	 */
	private function find_option( string $submitted ): ?array {
		$needle = array( strtolower( $submitted ) );
		foreach ( $this->addon['options'] as $option ) {
			if ( $this->option_matches( $option, $needle ) ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * @param array    $option      Legacy-shape option.
	 * @param string[] $value_lower Lower-cased submitted values.
	 */
	private function option_matches( array $option, array $value_lower ): bool {
		$option_id = ! empty( $option['id'] ) ? $option['id'] : sanitize_title( $option['label'] ?? '' );

		return in_array( strtolower( (string) $option_id ), $value_lower, true )
			|| in_array( strtolower( sanitize_title( $option['label'] ?? '' ) ), $value_lower, true );
	}
}
