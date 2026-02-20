<?php
/**
 * Customer Order Rejected email
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $order_type
 * @var string   $store_phone
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

// Build inline order summary
$items_summary = array();
foreach ( $order->get_items() as $item ) {
	$items_summary[] = $item->get_quantity() . 'x ' . $item->get_name();
}
$summary_text = implode( ', ', $items_summary );

if ( $plain_text ) :
	echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) );
	echo "\n\n";
	/* translators: %s: Order number */
	printf( esc_html__( 'Unfortunately, we are unable to fulfill your order #%s at this time.', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
	echo "\n\n";

	echo esc_html__( 'Your order was:', 'lafka-plugin' ) . ' ' . esc_html( $summary_text ) . "\n\n";

	if ( $additional_content ) {
		echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
		echo "\n\n";
	}

	if ( $store_phone ) {
		echo esc_html__( 'Please contact us if you have any questions:', 'lafka-plugin' ) . ' ' . esc_html( $store_phone ) . "\n";
	}
	echo "\n---\n\n";

	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

else :

	do_action( 'woocommerce_email_header', $email_heading, $email );
	?>

	<p><?php printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
	<p>
		<?php printf( esc_html__( 'Unfortunately, we are unable to fulfill your order #%s at this time.', 'lafka-plugin' ), esc_html( $order->get_order_number() ) ); ?>
	</p>

	<p style="background:#f8f8f8;padding:12px 16px;border-radius:6px;font-size:14px;color:#555;">
		<strong><?php esc_html_e( 'Your order was:', 'lafka-plugin' ); ?></strong><br>
		<?php echo esc_html( $summary_text ); ?>
	</p>

	<?php if ( $additional_content ) : ?>
		<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
	<?php endif; ?>

	<table cellpadding="0" cellspacing="0" style="width:100%;margin-top:16px;border-top:1px solid #e5e5e5;padding-top:12px;">
		<?php if ( $store_phone ) : ?>
		<tr>
			<td style="padding:4px 0;font-size:13px;color:#888;">
				<?php esc_html_e( 'Please contact us if you have any questions:', 'lafka-plugin' ); ?>
				<strong><?php echo esc_html( $store_phone ); ?></strong>
			</td>
		</tr>
		<?php endif; ?>
	</table>

	<?php
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_footer', $email );

endif;
