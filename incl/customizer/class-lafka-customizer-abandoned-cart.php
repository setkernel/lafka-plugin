<?php
/**
 * Phase 3B (v9.27.0): Customizer panel "Lafka — Abandoned cart recovery".
 *
 * Single source of operator-configurable values for the abandoned-cart engine:
 *   - Master enable toggle (default OFF — operator opts in)
 *   - Delay before a cart is considered abandoned (minutes)
 *   - Subject + heading + body + CTA label (full email copy override)
 *   - Global opt-out list (textarea of emails to never send to)
 *
 * All settings are theme_mods (consistent with the rest of the Lafka
 * Customizer surface — analytics, PDP, restaurant info). Read by the
 * capture/cron/email layers in incl/conversion/.
 *
 * Every setting has a `sanitize_callback` so untrusted Customizer payloads
 * can't reach the DB.
 *
 * @package Lafka\Plugin\Customizer
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Abandoned_Cart' ) ) {

	/**
	 * Registers the "Lafka — Abandoned cart recovery" Customizer panel.
	 */
	final class Lafka_Customizer_Abandoned_Cart {

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
				'lafka_abandoned_cart',
				array(
					'title'       => esc_html__( 'Lafka — Abandoned cart recovery', 'lafka-plugin' ),
					'description' => esc_html__( 'When a customer enters their email at checkout but doesn\'t complete the order within the delay window, the plugin sends ONE recovery email with their cart contents + a one-click resume link. Disabled by default — flip the toggle below to opt in.', 'lafka-plugin' ),
					'priority'    => 34,
				)
			);

			$wp_customize->add_section(
				'lafka_abandoned_cart_recovery',
				array(
					'title'       => esc_html__( 'Recovery email', 'lafka-plugin' ),
					'description' => esc_html__( 'Operator controls for the abandoned-cart recovery email. All copy is overridable from this panel; defaults ship in English and are translatable via the plugin\'s text domain.', 'lafka-plugin' ),
					'panel'       => 'lafka_abandoned_cart',
					'priority'    => 10,
				)
			);

			self::register_enabled( $wp_customize );
			self::register_delay( $wp_customize );
			self::register_subject( $wp_customize );
			self::register_intro_heading( $wp_customize );
			self::register_intro_body( $wp_customize );
			self::register_cta_label( $wp_customize );
			self::register_global_opt_out( $wp_customize );
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
		 * Clamp delay minutes to [5, 1440] (5 minutes — 24 hours).
		 *
		 * @param mixed $value
		 * @return int
		 */
		public static function sanitize_delay_minutes( $value ): int {
			$value = is_scalar( $value ) ? (int) $value : 75;
			return max( 5, min( 1440, $value ) );
		}

		/**
		 * Sanitize the email opt-out textarea — keep one email per line, drop
		 * anything that isn't a valid email.
		 *
		 * @param mixed $value
		 * @return string
		 */
		public static function sanitize_opt_out_list( $value ): string {
			if ( ! is_scalar( $value ) ) {
				return '';
			}
			$lines  = preg_split( '/[\s,]+/', (string) $value );
			if ( ! is_array( $lines ) ) {
				return '';
			}
			$valid = array();
			foreach ( $lines as $line ) {
				$line = strtolower( trim( $line ) );
				if ( '' === $line ) {
					continue;
				}
				if ( function_exists( 'is_email' ) ) {
					if ( ! is_email( $line ) ) {
						continue;
					}
				}
				$valid[] = $line;
			}
			return implode( "\n", array_values( array_unique( $valid ) ) );
		}

		// ─────────────────────────────────────────────────────────────────────
		// Settings + controls
		// ─────────────────────────────────────────────────────────────────────

		private static function register_enabled( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_enabled',
				array(
					'default'           => '0',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				'lafka_ac_enabled',
				array(
					'label'       => esc_html__( 'Enable recovery email', 'lafka-plugin' ),
					'description' => esc_html__( 'When ON, the plugin saves every (email, cart) pair entered at /checkout/ and triggers a recovery send through WP-Cron after the delay window. Default OFF so a fresh install never silently emails customers.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'checkbox',
				)
			);
		}

		private static function register_delay( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_delay_minutes',
				array(
					'default'           => 75,
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_delay_minutes' ),
				)
			);
			$wp_customize->add_control(
				'lafka_ac_delay_minutes',
				array(
					'label'       => esc_html__( 'Delay before recovery (minutes)', 'lafka-plugin' ),
					'description' => esc_html__( 'How long the cart must be idle (no order placed) before the recovery email fires. WP-Cron checks the queue every 15 minutes, so the actual send happens within (delay + 15) minutes of abandonment.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'number',
					'input_attrs' => array(
						'min'  => 5,
						'max'  => 1440,
						'step' => 5,
					),
				)
			);
		}

		private static function register_subject( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_subject',
				array(
					'default'           => 'Did you forget something? Your cart is waiting at {site}',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_ac_subject',
				array(
					'label'       => esc_html__( 'Email subject line', 'lafka-plugin' ),
					'description' => esc_html__( 'Subject line shown in the inbox. The token {site} is replaced with your site name at send time.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'text',
				)
			);
		}

		private static function register_intro_heading( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_intro_heading',
				array(
					'default'           => 'Your cart is still here',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_ac_intro_heading',
				array(
					'label'       => esc_html__( 'Email heading', 'lafka-plugin' ),
					'description' => esc_html__( 'Large H2 at the top of the email body, inside the WooCommerce email frame.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'text',
				)
			);
		}

		private static function register_intro_body( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_intro_body',
				array(
					'default'           => 'We saved your selection. Tap below to pick up where you left off.',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			);
			$wp_customize->add_control(
				'lafka_ac_intro_body',
				array(
					'label'       => esc_html__( 'Email intro paragraph', 'lafka-plugin' ),
					'description' => esc_html__( 'Friendly intro between the heading and the cart-items table.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'textarea',
				)
			);
		}

		private static function register_cta_label( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_cta_label',
				array(
					'default'           => 'Resume my order',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				'lafka_ac_cta_label',
				array(
					'label'       => esc_html__( 'CTA button label', 'lafka-plugin' ),
					'description' => esc_html__( 'Text on the red pill button that returns the customer to /cart/.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'text',
				)
			);
		}

		private static function register_global_opt_out( $wp_customize ): void {
			$wp_customize->add_setting(
				'lafka_ac_global_opt_out',
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_opt_out_list' ),
				)
			);
			$wp_customize->add_control(
				'lafka_ac_global_opt_out',
				array(
					'label'       => esc_html__( 'Global opt-out list', 'lafka-plugin' ),
					'description' => esc_html__( 'One email per line. Listed addresses NEVER receive a recovery email — use for staff test accounts, GDPR right-to-be-forgotten requests, or known-bouncing addresses.', 'lafka-plugin' ),
					'section'     => 'lafka_abandoned_cart_recovery',
					'type'        => 'textarea',
				)
			);
		}
	}

	Lafka_Customizer_Abandoned_Cart::init();
}
