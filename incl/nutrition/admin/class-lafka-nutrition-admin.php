<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Nutrition_Admin {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ), 100 );
		add_action( 'woocommerce_product_write_panel_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'process_meta_box' ), 1 );
	}

	/**
	 * Enqueue styles.
	 */
	public function styles() {
		wp_enqueue_style( 'lafka_nutrition_admin_css', plugins_url( '../assets/css/admin.css', __FILE__ ), array( 'woocommerce_admin_styles' ) );
	}

	/**
	 * Add product tab.
	 */
	public function tab() {
		?>
		<li class="lafka-nutrition-tab product-nutrition">
			<a href="#lafka_product_nutrition_data"><span><?php esc_html_e( 'Lafka Nutrition Facts', 'lafka-plugin' ); ?></span></a>
		</li>
		<?php
	}

	/**
	 * Add product panel.
	 */
	public function panel() {
		global $post;
		$product = wc_get_product( $post );

		foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $data ) {
			${$field_name} = $product->get_meta( '_' . $field_name );
		}
		$lafka_product_allergens = $product->get_meta( '_lafka_product_allergens' );

		include __DIR__ . '/views/html-nutrition-panel.php';
	}

	/**
	 * Process meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_meta_box( $post_id ) {
		$product = wc_get_product( $post_id );

		foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $data ) {
			if ( isset( $_POST[ '_' . $field_name ] ) ) {
				$product->update_meta_data( '_' . $field_name, $_POST[ '_' . $field_name ] );
			} else {
				$product->update_meta_data( '_' . $field_name, '' );
			}
		}

		$product->update_meta_data( '_lafka_product_allergens', sanitize_text_field( $_POST['_lafka_product_allergens'] ) );

		$product->save();
	}
}