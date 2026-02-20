<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Email_Preparing extends WC_Email {

	public function __construct() {
		$this->id             = 'lafka_kds_order_preparing';
		$this->customer_email = true;
		$this->title          = __( 'Order Preparing', 'lafka-plugin' );
		$this->description    = __( 'Sent to the customer when their order is being prepared.', 'lafka-plugin' );
		$this->template_html  = 'customer-order-preparing.php';
		$this->template_plain = 'customer-order-preparing.php';
		$this->template_base  = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_order_status_accepted_to_preparing_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your order #{order_number} is being prepared', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Order Being Prepared', 'lafka-plugin' );
	}

	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			// Prevent duplicate email sends
			$sent_flag = '_lafka_kds_preparing_email_sent';
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
			
			// Mark email as sent
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
				'order_url'          => $this->object->get_checkout_order_received_url(),
				'store_address'      => $this->get_store_address(),
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
				'order_url'          => $this->object->get_checkout_order_received_url(),
				'store_address'      => $this->get_store_address(),
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
		return __( 'Your order is now being prepared by our kitchen staff.', 'lafka-plugin' );
	}



	protected function get_store_address() {
		$parts = array_filter( array(
			get_option( 'woocommerce_store_address' ),
			get_option( 'woocommerce_store_address_2' ),
			get_option( 'woocommerce_store_city' ),
			get_option( 'woocommerce_store_postcode' ),
		) );
		return implode( ', ', $parts );
	}

	protected function get_store_phone() {
		$phone = get_option( 'woocommerce_store_phone', '' );
		if ( ! $phone ) {
			$phone = get_option( 'lafka_contact_phone', '' );
		}
		return $phone;
	}
}

