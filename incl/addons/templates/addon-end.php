<?php defined( 'ABSPATH' ) || exit; ?>
	<?php
	do_action( 'lafka_product_addon_end', $addon );
	do_action_deprecated( 'wc_product_addon_end', array( $addon ), '10.4.0', 'lafka_product_addon_end' );
	?>
		<div class="clear"></div>
	<?php if ( ! empty( $addon['limit'] ) ) : ?>
		<small class="lafka-addon-limit-message"><?php esc_html_e( 'Select up to', 'lafka-plugin' ); ?> <span><?php echo esc_html( $addon['limit'] ); ?></span> <?php esc_html_e( 'items', 'lafka-plugin' ); ?>.</small>
	<?php endif; ?>
	<?php if ( ! empty( $toggle ) ) : ?>
		</div><!-- .lafka-addon-body -->
	<?php endif; ?>
</div>