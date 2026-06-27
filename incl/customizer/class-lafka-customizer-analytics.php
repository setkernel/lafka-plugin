<?php
/**
 * Phase 1A: Customizer panel "Lafka — Analytics".
 *
 * Single source of operator-configurable tag manager + direct platform IDs +
 * Consent Mode v2 defaults. All settings are read by the emit layer in
 * incl/analytics/lafka-analytics-emitter.php and rendered on every front-end
 * request via wp_head / wp_body_open hooks.
 *
 * Pillar 1 of the Analytics + SEO + Conversion plan:
 *   - Operator pastes GTM Container ID → all platforms wire up through one tag.
 *   - If GTM is empty, direct IDs for GA4 / Clarity / Meta Pixel emit native snippets.
 *   - Consent Mode v2 default state fires BEFORE any tag — CPPA / GDPR compliant.
 *
 * All settings prefixed `lafka_gtm_*`, `lafka_ga4_*`, `lafka_clarity_*`,
 * `lafka_meta_pixel_*`, `lafka_gsc_*`, `lafka_consent_*`. All transport: refresh.
 *
 * @package Lafka\Plugin\Customizer
 * @since   9.23.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Analytics' ) ) {

	/**
	 * Registers the "Lafka — Analytics" Customizer panel.
	 */
	final class Lafka_Customizer_Analytics {

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
				'lafka_analytics',
				array(
					'title'       => esc_html__( 'Lafka — Analytics', 'lafka-plugin' ),
					'description' => esc_html__( 'Multi-platform tracking via Google Tag Manager (orchestrator) plus direct platform fallbacks for GA4, Microsoft Clarity, and Meta Pixel. Consent Mode v2 defaults emit BEFORE any tag fires so the site is CPPA/GDPR-compliant out of the box. Paste your IDs once here — the plugin handles emit order, escaping, and consent gating.', 'lafka-plugin' ),
					'priority'    => 33,
				)
			);

			self::register_tag_manager_section( $wp_customize );
			self::register_direct_ids_section( $wp_customize );
			self::register_consent_section( $wp_customize );
		}

		// ====================================================================
		// Sanitizers
		// ====================================================================

		/**
		 * Sanitize a Google Tag Manager container ID.
		 *
		 * Format: GTM- followed by 4-10 uppercase alphanumeric characters.
		 * Returns '' for invalid input so the emit layer no-ops cleanly.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_gtm_container_id( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			$value = strtoupper( $value );
			if ( preg_match( '/^GTM-[A-Z0-9]+$/', $value ) ) {
				return $value;
			}
			return '';
		}

		/**
		 * Sanitize a GA4 Measurement ID.
		 *
		 * Format: G- followed by uppercase alphanumeric characters (typically 10).
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_ga4_measurement_id( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			$value = strtoupper( $value );
			if ( preg_match( '/^G-[A-Z0-9]+$/', $value ) ) {
				return $value;
			}
			return '';
		}

		/**
		 * Sanitize a Microsoft Clarity project ID.
		 *
		 * Format: alphanumeric, typically 10 characters.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_clarity_project_id( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( preg_match( '/^[a-zA-Z0-9]+$/', $value ) ) {
				return $value;
			}
			return '';
		}

		/**
		 * Sanitize a Cloudflare Web Analytics beacon token.
		 *
		 * Format: 32 lowercase hex characters.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_cf_beacon_token( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = strtolower( trim( (string) $value ) );
			return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
		}

		/**
		 * Sanitize a Meta (Facebook) Pixel ID.
		 *
		 * Format: 15-16 digit numeric string.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_meta_pixel_id( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( preg_match( '/^\d{15,16}$/', $value ) ) {
				return $value;
			}
			return '';
		}

		/**
		 * Sanitize a Google Search Console verification token.
		 *
		 * GSC tokens are alphanumeric + underscores + hyphens (single-line opaque
		 * string). Keep this permissive but strip anything that could break the
		 * meta tag (newlines, quotes, angle brackets).
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_gsc_verification( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			// Drop anything that would break out of an attribute value or
			// inject an HTML tag.
			$value = preg_replace( '/[<>"\'\r\n]/', '', $value );
			return (string) $value;
		}

		/**
		 * Sanitize a consent state value (denied | granted).
		 *
		 * @param mixed $value
		 * @return string 'denied' or 'granted'; falls back to 'denied' for unknown input.
		 */
		public static function sanitize_consent_state( $value ): string {
			$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			return 'granted' === $value ? 'granted' : 'denied';
		}

		/**
		 * Sanitize the consent banner enable checkbox (0/1).
		 *
		 * @param mixed $value
		 * @return string '1' or '0'
		 */
		public static function sanitize_checkbox( $value ): string {
			return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
		}

		// ====================================================================
		// Section: Tag manager (orchestrator)
		// ====================================================================

		private static function register_tag_manager_section( $wp_customize ): void {
			$wp_customize->add_section(
				'lafka_analytics_gtm',
				array(
					'title'       => esc_html__( 'Tag manager', 'lafka-plugin' ),
					'description' => esc_html__( 'Google Tag Manager is the recommended orchestrator. Once GTM is set, you wire GA4 / Clarity / Meta Pixel inside the GTM UI and the plugin emits a single container snippet. The direct-platform fields below are only used when this field is empty (operator can pick the simpler path during early setup).', 'lafka-plugin' ),
					'panel'       => 'lafka_analytics',
					'priority'    => 10,
				)
			);

			$wp_customize->add_setting(
				'lafka_gtm_container_id',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_gtm_container_id' ),
				)
			);
			$wp_customize->add_control(
				'lafka_gtm_container_id',
				array(
					'label'       => esc_html__( 'GTM Container ID', 'lafka-plugin' ),
					'description' => esc_html__( 'Format: GTM-XXXXXXX. Find it at tagmanager.google.com → your container → top-right. When set, the plugin emits the GTM head snippet + body noscript iframe per Google\'s install spec. Leave blank to use direct-platform IDs below.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_gtm',
					'type'        => 'text',
					'input_attrs' => array(
						'placeholder' => 'GTM-XXXXXXX',
					),
				)
			);
		}

		// ====================================================================
		// Section: Direct platform IDs (used only if GTM is empty)
		// ====================================================================

		private static function register_direct_ids_section( $wp_customize ): void {
			$wp_customize->add_section(
				'lafka_analytics_direct',
				array(
					'title'       => esc_html__( 'Direct platform IDs (used only if GTM is empty)', 'lafka-plugin' ),
					'description' => esc_html__( 'Override-not-additive: if any GTM Container ID is set above, the plugin emits ONLY the GTM snippet and lets you wire these platforms via GTM tags (avoids double-firing). GA4 has a hard cap of 500 distinct event names per property — Microsoft Clarity is unlimited and complements GA4 for heatmaps + session replay. Meta Pixel is required only if you run paid Facebook/Instagram ads. GSC verification turns on the data feed for Google Search Console (rank tracking + click metrics).', 'lafka-plugin' ),
					'panel'       => 'lafka_analytics',
					'priority'    => 20,
				)
			);

			$wp_customize->add_setting(
				'lafka_ga4_measurement_id',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_ga4_measurement_id' ),
				)
			);
			$wp_customize->add_control(
				'lafka_ga4_measurement_id',
				array(
					'label'       => esc_html__( 'GA4 Measurement ID', 'lafka-plugin' ),
					'description' => esc_html__( 'Format: G-XXXXXXXXXX. Find it at analytics.google.com → Admin → Data Streams → Web → Measurement ID. Only used when GTM is empty.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_direct',
					'type'        => 'text',
					'input_attrs' => array(
						'placeholder' => 'G-XXXXXXXXXX',
					),
				)
			);

			$wp_customize->add_setting(
				'lafka_clarity_project_id',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_clarity_project_id' ),
				)
			);
			$wp_customize->add_control(
				'lafka_clarity_project_id',
				array(
					'label'       => esc_html__( 'Microsoft Clarity Project ID', 'lafka-plugin' ),
					'description' => esc_html__( 'Alphanumeric project ID from clarity.microsoft.com. Free, unlimited heatmaps + session replay — complements GA4. Only used when GTM is empty.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_direct',
					'type'        => 'text',
				)
			);

			$wp_customize->add_setting(
				'lafka_cf_beacon_token',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_cf_beacon_token' ),
				)
			);
			$wp_customize->add_control(
				'lafka_cf_beacon_token',
				array(
					'label'       => esc_html__( 'Cloudflare Web Analytics token', 'lafka-plugin' ),
					'description' => esc_html__( '32-character token from dash.cloudflare.com → Analytics & Logs → Web Analytics → your site → "JS snippet" (the data-cf-beacon token). Cookieless + privacy-first, so it emits independently of GTM and consent.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_direct',
					'type'        => 'text',
					'input_attrs' => array(
						'placeholder' => '0123456789abcdef0123456789abcdef',
					),
				)
			);

			$wp_customize->add_setting(
				'lafka_meta_pixel_id',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_meta_pixel_id' ),
				)
			);
			$wp_customize->add_control(
				'lafka_meta_pixel_id',
				array(
					'label'       => esc_html__( 'Meta Pixel ID', 'lafka-plugin' ),
					'description' => esc_html__( '15-16 digit numeric ID from business.facebook.com → Events Manager → Data Sources. Only needed when running paid Facebook / Instagram ads. Only used when GTM is empty.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_direct',
					'type'        => 'text',
				)
			);

			$wp_customize->add_setting(
				'lafka_gsc_verification',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_gsc_verification' ),
				)
			);
			$wp_customize->add_control(
				'lafka_gsc_verification',
				array(
					'label'       => esc_html__( 'Google Search Console verification token', 'lafka-plugin' ),
					'description' => esc_html__( 'Paste only the content="..." value from the HTML verification snippet Google gave you (not the full meta tag). Required once to claim the property in search.google.com/search-console — after verification you can leave it set or remove it.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_direct',
					'type'        => 'text',
				)
			);
		}

		// ====================================================================
		// Section: Consent + privacy
		// ====================================================================

		private static function register_consent_section( $wp_customize ): void {
			$wp_customize->add_section(
				'lafka_analytics_consent',
				array(
					'title'       => esc_html__( 'Consent + privacy', 'lafka-plugin' ),
					'description' => esc_html__( 'Google Consent Mode v2 defaults emit BEFORE any tag fires so the site is compliant from the first byte. The banner appears at the bottom of the viewport until the visitor Accepts, Rejects, or sets per-category preferences. Defaults are denied (most-restrictive) — set to granted only if your legal review allows opt-out-rather-than-opt-in (some EU jurisdictions do not).', 'lafka-plugin' ),
					'panel'       => 'lafka_analytics',
					'priority'    => 30,
				)
			);

			$wp_customize->add_setting(
				'lafka_consent_banner_enabled',
				array(
					'default'           => '1',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_consent_banner_enabled',
				array(
					'label'       => esc_html__( 'Show consent banner', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the banner appears for every new visitor until they decide. When OFF, the plugin still emits the Consent Mode v2 default state (still safer than no consent at all) but never asks for an explicit decision.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_consent',
					'type'        => 'checkbox',
				)
			);

			$consent_choices = array(
				'denied'  => esc_html__( 'Denied (most-restrictive default)', 'lafka-plugin' ),
				'granted' => esc_html__( 'Granted (opt-out flow — check your jurisdiction first)', 'lafka-plugin' ),
			);

			$consent_categories = array(
				'lafka_consent_default_analytics'        => array(
					'label'       => esc_html__( 'Default state: analytics_storage', 'lafka-plugin' ),
					'description' => esc_html__( 'GA4 / Clarity / behavioural-analytics storage. Required for any usage metric. Defaults to denied (Consent Mode v2 baseline).', 'lafka-plugin' ),
				),
				'lafka_consent_default_ad_storage'       => array(
					'label'       => esc_html__( 'Default state: ad_storage', 'lafka-plugin' ),
					'description' => esc_html__( 'Cookies/identifiers used for advertising (Meta Pixel, Google Ads). Defaults to denied.', 'lafka-plugin' ),
				),
				'lafka_consent_default_ad_user_data'     => array(
					'label'       => esc_html__( 'Default state: ad_user_data', 'lafka-plugin' ),
					'description' => esc_html__( 'Sending user data to Google for advertising. Required for Enhanced Conversions. Defaults to denied.', 'lafka-plugin' ),
				),
				'lafka_consent_default_ad_personalization' => array(
					'label'       => esc_html__( 'Default state: ad_personalization', 'lafka-plugin' ),
					'description' => esc_html__( 'Personalised ads / remarketing audiences. Defaults to denied.', 'lafka-plugin' ),
				),
			);

			foreach ( $consent_categories as $setting_id => $meta ) {
				$wp_customize->add_setting(
					$setting_id,
					array(
						'default'           => 'denied',
						'transport'         => 'refresh',
						'sanitize_callback' => array( __CLASS__, 'sanitize_consent_state' ),
					)
				);
				$wp_customize->add_control(
					$setting_id,
					array(
						'label'       => $meta['label'],
						'description' => $meta['description'],
						'section'     => 'lafka_analytics_consent',
						'type'        => 'select',
						'choices'     => $consent_choices,
					)
				);
			}

			$wp_customize->add_setting(
				'lafka_consent_banner_text',
				array(
					'default'           => 'We use cookies to analyze site traffic and personalize content. By accepting, you consent to our use of cookies.',
					'transport'         => 'refresh',
					'sanitize_callback' => 'wp_kses_post',
				)
			);
			$wp_customize->add_control(
				'lafka_consent_banner_text',
				array(
					'label'       => esc_html__( 'Banner body text', 'lafka-plugin' ),
					'description' => esc_html__( 'Shown above the Accept / Reject / Settings buttons. Inline markup (a, strong, em) is allowed; everything else is stripped.', 'lafka-plugin' ),
					'section'     => 'lafka_analytics_consent',
					'type'        => 'textarea',
				)
			);

			$button_labels = array(
				'lafka_consent_banner_accept_label'   => array(
					'default'     => 'Accept all',
					'label'       => esc_html__( 'Accept button label', 'lafka-plugin' ),
					'description' => esc_html__( 'Primary action — grants all categories.', 'lafka-plugin' ),
				),
				'lafka_consent_banner_reject_label'   => array(
					'default'     => 'Reject',
					'label'       => esc_html__( 'Reject button label', 'lafka-plugin' ),
					'description' => esc_html__( 'Secondary action — keeps everything denied.', 'lafka-plugin' ),
				),
				'lafka_consent_banner_settings_label' => array(
					'default'     => 'Settings',
					'label'       => esc_html__( 'Settings button label', 'lafka-plugin' ),
					'description' => esc_html__( 'Opens the per-category toggle modal.', 'lafka-plugin' ),
				),
			);

			foreach ( $button_labels as $setting_id => $meta ) {
				$wp_customize->add_setting(
					$setting_id,
					array(
						'default'           => $meta['default'],
						'transport'         => 'refresh',
						'sanitize_callback' => 'sanitize_text_field',
					)
				);
				$wp_customize->add_control(
					$setting_id,
					array(
						'label'       => $meta['label'],
						'description' => $meta['description'],
						'section'     => 'lafka_analytics_consent',
						'type'        => 'text',
					)
				);
			}
		}
	}

	Lafka_Customizer_Analytics::init();
}
