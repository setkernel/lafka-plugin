<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — WC_Email child class.
 *
 * Subclass of WC_Email. Picks up WC's send pipeline (locale switch, headers,
 * From-name/From-address from WC settings, mailer hooks). Listens for the
 * `lafka_abandoned_cart_email_trigger` action emitted by the cron handler and
 * fires `send()` with the rendered body.
 *
 * Kept in its own file so it can be `require_once`'d lazily — `WC_Email`
 * itself isn't available until after WC's `woocommerce_loaded` action.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

if ( ! class_exists( 'LAFKA_Abandoned_Cart_Email' ) ) {

	/**
	 * Customer-facing recovery email — one shot per abandoned-cart row.
	 */
	class LAFKA_Abandoned_Cart_Email extends WC_Email {

		/**
		 * Set up email config + bind to the cron trigger action.
		 */
		public function __construct() {
			$this->id             = 'lafka_abandoned_cart';
			$this->customer_email = true;
			$this->title          = __( 'Abandoned cart recovery', 'lafka-plugin' );
			$this->description    = __( 'Sent to a customer 75 minutes after they entered their email at checkout but didn\'t complete the order. Operator-configurable copy.', 'lafka-plugin' );
			$this->template_base  = '';
			$this->template_html  = '';
			$this->template_plain = '';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
			);

			add_action( 'lafka_abandoned_cart_email_trigger', array( $this, 'trigger' ), 10, 1 );

			parent::__construct();
		}

		/**
		 * Default subject line — operator overrides through Customizer.
		 *
		 * @return string
		 */
		public function get_default_subject(): string {
			return function_exists( 'lafka_ac_email_subject_default' )
				? lafka_ac_email_subject_default()
				: __( 'Did you forget something? Your cart is waiting', 'lafka-plugin' );
		}

		/**
		 * Default H2 heading at the top of the body.
		 *
		 * @return string
		 */
		public function get_default_heading(): string {
			return function_exists( 'lafka_ac_email_intro_heading' )
				? lafka_ac_email_intro_heading()
				: __( 'Your cart is still here', 'lafka-plugin' );
		}

		/**
		 * Action handler — assemble + send the email for one abandoned-cart row.
		 *
		 * @param object|null $row
		 * @return void
		 */
		public function trigger( $row = null ): void {
			if ( ! is_object( $row ) ) {
				return;
			}
			$recipient = isset( $row->customer_email ) ? (string) $row->customer_email : '';
			if ( '' === $recipient ) {
				return;
			}

			$this->setup_locale();

			$this->recipient = $recipient;

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$body = function_exists( 'lafka_ac_render_email_body' )
					? lafka_ac_render_email_body( $row, $this )
					: '';

				if ( '' !== $body ) {
					$this->send(
						$this->get_recipient(),
						$this->get_subject(),
						$body,
						$this->get_headers(),
						$this->get_attachments()
					);
				}
			}

			$this->restore_locale();
		}

		/**
		 * WC's preview pane in /wp-admin/admin.php?page=wc-settings&tab=email reads
		 * this method. Return the rendered HTML for an empty/example row so the
		 * preview doesn't 500 when there's no live abandoned cart to model.
		 *
		 * @return string
		 */
		public function get_content_html(): string {
			$sample_row                  = new \stdClass();
			$sample_row->customer_email  = 'preview@example.com';
			$sample_row->resume_token    = 'PREVIEWTOKEN0000PREVIEWTOKEN0000';
			$sample_row->cart_contents   = wp_json_encode(
				array(
					'items'    => array(
						array(
							'name'     => 'Margherita Pizza',
							'quantity' => 1,
							'price'    => 12.50,
							'image'    => '',
						),
					),
					'subtotal' => 12.50,
					'currency' => '',
				)
			);
			return function_exists( 'lafka_ac_render_email_body' )
				? lafka_ac_render_email_body( $sample_row, $this )
				: '';
		}

		/**
		 * Plain-text variant — same content, stripped tags.
		 *
		 * @return string
		 */
		public function get_content_plain(): string {
			return function_exists( 'wp_strip_all_tags' )
				? wp_strip_all_tags( $this->get_content_html() )
				: strip_tags( $this->get_content_html() );
		}
	}
}
