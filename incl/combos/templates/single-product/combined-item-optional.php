<?php
/**
 * Optional Combined Item Checkbox template
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/combined-item-optional.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 6.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><label class="combined_product_optional_checkbox">
	<input class="combined_product_checkbox" type="checkbox" name="<?php echo $combo_fields_prefix; ?>combo_selected_optional_<?php echo $combined_item->get_id(); ?>" value="" <?php checked( $combined_item->is_optional_checked() && $combined_item->is_in_stock(), true ); echo $combined_item->is_in_stock() ? '' : 'disabled="disabled"' ; ?> /> <?php
	echo sprintf( __( 'Add%1$s%2$s%3$s', 'lafka-plugin' ), $label_title, $label_price, '' );
?></label><?php

if ( $availability_html ) {
	echo $availability_html;
}
