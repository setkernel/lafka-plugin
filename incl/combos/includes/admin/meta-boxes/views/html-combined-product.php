<?php
/**
 * Admin Combined Product view
 */
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><div class="wc-combined-item wc-metabox <?php echo esc_attr( $toggle ); ?> <?php echo esc_attr( $stock_status ); ?>" rel="<?php echo (int) $loop; ?>">
	<h3>
		<span class="combined-item-product-id">#<span class="combined-product-id"><?php echo (int) $product_id; ?></span></span>
		<span class="combined-item-title-inner"><strong class="item-title"><?php echo esc_html( $title ); ?></strong></span>
		<?php
			// HIGH-3: $stock_status is a hardcoded enum, but $stock_status_label
			// is a translated WC string. Escape both for belt-and-suspenders.
			echo ( false !== $item_id && 'in_stock' !== $stock_status )
				? '<div class="woocommerce-help-tip combined-item-status combined-item-status--' . esc_attr( $stock_status ) . '" data-tip="' . esc_attr( $stock_status_label ) . '"></div>'
				: '';
		?>
		<div class="handle">
			<?php
				// $sku is merchant-controlled (product->get_sku()). Escape it.
				echo $sku
					? ( '<small class="item-sku">' . sprintf( esc_html_x( 'SKU: %s', 'combined product sku', 'lafka-plugin' ), esc_html( $sku ) ) . '</small>' )
					: '';
			?>
			<div class="handle-item toggle-item" aria-label="<?php esc_attr_e( 'Click to toggle', 'woocommerce' ); ?>"></div>
			<div class="handle-item sort-item" aria-label="<?php esc_attr_e( 'Drag and drop to set order', 'lafka-plugin' ); ?>"></div>
			<a href="#" class="remove_row delete"><?php esc_html_e( 'Remove', 'woocommerce' ); ?></a>
		</div>
	</h3>
	<div class="item-data wc-metabox-content">
		<input type="hidden" name="combo_data[<?php echo (int) $loop; ?>][menu_order]" class="item_menu_order" value="<?php echo (int) $loop; ?>" />
		<?php

		if ( false !== $item_id ) {
			?>
			<input type="hidden" name="combo_data[<?php echo (int) $loop; ?>][item_id]" class="item_id" value="<?php echo (int) $item_id; ?>" />
			<?php
		}

		?>
		<input type="hidden" name="combo_data[<?php echo (int) $loop; ?>][product_id]" class="product_id" value="<?php echo (int) $product_id; ?>" />

		<ul class="subsubsub">
		<?php

			/*--------------------------------*/
			/*  Tab menu items.               */
			/*--------------------------------*/

			$tab_loop = 0;

		foreach ( $tabs as $tab_values ) {

			$tab_id = $tab_values['id'];

			?>
				<li><a href="#" data-tab="<?php echo $tab_id; ?>" class="<?php echo $tab_loop === 0 ? 'current' : ''; ?>">
				<?php
				echo $tab_values['title'];
				?>
				</a></li>
				<?php

				++$tab_loop;
		}

		?>
		</ul>
		<?php

		/*--------------------------------*/
		/*  Tab contents.                 */
		/*--------------------------------*/

		$tab_loop = 0;

		foreach ( $tabs as $tab_values ) {

			$tab_id = $tab_values['id'];

			?>
			<div class="options_group options_group_<?php echo $tab_id; ?> <?php echo $tab_loop > 0 ? 'options_group_hidden' : ''; ?>">
			<?php
				/**
				 * 'woocommerce_combined_product_admin_{$tab_id}_html' action.
				 */
				do_action( 'woocommerce_combined_product_admin_' . $tab_id . '_html', $loop, $product_id, $item_data, $post_id );
			?>
			</div>
			<?php

			++$tab_loop;
		}

		?>
		</div>
</div>
