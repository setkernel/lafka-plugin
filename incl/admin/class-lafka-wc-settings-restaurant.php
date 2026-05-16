<?php
/**
 * Restaurant Information — WooCommerce Settings tab (v9.20.0).
 *
 * Canonical WC-standard home for the operator-controllable extras the
 * Lafka schema layer needs beyond what WC Settings → General + WP
 * Settings → General already provide.
 *
 * **What this tab contains** (Lafka-only fields):
 *   - Hours (per-day opening hours, used in JSON-LD openingHoursSpecification)
 *   - Cuisines + Payment methods (for schema.org servesCuisine + paymentAccepted)
 *   - Geo coordinates (for schema.org geo)
 *   - Business type (for schema @type — Restaurant / LocalBusiness / etc.)
 *   - Price range (for schema priceRange)
 *   - Phone display format (the human-readable phone shown in the UI)
 *   - sameAs URLs (Facebook, Instagram, Yelp, etc. — for schema sameAs)
 *   - Homepage hero image
 *
 * **What this tab does NOT duplicate** (sourced from WP/WC core instead):
 *   - Restaurant name → `get_bloginfo('name')` (WP Settings → General → Site Title)
 *   - Street / City / Region / Postal / Country → `woocommerce_store_*`
 *     options (WC Settings → General → Store Address)
 *   - Phone (tap-to-call E.164) → `woocommerce_store_phone`
 *   - Email → `get_bloginfo('admin_email')` (WP Settings → General)
 *
 * The lafka_get_restaurant_info() helper falls back to those native
 * sources when our extras aren't set, so operators only need to edit
 * data in one place — the canonical WP/WC location for that data type.
 *
 * **Registration pattern**: the filter callback below is wired at
 * plugin-load time. WC fires `woocommerce_get_settings_pages` only on
 * Settings page render — by that point WC_Settings_Page is loaded,
 * so the lazy `require_once` inside the callback succeeds.
 *
 * @package Lafka\Plugin\Admin
 * @since   9.18.0
 * @since   9.20.0 — fixed registration timing (was loading too early);
 *                   stripped WC-overlapping fields; added deep links.
 */

defined( 'ABSPATH' ) || exit;

// Register the filter at plugin load. WC_Settings_Page may not exist
// yet — that's why the class definition lives in its own gated block
// below, and we instantiate it lazily inside the filter callback.
add_filter( 'woocommerce_get_settings_pages', 'lafka_register_wc_settings_restaurant' );

if ( ! function_exists( 'lafka_register_wc_settings_restaurant' ) ) {
	function lafka_register_wc_settings_restaurant( $pages ) {
		if ( ! class_exists( 'WC_Settings_Page' ) ) {
			return $pages;
		}
		if ( ! class_exists( 'Lafka_WC_Settings_Restaurant' ) ) {
			lafka_define_wc_settings_restaurant_class();
		}
		if ( class_exists( 'Lafka_WC_Settings_Restaurant' ) ) {
			$pages[] = new Lafka_WC_Settings_Restaurant();
		}
		return $pages;
	}
}

