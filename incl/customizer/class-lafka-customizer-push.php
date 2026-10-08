<?php
/**
 * Phase 3E (v9.29.0): Customizer panel "Lafka - Push notifications".
 *
 * Single source of operator-configurable values for the Web Push module:
 *
 *   - Master enable toggle (default OFF - operator opts in)
 *   - VAPID subject (mailto:operator@site - RFC 8292 requirement). The VAPID
 *     keypair is created by the plugin (see lafka_push_ensure_vapid_keys());
 *     the private key is never in the Customizer.
 *   - Subscribe-prompt toggle + page-views threshold + copy
 *   - Reorder reminder toggle + days
 *
 * All settings are plugin options (type 'option'), so they survive a theme
 * switch. Every setting has a `sanitize_callback` so untrusted Customizer
 * payloads can't reach the DB.
 *
 * @package Lafka\Plugin\Customizer
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Push' ) ) {

	/**
	 * Registers the "Lafka - Push notifications" Customizer panel.
	 */
	final class Lafka_Customizer_Push {

		/**
		 * Hook into customize_register.
		 */
		public static function init(): void {
			add_action( 'customize_register', array( __CLASS__, 'register' ) );
		}

		/**
		 * Register panel + sections + settings + controls.
		 *
		 * @param WP_Customize_Manager $wp_customize
		 */
		public static function register( $wp_customize ): void {
			$wp_customize->add_panel(
				'lafka_push',
				array(
					'title'       => esc_html__( 'Lafka - Push notifications', 'lafka-plugin' ),
					'description' => esc_html__( 'Web Push notifications - browser-native alerts customers receive even when the site is closed. Flip the master toggle on; the signing keys are created for you. Disabled by default so a fresh install never silently prompts customers.', 'lafka-plugin' ),
					'priority'    => 36,
				)
			);

			$wp_customize->add_section(
				'lafka_push_main',
				array(
					'title'       => esc_html__( 'Master toggle and contact', 'lafka-plugin' ),
					'description' => esc_html__( 'The signing keys (VAPID) are created and kept on the server automatically; the private key is never shown here. To create new keys, use WooCommerce → Push notifications.', 'lafka-plugin' ),
					'panel'       => 'lafka_push',
					'priority'    => 10,
				)
			);
			$wp_customize->add_section(
				'lafka_push_prompt',
				array(
					'title'       => esc_html__( 'Subscribe prompt', 'lafka-plugin' ),
					'description' => esc_html__( 'Custom in-page prompt shown after N page views in the session. Browsers cap permission prompts - we only trigger the native dialog after the customer clicks Accept on this card.', 'lafka-plugin' ),
					'panel'       => 'lafka_push',
					'priority'    => 20,
				)
			);
			$wp_customize->add_section(
				'lafka_push_reorder',
				array(
					'title'       => esc_html__( 'Reorder reminder', 'lafka-plugin' ),
					'description' => esc_html__( 'Daily cron sends "Your usual? Tap to reorder" to customers whose last completed order was N days ago.', 'lafka-plugin' ),
					'panel'       => 'lafka_push',
					'priority'    => 30,
				)
			);

			self::register_enabled( $wp_customize );
			self::register_vapid_subject( $wp_customize );

			self::register_subscribe_prompt_enabled( $wp_customize );
			self::register_subscribe_prompt_threshold( $wp_customize );
			self::register_subscribe_prompt_copy( $wp_customize );

			self::register_reorder_enabled( $wp_customize );
			self::register_reorder_days( $wp_customize );
		}

		// -------------------------------------------------------------------
		// Sanitizers
		// -------------------------------------------------------------------

		/**
		 * Coerce checkbox-style input to '0' or '1'.
		 */
		public static function sanitize_checkbox( $value ): string {
			return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
		}

		/**
		 * Sanitize the VAPID subject - must be a mailto: or https: URL.
		 */
		public static function sanitize_vapid_subject( $value ): string {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			if ( 0 === stripos( $value, 'mailto:' ) ) {
				$email = substr( $value, 7 );
				if ( function_exists( 'is_email' ) && is_email( $email ) ) {
					return 'mailto:' . strtolower( $email );
				}
				return '';
			}
			if ( preg_match( '#^https://#i', $value ) ) {
				return esc_url_raw( $value );
			}
			return '';
		}

		/**
		 * Clamp prompt-threshold pageviews to [1, 10].
		 */
		public static function sanitize_prompt_threshold( $value ): int {
			$value = is_scalar( $value ) ? (int) $value : 2;
			return max( 1, min( 10, $value ) );
		}

		/**
		 * Clamp reorder reminder days to [3, 90].
		 */
		public static function sanitize_reorder_days( $value ): int {
			$value = is_scalar( $value ) ? (int) $value : 14;
			return max( 3, min( 90, $value ) );
		}

		// -------------------------------------------------------------------
		// Settings + controls
		// -------------------------------------------------------------------

		private static function register_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_enabled',
				array(
					'type'              => 'option',
					'default'           => '0',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_enabled',
				array(
					'label'       => esc_html__( 'Enable Web Push', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the plugin exposes /push REST routes and the theme renders the subscribe prompt. Default OFF.', 'lafka-plugin' ),
					'section'     => 'lafka_push_main',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_vapid_subject( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_vapid_subject',
				array(
					'type'              => 'option',
					'default'           => '', // Empty = the site admin email (lafka_push_default_vapid_subject()).
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_vapid_subject' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_vapid_subject',
				array(
					'label'       => esc_html__( 'VAPID subject (contact)', 'lafka-plugin' ),
					'description' => esc_html__( 'mailto: address (or https URL) push services contact if there is abuse. RFC 8292 requires a real contact - blank or fake values may be rejected.', 'lafka-plugin' ),
					'section'     => 'lafka_push_main',
					'type'        => 'text',
				)
			);
		}

		private static function register_subscribe_prompt_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_subscribe_prompt_enabled',
				array(
					'type'              => 'option',
					'default'           => '1',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_subscribe_prompt_enabled',
				array(
					'label'       => esc_html__( 'Show subscribe prompt', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the theme renders the custom in-page prompt after the page-view threshold. Default ON when master toggle is ON.', 'lafka-plugin' ),
					'section'     => 'lafka_push_prompt',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_subscribe_prompt_threshold( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_subscribe_prompt_threshold',
				array(
					'type'              => 'option',
					'default'           => 2,
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_prompt_threshold' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_subscribe_prompt_threshold',
				array(
					'label'       => esc_html__( 'Show after N page views', 'lafka-plugin' ),
					'description' => esc_html__( 'Number of pages the customer must visit in the current session before the prompt appears. 1 = first page; 2 = second page; etc.', 'lafka-plugin' ),
					'section'     => 'lafka_push_prompt',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => 1,
						'max'  => 10,
						'step' => 1,
					),
				)
			);
		}

		private static function register_subscribe_prompt_copy( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_subscribe_prompt_copy',
				array(
					'type'              => 'option',
					'default'           => 'Want occasional treats? We send 1-2 notifications a week max - never spam.',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			);
			$wp_customize->add_control(
				'lafka_push_subscribe_prompt_copy',
				array(
					'label'       => esc_html__( 'Subscribe prompt copy', 'lafka-plugin' ),
					'description' => esc_html__( 'The friendly body text inside the subscribe card. Keep it short and value-led - browsers permanently block the site if customers reject the native dialog.', 'lafka-plugin' ),
					'section'     => 'lafka_push_prompt',
					'type'        => 'textarea',
				)
			);
		}

		private static function register_reorder_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_reorder_reminder_enabled',
				array(
					'type'              => 'option',
					'default'           => '0',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_reorder_reminder_enabled',
				array(
					'label'       => esc_html__( 'Enable reorder reminder', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the daily cron sends a "Your usual?" push to customers whose last completed order was N days ago.', 'lafka-plugin' ),
					'section'     => 'lafka_push_reorder',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_reorder_days( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_reorder_reminder_days',
				array(
					'type'              => 'option',
					'default'           => 14,
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_reorder_days' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_reorder_reminder_days',
				array(
					'label'       => esc_html__( 'Days since last order', 'lafka-plugin' ),
					'description' => esc_html__( 'Trigger the reminder when the customer\'s most recent completed order was exactly this many days ago (+/- 1 day tolerance).', 'lafka-plugin' ),
					'section'     => 'lafka_push_reorder',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => 3,
						'max'  => 90,
						'step' => 1,
					),
				)
			);
		}
	}

	Lafka_Customizer_Push::init();
}
