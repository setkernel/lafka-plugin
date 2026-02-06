<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $post;
?>
<div class="lafka_product_addon wc-metabox closed">
	<h3>
		<button type="button" class="remove_addon button"><?php esc_html_e( 'Remove', 'lafka-plugin' ); ?></button>
		<div class="handlediv" title="<?php esc_html_e( 'Click to toggle', 'lafka-plugin' ); ?>"></div>
		<strong><?php esc_html_e( 'Group', 'lafka-plugin' ); ?> <span class="group_name"><?php if ( $addon['name'] ) echo '"' . esc_attr( $addon['name'] ) . '"'; ?></span> &mdash; </strong>
        <select name="product_addon_type[<?php echo $loop; ?>]" class="product_addon_type">
            <option <?php selected('checkbox', $addon['type']); ?> value="checkbox"><?php _e('Checkboxes', 'lafka-plugin'); ?></option>
            <option <?php selected('radiobutton', $addon['type']); ?> value="radiobutton"><?php _e('Radio buttons', 'lafka-plugin'); ?></option>
            <option <?php selected('textarea', $addon['type']); ?> value="textarea"><?php _e('Textarea', 'lafka-plugin'); ?></option>
        </select>
        <input type="hidden" name="product_addon_position[<?php echo $loop; ?>]" class="product_addon_position" value="<?php echo $loop; ?>" />
	</h3>
	<table class="wc-metabox-content">
		<tbody>
			<tr>
				<td class="addon_name">
					<label for="addon_name_<?php  is_product();  echo $loop; ?>">
						<?php esc_html_e( 'Name', 'lafka-plugin' ); ?>
					</label>
				</td>
				<td class="addon_name">
                    <input type="text" id="addon_name_<?php echo $loop; ?>" name="product_addon_name[<?php echo $loop; ?>]" value="<?php echo esc_attr( $addon['name'] ) ?>" />
				</td>
			</tr>
			<tr>
				<td class="addon_limit">
					<label for="addon_limit_<?php echo $loop; ?>"><?php esc_html_e( 'Maximum Number of Selectable Options', 'lafka-plugin' ); ?></label>
				</td>
				<td class="addon_limit">
					<label for="addon_limit_<?php echo $loop; ?>">
						<input type="number" id="addon_limit_<?php echo $loop; ?>" name="product_addon_limit[<?php echo $loop; ?>]" value="<?php echo isset($addon['limit']) ? esc_attr( $addon['limit'] ) : '' ?>" />
					</label>
				</td>
			</tr>
            <tr>
                <td class="addon_checkbox">
                    <label for="addon_required_<?php echo $loop; ?>"><?php esc_html_e( 'Required Fields', 'lafka-plugin' ); ?></label>
                </td>
                <td class="addon_checkbox">
                    <label for="addon_required_<?php echo $loop; ?>">
                        <input type="checkbox" id="addon_required_<?php echo $loop; ?>" name="product_addon_required[<?php echo $loop; ?>]" <?php checked( $addon['required'], 1 ) ?> />
                        <?php esc_html_e( 'Mark fields group as required', 'lafka-plugin' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <td class="addon_checkbox">
                    <label for="addon_variations_<?php echo $loop; ?>"><?php esc_html_e( 'Use in Variations ', 'lafka-plugin' ); ?></label>
                </td>
                <td class="addon_checkbox">
                    <label for="addon_variations_<?php echo $loop; ?>">
	                    <?php $is_variations = isset($addon['variations']) ? $addon['variations'] : 0; ?>
                        <input class="lafka-addon-variation-checkbox" type="checkbox" id="addon_variations_<?php echo $loop; ?>" name="product_addon_variations[<?php echo $loop; ?>]" <?php checked( $is_variations, 1 ) ?> />
						<?php esc_html_e( 'Mark fields group to be used in variations', 'lafka-plugin' ); ?>
                    </label>
                </td>
            </tr>
            <tr class="lafka-addon-attributes-select-row">
                <td class="addon_select">
                    <label for="addon_attribute_<?php echo $loop; ?>">
			            <?php esc_html_e( 'Choose attribute on which the variation is based.', 'lafka-plugin' ); ?>
                        <br>
			            <?php esc_html_e( 'Then you will be able to set different addon prices for each attribute value.', 'lafka-plugin' ); ?>
                        <br>
                        <b><?php esc_html_e( 'NOTE: Products which have addons with variable prices must have default variation.', 'lafka-plugin' ); ?></b>
                    </label>
                </td>
                <td class="addon_select">
                    <select name="product_addon_variation_attribute[<?php echo $loop; ?>]" class="lafka-addon-attributes-select addon_select">
			            <?php foreach ( $attribute_taxonomies as $tax ): ?>
				            <?php $addon_attribute = isset($addon['attribute']) ? $addon['attribute'] : ''; ?>
                            <option <?php selected( $tax->attribute_id, $addon_attribute ); ?> value="<?php esc_attr_e( $tax->attribute_id ); ?>"
                                data-attribute-values="<?php esc_attr_e( htmlspecialchars(json_encode( Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( wc_attribute_taxonomy_name_by_id( (int) $tax->attribute_id ) ) ) ) ); ?>">
                                <?php esc_html_e( $tax->attribute_label ); ?>
                            </option>
			            <?php endforeach; ?>
                    </select>
                </td>
            </tr>
			<tr>
				<td class="addon_description" colspan="2">
					<label for="addon_description_<?php echo $loop; ?>">
						<?php
						esc_html_e( 'Description', 'lafka-plugin' );
						?>
					</label>
					<textarea cols="20" id="addon_description_<?php echo $loop; ?>" rows="3" name="product_addon_description[<?php echo $loop; ?>]"><?php echo esc_textarea( $addon['description'] ) ?></textarea>
				</td>
			</tr>
			<?php do_action( 'lafka_product_addons_panel_before_options', $post, $addon, $loop ); ?>
			<tr>
				<td class="data" colspan="3">
					<table cellspacing="0" cellpadding="0">
						<thead>
							<tr>
								<th class="label_column"><?php esc_html_e('Label', 'lafka-plugin'); ?></th>
                                <th class="image_column"><?php esc_html_e('Image', 'lafka-plugin'); ?></th>
								<?php
                                    if(isset($addon['variations']) && $addon['variations'] === 1 && is_int($addon['attribute'])) {
	                                    $attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( wc_attribute_taxonomy_name_by_id( $addon['attribute'] ) );
                                    }
                                ?>
								<?php if (isset($addon['variations']) && $addon['variations'] === 1 && !empty( $attribute_values ) ): ?>
									<?php foreach ( $attribute_values as $attribute_name => $name_value_pair ): ?>
								        <?php foreach ($name_value_pair as $slug => $value): ?>
                                            <th class="price_column"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?> <?php echo esc_html( $value ) ?></th>
								        <?php endforeach; ?>
									<?php endforeach; ?>
								<?php else: ?>
                                    <th class="price_column"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?></th>
								<?php endif; ?>
                                <th class="minmax_column"><span class="column-title"><?php esc_html_e('Min / Max', 'lafka-plugin'); ?></span></th>
                                <th width="10%" class="lafka-is-default-column"><?php esc_html_e('Default Value', 'lafka-plugin'); ?></th>
								<?php do_action( 'lafka_product_addons_panel_option_heading', $post, $addon, $loop ); ?>
								<th width="1%"></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<td colspan="5"><button type="button" class="add_addon_option button"><?php esc_html_e('New&nbsp;Option', 'lafka-plugin'); ?></button></td>
							</tr>
						</tfoot>
						<tbody>
							<?php
							foreach ( $addon['options'] as $option )
								include( dirname( __FILE__ ) . '/html-addon-option.php' );
							?>
						</tbody>
					</table>
				</td>
			</tr>
		</tbody>
	</table>
</div>