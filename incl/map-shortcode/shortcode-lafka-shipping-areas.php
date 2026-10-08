<?php
/**
 * [lafka_shipping_areas] — a map of the delivery zones (and optionally a
 * radius around the store) for a "Where we deliver" page.
 *
 *   [lafka_shipping_areas title="Where we deliver" map_height="400"
 *       areas="<URL-encoded JSON list of {area_id, label_text, label_position, area_color}>"
 *       circle_area="yes" circle_radius="5" circle_radius_unit="metric"
 *       circle_label_text="5 km" circle_area_color="#d63638"]
 *
 * Draws with the configured map provider (keyless OpenStreetMap, or Google
 * with a key). Each instance carries its own settings in a data attribute,
 * so several maps can share a page. The radius is drawn around the store
 * point (lafka_get_store_point()), else around a geocode of the WooCommerce
 * store address.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../lafka-asset-helpers.php';

/**
 * Render the shortcode.
 *
 * @param array|string $atts    Attributes.
 * @param string|null  $content Unused.
 * @param string       $tag     Shortcode tag.
 * @return string
 */
function lafka_shipping_areas_shortcode( $atts = array(), $content = null, $tag = '' ): string {
	$atts = array_change_key_case( (array) $atts, CASE_LOWER );

	$shortcode_atts = shortcode_atts(
		array(
			'title'              => '',
			'areas'              => '',
			'map_height'         => '400',
			'circle_area'        => 'no',
			'circle_radius'      => '',
			'circle_radius_unit' => 'metric',
			'circle_label_text'  => '',
			'circle_area_color'  => '',
		),
		$atts,
		$tag
	);

	if ( ! lafka_enqueue_maps() ) {
		return '';
	}

	$area_params = json_decode( urldecode( (string) $shortcode_atts['areas'] ), true );
	$areas       = array();
	if ( is_array( $area_params ) ) {
		foreach ( $area_params as $area_param ) {
			if ( ! is_array( $area_param ) || empty( $area_param['area_id'] ) ) {
				continue;
			}
			$polygon = get_post_meta( (int) $area_param['area_id'], '_lafka_shipping_area_polygon_coordinates', true );
			if ( ! is_string( $polygon ) || '' === $polygon ) {
				continue;
			}
			$areas[] = array(
				'label'    => sanitize_text_field( (string) ( $area_param['label_text'] ?? '' ) ),
				'position' => sanitize_key( (string) ( $area_param['label_position'] ?? '' ) ),
				'polygon'  => $polygon,
				'color'    => (string) sanitize_hex_color( (string) ( $area_param['area_color'] ?? '' ) ),
			);
		}
	}

	$circle = null;
	if ( 'yes' === $shortcode_atts['circle_area'] && is_numeric( $shortcode_atts['circle_radius'] ) && (float) $shortcode_atts['circle_radius'] > 0 ) {
		$radius = (float) $shortcode_atts['circle_radius'];
		$circle = array(
			'metres'       => 'imperial' === $shortcode_atts['circle_radius_unit'] ? $radius * 1609.344 : $radius * 1000,
			'label'        => sanitize_text_field( (string) $shortcode_atts['circle_label_text'] ),
			'color'        => (string) sanitize_hex_color( (string) $shortcode_atts['circle_area_color'] ),
			'store'        => lafka_get_store_point(),
			'storeAddress' => lafka_geo_wc_store_address(),
		);
	}

	$shortcode_js = lafka_plugin_script_path( 'incl/shipping-areas/assets/js/frontend/lafka-shipping-areas-shortcode.min.js' );
	wp_enqueue_script( 'lafka-shipping-areas-shortcode', plugins_url( $shortcode_js, LAFKA_PLUGIN_FILE ), array( 'lafka-maps' ), lafka_plugin_asset_version( $shortcode_js ), true );

	$settings = array(
		'areas'  => $areas,
		'circle' => $circle,
	);

	ob_start();
	?>
	<div class="lafka-shipping-areas-shortcode">
		<?php if ( '' !== trim( (string) $shortcode_atts['title'] ) ) : ?>
			<h2><?php echo esc_html( $shortcode_atts['title'] ); ?></h2>
		<?php endif; ?>
		<div class="lafka-shipping-areas-shortcode-map" style="height: <?php echo (int) $shortcode_atts['map_height']; ?>px;" data-lafka-zones="<?php echo esc_attr( (string) wp_json_encode( $settings ) ); ?>"></div>
	</div>
	<?php

	return (string) ob_get_clean();
}
