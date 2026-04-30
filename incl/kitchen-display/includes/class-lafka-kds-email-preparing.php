<?php
/**
 * Sent to the customer when the kitchen starts preparing the order.
 *
 * @package Lafka_Kitchen_Display
 */

defined( 'ABSPATH' ) || exit;

class Lafka_KDS_Email_Preparing extends Lafka_KDS_Email_Base {

	public function __construct() {
		$this->id                 = 'lafka_kds_order_preparing';
		$this->customer_email     = true;
		$this->title              = __( 'Order Preparing', 'lafka-plugin' );
		$this->description        = __( 'Sent to the customer when their order is being prepared.', 'lafka-plugin' );
		$this->template_html      = 'customer-order-preparing.php';
		$this->template_plain     = 'customer-order-preparing.php';
		$this->template_base      = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders       = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		$this->sent_flag_meta_key = '_lafka_kds_preparing_email_sent';

		add_action( 'woocommerce_order_status_accepted_to_preparing_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your order #{order_number} is being prepared', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Order Being Prepared', 'lafka-plugin' );
	}

	public function get_default_additional_content() {
		return __( 'Your order is now being prepared by our kitchen staff.', 'lafka-plugin' );
	}
}
