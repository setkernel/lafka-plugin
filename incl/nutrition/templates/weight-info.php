<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $lafka_product_weights */

if ( count( $lafka_product_weights ) ) : ?>
	<ul class="lafka-item-weight-holder">
		<li>
			<span class="lafka-item-weight">
				<?php esc_html_e( 'Serving size', 'lafka-plugin' ); ?>:
				<span class="lafka-item-weight-values">
					<?php
					$lafka_serving_size_text_array = array();
					foreach ( $lafka_product_weights as $lafka_weight ) {
						$lafka_serving_size_text_array[] = ( $lafka_weight['title'] ? ' ' . $lafka_weight['title'] . ' - ' : '' ) . $lafka_weight['weight'] . ' ' . get_option( 'woocommerce_weight_unit' );
					}
					?>
					<?php echo esc_html( implode( ' /', $lafka_serving_size_text_array ) ); ?>
				</span>
			</span>
		</li>
	</ul>
<?php endif; ?>