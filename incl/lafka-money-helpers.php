<?php
/**
 * Money as plain text, in the store's WooCommerce currency settings.
 *
 * The one PHP formatter for prices that go into compact rows, accessible
 * names, messages and JSON. Anything printed as HTML keeps using wc_price();
 * scripts format through window.lafka.money (the same currency settings).
 *
 * @package Lafka\Plugin
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_price_plain' ) ) {
	/**
	 * A price as plain text in the store currency ("$19.45", "19,45 €"): the
	 * symbol, its position, the separators and the decimals come from
	 * WooCommerce → Settings → General. With $trim_whole a whole amount drops
	 * its zero decimals ("$10").
	 *
	 * Without WooCommerce there is no currency to name, so the amount is only
	 * formatted for the site's locale.
	 *
	 * @since 10.4.0
	 *
	 * @param float $amount     Amount (display price; tax is the caller's business).
	 * @param bool  $trim_whole Drop the zero decimals on a whole amount.
	 * @return string
	 */
	function lafka_price_plain( float $amount, bool $trim_whole = false ): string {
		// Whole at the store's own precision (a cent at 2 decimals, a mill at 3).
		$whole = abs( $amount - round( $amount ) ) < 0.5 * pow( 10, -( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 ) );

		if ( function_exists( 'wc_price' ) ) {
			$args = array();
			if ( $trim_whole && $whole ) {
				$args['decimals'] = 0;
			}
			return trim( html_entity_decode( wp_strip_all_tags( (string) wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' ) );
		}

		return number_format_i18n( $amount, $trim_whole && $whole ? 0 : 2 );
	}
}
