<?php
/**
 * P6-SEO-1: Restaurant + LocalBusiness + FoodEstablishment schema generator.
 *
 * Emitted on every page (sitewide). Provides the knowledge-panel signals
 * Google uses for local-intent rich results: restaurant type, opening hours,
 * price range, cuisine, address, geo, telephone, and citation sameAs links.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and return the Restaurant schema array.
 *
 * Returns null only if something critical is missing (e.g. no home_url),
 * which is never expected in a running WP install.
 *
 * @return array<string, mixed>|null
 */
function lafka_schema_restaurant(): ?array {
	$nap      = lafka_schema_get_nap();
	$home_url = trailingslashit( home_url( '/' ) );
	$logo_url = lafka_schema_get_logo_url();

	$schema = array(
		'@type'                      => array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' ),
		'@id'                        => $home_url . '#restaurant',
		'name'                       => $nap['name'],
		'url'                        => $home_url,
		'telephone'                  => $nap['telephone'],
		'priceRange'                 => '$$',
		'servesCuisine'              => array( 'Pizza', 'Donair', 'Poutine', 'Maritime Canadian' ),
		'address'                    => lafka_schema_get_postal_address(),
		'geo'                        => lafka_schema_get_geo(),
		'openingHoursSpecification'  => lafka_schema_get_opening_hours(),
		'acceptsReservations'        => false,
		'hasMenu'                    => trailingslashit( home_url( '/menu/' ) ),
		'paymentAccepted'            => 'Cash, Credit Card, Visa, Mastercard, Amex',
		'currenciesAccepted'         => 'CAD',
		'sameAs'                     => lafka_schema_get_same_as(),
	);

	if ( '' !== $logo_url ) {
		$schema['image'] = $logo_url;
	}

	/**
	 * Filter the Restaurant schema array before emission.
	 *
	 * @since 8.8.1
	 * @param array<string, mixed> $schema The assembled schema array.
	 */
	return (array) apply_filters( 'lafka_schema_restaurant', $schema );
}
