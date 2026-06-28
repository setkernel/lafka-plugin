<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pure token-verification helpers (P2-02a). Loaded at file scope so the static
// token methods below work whenever this class is loaded, independent of instance().
require_once __DIR__ . '/includes/class-lafka-kds-token.php';

class Lafka_Kitchen_Display {
	/**
	 * The single instance of the class.
	 */
	protected static $_instance = null;

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->includes();
		$this->init();
	}

	/**
	 * Instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() emits to error log + do_action hook, not HTML; escaping would corrupt plain-text log output.
		_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() emits to error log + do_action hook, not HTML; escaping would corrupt plain-text log output.
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Load dependencies.
	 */
	private function includes() {
		require_once __DIR__ . '/includes/class-lafka-kds-order-statuses.php';
		require_once __DIR__ . '/includes/class-lafka-kds-order-formatter.php';
		require_once __DIR__ . '/includes/class-lafka-kds-ajax.php';
		require_once __DIR__ . '/includes/class-lafka-kds-frontend.php';
		require_once __DIR__ . '/includes/class-lafka-kds-customer-view.php';
		// Email classes loaded lazily in register_emails() — WC_Email not available yet

		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-lafka-kds-admin.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init() {
		new Lafka_KDS_Order_Statuses();
		new Lafka_KDS_Ajax();
		new Lafka_KDS_Frontend();
		new Lafka_KDS_Customer_View();

		if ( is_admin() ) {
			new Lafka_KDS_Admin();
		}

		// Register email classes
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );

		// Add KDS notification email to WC new-order recipient list
		add_filter( 'woocommerce_email_recipient_new_order', array( $this, 'add_kds_admin_to_new_order' ), 10, 2 );
	}

	/**
	 * Register WC email classes.
	 */
	public function register_emails( $email_classes ) {
		// Base must load before subclasses so PHP can resolve the `extends` reference.
		require_once __DIR__ . '/includes/class-lafka-kds-email-base.php';
		require_once __DIR__ . '/includes/class-lafka-kds-email-accepted.php';
		require_once __DIR__ . '/includes/class-lafka-kds-email-preparing.php';
		require_once __DIR__ . '/includes/class-lafka-kds-email-ready.php';
		require_once __DIR__ . '/includes/class-lafka-kds-email-rejected.php';

		$email_classes['Lafka_KDS_Email_Accepted']  = new Lafka_KDS_Email_Accepted();
		$email_classes['Lafka_KDS_Email_Preparing'] = new Lafka_KDS_Email_Preparing();
		$email_classes['Lafka_KDS_Email_Ready']     = new Lafka_KDS_Email_Ready();
		$email_classes['Lafka_KDS_Email_Rejected']  = new Lafka_KDS_Email_Rejected();

		return $email_classes;
	}

	/**
	 * Add the KDS order notification email to WooCommerce "New Order" email recipients.
	 *
	 * This is the only admin-facing email — status-change emails go to the customer only.
	 */
	public function add_kds_admin_to_new_order( $recipient, $order ) {
		$options   = self::get_options();
		$kds_email = sanitize_email( $options['order_notification_email'] );

		if ( ! $kds_email || ! is_email( $kds_email ) ) {
			return $recipient;
		}

		// Avoid duplicating if the email is already in the recipient list
		$recipients = array_map( 'trim', explode( ',', $recipient ) );
		if ( in_array( $kds_email, $recipients, true ) ) {
			return $recipient;
		}

		return $recipient . ', ' . $kds_email;
	}

	/**
	 * Get KDS options with defaults.
	 */
	public static function get_options() {
		$defaults = array(
			'token'                    => '',
			'pickup_times'             => '30,45,60',
			'delivery_times'           => '60,75,90',
			'sound_enabled'            => '1',
			'poll_interval'            => 12,
			'customer_poll_interval'   => 20,
			'order_notification_email' => '',
		);

		$options = get_option( 'lafka_kds_options', array() );

		return wp_parse_args( $options, $defaults );
	}

	/**
	 * HMAC of a raw access token (P2-02a hash-at-rest). See Lafka_KDS_Token::hash().
	 */
	public static function hash_token( $raw ) {
		return Lafka_KDS_Token::hash( $raw );
	}

	/**
	 * Whether the stored value is a hash-at-rest digest. See Lafka_KDS_Token::is_hashed().
	 */
	public static function is_hashed_token( $value ) {
		return Lafka_KDS_Token::is_hashed( $value );
	}

	/**
	 * Verify a candidate raw token against the stored token (legacy plaintext OR
	 * hash-at-rest). Single source of truth for KDS auth — used by the standalone
	 * page and every AJAX endpoint. See Lafka_KDS_Token::matches().
	 */
	public static function token_matches( $candidate ) {
		$options = self::get_options();
		return Lafka_KDS_Token::matches( isset( $options['token'] ) ? $options['token'] : '', $candidate );
	}

	/**
	 * Record last-seen IP + time for the KDS token (anomaly detection), throttled to
	 * once per 5 minutes and stored non-autoloaded to avoid churn on the polling hot path.
	 */
	public static function record_token_activity() {
		$now  = time();
		$last = get_option( 'lafka_kds_token_activity', array() );
		if ( is_array( $last ) && isset( $last['time'] ) && ( $now - (int) $last['time'] ) < 300 ) {
			return;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		update_option(
			'lafka_kds_token_activity',
			array(
				'time' => $now,
				'ip'   => $ip,
			),
			false
		);
	}

	/**
	 * Detect order type (pickup or delivery).
	 */
	public static function get_order_type( $order ) {
		// Check lafka meta first
		$type = $order->get_meta( 'lafka_order_type' );
		if ( $type ) {
			return $type;
		}

		// Fallback: check shipping methods
		$shipping_methods = $order->get_shipping_methods();
		foreach ( $shipping_methods as $method ) {
			$method_id = $method->get_method_id();
			if ( 'local_pickup' === $method_id ) {
				return 'pickup';
			}
		}

		// If no shipping methods at all, treat as pickup
		if ( empty( $shipping_methods ) ) {
			return 'pickup';
		}

		return 'delivery';
	}
}

// Guarded so unit tests can load the class for its static token helpers without
// booting the full subsystem (which calls is_admin() etc.). Matches the repo's
// LAFKA_TESTING auto-init convention.
if ( ! defined( 'LAFKA_TESTING' ) ) {
	Lafka_Kitchen_Display::instance();
}
