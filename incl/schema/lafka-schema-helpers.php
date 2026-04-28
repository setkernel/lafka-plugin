<?php
/**
 * P6-SEO-1/2/3/6: Shared helpers — canonical NAP, geo, hours, sameAs.
 *
 * Single source-of-truth for all business facts used by:
 *  - JSON-LD generators (this module)
 *  - [lafka_nap] shortcode (lafka-plugin.php delegates here via
 *    lafka_schema_get_nap())
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the canonical NAP array.
 *
 * All strings are byte-identical to those cited on GBP, Yelp, TripAdvisor,
 * and Yellow Pages. Do NOT change without updating every citation platform.
 *
 * @return array{
 *   name: string,
 *   street: string,
 *   city: string,
 *   region: string,
 *   postal: string,
 *   country: string,
 *   telephone: string,
 *   telephone_display: string,
 * }
 */
function lafka_schema_get_nap(): array {
	return array(
		'name'              => 'Peppery Pizza & Poutine',
		'street'            => '512 Sackville Drive',
		'city'              => 'Lower Sackville',
		'region'            => 'NS',
		'postal'            => 'B4C 2R8',
		'country'           => 'CA',
		'telephone'         => '+19022525353',
		'telephone_display' => '+1 902-252-5353',
	);
}

/**
 * Return GeoCoordinates for 512 Sackville Drive, Lower Sackville NS B4C 2R8.
 *
 * Source: Google Maps search for "512 Sackville Drive Lower Sackville NS" —
 * coordinates cross-checked via the street corridor centroid from
 * postalcodesincanada.com (Sackville Drive, B4C 2R8 block) and the spec
 * estimate. 4-decimal precision ≈ 10 m, sufficient for schema.org GeoCoordinates.
 * Approximate value: 44.7720°N, -63.6789°W (Lower Sackville commercial strip,
 * Sackville Drive at the 512 civic block).
 *
 * @return array{@type: string, latitude: float, longitude: float}
 */
function lafka_schema_get_geo(): array {
	return array(
		'@type'     => 'GeoCoordinates',
		'latitude'  => 44.7720,
		'longitude' => -63.6789,
	);
}

/**
 * Return the opening-hours specification array.
 *
 * Per W1 audit: Mon-Sun 11:00-23:00, same hours for delivery and takeout.
 * Uses OpeningHoursSpecification (schema.org/OpeningHoursSpecification) —
 * preferred over the deprecated openingHours string property.
 *
 * @return array<int, array{@type: string, dayOfWeek: list<string>, opens: string, closes: string}>
 */
function lafka_schema_get_opening_hours(): array {
	return array(
		array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => array(
				'https://schema.org/Monday',
				'https://schema.org/Tuesday',
				'https://schema.org/Wednesday',
				'https://schema.org/Thursday',
				'https://schema.org/Friday',
				'https://schema.org/Saturday',
				'https://schema.org/Sunday',
			),
			'opens'     => '11:00',
			'closes'    => '23:00',
		),
	);
}

/**
 * Return the sameAs array of authoritative citation URLs.
 *
 * Filterable so operators can add/remove platforms without touching core code:
 *   add_filter( 'lafka_schema_same_as', function( $urls ) { ... } );
 *
 * @return list<string>
 */
function lafka_schema_get_same_as(): array {
	$urls = array(
		'https://www.facebook.com/three.ppps/',
		'https://www.yelp.com/biz/peppery-pizza-and-poutine-lower-sackville',
		'https://www.yellowpages.ca/bus/Nova-Scotia/Lower-Sackville/Peppery-Pizza-and-Poutine/100178791.html',
		'https://www.tripadvisor.com/Restaurant_Review-g1863857-d25534291-Reviews-Peppery_Pizza_and_Poutine-Lower_Sackville_Halifax_Regional_Municipality_Nova_Sc.html',
		'https://restaurantguru.com/Peppery-Pizza-and-Poutine-Lower-Sackville',
	);

	/**
	 * Filter the sameAs citation URL list.
	 *
	 * @since 8.8.1
	 * @param list<string> $urls Canonical citation URLs.
	 */
	return (array) apply_filters( 'lafka_schema_same_as', $urls );
}

