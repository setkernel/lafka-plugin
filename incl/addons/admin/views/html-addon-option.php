<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr>
	<td class="label_column">
        <input type="text" name="product_addon_option_label[<?php echo $loop; ?>][]" value="<?php echo esc_attr( $option['label'] ); ?>" placeholder="<?php esc_html_e( 'Default Label', 'lafka-plugin' ); ?>" />
    </td>
    <td class="image_column">
		<?php $image_input_name = 'product_addon_option_image[' . $loop . '][]'; ?>
		<?php $image_input_id = 'product_addon_option_image_' . $loop . '_' . uniqid(); ?>
		<?php echo lafka_medialibrary_uploader( $image_input_id, (empty($option['image']) ? '' : $option['image']), '', $image_input_name, false, true ); ?>
    </td>
	<?php
	if(isset($addon['variations']) && $addon['variations'] === 1 && is_int($addon['attribute'])) {
		$attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( wc_attribute_taxonomy_name_by_id( $addon['attribute'] ) );
	}
	?>
	<?php if (isset($addon['variations']) && $addon['variations'] === 1 && !empty( $attribute_values ) ): ?>
		<?php $prices_array = (array) $option['price']; ?>
		<?php foreach ( $attribute_values as $attribute_name => $name_value_pair ): ?>
			<?php foreach ($name_value_pair as $slug => $value): ?>
                <?php $price = (string) isset($prices_array[$attribute_name]) ? $prices_array[$attribute_name] : reset($prices_array)?>
                <td class="price_column">
                    <input type="text" name="product_addon_option_price[<?php echo $loop; ?>][<?php echo $attribute_name; ?>][<?php echo $slug; ?>][]"
                           value="<?php echo isset($price[ $slug ]) ? esc_attr( wc_format_localized_price( $price[ $slug ] ) ) : ''; ?>" placeholder="0.00" class="wc_input_price"/>
                </td>
	        <?php endforeach; ?>
		<?php endforeach; ?>
	<?php else: ?>
		<?php $price = is_array($option['price']) ? (string) reset($option['price']) : $option['price'] ?>
        <td class="price_column">
            <input type="text" name="product_addon_option_price[<?php echo $loop; ?>][]" value="<?php echo esc_attr( wc_format_localized_price( $price ) ); ?>" placeholder="0.00" class="wc_input_price"/>
        </td>
	<?php endif; ?>
    <td class="minmax_column">
        <input type="number" name="product_addon_option_min[<?php echo $loop; ?>][]" value="<?php echo isset($option['min']) ? esc_attr( $option['min'] ) : '' ?>" placeholder="Min" min="0" step="any" />
        <input type="number" name="product_addon_option_max[<?php echo $loop; ?>][]" value="<?php echo isset($option['max']) ? esc_attr( $option['max'] ) : '' ?>" placeholder="Max" min="0" step="any" />
    </td>

    <td class="lafka-is-default-column" width="10%">
        <input class="lafka-is-default-value" type="hidden" name="product_addon_option_default[<?php echo $loop; ?>][]" value="<?php echo esc_attr( $option['default'] ); ?>" />
	    <?php
	    $inputs_type = 'checkbox';
	    $inputs_name = 'product_addon_option_default_switch[' . $loop . '][]';
	    if ( isset($addon['type']) && $addon['type'] === 'radiobutton' ) {
		    $inputs_type = 'radio';
		    $inputs_name = 'product_addon_option_default_switch[' . $loop . ']';
	    }
        ?>
        <input class="lafka-is-default-switch" type="<?php echo esc_attr($inputs_type); ?>" name="<?php echo esc_attr($inputs_name); ?>" value="1" <?php checked( 1, $option['default'] ) ?> />
    </td>

	<?php do_action( 'lafka_product_addons_panel_option_row', isset( $post ) ? $post : null, $product_addons, $loop, $option ); ?>

	<td class="actions" width="1%"><button type="button" class="remove_addon_option button">x</button></td>
</tr>