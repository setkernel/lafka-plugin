<?php
/**
 * P6-SEO-1/2/3/6 + W2-T1: Shared helpers — canonical NAP, geo, hours, sameAs.
 *
 * Single source-of-truth for all business facts used by:
 *  - JSON-LD generators (this module)
 *  - [lafka_nap] shortcode (incl/schema/lafka-nap-shortcode.php, via
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

require_once __DIR__ . '/lafka-phone-format.php';

if ( ! function_exists( 'lafka_get_menu_url' ) ) {
	/**
	 * Canonical "browse the menu" URL — single source of truth for every
	 * Order / Browse / Start-your-order CTA AND for the "Menu" crumb + hasMenu
	 * link emitted in JSON-LD.
	 *
	 * CANONICAL BROWSE TARGET (decision, f104): the menu lives at the /menu/
	 * custom WP page, NOT the WooCommerce shop archive. Those are two distinct
	 * URLs — the /menu/ page is the operator-facing browse experience that the
	 * handoff layout renders (lafka-theme/page-menu.php), while the shop archive
	 * (archive-product.php) only fires on the shop + product taxonomy URLs. All
	 * "browse/order" CTAs therefore resolve here, to /menu/, rather than to
	 * wc_get_page_permalink( 'shop' ).
	 *
	 * Routed through the long-standing `lafka_header_cta_url` filter so an
	 * operator who repoints the header "Order now" button (a custom page slug,
	 * or an external ordering platform) moves every menu CTA — and the
	 * structured-data Menu links — in lockstep: the visible CTAs and the JSON-LD
	 * can never diverge (the inconsistency this resolver exists to kill).
	 *
	 * Returns '' when home_url() is unavailable (e.g. running outside a booted
	 * WP); callers that emit a link already gate on a non-empty value, and the
	 * schema generators skip the field when it is empty.
	 *
	 * @since 9.34.0
	 *
	 * @return string Absolute, trailing-slashed menu URL, or '' when unresolvable.
	 */
	function lafka_get_menu_url(): string {
		$menu_url = '';
		if ( function_exists( 'home_url' ) && function_exists( 'trailingslashit' ) ) {
			$menu_url = trailingslashit( home_url( '/menu/' ) );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$menu_url = (string) apply_filters( 'lafka_header_cta_url', $menu_url );
		}
		return (string) $menu_url;
	}
}

