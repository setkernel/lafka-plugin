<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Email_Rejected extends WC_Email {

	public function __construct() {
		$this->id             = 'lafka_kds_order_rejected';
		$this->customer_email = true;
		$this->title          = __( 'Order Rejected', 'lafka-plugin' );
		$this->description    = __( 'Sent to the customer when their order is rejected by the kitchen.', 'lafka-plugin' );
		$this->template_html  = 'customer-order-rejected.php';
		$this->template_plain = 'customer-order-rejected.php';
		$this->template_base  = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

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

	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$sent_flag = '_lafka_kds_rejected_email_sent';
			if ( $order->get_meta( $sent_flag ) ) {
				$this->restore_locale();
				return;
			}

			$this->object                         = $order;
			$this->recipient                      = $this->object->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

			$order->update_meta_data( $sent_flag, time() );
			$order->save();
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'order_type'         => Lafka_Kitchen_Display::get_order_type( $this->object ),
				'store_phone'        => $this->get_store_phone(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'order_type'         => Lafka_Kitchen_Display::get_order_type( $this->object ),
				'store_phone'        => $this->get_store_phone(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_default_additional_content() {
		return __( 'We sincerely apologize for the inconvenience. You have not been charged for this order.', 'lafka-plugin' );
	}



	protected function get_store_phone() {
		$phone = get_option( 'woocommerce_store_phone', '' );
		if ( ! $phone ) {
			$phone = get_option( 'lafka_contact_phone', '' );
		}
		return $phone;
	}
}
