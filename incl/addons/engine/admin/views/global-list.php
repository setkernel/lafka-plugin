<?php
/**
 * Lafka Add-ons — global list view.
 *
 * Renders the table of existing global addon groups.
 *
 * Variables in scope:
 *   $groups — WP_Post[] (lafka_glb_addon CPT)
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

$add_url = add_query_arg(
	array(
		'post_type' => 'product',
		'page'      => Lafka_Engine_Admin::PAGE_SLUG,
		'add'       => 1,
	),
	admin_url( 'edit.php' )
);
?>
<div class="wrap lafka-engine-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Lafka Add-ons', 'lafka-plugin' ); ?></h1>
	<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'lafka-plugin' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( empty( $groups ) ) : ?>
		<p><?php esc_html_e( 'No add-on groups yet.', 'lafka-plugin' ); ?> <a href="<?php echo esc_url( $add_url ); ?>"><?php esc_html_e( 'Create one.', 'lafka-plugin' ); ?></a></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'lafka-plugin' ); ?></th>
					<th><?php esc_html_e( 'Priority', 'lafka-plugin' ); ?></th>
					<th><?php esc_html_e( 'Applies to', 'lafka-plugin' ); ?></th>
					<th><?php esc_html_e( 'Groups', 'lafka-plugin' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
                foreach ( $groups as $group_post ) :
					$priority      = (int) get_post_meta( $group_post->ID, '_priority', true );
					$applies_to_all = (string) get_post_meta( $group_post->ID, '_all_products', true ) === '1';
					$category_terms = $applies_to_all ? array() : wp_get_post_terms( $group_post->ID, 'product_cat', array( 'fields' => 'names' ) );
					$group_count    = count( (array) get_post_meta( $group_post->ID, '_product_addons', true ) );

					$edit_url = add_query_arg(
						array(
							'post_type' => 'product',
							'page'      => Lafka_Engine_Admin::PAGE_SLUG,
							'edit'      => $group_post->ID,
						),
						admin_url( 'edit.php' )
					);
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'post_type' => 'product',
								'page'      => Lafka_Engine_Admin::PAGE_SLUG,
								'delete'    => $group_post->ID,
							),
							admin_url( 'edit.php' )
						),
						'delete_addon_' . $group_post->ID
					);
					?>
					<tr>
						<td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $group_post->post_title ); ?></strong></a></td>
						<td><?php echo esc_html( $priority ); ?></td>
						<td>
							<?php if ( $applies_to_all ) : ?>
								<?php esc_html_e( 'All products', 'lafka-plugin' ); ?>
							<?php elseif ( ! empty( $category_terms ) ) : ?>
								<?php echo esc_html( implode( ', ', $category_terms ) ); ?>
							<?php else : ?>
								<em><?php esc_html_e( 'No categories', 'lafka-plugin' ); ?></em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $group_count ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit', 'lafka-plugin' ); ?></a>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Move this addon to trash?', 'lafka-plugin' ) ); ?>');"><?php esc_html_e( 'Trash', 'lafka-plugin' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