if ( ! function_exists( 'lafka_get_restaurant_info' ) ) {
	/**
	 * Canonical restaurant-info resolver. Single source of truth for NAP, geo,
	 * hours, cuisine, payment, social profiles, and brand identity.
	 *
	 * Resolution order per field:
	 *   1. Option (`lafka_business_<field>`) — the single store, written by
	 *      WooCommerce → Settings → Restaurant AND the Customizer panel
	 *      (GX3: legacy theme_mods are migrated in once, never read here)
	 *   2. WooCommerce store option (address / city / postcode / country / phone)
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
		// WooCommerce already stores the canonical NAP for the shop. Pull defaults
		// from there so operators don't enter address/phone twice. The Lafka
		// Customizer fields then act as overrides — useful when the site is multi-
		// location (one WC store, many physical addresses) or when the schema
		// branding differs from the WC checkout/email branding.
		$wc_country_split = static function (): array {
			if ( ! function_exists( 'get_option' ) ) {
				return array( '', '' );
			}
			$raw = (string) get_option( 'woocommerce_default_country', '' );
			if ( '' === $raw ) {
				return array( '', '' );
			}
			// WC stores "CA:ON" for country with state, or just "CA" without.
			$parts   = explode( ':', $raw, 2 );
			$country = isset( $parts[0] ) ? (string) $parts[0] : '';
			$region  = isset( $parts[1] ) ? (string) $parts[1] : '';
			return array( $country, $region );
		};
		[ $wc_country, $wc_region ] = $wc_country_split();

		// Map of Lafka resolver keys → equivalent WC store option (or computed
		// fallback). Keys not in this map fall through to the final default.
		$wc_fallbacks = array(
			'street'        => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_store_address', '' ) : '',
			'city'          => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_store_city', '' ) : '',
			'postal'        => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_store_postcode', '' ) : '',
			'country'       => $wc_country,
			'region'        => $wc_region,
			// Phone: WC core didn't ship `woocommerce_store_phone` until late
			// versions; reading the option returns '' on older installs which
			// is fine — the resolver then falls through to the default.
			'phone_e164'    => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_store_phone', '' ) : '',
			'phone_display' => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_store_phone', '' ) : '',
		);

		// Helper: wp_options → WC store fallback → default.
		//
		// GX3 (single NAP store): wp_options `lafka_business_*` is the ONE
		// store. Both write surfaces — the WooCommerce → Settings →
		// Restaurant tab and the Customizer "Restaurant Information" panel
		// (whose settings are now `type => option`) — write it. Legacy
		// Customizer theme_mods are copied in once by
		// lafka_nap_migrate_theme_mods() (incl/schema/lafka-nap-migration.php)
		// where the option is empty, and are never read here again: two
		// stores with "options win" silently shadowed operator edits (a
		// phone typed in the Customizer never reached the site).
		//
		// Empty strings — and the literal "Array" a pre-9.11 cast bug stored
		// for list fields — are treated as "not set" so the next resolver
		// step takes over. WC store options (woocommerce_store_address etc.)
		// remain the fallback for shared NAP fields.
		$get = function ( $key, $default = '' ) use ( $wc_fallbacks ) {
			if ( function_exists( 'get_option' ) ) {
				$option = get_option( 'lafka_business_' . $key, null );
				if ( lafka_schema_is_set_value( $option ) ) {
					return $option;
				}
			}
			if ( isset( $wc_fallbacks[ $key ] ) && '' !== $wc_fallbacks[ $key ] ) {
				return $wc_fallbacks[ $key ];
			}
			return $default;
		};

		// Decoded: get_bloginfo() is display-filtered ("&amp;"), and this name
		// feeds JSON-LD / llms.txt (plain text) as well as escaped HTML.
		$name_default  = function_exists( 'get_bloginfo' ) ? html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		$email_default = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'admin_email' ) : '';
		$logo_url      = '';
		if ( function_exists( 'get_site_icon_url' ) ) {
			$logo_url = (string) get_site_icon_url( 1200 );
		}
		// Canonical menu URL — the SAME resolver every visible "Order now" /
		// "Browse the menu" CTA uses, so hasMenu (schema-restaurant.php) can
		// never point somewhere the on-page buttons don't.
		$menu_url = lafka_get_menu_url();

		$map_url = trim( (string) $get( 'map_url' ) );
		if ( '' !== $map_url && false === filter_var( $map_url, FILTER_VALIDATE_URL ) ) {
			$map_url = '';
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
			// v9.11.1: handle both string (operator-typed CSV) and array
			// (some plugins / filters return arrays from option storage)
			// inputs. Without this, `(string) $array` produced the literal
			// "Array" inside servesCuisine — bad SEO signal to Google.
			'cuisines'        => lafka_schema_normalize_csv_list( $get( 'cuisines' ) ),
			'payment_methods' => lafka_schema_normalize_csv_list( $get( 'payment_methods' ) ),
			// v9.x: business_type is an editable control in BOTH the Customizer
			// Restaurant-Information panel and the WooCommerce Restaurant Settings
			// tab (stored as a CSV string of schema.org subtypes). Previously this
			// was a hardcoded literal, so operator input from either surface was
			// silently discarded and a non-restaurant could never correct its
			// JSON-LD @type. Resolve the stored value via the shared CSV parser;
			// fall back to the Restaurant default only when nothing is stored
			// (normalize returns an empty — falsy — array for unset/empty input).
			'business_type'   => lafka_schema_normalize_csv_list( $get( 'business_type' ) ) ?: array( 'Restaurant', 'LocalBusiness', 'FoodEstablishment' ),
			'same_as'         => array_values(
				array_filter(
					lafka_schema_normalize_line_list( $get( 'same_as' ) ),
					static function ( $url ) {
						return false !== filter_var( $url, FILTER_VALIDATE_URL );
					}
				)
			),
			'logo_url'        => $logo_url,
			'menu_url'        => $menu_url,
			// GX3: free-text restaurant description (llms.txt summary + the
			// meta-description fallback), Google Maps / Business Profile URL
			// (schema hasMap), and the operator's service-area list (one
			// place per line → schema areaServed + llms.txt).
			'description'     => trim( (string) $get( 'description' ) ),
			'map_url'         => $map_url,
			'service_areas'   => lafka_schema_normalize_line_list( $get( 'service_areas' ) ),
		);

		// Phone fallbacks — handle both directions so operators only need to fill one field.
		//
		// Forward: with no separate display value — or one stored as a bare
		// number (a raw "+15551234567" reads like a code, not a phone) — show
		// the national format. tel: links keep phone_e164.
		if ( '' !== $info['phone_e164'] && ( '' === $info['phone_display'] || lafka_phone_is_bare_number( (string) $info['phone_display'] ) ) ) {
			$info['phone_display'] = lafka_format_phone_display(
				'' !== $info['phone_display'] ? (string) $info['phone_display'] : (string) $info['phone_e164'],
				isset( $wc_country ) ? (string) $wc_country : ''
			);
		}
		// Reverse (v9.22.3): when e164 is blank but display has digits, derive an E.164
		// by stripping all non-digit characters and prepending "+". Without this, the
		// tap-to-call tel: link silently fell back to woocommerce_store_phone — so an
		// operator updating "Phone (display)" in WC Restaurant Settings got the visible
		// text changed but the tel link kept calling the old WC store number.
		if ( '' === $info['phone_e164'] && '' !== $info['phone_display'] ) {
			$digits = preg_replace( '/[^0-9]/', '', (string) $info['phone_display'] );
			if ( '' !== $digits ) {
				// If the operator typed digits only (e.g. "9024042888") and the WC store
				// country is CA or US, prepend "+1" so the link is a valid E.164.
				// Otherwise just prepend "+" — caller is responsible for country code.
				$country = isset( $wc_country ) ? strtoupper( (string) $wc_country ) : '';
				if ( strlen( $digits ) === 10 && in_array( $country, array( 'CA', 'US' ), true ) ) {
					$digits = '1' . $digits;
				}
				$info['phone_e164'] = '+' . $digits;
			}
		}

		// Normalize cuisines/payment_methods: cast to string array regardless
		// of input shape. See `lafka_schema_normalize_csv_list()` below.

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

		// SSOT reconciliation: restaurant hours are otherwise captured twice —
		// the dedicated display store above (lafka_business_hours_*) drives this
		// badge + JSON-LD, while Lafka_Order_Hours' own JSON schedule gates
		// whether an order is actually accepted. Nothing keeps the two in sync,
		// so an operator who fills in only the order-hours schedule gets a
		// storefront that emits NO hours (badge hidden / schema omitted) while
		// ordering is gated, and one who edits only one of two populated stores
		// can show "Open now" (telling Google the store is open) while ordering
		// is blocked, or the reverse.
		//
		// When the display store is unset we therefore derive hours from the
		// SAME schedule the order gate reads, so badge + schema + gate all read
		// one store. An explicitly populated display store still wins (the
		// emptiness check below), preserving any deliberate operator override.
		// Scoped to the main-store schedule; per-branch order-gate overrides
		// remain authoritative for the gate only (the display store is
		// single-location). See Lafka_Order_Hours::get_schedule_display_hours_map().
		if ( empty( $info['hours'] ) && class_exists( 'Lafka_Order_Hours' ) ) {
			$schedule_map = Lafka_Order_Hours::get_schedule_display_hours_map();
			foreach ( $schedule_map as $day_name => $range ) {
				if ( 'Closed' === $range ) {
					$info['hours'][ $day_name ] = 'Closed';
					continue;
				}
				if ( preg_match( '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $range, $m ) ) {
					$info['hours'][ $day_name ] = $m[1] . '-' . $m[2];
					$info['opening_hours'][]    = array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => 'https://schema.org/' . $day_name,
						'opens'     => $m[1],
						'closes'    => $m[2],
					);
				}
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

if ( ! function_exists( 'lafka_schema_get_brand_logo_url' ) ) {
	/**
	 * GX3: the brand logo for Restaurant.logo — the theme's Custom Logo
	 * (Customizer → Site Identity) when set, else the site icon.
	 *
	 * @return string Absolute URL or ''.
	 */
	function lafka_schema_get_brand_logo_url(): string {
		$url = '';
		if ( function_exists( 'get_theme_mod' ) && function_exists( 'wp_get_attachment_image_url' ) ) {
			$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
			if ( $logo_id > 0 ) {
				$url = (string) wp_get_attachment_image_url( $logo_id, 'full' );
			}
		}
		if ( '' === $url ) {
			$url = lafka_schema_get_logo_url();
		}
		if ( function_exists( 'apply_filters' ) ) {
			$url = (string) apply_filters( 'lafka_schema_logo_url', $url );
		}
		return $url;
	}
}

