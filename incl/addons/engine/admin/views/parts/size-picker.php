<?php
/**
 * Size attribute + size include picker partial.
 *
 * Used by flat_per_size and matrix pricing modes.
 *
 * Variables in scope: $group, $prefix, $product_attributes
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

$size_terms = array();
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
			$size_terms = $terms;
		}
	}
}

$is_size_mode = in_array(
	$group->pricing_mode,
	array( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, Lafka_Addon_Schema::PRICING_MATRIX ),
	true
);
?>
<fieldset class="lafka-engine-fieldset lafka-engine-size-section" <?php echo $is_size_mode ? '' : 'style="display:none;"'; ?> data-lafka-size-section>
	<legend><?php esc_html_e( 'Size attribute (for per-size pricing)', 'lafka-plugin' ); ?></legend>

	<p>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $prefix . '[variations]' ); ?>" value="1" <?php checked( 1, $group->variations ); ?> data-lafka-variations />
			<?php esc_html_e( 'Use a size attribute', 'lafka-plugin' ); ?>
		</label>
	</p>

	<p>
		<label>
			<?php esc_html_e( 'Size attribute:', 'lafka-plugin' ); ?>
			<select name="<?php echo esc_attr( $prefix . '[attribute]' ); ?>" data-lafka-size-attribute>
				<option value="0"><?php esc_html_e( '— Pick an attribute —', 'lafka-plugin' ); ?></option>
				<?php foreach ( $product_attributes as $tax ) : ?>
					<option value="<?php echo esc_attr( (int) $tax->attribute_id ); ?>" <?php selected( (int) $group->attribute, (int) $tax->attribute_id ); ?>>
						<?php echo esc_html( $tax->attribute_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</p>

	<?php if ( ! empty( $size_terms ) ) : ?>
		<div class="lafka-engine-size-terms">
			<p>
				<strong><?php esc_html_e( 'Size terms to include', 'lafka-plugin' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Deselect a size to hide this addon group on PDPs for that size.', 'lafka-plugin' ); ?></span>
			</p>
			<?php
            foreach ( $size_terms as $term ) :
				$slug = $term->slug;
				$included = empty( $group->included_size_slugs ) || in_array( $slug, $group->included_size_slugs, true );
				?>
				<label class="lafka-engine-size-term">
					<input type="checkbox" name="<?php echo esc_attr( $prefix . '[included_size_slugs][]' ); ?>" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $included ); ?> />
					<?php echo esc_html( $term->name ); ?>
					<?php if ( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE === $group->pricing_mode ) : ?>
						<input type="text" name="<?php echo esc_attr( $prefix . '[group_size_prices][' . $slug . ']' ); ?>" value="<?php echo esc_attr( $group->group_size_prices[ $slug ] ?? '' ); ?>" class="wc_input_price small-text" placeholder="0.00" />
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</fieldset>
