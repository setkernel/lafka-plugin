<?php
/**
 * Customer Order Ready email
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $order_type
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */
defined( 'ABSPATH' ) || exit;

$is_pickup = ( 'pickup' === $order_type );

if ( $plain_text ) :
	echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) );
	echo "\n\n";

	if ( $is_pickup ) {
		/* translators: %s: Order number */
		printf( esc_html__( 'Your order #%s is ready for pickup!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
	} else {
		/* translators: %s: Order number */
		printf( esc_html__( 'Your order #%s is ready and on its way!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
	}
	echo "\n\n";

	if ( $additional_content ) {
		echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
		echo "\n\n";
	}

	echo "---\n\n";

	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

else :

	do_action( 'woocommerce_email_header', $email_heading, $email );
	?>

	<p>
		<?php
		/* translators: %s: Customer first name */
		printf( esc_html__( 'Hi %s,', 'lafka-plugin' ), esc_html( $order->get_billing_first_name() ) );
		?>
	</p>
	<p style="font-size:18px;font-weight:bold;">
		<?php
		if ( $is_pickup ) {
			/* translators: %s: Order number */
			printf( esc_html__( 'Your order #%s is ready for pickup!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
		} else {
			/* translators: %s: Order number */
			printf( esc_html__( 'Your order #%s is ready and on its way!', 'lafka-plugin' ), esc_html( $order->get_order_number() ) );
		}
		?>
	</p>

	<?php if ( $additional_content ) : ?>
		<p><?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?></p>
	<?php endif; ?>

	<?php
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_footer', $email );

endif;
