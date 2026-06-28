<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Shipping_Areas {
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
	public static function instance(): ?Lafka_Shipping_Areas {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() emits to error log + do_action hook, not HTML; escaping would corrupt plain-text log output.
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Function for loading dependencies.
	 */
	private function includes() {
		$options = get_option( 'lafka_shipping_areas_branches' );

		// Map shortcode — extracted to incl/map-shortcode/ in v9.3.0 (Path A4).
		require_once __DIR__ . '/../map-shortcode/shortcode-lafka-shipping-areas.php';

		// Timeslots module — extracted to incl/timeslots/ in v9.4.0 (Path A3).
		// Self-registers on instance() — pulls own datetime config + hooks.
		require_once __DIR__ . '/../timeslots/class-lafka-timeslots.php';
		Lafka_Timeslots::instance();

		// Branches module — extracted to incl/branches/ in v9.2.0 (Path A2).
		if ( ! empty( $options['enable_branch_selection_modal'] ) ) {
			require_once __DIR__ . '/../branches/class-lafka-branch-locations.php';
		}

		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-lafka-shipping-areas-admin.php';
			require_once __DIR__ . '/../branches/class-lafka-branch-locations-admin.php';
			if ( function_exists( 'vc_lean_map' ) ) {
				vc_lean_map( 'lafka_shipping_areas', null, __DIR__ . '/../map-shortcode/shortcode-lafka-shipping-areas-to-vc.php' );
			}
		}
	}

	private function init() {
		// Register Lafka Shipping Areas post type
		add_action( 'init', array( $this, 'register_lafka_shipping_areas' ) );
		add_action( 'init', array( $this, 'register_branch_locations_taxonomy' ) );

		// Scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Shipping rate calculation is handled by WooCommerce Distance Rate
		// Shipping (the official upstream plugin) since v9.1.0. Lafka's own
		// distance-shipping-method, which had ~700 lines of duplicate impl,
		// was retired here. The branches/datetime/marketing-map features
		// below remain Lafka's own.

		// Inject delivery address into ajax update_order_review response
		add_action( 'woocommerce_update_order_review_fragments', array( $this, 'update_order_review_fragments' ) );
		// Show picked location in order
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show_checkout_fields_admin_order_meta' ), 10, 1 );
		// Add map to check out
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'add_map_to_checkout' ) );
		// Validate "mandatory to pick address"
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_field_process' ) );
		// Store picked map location to order. `woocommerce_checkout_update_order_meta`
		// was deprecated in WC 9.0; `woocommerce_checkout_create_order` fires before
		// the order is saved on the classic checkout path, receives WC_Order directly,
		// and is HPOS-safe without branching.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'checkout_update_order_meta' ), 10, 2 );

		// `lowest_cost_shipping`, `hide_shipping_cost_at_cart`,
		// `deactivate_post_code`, `disable_state` settings were tied to the
		// retired Lafka shipping method. WC Distance Rate Shipping handles
		// these concerns natively (or doesn't need them). Settings are no
		// longer wired to any behaviour.

		$branches_options = get_option( 'lafka_shipping_areas_branches' );
		if ( ! empty( $branches_options['hide_address_fields'] ) ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'disable_address_fields' ) );
		}

		// Datetime/timeslot hooks moved to Lafka_Timeslots in v9.4.0
		// (Path A3). The new class self-registers on instance() —
		// see includes() above.

		// Output order type, time for delivery/pickup, branch, picked delivery location in all places
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'output_custom_fields_in_thank_you_page' ), 10, 3 );

		// Handle order emails for branch managers
		if ( empty( $branches_options['disable_order_emails'] ) ) {
			foreach ( array( 'cancelled_order', 'failed_order', 'new_order' ) as $email_type_id ) {
				add_filter( 'woocommerce_email_recipient_' . $email_type_id, array( __CLASS__, 'add_recipient_to_order_emails' ), 10, 3 );
			}
		}
	}

	public function register_lafka_shipping_areas() {
		$labels = array(
			'name'               => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
			'singular_name'      => __( 'Lafka Shipping Area', 'lafka-plugin' ),
			'name_admin_bar'     => __( 'Lafka Shipping Area', 'lafka-plugin' ),
			'add_new'            => __( 'Add New', 'lafka-plugin' ),
			'add_new_item'       => __( 'Add New Lafka Shipping Area', 'lafka-plugin' ),
			'new_item'           => __( 'New Lafka Shipping Area', 'lafka-plugin' ),
			'edit_item'          => __( 'Edit Lafka Shipping Area', 'lafka-plugin' ),
			'view_item'          => __( 'View Lafka Shipping Area', 'lafka-plugin' ),
			'all_items'          => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
			'search_items'       => __( 'Search Lafka Shipping Areas', 'lafka-plugin' ),
			'parent_item_colon'  => '',
			'not_found'          => __( 'Nothing found.', 'lafka-plugin' ),
			'not_found_in_trash' => __( 'Nothing found in Trash.', 'lafka-plugin' ),
		);

		$args = array(
			'labels'                => $labels,
			'public'                => true,
			'publicly_queryable'    => false,
			'show_ui'               => true,
			'show_in_menu'          => 'woocommerce',
			// PERF-29: shipping-areas posts hold delivery-zone configuration
			// (lat/lng, fees, time windows, branch metadata). The CPT is
			// `publicly_queryable => false` so it doesn't surface in archives or
			// search, but `show_in_rest => true` was unauthenticated-readable at
			// `/wp-json/wp/v2/lafka-shipping-areas` — anyone could scrape every
			// branch's coordinates, fees, and hours. Disable REST exposure;
			// the admin UI uses its own AJAX endpoints, not the REST API.
			'show_in_rest'          => false,
			'query_var'             => true,
			'rewrite'               => false,
			'capability_type'       => 'product',
			'hierarchical'          => false,
			'menu_position'         => null,
			'supports'              => array( 'title', 'author' ),
		);

		register_post_type( 'lafka_shipping_areas', $args );

		add_shortcode( 'lafka_shipping_areas', 'lafka_shipping_areas_shortcode' );
	}

	public function register_branch_locations_taxonomy() {
		$labels = array(
			'name'          => esc_html__( 'Lafka Branch Locations', 'lafka-plugin' ),
			'singular_name' => esc_html__( 'Branch Location', 'lafka-plugin' ),
			'search_items'  => esc_html__( 'Search Branch Location', 'lafka-plugin' ),
			'all_items'     => esc_html__( 'All Branch Location', 'lafka-plugin' ),
			'edit_item'     => esc_html__( 'Edit Branch Location', 'lafka-plugin' ),
			'update_item'   => esc_html__( 'Update Branch Location', 'lafka-plugin' ),
			'add_new_item'  => esc_html__( 'Add New Branch Location', 'lafka-plugin' ),
			'back_to_items' => esc_html__( 'Go to Branch Locations', 'lafka-plugin' ),
			'not_found'     => esc_html__( 'No Branch Locations found', 'lafka-plugin' ),
			'menu_name'     => esc_html__( 'Lafka Branch Locations', 'lafka-plugin' ),
		);
		$args   = array(
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'labels'             => $labels,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
		);

		register_taxonomy( 'lafka_branch_location', 'product', $args );
	}

	public function get_all_delivery_areas(): array {
		$args = array(
			'numberposts' => 100,
			'post_type'   => 'lafka_shipping_areas',
			'post_status' => 'publish',
			'orderby'     => 'title',
		);

		return get_posts( $args );
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'lafka-shipping-areas-front', plugins_url( 'assets/css/frontend/lafka-shipping-areas-front.css', __FILE__ ), array(), lafka_plugin_asset_version( 'incl/shipping-areas/assets/css/frontend/lafka-shipping-areas-front.css' ) );

		if ( is_cart() || is_checkout() ) {
			// The handle-shipping JS uses Google Maps for geo-fencing the
			// customer's address against zone polygons. With no key the JS
			// has no way to validate, so skip it — server-side validation in
			// `validate_checkout_field_process` independently enforces the
			// pinpoint's presence, valid lat/lng, and a point-in-polygon test
			// against the published delivery zones, so the order is still gated
			// even when the client-side map never loads.
			if ( wp_script_is( 'lafka-google-maps', 'registered' ) ) {
				wp_enqueue_script(
					'lafka-shipping-areas-handle-shipping',
					plugins_url( 'assets/js/frontend/lafka-shipping-areas-handle-shipping.min.js', __FILE__ ),
					array(
						'jquery',
						'lafka-google-maps',
						'jquery-blockui',
					),
					lafka_plugin_asset_version( 'incl/shipping-areas/assets/js/frontend/lafka-shipping-areas-handle-shipping.min.js' ),
					true
				);
			}

			$options                 = get_option( 'lafka_shipping_areas_general' );
			$options_advanced        = get_option( 'lafka_shipping_areas_advanced' );
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );

			// Init a properties variable
			wp_add_inline_script(
				'lafka-shipping-areas-handle-shipping',
				'
				const lafka_shipping_properties = {};
				const lafka_no_shipping_methods_string = ' . wp_json_encode( __( 'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.', 'lafka-plugin' ) ) . ';
				const lafka_debug_mode = ' . ( empty( $options_advanced['debug_mode'] ) ? 'false' : 'true' ) . ';
				const lafka_lowest_cost_shipping = ' . ( empty( $options['lowest_cost_shipping'] ) ? 'false' : 'true' ) . ';
				const lafka_store_address = ' . wp_json_encode( self::get_store_address() ) . ';
				const lafka_set_store_location = ' . wp_json_encode( empty( $options_advanced['set_store_location'] ) ? 'geo_woo_store' : $options_advanced['set_store_location'] ) . ';
				const lafka_store_map_location = ' . wp_json_encode( empty( $options_advanced['store_map_location'] ) ? '' : $options_advanced['store_map_location'] ) . ';
				const lafka_order_type = ' . wp_json_encode( empty( $branch_location_session['order_type'] ) ? '' : $branch_location_session['order_type'] ) . ';
				',
				'before'
			);
		}

		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );
		if ( is_checkout() && ! empty( $datetime_options['enable_datetime_option'] ) ) {
			// Pull config from Lafka_Timeslots which already loaded it on init
			// with the per-branch overrides applied.
			$timeslots          = Lafka_Timeslots::instance();
			$days_ahead         = $timeslots->get_days_ahead();
			$timeslot_duration  = $timeslots->get_timeslot_duration();
			$datetime_mandatory = $timeslots->is_mandatory();

			if ( class_exists( 'Lafka_Order_Hours' ) && ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
				$enabled_dates = Lafka_Timeslots::get_enabled_dates_for_days_ahead( $days_ahead, $timeslot_duration );
			} else {
				$enabled_dates = Lafka_Timeslots::get_all_days_ahead_public( $days_ahead );
			}
			$flatpickr_locale = apply_filters( 'lafka_flatpickr_locale', strtok( get_locale(), '_' ), get_locale() );
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_script( 'flatpickr-local' );
			wp_enqueue_script( 'lafka-shipping-datetime', plugins_url( 'assets/js/frontend/lafka-shipping-datetime.min.js', __FILE__ ), array( 'jquery', 'select2', 'flatpickr' ), lafka_plugin_asset_version( 'incl/shipping-areas/assets/js/frontend/lafka-shipping-datetime.min.js' ), true );
			wp_localize_script(
				'lafka-shipping-datetime',
				'lafka_datetime_options',
				array(
					'is_order_hours_enabled' => class_exists( 'Lafka_Order_Hours' ),
					'days_ahead'             => $days_ahead,
					'enabled_dates'          => $enabled_dates,
					'ajax_url'               => admin_url( 'admin-ajax.php' ),
					'nonce'                  => wp_create_nonce( 'time_slots_for_date' ),
					'select_time_label'      => esc_html__( 'Select time...', 'lafka-plugin' ),
					'datetime_mandatory'     => $datetime_mandatory,
					'flatpickr_locale'       => $flatpickr_locale,
				)
			);
		}
	}

	public function update_order_review_fragments( $fragments ) {
		if ( WC()->customer->has_shipping_address() ) {
			$delivery_address = WC()->customer->get_shipping();
		} else {
			$delivery_address = WC()->customer->get_billing();
		}
		$all_countries                     = WC()->countries->get_countries();
		$delivery_address['country_label'] = $all_countries[ $delivery_address['country'] ] ?? '';
		$fragments['delivery_address']     = $delivery_address;

		return $fragments;
	}

	public function checkout_update_order_meta( $order, $data = null ) {
		// Backward-compat: legacy hook passed an int order_id.
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// CSRF: hooked to woocommerce_checkout_order_processed; WC core verifies
		// its own checkout nonce upstream before this hook fires.
		// Malformed / out-of-zone input never reaches the persisted order:
		// validate_checkout_field_process() runs on the earlier
		// woocommerce_checkout_process hook and aborts the checkout (via
		// wc_add_notice) before this create-order hook fires. We re-validate the
		// lat/lng bounds here as a final guard and simply skip the meta write if
		// the payload is unusable, rather than silently storing junk.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core verifies checkout nonce upstream.
		if ( ! empty( $_POST['lafka_picked_delivery_geocoded'] ) && ! empty( $_POST['lafka_is_location_clicked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core verifies checkout nonce upstream.
			$raw_geocoded = wp_unslash( $_POST['lafka_picked_delivery_geocoded'] );
			$decoded      = json_decode( $raw_geocoded );

			// Validate JSON structure contains valid lat/lng
			if ( $decoded !== null && isset( $decoded->lat, $decoded->lng ) ) {
				$lat = floatval( $decoded->lat );
				$lng = floatval( $decoded->lng );
				if ( $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 ) {
					$safe_value = wp_json_encode(
						array(
							'lat' => $lat,
							'lng' => $lng,
						)
					);
					// woocommerce_checkout_create_order fires BEFORE save, so
					// update_meta_data on the in-memory WC_Order is enough — the
					// caller persists. Works identically under HPOS and CPT.
					$order->update_meta_data( 'lafka_picked_delivery_geocoded', $safe_value );
				}
			}
		}
	}

	public static function add_recipient_to_order_emails( $recipient, $object, $wc_email_object ): string {
		if ( $object instanceof \Automattic\WooCommerce\Admin\Overrides\Order ) {
			$recipients      = array_map( 'trim', explode( ',', $recipient ) );
			$order_branch_id = self::get_order_meta_backward_compatible( $object->get_id(), 'lafka_selected_branch_id' );
			if ( ! empty( $order_branch_id ) ) {
				$branch_user_id   = get_term_meta( $order_branch_id, 'lafka_branch_user', true );
				$branch_user_data = get_userdata( $branch_user_id );
				if ( $branch_user_data && ! empty( $branch_user_data->user_email ) ) {
					$recipients[] = $branch_user_data->user_email;
				}
			}

			return implode( ',', $recipients );
		}

		return $recipient;
	}

	public static function output_custom_fields_in_thank_you_page( $total_rows, $order, $tax_display ) {
		$order_type                     = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_order_type' );
		$lafka_checkout_date            = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
		$lafka_checkout_timeslot        = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );
		$lafka_selected_branch_id       = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_selected_branch_id' );
		$lafka_picked_delivery_geocoded = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_picked_delivery_geocoded' );

		$order_type_label = esc_html__( 'Delivery', 'lafka-plugin' );
		if ( ! empty( $order_type ) ) {
			if ( $order_type === 'pickup' ) {
				$order_type_label = esc_html__( 'Pickup', 'lafka-plugin' );
			}
			$total_rows['lafka_order_type'] = array(
				'label' => esc_html__( 'Order Type:', 'lafka-plugin' ),
				'value' => $order_type_label,
			);
		}
		if ( ! empty( $lafka_checkout_date ) ) {
			$total_rows['lafka_checkout_date'] = array(
				'label' => esc_html( $order_type_label ) . ' ' . esc_html__( 'Date:', 'lafka-plugin' ),
				'value' => date_i18n( get_option( 'date_format' ), DateTime::createFromFormat( 'Y-m-d', $lafka_checkout_date )->getTimestamp() ),
			);
		}
		if ( ! empty( $lafka_checkout_timeslot ) ) {
			$total_rows['lafka_checkout_timeslot'] = array(
				'label' => esc_html( $order_type_label ) . ' ' . esc_html__( 'Time:', 'lafka-plugin' ),
				'value' => esc_html( $lafka_checkout_timeslot ),
			);
		}
		if ( ! empty( $lafka_selected_branch_id ) ) {
			$branch_location = get_term( $lafka_selected_branch_id, 'lafka_branch_location' );
			if ( ! empty( $branch_location ) ) {
				$total_rows['lafka_selected_branch_id'] = array(
					'label' => esc_html__( 'Branch:', 'lafka-plugin' ),
					'value' => esc_html( $branch_location->name ),
				);
			}
		}
		if ( ! empty( $lafka_picked_delivery_geocoded ) && is_string( $lafka_picked_delivery_geocoded ) ) {
			$location = json_decode( $lafka_picked_delivery_geocoded );
			if ( $location !== null && isset( $location->lat ) ) {
				$total_rows['lafka_picked_delivery_geocoded'] = array(
					'label' => esc_html__( 'Picked Delivery Location:', 'lafka-plugin' ),
					'value' => self::get_delivery_location_link( $location ),
				);
			}
		}

		return $total_rows;
	}

	public function show_checkout_fields_admin_order_meta( $order ) {
		echo '<div class="address">';

		$order_type                     = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_order_type' );
		$lafka_checkout_date            = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
		$lafka_checkout_timeslot        = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );
		$lafka_selected_branch_id       = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_selected_branch_id' );
		$lafka_picked_delivery_geocoded = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_picked_delivery_geocoded' );

		$order_type_label = esc_html__( 'Delivery', 'lafka-plugin' );
		if ( ! empty( $order_type ) ) {
			if ( $order_type === 'pickup' ) {
				$order_type_label = esc_html__( 'Pickup', 'lafka-plugin' );
			}
			?>
			<p>
				<strong><?php esc_html_e( 'Order Type', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( $order_type_label ); ?>
			</p>
			<?php
		}
		if ( ! empty( $lafka_checkout_date ) ) {
			?>
			<p>
				<strong><?php echo esc_html( $order_type_label ) . ' ' . esc_html__( 'Date', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), DateTime::createFromFormat( 'Y-m-d', $lafka_checkout_date )->getTimestamp() ) ); ?>
			</p>
			<?php
		}
		if ( ! empty( $lafka_checkout_timeslot ) ) {
			?>
			<p>
				<strong><?php echo esc_html( $order_type_label ) . ' ' . esc_html__( 'Time', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( $lafka_checkout_timeslot ); ?>
			</p>
			<?php
		}
		if ( ! empty( $lafka_selected_branch_id ) ) {
			$branch_location = get_term( $lafka_selected_branch_id, 'lafka_branch_location' );
			if ( ! empty( $branch_location ) ) {
				?>
				<p>
					<strong><?php esc_html_e( 'Branch', 'lafka-plugin' ); ?>:</strong>
					<?php echo esc_html( $branch_location->name ); ?>
				</p>
				<?php
			}
		}
		if ( ! empty( $lafka_picked_delivery_geocoded ) && is_string( $lafka_picked_delivery_geocoded ) ) {
			$location = json_decode( $lafka_picked_delivery_geocoded );
			if ( $location !== null && isset( $location->lat ) ) {
				?>
				<p>
					<strong><?php esc_html_e( 'Picked Delivery Location', 'lafka-plugin' ); ?>:</strong>
					<?php echo wp_kses_post( self::get_delivery_location_link( $location ) ); ?>
				</p>
				<?php
			}
		}
		echo '</div>';
	}

	public function add_map_to_checkout() {
		// WC()->cart is lazy-initialized in WC 10.x and may be null in REST or
		// admin contexts; guard the deref before calling cart methods.
		if ( ! is_checkout() || ! isset( WC()->cart ) || WC()->cart->needs_shipping() === false ) {
			return;
		}

		$options = get_option( 'lafka_shipping_areas_general' );
		if ( empty( $options['pick_delivery_address'] ) ) {
			return;
		}

		if ( empty( $options['mandatory_pickup_delivery'] ) ) {
			$title = esc_html__( 'Address not found! You can pinpoint Your Location on the map.', 'lafka-plugin' );
		} else {
			$title = esc_html__( 'Address not found! Pinpoint Your Location on the map to Continue', 'lafka-plugin' );
		}

		echo '<div id="lafka_pick_delivery_address_field" class="shop_table">';
		echo '<h3 class="lafka-address-not-found">' . esc_html( $title ) . '</h3>';
		echo '<h3 class="lafka-address-marked">' . esc_html__( 'Pinpoint your location on the map if it\'s not accurately marked.', 'lafka-plugin' ) . '</h3>';

		woocommerce_form_field(
			'lafka_picked_delivery_geocoded',
			array(
				'type'  => 'text',
				'class' => array(
					'hidden',
				),
				'label' => esc_html__( 'Please Precise Your Location', 'lafka-plugin' ),
			)
		);
		woocommerce_form_field(
			'lafka_is_location_clicked',
			array(
				'type'  => 'text',
				'class' => array(
					'hidden',
				),
			)
		);

		echo '<div id="lafka-pick-delivery-address-content">';
		echo '<div id="lafka-pick-delivery-address-checkout-map">';
		echo '</div></div></div>';

		wp_add_inline_script(
			'lafka-shipping-areas-handle-shipping',
			'lafka_checkout_map_properties = ' . json_encode(
				array(
					'pick_delivery_address_option' => $options['pick_delivery_address'],
				)
			),
			'before'
		);
	}

	public function validate_checkout_field_process() {
		$options = get_option( 'lafka_shipping_areas_general' );

		// Geo-fencing only applies when pinpoint delivery is on AND mandatory.
		if ( empty( $options['pick_delivery_address'] ) || empty( $options['mandatory_pickup_delivery'] ) ) {
			return;
		}

		// Pickup orders are collected from the branch, so they never carry a
		// delivery pinpoint — skip the whole gate for them, otherwise a legit
		// pickup checkout would be blocked for the missing field.
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session['order_type'] ) && 'pickup' === $branch_location_session['order_type'] ) {
				return;
			}
		}

		// (a) Missing OR blank must both fail. Omitting the hidden field from the
		// POST previously slipped through `isset() && empty()`; `empty()` alone
		// closes that bypass.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- woocommerce_checkout_process context; WC core verifies the checkout nonce upstream.
		if ( empty( $_POST['lafka_picked_delivery_geocoded'] ) ) {
			wc_add_notice( esc_html__( 'Please precise your address on the map.', 'lafka-plugin' ), 'error' );

			return;
		}

		// (c) The payload must decode to numeric lat/lng inside planet bounds.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core verifies the checkout nonce upstream.
		$decoded = json_decode( sanitize_text_field( wp_unslash( $_POST['lafka_picked_delivery_geocoded'] ) ) );
		if ( null === $decoded || ! isset( $decoded->lat, $decoded->lng ) || ! is_numeric( $decoded->lat ) || ! is_numeric( $decoded->lng ) ) {
			wc_add_notice( esc_html__( 'Please precise your address on the map.', 'lafka-plugin' ), 'error' );

			return;
		}

		$lat = (float) $decoded->lat;
		$lng = (float) $decoded->lng;
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			wc_add_notice( esc_html__( 'Please precise your address on the map.', 'lafka-plugin' ), 'error' );

			return;
		}

		// Server-side geo-fence: reproduce the client-side Google Maps check in
		// lafka-shipping-areas-handle-shipping.min.js so a tampered or skipped JS
		// run can't push an out-of-zone address through. The point must fall
		// inside at least one published delivery-zone polygon.
		$polygons = $this->get_published_area_polygons();
		if ( empty( $polygons ) ) {
			// No polygon zones are drawn, so there is nothing to fence against —
			// a valid pinpoint is sufficient.
			return;
		}

		foreach ( $polygons as $polygon ) {
			if ( self::point_in_polygon( $lat, $lng, $polygon ) ) {
				return;
			}
		}

		wc_add_notice( esc_html__( 'The selected location is outside our delivery area. Please pinpoint an address inside the delivery zone.', 'lafka-plugin' ), 'error' );
	}

	/**
	 * Build the list of delivery-zone polygons from every published
	 * lafka_shipping_areas post. Each post stores its polygon as a Google Maps
	 * "Encoded Polyline Algorithm Format" string in
	 * `_lafka_shipping_area_polygon_coordinates` — the very value the frontend
	 * feeds to google.maps.geometry.encoding.decodePath() (and that
	 * get_branch_locations_json_data ships to the client). Decoding it here lets
	 * the server reproduce the client geo-fence.
	 *
	 * @return array List of polygons, each an array of [ lat, lng ] float pairs.
	 */
	private function get_published_area_polygons(): array {
		$polygons = array();

		foreach ( $this->get_all_delivery_areas() as $area ) {
			$encoded = get_post_meta( $area->ID, '_lafka_shipping_area_polygon_coordinates', true );
			if ( ! is_string( $encoded ) || '' === $encoded ) {
				continue;
			}

			$points = self::decode_polygon_coordinates( $encoded );
			if ( count( $points ) >= 3 ) {
				$polygons[] = $points;
			}
		}

		return $polygons;
	}

	/**
	 * Decode a Google Maps "Encoded Polyline Algorithm Format" string into a
	 * list of [ lat, lng ] float pairs. Server-side mirror of
	 * google.maps.geometry.encoding.decodePath() used on the client. Pure
	 * function with no WordPress dependencies, so it is unit-testable.
	 *
	 * @param string $encoded Encoded polyline string.
	 *
	 * @return array List of [ lat, lng ] float pairs.
	 */
	public static function decode_polygon_coordinates( string $encoded ): array {
		$points = array();
		$length = strlen( $encoded );
		$index  = 0;
		$lat    = 0;
		$lng    = 0;

		while ( $index < $length ) {
			// Decode the latitude delta.
			$shift  = 0;
			$result = 0;
			do {
				if ( $index >= $length ) {
					return $points;
				}
				$byte    = ord( $encoded[ $index++ ] ) - 63;
				$result |= ( $byte & 0x1f ) << $shift;
				$shift  += 5;
			} while ( $byte >= 0x20 );
			$lat += ( $result & 1 ) ? ~( $result >> 1 ) : ( $result >> 1 );

			// Decode the longitude delta.
			$shift  = 0;
			$result = 0;
			do {
				if ( $index >= $length ) {
					return $points;
				}
				$byte    = ord( $encoded[ $index++ ] ) - 63;
				$result |= ( $byte & 0x1f ) << $shift;
				$shift  += 5;
			} while ( $byte >= 0x20 );
			$lng += ( $result & 1 ) ? ~( $result >> 1 ) : ( $result >> 1 );

			$points[] = array( $lat / 100000, $lng / 100000 );
		}

		return $points;
	}

	/**
	 * Ray-casting point-in-polygon test (even-odd rule). Shared helper backing
	 * the server-side delivery geo-fence. Pure function with no WordPress
	 * dependencies, so it is unit-testable.
	 *
	 * @param float $lat     Latitude of the point under test.
	 * @param float $lng     Longitude of the point under test.
	 * @param array $polygon List of [ lat, lng ] float pairs describing the ring.
	 *
	 * @return bool True when the point lies inside the polygon.
	 */
	public static function point_in_polygon( float $lat, float $lng, array $polygon ): bool {
		$vertices = count( $polygon );
		if ( $vertices < 3 ) {
			return false;
		}

		$inside = false;
		for ( $i = 0, $j = $vertices - 1; $i < $vertices; $j = $i++ ) {
			if ( ! isset( $polygon[ $i ][0], $polygon[ $i ][1], $polygon[ $j ][0], $polygon[ $j ][1] ) ) {
				continue;
			}

			$lat_i = (float) $polygon[ $i ][0];
			$lng_i = (float) $polygon[ $i ][1];
			$lat_j = (float) $polygon[ $j ][0];
			$lng_j = (float) $polygon[ $j ][1];

			// Treat latitude as the Y axis and longitude as the X axis. The
			// `(lat_i > lat) !== (lat_j > lat)` guard short-circuits before the
			// division whenever the edge is horizontal, so there is no divide by
			// zero.
			$intersects = ( ( $lat_i > $lat ) !== ( $lat_j > $lat ) )
				&& ( $lng < ( $lng_j - $lng_i ) * ( $lat - $lat_i ) / ( $lat_j - $lat_i ) + $lng_i );

			if ( $intersects ) {
				$inside = ! $inside;
			}
		}

		return $inside;
	}

	public function disable_address_fields( $fields ): array {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session ) && $branch_location_session['order_type'] === 'pickup' ) {
				unset( $fields['billing']['billing_state'] );
				unset( $fields['shipping']['shipping_state'] );
				unset( $fields['billing']['billing_address_1'] );
				unset( $fields['shipping']['shipping_address_1'] );
				unset( $fields['billing']['billing_address_2'] );
				unset( $fields['shipping']['shipping_address_2'] );
				unset( $fields['billing']['billing_city'] );
				unset( $fields['shipping']['shipping_city'] );
				unset( $fields['billing']['billing_state'] );
				unset( $fields['shipping']['shipping_state'] );
				unset( $fields['billing']['billing_postcode'] );
				unset( $fields['shipping']['shipping_postcode'] );
			}
		}

		return $fields;
	}

	private static function get_delivery_location_link( $location ): string {
		$lat  = $location->lat;
		$long = $location->lng;

		return '<a target="_blank" href="https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $long . '" >' . esc_html__( 'Open delivery location with Google Maps', 'lafka-plugin' ) . '</a>';
	}

	public static function get_store_address(): string {
		$store_address     = get_option( 'woocommerce_store_address', '' );
		$store_address_2   = get_option( 'woocommerce_store_address_2', '' );
		$store_city        = get_option( 'woocommerce_store_city', '' );
		$store_postcode    = get_option( 'woocommerce_store_postcode', '' );
		$store_raw_country = get_option( 'woocommerce_default_country', '' );
		$split_country     = explode( ':', $store_raw_country );
		// Country and state
		$store_country = $split_country[0];
		// Convert country code to full name if available
		if ( isset( WC()->countries->countries[ $store_country ] ) ) {
			$store_country = WC()->countries->countries[ $store_country ];
		}
		$store_state = $split_country[1] ?? '';

		return $store_address . ' ' . $store_address_2 . ' ' . $store_postcode . ' ' . $store_city . ' ' . $store_state . ' ' . $store_country;
	}

	public static function get_all_legit_branch_locations(): array {
		$args = array(
			'taxonomy'   => 'lafka_branch_location',
			'hide_empty' => false,
			'fields'     => 'id=>name',
			'meta_query' => array(
				array(
					'key'     => 'lafka_branch_address',
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'key'     => 'lafka_branch_address_geocoded',
					'value'   => '',
					'compare' => '!=',
				),
			),
		);

		$all_legit_branch_locations = get_terms( $args );

		if ( is_array( $all_legit_branch_locations ) ) {
			return $all_legit_branch_locations;
		} else {
			return array();
		}
	}

	public static function get_order_meta_backward_compatible( $order_id, $meta_field_key ) {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order        = wc_get_order( $order_id );
			$return_value = $order ? $order->get_meta( $meta_field_key ) : '';
		} else {
			$return_value = get_post_meta( $order_id, $meta_field_key, true );
		}

		return $return_value;
	}
}

/**
 * Function for delaying initialization of the extension until after WooCommerce is loaded.
 */
function lafka_shipping_areas_initialize() {

	// This is also a great place to check for the existence of the WooCommerce class
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$GLOBALS['lafka_shipping_areas'] = Lafka_Shipping_Areas::instance();
}

add_action( 'plugins_loaded', 'lafka_shipping_areas_initialize', 10 );