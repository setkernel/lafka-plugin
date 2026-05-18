<?php
/**
 * Phase 3D (v9.28.0): Customizer panel "Lafka — Review prompts".
 *
 * Single source of operator-configurable values for the post-purchase review
 * collection engine: a 24-hour-delayed email + an on-site banner shown to a
 * customer when they revisit within a configurable window.
 *
 * Operator opt-in for both channels (defaults OFF):
 *   - lafka_review_email_enabled       (checkbox, default false)
 *   - lafka_review_email_delay_hours   (1–336 — 1 hour to 14 days, default 24)
 *   - lafka_review_email_subject       (text, supports {firstname} + {site} tokens)
 *   - lafka_review_email_intro         (textarea)
 *   - lafka_review_target_url          (URL, operator's Google Review link)
 *   - lafka_review_target_label        (text — CTA label below the stars)
 *   - lafka_review_banner_enabled      (checkbox, default false)
 *   - lafka_review_banner_window_days  (1–30, default 7)
 *   - lafka_review_banner_copy         (text)
 *   - lafka_review_banner_cta_label    (text)
 *
 * Every setting carries a sanitize_callback so untrusted Customizer payloads
 * can't reach the DB. All values stored as theme_mods (consistent with the
 * rest of the Lafka Customizer surface — analytics, PDP, abandoned-cart).
 *
 * @package Lafka\Plugin\Customizer
 * @since   9.28.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Reviews' ) ) {

	/**
	 * Registers the "Lafka — Review prompts" Customizer panel + its two sections
	 * (email channel + on-site banner channel).
	 */
	final class Lafka_Customizer_Reviews {

		/**
		 * Hook into customize_register.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'customize_register', array( __CLASS__, 'register' ) );
		}

		/**
		 * Register panel + section + settings + controls.
		 *
		 * @param WP_Customize_Manager $wp_customize
		 * @return void
		 */
		public static function register( $wp_customize ): void {
			$wp_customize->add_panel(
				'lafka_reviews',
				array(
					'title'       => esc_html__( 'Lafka — Review prompts', 'lafka-plugin' ),
					'description' => esc_html__( 'Collect Google / on-site reviews after a customer\'s order completes. Two channels: a one-shot email sent 24 hours after the order status flips to "completed", and a small on-site banner shown when the customer revisits within a configurable window. Both channels are OFF by default — flip each on after pasting your review URL below.', 'lafka-plugin' ),
					'priority'    => 36,
				)
			);

			// ─────────────────────────────────────────────────────────────
			// Section 1: Email channel.
			// ─────────────────────────────────────────────────────────────
			$wp_customize->add_section(
				'lafka_reviews_email',
				array(
					'title'       => esc_html__( 'Review email', 'lafka-plugin' ),
					'description' => esc_html__( 'Sent 24 hours after the order status becomes "completed". Includes a 5-star tap row that opens your review URL with the chosen rating attached as a query parameter.', 'lafka-plugin' ),
					'panel'       => 'lafka_reviews',
					'priority'    => 10,
				)
			);

			self::register_email_enabled( $wp_customize );
			self::register_email_delay( $wp_customize );
			self::register_email_subject( $wp_customize );
			self::register_email_intro( $wp_customize );

			// ─────────────────────────────────────────────────────────────
			// Section 2: Review target (shared by email + banner).
			// ─────────────────────────────────────────────────────────────
			$wp_customize->add_section(
				'lafka_reviews_target',
				array(
					'title'       => esc_html__( 'Review destination', 'lafka-plugin' ),
					'description' => esc_html__( 'Where customers land when they tap a star or the banner CTA. Paste your Google Business Profile review URL (e.g. https://g.page/r/CXXXX/review) — both channels use the same destination.', 'lafka-plugin' ),
					'panel'       => 'lafka_reviews',
					'priority'    => 20,
				)
			);

			self::register_target_url( $wp_customize );
			self::register_target_label( $wp_customize );

			// ─────────────────────────────────────────────────────────────
			// Section 3: On-site banner channel.
			// ─────────────────────────────────────────────────────────────
			$wp_customize->add_section(
				'lafka_reviews_banner',
				array(
					'title'       => esc_html__( 'On-site banner', 'lafka-plugin' ),
					'description' => esc_html__( 'Small banner shown bottom-right (desktop) or bottom-center (mobile) when a logged-in customer revisits the site within the window below after a completed order. Never shown on /cart/, /checkout/, /order-received/, or /my-account/.', 'lafka-plugin' ),
					'panel'       => 'lafka_reviews',
					'priority'    => 30,
				)
			);

			self::register_banner_enabled( $wp_customize );
			self::register_banner_window( $wp_customize );
			self::register_banner_copy( $wp_customize );
			self::register_banner_cta_label( $wp_customize );
		}

		// ─────────────────────────────────────────────────────────────────────
		// Sanitizers
		// ─────────────────────────────────────────────────────────────────────

		/**
		 * Coerce checkbox-style input → '0' or '1'.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_checkbox( $value ): string {
			return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
		}

		/**
		 * Clamp delay hours to [1, 336] (1 hour — 14 days).
		 *
		 * @param mixed $value
		 * @return int
		 */
		public static function sanitize_delay_hours( $value ): int {
			$value = is_scalar( $value ) ? (int) $value : 24;
			return max( 1, min( 336, $value ) );
		}

		/**
		 * Clamp banner window days to [1, 30].
		 *
		 * @param mixed $value
		 * @return int
		 */
		public static function sanitize_window_days( $value ): int {
			$value = is_scalar( $value ) ? (int) $value : 7;
			return max( 1, min( 30, $value ) );
		}

		/**
		 * Sanitize the review-target URL. Empty string is valid (means feature
		 * is disabled). Otherwise must be a fully-qualified http(s) URL.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_review_url( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( function_exists( 'esc_url_raw' ) ) {
				$clean = (string) esc_url_raw( $value, array( 'http', 'https' ) );
			} else {
				$clean = filter_var( $value, FILTER_VALIDATE_URL ) ? $value : '';
			}
			return $clean;
		}

		// ─────────────────────────────────────────────────────────────────────
		// Email-channel settings
		// ─────────────────────────────────────────────────────────────────────

		private static function register_email_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_email_enabled',
				array(
					'default'           => '0',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_review_email_enabled',
				array(
					'label'       => esc_html__( 'Enable review email', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the plugin schedules a one-shot WP-Cron event 24h after an order is marked completed. Default OFF so a fresh install never silently emails customers.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_email',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_email_delay( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_email_delay_hours',
				array(
					'default'           => 24,
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_delay_hours' ),
				)
			);
			$wp_customize->add_control(
				'lafka_review_email_delay_hours',
				array(
					'label'       => esc_html__( 'Delay after order completion (hours)', 'lafka-plugin' ),
					'description' => esc_html__( 'How long to wait after the order status flips to "completed" before sending the review-prompt email. 24h is a sweet spot — long enough for the customer to have actually eaten / consumed the order, short enough that the experience is fresh.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_email',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => 1,
						'max'  => 336,
						'step' => 1,
					),
				)
			);
		}

		private static function register_email_subject( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_email_subject',
				array(
					'default'           => 'How was your order, {firstname}?',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_review_email_subject',
				array(
					'label'       => esc_html__( 'Email subject', 'lafka-plugin' ),
					'description' => esc_html__( 'Tokens supported: {firstname} (customer\'s billing first name, falls back to "there") and {site} (your site title).', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_email',
					'type'        => 'text',
				)
			);
		}

		private static function register_email_intro( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_email_intro',
				array(
					'default'           => 'We hope you enjoyed every bite. A quick rating goes a long way for a small spot like ours.',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			);
			$wp_customize->add_control(
				'lafka_review_email_intro',
				array(
					'label'       => esc_html__( 'Intro paragraph', 'lafka-plugin' ),
					'description' => esc_html__( 'Short paragraph that appears above the 5-star tap row.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_email',
					'type'        => 'textarea',
				)
			);
		}

		// ─────────────────────────────────────────────────────────────────────
		// Review-target settings (shared)
		// ─────────────────────────────────────────────────────────────────────

		private static function register_target_url( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_target_url',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_review_url' ),
				)
			);
			$wp_customize->add_control(
				'lafka_review_target_url',
				array(
					'label'       => esc_html__( 'Review URL', 'lafka-plugin' ),
					'description' => esc_html__( 'Paste the operator\'s Google Business Profile review URL (or Yelp / TripAdvisor / any other public review form). Leave empty to disable both the email star row and the banner CTA — they\'ll point at an on-site fallback (your WooCommerce product reviews).', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_target',
					'type'        => 'url',
				)
			);
		}

		private static function register_target_label( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_target_label',
				array(
					'default'           => 'Leave a Google review',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_review_target_label',
				array(
					'label'       => esc_html__( 'Email CTA label (below stars)', 'lafka-plugin' ),
					'description' => esc_html__( 'Text link beneath the 5-star tap row that lets customers leave a longer review.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_target',
					'type'        => 'text',
				)
			);
		}

		// ─────────────────────────────────────────────────────────────────────
		// Banner-channel settings
		// ─────────────────────────────────────────────────────────────────────

		private static function register_banner_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_banner_enabled',
				array(
					'default'           => '0',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_review_banner_enabled',
				array(
					'label'       => esc_html__( 'Enable on-site banner', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, logged-in customers who completed an order within the window below see a small banner in the bottom-right when they revisit the site. Default OFF.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_banner',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_banner_window( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_banner_window_days',
				array(
					'default'           => 7,
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_window_days' ),
				)
			);
			$wp_customize->add_control(
				'lafka_review_banner_window_days',
				array(
					'label'       => esc_html__( 'Banner window (days after order)', 'lafka-plugin' ),
					'description' => esc_html__( 'How many days after a customer\'s most recent completed order the banner remains visible on their next visit. 7 days is the default — long enough to catch return browsers, short enough that the prompt feels current.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_banner',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => 1,
						'max'  => 30,
						'step' => 1,
					),
				)
			);
		}

		private static function register_banner_copy( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_banner_copy',
				array(
					'default'           => 'Loved your order? Tap to rate us',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_review_banner_copy',
				array(
					'label'       => esc_html__( 'Banner copy', 'lafka-plugin' ),
					'description' => esc_html__( 'Single-line headline shown on the banner. Keep short — banner is 320px wide.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_banner',
					'type'        => 'text',
				)
			);
		}

		private static function register_banner_cta_label( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_review_banner_cta_label',
				array(
					'default'           => 'Leave a review →',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_review_banner_cta_label',
				array(
					'label'       => esc_html__( 'Banner CTA label', 'lafka-plugin' ),
					'description' => esc_html__( 'Primary button on the banner. Links to the Review URL above when set, otherwise to the most recent product\'s reviews tab.', 'lafka-plugin' ),
					'section'     => 'lafka_reviews_banner',
					'type'        => 'text',
				)
			);
		}
	}

	Lafka_Customizer_Reviews::init();
}
