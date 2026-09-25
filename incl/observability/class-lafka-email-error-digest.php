<?php
/**
 * Lafka_Email_Error_Digest — daily "something needs attention" email (GX1 / A8).
 *
 * A WC_Email, so the on/off switch, recipient (default: the site admin email),
 * subject and heading live in WooCommerce → Settings → Emails like every other
 * store email. Sent by the daily Diagnostics job only when there are NEW (or
 * recurring since the last digest) open incidents: payment, checkout, PHP or
 * JS warnings and anything at error level. Lazy-loaded from
 * Lafka_Diagnostics::register_email() once WC_Email exists.
 *
 * The body lists incident summaries only (already PII-scrubbed on write) and
 * links to Lafka → Diagnostics.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Email' ) && ! class_exists( 'Lafka_Email_Error_Digest' ) ) {

	/**
	 * Admin error digest.
	 */
	class Lafka_Email_Error_Digest extends WC_Email {

		/** @var array<int,object> Incidents being reported. */
		private $rows = array();

		/**
		 * Email identity + defaults.
		 */
		public function __construct() {
			$this->id             = 'lafka_error_digest';
			$this->customer_email = false;
			$this->title          = __( 'Lafka error digest', 'lafka-plugin' );
			$this->description    = __( 'Daily summary sent to the store owner when new payment failures, checkout problems or site errors were recorded. Only sent when something new happened.', 'lafka-plugin' );
			$this->template_base  = '';
			$this->template_html  = '';
			$this->template_plain = '';
			$this->placeholders   = array(
				'{site_title}' => $this->get_blogname(),
			);

			parent::__construct();

			if ( method_exists( $this, 'get_option' ) ) {
				$this->recipient = (string) $this->get_option( 'recipient', function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '' );
			}
		}

		/**
		 * @return string
		 */
		public function get_default_subject() {
			return __( '[{site_title}] New problems need your attention', 'lafka-plugin' );
		}

		/**
		 * @return string
		 */
		public function get_default_heading() {
			return __( 'Store health: new problems', 'lafka-plugin' );
		}

		/**
		 * Settings: enabled (default yes), recipient (default admin email), subject, heading, type.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$placeholder_text  = sprintf( __( 'Available placeholders: %s', 'lafka-plugin' ), '<code>{site_title}</code>' );
			$this->form_fields = array(
				'enabled'    => array(
					'title'   => __( 'Enable/Disable', 'lafka-plugin' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'lafka-plugin' ),
					'default' => 'yes',
				),
				'recipient'  => array(
					'title'       => __( 'Recipient(s)', 'lafka-plugin' ),
					'type'        => 'text',
					'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'lafka-plugin' ), '<code>' . esc_html( (string) get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
				'subject'    => array(
					'title'       => __( 'Subject', 'lafka-plugin' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_subject(),
					'default'     => '',
				),
				'heading'    => array(
					'title'       => __( 'Email heading', 'lafka-plugin' ),
					'type'        => 'text',
					'desc_tip'    => true,
					'description' => $placeholder_text,
					'placeholder' => $this->get_default_heading(),
					'default'     => '',
				),
				'email_type' => array(
					'title'       => __( 'Email type', 'lafka-plugin' ),
					'type'        => 'select',
					'description' => __( 'Choose which format of email to send.', 'lafka-plugin' ),
					'default'     => 'html',
					'class'       => 'email_type wc-enhanced-select',
					'options'     => $this->get_email_type_options(),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Recipient falls back to the admin email when the field is empty.
		 *
		 * @return string
		 */
		public function get_recipient(): string {
			$recipient = (string) parent::get_recipient();
			if ( '' === trim( $recipient ) && function_exists( 'get_option' ) ) {
				$recipient = (string) get_option( 'admin_email' );
			}
			return $recipient;
		}

		/**
		 * Send the digest for the given incidents.
		 *
		 * @param array<int,object> $rows Incidents (Lafka_Incidents::pending_digest()).
		 * @return bool Whether the email was sent.
		 */
		public function trigger( $rows = array() ) {
			$this->rows = is_array( $rows ) ? array_values( $rows ) : array();
			if ( empty( $this->rows ) || ! $this->is_enabled() || '' === $this->get_recipient() ) {
				return false;
			}
			$this->setup_locale();
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			$this->restore_locale();
			return $sent;
		}

		/**
		 * HTML body inside the store's email header/footer.
		 *
		 * @return string
		 */
		public function get_content_html() {
			ob_start();
			do_action( 'woocommerce_email_header', $this->get_heading(), $this );
			echo self::render_rows_html( $this->rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside render_rows_html().
			do_action( 'woocommerce_email_footer', $this );
			return (string) ob_get_clean();
		}

		/**
		 * Plain-text body.
		 *
		 * @return string
		 */
		public function get_content_plain() {
			return self::render_rows_plain( $this->rows );
		}

		/**
		 * Incident table (HTML, escaped). Public + static for tests and previews.
		 *
		 * @param array<int,object|array> $rows Incidents.
		 * @return string
		 */
		public static function render_rows_html( array $rows ): string {
			$html  = '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of problems */
					_n( '%d problem was recorded since the last summary:', '%d problems were recorded since the last summary:', count( $rows ), 'lafka-plugin' ),
					count( $rows )
				)
			) . '</p>';
			$html .= '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;">';
			$html .= '<thead><tr><th style="text-align:left;">' . esc_html__( 'Problem', 'lafka-plugin' ) . '</th><th style="text-align:left;">' . esc_html__( 'Area', 'lafka-plugin' ) . '</th><th style="text-align:right;">' . esc_html__( 'Times', 'lafka-plugin' ) . '</th><th style="text-align:left;">' . esc_html__( 'Last seen', 'lafka-plugin' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$row   = (array) $row;
				$html .= '<tr>'
					. '<td>' . esc_html( self::describe( $row ) ) . '</td>'
					. '<td>' . esc_html( (string) ( $row['channel'] ?? '' ) . ' / ' . (string) ( $row['level'] ?? '' ) ) . '</td>'
					. '<td style="text-align:right;">' . esc_html( (string) (int) ( $row['hit_count'] ?? 0 ) ) . '</td>'
					. '<td>' . esc_html( self::local_time( (string) ( $row['last_seen'] ?? '' ) ) ) . '</td>'
					. '</tr>';
			}
			$html .= '</tbody></table>';
			$url   = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=lafka-diagnostics' ) : '';
			if ( '' !== $url ) {
				$html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Lafka → Diagnostics', 'lafka-plugin' ) . '</a></p>';
			}
			return $html;
		}

		/**
		 * Incident list (plain text).
		 *
		 * @param array<int,object|array> $rows Incidents.
		 * @return string
		 */
		public static function render_rows_plain( array $rows ): string {
			$lines = array();
			foreach ( $rows as $row ) {
				$row     = (array) $row;
				$lines[] = sprintf(
					'- %1$s [%2$s/%3$s] x%4$d — %5$s',
					self::describe( $row ),
					(string) ( $row['channel'] ?? '' ),
					(string) ( $row['level'] ?? '' ),
					(int) ( $row['hit_count'] ?? 0 ),
					self::local_time( (string) ( $row['last_seen'] ?? '' ) )
				);
			}
			$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=lafka-diagnostics' ) : '';
			return wp_strip_all_tags( implode( "\n", $lines ) ) . ( '' !== $url ? "\n\n" . $url : '' );
		}

		/**
		 * Friendly line for an incident: payment/checkout reasons get their
		 * operator label; everything else shows the (scrubbed) message.
		 *
		 * @param array<string,mixed> $row Incident.
		 * @return string
		 */
		private static function describe( array $row ): string {
			$code = (string) ( $row['code'] ?? '' );
			if ( class_exists( 'Lafka_Checkout_Block_Reasons' ) && Lafka_Checkout_Block_Reasons::is_known( $code ) ) {
				return Lafka_Checkout_Block_Reasons::label( $code );
			}
			return (string) ( $row['message'] ?? '' );
		}

		/**
		 * UTC MySQL datetime → site-local display.
		 *
		 * @param string $gmt Y-m-d H:i:s (UTC).
		 * @return string
		 */
		private static function local_time( string $gmt ): string {
			if ( '' === $gmt ) {
				return '';
			}
			return function_exists( 'get_date_from_gmt' ) ? (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' ) : $gmt;
		}
	}
}
