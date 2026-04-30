<?php
/**
 * Per-product addon panel — renders inside the WC product editor's tab.
 *
 * Variables in scope:
 *   $post_id            — int, the product post ID
 *   $groups             — Lafka_Addon_Group[] (always at least 1)
 *   $exclude_global     — bool
 *   $product_attributes — stdClass[]
 *
 * Form name pattern matches global editor: lafka_addon_groups[$index][...]
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.2
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="lafka_engine_addons_panel" class="panel woocommerce_options_panel lafka-engine-product-panel">
	<div class="options_group">
		<p>
			<label class="lafka-engine-exclude-global">
				<input type="checkbox" name="lafka_addons_exclude_global" value="1" <?php checked( $exclude_global ); ?> />
				<?php esc_html_e( 'Exclude this product from global add-on groups', 'lafka-plugin' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'When checked, global addon groups assigned to this product\'s categories will not display on this product.', 'lafka-plugin' ); ?></span>
		</p>
	</div>

	<div class="lafka-engine-admin lafka-engine-product-groups">
		<h3><?php esc_html_e( 'Product-specific add-on groups', 'lafka-plugin' ); ?></h3>
		<p class="description"><?php esc_html_e( 'These groups apply only to this product. Use Lafka Add-ons (Products → Lafka Add-ons) for groups that apply to multiple products.', 'lafka-plugin' ); ?></p>

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
	</div>

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
