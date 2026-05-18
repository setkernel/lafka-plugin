<?php
/**
 * Phase 3D (v9.28.0): Post-purchase review prompt — WC_Email child class.
 *
 * Subclass of WC_Email. Picks up WC's send pipeline (locale switch, headers,
 * From-name/From-address from WC settings, mailer hooks). Listens for the
 * `lafka_review_prompt_email_trigger` action emitted by the cron handler and
 * fires `send()` with the rendered body.
 *
 * Kept in its own file so it can be `require_once`'d lazily — `WC_Email`
 * itself isn't available until after WC's `woocommerce_loaded` action.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.28.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

if ( ! class_exists( 'LAFKA_Review_Prompt_Email' ) ) {

	/**
	 * Customer-facing review-request email — one shot per completed order.
	 */
	class LAFKA_Review_Prompt_Email extends WC_Email {

		/**
		 * Set up email config + bind to the cron trigger action.
		 */
		public function __construct() {
			$this->id             = 'lafka_review_prompt';
			$this->customer_email = true;
			$this->title          = __( 'Post-purchase review prompt', 'lafka-plugin' );
			$this->description    = __( 'Sent N hours after an order reaches the "completed" status (default 24h, operator-configurable in Customize → Lafka — Review prompts). Includes a 5-star tap row + link to your Google review URL.', 'lafka-plugin' );
			$this->template_base  = '';
			$this->template_html  = '';
			$this->template_plain = '';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
			);

			add_action( 'lafka_review_prompt_email_trigger', array( $this, 'trigger' ), 10, 1 );

			parent::__construct();
		}

		/**
		 * Default subject line — operator overrides through Customizer.
		 *
		 * @return string
		 */
		public function get_default_subject(): string {
			return function_exists( 'lafka_review_email_subject_default' )
				? lafka_review_email_subject_default()
				: __( 'How was your order?', 'lafka-plugin' );
		}

		/**
		 * Default H2 heading at the top of the body. WC's email frame renders
		 * this as the top headline — we set it to the customer's name greeting.
		 *
		 * @return string
		 */
		public function get_default_heading(): string {
			return __( 'How was your order?', 'lafka-plugin' );
		}

		/**
		 * Action handler — assemble + send the email for one completed order.
		 *
		 * @param int $order_id
		 * @return void
		 */
		public function trigger( $order_id = 0 ): void {
			$order_id = (int) $order_id;
			if ( $order_id <= 0 ) {
				return;
			}
			if ( ! function_exists( 'wc_get_order' ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || ! is_object( $order ) ) {
				return;
			}
			$recipient = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
			if ( '' === $recipient ) {
				return;
			}

			// Honour user-level opt-out + idempotence (both checked in render
			// helpers, but short-circuit here to skip locale + mailer overhead).
			if ( function_exists( 'lafka_review_email_should_skip_order' ) && lafka_review_email_should_skip_order( $order ) ) {
				return;
			}

			$this->setup_locale();

			$this->object    = $order;
			$this->recipient = $recipient;

			if ( $this->is_enabled() && $this->get_recipient() ) {
				$body = function_exists( 'lafka_review_email_render_body' )
					? lafka_review_email_render_body( $order, $this )
					: '';

				if ( '' !== $body ) {
					$sent = $this->send(
						$this->get_recipient(),
						$this->get_subject(),
						$body,
						$this->get_headers(),
						$this->get_attachments()
					);

					if ( $sent && function_exists( 'lafka_review_email_mark_sent' ) ) {
						lafka_review_email_mark_sent( $order );
					}
				}
			}

			$this->restore_locale();
		}

		/**
		 * Override subject — supports {firstname} + {site} token substitution
		 * keyed off the current $this->object (set by trigger()).
		 *
		 * @return string
		 */
		public function get_subject(): string {
			$tmpl = (string) $this->get_default_subject();
			$first = '';
			$site  = $this->get_blogname();
			if ( is_object( $this->object ) && method_exists( $this->object, 'get_billing_first_name' ) ) {
				$first = (string) $this->object->get_billing_first_name();
			}
			if ( '' === $first ) {
				$first = function_exists( '__' ) ? __( 'there', 'lafka-plugin' ) : 'there';
			}
			$tmpl = str_replace( array( '{firstname}', '{site}' ), array( $first, $site ), $tmpl );
			return $tmpl;
		}

		/**
		 * WC's preview pane in /wp-admin/admin.php?page=wc-settings&tab=email reads
		 * this method. Return a sample-rendered email so the preview doesn't 500
		 * when there's no live order to model.
		 *
		 * @return string
		 */
		public function get_content_html(): string {
			if ( is_object( $this->object ) ) {
				return function_exists( 'lafka_review_email_render_body' )
					? lafka_review_email_render_body( $this->object, $this )
					: '';
			}
			// Synthesise a preview when called outside trigger() context.
			return function_exists( 'lafka_review_email_render_preview' )
				? lafka_review_email_render_preview( $this )
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
