<?php
/**
 * Lafka_Email_Weekly_Insights — the Monday-morning owner email (WC_Email).
 *
 * Being a WC_Email, its on/off switch and recipient live in WooCommerce →
 * Settings → Emails → "Weekly Insights" (enabled by default once the Insights
 * module is on; the recipient defaults to the site admin email). The body is
 * the plain-English summary from Lafka_Insights_Narrative::build() plus a
 * link to Lafka → Insights, wrapped in WooCommerce's own email header/footer.
 *
 * Triggered by the weekly Action Scheduler job
 * (Lafka_Insights::send_weekly_email → `lafka_insights_weekly_email_trigger`).
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

if ( ! class_exists( 'Lafka_Email_Weekly_Insights' ) ) {

	class Lafka_Email_Weekly_Insights extends WC_Email {

		/** @var array<string,mixed> The report being sent. */
		public $report = array();

		/**
		 * Configure + bind the trigger.
		 */
		public function __construct() {
			$this->id             = 'lafka_weekly_insights';
			$this->customer_email = false;
			$this->title          = __( 'Weekly Insights', 'lafka-plugin' );
			$this->description    = __( 'Every Monday morning: last week in plain English — visits, where people dropped out and why, items viewed but not ordered, and payment problems (Lafka Insights).', 'lafka-plugin' );
			$this->template_base  = '';
			$this->template_html  = '';
			$this->template_plain = '';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
			);

			add_action( 'lafka_insights_weekly_email_trigger', array( $this, 'trigger' ), 10, 1 );

			parent::__construct();

			$recipient       = method_exists( $this, 'get_option' ) ? trim( (string) $this->get_option( 'recipient', '' ) ) : '';
			$this->recipient = '' !== $recipient ? $recipient : (string) get_option( 'admin_email' );
		}

		/**
		 * @return string
		 */
		public function get_default_subject(): string {
			return __( '[{site_title}] Your week online: visits, orders and what got in the way', 'lafka-plugin' );
		}

		/**
		 * @return string
		 */
		public function get_default_heading(): string {
			return __( 'Last week at a glance', 'lafka-plugin' );
		}

		/**
		 * Send the email for a report.
		 *
		 * @param array<string,mixed> $report Lafka_Insights_Queries report.
		 * @return bool Whether a send was attempted.
		 */
		public function trigger( $report = array() ): bool {
			$this->report = is_array( $report ) ? $report : array();
			if ( ! $this->is_enabled() || '' === (string) $this->get_recipient() ) {
				return false;
			}
			$this->setup_locale();
			$sent = $this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content_html(),
				$this->get_headers(),
				$this->get_attachments()
			);
			$this->restore_locale();
			return (bool) $sent;
		}

		/**
		 * The sentences for the current report.
		 *
		 * @return array<int,string>
		 */
		public function sentences(): array {
			return Lafka_Insights_Narrative::build( $this->report );
		}

		/**
		 * HTML body (WooCommerce header/footer + the sentences + dashboard link).
		 *
		 * @return string
		 */
		public function get_content_html(): string {
			ob_start();
			do_action( 'woocommerce_email_header', $this->get_heading(), $this );
			echo '<ul style="margin:0 0 16px 0;padding-left:18px;">';
			foreach ( $this->sentences() as $sentence ) {
				echo '<li style="margin:0 0 8px 0;font-size:15px;line-height:1.5;">' . esc_html( $sentence ) . '</li>';
			}
			echo '</ul>';
			$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=lafka-insights' ) : '';
			if ( '' !== $url ) {
				echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Lafka Insights', 'lafka-plugin' ) . '</a></p>';
			}
			do_action( 'woocommerce_email_footer', $this );
			return (string) ob_get_clean();
		}

		/**
		 * Plain-text body.
		 *
		 * @return string
		 */
		public function get_content_plain(): string {
			$lines = array_map(
				static function ( $s ) {
					return '- ' . $s;
				},
				$this->sentences()
			);
			return implode( "\n", $lines ) . "\n";
		}

		/**
		 * Settings fields: enabled (default yes), recipient, subject, heading.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'    => array(
					'title'   => __( 'Enable/Disable', 'lafka-plugin' ),
					'type'    => 'checkbox',
					'label'   => __( 'Send the weekly Insights email', 'lafka-plugin' ),
					'default' => 'yes',
				),
				'recipient'  => array(
					'title'       => __( 'Recipient(s)', 'lafka-plugin' ),
					'type'        => 'text',
					/* translators: %s: admin email. */
					'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'lafka-plugin' ), '<code>' . esc_html( (string) get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
				),
				'subject'    => array(
					'title'       => __( 'Subject', 'lafka-plugin' ),
					'type'        => 'text',
					'placeholder' => $this->get_default_subject(),
					'default'     => '',
				),
				'heading'    => array(
					'title'       => __( 'Email heading', 'lafka-plugin' ),
					'type'        => 'text',
					'placeholder' => $this->get_default_heading(),
					'default'     => '',
				),
				'email_type' => array(
					'title'   => __( 'Email type', 'lafka-plugin' ),
					'type'    => 'select',
					'default' => 'html',
					'class'   => 'email_type wc-enhanced-select',
					'options' => $this->get_email_type_options(),
				),
			);
		}
	}
}
