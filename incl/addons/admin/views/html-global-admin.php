<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap woocommerce">
	<div class="icon32 icon32-posts-product" id="icon-woocommerce"><br/></div>

    <h2><?php esc_html_e( 'Lafka Global Add-ons', 'lafka-plugin' ) ?> <a href="<?php echo add_query_arg( 'add', true, admin_url( 'edit.php?post_type=product&page=lafka_global_addons' ) ); ?>" class="add-new-h2"><?php esc_html_e( 'Add Global Add-on', 'lafka-plugin' ); ?></a></h2><br/>

	<table id="global-addons-table" class="wp-list-table widefat" cellspacing="0">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Reference', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Number of Fields', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Priority', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Applies to...', 'lafka-plugin' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'lafka-plugin' ); ?></th>
			</tr>
		</thead>
		<tbody id="the-list">
			<?php
				$global_addons = Lafka_Product_Addon_Groups::get_all_global_groups();

				if ( $global_addons ) {
					foreach ( $global_addons as $global_addon ) {
						?>
						<tr>
							<td><?php echo $global_addon['name']; ?></td>
							<td><?php echo sizeof( $global_addon['fields'] ); ?></td>
							<td><?php echo $global_addon['priority']; ?></td>
							<td><?php

								$restrict_to_categories = $global_addon['restrict_to_categories'];
								if ( 0 === count( $restrict_to_categories) ) {
									esc_html_e( 'All Products', 'lafka-plugin' );
								} else {
									$objects = array_keys( $restrict_to_categories );
									$term_names = array_values( $restrict_to_categories );
									$term_names = apply_filters( 'lafka_product_addons_global_display_term_names', $term_names, $objects );
									echo implode( ', ', $term_names );
								}

							?></td>
							<td>
								<a href="<?php echo add_query_arg( 'edit', $global_addon['id'], admin_url( 'edit.php?post_type=product&page=lafka_global_addons' ) ); ?>" class="button"><?php esc_html_e( 'Edit', 'lafka-plugin' ); ?></a> <a href="<?php echo wp_nonce_url( add_query_arg( 'delete', $global_addon['id'], admin_url( 'edit.php?post_type=product&page=lafka_global_addons' ) ), 'delete_addon' ); ?>" class="button"><?php esc_html_e( 'Delete', 'lafka-plugin' ); ?></a>
							</td>
						</tr>
						<?php
					}
				} else {
					?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No global add-ons exists yet.', 'lafka-plugin' ); ?> <a href="<?php echo add_query_arg( 'add', true, admin_url( 'edit.php?post_type=product&page=lafka_global_addons' ) ); ?>"><?php esc_html_e( 'Add one?', 'lafka-plugin' ); ?></a></td>
					</tr>
					<?php
				}
			?>
		</tbody>
	</table>
</div>
