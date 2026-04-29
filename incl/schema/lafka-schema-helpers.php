<?php
/**
 * P6-SEO-1/2/3/6 + W2-T1: Shared helpers — canonical NAP, geo, hours, sameAs.
 *
 * Single source-of-truth for all business facts used by:
 *  - JSON-LD generators (this module)
 *  - [lafka_nap] shortcode (lafka-plugin.php delegates here via
 *    lafka_schema_get_nap())
 *  - Editorial templates (lafka-child/page-templates/template-editorial-*.php
 *    + lafka-child/partials/editorial-*.php) read from
 *    lafka_get_restaurant_info() — the canonical resolver below.
 *
 * OSS-safety: this file ships in a public repo (github.com/setkernel/
 * lafka-plugin). It MUST NOT contain restaurant-specific literals (NAP,
 * geo, hours, citation URLs). All operator content flows through the
 * Customizer panel "Lafka — Restaurant Information" registered in
 * incl/customizer/class-lafka-customizer-restaurant-info.php.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_get_restaurant_info' ) ) {
	/**
	 * Canonical restaurant-info resolver. Single source of truth for NAP, geo,
	 * hours, cuisine, payment, social profiles, and brand identity.
	 *
	 * Resolution order per field:
	 *   1. Explicit theme_mod  (`lafka_business_<field>`) — operator sets via Customizer
	 *   2. Explicit option     (`lafka_business_<field>`)  — programmatic / migrations
	 *   3. WP-core fallback    (e.g. get_bloginfo('name') for name)
	 *   4. Empty               — schema generator will skip the field
	 *
	 * Returns an associative array with these keys:
	 *   - name (string)
	 *   - street, city, region, postal, country (strings)
	 *   - address_display (string, multi-line "street\ncity, region postal\ncountry")
	 *   - address_short   (string, single-line "street, city")
	 *   - phone_e164 (string, e.g. "+15551234567")
	 *   - phone_display (string, e.g. "+1 555-123-4567")
	 *   - email (string)
	 *   - geo_lat, geo_lng (string|null — null when unset; schema skips geo)
	 *   - hours (array<string, string>) — display map keyed by full day name
	 *           e.g. [ 'Monday' => '11:00-23:00', ... ]. Used by editorial templates.
	 *   - opening_hours (array of OpeningHoursSpecification objects, or empty array)
	 *           — used by JSON-LD schema. Empty array means "no hours configured".
	 *   - cuisines (array of strings)
	 *   - price_range (string, '$' to '$$$$')
	 *   - payment_methods (array of strings)
	 *   - business_type (array — schema.org @type values; default
	 *                    ['Restaurant','LocalBusiness','FoodEstablishment'])
	 *   - same_as (array of profile/citation URLs)
	 *   - logo_url (string)
	 *   - menu_url (string — link to menu archive)
	 *   - directions_url (string — Google Maps directions link, derived from address)
	 *
	 * Filterable as a whole via `lafka_restaurant_info` for child-theme/plugin override.
	 *
	 * Empty defaults are intentional — OSS-shipped code must not advertise any
	 * specific restaurant. Operator populates via Customizer panel
	 * "Restaurant Information" (registered in lafka-plugin/incl/customizer/).
	 *
	 * @return array<string, mixed>
	 */
	function lafka_get_restaurant_info(): array {
		// Helper: read theme_mod first, then option, then default. Empty string
		// is treated as "not set" so the next resolver step takes over.
		$get = function ( $key, $default = '' ) {
			if ( function_exists( 'get_theme_mod' ) ) {
				$theme_mod = get_theme_mod( 'lafka_business_' . $key, null );
				if ( null !== $theme_mod && '' !== $theme_mod ) {
					return $theme_mod;
				}
			}
			if ( function_exists( 'get_option' ) ) {
				$option = get_option( 'lafka_business_' . $key, null );
				if ( null !== $option && '' !== $option ) {
					return $option;
				}
			}
			return $default;
		};

		$name_default  = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$email_default = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'admin_email' ) : '';
		$logo_url      = '';
		if ( function_exists( 'get_site_icon_url' ) ) {
			$logo_url = (string) get_site_icon_url( 1200 );
		}
		$menu_url = '';
		if ( function_exists( 'home_url' ) && function_exists( 'trailingslashit' ) ) {
			$menu_url = trailingslashit( home_url( '/menu/' ) );
		}

		$info = array(
			'name'            => $get( 'name', $name_default ),
			'street'          => $get( 'street' ),
			'city'            => $get( 'city' ),
			'region'          => $get( 'region' ),
			'postal'          => $get( 'postal' ),
			'country'         => $get( 'country' ),
			'phone_e164'      => $get( 'phone_e164' ),
			'phone_display'   => $get( 'phone_display' ),
			'email'           => $get( 'email', $email_default ),
			'geo_lat'         => $get( 'geo_lat', null ),
			'geo_lng'         => $get( 'geo_lng', null ),
			'price_range'     => $get( 'price_range', '$$' ),
			'cuisines'        => array_values( array_filter( array_map( 'trim', explode( ',', (string) $get( 'cuisines' ) ) ) ) ),
			'payment_methods' => array_values( array_filter( array_map( 'trim', explode( ',', (string) $get( 'payment_methods' ) ) ) ) ),
			'business_type'   => array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' ),
			'same_as'         => array_values(
				array_filter(
					array_map( 'trim', explode( "\n", (string) $get( 'same_as' ) ) ),
					static function ( $url ) {
						return '' !== $url && false !== filter_var( $url, FILTER_VALIDATE_URL );
					}
				)
			),
			'logo_url'        => $logo_url,
			'menu_url'        => $menu_url,
		);

		// Phone display falls back to e164 (raw +15551234567) if no separate display set.
		if ( '' === $info['phone_display'] && '' !== $info['phone_e164'] ) {
			$info['phone_display'] = $info['phone_e164'];
		}

		// Build address_display + address_short (template-friendly composites).
		$line1 = $info['street'];
		$line2_parts = array_filter( array( $info['city'], trim( $info['region'] . ' ' . $info['postal'] ) ) );
		$line2 = implode( ', ', $line2_parts );
		$address_lines = array_filter( array( $line1, $line2, $info['country'] ) );
		$info['address_display'] = implode( "\n", $address_lines );
		$short_parts = array_filter( array( $info['street'], $info['city'] ) );
		$info['address_short']   = implode( ', ', $short_parts );

		// Hours: structured per-day. Read from theme_mod 'lafka_business_hours_<key>'
		// in "HH:MM-HH:MM" 24h format (or "closed"). Produces TWO shapes:
		//   - $info['hours']         display map ['Monday' => '11:00-23:00', ...]
		//   - $info['opening_hours'] OpeningHoursSpecification array for JSON-LD
		$info['hours']         = array();
		$info['opening_hours'] = array();
		$days = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		foreach ( $days as $key => $day_name ) {
			$val = trim( (string) $get( 'hours_' . $key ) );
			if ( '' === $val ) {
				continue;
			}
			if ( 'closed' === strtolower( $val ) ) {
				$info['hours'][ $day_name ] = 'Closed';
				continue;
			}
			if ( preg_match( '/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $val, $m ) ) {
				$info['hours'][ $day_name ]  = $m[1] . '-' . $m[2];
				$info['opening_hours'][]      = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => 'https://schema.org/' . $day_name,
					'opens'     => $m[1],
					'closes'    => $m[2],
				);
			}
		}

		// Directions URL — Google Maps query when address is configured.
		$info['directions_url'] = '';
		if ( '' !== $info['address_short'] ) {
			$query = $info['street'] . ', ' . $info['city'] . ', ' . $info['region'] . ' ' . $info['postal'];
			$info['directions_url'] = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( trim( $query ) );
		}

		/**
		 * Filter the resolved restaurant-info array.
		 *
		 * Use this as the topmost extension point — child themes / plugins can
		 * fully override the resolver output.
		 *
		 * @since 8.8.2
		 * @param array<string, mixed> $info Resolved restaurant info.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$info = (array) apply_filters( 'lafka_restaurant_info', $info );
		}
		return $info;
	}
}

/**
 * Return the canonical NAP array.
 *
 * Reads from `lafka_get_restaurant_info()` (the W2-T1 resolver). Filterable
 * via `lafka_schema_nap` for fine-grained schema-only override.
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
	$info = lafka_get_restaurant_info();
	$nap  = array(
		'name'              => (string) ( $info['name'] ?? '' ),
		'street'            => (string) ( $info['street'] ?? '' ),
		'city'              => (string) ( $info['city'] ?? '' ),
		'region'            => (string) ( $info['region'] ?? '' ),
		'postal'            => (string) ( $info['postal'] ?? '' ),
		'country'           => (string) ( $info['country'] ?? '' ),
		'telephone'         => (string) ( $info['phone_e164'] ?? '' ),
		'telephone_display' => (string) ( $info['phone_display'] ?? '' ),
	);

	/**
	 * Filter the schema NAP array.
	 *
	 * @since 8.8.2
	 * @param array<string, string> $nap NAP array.
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$nap = (array) apply_filters( 'lafka_schema_nap', $nap );
	}
	return $nap;
}

/**
 * Return GeoCoordinates schema array, or null when lat/lng aren't both set.
 *
 * Reads from `lafka_get_restaurant_info()`. Schema generator MUST skip
 * emission of the `geo` block when this returns null.
 *
 * @return array{@type: string, latitude: float, longitude: float}|null
 */
