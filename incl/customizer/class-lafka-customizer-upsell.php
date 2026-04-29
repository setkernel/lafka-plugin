<?php
/**
 * Upsell row Customizer panel — per category, 4 product picks each.
 *
 * @package Lafka\Plugin\Customizer
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Customizer_Upsell' ) ) {

    final class Lafka_Customizer_Upsell {

        public static function init(): void {
            add_action( 'customize_register', array( __CLASS__, 'register' ) );
        }

        public static function register( $wp_customize ): void {
            $wp_customize->add_panel( 'lafka_upsell', array(
                'title'    => esc_html__( 'Lafka — Upsell rows', 'lafka-plugin' ),
                'priority' => 153,
            ) );

            $cats = get_terms( array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'parent'     => 0,
            ) );
            if ( is_wp_error( $cats ) || empty( $cats ) ) {
                return;
            }

            foreach ( $cats as $term ) {
                $section_id = 'lafka_upsell_' . sanitize_key( $term->slug );
                $wp_customize->add_section( $section_id, array(
                    'title'       => sprintf( __( 'Upsells: %s', 'lafka-plugin' ), $term->name ),
                    'description' => esc_html__( 'Pick 4 products to show as "Make it a meal" on PDPs in this category.', 'lafka-plugin' ),
                    'panel'       => 'lafka_upsell',
                ) );

                for ( $i = 1; $i <= 4; $i++ ) {
                    $setting_id = 'lafka_upsell_' . sanitize_key( $term->slug ) . '_' . $i;
                    $wp_customize->add_setting( $setting_id, array(
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                        'transport'         => 'refresh',
                    ) );
                    $wp_customize->add_control( $setting_id, array(
                        'label'   => sprintf( __( 'Slot %d (product ID)', 'lafka-plugin' ), $i ),
                        'section' => $section_id,
                        'type'    => 'number',
                        'input_attrs' => array( 'min' => 0, 'step' => 1 ),
                    ) );
                }
            }
        }
    }

    Lafka_Customizer_Upsell::init();
}
