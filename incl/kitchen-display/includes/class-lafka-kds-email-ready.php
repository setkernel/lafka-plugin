<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Email_Ready extends WC_Email {

	public function __construct() {
		$this->id             = 'lafka_kds_order_ready';
		$this->customer_email = true;
		$this->title          = __( 'Order Ready', 'lafka-plugin' );
		$this->description    = __( 'Sent to the customer when their order is ready for pickup or delivery.', 'lafka-plugin' );
		$this->template_html  = 'customer-order-ready.php';
		$this->template_plain = 'customer-order-ready.php';
		$this->template_base  = dirname( __DIR__ ) . '/templates/emails/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_order_status_preparing_to_ready_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your order #{order_number} is ready!', 'lafka-plugin' );
	}

	public function get_default_heading() {
		return __( 'Your Order is Ready!', 'lafka-plugin' );
	}

	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $this->object->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
			$this->placeholders['{order_number}'] = $this->object->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
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
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_default_additional_content() {
		return __( 'Thank you for your patience!', 'lafka-plugin' );
	}
}
