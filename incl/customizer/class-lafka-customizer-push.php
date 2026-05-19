<?php
/**
 * Phase 3E (v9.29.0): Customizer panel "Lafka - Push notifications".
 *
 * Single source of operator-configurable values for the Web Push module:
 *
 *   - Master enable toggle (default OFF - operator opts in)
 *   - VAPID public + private key (base64url; private rendered as password)
 *   - VAPID subject (mailto:operator@site - RFC 8292 requirement)
 *   - Subscribe-prompt toggle + page-views threshold + copy
 *   - Reorder reminder toggle + days
 *
 * All settings are theme_mods (consistent with the rest of the Lafka
 * Customizer surface - analytics, PDP, abandoned-cart, reviews). Every
 * setting has a `sanitize_callback` so untrusted Customizer payloads can't
 * reach the DB.
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
					'description' => esc_html__( 'Web Push notifications - browser-native alerts customers receive even when the site is closed. Generate a VAPID keypair (operator one-time cost), paste it below, and flip the master toggle on. Disabled by default so a fresh install never silently prompts customers.', 'lafka-plugin' ),
					'priority'    => 36,
				)
			);

			$wp_customize->add_section(
				'lafka_push_main',
				array(
					'title'       => esc_html__( 'VAPID + master toggle', 'lafka-plugin' ),
					'description' => esc_html__( 'Generate a VAPID keypair once (e.g. via npx web-push generate-vapid-keys or vapidkeys.com). Public key is shown to subscribers; private key signs outbound pushes. Both must be base64url-encoded.', 'lafka-plugin' ),
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
			self::register_vapid_public( $wp_customize );
			self::register_vapid_private( $wp_customize );
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
		 * Sanitize a VAPID public key - base64url, ~88 chars (65 bytes encoded).
		 */
		public static function sanitize_vapid_public( $value ): string {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{80,100}=*$/', $value ) ) {
				return '';
			}
			return $value;
		}

		/**
		 * Sanitize a VAPID private key - base64url, ~43 chars (32 bytes encoded).
		 */
		public static function sanitize_vapid_private( $value ): string {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return '';
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-]{40,60}=*$/', $value ) ) {
				return '';
			}
			return $value;
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

		private static function register_vapid_public( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_vapid_public_key',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_vapid_public' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_vapid_public_key',
				array(
					'label'       => esc_html__( 'VAPID public key', 'lafka-plugin' ),
					'description' => esc_html__( 'base64url-encoded P-256 public key (~88 chars). Used as applicationServerKey when customers subscribe.', 'lafka-plugin' ),
					'section'     => 'lafka_push_main',
					'type'        => 'text',
				)
			);
		}

		private static function register_vapid_private( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_vapid_private_key',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_vapid_private' ),
				)
			);
			$wp_customize->add_control(
				'lafka_push_vapid_private_key',
				array(
					'label'       => esc_html__( 'VAPID private key', 'lafka-plugin' ),
					'description' => esc_html__( 'base64url-encoded 32-byte private key (~43 chars). Treat as a secret - never share. Used to sign the VAPID JWT on every push. SECURITY NOTE: anyone with the edit_theme_options capability can read this field. For stronger isolation on multi-admin sites, define LAFKA_PUSH_VAPID_PRIVATE_KEY (and optionally LAFKA_PUSH_VAPID_PUBLIC_KEY + LAFKA_PUSH_VAPID_SUBJECT) as constants in wp-config.php — the constant takes precedence over the theme_mod and keeps the key out of the database entirely.', 'lafka-plugin' ),
					'section'     => 'lafka_push_main',
					'type'        => 'password',
				)
			);
		}

		private static function register_vapid_subject( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_push_vapid_subject',
				array(
					'default'           => 'mailto:operator@site.com',
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
