<?php
/**
 * Customizer → "Lafka — Checkout": the cart/checkout conversion switches.
 *
 * Every setting ships a working default so an install that never opens the
 * Customizer gets the behaviour; the Customizer only overrides it. Values are
 * theme_mods read by the checkout modules in incl/checkout/.
 *
 * @package Lafka\Plugin\Customizer
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Checkout' ) ) {

	/**
	 * Checkout conversion settings.
	 */
	final class Lafka_Customizer_Checkout {

		/**
		 * Customizer section id.
		 */
		const SECTION = 'lafka_checkout';

		/**
		 * Hook the registration.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'customize_register', array( __CLASS__, 'register' ) );
		}

		/**
		 * Coerce checkbox input to '1' / '0'.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		public static function sanitize_checkbox( $value ): string {
			return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
		}

		/**
		 * Register the section, settings and controls.
		 *
		 * @param WP_Customize_Manager $wp_customize Customizer manager.
		 * @return void
		 */
		public static function register( $wp_customize ): void {
			$wp_customize->add_section(
				self::SECTION,
				array(
					'title'       => esc_html__( 'Lafka — Checkout', 'lafka-plugin' ),
					'description' => esc_html__( 'How delivery prices, pickup checkout and payment options behave in the cart and at checkout.', 'lafka-plugin' ),
					'priority'    => 158,
				)
			);

			self::checkbox(
				$wp_customize,
				'lafka_delivery_quote_guard',
				esc_html__( 'Hide delivery prices until a street address is entered', 'lafka-plugin' ),
				esc_html__( 'Distance-based delivery methods quote from a rough location (the province or state) until the customer enters a street address, which can show a wildly wrong price. When on, delivery options appear once the street address and postcode are known; pickup is always shown.', 'lafka-plugin' )
			);
			self::text(
				$wp_customize,
				'lafka_delivery_quote_guard_message',
				esc_html__( 'Message while delivery prices are hidden', 'lafka-plugin' ),
				esc_html__( 'Leave empty for the default: "Enter your street address to see the delivery cost."', 'lafka-plugin' )
			);
		}

		/**
		 * A default-on checkbox setting + control.
		 *
		 * @param WP_Customize_Manager $wp_customize Customizer manager.
		 * @param string               $id           Theme mod id.
		 * @param string               $label        Control label.
		 * @param string               $description  Control description.
		 * @return void
		 */
		private static function checkbox( $wp_customize, string $id, string $label, string $description ): void {
			$wp_customize->add_setting(
				$id,
				array(
					'default'           => '1',
					'transport'         => 'refresh',
					'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				)
			);
			$wp_customize->add_control(
				$id,
				array(
					'label'       => $label,
					'description' => $description,
					'section'     => self::SECTION,
					'type'        => 'checkbox',
				)
			);
		}

		/**
		 * An optional text override (empty = translatable default).
		 *
		 * @param WP_Customize_Manager $wp_customize Customizer manager.
		 * @param string               $id           Theme mod id.
		 * @param string               $label        Control label.
		 * @param string               $description  Control description.
		 * @return void
		 */
		private static function text( $wp_customize, string $id, string $label, string $description ): void {
			$wp_customize->add_setting(
				$id,
				array(
					'default'           => '',
					'transport'         => 'refresh',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				$id,
				array(
					'label'       => $label,
					'description' => $description,
					'section'     => self::SECTION,
					'type'        => 'text',
				)
			);
		}
	}

	Lafka_Customizer_Checkout::init();
}