/**
 * Return the postal address array (schema.org/PostalAddress).
 *
 * @return array{@type: string, streetAddress: string, addressLocality: string, addressRegion: string, postalCode: string, addressCountry: string}
 */
function lafka_schema_get_postal_address(): array {
	$nap = lafka_schema_get_nap();
	return array(
		'@type'           => 'PostalAddress',
		'streetAddress'   => $nap['street'],
		'addressLocality' => $nap['city'],
		'addressRegion'   => $nap['region'],
		'postalCode'      => $nap['postal'],
		'addressCountry'  => $nap['country'],
	);
}

/**
 * Return the site logo URL, trying get_site_icon_url() first (1200px),
 * then falling back to an empty string (omit if unavailable).
 *
 * @return string
 */
function lafka_schema_get_logo_url(): string {
	if ( function_exists( 'get_site_icon_url' ) ) {
		$url = (string) get_site_icon_url( 1200 );
		if ( '' !== $url ) {
			return $url;
		}
	}
	return '';
}

/**
 * Build a single MenuItem schema array from a WC_Product.
 *
 * Used by lafka-schema-menu.php. Extracted here to keep that file ≤200 LOC.
 *
 * @param WC_Product $product
 * @return array<string, mixed>|null
 */
function lafka_schema_build_menu_item( WC_Product $product ): ?array {
	$item = array(
		'@type' => 'MenuItem',
		'name'  => $product->get_name(),
	);

	$short_desc = wp_strip_all_tags( $product->get_short_description() );
	if ( '' !== $short_desc ) {
		$item['description'] = $short_desc;
	} elseif ( '' !== $product->get_description() ) {
		$item['description'] = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 20, '...' );
	}

	$img_id = $product->get_image_id();
	if ( $img_id ) {
		$img_src = wp_get_attachment_image_url( (int) $img_id, 'woocommerce_single' );
		if ( $img_src ) {
			$item['image'] = $img_src;
		}
	}

	$offer = lafka_schema_build_offer_for_menu_item( $product );
	if ( null !== $offer ) {
		$item['offers'] = $offer;
	}

	return $item;
}

/**
 * Build an Offer (or AggregateOffer) for a MenuItem.
 *
 * Used by lafka-schema-menu.php. Extracted here to keep that file ≤200 LOC.
 *
 * @param WC_Product $product
 * @return array<string, mixed>|null
 */
function lafka_schema_build_offer_for_menu_item( WC_Product $product ): ?array {
	$avail = $product->is_in_stock()
		? 'https://schema.org/InStock'
		: 'https://schema.org/OutOfStock';

	if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
		/** @var WC_Product_Variable $product */
		$prices = $product->get_variation_prices( true );
		if ( ! empty( $prices['price'] ) ) {
			$low  = min( $prices['price'] );
			$high = max( $prices['price'] );
			if ( $low !== $high ) {
				return array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => number_format( (float) $low, 2, '.', '' ),
					'highPrice'     => number_format( (float) $high, 2, '.', '' ),
					'priceCurrency' => 'CAD',
					'availability'  => $avail,
				);
			}
			return array(
				'@type'         => 'Offer',
				'price'         => number_format( (float) $low, 2, '.', '' ),
				'priceCurrency' => 'CAD',
				'availability'  => $avail,
			);
		}
	}

	$price = $product->get_price();
	if ( '' === $price ) {
		return null;
	}

	return array(
		'@type'         => 'Offer',
		'price'         => number_format( (float) $price, 2, '.', '' ),
		'priceCurrency' => 'CAD',
		'availability'  => $avail,
	);
}
