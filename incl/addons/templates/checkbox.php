<?php defined( 'ABSPATH' ) || exit; ?>
<?php
// The posted add-to-cart values read in this template only preserve form state
// on re-render during validation failures.
/** @var array $addon */
foreach ( $addon['options'] as $lafka_i => $lafka_option ) :
	/**
	 * @var WC_Product $product
	 * @var Lafka_Engine_Display $lafka_engine_display
	 */

	global $product;
	$lafka_engine_display = $GLOBALS['Lafka_Engine_Display'];

	$lafka_option_price             = lafka_get_option_price_on_default_attribute( $product, $lafka_option['price'] );
	$lafka_option_price_for_display = '';
	if ( is_numeric( $lafka_option_price ) ) {
		// Wrap the whole "($price)" block in a <span class="lafka-addon-price">
		// so themes can position it as one inline element. Without the wrapper,
		// the bare "(" and ")" parens are text nodes that don't move with the
		// .woocommerce-Price-amount span when a theme uses flex/grid alignment
		// — splitting "(" left and ")$3.50" right. The span is structural, not
		// stylistic: themes that don't target it still see "(<wc_price>)".
		$lafka_option_price_for_display = '<span class="lafka-addon-price">(' . wc_price( Lafka_Engine_Helper::get_product_addon_price_for_display( $lafka_option_price ) ) . ')</span>';
	}

	$lafka_price = apply_filters( 'lafka_product_addons_option_price', $lafka_option_price_for_display, $lafka_option, $lafka_i, 'checkbox' );

	$lafka_option_id = ! empty( $lafka_option['id'] ) ? $lafka_option['id'] : sanitize_title( $lafka_option['label'] );

	$lafka_selected = array();
	$lafka_posted   = Lafka_Engine_Cart::request_post_data();
	if ( isset( $lafka_posted[ 'addon-' . sanitize_title( $addon['field-name'] ) ] ) ) {
		$lafka_selected = $lafka_posted[ 'addon-' . sanitize_title( $addon['field-name'] ) ];
	} elseif ( ! empty( $lafka_option['default'] ) ) {
		$lafka_selected = array( $lafka_option_id );
	}

	if ( ! is_array( $lafka_selected ) ) {
		$lafka_selected = array( $lafka_selected );
	}

	$lafka_current_value = ( in_array( (string) $lafka_option_id, array_map( 'strval', (array) $lafka_selected ), true ) ) ? 1 : 0;

	$lafka_attribute_raw_prices = $lafka_option['price'];
	$lafka_attribute_prices     = lafka_convert_attribute_raw_prices_to_prices( $lafka_attribute_raw_prices );

	$lafka_custom_image_id      = $lafka_engine_display->get_addon_option_custom_image_id( $lafka_option );
	$lafka_custom_image_classes = $lafka_engine_display->get_addon_option_image_classes( $lafka_custom_image_id );
	?>

	<p class="form-row form-row-wide addon-wrap-<?php echo esc_attr( sanitize_title( $addon['field-name'] ) . '-' . $lafka_i ); ?>">
		<label><input type="checkbox" class="addon addon-checkbox" name="addon-<?php echo esc_attr( sanitize_title( $addon['field-name'] ) ); ?>[]"
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
						value="<?php echo esc_attr( $lafka_option_id ); ?>" <?php checked( $lafka_current_value, 1 ); ?> /><?php echo ' '; ?>
			<?php if ( $lafka_custom_image_id ) : ?>
				<?php echo wp_get_attachment_image( $lafka_custom_image_id, 'lafka-widgets-thumb', false, array( 'class' => implode( ' ', $lafka_custom_image_classes ) ) ); ?>
			<?php endif; ?>
			<?php
			// $price is HTML built by wc_price() (e.g., "(<span class="amount">$3.50</span>)").
			// esc_html() would escape the spans to entities and render them as visible text.
			// wp_kses_post() preserves trusted markup (spans/bdi/sup) while stripping anything
			// dangerous a hostile filter on lafka_product_addons_option_price might inject.
			echo esc_html( wptexturize( $lafka_option['label'] ) ) . ' ' . wp_kses_post( $lafka_price );
			?>
			</label>
	</p>

<?php endforeach; ?>
