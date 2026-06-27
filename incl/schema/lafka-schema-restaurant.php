<?php
/**
 * P6-SEO-1 + W2-T1: Restaurant + LocalBusiness + FoodEstablishment schema generator.
 *
 * Emitted on every page (sitewide) when the operator has populated the basics
 * via Customizer (panel "Lafka — Restaurant Information"). Provides the
 * knowledge-panel signals Google uses for local-intent rich results: restaurant
 * type, opening hours, price range, cuisine, address, geo, telephone, sameAs.
 *
 * All field values come from `lafka_get_restaurant_info()`. Empty fields are
 * SKIPPED — never emitted as empty strings/arrays — so unconfigured installs
 * don't broadcast garbage to crawlers.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and return the Restaurant schema array.
 *
 * Returns null if essential identity fields are missing — schema emitter
 * MUST treat null as "skip emission entirely".
 *
 * @return array<string, mixed>|null
 */
function lafka_schema_restaurant(): ?array {
	$info     = lafka_get_restaurant_info();
	$nap      = lafka_schema_get_nap();
	$home_url = function_exists( 'home_url' ) && function_exists( 'trailingslashit' )
		? trailingslashit( home_url( '/' ) )
		: '/';
	$logo_url = lafka_schema_get_logo_url();

	$business_type = ! empty( $info['business_type'] ) && is_array( $info['business_type'] )
		? array_values( $info['business_type'] )
		: array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' );

	$schema = array(
		'@type' => $business_type,
		'@id'   => $home_url . '#restaurant',
		'url'   => $home_url,
	);

	// Identity (only emit non-empty).
	if ( '' !== $nap['name'] ) {
		$schema['name'] = $nap['name'];
	}
	if ( '' !== $nap['telephone'] ) {
		$schema['telephone'] = $nap['telephone'];
	}
	if ( ! empty( $info['email'] ) ) {
		$schema['email'] = (string) $info['email'];
	}
	if ( ! empty( $info['price_range'] ) ) {
		$schema['priceRange'] = (string) $info['price_range'];
	}
	if ( ! empty( $info['cuisines'] ) ) {
		$schema['servesCuisine'] = array_values( $info['cuisines'] );
	}

	// Address — skip when no address fields configured.
	$address = lafka_schema_get_postal_address();
	if ( null !== $address ) {
		$schema['address'] = $address;
	}

	// Geo — skip when lat/lng not both set.
	$geo = lafka_schema_get_geo();
	if ( null !== $geo ) {
		$schema['geo'] = $geo;
	}

	// Hours — skip when nothing configured.
	$hours = lafka_schema_get_opening_hours();
	if ( ! empty( $hours ) ) {
		$schema['openingHoursSpecification'] = $hours;
	}

	$schema['acceptsReservations'] = false;

	// Menu URL — only when configured.
	if ( ! empty( $info['menu_url'] ) ) {
		$schema['hasMenu'] = (string) $info['menu_url'];
	}

	// Payment methods — schema.org expects comma-separated string.
	if ( ! empty( $info['payment_methods'] ) ) {
		$schema['paymentAccepted'] = implode( ', ', array_values( $info['payment_methods'] ) );
	}

	// Currency — derive from WooCommerce when active, else skip.
	if ( function_exists( 'get_woocommerce_currency' ) ) {
		$currency = (string) get_woocommerce_currency();
		if ( '' !== $currency ) {
			$schema['currenciesAccepted'] = $currency;
		}
	}

	// sameAs — citation URLs (skip when empty).
	$same_as = lafka_schema_get_same_as();
	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	// areaServed — the locality the business serves. Honest default: its own
	// city/region (where it operates). Operators serving more areas can extend
	// via the `lafka_schema_area_served` filter. Skipped when no city is set.
	$area_served = array();
	if ( ! empty( $info['city'] ) ) {
		$area_served[] = array(
			'@type' => 'City',
			'name'  => (string) $info['city'] . ( ! empty( $info['region'] ) ? ', ' . (string) $info['region'] : '' ),
		);
	}
	if ( function_exists( 'apply_filters' ) ) {
		$area_served = (array) apply_filters( 'lafka_schema_area_served', $area_served, $info );
	}
	if ( ! empty( $area_served ) ) {
		$schema['areaServed'] = $area_served;
	}

	// aggregateRating — surface the same data the on-page social-proof
	// widget renders (4.8 / 1,200 reviews etc.). Pulled from the Customizer
	// theme_mods written by the Lafka theme's social-proof panel. Only
	// emitted when both rating and count are set; partial data confuses
	// Google's rich-result validator.
	if ( function_exists( 'get_theme_mod' ) ) {
		$rating_raw = (float) get_theme_mod( 'lafka_social_proof_rating', 0 );
		$count_raw  = (int) get_theme_mod( 'lafka_social_proof_count', 0 );
		if ( $rating_raw > 0 && $count_raw > 0 ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( $rating_raw, 1, '.', '' ),
				'reviewCount' => $count_raw,
				'bestRating'  => '5',
				'worstRating' => '1',
			);
		}
	}

	if ( '' !== $logo_url ) {
		$schema['image'] = $logo_url;
	}

	/**
	 * Filter the Restaurant schema array before emission.
	 *
	 * @since 8.8.1
	 * @param array<string, mixed> $schema The assembled schema array.
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$schema = (array) apply_filters( 'lafka_schema_restaurant', $schema );
	}
	return $schema;
}
