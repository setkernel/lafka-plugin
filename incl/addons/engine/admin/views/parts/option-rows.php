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

// Build the matrix column list (size term slug → label). Computed regardless
// of current pricing_mode so the matrix columns are present in the DOM and
// can be revealed via CSS when the user toggles to matrix mode without
// reloading. The column set still depends on the saved size attribute +
// included_size_slugs — switching to matrix mode without a saved attribute
// shows the matrix-needs-attribute notice instead of phantom columns.
$matrix_columns = array();
if ( $group->attribute > 0 && function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
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

	<?php if ( empty( $matrix_columns ) ) : ?>
		<p class="lafka-engine-matrix-needs-attribute description" style="display:none;">
			<?php esc_html_e( 'Pick a size attribute and at least one size term above to configure matrix prices.', 'lafka-plugin' ); ?>
		</p>
	<?php endif; ?>

	<table class="widefat lafka-engine-options-table" data-pricing-mode="<?php echo esc_attr( $group->pricing_mode ); ?>" data-attribute-source="<?php echo $is_attribute_source ? '1' : '0'; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Include', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Label', 'lafka-plugin' ); ?></th>
				<th class="lafka-col-price"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?></th>
				<?php foreach ( $matrix_columns as $col ) : ?>
					<th class="lafka-col-matrix" data-tax="<?php echo esc_attr( $col['taxonomy'] ); ?>" data-slug="<?php echo esc_attr( $col['slug'] ); ?>"><?php echo esc_html( $col['name'] ); ?></th>
				<?php endforeach; ?>
				<th><?php esc_html_e( 'Default', 'lafka-plugin' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody data-lafka-option-rows>
			<?php
            // option-row.php now always renders the per-option price + matrix
            // cells; CSS shows the right column set based on data-pricing-mode.
            $shows_per_option_price = true; // always emit; CSS hides when not active mode
            $shows_matrix_price     = true; // always emit if columns exist; CSS hides when not active mode
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
