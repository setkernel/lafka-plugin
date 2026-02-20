<?php
/**
 * Customer Order Preparing email
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $order_type
 * @var string   $order_url
 * @var string   $store_address
 * @var string   $store_phone
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

$is_pickup = ( 'pickup' === $order_type );

// Build inline order summary
$items_summary = array();
foreach ( $order->get_items() as $item ) {
	$items_summary[] = $item->get_quantity() . 'x ' . $item->get_name();
}
$summary_text = implode( ', ', $items_summary );

// Contextualized ETA — compute remaining minutes from now
$eta_timestamp = $order->get_meta( '_lafka_kds_eta' );
$eta_remaining = 0;
if ( $eta_timestamp ) {
	$eta_remaining = max( 0, (int) round( ( (int) $eta_timestamp - time() ) / 60 ) );
}

if ( $plain_text ) :
	echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) );
	echo "\n\n";

	if ( $is_pickup ) {
		/* translators: %s: Order number */
		printf( esc_html__( 'Your order #%s is now being prepared by our kitchen. We\'ll let you know when it\'s ready for pickup!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
	} else {
		/* translators: %s: Order number */
		printf( esc_html__( 'Your order #%s is now being prepared by our kitchen. We\'ll let you know when it\'s ready for delivery!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
	}
	echo "\n\n";

	echo esc_html__( 'Your order:', 'lafka-plugin' ) . ' ' . esc_html( $summary_text ) . "\n\n";

	if ( $eta_remaining > 0 ) {
		/* translators: %d: minutes */
		printf( esc_html__( 'Estimated ready in about %d minutes.', 'lafka-plugin' ), $eta_remaining );
		echo "\n\n";
	}

	echo esc_html__( 'Track your order in real time:', 'lafka-plugin' ) . ' ' . esc_url( $order_url ) . "\n\n";

	if ( $additional_content ) {
		echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
		echo "\n\n";
	}

	if ( $store_phone ) {
		echo esc_html__( 'Questions? Call us:', 'lafka-plugin' ) . ' ' . esc_html( $store_phone ) . "\n";
	}
	echo "\n---\n\n";

	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

else :

	do_action( 'woocommerce_email_header', $email_heading, $email );
	?>

	<p><?php printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) ); ?></p>
	<p>
		<?php
		if ( $is_pickup ) {
			printf( esc_html__( 'Your order #%s is now being prepared by our kitchen. We\'ll let you know when it\'s ready for pickup!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
		} else {
			printf( esc_html__( 'Your order #%s is now being prepared by our kitchen. We\'ll let you know when it\'s ready for delivery!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
		}
		?>
	</p>

	<p style="background:#f8f8f8;padding:12px 16px;border-radius:6px;font-size:14px;color:#555;">
		<strong><?php esc_html_e( 'Your order:', 'lafka-plugin' ); ?></strong><br>
		<?php echo esc_html( $summary_text ); ?>
	</p>

	<?php if ( $eta_remaining > 0 ) : ?>
		<p style="font-size:18px;font-weight:bold;">
			<?php printf( esc_html__( 'Estimated ready in about %d minutes', 'lafka-plugin' ), $eta_remaining ); ?>
		</p>
	<?php endif; ?>

	<p style="text-align:center;margin:20px 0;">
		<a href="<?php echo esc_url( $order_url ); ?>" style="display:inline-block;padding:12px 28px;background:#e94560;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:15px;">
			<?php esc_html_e( 'Track Your Order', 'lafka-plugin' ); ?>
		</a>
	</p>

	<?php if ( $additional_content ) : ?>
		<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
	<?php endif; ?>

	<table cellpadding="0" cellspacing="0" style="width:100%;margin-top:16px;border-top:1px solid #e5e5e5;padding-top:12px;">
		<?php if ( $store_phone ) : ?>
		<tr>
			<td style="padding:4px 0;font-size:13px;color:#888;">
				<?php esc_html_e( 'Questions? Call us:', 'lafka-plugin' ); ?>
				<strong><?php echo esc_html( $store_phone ); ?></strong>
			</td>
		</tr>
		<?php endif; ?>
	</table>

	<?php
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_footer', $email );

endif;

