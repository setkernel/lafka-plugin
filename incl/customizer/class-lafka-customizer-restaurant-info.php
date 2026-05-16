<?php
/**
 * W2-T1: Customizer panel "Lafka — Restaurant Information".
 *
 * Single source of operator-configurable NAP, geo, hours, cuisine, payment,
 * and citation URLs. All settings are read by `lafka_get_restaurant_info()`
 * (lafka-plugin/incl/schema/lafka-schema-helpers.php) — the W2-T1 resolver
 * — and consumed by:
 *   - JSON-LD schema generators (incl/schema/lafka-schema-*.php)
 *   - [lafka_nap] shortcode (lafka-plugin.php)
 *   - Editorial templates (lafka-child/page-templates/template-editorial-*.php
 *     + partials/editorial-*.php)
 *
 * All settings prefixed `lafka_business_*`. All transport: refresh.
 *
 * @package Lafka\Plugin\Customizer
 * @since   8.8.2
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Restaurant_Info' ) ) {

	/**
	 * Registers the "Lafka — Restaurant Information" Customizer panel.
	 */
	final class Lafka_Customizer_Restaurant_Info {

		/**
		 * Hook into customize_register at default priority.
		 */
		public static function init(): void {
			add_action( 'customize_register', array( __CLASS__, 'register' ) );
		}

		/**
		 * Register panel, sections, settings, and controls.
		 *
		 * @param WP_Customize_Manager $wp_customize
		 */
		public static function register( $wp_customize ): void {

			$wp_customize->add_panel(
                'lafka_restaurant_info',
                array(
					'title'       => esc_html__( 'Lafka — Restaurant Information', 'lafka-plugin' ),
					'description' => esc_html__( 'Restaurant-specific extras for JSON-LD schema, the [lafka_nap] shortcode, and editorial templates: opening hours, cuisine, geo coordinates, social/citation URLs, and price range. Address, phone, and country are read from WooCommerce → Settings → General by default — only fill them in here if you want to override the WC values for schema/branding (e.g. multi-location).', 'lafka-plugin' ),
					// Priority 32 groups this with the other Lafka panels
					// at the top of the Customizer sidebar (Announce Bar 28,
					// Site Settings 30, Home Page 35) — pure positioning,
					// not a visibility hack.
					'priority'    => 32,
                )
            );

			self::register_identity_section( $wp_customize );
			self::register_location_section( $wp_customize );
			self::register_contact_section( $wp_customize );
			self::register_hours_section( $wp_customize );
			self::register_cuisine_payment_section( $wp_customize );
			self::register_same_as_section( $wp_customize );
			self::register_homepage_hero_section( $wp_customize );
		}

		/**
		 * Helper: register a simple text setting + control under a section.
		 *
		 * @param WP_Customize_Manager $wp_customize
		 * @param string               $id
		 * @param string               $section
		 * @param string               $label
		 * @param string               $default
		 * @param string               $description
		 * @param string               $type     'text' | 'textarea' | 'email'
		 * @param callable|string|null $sanitize Custom sanitizer or null for default.
		 */
		private static function add_text( $wp_customize, $id, $section, $label, $default = '', $description = '', $type = 'text', $sanitize = null ): void {
			if ( null === $sanitize ) {
				$sanitize = ( 'textarea' === $type )
					? 'sanitize_textarea_field'
					: ( 'email' === $type ? 'sanitize_email' : 'sanitize_text_field' );
			}
			$wp_customize->add_setting(
                $id,
                array(
					'default'           => $default,
					'transport'         => 'refresh',
					'sanitize_callback' => $sanitize,
                ) 
            );
			$wp_customize->add_control(
                $id,
                array(
					'label'       => $label,
					'description' => $description,
					'section'     => $section,
					'type'        => $type,
                ) 
            );
		}

		/**
		 * Sanitize a numeric latitude/longitude string. Returns '' for invalid.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_geo( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			$f = (float) $value;
			if ( $f < -180.0 || $f > 180.0 ) {
				return '';
			}
			return (string) $f;
		}

		/**
		 * Sanitize a price-range string ($, $$, $$$, $$$$).
		 */
		public static function sanitize_price_range( $value ): string {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			$allowed = array( '$', '$$', '$$$', '$$$$' );
			return in_array( $value, $allowed, true ) ? $value : '$$';
		}

		/**
		 * Sanitize a textarea of newline-separated URLs. Invalid lines silently dropped.
		 */
		public static function sanitize_url_list( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
			$out   = array();
			foreach ( (array) $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( false !== filter_var( $line, FILTER_VALIDATE_URL ) ) {
					$out[] = esc_url_raw( $line );
				}
			}
			return implode( "\n", $out );
		}

		/**
		 * Sanitize an opening-hours value: "HH:MM-HH:MM" (24h) or "closed" or empty.
		 */
		public static function sanitize_hours( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( 'closed' === strtolower( $value ) ) {
				return 'closed';
			}
			if ( preg_match( '/^(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})$/', $value, $m ) ) {
				$oh = (int) $m[1];
				$om = (int) $m[2];
				$ch = (int) $m[3];
				$cm = (int) $m[4];
				if ( $oh > 23 || $ch > 23 || $om > 59 || $cm > 59 ) {
					return '';
				}
				return sprintf( '%02d:%02d-%02d:%02d', $oh, $om, $ch, $cm );
			}
			return '';
		}

		/**
		 * Sanitize comma-separated business-type list (schema.org subtypes).
		 */
		public static function sanitize_business_type( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$parts = array_map( 'trim', explode( ',', (string) $value ) );
			$out   = array();
			foreach ( $parts as $p ) {
				if ( '' === $p ) {
					continue;
				}
				// Schema.org type names are PascalCase ASCII; strip anything else.
				$clean = preg_replace( '/[^A-Za-z0-9]/', '', $p );
				if ( '' !== $clean ) {
					$out[] = $clean;
				}
			}
			return implode( ', ', $out );
		}

		// ====================================================================
		// Section: Identity
		// ====================================================================

		private static function register_identity_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_identity',
                array(
					'title'    => esc_html__( 'Identity', 'lafka-plugin' ),
					'panel'    => 'lafka_restaurant_info',
					'priority' => 10,
                ) 
            );

			self::add_text(
                $wp_customize,
                'lafka_business_name',
				'lafka_business_identity',
				esc_html__( 'Restaurant name', 'lafka-plugin' ),
				'',
				esc_html__( 'Defaults to the WordPress site title when empty.', 'lafka-plugin' )
			);

			$wp_customize->add_setting(
                'lafka_business_business_type',
                array(
					'default'           => 'Restaurant, LocalBusiness, FoodEstablishment',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_business_type' ),
                ) 
            );
			$wp_customize->add_control(
                'lafka_business_business_type',
                array(
					'label'       => esc_html__( 'Schema.org business types (comma-separated)', 'lafka-plugin' ),
					'description' => esc_html__( 'Examples: Restaurant, CafeOrCoffeeShop, BarOrPub, Bakery, FastFoodRestaurant. Multi-typed JSON-LD lets Google match more local-intent queries.', 'lafka-plugin' ),
					'section'     => 'lafka_business_identity',
					'type'        => 'text',
                ) 
            );

			$wp_customize->add_setting(
                'lafka_business_price_range',
                array(
					'default'           => '$$',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_price_range' ),
                ) 
            );
			$wp_customize->add_control(
                'lafka_business_price_range',
                array(
					'label'       => esc_html__( 'Price range', 'lafka-plugin' ),
					'description' => esc_html__( '$ = inexpensive, $$$$ = very expensive. Used by JSON-LD priceRange.', 'lafka-plugin' ),
					'section'     => 'lafka_business_identity',
					'type'        => 'select',
					'choices'     => array(
						'$'    => '$  (inexpensive)',
						'$$'   => '$$  (moderate)',
						'$$$'  => '$$$  (expensive)',
						'$$$$' => '$$$$  (very expensive)',
					),
                ) 
            );

			self::add_text(
                $wp_customize,
                'lafka_business_email',
				'lafka_business_identity',
				esc_html__( 'Public contact email', 'lafka-plugin' ),
				'',
				esc_html__( 'Defaults to the WP admin email when empty.', 'lafka-plugin' ),
				'email',
				'sanitize_email'
			);
		}

		// ====================================================================
		// Section: Location
		// ====================================================================

		private static function register_location_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_location',
                array(
					'title'    => esc_html__( 'Location', 'lafka-plugin' ),
					'panel'    => 'lafka_restaurant_info',
					'priority' => 20,
                ) 
            );

			$wc_default_msg = esc_html__( 'Leave blank to inherit from WooCommerce → Settings → General.', 'lafka-plugin' );
			self::add_text( $wp_customize, 'lafka_business_street', 'lafka_business_location', esc_html__( 'Street address', 'lafka-plugin' ), '', $wc_default_msg );
			self::add_text( $wp_customize, 'lafka_business_city', 'lafka_business_location', esc_html__( 'City', 'lafka-plugin' ), '', $wc_default_msg );
			self::add_text( $wp_customize, 'lafka_business_region', 'lafka_business_location', esc_html__( 'Region / State (2-letter code preferred)', 'lafka-plugin' ), '', $wc_default_msg );
			self::add_text( $wp_customize, 'lafka_business_postal', 'lafka_business_location', esc_html__( 'Postal / ZIP code', 'lafka-plugin' ), '', $wc_default_msg );
			self::add_text( $wp_customize, 'lafka_business_country', 'lafka_business_location', esc_html__( 'Country (2-letter ISO, e.g. CA, US)', 'lafka-plugin' ), '', $wc_default_msg );

			$wp_customize->add_setting(
                'lafka_business_geo_lat',
                array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_geo' ),
                ) 
            );
			$wp_customize->add_control(
                'lafka_business_geo_lat',
                array(
					'label'       => esc_html__( 'Geo latitude', 'lafka-plugin' ),
					'description' => esc_html__( 'Decimal degrees, 4-6 places (e.g. 40.7128). Leave both lat & lng empty to omit geo from JSON-LD.', 'lafka-plugin' ),
					'section'     => 'lafka_business_location',
					'type'        => 'text',
                ) 
            );

			$wp_customize->add_setting(
                'lafka_business_geo_lng',
                array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_geo' ),
                ) 
            );
			$wp_customize->add_control(
                'lafka_business_geo_lng',
                array(
					'label'       => esc_html__( 'Geo longitude', 'lafka-plugin' ),
					'description' => esc_html__( 'Decimal degrees, 4-6 places (e.g. -74.0060).', 'lafka-plugin' ),
					'section'     => 'lafka_business_location',
					'type'        => 'text',
                ) 
            );
		}

		// ====================================================================
		// Section: Contact
		// ====================================================================

		private static function register_contact_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_contact',
                array(
					'title'    => esc_html__( 'Contact', 'lafka-plugin' ),
					'panel'    => 'lafka_restaurant_info',
					'priority' => 30,
                ) 
            );

			self::add_text(
                $wp_customize,
                'lafka_business_phone_e164',
				'lafka_business_contact',
				esc_html__( 'Phone (E.164 format)', 'lafka-plugin' ),
				'',
				esc_html__( 'Machine-readable form. Example: +15551234567 (no spaces, no dashes). Leave blank to inherit from WooCommerce → Settings → General → Store phone.', 'lafka-plugin' )
			);

			self::add_text(
                $wp_customize,
                'lafka_business_phone_display',
				'lafka_business_contact',
				esc_html__( 'Phone (human-friendly display)', 'lafka-plugin' ),
				'',
				esc_html__( 'Shown to users. Example: +1 555-123-4567. Falls back to the E.164 value (or WC store phone) when blank.', 'lafka-plugin' )
			);
		}

		// ====================================================================
		// Section: Hours
		// ====================================================================

		private static function register_hours_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_hours',
                array(
					'title'           => esc_html__( 'Hours', 'lafka-plugin' ),
					'panel'           => 'lafka_restaurant_info',
					'description'     => esc_html__( 'Per-day opening hours in 24h format "HH:MM-HH:MM" (e.g. 11:00-23:00). Use "closed" for closed days. Empty values are simply skipped.', 'lafka-plugin' ),
					'priority'        => 40,
                )
            );

			$days = array(
				'mon' => esc_html__( 'Monday', 'lafka-plugin' ),
				'tue' => esc_html__( 'Tuesday', 'lafka-plugin' ),
				'wed' => esc_html__( 'Wednesday', 'lafka-plugin' ),
				'thu' => esc_html__( 'Thursday', 'lafka-plugin' ),
				'fri' => esc_html__( 'Friday', 'lafka-plugin' ),
				'sat' => esc_html__( 'Saturday', 'lafka-plugin' ),
				'sun' => esc_html__( 'Sunday', 'lafka-plugin' ),
			);
			foreach ( $days as $key => $label ) {
				$wp_customize->add_setting(
                    'lafka_business_hours_' . $key,
                    array(
						'default'           => '',
						'transport'         => 'refresh',
						'sanitize_callback' => array( __CLASS__, 'sanitize_hours' ),
                    ) 
                );
				$wp_customize->add_control(
                    'lafka_business_hours_' . $key,
                    array(
						'label'       => $label,
						'description' => esc_html__( 'Format: HH:MM-HH:MM or "closed". Leave empty to omit.', 'lafka-plugin' ),
						'section'     => 'lafka_business_hours',
						'type'        => 'text',
                    ) 
                );
			}
		}

		// ====================================================================
		// Section: Cuisine & Payment
		// ====================================================================

		private static function register_cuisine_payment_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_cuisine_payment',
                array(
					'title'    => esc_html__( 'Cuisine & Payment', 'lafka-plugin' ),
					'panel'    => 'lafka_restaurant_info',
					'priority' => 50,
                ) 
            );

			self::add_text(
                $wp_customize,
                'lafka_business_cuisines',
				'lafka_business_cuisine_payment',
				esc_html__( 'Cuisines (comma-separated)', 'lafka-plugin' ),
				'',
				esc_html__( 'Examples: Pizza, Donair, Poutine, Italian, Mediterranean.', 'lafka-plugin' ),
				'textarea'
			);

			self::add_text(
                $wp_customize,
                'lafka_business_payment_methods',
				'lafka_business_cuisine_payment',
				esc_html__( 'Payment methods (comma-separated)', 'lafka-plugin' ),
				'',
				esc_html__( 'Examples: Cash, Credit Card, Visa, Mastercard, Amex, Apple Pay.', 'lafka-plugin' ),
				'textarea'
			);
		}

		// ====================================================================
		// Section: Social Profiles & Citations (sameAs)
		// ====================================================================

		private static function register_same_as_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_business_same_as',
                array(
					'title'       => esc_html__( 'Social Profiles & Citations (sameAs)', 'lafka-plugin' ),
					'description' => esc_html__( 'Authoritative URLs Google uses to corroborate your business identity. One URL per line. Invalid lines are silently dropped at render. Recommended: Facebook, Instagram, Yelp, TripAdvisor, Google Business Profile, YellowPages.', 'lafka-plugin' ),
					'panel'       => 'lafka_restaurant_info',
					'priority'    => 60,
                ) 
            );

			$wp_customize->add_setting(
                'lafka_business_same_as',
                array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_url_list' ),
                ) 
            );
			$wp_customize->add_control(
                'lafka_business_same_as',
                array(
					'label'   => esc_html__( 'sameAs URLs (one per line)', 'lafka-plugin' ),
					'section' => 'lafka_business_same_as',
					'type'    => 'textarea',
                ) 
            );
		}

		// ====================================================================
		// Section: Homepage Hero (LCP image)
		// ====================================================================

		private static function register_homepage_hero_section( $wp_customize ): void {
			$wp_customize->add_section(
                'lafka_homepage_hero',
                array(
					'title'       => esc_html__( 'Homepage Hero (LCP)', 'lafka-plugin' ),
					'description' => esc_html__( 'Image preloaded on the homepage for fastest Largest Contentful Paint. Used by the lafka_lcp_image_url filter in lafka-plugin (incl/perf/lcp-preload.php). Image emitted as a `<link rel="preload">` from the theme\'s header.php.', 'lafka-plugin' ),
					'panel'       => 'lafka_restaurant_info',
					'priority'    => 70,
                ) 
            );

			$wp_customize->add_setting(
                'lafka_homepage_hero_image',
                array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => 'esc_url_raw',
                ) 
            );
			$wp_customize->add_control(
				new WP_Customize_Image_Control(
					$wp_customize,
					'lafka_homepage_hero_image',
					array(
						'label'       => esc_html__( 'Homepage hero image', 'lafka-plugin' ),
						'description' => esc_html__( 'Leave empty to disable LCP preloading.', 'lafka-plugin' ),
						'section'     => 'lafka_homepage_hero',
					)
				)
			);
		}
	}

	Lafka_Customizer_Restaurant_Info::init();
}
