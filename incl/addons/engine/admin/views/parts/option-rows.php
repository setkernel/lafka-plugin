<?php
/**
 * Option rows partial — wraps the option-row table for one group.
 *
 * Variables in scope: $group, $group_index, $prefix
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

$is_attribute_source = Lafka_Addon_Schema::SOURCE_ATTRIBUTE === $group->options_source;
$shows_per_option_price = Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION === $group->pricing_mode;
$shows_matrix_price     = Lafka_Addon_Schema::PRICING_MATRIX === $group->pricing_mode;

// Build the matrix column list (size term slug → label) for matrix mode.
$matrix_columns = array();
if ( $shows_matrix_price && $group->attribute > 0 && function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
	$tax_slug = wc_attribute_taxonomy_name_by_id( $group->attribute );
	if ( $tax_slug && taxonomy_exists( $tax_slug ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax_slug,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! empty( $group->included_size_slugs ) && ! in_array( $term->slug, $group->included_size_slugs, true ) ) {
					continue;
				}
				$matrix_columns[ $tax_slug . ':' . $term->slug ] = array(
					'taxonomy' => $tax_slug,
					'slug'     => $term->slug,
					'name'     => $term->name,
				);
			}
		}
	}
}
?>
<fieldset class="lafka-engine-fieldset lafka-engine-options-section" data-lafka-options>
	<legend><?php esc_html_e( 'Options', 'lafka-plugin' ); ?></legend>

	<table class="widefat lafka-engine-options-table" data-shows-per-option-price="<?php echo $shows_per_option_price ? '1' : '0'; ?>" data-shows-matrix-price="<?php echo $shows_matrix_price ? '1' : '0'; ?>" data-attribute-source="<?php echo $is_attribute_source ? '1' : '0'; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Include', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Label', 'lafka-plugin' ); ?></th>
				<?php if ( $shows_per_option_price ) : ?>
					<th><?php esc_html_e( 'Price', 'lafka-plugin' ); ?></th>
				<?php endif; ?>
				<?php if ( $shows_matrix_price && ! empty( $matrix_columns ) ) : ?>
					<?php foreach ( $matrix_columns as $col ) : ?>
						<th><?php echo esc_html( $col['name'] ); ?></th>
					<?php endforeach; ?>
				<?php endif; ?>
				<th><?php esc_html_e( 'Default', 'lafka-plugin' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody data-lafka-option-rows>
			<?php
            foreach ( $group->options as $option_index => $option ) {
				require __DIR__ . '/option-row.php';
			}
            ?>
		</tbody>
	</table>

	<?php if ( ! $is_attribute_source ) : ?>
		<p>
			<button type="button" class="button" data-lafka-add-option><?php esc_html_e( 'Add option', 'lafka-plugin' ); ?></button>
		</p>
	<?php endif; ?>
</fieldset>
