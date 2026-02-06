<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="lafka_product_nutrition_data" class="panel woocommerce_options_panel wc-metaboxes-wrapper">
	<div class="options_group lafka-nutrition-info-group">
		<?php foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $nutrition_meta_field => $data ) : ?>
			<p class="form-field">
				<label for="_<?php echo esc_attr( $nutrition_meta_field ); ?>"><?php echo esc_html( $data['label'] ); ?></label>
				<input type="text" name="_<?php echo esc_attr( $nutrition_meta_field ); ?>" placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>"
						id="_<?php echo esc_attr( $nutrition_meta_field ); ?>" value="<?php echo esc_attr( ${$nutrition_meta_field} ); ?>">
			</p>
		<?php endforeach; ?>
	</div>
	<div class="options_group">
		<p class="form-field">
			<label for="_lafka_product_allergens"><?php esc_html_e( 'Allergens', 'lafka-plugin' ); ?></label>
			<span class="woocommerce-help-tip" data-tip="<?php esc_html_e( 'Enter the list of allergens as they will appear in the frontend.', 'lafka-plugin' ); ?>"></span>
			<input type="text" class="short" name="_lafka_product_allergens" placeholder="<?php esc_html_e( 'Milk, Eggs, Peanuts', 'lafka-plugin' ); ?>" id="_lafka_product_allergens"
					value="<?php echo esc_attr( $lafka_product_allergens ); ?>">
		</p>
	</div>
</div>