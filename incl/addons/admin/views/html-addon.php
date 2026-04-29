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
		<strong><?php esc_html_e( 'Group', 'lafka-plugin' ); ?> <span class="group_name">
		<?php
		if ( $addon['name'] ) {
			echo '"' . esc_attr( $addon['name'] ) . '"';}
		?>
		</span> &mdash; </strong>
		<select name="product_addon_type[<?php echo esc_attr( $loop ); ?>]" class="product_addon_type">
			<option <?php selected( 'checkbox', $addon['type'] ); ?> value="checkbox"><?php esc_html_e( 'Checkboxes', 'lafka-plugin' ); ?></option>
			<option <?php selected( 'radiobutton', $addon['type'] ); ?> value="radiobutton"><?php esc_html_e( 'Radio buttons', 'lafka-plugin' ); ?></option>
			<option <?php selected( 'textarea', $addon['type'] ); ?> value="textarea"><?php esc_html_e( 'Textarea', 'lafka-plugin' ); ?></option>
		</select>
		<input type="hidden" name="product_addon_position[<?php echo esc_attr( $loop ); ?>]" class="product_addon_position" value="<?php echo esc_attr( $loop ); ?>" />
	</h3>
	<table class="wc-metabox-content">
		<tbody>
			<tr>
				<td class="addon_name">
					<label for="addon_name_
					<?php
					is_product();
					echo $loop;
					?>
					">
						<?php esc_html_e( 'Name', 'lafka-plugin' ); ?>
					</label>
				</td>
				<td class="addon_name">
					<input type="text" id="addon_name_<?php echo esc_attr( $loop ); ?>" name="product_addon_name[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $addon['name'] ); ?>" />
				</td>
			</tr>
			<tr>
				<td class="addon_limit">
					<label for="addon_limit_<?php echo esc_attr( $loop ); ?>"><?php esc_html_e( 'Maximum Number of Selectable Options', 'lafka-plugin' ); ?></label>
				</td>
				<td class="addon_limit">
					<label for="addon_limit_<?php echo esc_attr( $loop ); ?>">
						<input type="number" id="addon_limit_<?php echo esc_attr( $loop ); ?>" name="product_addon_limit[<?php echo esc_attr( $loop ); ?>]" value="<?php echo isset( $addon['limit'] ) ? esc_attr( $addon['limit'] ) : ''; ?>" />
					</label>
				</td>
			</tr>
			<tr>
				<td class="addon_checkbox">
					<label for="addon_required_<?php echo esc_attr( $loop ); ?>"><?php esc_html_e( 'Required Fields', 'lafka-plugin' ); ?></label>
				</td>
				<td class="addon_checkbox">
					<label for="addon_required_<?php echo esc_attr( $loop ); ?>">
						<input type="checkbox" id="addon_required_<?php echo esc_attr( $loop ); ?>" name="product_addon_required[<?php echo esc_attr( $loop ); ?>]" <?php checked( $addon['required'], 1 ); ?> />
						<?php esc_html_e( 'Mark fields group as required', 'lafka-plugin' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<td class="addon_checkbox">
					<label for="addon_variations_<?php echo esc_attr( $loop ); ?>"><?php esc_html_e( 'Use in Variations ', 'lafka-plugin' ); ?></label>
				</td>
				<td class="addon_checkbox">
					<label for="addon_variations_<?php echo esc_attr( $loop ); ?>">
						<?php $is_variations = isset( $addon['variations'] ) ? $addon['variations'] : 0; ?>
						<input class="lafka-addon-variation-checkbox" type="checkbox" id="addon_variations_<?php echo esc_attr( $loop ); ?>" name="product_addon_variations[<?php echo esc_attr( $loop ); ?>]" <?php checked( $is_variations, 1 ); ?> />
						<?php esc_html_e( 'Mark fields group to be used in variations', 'lafka-plugin' ); ?>
					</label>
				</td>
			</tr>
			<tr class="lafka-addon-attributes-select-row">
				<td class="addon_select">
					<label for="addon_attribute_<?php echo esc_attr( $loop ); ?>">
						<?php esc_html_e( 'Choose attribute on which the variation is based.', 'lafka-plugin' ); ?>
						<br>
						<?php esc_html_e( 'Then you will be able to set different addon prices for each attribute value.', 'lafka-plugin' ); ?>
						<br>
						<b><?php esc_html_e( 'NOTE: Products which have addons with variable prices must have default variation.', 'lafka-plugin' ); ?></b>
					</label>
				</td>
				<td class="addon_select">
					<select name="product_addon_variation_attribute[<?php echo esc_attr( $loop ); ?>]" class="lafka-addon-attributes-select addon_select">
						<?php foreach ( $attribute_taxonomies as $tax ) : ?>
							<?php $addon_attribute = isset( $addon['attribute'] ) ? $addon['attribute'] : ''; ?>
							<option <?php selected( $tax->attribute_id, $addon_attribute ); ?> value="<?php echo esc_attr( $tax->attribute_id ); ?>"
								data-attribute-values="<?php echo esc_attr( wp_json_encode( Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( wc_attribute_taxonomy_name_by_id( (int) $tax->attribute_id ) ) ) ); ?>">
								<?php echo esc_html( $tax->attribute_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<td class="addon_description" colspan="2">
					<label for="addon_description_<?php echo esc_attr( $loop ); ?>">
						<?php
						esc_html_e( 'Description', 'lafka-plugin' );
						?>
					</label>
					<textarea cols="20" id="addon_description_<?php echo esc_attr( $loop ); ?>" rows="3" name="product_addon_description[<?php echo esc_attr( $loop ); ?>]"><?php echo esc_textarea( $addon['description'] ); ?></textarea>
				</td>
			</tr>
			<?php do_action( 'lafka_product_addons_panel_before_options', $post, $addon, $loop ); ?>
			<tr>
				<td class="data" colspan="3">
					<table cellspacing="0" cellpadding="0">
						<thead>
							<tr>
								<th class="label_column"><?php esc_html_e( 'Label', 'lafka-plugin' ); ?></th>
								<th class="image_column"><?php esc_html_e( 'Image', 'lafka-plugin' ); ?></th>
								<?php
								// Resolve which taxonomy this addon's per-attribute pricing
								// should be keyed by. Two-step resolution:
								//   1. Configured attribute (preferred) — $addon['attribute'] is
								//      a WC attribute_taxonomy ID. Look up its taxonomy slug.
								//   2. Detected from data — if the configured attribute doesn't
								//      resolve to terms (e.g. attribute was deleted, or the ID
								//      was stale from an earlier site state), fall back to
								//      whatever taxonomy the FIRST option's price array is
								//      keyed by (e.g. 'pa_size'). This preserves the data
								//      structure operators painstakingly built and prevents
								//      the form from overwriting good data with empty values.
								$attribute_values = array();
								$has_variations   = isset( $addon['variations'] ) && (int) $addon['variations'] === 1;

								if ( $has_variations && ! empty( $addon['attribute'] ) ) {
									$attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values(
										wc_attribute_taxonomy_name_by_id( (int) $addon['attribute'] )
									);
								}

								if ( $has_variations && empty( $attribute_values ) && ! empty( $addon['options'] ) ) {
									$first_option = reset( $addon['options'] );
									if ( ! empty( $first_option['price'] ) && is_array( $first_option['price'] ) ) {
										$detected_taxonomy = (string) key( $first_option['price'] );
										if ( $detected_taxonomy && taxonomy_exists( $detected_taxonomy ) ) {
											$attribute_values = Lafka_Product_Addon_Admin::lafka_get_addons_variations_attribute_values( $detected_taxonomy );
										}
									}
								}
								?>
								<?php if ( $has_variations && ! empty( $attribute_values ) ) : ?>
									<?php foreach ( $attribute_values as $attribute_name => $name_value_pair ) : ?>
										<?php foreach ( $name_value_pair as $slug => $value ) : ?>
											<th class="price_column"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?> <?php echo esc_html( $value ); ?></th>
										<?php endforeach; ?>
									<?php endforeach; ?>
								<?php else : ?>
									<th class="price_column"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?></th>
								<?php endif; ?>
								<th class="minmax_column"><span class="column-title"><?php esc_html_e( 'Min / Max', 'lafka-plugin' ); ?></span></th>
								<th width="10%" class="lafka-is-default-column"><?php esc_html_e( 'Default Value', 'lafka-plugin' ); ?></th>
								<?php do_action( 'lafka_product_addons_panel_option_heading', $post, $addon, $loop ); ?>
								<th width="1%"></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<td colspan="5"><button type="button" class="add_addon_option button"><?php esc_html_e( 'New&nbsp;Option', 'lafka-plugin' ); ?></button></td>
							</tr>
						</tfoot>
						<tbody>
							<?php
							foreach ( $addon['options'] as $option ) {
								require __DIR__ . '/html-addon-option.php';
							}
							?>
						</tbody>
					</table>
				</td>
			</tr>
		</tbody>
	</table>
</div>