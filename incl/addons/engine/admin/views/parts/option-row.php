<?php
/**
 * One option row partial. Used by both the live form and by the JS template
 * for "Add option".
 *
 * Variables in scope:
 *   $option, $option_index, $group_index, $group, $shows_per_option_price,
 *   $shows_matrix_price, $matrix_columns, $is_attribute_source
 *
 * Form name pattern: lafka_addon_groups[$group_index][options][$option_index][...]
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $option, $option_index, $group_index, $group ) ) {
	return;
}

$option_prefix = 'lafka_addon_groups[' . $group_index . '][options][' . $option_index . ']';
$shows_per_option_price = $shows_per_option_price ?? ( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION === $group->pricing_mode );
$shows_matrix_price     = $shows_matrix_price ?? ( Lafka_Addon_Schema::PRICING_MATRIX === $group->pricing_mode );
$matrix_columns         = $matrix_columns ?? array();
$is_attribute_source    = $is_attribute_source ?? ( Lafka_Addon_Schema::SOURCE_ATTRIBUTE === $group->options_source );

$matrix_for_option = is_array( $option->price ) ? $option->price : array();
?>
<tr data-lafka-option-row data-option-index="<?php echo esc_attr( (string) $option_index ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $option_prefix . '[id]' ); ?>" value="<?php echo esc_attr( $option->id ); ?>" />

	<td>
		<input type="hidden" name="<?php echo esc_attr( $option_prefix . '[included]' ); ?>" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $option_prefix . '[included]' ); ?>" value="1" <?php checked( $option->included ); ?> />
	</td>

	<td>
		<?php if ( $is_attribute_source ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $option_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $option->label ); ?>" />
			<span class="lafka-engine-option-label-readonly"><?php echo esc_html( $option->label ); ?></span>
		<?php else : ?>
			<input type="text" name="<?php echo esc_attr( $option_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $option->label ); ?>" class="regular-text" />
		<?php endif; ?>
	</td>

	<?php
    if ( $shows_per_option_price ) :
		$scalar_price = is_scalar( $option->price ) ? (string) $option->price : '';
		?>
		<td>
			<input type="text" name="<?php echo esc_attr( $option_prefix . '[price]' ); ?>" value="<?php echo esc_attr( $scalar_price ); ?>" class="wc_input_price small-text" placeholder="0.00" />
		</td>
	<?php endif; ?>

	<?php
    if ( $shows_matrix_price && ! empty( $matrix_columns ) ) :
		foreach ( $matrix_columns as $col ) :
			$cell_value = $matrix_for_option[ $col['taxonomy'] ][ $col['slug'] ] ?? '';
			?>
			<td>
				<input type="text"
					name="<?php echo esc_attr( $option_prefix . '[matrix_price][' . $col['taxonomy'] . '][' . $col['slug'] . ']' ); ?>"
					value="<?php echo esc_attr( is_scalar( $cell_value ) ? (string) $cell_value : '' ); ?>"
					class="wc_input_price small-text"
					placeholder="0.00" />
			</td>
			<?php
        endforeach;
	endif;
    ?>

	<td>
		<input type="hidden" name="<?php echo esc_attr( $option_prefix . '[default]' ); ?>" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $option_prefix . '[default]' ); ?>" value="1" <?php checked( '1', $option->default ); ?> />
	</td>

	<td>
		<?php if ( ! $is_attribute_source ) : ?>
			<button type="button" class="button-link-delete" data-lafka-remove-option>×</button>
		<?php endif; ?>
	</td>
</tr>
