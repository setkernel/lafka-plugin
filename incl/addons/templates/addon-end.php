	<?php do_action( 'wc_product_addon_end', $addon ); ?>
   	<div class="clear"></div>
    <?php if(!empty($addon['limit'])): ?>
	    <small class="lafka-addon-limit-message"><?php esc_html_e('Select up to', 'lafka-plugin') ?> <span><?php echo esc_html($addon['limit']) ?></span> <?php esc_html_e('items', 'lafka-plugin') ?>.</small>
    <?php endif; ?>
</div>