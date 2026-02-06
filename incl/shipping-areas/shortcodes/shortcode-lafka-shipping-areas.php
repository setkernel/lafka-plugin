<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lafka_shipping_areas_shortcode( $atts = [], $content = null, $tag = '' ): string {
	// normalize attribute keys, lowercase
	$atts = array_change_key_case( (array) $atts, CASE_LOWER );

	// override default attributes with user attributes
	$shortcode_atts = shortcode_atts(
		array(
			'title'              => '',
			'areas'              => '',
			'map_height'         => '400',
			'css'                => '',
			'circle_area'        => 'no',
			'circle_radius'      => '',
			'circle_radius_unit' => 'metric',
			'circle_label_text'  => '',
			'circle_area_color'  => '',
		),
		$atts,
		$tag
	);

	$css_class = apply_filters( VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, vc_shortcode_custom_css_class( $shortcode_atts['css'], ' ' ), 'lafka_shipping_areas', $shortcode_atts );

	$area_params = json_decode( urldecode( $shortcode_atts['areas'] ), true );

	$areas_array = array();
	if ( ! empty( $area_params ) ) {
		foreach ( $area_params as $area_param ) {
			if ( ! empty( $area_param['area_id'] ) ) {
				$areas_array[] = array(
					'label'               => $area_param['label_text'] ?? '',
					'label_position'      => $area_param['label_position'] ?? '',
					'polygon_coordinates' => get_post_meta( $area_param['area_id'], '_lafka_shipping_area_polygon_coordinates', true ),
					'color'               => $area_param['area_color'] ?? '',
				);
			}
		}
	}

	$options            = get_option( 'lafka_shipping_areas_advanced' );
	$set_store_location = empty( $options['set_store_location'] ) ? 'geo_woo_store' : $options['set_store_location'];
	$store_map_location = empty( $options['store_map_location'] ) ? '' : $options['store_map_location'];
	$shortcode_id       = wp_unique_id( 'lafka_shipping_areas_shortcode' );
	wp_enqueue_script( 'lafka-shipping-areas-shortcode-' . $shortcode_id, plugins_url( 'assets/js/frontend/lafka-shipping-areas-shortcode.min.js', __DIR__ ), array( 'lafka-google-maps' ), false, true );
	wp_localize_script(
		'lafka-shipping-areas-shortcode-' . $shortcode_id,
		'lafka_shipping_areas_shortcode_php_variables',
		array(
			'shortcode_id'       => $shortcode_id,
			'areas'              => json_encode( $areas_array ),
			'circle_area'        => $shortcode_atts['circle_area'],
			'circle_radius'      => $shortcode_atts['circle_radius'],
			'circle_radius_unit' => $shortcode_atts['circle_radius_unit'],
			'circle_label_text'  => $shortcode_atts['circle_label_text'],
			'circle_area_color'  => $shortcode_atts['circle_area_color'],
			'set_store_location' => $set_store_location,
			'store_location'     => Lafka_Shipping_Areas::get_store_address(),
			'store_map_location' => $store_map_location,
		)
	);

	ob_start();
	?>
	<div id="<?php echo esc_attr( $shortcode_id ); ?>" class="lafka-shipping-areas-shortcode <?php echo esc_attr( $css_class ); ?>">
		<h2><?php echo esc_html( $shortcode_atts['title'] ); ?> </h2>
		<div id="<?php echo esc_attr( $shortcode_id ) . '_map'; ?>" class="lafka-shipping-areas-shortcode-map" style="height: <?php echo esc_attr( $shortcode_atts['map_height'] ); ?>px;"></div>
	</div>
	<?php
	$output = ob_get_clean();

	// return output
	return $output;
}
