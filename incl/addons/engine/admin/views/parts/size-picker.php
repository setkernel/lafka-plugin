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

$lafka_size_terms = array();
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
			$lafka_size_terms = $lafka_terms;
		}
	}
}

$lafka_is_size_mode = in_array(
	$lafka_group->pricing_mode,
	array( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, Lafka_Addon_Schema::PRICING_MATRIX ),
	true
);
?>
<fieldset class="lafka-engine-fieldset lafka-engine-size-section" data-pricing-mode="<?php echo esc_attr( $lafka_group->pricing_mode ); ?>" <?php echo $lafka_is_size_mode ? '' : 'style="display:none;"'; ?> data-lafka-size-section>
	<legend><?php esc_html_e( 'Size attribute (for per-size pricing)', 'lafka-plugin' ); ?></legend>

	<p>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $lafka_prefix . '[variations]' ); ?>" value="1" <?php checked( 1, $lafka_group->variations ); ?> data-lafka-variations />
			<?php esc_html_e( 'Use a size attribute', 'lafka-plugin' ); ?>
		</label>
	</p>

	<p>
		<label>
			<?php esc_html_e( 'Size attribute:', 'lafka-plugin' ); ?>
			<select name="<?php echo esc_attr( $lafka_prefix . '[attribute]' ); ?>" data-lafka-size-attribute>
				<option value="0"><?php esc_html_e( '— Pick an attribute —', 'lafka-plugin' ); ?></option>
				<?php foreach ( $product_attributes as $lafka_tax ) : ?>
					<option value="<?php echo esc_attr( (int) $lafka_tax->attribute_id ); ?>" <?php selected( (int) $lafka_group->attribute, (int) $lafka_tax->attribute_id ); ?>>
						<?php echo esc_html( $lafka_tax->attribute_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
	</p>

	<?php if ( ! empty( $lafka_size_terms ) ) : ?>
		<div class="lafka-engine-size-terms">
			<p>
				<strong><?php esc_html_e( 'Size terms to include', 'lafka-plugin' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Deselect a size to hide this addon group on PDPs for that size.', 'lafka-plugin' ); ?></span>
			</p>
			<?php
			foreach ( $lafka_size_terms as $lafka_term ) :
				$lafka_slug     = $lafka_term->slug;
				$lafka_included = empty( $lafka_group->included_size_slugs ) || in_array( $lafka_slug, $lafka_group->included_size_slugs, true );
				?>
				<label class="lafka-engine-size-term">
					<input type="checkbox" name="<?php echo esc_attr( $lafka_prefix . '[included_size_slugs][]' ); ?>" value="<?php echo esc_attr( $lafka_slug ); ?>" <?php checked( $lafka_included ); ?> />
					<?php echo esc_html( $lafka_term->name ); ?>
					<input type="text"
						class="wc_input_price small-text lafka-engine-size-term-price"
						name="<?php echo esc_attr( $lafka_prefix . '[group_size_prices][' . $lafka_slug . ']' ); ?>"
						value="<?php echo esc_attr( $lafka_group->group_size_prices[ $lafka_slug ] ?? '' ); ?>"
						placeholder="0.00" />
				</label>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</fieldset>
