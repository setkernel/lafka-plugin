<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Nutrition {
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
	}

	/**
	 * Initializes classes.
	 */
	public function init_classes() {
		// Admin
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Front-side
		include_once( dirname( __FILE__ ) . '/includes/class-lafka-nutrition-display.php' );

		$GLOBALS['Lafka_Nutrition_Display'] = new Lafka_Nutrition_Display();
	}

	/**
	 * Initializes plugin admin.
	 */
	protected function init_admin() {
		include_once( dirname( __FILE__ ) . '/admin/class-lafka-nutrition-admin.php' );
		$GLOBALS['Lafka_Nutrition_Admin'] = new Lafka_Nutrition_Admin();
	}
}

new Lafka_Nutrition();
