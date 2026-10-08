<?php defined( 'ABSPATH' ) || exit; ?>
<?php
// $_POST reads in this template are for preserving form state on re-render
// during validation failures. WC verifies the add-to-cart nonce upstream
// before this template is included in the variations form output.
/** @var array $addon */
foreach ( $addon['options'] as $lafka_key => $lafka_option ) :
	/**
	 * @var WC_Product $product
	 * @var Lafka_Engine_Display $lafka_engine_display
	 */

	global $product;
	$lafka_engine_display = $GLOBALS['Lafka_Engine_Display'];

	$lafka_option_price             = lafka_get_option_price_on_default_attribute( $product, $lafka_option['price'] );
	$lafka_option_price_for_display = '';
	if ( is_numeric( $lafka_option_price ) ) {
		// See checkbox.php for the .lafka-addon-price wrapper rationale.
		$lafka_option_price_for_display = '<span class="lafka-addon-price">(' . wc_price( Lafka_Engine_Helper::get_product_addon_price_for_display( $lafka_option_price ) ) . ')</span>';
	}

	$lafka_addon_key     = 'addon-' . sanitize_title( $addon['field-name'] );
	$lafka_option_key    = ! empty( $lafka_option['id'] ) ? $lafka_option['id'] : ( empty( $lafka_option['label'] ) ? $lafka_key : sanitize_title( $lafka_option['label'] ) );
	$lafka_current_value = isset( $_POST[ $lafka_addon_key ] ) && isset( $_POST[ $lafka_addon_key ][ $lafka_option_key ] ) ? wc_clean( $_POST[ $lafka_addon_key ][ $lafka_option_key ] ) : '';
	$lafka_price         = apply_filters(
		'lafka_product_addons_option_price',
		$lafka_option_price_for_display,
		$lafka_option,
		$lafka_key,
		'textarea'
	);

	$lafka_attribute_raw_prices = $lafka_option['price'];
	$lafka_attribute_prices     = lafka_convert_attribute_raw_prices_to_prices( $lafka_attribute_raw_prices );

	$lafka_custom_image_id      = $lafka_engine_display->get_addon_option_custom_image_id( $lafka_option );
	$lafka_custom_image_classes = $lafka_engine_display->get_addon_option_image_classes( $lafka_custom_image_id );
	?>

	<p class="form-row form-row-wide addon-wrap-<?php echo esc_attr( sanitize_title( $addon['field-name'] ) ); ?>">
		<?php if ( ! empty( $lafka_option['label'] ) ) : ?>
			<label>
				<?php if ( $lafka_custom_image_id ) : ?>
					<?php echo wp_get_attachment_image( $lafka_custom_image_id, 'lafka-widgets-thumb', false, array( 'class' => implode( ' ', $lafka_custom_image_classes ) ) ); ?>
				<?php endif; ?>
				<?php
				// See checkbox.php for the wp_kses_post-vs-esc_html rationale.
				echo esc_html( wptexturize( $lafka_option['label'] ) ) . ' ' . wp_kses_post( $lafka_price );
				?>
			</label>
		<?php endif; ?>
		<textarea type="text" class="input-text addon addon-custom-textarea"
					data-attribute-raw-prices="<?php echo esc_attr( wp_json_encode( $lafka_attribute_raw_prices ) ); ?>"
					data-attribute-prices="<?php echo esc_attr( wp_json_encode( $lafka_attribute_prices ) ); ?>"
					<?php $lafka_addon_attribute = isset( $addon['attribute'] ) ? wc_get_attribute( $addon['attribute'] ) : null; ?>
					<?php if ( ! is_null( $lafka_addon_attribute ) && isset( $lafka_attribute_prices[ $lafka_addon_attribute->slug ] ) && is_array( $lafka_attribute_prices[ $lafka_addon_attribute->slug ] ) ) : ?>
						<?php foreach ( $lafka_attribute_prices[ $lafka_addon_attribute->slug ] as $lafka_attribute => $lafka_attr_price ) : ?>
							data-<?php echo esc_html( $lafka_attribute ); ?>-formatted-price="<?php echo esc_html( wc_price( $lafka_attr_price ) ); ?>"
						<?php endforeach; ?>
					<?php endif; ?>
					data-raw-price="<?php echo esc_attr( $lafka_option_price ); ?>"
					data-price="<?php echo esc_attr( Lafka_Engine_Helper::get_product_addon_price_for_display( $lafka_option_price ) ); ?>"
					name="<?php echo esc_attr( $lafka_addon_key ); ?>[<?php echo esc_attr( $lafka_option_key ); ?>]" rows="4" cols="20"
					<?php
					if ( ! empty( $lafka_option['max'] ) ) {
						echo 'maxlength="' . esc_attr( $lafka_option['max'] ) . '"';}
					?>
					><?php echo esc_textarea( $lafka_current_value ); ?></textarea>
	</p>

<?php endforeach; ?>