if ( ! function_exists( 'lafka_schema_get_restaurant_images' ) ) {
	/**
	 * GX3: photos for Restaurant.image — the operator's default share image
	 * and the homepage hero (both real, operator-chosen photos), falling back
	 * to the brand logo so the node always carries an image when one exists.
	 *
	 * @return list<string> Absolute, de-duplicated URLs.
	 */
	function lafka_schema_get_restaurant_images(): array {
		$images = array();

		$og_default = function_exists( 'get_theme_mod' ) ? get_theme_mod( 'lafka_og_image_default', '' ) : '';
		if ( is_numeric( $og_default ) && (int) $og_default > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$images[] = (string) wp_get_attachment_image_url( (int) $og_default, 'large' );
		} elseif ( is_string( $og_default ) && false !== filter_var( $og_default, FILTER_VALIDATE_URL ) ) {
			$images[] = $og_default;
		}

		if ( function_exists( 'lafka_lcp_hero' ) ) {
			$hero = lafka_lcp_hero();
			if ( ! empty( $hero['url'] ) ) {
				$images[] = (string) $hero['url'];
			}
		}

		$images = array_values( array_unique( array_filter( $images ) ) );
		if ( empty( $images ) ) {
			$logo = lafka_schema_get_brand_logo_url();
			if ( '' !== $logo ) {
				$images[] = $logo;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$images = (array) apply_filters( 'lafka_schema_restaurant_images', $images );
		}
		return array_values( array_filter( array_map( 'strval', $images ) ) );
	}
}

if ( ! function_exists( 'lafka_schema_is_set_value' ) ) {
	/**
	 * Whether a stored NAP value counts as "set": not null, not '', not an
	 * empty array, and not the literal "Array" a pre-9.11 cast bug persisted
	 * for list fields (cuisines / payment methods / sameAs).
	 *
	 * @param mixed $value Raw stored value.
	 * @return bool
	 */
	function lafka_schema_is_set_value( $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		$value = trim( (string) $value );
		return '' !== $value && 0 !== strcasecmp( $value, 'Array' );
	}
}

if ( ! function_exists( 'lafka_schema_normalize_line_list' ) ) {
	/**
	 * Normalise a one-entry-per-line textarea value (or an array) into a clean
	 * list of trimmed, non-empty, unique strings, dropping the "Array" sentinel.
	 *
	 * @param mixed $value Raw stored value.
	 * @return list<string>
	 */
	function lafka_schema_normalize_line_list( $value ): array {
		if ( is_array( $value ) ) {
			$items = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $value );
		} elseif ( is_scalar( $value ) ) {
			$items = preg_split( '/\r\n|\r|\n/', (string) $value );
		} else {
			return array();
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item || 0 === strcasecmp( $item, 'Array' ) || in_array( $item, $out, true ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}
}

/**
 * Return the currency code to emit in JSON-LD Offer/AggregateOffer blocks.
 *
 * Reads `get_woocommerce_currency()` when WC is active. Falls back to USD
 * (the schema.org docs default) when the helper isn't available — the only
 * realistic case is a code path running before WC has booted, in which case
 * the calling generator is wrong to be running at all.
 *
 * Filterable via `lafka_schema_price_currency` so an operator running a
 * multi-currency setup can scope per-product rather than per-store.
 *
 * @since 9.7.3
 *
 * @return string ISO-4217 currency code, e.g. 'CAD', 'USD', 'EUR'.
 */
function lafka_schema_get_price_currency(): string {
	$currency = function_exists( 'get_woocommerce_currency' )
		? (string) get_woocommerce_currency()
		: 'USD';
	if ( '' === $currency ) {
		$currency = 'USD';
	}

	if ( function_exists( 'apply_filters' ) ) {
		$currency = (string) apply_filters( 'lafka_schema_price_currency', $currency );
	}
	return $currency;
}

if ( ! function_exists( 'lafka_schema_diet_map' ) ) {
	/**
	 * Map of product_tag / product_cat slug → schema.org RestrictedDiet URL.
	 *
	 * Defaults cover the conventional slugs (the dietary-tag seeder creates
	 * `vegan` and `vegetarian`); the operator adds or overrides lines under
	 * WooCommerce → Settings → Restaurant → Search & AI ("slug = Diet", e.g.
	 * `plant-based = VeganDiet`), and `lafka_schema_diet_map` has the last
	 * word. A diet is only ever claimed for items the operator tagged.
	 *
	 * @return array<string,string> slug => https://schema.org/<Diet>
	 */
	function lafka_schema_diet_map(): array {
		$map = array(
			'vegan'        => 'VeganDiet',
			'vegetarian'   => 'VegetarianDiet',
			'gluten-free'  => 'GlutenFreeDiet',
			'glutenfree'   => 'GlutenFreeDiet',
			'halal'        => 'HalalDiet',
			'kosher'       => 'KosherDiet',
			'lactose-free' => 'LowLactoseDiet',
			'dairy-free'   => 'LowLactoseDiet',
			'low-fat'      => 'LowFatDiet',
			'low-salt'     => 'LowSaltDiet',
			'low-calorie'  => 'LowCalorieDiet',
			'diabetic'     => 'DiabeticDiet',
			'hindu'        => 'HinduDiet',
		);

		$raw = function_exists( 'lafka_seo_get' ) ? lafka_seo_get( 'lafka_seo_diet_map' ) : '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( ! preg_match( '/^\s*([a-z0-9_-]+)\s*[:=]\s*([A-Za-z]+)\s*$/', (string) $line, $m ) ) {
				continue;
			}
			$map[ strtolower( $m[1] ) ] = $m[2];
		}

		$out = array();
		foreach ( $map as $slug => $diet ) {
			$diet = (string) preg_replace( '#^https?://schema\.org/#', '', (string) $diet );
			if ( '' === $diet ) {
				continue;
			}
			$out[ (string) $slug ] = 'https://schema.org/' . ( str_ends_with( $diet, 'Diet' ) ? $diet : $diet . 'Diet' );
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the slug → schema.org RestrictedDiet map.
			 *
			 * @since 10.2.0
			 * @param array<string,string> $out slug => diet URL.
			 */
			$out = (array) apply_filters( 'lafka_schema_diet_map', $out );
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_schema_product_diets' ) ) {
	/**
	 * schema.org diets a product is tagged/categorised for.
	 *
	 * @param WC_Product $product Product.
	 * @return list<string> Diet URLs (unique, map order).
	 */
	function lafka_schema_product_diets( WC_Product $product ): array {
		$map = lafka_schema_diet_map();
		if ( empty( $map ) || ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}
		$slugs = wp_get_post_terms( $product->get_id(), array( 'product_tag', 'product_cat' ), array( 'fields' => 'slugs' ) );
		if ( ! is_array( $slugs ) ) {
			return array();
		}
		$slugs = array_map( 'strtolower', array_map( 'strval', $slugs ) );
		$diets = array();
		foreach ( $map as $slug => $diet ) {
			if ( in_array( (string) $slug, $slugs, true ) && ! in_array( $diet, $diets, true ) ) {
				$diets[] = $diet;
			}
		}
		return $diets;
	}
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

	// GX3: link every MenuItem to its product page, so answer engines can
	// cite the orderable URL and a MenuItem listed in two sections resolves
	// to one entity.
	$url = function_exists( 'get_permalink' ) ? (string) get_permalink( $product->get_id() ) : '';
	if ( '' !== $url ) {
		$item['@id'] = $url . '#menuitem';
		$item['url'] = $url;
	}

	$diets = lafka_schema_product_diets( $product );
	if ( ! empty( $diets ) ) {
		$item['suitableForDiet'] = 1 === count( $diets ) ? $diets[0] : $diets;
	}

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
					'priceCurrency' => lafka_schema_get_price_currency(),
					'availability'  => $avail,
				);
			}
			return array(
				'@type'         => 'Offer',
				'price'         => number_format( (float) $low, 2, '.', '' ),
				'priceCurrency' => lafka_schema_get_price_currency(),
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
		'priceCurrency' => lafka_schema_get_price_currency(),
		'availability'  => $avail,
	);
}

if ( ! function_exists( 'lafka_schema_normalize_csv_list' ) ) {
	/**
	 * Normalize a comma-separated list field that may have been stored as
	 * a string OR as an array (depending on Customizer sanitization or
	 * filter chain). Returns a clean string[] of trimmed, non-empty entries.
	 *
	 * Without this, casting a stored array to (string) yielded the literal
	 * "Array" inside servesCuisine / paymentAccepted, a bad SEO signal.
	 *
	 * Also strips the literal "Array" sentinel (case-insensitive) from
	 * output. That value only appears in a stored option when a previous
	 * code path cast a PHP array to string before saving — it's never a
	 * legitimate cuisine or payment-method label.
	 *
	 * @param mixed $value Raw option / theme-mod value.
	 * @return array<int, string>
	 */
	function lafka_schema_normalize_csv_list( $value ) {
		if ( is_array( $value ) ) {
			$items = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $value );
		} elseif ( is_scalar( $value ) ) {
			$items = explode( ',', (string) $value );
		} else {
			return array();
		}
		$items = array_map( 'trim', $items );
		return array_values(
            array_filter(
                $items,
                static function ( $v ) {
					return '' !== $v && 0 !== strcasecmp( $v, 'Array' );
				} 
            ) 
        );
	}
}
