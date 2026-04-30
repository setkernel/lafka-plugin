<?php
/**
 * Lafka Add-ons — global list view.
 *
 * Renders the WP_List_Table-powered listing for the global addons admin page.
 *
 * Variables in scope:
 *   $table — Lafka_Engine_Addons_List_Table (already prepared)
 *
 * @package Lafka_Addons_Engine
 * @since   8.14.0
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

	<form method="get">
		<input type="hidden" name="post_type" value="product" />
		<input type="hidden" name="page" value="<?php echo esc_attr( Lafka_Engine_Admin::PAGE_SLUG ); ?>" />
		<?php
		$table->search_box( __( 'Search add-ons', 'lafka-plugin' ), 'lafka-addons' );
		$table->display();
		?>
	</form>
</div>
