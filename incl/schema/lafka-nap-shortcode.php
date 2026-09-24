<?php
/**
 * The [lafka_nap] shortcode: name / address / phone from the Customizer
 * Restaurant Information panel, via lafka_schema_get_nap().
 *
 * Moved verbatim out of lafka-plugin.php; the shortcode tag and callback
 * name are unchanged.
 *
 * @package Lafka\Plugin\Schema
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'lafka_nap', 'lafka_nap_shortcode' );
if ( ! function_exists( 'lafka_nap_shortcode' ) ) {
	/**
	 * P6-UX-5 + W2-T1: canonical NAP block. Reads from lafka_get_restaurant_info()
	 * via lafka_schema_get_nap() — the single source-of-truth shared with the
	 * JSON-LD module and the editorial templates. Operator content flows from
	 * the Customizer panel "Lafka — Restaurant Information".
	 *
	 * Usage:
	 *   [lafka_nap]                       // full address block
	 *   [lafka_nap part="address"]        // address line only
	 *   [lafka_nap part="phone"]          // tap-to-call phone link
	 *   [lafka_nap part="name"]           // restaurant name
	 *   [lafka_nap part="street"]         // street address
	 *   [lafka_nap part="city"]           // city
	 *   [lafka_nap part="region"]         // region/state
	 *   [lafka_nap part="postal"]         // postal/ZIP code
	 */
	function lafka_nap_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'part' => 'all' ), $atts, 'lafka_nap' );

		// Delegate to the canonical helper — Customizer-driven.
		$nap = lafka_schema_get_nap();

		$name   = $nap['name'];
		$street = $nap['street'];
		$city   = $nap['city'];
		$region = $nap['region'];
		$postal = $nap['postal'];
		$phone  = $nap['telephone_display'];
		$tel    = $nap['telephone'];

		$address_parts = array_filter( array( $street, trim( $city . ', ' . $region . ' ' . $postal, ' ,' ) ) );
		$address       = implode( ', ', $address_parts );

		switch ( $atts['part'] ) {
			case 'name':
				return esc_html( $name );
			case 'address':
				return esc_html( $address );
			case 'street':
				return esc_html( $street );
			case 'city':
				return esc_html( $city );
			case 'region':
				return esc_html( $region );
			case 'postal':
				return esc_html( $postal );
			case 'phone':
				if ( '' === $tel ) {
					return '';
				}
				return sprintf(
					'<a href="tel:%s">%s</a>',
					esc_attr( $tel ),
					esc_html( $phone )
				);
			case 'all':
			default:
				$phone_html = '';
				if ( '' !== $tel ) {
					$phone_html = sprintf(
						'<br><a href="tel:%s">%s</a>',
						esc_attr( $tel ),
						esc_html( $phone )
					);
				}
				return sprintf(
					'<address class="lafka-nap"><strong>%s</strong><br>%s%s</address>',
					esc_html( $name ),
					esc_html( $address ),
					$phone_html
				);
		}
	}
}
