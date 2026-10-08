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

$lafka_is_attribute_source = Lafka_Addon_Schema::SOURCE_ATTRIBUTE === $lafka_group->options_source;

// Build the matrix column list (size term slug → label). Computed regardless
// of current pricing_mode so the matrix columns are present in the DOM and
// can be revealed via CSS when the user toggles to matrix mode without
// reloading. The column set still depends on the saved size attribute +
// included_size_slugs — switching to matrix mode without a saved attribute
// shows the matrix-needs-attribute notice instead of phantom columns.
$lafka_matrix_columns = array();
if ( $lafka_group->attribute > 0 && function_exists( 'wc_attribute_taxonomy_name_by_id' ) ) {
	$lafka_tax_slug = wc_attribute_taxonomy_name_by_id( $lafka_group->attribute );
	if ( $lafka_tax_slug && taxonomy_exists( $lafka_tax_slug ) ) {
		$lafka_terms = get_terms(
			array(
				'taxonomy'   => $lafka_tax_slug,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $lafka_terms ) ) {
			foreach ( $lafka_terms as $lafka_term ) {
				if ( ! empty( $lafka_group->included_size_slugs ) && ! in_array( $lafka_term->slug, $lafka_group->included_size_slugs, true ) ) {
					continue;
				}
				$lafka_matrix_columns[ $lafka_tax_slug . ':' . $lafka_term->slug ] = array(
					'taxonomy' => $lafka_tax_slug,
					'slug'     => $lafka_term->slug,
					'name'     => $lafka_term->name,
				);
			}
		}
	}
}
?>
<fieldset class="lafka-engine-fieldset lafka-engine-options-section" data-lafka-options>
	<legend><?php esc_html_e( 'Options', 'lafka-plugin' ); ?></legend>

	<?php if ( empty( $lafka_matrix_columns ) ) : ?>
		<p class="lafka-engine-matrix-needs-attribute description" style="display:none;">
			<?php esc_html_e( 'Pick a size attribute and at least one size term above to configure matrix prices.', 'lafka-plugin' ); ?>
		</p>
	<?php endif; ?>

	<table class="widefat lafka-engine-options-table" data-pricing-mode="<?php echo esc_attr( $lafka_group->pricing_mode ); ?>" data-attribute-source="<?php echo $lafka_is_attribute_source ? '1' : '0'; ?>">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Include', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Label', 'lafka-plugin' ); ?></th>
				<th class="lafka-col-price"><?php esc_html_e( 'Price', 'lafka-plugin' ); ?></th>
				<?php foreach ( $lafka_matrix_columns as $lafka_col ) : ?>
					<th class="lafka-col-matrix" data-tax="<?php echo esc_attr( $lafka_col['taxonomy'] ); ?>" data-slug="<?php echo esc_attr( $lafka_col['slug'] ); ?>"><?php echo esc_html( $lafka_col['name'] ); ?></th>
				<?php endforeach; ?>
				<th><?php esc_html_e( 'Default', 'lafka-plugin' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody data-lafka-option-rows>
			<?php
			// option-row.php now always renders the per-option price + matrix
			// cells; CSS shows the right column set based on data-pricing-mode.
			$lafka_shows_per_option_price = true; // always emit; CSS hides when not active mode
			$lafka_shows_matrix_price     = true; // always emit if columns exist; CSS hides when not active mode
			foreach ( $lafka_group->options as $lafka_option_index => $lafka_option ) {
				require __DIR__ . '/option-row.php';
			}
			?>
		</tbody>
	</table>

	<?php if ( ! $lafka_is_attribute_source ) : ?>
		<p>
			<button type="button" class="button" data-lafka-add-option><?php esc_html_e( 'Add option', 'lafka-plugin' ); ?></button>
		</p>
	<?php endif; ?>
</fieldset>
