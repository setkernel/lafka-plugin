<?php
/**
 * Adds options to the customizer for Lafka.
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Customizer {
	function __construct() {
		add_action( 'customize_register', array( $this, 'add_sections' ) );
	}

	/**
	 * Add settings to the customizer.
	 *
	 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
	 */
	public function add_sections( $wp_customize ) {
		$wp_customize->add_panel( 'lafka_plugin', array(
			'priority'       => 200,
			'capability'     => 'edit_theme_options',
			'theme_supports' => '',
			'title'          => esc_html__( 'Lafka Options', 'lafka-plugin' ),
		) );

		$this->add_social_share_section( $wp_customize );
	}

	/**
	 * Social share links section.
	 *
	 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
	 */
	public function add_social_share_section( $wp_customize ) {
		$wp_customize->add_section(
			'lafka_social_share',
			array(
				'title'       => esc_html__( 'Social Share Links', 'lafka-plugin' ),
				'description' => esc_html__( 'Configure globally the social networks share links. They can be overridden for each post, page or portfolio on the edit page.', 'lafka-plugin' ),
				'priority'    => 10,
				'panel'       => 'lafka_plugin',
			)
		);

		$wp_customize->add_setting(
			'lafka_share_on_posts',
			array(
				'default'           => 'no',
				'type'              => 'option',
				'capability'        => 'edit_theme_options',
				'sanitize_callback' => array( $this, 'lafka_bool_to_string' ),
				'sanitize_js_callback' => array( $this, 'lafka_string_to_bool' )
			)
		);

		$wp_customize->add_control(
			'lafka_share_on_posts_field',
			array(
				'label'    => esc_html__( 'Enable social share links on single post, page and portfolio.', 'lafka-plugin' ),
				'section'  => 'lafka_social_share',
				'settings' => 'lafka_share_on_posts',
				'type'     => 'checkbox'
			)
		);

		if ( defined( 'LAFKA_PLUGIN_IS_WOOCOMMERCE' ) && LAFKA_PLUGIN_IS_WOOCOMMERCE ) {
			$wp_customize->add_setting(
				'lafka_share_on_products',
				array(
					'default'           => 'no',
					'type'              => 'option',
					'capability'        => 'edit_theme_options',
					'sanitize_callback' => array( $this, 'lafka_bool_to_string' ),
					'sanitize_js_callback' => array( $this, 'lafka_string_to_bool' )
				)
			);

			$wp_customize->add_control(
				'lafka_share_on_products_field',
				array(
					'label'    => esc_html__( 'Enable social share links on single product pages.', 'lafka-plugin' ),
					'section'  => 'lafka_social_share',
					'settings' => 'lafka_share_on_products',
					'type'     => 'checkbox'
				)
			);
		}
	}

	public function lafka_bool_to_string( $bool ) {
		if ( ! is_bool( $bool ) ) {
			$bool = $this->lafka_string_to_bool($bool);
		}
		return true === $bool ? 'yes' : 'no';
	}

	public function lafka_string_to_bool( $string ) {
		return is_bool( $string ) ? $string : ( 'yes' === $string || 1 === $string || 'true' === $string || '1' === $string );
	}
}

new Lafka_Customizer();