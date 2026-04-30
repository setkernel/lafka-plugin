<?php
/**
 * Sent to the customer when the kitchen marks the order Accepted.
 *
 * @package Lafka_Kitchen_Display
 */

defined( 'ABSPATH' ) || exit;

class Lafka_KDS_Email_Accepted extends Lafka_KDS_Email_Base {

	public function __construct() {
		$this->id                 = 'lafka_kds_order_accepted';
		$this->customer_email     = true;
		$this->title              = __( 'Order Accepted', 'lafka-plugin' );
		$this->description        = __( 'Sent to the customer when their order is accepted by the kitchen.', 'lafka-plugin' );
		$this->template_html      = 'customer-order-accepted.php';
		$this->template_plain     = 'customer-order-accepted.php';
		$this->template_base      = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders       = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		$this->sent_flag_meta_key = '_lafka_kds_accepted_email_sent';

		add_action( 'woocommerce_order_status_processing_to_accepted_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your order #{order_number} has been accepted', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Order Accepted', 'lafka-plugin' );
	}

	public function get_default_additional_content() {
		return __( 'Thank you for your order. We will begin preparing it shortly.', 'lafka-plugin' );
	}
}
