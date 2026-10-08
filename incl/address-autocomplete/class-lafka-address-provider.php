<?php
/**
 * Lafka_Address_Provider: Lafka's entry in WooCommerce's address-autocomplete
 * provider list (WooCommerce → Settings → General → Address autocomplete).
 *
 * WooCommerce owns the suggestion list on both checkouts; this class only
 * names the provider and says who to credit. The browser half is
 * assets/js/lafka-address-autocomplete.js, the search itself is
 * Lafka_Address_Search.
 *
 * @package Lafka\Plugin\AddressAutocomplete
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Address_Provider' ) && class_exists( 'WC_Address_Provider' ) ) {

	/**
	 * The "lafka" address provider.
	 */
	final class Lafka_Address_Provider extends WC_Address_Provider {

		/**
		 * Name the provider and credit the service behind it.
		 */
		public function __construct() {
			$this->id   = Lafka_Address_Autocomplete::PROVIDER_ID;
			$this->name = __( 'Lafka address search', 'lafka-plugin' );

			// One span: the branding row is a flex box, which would drop the space before a bare link.
			$this->branding_html = '<span>' . (
				'google' === Lafka_Address_Search::backend()
					? esc_html__( 'Powered by Google', 'lafka-plugin' )
					: sprintf(
						/* translators: %s: link to the OpenStreetMap copyright page. */
						esc_html__( 'Search by Photon, data %s', 'lafka-plugin' ),
						'<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">&copy; OpenStreetMap</a>'
					)
			) . '</span>';
		}
	}
}
