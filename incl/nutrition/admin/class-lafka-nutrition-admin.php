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
	 * Enqueue styles — product-edit screen only (the panel this styles lives
	 * there; mirrors the screen-scoped enqueues in Lafka_Order_Hours_Admin).
	 */
	public function styles() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( ! $screen || 'product' !== $screen->id ) {
				return;
			}
		}
		wp_enqueue_style( 'lafka_nutrition_admin_css', plugins_url( '../assets/css/admin.css', __FILE__ ), array( 'woocommerce_admin_styles' ), lafka_plugin_asset_version( 'incl/nutrition/assets/css/admin.css' ) );
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
	 * Process meta box on product save.
	 *
	 * Hooked to `woocommerce_process_product_meta` which fires for every
	 * product save WC processes — including REST API writes, Quick Edit,
	 * bulk-edit, programmatic `wp_update_post` calls, and third-party plugins
	 * that trigger product saves. Pre-v9.7.13 this loop blindly overwrote
	 * each nutrition field with `''` when the corresponding `$_POST[]` key
	 * was missing, silently wiping operator-entered nutrition + allergen
	 * data on every non-panel save. A single Quick Edit destroyed an entire
	 * product's nutrition.
	 *
	 * Now gated on a hidden marker (`_lafka_nutrition_panel_present`) the
	 * panel template emits when rendered. Only saves that include the marker
	 * proceed to overwrite — so out-of-band saves leave existing meta
	 * untouched.
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_meta_box( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// WC's product-edit screen supplies the nonce verified by the WC
		// product save flow before this hook fires; we rely on that gate.
		if ( ! isset( $_POST['_lafka_nutrition_panel_present'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $data ) {
			if ( isset( $_POST[ '_' . $field_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$product->update_meta_data( '_' . $field_name, sanitize_text_field( wp_unslash( $_POST[ '_' . $field_name ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} else {
				$product->update_meta_data( '_' . $field_name, '' );
			}
		}

		$product->update_meta_data(
			'_lafka_product_allergens',
			sanitize_text_field( wp_unslash( $_POST['_lafka_product_allergens'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		$product->save();
	}
}