function lafka_schema_get_geo(): ?array {
	$info = lafka_get_restaurant_info();
	$lat  = $info['geo_lat'] ?? null;
	$lng  = $info['geo_lng'] ?? null;
	if ( null === $lat || null === $lng || '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		$geo = null;
	} else {
		$geo = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $lat,
			'longitude' => (float) $lng,
		);
	}

	/**
	 * Filter the schema geo block.
	 *
	 * @since 8.8.2
	 * @param array|null $geo GeoCoordinates schema array or null when unconfigured.
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$geo = apply_filters( 'lafka_schema_geo', $geo );
	}
	return is_array( $geo ) ? $geo : null;
}

/**
 * Return the opening-hours specification array (one block per configured day).
 *
 * Empty array means "no hours configured" — schema generator should skip
 * emission of the `openingHoursSpecification` field.
 *
 * @return array<int, array<string, mixed>>
 */
function lafka_schema_get_opening_hours(): array {
	$info  = lafka_get_restaurant_info();
	$hours = isset( $info['opening_hours'] ) && is_array( $info['opening_hours'] ) ? $info['opening_hours'] : array();

	/**
	 * Filter the openingHoursSpecification array.
	 *
	 * @since 8.8.2
	 * @param array $hours OpeningHoursSpecification array (may be empty).
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$hours = (array) apply_filters( 'lafka_schema_opening_hours', $hours );
	}
	return $hours;
}

/**
 * Return the sameAs array of authoritative citation URLs.
 *
 * Reads from `lafka_get_restaurant_info()['same_as']`. The existing
 * `lafka_schema_same_as` filter remains the public extension point.
 *
 * @return list<string>
 */
function lafka_schema_get_same_as(): array {
	$info = lafka_get_restaurant_info();
	$urls = isset( $info['same_as'] ) && is_array( $info['same_as'] ) ? array_values( $info['same_as'] ) : array();

	/**
	 * Filter the sameAs citation URL list.
	 *
	 * @since 8.8.1
	 * @param list<string> $urls Citation URLs.
	 */
	if ( function_exists( 'apply_filters' ) ) {
		$urls = (array) apply_filters( 'lafka_schema_same_as', $urls );
	}
	return array_values( $urls );
}

/**
 * Return the postal address array (schema.org/PostalAddress) — or null when
 * no address fields are configured.
 *
 * @return array{@type: string, streetAddress: string, addressLocality: string, addressRegion: string, postalCode: string, addressCountry: string}|null
 */
function lafka_schema_get_postal_address(): ?array {
	$nap = lafka_schema_get_nap();
	if ( '' === $nap['street'] && '' === $nap['city'] && '' === $nap['postal'] ) {
		return null;
	}
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
 * Used by lafka-schema-menu.php. Extracted here to keep that file <=200 LOC.
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
 * Used by lafka-schema-menu.php. Extracted here to keep that file <=200 LOC.
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
