<?php
/**
 * Lafka Add-ons — global edit view.
 *
 * Wraps the editor form: post-level metadata (reference, priority,
 * applies-to-categories) above the addon-group editors.
 *
 * Variables in scope (from $context):
 *   $edit_id            — int (0 for new)
 *   $reference          — string (post_title)
 *   $priority           — int
 *   $applies_to_all     — bool
 *   $category_ids       — int[]
 *   $groups             — Lafka_Addon_Group[] (always at least 1)
 *   $product_attributes — stdClass[] from wc_get_attribute_taxonomies()
 *   $product_categories — WP_Term[]
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $context ) || ! is_array( $context ) ) {
	return;
}
extract( $context, EXTR_SKIP );

$list_url = add_query_arg(
	array(
		'post_type' => 'product',
		'page'      => Lafka_Engine_Admin::PAGE_SLUG,
	),
	admin_url( 'edit.php' )
);
?>
<div class="wrap lafka-engine-admin lafka-engine-edit">
	<h1 class="wp-heading-inline">
		<?php
		echo esc_html(
			$edit_id
				? __( 'Edit Add-on Group', 'lafka-plugin' )
				: __( 'Add New Add-on Group', 'lafka-plugin' )
		);
		?>
	</h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'lafka-plugin' ); ?></a>
	<hr class="wp-header-end">

	<form method="post" class="lafka-engine-form">
		<?php wp_nonce_field( Lafka_Engine_Editor::NONCE_ACTION ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="lafka_addon_reference"><?php esc_html_e( 'Reference name', 'lafka-plugin' ); ?></label></th>
				<td>
					<input type="text" id="lafka_addon_reference" name="lafka_addon_reference" value="<?php echo esc_attr( $reference ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Internal name to identify this group in the admin list.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lafka_addon_priority"><?php esc_html_e( 'Priority', 'lafka-plugin' ); ?></label></th>
				<td>
					<input type="number" id="lafka_addon_priority" name="lafka_addon_priority" value="<?php echo esc_attr( (string) $priority ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( 'Lower numbers display first on the product page.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Applies to', 'lafka-plugin' ); ?></th>
				<td>
					<fieldset class="lafka-engine-applies-to">
						<label class="lafka-engine-applies-to__option">
							<input type="radio" name="lafka_addon_applies_to_all" value="1" <?php checked( $applies_to_all ); ?> />
							<?php esc_html_e( 'All products', 'lafka-plugin' ); ?>
						</label>
						<label class="lafka-engine-applies-to__option">
							<input type="radio" name="lafka_addon_applies_to_all" value="0" <?php checked( ! $applies_to_all ); ?> />
							<?php esc_html_e( 'Only specific product categories', 'lafka-plugin' ); ?>
						</label>

						<div class="lafka-engine-categories" <?php echo $applies_to_all ? 'style="display:none;"' : ''; ?>>
							<p class="description"><?php esc_html_e( 'Tick categories to restrict this group to:', 'lafka-plugin' ); ?></p>
							<?php if ( empty( $product_categories ) ) : ?>
								<p><em><?php esc_html_e( 'No product categories defined yet. Add categories under Products → Categories first.', 'lafka-plugin' ); ?></em></p>
							<?php else : ?>
								<ul class="lafka-engine-category-list">
									<?php foreach ( $product_categories as $term ) : ?>
										<li>
											<label>
												<input type="checkbox" name="lafka_addon_categories[]" value="<?php echo esc_attr( (int) $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $category_ids, true ), true ); ?> />
												<?php echo esc_html( $term->name ); ?>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</fieldset>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Add-on Groups', 'lafka-plugin' ); ?></h2>
		<div class="lafka-engine-groups">
			<?php
			foreach ( $groups as $group_index => $group ) {
				require __DIR__ . '/editor.php';
			}
			?>
		</div>

		<p class="lafka-engine-add-group-row">
			<button type="button" class="button" data-lafka-add-group><?php esc_html_e( 'Add another group', 'lafka-plugin' ); ?></button>
		</p>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'lafka-plugin' ); ?></button>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'lafka-plugin' ); ?></a>
		</p>
	</form>

	<template id="lafka-engine-group-template" data-loop-placeholder="__GROUP_INDEX__">
		<?php
		$group       = Lafka_Addon_Group::from_array( array() );
		$group_index = '__GROUP_INDEX__';
		require __DIR__ . '/editor.php';
		?>
	</template>

	<template id="lafka-engine-option-row-template" data-loop-placeholder="__OPTION_INDEX__">
		<?php
		$option       = Lafka_Addon_Option::from_array( array() );
		$option_index = '__OPTION_INDEX__';
		$group_index  = '__GROUP_INDEX__';
		$group        = Lafka_Addon_Group::from_array( array() );
		require __DIR__ . '/parts/option-row.php';
		?>
	</template>
</div>
