<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( count( $lafka_nutrition_list ) || $lafka_product_allergens ): ?>
    <div class="lafka-nutrition-summary">
		<?php if ( count( $lafka_nutrition_list ) ): ?>
            <ul class="lafka-nutrition-list">
				<?php foreach ( $lafka_nutrition_list as $nutrition_name => $nutrition_value ): ?>
                    <li <?php if ( $nutrition_name === 'lafka_nutrition_energy' ): ?> class="lafka-nutrition-energy" <?php endif; ?> >
                        <span class="lafka-nutrition-list-label"><?php echo esc_html( Lafka_Nutrition_Config::$nutrition_meta_fields[ $nutrition_name ]['frontend_label'] ); ?></span>
						<?php echo esc_html( $nutrition_value ) ?> <?php echo esc_html( Lafka_Nutrition_Config::$nutrition_meta_fields[ $nutrition_name ]['frontend_label_weight'] ) ?>
                        <span class="lafka-nutrition-list-label"><?php esc_html_e( 'DI', 'lafka-plugin' ); ?>*</span>
						<?php echo esc_html( round( $nutrition_value / Lafka_Nutrition_Config::$nutrition_meta_fields[ $nutrition_name ]['DI'] * 100 ) ); ?>%
                    </li>
				<?php endforeach; ?>
            </ul>
            <span class="lafka-nutrition-di-legend">*<?php esc_html_e( 'DI', 'lafka-plugin' ); ?>: <?php esc_html_e( 'Recommended Daily Intake based on 2000 calories diet', 'lafka-plugin' ); ?></span>
		<?php endif; ?>

		<?php if ( $lafka_product_allergens ): ?>
            <span class="lafka-nutrition-allergens"><?php esc_html_e( 'Allergens', 'lafka-plugin' ); ?>: <?php echo esc_html( $lafka_product_allergens ); ?></span>
		<?php endif; ?>
    </div>
<?php endif; ?>