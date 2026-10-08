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

if ( ! isset( $lafka_option, $lafka_option_index, $lafka_group_index, $lafka_group ) ) {
	return;
}

$lafka_option_prefix          = 'lafka_addon_groups[' . $lafka_group_index . '][options][' . $lafka_option_index . ']';
$lafka_shows_per_option_price = $lafka_shows_per_option_price ?? ( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION === $lafka_group->pricing_mode );
$lafka_shows_matrix_price     = $lafka_shows_matrix_price ?? ( Lafka_Addon_Schema::PRICING_MATRIX === $lafka_group->pricing_mode );
$lafka_matrix_columns         = $lafka_matrix_columns ?? array();
$lafka_is_attribute_source    = $lafka_is_attribute_source ?? ( Lafka_Addon_Schema::SOURCE_ATTRIBUTE === $lafka_group->options_source );

$lafka_matrix_for_option = is_array( $lafka_option->price ) ? $lafka_option->price : array();
?>
<tr data-lafka-option-row data-option-index="<?php echo esc_attr( (string) $lafka_option_index ); ?>">
	<input type="hidden" name="<?php echo esc_attr( $lafka_option_prefix . '[id]' ); ?>" value="<?php echo esc_attr( $lafka_option->id ); ?>" />

	<td>
		<input type="hidden" name="<?php echo esc_attr( $lafka_option_prefix . '[included]' ); ?>" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $lafka_option_prefix . '[included]' ); ?>" value="1" <?php checked( $lafka_option->included ); ?> />
	</td>

	<td>
		<?php if ( $lafka_is_attribute_source ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $lafka_option_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $lafka_option->label ); ?>" />
			<span class="lafka-engine-option-label-readonly"><?php echo esc_html( $lafka_option->label ); ?></span>
		<?php else : ?>
			<input type="text" name="<?php echo esc_attr( $lafka_option_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $lafka_option->label ); ?>" class="regular-text" />
		<?php endif; ?>
	</td>

	<?php
	// Always emit the per-option price cell so CSS can toggle visibility when
	// the user changes pricing_mode without saving. Engine save semantics
	// ignore the field when pricing_mode isn't flat_per_option.
	$lafka_scalar_price = is_scalar( $lafka_option->price ) ? (string) $lafka_option->price : '';
	?>
	<td class="lafka-col-price">
		<input type="text" name="<?php echo esc_attr( $lafka_option_prefix . '[price]' ); ?>" value="<?php echo esc_attr( $lafka_scalar_price ); ?>" class="wc_input_price small-text" placeholder="0.00" />
	</td>

	<?php
	// Always emit matrix cells when columns exist (regardless of current
	// pricing_mode). CSS hides them outside matrix mode. If the saved data
	// has no matrix prices yet, cells render empty for the user to fill in.
	if ( ! empty( $lafka_matrix_columns ) ) :
		foreach ( $lafka_matrix_columns as $lafka_col ) :
			$lafka_cell_value = $lafka_matrix_for_option[ $lafka_col['taxonomy'] ][ $lafka_col['slug'] ] ?? '';
			?>
			<td class="lafka-col-matrix" data-tax="<?php echo esc_attr( $lafka_col['taxonomy'] ); ?>" data-slug="<?php echo esc_attr( $lafka_col['slug'] ); ?>">
				<input type="text"
					name="<?php echo esc_attr( $lafka_option_prefix . '[matrix_price][' . $lafka_col['taxonomy'] . '][' . $lafka_col['slug'] . ']' ); ?>"
					value="<?php echo esc_attr( is_scalar( $lafka_cell_value ) ? (string) $lafka_cell_value : '' ); ?>"
					class="wc_input_price small-text"
					placeholder="0.00" />
			</td>
			<?php
		endforeach;
	endif;
	?>

	<td>
		<input type="hidden" name="<?php echo esc_attr( $lafka_option_prefix . '[default]' ); ?>" value="0" />
		<input type="checkbox" name="<?php echo esc_attr( $lafka_option_prefix . '[default]' ); ?>" value="1" <?php checked( '1', $lafka_option->default ); ?> />
	</td>

	<td>
		<?php if ( ! $lafka_is_attribute_source ) : ?>
			<button type="button" class="button-link-delete" data-lafka-remove-option>×</button>
		<?php endif; ?>
	</td>
</tr>