if ( ! function_exists( 'lafka_define_wc_settings_restaurant_class' ) ) {
	/**
	 * Defines the WC settings page class on first access. Keeps the class
	 * declaration out of file-load time so we don't need WC_Settings_Page
	 * to exist when our plugin file loads.
	 */
	function lafka_define_wc_settings_restaurant_class() {

		/**
		 * The "Restaurant" tab under WooCommerce → Settings.
		 */
		class Lafka_WC_Settings_Restaurant extends WC_Settings_Page {

			public function __construct() {
				$this->id    = 'lafka_restaurant';
				$this->label = __( 'Restaurant', 'lafka-plugin' );
				parent::__construct();
			}

			public function get_sections() {
				return apply_filters(
					'woocommerce_get_sections_' . $this->id,
					array(
						''        => __( 'Hours', 'lafka-plugin' ),
						'cuisine' => __( 'Cuisine & Payment', 'lafka-plugin' ),
						'schema'  => __( 'Schema & Geo', 'lafka-plugin' ),
						'social'  => __( 'Social Profiles', 'lafka-plugin' ),
						'hero'    => __( 'Homepage Hero', 'lafka-plugin' ),
					)
				);
			}

			public function get_settings_for_section_core( $section_id ) {
				switch ( $section_id ) {
					case 'cuisine':
						return $this->get_cuisine_settings();
					case 'schema':
						return $this->get_schema_settings();
					case 'social':
						return $this->get_social_settings();
					case 'hero':
						return $this->get_hero_settings();
					default:
						return $this->get_hours_settings();
				}
			}

			// ============================================================
			// Sections
			// ============================================================

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
						'desc'  => $this->intro_html(
							__( 'Per-day hours in 24h format "HH:MM-HH:MM" (e.g. 11:00-23:00). Use "closed" for closed days. Empty values are skipped from JSON-LD openingHoursSpecification.', 'lafka-plugin' )
						),
						'id'    => 'lafka_restaurant_hours_title',
					),
				);

				foreach ( $days as $key => $label ) {
					$settings[] = array(
						'title'       => $label,
						'id'          => 'lafka_business_hours_' . $key,
						'type'        => 'text',
						'default'     => '',
						'placeholder' => '11:00-23:00',
						'css'         => 'max-width: 220px;',
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
						'desc'  => $this->intro_html(
							__( 'Free-text lists that flow into JSON-LD as servesCuisine and paymentAccepted. Comma-separate values.', 'lafka-plugin' )
						),
						'id'    => 'lafka_restaurant_cuisine_title',
					),
					array(
						'title'    => __( 'Cuisines', 'lafka-plugin' ),
						'desc_tip' => __( 'Examples: Pizza, Donair, Poutine, Italian, Mediterranean.', 'lafka-plugin' ),
						'id'       => 'lafka_business_cuisines',
						'type'     => 'textarea',
						'default'  => '',
						'css'      => 'min-height: 60px;',
					),
					array(
						'title'    => __( 'Payment methods', 'lafka-plugin' ),
						'desc_tip' => __( 'Examples: Cash, Credit Card, Visa, Mastercard, Amex, Apple Pay. Independent of WooCommerce gateways — this is for schema labelling only.', 'lafka-plugin' ),
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

			private function get_schema_settings() {
				return array(
					array(
						'title' => __( 'Schema & Geo', 'lafka-plugin' ),
						'type'  => 'title',
						'desc'  => $this->intro_html(
							__( 'JSON-LD-specific overrides. Schema-only fields — your business name, address, phone, and email flow directly from WordPress + WooCommerce settings (see links above).', 'lafka-plugin' )
						),
						'id'    => 'lafka_restaurant_schema_title',
					),
					array(
						'title'    => __( 'Business type', 'lafka-plugin' ),
						'desc_tip' => __( 'Comma-separated schema.org type(s). E.g. "Restaurant, LocalBusiness, FoodEstablishment".', 'lafka-plugin' ),
						'id'       => 'lafka_business_business_type',
						'type'     => 'text',
						'default'  => 'Restaurant, LocalBusiness, FoodEstablishment',
					),
					array(
						'title'    => __( 'Price range', 'lafka-plugin' ),
						'desc_tip' => __( 'Schema.org priceRange tier.', 'lafka-plugin' ),
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
						'title'    => __( 'Phone (display format)', 'lafka-plugin' ),
						'desc_tip' => __( 'Human-readable phone for UI. Leave blank to fall back to the WooCommerce store phone. The tap-to-call E.164 link is generated from your WC store phone automatically.', 'lafka-plugin' ),
						'id'       => 'lafka_business_phone_display',
						'type'     => 'text',
						'default'  => '',
					),
					array(
						'title'    => __( 'Latitude', 'lafka-plugin' ),
						'desc_tip' => __( 'Decimal degrees, e.g. 44.7711. For schema.org geo.latitude.', 'lafka-plugin' ),
						'id'       => 'lafka_business_geo_lat',
						'type'     => 'text',
						'default'  => '',
					),
					array(
						'title'    => __( 'Longitude', 'lafka-plugin' ),
						'desc_tip' => __( 'Decimal degrees, e.g. -63.6919. For schema.org geo.longitude.', 'lafka-plugin' ),
						'id'       => 'lafka_business_geo_lng',
						'type'     => 'text',
						'default'  => '',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_restaurant_schema_end',
					),
				);
			}

			private function get_social_settings() {
				return array(
					array(
						'title' => __( 'Social Profiles & Citations', 'lafka-plugin' ),
						'type'  => 'title',
						'desc'  => $this->intro_html(
							__( 'Authoritative URLs Google uses to corroborate your business identity (schema.org sameAs). One URL per line. Invalid lines are silently dropped. Recommended: Facebook, Instagram, Yelp, TripAdvisor, Google Business Profile, YellowPages.', 'lafka-plugin' )
						),
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
						'desc'  => $this->intro_html(
							__( 'Image preloaded on the homepage for fastest Largest Contentful Paint. Used by the lafka_lcp_image_url filter in lafka-plugin/incl/perf/lcp-preload.php. Leave empty to disable preloading.', 'lafka-plugin' )
						),
						'id'    => 'lafka_restaurant_hero_title',
					),
					array(
						'title'    => __( 'Hero image URL', 'lafka-plugin' ),
						'desc_tip' => __( 'Absolute URL. Use Media Library "Copy URL" to grab the address of an uploaded image.', 'lafka-plugin' ),
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

			/**
			 * Build the section-intro HTML that includes a "Standard
			 * fields live here →" link block pointing at WP/WC native
			 * settings pages — so operators don't go looking for
			 * address/phone/email/name in this tab.
			 *
			 * @param string $body Section-specific description.
			 * @return string Combined HTML safe for WC's settings 'desc'.
			 */
			private function intro_html( $body ) {
				$wc_general  = admin_url( 'admin.php?page=wc-settings&tab=general' );
				$wp_general  = admin_url( 'options-general.php' );
				$site_identity = admin_url( 'customize.php?autofocus[section]=title_tagline' );

				/* translators: 1: WP General Settings URL; 2: WC General Settings URL; 3: Customize → Site Identity URL */
				$footer = sprintf(
					'<div style="margin-top: 12px; padding: 10px 12px; background: #f6f7f7; border-left: 4px solid #007cba; font-size: 13px; line-height: 1.5;"><strong>%1$s</strong><br>%2$s</div>',
					esc_html__( 'Lafka picks these up from their canonical homes — no need to duplicate them here:', 'lafka-plugin' ),
					sprintf(
						/* translators: 1: site title link; 2: WC address link; 3: WC phone link */
						esc_html__( 'Site name → %1$s · Address → %2$s · Phone & email → %3$s', 'lafka-plugin' ),
						'<a href="' . esc_url( $site_identity ) . '">' . esc_html__( 'Site Identity', 'lafka-plugin' ) . '</a>',
						'<a href="' . esc_url( $wc_general ) . '">' . esc_html__( 'WooCommerce → Settings → General', 'lafka-plugin' ) . '</a>',
						'<a href="' . esc_url( $wp_general ) . '">' . esc_html__( 'WordPress → Settings → General', 'lafka-plugin' ) . '</a>'
					)
				);

				return wp_kses_post( $body ) . $footer;
			}
		}
	}
}
