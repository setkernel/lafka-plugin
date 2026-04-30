<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Nutrition_Config {
	/**
	 * @var array Nutrition meta fields (lazy-initialized with translations).
	 */
	public static $nutrition_meta_fields = array();

	/**
	 * @var bool Whether the fields have been initialized.
	 */
	private static $initialized = false;

	/**
	 * Initialize nutrition meta fields with translated labels.
	 * Called on 'init' to ensure textdomain is loaded.
	 *
	 * The default DI (Daily Intake) values follow US FDA "% Daily Value"
	 * targets calibrated to a 2000-calorie diet. EU/UK/Canada/etc. use
	 * different reference intakes — operators in those markets override
	 * the entire field map (or a subset) via the `lafka_nutrition_meta_fields`
	 * filter rather than forking the plugin.
	 *
	 * Filter signature:
	 *   apply_filters( 'lafka_nutrition_meta_fields', array $defaults ) → array
	 *
	 * Each field is keyed by its meta-key suffix (e.g. `lafka_nutrition_protein`)
	 * and contains: label, frontend_label, frontend_label_weight, placeholder, DI.
	 */
	public static function init_fields() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		$defaults = array(
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

		/**
		 * Filter the nutrition meta fields map.
		 *
		 * Use to override DI targets for non-US markets, change placeholder
		 * values, or add new fields (e.g. omega-3, calcium). Each field must
		 * keep the keys: label, frontend_label, frontend_label_weight,
		 * placeholder, DI.
		 *
		 * @since 9.7.14
		 * @param array<string, array<string, mixed>> $defaults FDA-calibrated defaults.
		 */
		self::$nutrition_meta_fields = (array) apply_filters( 'lafka_nutrition_meta_fields', $defaults );
	}
}

add_action( 'init', array( 'Lafka_Nutrition_Config', 'init_fields' ), 0 );
