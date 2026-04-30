<?php
/**
 * Source selector partial — manual or attribute-sourced.
 *
 * Variables in scope: $group, $prefix, $product_attributes
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;
?>
<fieldset class="lafka-engine-fieldset" data-lafka-source-selector>
	<legend><?php esc_html_e( 'Where do options come from?', 'lafka-plugin' ); ?></legend>
	<label>
		<input type="radio" name="<?php echo esc_attr( $prefix . '[options_source]' ); ?>" value="<?php echo esc_attr( Lafka_Addon_Schema::SOURCE_MANUAL ); ?>" <?php checked( $group->options_source, Lafka_Addon_Schema::SOURCE_MANUAL ); ?> data-lafka-source />
		<?php esc_html_e( 'Manual — type each option below', 'lafka-plugin' ); ?>
	</label>
	<br>
	<label>
		<input type="radio" name="<?php echo esc_attr( $prefix . '[options_source]' ); ?>" value="<?php echo esc_attr( Lafka_Addon_Schema::SOURCE_ATTRIBUTE ); ?>" <?php checked( $group->options_source, Lafka_Addon_Schema::SOURCE_ATTRIBUTE ); ?> data-lafka-source />
		<?php esc_html_e( 'From product attribute', 'lafka-plugin' ); ?>
	</label>

	<div class="lafka-engine-source-attribute" <?php echo Lafka_Addon_Schema::SOURCE_ATTRIBUTE === $group->options_source ? '' : 'style="display:none;"'; ?>>
		<select name="<?php echo esc_attr( $prefix . '[options_source_attribute]' ); ?>" data-lafka-source-attribute>
			<option value=""><?php esc_html_e( '— Pick an attribute —', 'lafka-plugin' ); ?></option>
			<?php
            foreach ( $product_attributes as $tax ) :
				$slug = function_exists( 'wc_attribute_taxonomy_name' ) ? wc_attribute_taxonomy_name( $tax->attribute_name ) : 'pa_' . $tax->attribute_name;
				?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $group->options_source_attribute, $slug ); ?>>
					<?php echo esc_html( $tax->attribute_label . ' (' . $slug . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="button" data-lafka-sync-attribute><?php esc_html_e( 'Sync options', 'lafka-plugin' ); ?></button>
		<p class="description"><?php esc_html_e( 'Pull options from the attribute\'s terms. Existing prices and include/exclude flags are preserved by label match.', 'lafka-plugin' ); ?></p>
	</div>
</fieldset>
