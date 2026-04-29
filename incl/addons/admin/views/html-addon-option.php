<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr>
	<td class="label_column">
		<input type="hidden" name="product_addon_option_id[<?php echo $loop; ?>][]" value="<?php echo esc_attr( ! empty( $option['id'] ) ? $option['id'] : '' ); ?>" />
		<input type="text" name="product_addon_option_label[<?php echo $loop; ?>][]" value="<?php echo esc_attr( $option['label'] ); ?>" placeholder="<?php esc_html_e( 'Default Label', 'lafka-plugin' ); ?>" />
	</td>
	<td class="image_column">
		<?php $image_input_name = 'product_addon_option_image[' . $loop . '][]'; ?>
		<?php $image_input_id = 'product_addon_option_image_' . $loop . '_' . uniqid(); ?>
		<?php echo lafka_medialibrary_uploader( $image_input_id, ( empty( $option['image'] ) ? '' : $option['image'] ), '', $image_input_name, false, true ); ?>
	</td>
	<?php
	// Resolve attribute taxonomy — same two-step logic as html-addon.php:
	// configured attribute first, fall back to data-detected taxonomy. Keeping
	// the resolution in this file too because option rows are rendered in a
	// foreach loop and we want each row to use the same column structure as
	// the parent header.
	$attribute_values = array();
	$has_variations   = isset( $addon['variations'] ) && (int) $addon['variations'] === 1;

	if ( $has_variations && ! empty( $addon['attribute'] ) ) {
		$attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values(
			wc_attribute_taxonomy_name_by_id( (int) $addon['attribute'] )
		);
	}
	if ( $has_variations && empty( $attribute_values ) && ! empty( $option['price'] ) && is_array( $option['price'] ) ) {
		$detected_taxonomy = (string) key( $option['price'] );
		if ( $detected_taxonomy && taxonomy_exists( $detected_taxonomy ) ) {
			$attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( $detected_taxonomy );
		}
	}
	?>
	<?php if ( $has_variations && ! empty( $attribute_values ) ) : ?>
		<?php $prices_array = is_array( $option['price'] ) ? $option['price'] : array(); ?>
		<?php foreach ( $attribute_values as $attribute_name => $name_value_pair ) : ?>
			<?php
			// Per-attribute price array, e.g. ['small' => '1.00', 'medium' => '1.50'].
			// Fix from upstream: previous code had `(string) isset(...) ? ... : ...`
			// which casts the bool to string ("1" or ""), making the ternary always
			// evaluate the truthy branch. That meant the fallback to reset() never
			// fired correctly. Pure ternary now.
			$attr_prices = isset( $prices_array[ $attribute_name ] ) && is_array( $prices_array[ $attribute_name ] )
				? $prices_array[ $attribute_name ]
				: array();
			?>
			<?php foreach ( $name_value_pair as $slug => $value ) : ?>
				<?php
				$cell_value = isset( $attr_prices[ $slug ] ) && is_scalar( $attr_prices[ $slug ] )
					? wc_format_localized_price( $attr_prices[ $slug ] )
					: '';
				?>
				<td class="price_column">
					<input type="text" name="product_addon_option_price[<?php echo $loop; ?>][<?php echo esc_attr( $attribute_name ); ?>][<?php echo esc_attr( $slug ); ?>][]"
							value="<?php echo esc_attr( $cell_value ); ?>" placeholder="0.00" class="wc_input_price"/>
				</td>
			<?php endforeach; ?>
		<?php endforeach; ?>
	<?php else : ?>
		<?php
		// Price can be a multi-dimensional array (when the addon was originally
		// configured with `variations: 1` and per-attribute pricing) but the
		// addon's attribute taxonomy may have been deleted or the variations
		// flag turned off. The previous (string)reset() on a nested array
		// produced the literal string "Array". Walk into nested arrays until
		// we hit a scalar; if everything is array-shaped, render empty so the
		// operator can re-enter the price cleanly.
		$price = $option['price'];
		while ( is_array( $price ) ) {
			$price = empty( $price ) ? '' : reset( $price );
		}
		$price = is_scalar( $price ) ? (string) $price : '';
		?>
		<td class="price_column">
			<input type="text" name="product_addon_option_price[<?php echo $loop; ?>][]" value="<?php echo esc_attr( wc_format_localized_price( $price ) ); ?>" placeholder="0.00" class="wc_input_price"/>
		</td>
	<?php endif; ?>
	<td class="minmax_column">
		<input type="number" name="product_addon_option_min[<?php echo $loop; ?>][]" value="<?php echo isset( $option['min'] ) ? esc_attr( $option['min'] ) : ''; ?>" placeholder="Min" min="0" step="any" />
		<input type="number" name="product_addon_option_max[<?php echo $loop; ?>][]" value="<?php echo isset( $option['max'] ) ? esc_attr( $option['max'] ) : ''; ?>" placeholder="Max" min="0" step="any" />
	</td>

	<td class="lafka-is-default-column" width="10%">
		<input class="lafka-is-default-value" type="hidden" name="product_addon_option_default[<?php echo $loop; ?>][]" value="<?php echo esc_attr( $option['default'] ); ?>" />
		<?php
		$inputs_type = 'checkbox';
		$inputs_name = 'product_addon_option_default_switch[' . $loop . '][]';
		if ( isset( $addon['type'] ) && $addon['type'] === 'radiobutton' ) {
			$inputs_type = 'radio';
			$inputs_name = 'product_addon_option_default_switch[' . $loop . ']';
		}
		?>
		<input class="lafka-is-default-switch" type="<?php echo esc_attr( $inputs_type ); ?>" name="<?php echo esc_attr( $inputs_name ); ?>" value="1" <?php checked( 1, $option['default'] ); ?> />
	</td>

	<?php do_action( 'lafka_product_addons_panel_option_row', isset( $post ) ? $post : null, $product_addons, $loop, $option ); ?>

	<td class="actions" width="1%"><button type="button" class="remove_addon_option button">x</button></td>
</tr>