<?php
/**
 * Restaurant Information — WooCommerce Settings tab (v9.18.0).
 *
 * Canonical WC-standard home for operator business data. Replaces the
 * custom Customizer panel (lafka_restaurant_info) by extending
 * WC_Settings_Page — the same base class WooCommerce ships for its own
 * General / Products / Tax / etc. tabs.
 *
 * Storage: each setting writes to its own wp_options row using the same
 * keys the legacy Customizer used (lafka_business_*). The read helper
 * `lafka_get_restaurant_info()` (incl/schema/lafka-schema-helpers.php)
 * checks wp_options FIRST as of v9.18.0, so values entered here win.
 * Legacy theme_mod values remain readable as fallback for operators
 * who haven't migrated.
 *
 * Why a WC Settings tab and not a Customizer panel:
 *  - Operators look for business data under WooCommerce → Settings (the
 *    WC-ecosystem-standard location); they don't expect schema config
 *    under Appearance
 *  - WC_Settings_Page handles save, nonce, sanitize, tab rendering;
 *    Customizer panels duplicate all of that with their own primitives
 *  - Sidesteps the WP Customizer panel visibility issues that hounded
 *    the previous implementation
 *  - Same `lafka_business_*` keys means zero migration for legacy data
 *
 * @package Lafka\Plugin\Admin
 * @since   9.18.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Settings_Page' ) || class_exists( 'Lafka_WC_Settings_Restaurant' ) ) {
	return;
}

/**
 * Adds the "Restaurant" tab under WooCommerce → Settings.
 */
class Lafka_WC_Settings_Restaurant extends WC_Settings_Page {

	/**
	 * Tab ID + identifier used in URLs (?tab=lafka_restaurant).
	 */
	public function __construct() {
		$this->id    = 'lafka_restaurant';
		$this->label = __( 'Restaurant', 'lafka-plugin' );
		parent::__construct();
	}

	/**
	 * Sections inside the Restaurant tab (left sub-nav).
	 *
	 * Mirrors the section layout the legacy Customizer panel used:
	 * Identity, Location, Contact, Hours, Cuisine & Payment, Social,
	 * Hero. WC_Settings_Page renders these as section links across
	 * the top of the tab.
	 */
	public function get_sections() {
		return apply_filters(
			'woocommerce_get_sections_' . $this->id,
			array(
				''         => __( 'Identity', 'lafka-plugin' ),
				'location' => __( 'Location', 'lafka-plugin' ),
				'contact'  => __( 'Contact', 'lafka-plugin' ),
				'hours'    => __( 'Hours', 'lafka-plugin' ),
				'cuisine'  => __( 'Cuisine & Payment', 'lafka-plugin' ),
				'social'   => __( 'Social Profiles', 'lafka-plugin' ),
				'hero'     => __( 'Homepage Hero', 'lafka-plugin' ),
			)
		);
	}

	/**
	 * Settings for the current section.
	 *
	 * @return array WC settings array (consumed by WC_Settings_Page::output()).
	 */
	public function get_settings_for_section_core( $section_id ) {
		switch ( $section_id ) {
			case 'location':
				return $this->get_location_settings();
			case 'contact':
				return $this->get_contact_settings();
			case 'hours':
				return $this->get_hours_settings();
			case 'cuisine':
				return $this->get_cuisine_settings();
			case 'social':
				return $this->get_social_settings();
			case 'hero':
				return $this->get_hero_settings();
			default:
				return $this->get_identity_settings();
		}
	}

	// =========================================================================
	// Sections
	// =========================================================================

