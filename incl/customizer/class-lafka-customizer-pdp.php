<?php
/**
 * PDP Redesign Customizer panel.
 *
 * Master toggles:
 *   - lafka_pdp_redesign_enabled       (yes|no)  default yes — feature flag
 *   - lafka_pdp_show_bestseller_eyebrow (yes|no) default yes — eyebrow on top-3 sellers
 *   - lafka_pdp_prep_time_default      (int)     default 25  — fallback "Ready in X min"
 *
 * @package Lafka\Plugin\Customizer
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_PDP' ) ) {

    final class Lafka_Customizer_PDP {

        public static function init(): void {
            add_action( 'customize_register', array( __CLASS__, 'register' ) );
        }

        public static function register( $wp_customize ): void {
            $wp_customize->add_section( 'lafka_pdp', array(
                'title'    => esc_html__( 'Lafka — PDP Redesign', 'lafka-plugin' ),
                'priority' => 152,
            ) );

            $wp_customize->add_setting( 'lafka_pdp_redesign_enabled', array(
                'default'           => 'yes',
                'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
                'transport'         => 'refresh',
            ) );
            $wp_customize->add_control( 'lafka_pdp_redesign_enabled', array(
                'label'       => esc_html__( 'Enable redesigned PDP', 'lafka-plugin' ),
                'description' => esc_html__( 'Toggle off for instant rollback to the legacy PDP template.', 'lafka-plugin' ),
                'section'     => 'lafka_pdp',
                'type'        => 'select',
                'choices'     => array( 'yes' => 'Yes', 'no' => 'No' ),
            ) );

            $wp_customize->add_setting( 'lafka_pdp_show_bestseller_eyebrow', array(
                'default'           => 'yes',
                'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
                'transport'         => 'refresh',
            ) );
            $wp_customize->add_control( 'lafka_pdp_show_bestseller_eyebrow', array(
                'label'   => esc_html__( 'Show "★ Best Seller" eyebrow on top-3 products', 'lafka-plugin' ),
                'section' => 'lafka_pdp',
                'type'    => 'select',
                'choices' => array( 'yes' => 'Yes', 'no' => 'No' ),
            ) );

            $wp_customize->add_setting( 'lafka_pdp_prep_time_default', array(
                'default'           => 25,
                'sanitize_callback' => 'absint',
                'transport'         => 'refresh',
            ) );
            $wp_customize->add_control( 'lafka_pdp_prep_time_default', array(
                'label'       => esc_html__( 'Default prep time (minutes)', 'lafka-plugin' ),
                'description' => esc_html__( 'Used in "Ready in X min" trust signal when no per-category override is set.', 'lafka-plugin' ),
                'section'     => 'lafka_pdp',
                'type'        => 'number',
                'input_attrs' => array( 'min' => 0, 'max' => 180, 'step' => 1 ),
            ) );
        }

        public static function sanitize_yes_no( $value ): string {
            return in_array( $value, array( 'yes', 'no' ), true ) ? $value : 'no';
        }
    }

    Lafka_Customizer_PDP::init();
}

if ( ! function_exists( 'lafka_pdp_redesign_enabled' ) ) {
    function lafka_pdp_redesign_enabled(): bool {
        if ( ! function_exists( 'get_theme_mod' ) ) {
            return true;
        }
        return 'no' !== get_theme_mod( 'lafka_pdp_redesign_enabled', 'yes' );
    }
}
