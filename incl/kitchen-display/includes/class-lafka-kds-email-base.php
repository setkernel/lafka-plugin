<?php
/**
 * Lafka_KDS_Email_Base — shared base for KDS status-change customer emails.
 *
 * Each concrete subclass declares only the per-status configuration:
 *   - id, title, description, template filename
 *   - one or more `woocommerce_order_status_{from}_to_{to}_notification` hooks
 *   - default subject / heading / additional content
 *   - a unique `_lafka_kds_*_email_sent` meta flag for dedupe
 *
 * The base owns the trigger flow, content rendering, store address/phone
 * helpers, and the WC_Email plumbing. Subclasses set the config in their
 * constructor and call `parent::__construct()`.
 *
 * @package Lafka_Kitchen_Display
 * @since   9.7.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_KDS_Email_Base extends WC_Email {

	/**
	 * Order meta key used to record a successful send. Each subclass overrides
	 * with a status-specific key (e.g. `_lafka_kds_accepted_email_sent`) so the
	 * dedupe flags don't collide across the four emails.
	 */
	protected string $sent_flag_meta_key = '';

	/**
	 * Run the WC status-change → email flow once per order. Subclasses don't
	 * override this; they only declare the hook(s) that fire it.
	 *
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 */
	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			if ( ! $this->sent_flag_meta_key || $order->get_meta( $this->sent_flag_meta_key ) ) {
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

			$order->update_meta_data( $this->sent_flag_meta_key, time() );
			$order->save();
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->get_template_args( false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->get_template_args( true ),
			'',
			$this->template_base
		);
	}

	/**
	 * Build the args array passed to the email templates. Subclasses can
	 * override to add status-specific data, but typically don't need to —
	 * the four KDS status emails all share the same shape.
	 *
	 * @param bool $plain_text Whether the plain-text variant is being rendered.
	 * @return array
	 */
	protected function get_template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'order_type'         => Lafka_Kitchen_Display::get_order_type( $this->object ),
			'order_url'          => $this->object->get_checkout_order_received_url(),
			'store_address'      => $this->get_store_address(),
			'store_phone'        => $this->get_store_phone(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * WooCommerce store address as a single comma-separated string. Empty
	 * components are filtered out so an unset address-line-2 doesn't yield
	 * `Foo, , Bar`.
	 */
	protected function get_store_address(): string {
		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address' ),
				get_option( 'woocommerce_store_address_2' ),
				get_option( 'woocommerce_store_city' ),
				get_option( 'woocommerce_store_postcode' ),
			)
		);
		return implode( ', ', $parts );
	}

	/**
	 * Store phone — prefers the WC core option, falls back to the customizer
	 * key the Lafka theme writes for header/footer NAP rendering.
	 */
	protected function get_store_phone(): string {
		$phone = get_option( 'woocommerce_store_phone', '' );
		if ( ! $phone ) {
			$phone = get_option( 'lafka_contact_phone', '' );
		}
		return (string) $phone;
	}
}
