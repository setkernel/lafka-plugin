<?php
/**
 * P6-UX-7: pricing presentation consistency.
 *
 * - Strip "Price range:" prefix from variable product prices
 * - Replace "through" / "&ndash;" / "&mdash;" with a single en-dash
 * - Strip <sup> tags from cents (no superscript theatrics)
 * - Keep WC's own sale-price strike + new-price markup intact
 *
 * Priority 99 runs after the theme's formatted_woocommerce_price filter (prio 10)
 * that injects <sup> around the decimal portion.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_normalize_price_html' ) ) {
	add_filter( 'woocommerce_get_price_html', 'lafka_normalize_price_html', 99, 2 );
	function lafka_normalize_price_html( $html, $product ) {
		if ( ! $html ) {
			return $html;
		}
		// Strip <sup> wrappers (theme-injected superscript on cents)
		$html = preg_replace( '#<sup[^>]*>(.*?)</sup>#s', '$1', $html );

		// Variable product range — WC core renders as "<span>...</span>&ndash;<span>...</span>"
		// OR with a "through" word if a translation override is in place. Normalize.
		$html = str_replace(
			array( ' through ', ' to ', '&mdash;', '&ndash;' ),
			' – ', // EN DASH (U+2013) for consistency
			$html
		);

		// Strip "Price range:" prefix (some themes/plugins prepend this)
		$html = preg_replace( '#(Price\s+range:\s*)#i', '', $html );

		return $html;
	}
}
