<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Nutrition_Config {
	public static $nutrition_meta_fields = array();
}

Lafka_Nutrition_Config::$nutrition_meta_fields = array(
	'lafka_nutrition_energy'      => array(
		'label'                 => esc_html__( 'Energy (Calories)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Energy', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'Cal', 'lafka-plugin' ),
		'placeholder'           => '650',
		'DI'                    => 2000,
	),
	'lafka_nutrition_protein'     => array(
		'label'                 => esc_html__( 'Protein (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Protein', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '28.3',
		'DI'                    => 50,
	),
	'lafka_nutrition_fat'         => array(
		'label'                 => esc_html__( 'Fat (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Fat', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '39.3',
		'DI'                    => 78,
	),
	'lafka_nutrition_satfat'      => array(
		'label'                 => esc_html__( 'Saturated Fat (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Sat Fat', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '11.7',
		'DI'                    => 20,
	),
	'lafka_nutrition_cholesterol' => array(
		'label'                 => esc_html__( 'Cholesterol (mg)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Cholest.', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'mg', 'lafka-plugin' ),
		'placeholder'           => '30.0',
		'DI'                    => 300,
	),
	'lafka_nutrition_carbs'       => array(
		'label'                 => esc_html__( 'Carbohydrates (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Carbs', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '47.9',
		'DI'                    => 275,
	),
	'lafka_nutrition_sugars'      => array(
		'label'                 => esc_html__( 'Sugars (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Sugars', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '8.0',
		'DI'                    => 50,
	),
	'lafka_nutrition_sodium'      => array(
		'label'                 => esc_html__( 'Sodium (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Sodium', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '0.8',
		'DI'                    => 2.3,
	),
	'lafka_nutrition_fibre'       => array(
		'label'                 => esc_html__( 'Fibre (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Fibre', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '5.0',
		'DI'                    => 30,
	),
	'lafka_nutrition_salt'        => array(
		'label'                 => esc_html__( 'Salt (Grams)', 'lafka-plugin' ),
		'frontend_label'        => esc_html__( 'Salt', 'lafka-plugin' ),
		'frontend_label_weight' => esc_html__( 'g', 'lafka-plugin' ),
		'placeholder'           => '0.46',
		'DI'                    => 5,
	),
);
