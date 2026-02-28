<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="product_addons_data" class="panel woocommerce_options_panel wc-metaboxes-wrapper">
	<?php do_action( 'lafka-product-addons_panel_start' ); ?>

	<p class="lafka-product-add-ons-toolbar lafka-product-add-ons-toolbar--open-close toolbar">
		<a href="#" class="close_all"><?php esc_html_e( 'Close all', 'lafka-plugin' ); ?></a> / <a href="#" class="expand_all"><?php esc_html_e( 'Expand all', 'lafka-plugin' ); ?></a>
	</p>

	<div class="lafka_product_addons wc-metaboxes">

		<?php
			$loop = 0;

			foreach ( $product_addons as $addon ) {
				include( dirname( __FILE__ ) . '/html-addon.php' );

				$loop++;
			}
		?>

	</div>

	<div class="lafka-product-add-ons-toolbar lafka-product-add-ons-toolbar--add-import-export toolbar">
		<button type="button" class="button add_new_addon"><?php esc_html_e( 'New add-on', 'lafka-plugin' ); ?></button>
	</div>
	<?php if ( $exists ) : ?>
		<div class="options_group">
			<p class="form-field">
			<label for="_product_addons_exclude_global"><?php esc_html_e( 'Global Addon Exclusion', 'lafka-plugin' ); ?></label>
			<input id="_product_addons_exclude_global" name="_product_addons_exclude_global" class="checkbox" type="checkbox" value="1" <?php checked( $exclude_global, 1 ); ?>/><span class="description"><?php esc_html_e( 'Check this to exclude this product from all Global Addons', 'lafka-plugin' ); ?></span>
			</p>
		</div>
	<?php endif; ?>
</div>