	private function get_identity_settings() {
		return array(
			array(
				'title' => __( 'Identity', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Restaurant name, schema.org business type, and price tier. The name defaults to the WordPress site title when empty. Business type is used in JSON-LD as the @type list.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_identity_title',
			),
			array(
				'title'    => __( 'Restaurant name', 'lafka-plugin' ),
				'desc_tip' => __( 'Defaults to the WordPress site title when empty.', 'lafka-plugin' ),
				'id'       => 'lafka_business_name',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'title'    => __( 'Business type (schema.org)', 'lafka-plugin' ),
				'desc_tip' => __( 'Comma-separated schema.org type(s). E.g. "Restaurant, LocalBusiness, FoodEstablishment".', 'lafka-plugin' ),
				'id'       => 'lafka_business_business_type',
				'type'     => 'text',
				'default'  => 'Restaurant, LocalBusiness, FoodEstablishment',
			),
			array(
				'title'    => __( 'Price range', 'lafka-plugin' ),
				'desc_tip' => __( 'Schema.org priceRange. Use $ to $$$$ to indicate tier.', 'lafka-plugin' ),
				'id'       => 'lafka_business_price_range',
				'type'     => 'select',
				'options'  => array(
					'$'    => '$',
					'$$'   => '$$',
					'$$$'  => '$$$',
					'$$$$' => '$$$$',
				),
				'default'  => '$$',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_identity_end',
			),
		);
	}

	private function get_location_settings() {
		return array(
			array(
				'title' => __( 'Location', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Street address fields. Each field falls back to its WooCommerce → Settings → General equivalent when blank — fill in here only to override per-schema (e.g. multi-location).', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_location_title',
			),
			array(
				'title'   => __( 'Street address', 'lafka-plugin' ),
				'id'      => 'lafka_business_street',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'   => __( 'City', 'lafka-plugin' ),
				'id'      => 'lafka_business_city',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'   => __( 'Region / State / Province', 'lafka-plugin' ),
				'id'      => 'lafka_business_region',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'   => __( 'Postal / ZIP code', 'lafka-plugin' ),
				'id'      => 'lafka_business_postal',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'    => __( 'Country', 'lafka-plugin' ),
				'desc_tip' => __( 'ISO-3166 alpha-2 code (e.g. "CA"). Defaults to WooCommerce store country when empty.', 'lafka-plugin' ),
				'id'       => 'lafka_business_country',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'title'    => __( 'Latitude', 'lafka-plugin' ),
				'desc_tip' => __( 'Decimal degrees, e.g. 44.7711. Used for schema.org geo coordinates.', 'lafka-plugin' ),
				'id'       => 'lafka_business_geo_lat',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'title'    => __( 'Longitude', 'lafka-plugin' ),
				'desc_tip' => __( 'Decimal degrees, e.g. -63.6919.', 'lafka-plugin' ),
				'id'       => 'lafka_business_geo_lng',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_location_end',
			),
		);
	}

	private function get_contact_settings() {
		return array(
			array(
				'title' => __( 'Contact', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Phone (in two shapes — E.164 for tel: links and a human display) and email. Phone falls back to woocommerce_store_phone; email to the WP admin email.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_contact_title',
			),
			array(
				'title'    => __( 'Phone (E.164 — tap-to-call)', 'lafka-plugin' ),
				'desc_tip' => __( 'Must start with +countrycode, digits only. E.g. +19024042888.', 'lafka-plugin' ),
				'id'       => 'lafka_business_phone_e164',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'title'    => __( 'Phone (display)', 'lafka-plugin' ),
				'desc_tip' => __( 'Operator-facing format. E.g. (902) 404-2888.', 'lafka-plugin' ),
				'id'       => 'lafka_business_phone_display',
				'type'     => 'text',
				'default'  => '',
			),
			array(
				'title'   => __( 'Email', 'lafka-plugin' ),
				'id'      => 'lafka_business_email',
				'type'    => 'email',
				'default' => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_contact_end',
			),
		);
	}

	private function get_hours_settings() {
		$days = array(
			'mon' => __( 'Monday', 'lafka-plugin' ),
			'tue' => __( 'Tuesday', 'lafka-plugin' ),
			'wed' => __( 'Wednesday', 'lafka-plugin' ),
			'thu' => __( 'Thursday', 'lafka-plugin' ),
			'fri' => __( 'Friday', 'lafka-plugin' ),
			'sat' => __( 'Saturday', 'lafka-plugin' ),
			'sun' => __( 'Sunday', 'lafka-plugin' ),
		);

		$settings = array(
			array(
				'title' => __( 'Opening hours', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Per-day hours in 24h format "HH:MM-HH:MM" (e.g. 11:00-23:00). Use "closed" for closed days. Empty values are simply skipped from JSON-LD.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_hours_title',
			),
		);

		foreach ( $days as $key => $label ) {
			$settings[] = array(
				'title'             => $label,
				'id'                => 'lafka_business_hours_' . $key,
				'type'              => 'text',
				'default'           => '',
				'placeholder'       => '11:00-23:00',
				'css'               => 'max-width: 200px;',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'lafka_restaurant_hours_end',
		);
		return $settings;
	}

	private function get_cuisine_settings() {
		return array(
			array(
				'title' => __( 'Cuisine & Payment', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Comma-separated lists. Both flow into the Restaurant JSON-LD as servesCuisine and paymentAccepted respectively.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_cuisine_title',
			),
			array(
				'title'    => __( 'Cuisines (comma-separated)', 'lafka-plugin' ),
				'desc_tip' => __( 'Examples: Pizza, Donair, Poutine, Italian, Mediterranean.', 'lafka-plugin' ),
				'id'       => 'lafka_business_cuisines',
				'type'     => 'textarea',
				'default'  => '',
				'css'      => 'min-height: 60px;',
			),
			array(
				'title'    => __( 'Payment methods (comma-separated)', 'lafka-plugin' ),
				'desc_tip' => __( 'Examples: Cash, Credit Card, Visa, Mastercard, Amex, Apple Pay.', 'lafka-plugin' ),
				'id'       => 'lafka_business_payment_methods',
				'type'     => 'textarea',
				'default'  => '',
				'css'      => 'min-height: 60px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_cuisine_end',
			),
		);
	}

	private function get_social_settings() {
		return array(
			array(
				'title' => __( 'Social Profiles & Citations (sameAs)', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Authoritative URLs Google uses to corroborate your business identity. One URL per line. Invalid lines are silently dropped at render. Recommended: Facebook, Instagram, Yelp, TripAdvisor, Google Business Profile, YellowPages.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_social_title',
			),
			array(
				'title'   => __( 'URLs (one per line)', 'lafka-plugin' ),
				'id'      => 'lafka_business_same_as',
				'type'    => 'textarea',
				'default' => '',
				'css'     => 'min-height: 140px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_social_end',
			),
		);
	}

	private function get_hero_settings() {
		return array(
			array(
				'title' => __( 'Homepage hero (LCP preload)', 'lafka-plugin' ),
				'type'  => 'title',
				'desc'  => __( 'Image preloaded on the homepage for fastest Largest Contentful Paint. Used by the lafka_lcp_image_url filter in lafka-plugin (incl/perf/lcp-preload.php). Leave empty to disable LCP preloading.', 'lafka-plugin' ),
				'id'    => 'lafka_restaurant_hero_title',
			),
			array(
				'title'    => __( 'Hero image URL', 'lafka-plugin' ),
				'desc_tip' => __( 'Absolute URL to a hero image. Use Media Library "Copy URL" to grab the address of an uploaded image.', 'lafka-plugin' ),
				'id'       => 'lafka_homepage_hero_image',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 480px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'lafka_restaurant_hero_end',
			),
		);
	}
}

// Register the tab via the WC settings pages filter — the canonical
// extension point.
add_filter(
	'woocommerce_get_settings_pages',
	function ( $pages ) {
		$pages[] = new Lafka_WC_Settings_Restaurant();
		return $pages;
	}
);
