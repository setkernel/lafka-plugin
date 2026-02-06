<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Load dependencies.
	 */
	private function includes() {
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-order-statuses.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-ajax.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-frontend.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-customer-view.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-email-accepted.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-email-ready.php';

		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/includes/class-lafka-kds-admin.php';
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
	}

	/**
	 * Register WC email classes.
	 */
	public function register_emails( $email_classes ) {
		$email_classes['Lafka_KDS_Email_Accepted'] = new Lafka_KDS_Email_Accepted();
		$email_classes['Lafka_KDS_Email_Ready']    = new Lafka_KDS_Email_Ready();

		return $email_classes;
	}

	/**
	 * Get KDS options with defaults.
	 */
	public static function get_options() {
		$defaults = array(
			'token'                  => '',
			'pickup_times'           => '30,45,60',
			'delivery_times'         => '60,75,90',
			'sound_enabled'          => '1',
			'poll_interval'          => 12,
			'customer_poll_interval' => 20,
		);

		$options = get_option( 'lafka_kds_options', array() );

		return wp_parse_args( $options, $defaults );
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

Lafka_Kitchen_Display::instance();
