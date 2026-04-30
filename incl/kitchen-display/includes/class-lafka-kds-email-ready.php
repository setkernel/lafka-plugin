<?php
/**
 * Sent to the customer when the kitchen marks the order Ready (for
 * pickup or out-for-delivery, depending on order_type).
 *
 * @package Lafka_Kitchen_Display
 */

defined( 'ABSPATH' ) || exit;

class Lafka_KDS_Email_Ready extends Lafka_KDS_Email_Base {

	public function __construct() {
		$this->id                 = 'lafka_kds_order_ready';
		$this->customer_email     = true;
		$this->title              = __( 'Order Ready', 'lafka-plugin' );
		$this->description        = __( 'Sent to the customer when their order is ready for pickup or delivery.', 'lafka-plugin' );
		$this->template_html      = 'customer-order-ready.php';
		$this->template_plain     = 'customer-order-ready.php';
		$this->template_base      = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders       = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		$this->sent_flag_meta_key = '_lafka_kds_ready_email_sent';

		add_action( 'woocommerce_order_status_preparing_to_ready_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your order #{order_number} is ready', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Order Ready', 'lafka-plugin' );
	}

	public function get_default_additional_content() {
		return __( 'Your order is ready! See the details below.', 'lafka-plugin' );
	}
}
