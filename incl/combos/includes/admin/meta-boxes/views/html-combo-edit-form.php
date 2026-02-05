<?php
/**
 * Combined order item
 *
 * @var object $item The item being displayed
 * @var int $item_id The id of the item being displayed
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<table cellspacing="0" class="combined_products">
	<thead>
		<th class="combined_item_col combined_item_images_head"></th>
		<th class="combined_item_col combined_item_details_head"><?php _e( 'Product', 'lafka-plugin' ); ?></th>
		<th class="combined_item_col combined_item_qty_head"><?php _e( 'Quantity', 'lafka-plugin' ); ?></th>
	</thead><?php

	foreach ( $combined_items as $combined_item ) {
		do_action( 'woocommerce_combined_item_details', $combined_item, $product );
	}

	?></tbody>
</table>
