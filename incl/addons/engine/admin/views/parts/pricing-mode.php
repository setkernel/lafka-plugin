<?php
/**
 * Pricing mode partial — 4-mode picker + per-mode group-level inputs.
 *
 * Variables in scope: $group, $prefix
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

$lafka_modes   = array(
	Lafka_Addon_Schema::PRICING_FLAT_GROUP      => __( 'Flat for whole group', 'lafka-plugin' ),
	Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION => __( 'Flat per option', 'lafka-plugin' ),
	Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE   => __( 'Flat per size', 'lafka-plugin' ),
	Lafka_Addon_Schema::PRICING_MATRIX          => __( 'Full matrix (option × size)', 'lafka-plugin' ),
);
$lafka_current = $lafka_group->pricing_mode;
?>
<fieldset class="lafka-engine-fieldset" data-lafka-pricing-mode>
	<legend><?php esc_html_e( 'Pricing', 'lafka-plugin' ); ?></legend>
	<?php foreach ( $lafka_modes as $lafka_mode_id => $lafka_mode_label ) : ?>
		<label class="lafka-engine-pricing-mode__option">
			<input type="radio" name="<?php echo esc_attr( $lafka_prefix . '[pricing_mode]' ); ?>" value="<?php echo esc_attr( $lafka_mode_id ); ?>" <?php checked( $lafka_current, $lafka_mode_id ); ?> data-lafka-pricing />
			<?php echo esc_html( $lafka_mode_label ); ?>
		</label>
	<?php endforeach; ?>

	<div class="lafka-engine-flat-group-input" <?php echo Lafka_Addon_Schema::PRICING_FLAT_GROUP === $lafka_current ? '' : 'style="display:none;"'; ?>>
		<p>
			<label>
				<?php esc_html_e( 'Single price for every option:', 'lafka-plugin' ); ?>
				<input type="text" name="<?php echo esc_attr( $lafka_prefix . '[group_flat_price]' ); ?>" value="<?php echo esc_attr( $lafka_group->group_flat_price ); ?>" class="wc_input_price small-text" placeholder="0.00" />
			</label>
		</p>
	</div>

	<div class="lafka-engine-per-size-input" <?php echo Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE === $lafka_current ? '' : 'style="display:none;"'; ?>>
		<p>
			<?php esc_html_e( 'Per-size prices apply uniformly to every option in the group. Pick the size attribute below, then enter prices for each size term you want to include.', 'lafka-plugin' ); ?>
		</p>
	</div>

	<div class="lafka-engine-matrix-hint" <?php echo Lafka_Addon_Schema::PRICING_MATRIX === $lafka_current ? '' : 'style="display:none;"'; ?>>
		<p>
			<?php esc_html_e( 'Each option will get its own per-size price grid below. Pick the size attribute below first.', 'lafka-plugin' ); ?>
		</p>
	</div>
</fieldset>
