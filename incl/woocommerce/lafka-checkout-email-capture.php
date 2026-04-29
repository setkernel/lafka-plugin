<?php
/**
 * Checkout email-capture field — "Save 10% on next order".
 *
 * Optional. Captures into wp_wc_orders meta_data under key _lafka_winback_email.
 * Win-back email sequence is deferred — v1 just collects.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_render_checkout_email_capture' ) ) {
	function lafka_pdp_render_checkout_email_capture(): void {
		?>
		<div class="lafka-checkout-winback">
			<label for="lafka_winback_email" class="lafka-checkout-winback__label">
				<?php esc_html_e( '💌 Save 10% on your next order', 'lafka-plugin' ); ?>
				<span class="lafka-checkout-winback__hint"><?php esc_html_e( "Optional — we'll email you a one-time code.", 'lafka-plugin' ); ?></span>
			</label>
			<input
				type="email"
				id="lafka_winback_email"
				name="lafka_winback_email"
				class="input-text"
				placeholder="<?php esc_attr_e( 'your@email.com', 'lafka-plugin' ); ?>"
				autocomplete="email">
		</div>
		<?php
	}
	add_action( 'woocommerce_checkout_after_customer_details', 'lafka_pdp_render_checkout_email_capture' );
}

if ( ! function_exists( 'lafka_pdp_save_checkout_email_capture' ) ) {
	function lafka_pdp_save_checkout_email_capture( int $order_id ): void {
		if ( empty( $_POST['lafka_winback_email'] ) ) {
			return;
		}
		$raw = wp_unslash( $_POST['lafka_winback_email'] );
		$email = sanitize_email( $raw );
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_lafka_winback_email', $email );
		$order->save();
	}
	add_action( 'woocommerce_checkout_update_order_meta', 'lafka_pdp_save_checkout_email_capture' );
}
