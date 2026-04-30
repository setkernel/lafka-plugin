<?php
/**
 * Sent to the customer when the kitchen rejects the order.
 *
 * @package Lafka_Kitchen_Display
 */

defined( 'ABSPATH' ) || exit;

class Lafka_KDS_Email_Rejected extends Lafka_KDS_Email_Base {

	public function __construct() {
		$this->id                 = 'lafka_kds_order_rejected';
		$this->customer_email     = true;
		$this->title              = __( 'Order Rejected', 'lafka-plugin' );
		$this->description        = __( 'Sent to the customer when their order is rejected by the kitchen.', 'lafka-plugin' );
		$this->template_html      = 'customer-order-rejected.php';
		$this->template_plain     = 'customer-order-rejected.php';
		$this->template_base      = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders       = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		$this->sent_flag_meta_key = '_lafka_kds_rejected_email_sent';

		// Rejection can happen from processing OR from accepted (operator
		// accepted, then realised they can't fulfil), so we wire two transitions.
		add_action( 'woocommerce_order_status_processing_to_rejected_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_accepted_to_rejected_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Update regarding your order #{order_number}', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Order Update', 'lafka-plugin' );
	}

	public function get_default_additional_content() {
		return __( 'We sincerely apologize for the inconvenience. You have not been charged for this order.', 'lafka-plugin' );
	}
}
