<?php
/**
 * Lafka Add-ons — single addon-group editor.
 *
 * Renders ONE addon group's form fields. Looped from global-edit.php and
 * also used to populate the "new group" template.
 *
 * Variables in scope:
 *   $group              — Lafka_Addon_Group
 *   $group_index        — int|string (loop index, or placeholder for templates)
 *   $product_attributes — stdClass[]
 *
 * Form name pattern: lafka_addon_groups[$group_index][...]
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $group, $group_index ) ) {
	return;
}
$prefix = 'lafka_addon_groups[' . $group_index . ']';
?>
<div class="lafka-engine-group" data-lafka-group data-group-index="<?php echo esc_attr( (string) $group_index ); ?>">
	<div class="lafka-engine-group__header">
		<h3>
			<?php esc_html_e( 'Group', 'lafka-plugin' ); ?>:
			<span class="lafka-engine-group__title-display"><?php echo esc_html( $group->name ?: __( 'Untitled', 'lafka-plugin' ) ); ?></span>
		</h3>
		<button type="button" class="button-link-delete" data-lafka-remove-group><?php esc_html_e( 'Remove group', 'lafka-plugin' ); ?></button>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row"><label><?php esc_html_e( 'Name', 'lafka-plugin' ); ?></label></th>
			<td>
				<input type="text" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( $group->name ); ?>" class="regular-text" data-lafka-group-name />
				<p class="description"><?php esc_html_e( 'Customer-facing label for this group on the product page.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label><?php esc_html_e( 'Field type', 'lafka-plugin' ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $prefix . '[type]' ); ?>">
					<option value="checkbox" <?php selected( $group->type, 'checkbox' ); ?>><?php esc_html_e( 'Checkboxes (multi-select)', 'lafka-plugin' ); ?></option>
					<option value="radiobutton" <?php selected( $group->type, 'radiobutton' ); ?>><?php esc_html_e( 'Radio buttons (single-select)', 'lafka-plugin' ); ?></option>
					<option value="textarea" <?php selected( $group->type, 'textarea' ); ?>><?php esc_html_e( 'Free text', 'lafka-plugin' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label><?php esc_html_e( 'Description', 'lafka-plugin' ); ?></label></th>
			<td>
				<textarea name="<?php echo esc_attr( $prefix . '[description]' ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $group->description ); ?></textarea>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Required', 'lafka-plugin' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $prefix . '[required]' ); ?>" value="1" <?php checked( 1, $group->required ); ?> />
					<?php esc_html_e( 'Customer must make a selection.', 'lafka-plugin' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label><?php esc_html_e( 'Max selections', 'lafka-plugin' ); ?></label></th>
			<td>
				<input type="number" name="<?php echo esc_attr( $prefix . '[limit]' ); ?>" value="<?php echo esc_attr( (string) $group->limit ); ?>" min="0" class="small-text" />
				<p class="description"><?php esc_html_e( '0 = unlimited. Only relevant for checkboxes.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
	</table>

	<?php require __DIR__ . '/parts/source-selector.php'; ?>

	<?php require __DIR__ . '/parts/pricing-mode.php'; ?>

	<?php require __DIR__ . '/parts/size-picker.php'; ?>

	<?php require __DIR__ . '/parts/option-rows.php'; ?>
</div>
