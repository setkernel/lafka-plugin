<?php
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../../lafka-asset-helpers.php';

class Lafka_Shipping_Areas_Admin {
	/**
	 * Setup Admin class.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_postdata' ) );
	}

	public static function admin_init() {
		self::create_main_settings();
	}

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Lafka Shipping Settings', 'lafka-plugin' ),
			esc_html__( 'Lafka Shipping Settings', 'lafka-plugin' ),
			'manage_woocommerce',
			'lafka_shipping_areas_admin',
			array(
				__CLASS__,
				'show_lafka_shipping_areas_settings_page',
			)
		);
	}

	public static function show_lafka_shipping_areas_settings_page() {

		// check user capabilities
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// add error/update messages

		// check if the user have submitted the settings
		// WordPress will add the "settings-updated" $_GET parameter to the url
		if ( lafka_input_has_get( 'settings-updated' ) ) {
			// add settings saved message with the class of "updated"
			add_settings_error( 'lafka_shipping_areas_messages', 'lafka_shipping_areas_message', esc_html__( 'Settings Saved', 'lafka-plugin' ), 'updated' );
		}

		// show error/update messages
		settings_errors( 'lafka_shipping_areas_messages' );
		?>
		<div class="lafka-shipping-areas-admin-wrap wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			$active_tab      = lafka_input_get_text( 'tab', 'general' );
			$active_general  = ( 'general' === (string) $active_tab ? 'nav-tab-active' : '' );
			$active_advanced = ( 'advanced' === (string) $active_tab ? 'nav-tab-active' : '' );
			$active_datetime = ( 'datetime' === (string) $active_tab ? 'nav-tab-active' : '' );
			$active_branches = ( 'branches' === (string) $active_tab ? 'nav-tab-active' : '' );
			?>
			<h2 class="nav-tab-wrapper">
				<a href="?page=lafka_shipping_areas_admin&tab=general" class="nav-tab <?php echo sanitize_html_class( $active_general ); ?>"><?php esc_html_e( 'General', 'lafka-plugin' ); ?></a>
				<a href="?page=lafka_shipping_areas_admin&tab=advanced" class="nav-tab <?php echo sanitize_html_class( $active_advanced ); ?>"><?php esc_html_e( 'Advanced', 'lafka-plugin' ); ?></a>
				<a href="?page=lafka_shipping_areas_admin&tab=datetime"
					class="nav-tab <?php echo sanitize_html_class( $active_datetime ); ?>"><?php esc_html_e( 'Delivery/Pickup Date Time', 'lafka-plugin' ); ?></a>
				<a href="?page=lafka_shipping_areas_admin&tab=branches"
					class="nav-tab <?php echo sanitize_html_class( $active_branches ); ?>"><?php esc_html_e( 'Branch Locations', 'lafka-plugin' ); ?></a>
			</h2>
			<form id="lafka-plugin-shipping-areas-form" action="options.php" method="post">
				<?php
				if ( 'general' === $active_tab ) {
					settings_fields( 'lafka_shipping_areas_general' );
					do_settings_sections( 'lafka_shipping_areas_general' );
				} elseif ( 'advanced' === $active_tab ) {
					settings_fields( 'lafka_shipping_areas_advanced' );
					do_settings_sections( 'lafka_shipping_areas_advanced' );
				} elseif ( 'datetime' === $active_tab ) {
					settings_fields( 'lafka_shipping_areas_datetime' );
					do_settings_sections( 'lafka_shipping_areas_datetime' );
				} elseif ( 'branches' === $active_tab ) {
					settings_fields( 'lafka_shipping_areas_branches' );
					do_settings_sections( 'lafka_shipping_areas_branches' );
				}
				// output save settings button
				submit_button( esc_html__( 'Save Settings', 'lafka-plugin' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Admin screens that render shipping-areas / branch / order-hours markup
	 * styled by lafka-shipping-areas-admin.css (settings pages, the delivery
	 * area CPT, branch terms, and the orders list's branch/type/time columns).
	 *
	 * @return string[]
	 */
	public static function styled_screen_ids(): array {
		return array(
			'woocommerce_page_lafka_shipping_areas_admin',
			'woocommerce_page_lafka_order_hours',
			'lafka_shipping_areas',
			'edit-lafka_shipping_areas',
			'edit-lafka_branch_location',
			'edit-shop_order',
			'woocommerce_page_wc-orders',
		);
	}

	public static function enqueue_scripts() {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_object( $screen ) ? (string) $screen->id : '';
		if ( ! in_array( $screen_id, self::styled_screen_ids(), true ) ) {
			return;
		}

		wp_enqueue_style( 'lafka-shipping-areas-admin', plugins_url( '../assets/css/backend/lafka-shipping-areas-admin.css', __FILE__ ), array(), lafka_plugin_asset_version( 'incl/shipping-areas/assets/css/backend/lafka-shipping-areas-admin.css' ) );
		if ( 'woocommerce_page_lafka_shipping_areas_admin' === $screen_id ) {
			// Show/hide dependent settings rows on the settings form.
			self::enqueue( 'lafka-shipping-areas-admin', 'lafka-shipping-areas-admin', array( 'jquery' ) );
			// The store-location map (Advanced tab): keyless OpenStreetMap, or
			// Google with a key.
			if ( 'advanced' === (string) lafka_input_get_text( 'tab', 'general' ) && lafka_enqueue_maps() ) {
				self::enqueue( 'lafka-shipping-areas-admin-store-map', 'lafka-shipping-areas-pick-address-map', array( 'lafka-maps' ) );
				wp_localize_script(
					'lafka-shipping-areas-admin-store-map',
					'lafkaStorePicker',
					array(
						'point'        => lafka_get_store_point(),
						'storeAddress' => lafka_geo_wc_store_address(),
						'i18n'         => array(
							'searching' => __( 'Looking up the address…', 'lafka-plugin' ),
						),
					)
				);
			}
		} elseif ( 'lafka_shipping_areas' === $screen_id && lafka_enqueue_maps() ) {
			// The delivery-zone polygon editor.
			self::enqueue( 'lafka-shipping-areas-admin-define-area', 'lafka-shipping-areas-define-area', array( 'lafka-maps' ) );
		}
	}

	/**
	 * Enqueue one of the backend scripts (the .min build unless SCRIPT_DEBUG).
	 *
	 * @param string   $handle Script handle.
	 * @param string   $file   File name under assets/js/backend/, no extension.
	 * @param string[] $deps   Dependencies.
	 * @return void
	 */
	private static function enqueue( string $handle, string $file, array $deps ): void {
		$path = lafka_plugin_script_path( 'incl/shipping-areas/assets/js/backend/' . $file . '.min.js' );
		wp_enqueue_script( $handle, plugins_url( $path, LAFKA_PLUGIN_FILE ), $deps, lafka_plugin_asset_version( $path ), true );
	}

	public static function google_maps_api_key_cb( $args ) {
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
				class="lafka-admin-maps-api-key"
				type="text"
				autocomplete="off"
				value="<?php echo esc_attr( lafka_google_maps_key() ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Optional. Without a key every map works with OpenStreetMap: delivery zones, the store and branch locations, the checkout pin and "use my location". With a key the maps use Google Maps instead, and the location popup suggests addresses as customers type.', 'lafka-plugin' ); ?>
			<br>
			<?php esc_html_e( 'This is the same key as the Google Maps API key in the Lafka theme\'s Customizer; changing it in either place changes it in both. Leave it empty to remove it.', 'lafka-plugin' ); ?>
			<br>
			<a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank" rel="noopener"><?php esc_html_e( 'Get a Google Maps API key', 'lafka-plugin' ); ?></a>
			<?php esc_html_e( '(enable the Maps JavaScript API, Places API and Geocoding API, and restrict the key to your site).', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function pick_delivery_address_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		$values  = array(
			''          => __( 'Disabled', 'lafka-plugin' ),
			'always'    => __( 'Always show the delivery map', 'lafka-plugin' ),
			'when_fail' => __( 'Show the delivery map only when the delivery address cannot be found precisely', 'lafka-plugin' ),
		);
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
		>
			<?php foreach ( $values as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $key, false ) ) : ( '' ); ?>>
					<?php echo esc_html( $value ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'A map can be shown at the classic checkout that lets customers pin their precise delivery location (drag the pin, click the map, or "Use my location"). The block checkout has no pin map yet, so a pin is never required there.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function mandatory_pickup_delivery_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Make it mandatory to pick delivery address from map.', 'lafka-plugin' ); ?>
		</label>
		<?php
	}

	public static function store_point_cb( $args ) {
		$point   = lafka_get_store_point();
		$problem = function_exists( 'lafka_store_location_problem' ) ? lafka_store_location_problem() : '';
		if ( '' !== $problem ) {
			echo '<p class="notice notice-warning inline">' . esc_html( $problem ) . '</p>';
		}
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_advanced[store_point]"
				type="hidden"
				value="<?php echo null === $point ? '' : esc_attr( rawurlencode( (string) wp_json_encode( $point ) ) ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Where your store is: delivery maps start here and the checkout measures delivery distances from it. It is the same point as the restaurant coordinates under WooCommerce → Settings → Restaurant (and in the search-engine schema); pinning it here changes them there.', 'lafka-plugin' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Coordinates:', 'lafka-plugin' ); ?>
			<span id="lafka-store-point-coordinates" class="lafka-store-point-coordinates"><?php echo null === $point ? esc_html__( 'not set', 'lafka-plugin' ) : esc_html( sprintf( '%.6f, %.6f', $point['lat'], $point['lng'] ) ); ?></span>
		</p>
		<span id="lafka-shipping-areas-floating-search-panel">
			<input id="lafka-shipping-areas-search-address" type="text" placeholder="<?php esc_attr_e( 'Search an address', 'lafka-plugin' ); ?>"/>
			<input id="lafka-shipping-areas-floating-search-panel-submit" class="button" type="button" value="<?php esc_attr_e( 'Find', 'lafka-plugin' ); ?>"/>
			<button type="button" class="button-secondary" id="lafka_shipping_store_map_locate"><?php esc_html_e( 'Use the WooCommerce store address', 'lafka-plugin' ); ?></button>
		</span>
		<p id="lafka-store-point-message" class="lafka-map-message" role="status"></p>
		<p><?php esc_html_e( 'Or click the map, or drag the pin, to the exact spot. Save the settings to keep it.', 'lafka-plugin' ); ?></p>
		<div id="lafka-shipping-areas-admin-store-map"></div>
		<?php
	}

	public static function enable_datetime_option_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_datetime' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_datetime[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Enable Delivery/Pickup date and time fields in the checkout page.', 'lafka-plugin' ); ?>
		</label>
		<?php
	}

	public static function datetime_mandatory_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_datetime' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_datetime[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Make Delivery/Pickup date and time fields mandatory.', 'lafka-plugin' ); ?>
		</label>
		<?php
	}

	public static function days_ahead_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_datetime' );
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_datetime[<?php echo esc_attr( $args['label_for'] ); ?>]"
				type="number"
				min="0"
				max="365"
				value="<?php echo isset( $options[ $args['label_for'] ] ) ? esc_attr( $options[ $args['label_for'] ] ) : '30'; ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Enter for how many days ahead user can make an order. The following days will be disabled in the calendar on the checkout page.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function timeslot_duration_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_datetime' );
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_datetime[<?php echo esc_attr( $args['label_for'] ); ?>]"
				type="number"
				min="1"
				max="720"
				value="<?php echo isset( $options[ $args['label_for'] ] ) ? esc_attr( $options[ $args['label_for'] ] ) : '60'; ?>"
		>
		<?php esc_html_e( 'Minutes', 'lafka-plugin' ); ?>
		<p class="description">
			<?php esc_html_e( 'Enter the time in minutes for the slots in which the user can request the order to be completed.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function orders_per_timeslot_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_datetime' );
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_datetime[<?php echo esc_attr( $args['label_for'] ); ?>]"
				type="number"
				min="1"
				max="1000"
				value="<?php echo isset( $options[ $args['label_for'] ] ) ? esc_attr( $options[ $args['label_for'] ] ) : ''; ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Enter the number of orders which can be made in one time slot. If the number is reached for particular time slot, the users will still be able to see it, but it will be disabled.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function branches_section_cb() {
		?>
		<p class="lafka-tab-description">
			<?php esc_html_e( 'Branch Location entries can be managed from "Products" -> "Lafka Branch Locations"', 'lafka-plugin' ); ?>
			<a href="edit-tags.php?taxonomy=lafka_branch_location&post_type=product"><?php esc_html_e( 'Manage Lafka Branch Locations', 'lafka-plugin' ); ?> </a>
		</p>
		<?php
	}

	public static function enable_branch_selection_modal_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Enable order details form to appear on page load.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Modal popup will be shown, allowing the user to choose order type, enter his address and select branch location where he will get or pick order from.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function closable_popup_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Show close button on the popup with order details form.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Allows users to enter the site without providing any details. They will be able to pick store and provide details at later stage, but will not be forced to do so. Works only if "Different Products in Branches" is disabled.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function allow_partial_address_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Allow entering of partial addresses in the Location Confirmation Popup.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php
			esc_html_e(
				'In some areas the address lookup does not resolve full street addresses. If you operate in such areas, enable this option to accept a street without a house number in the popup. Customers can still pin their exact location at checkout.',
				'lafka-plugin'
			);
			?>
		</p>
		<?php
	}

	public static function autocomplete_area_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
				class="lafka-admin-wide-input"
				type="text"
				value="<?php echo esc_attr( $options[ $args['label_for'] ] ?? '' ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Google Maps key only. Rectangle coordinates to set strict bounds where Google will look to autocomplete the address. Enter the coordinates, separated by commas in the following format', 'lafka-plugin' ); ?>
			:
			<strong><?php esc_html_e( 'East longitude, North latitude, South latitude, West longitude', 'lafka-plugin' ); ?></strong>
			<br>
			<?php esc_html_e( 'For example, to restrict to New York addresses, enter:', 'lafka-plugin' ); ?> <strong>-71.62427214318609,41.3117171325974,40.44088789322332,-74.54704598717449</strong>
		</p>
		<?php
	}

	public static function autocomplete_countries_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>"
				class="lafka-admin-select2"
				multiple="multiple"
				name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>][]"
				data-placeholder="<?php esc_attr_e( 'Choose up to 5 countries', 'lafka-plugin' ); ?>"
				aria-label="<?php esc_attr_e( 'Country / Region', 'lafka-plugin' ); ?>">
			<?php foreach ( WC()->countries->get_countries() as $code => $label ) : ?>
				<?php $selected = isset( $options[ $args['label_for'] ] ) && is_array( $options[ $args['label_for'] ] ) && in_array( $code, $options[ $args['label_for'] ], true ) ? ' selected="selected" ' : ''; ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php echo esc_html( $selected ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Google Maps key only. Limit Google address autocomplete to up to 5 countries. This is the maximum allowed number by Google.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function products_by_branches_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Branches can have different products.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Assigning products to branches can be done from the Products screen in WooCommerce. NOTE: Clear all transient fields from WooCommerce -> Status -> Tools to make sure related products works correctly.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function show_branches_info_in_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		$values  = array(
			'mini_cart' => __( 'Mini Cart', 'lafka-plugin' ),
			'cart'      => __( 'Cart', 'lafka-plugin' ),
			'checkout'  => __( 'Checkout', 'lafka-plugin' ),
			'shop'      => __( 'Shop and Category', 'lafka-plugin' ),
		);
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>" multiple="multiple" class="lafka-admin-select2"
				name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>][]">
			<?php foreach ( $values as $key => $value ) : ?>
				<?php $selected = isset( $options[ $args['label_for'] ] ) && is_array( $options[ $args['label_for'] ] ) && in_array( (string) $key, array_map( 'strval', $options[ $args['label_for'] ] ), true ) ? ' selected="selected" ' : ''; ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php echo esc_html( $selected ); ?>>
					<?php echo esc_html( $value ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose where to show the branch and order type info box.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function order_type_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		$values  = array(
			'delivery_pickup' => __( 'Delivery and Pickup', 'lafka-plugin' ),
			'delivery'        => __( 'Only Delivery', 'lafka-plugin' ),
			'pickup'          => __( 'Only Pickup', 'lafka-plugin' ),
		);
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
		>
			<?php foreach ( $values as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $key, false ) ) : ( '' ); ?>>
					<?php echo esc_html( $value ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose what options will the customers have to choose for order type.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function hide_address_fields_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Disable address fields for "Pickup" order type on checkout page.', 'lafka-plugin' ); ?>
		</label>
		<?php
	}

	public static function branch_selection_type_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		$values  = array(
			'images' => __( 'Branch Images', 'lafka-plugin' ),
			'select' => __( 'Dropdown', 'lafka-plugin' ),
		);
		?>
		<select id="<?php echo esc_attr( $args['label_for'] ); ?>"
				name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
		>
			<?php foreach ( $values as $key => $value ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $key, false ) ) : ( '' ); ?>>
					<?php echo esc_html( $value ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose how branches will be selected from the popup.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function disable_current_location_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Disable "Use Current Location" link.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Remove "Use Current Location" link next to user address input which allows automatic location address population.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function disable_order_emails_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_branches' );
		?>
		<label for="<?php echo esc_attr( $args['label_for'] ); ?>">
			<input id="<?php echo esc_attr( $args['label_for'] ); ?>"
					name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>]"
					type="checkbox"
					value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
			>
			<?php esc_html_e( 'Do not send emails to branch manager about orders.', 'lafka-plugin' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'By default, branch managers receive emails about new, canceled and failed orders for their branches. This can be disabled with this setting.', 'lafka-plugin' ); ?>
		</p>
		<?php
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'shipping_areas_define_map',
			esc_html__( 'Draw Shipping Area', 'lafka-plugin' ),
			array(
				__CLASS__,
				'shipping_areas_define_map_html',
			),
			'lafka_shipping_areas',
			'normal',
			'high'
		);
	}

	public static function shipping_areas_define_map_html( $post ) {
		$value = get_post_meta( $post->ID, '_lafka_shipping_area_polygon_coordinates', true );
		// Use nonce for verification
		wp_nonce_field( 'lafka_shipping_area_save', 'lafka_shipping_area_polygon_nonce' );
		?>
		<div id="lafka-shipping-areas-admin-define-area-map"></div>
		<input type="hidden" name="lafka_shipping_area_polygon_coordinates" id="lafka_shipping_area_polygon_coordinates" value="<?php echo esc_attr( $value ); ?>" />
		<?php
	}

	public static function save_postdata( $post_id ) {
		if ( isset( $_POST['lafka_shipping_area_polygon_coordinates'] ) ) {
			// verify if this is an auto save routine.
			// If it is our form has not been submitted, so we dont want to do anything
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// verify this came from our screen and with proper authorization,
			// because save_post can be triggered at other times
			if ( ! isset( $_POST['lafka_shipping_area_polygon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lafka_shipping_area_polygon_nonce'] ) ), 'lafka_shipping_area_save' ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// A Google encoded polyline: its alphabet includes the backslash, so the
			// unslashed value is re-slashed for update_post_meta(), which unslashes
			// what it stores.
			update_post_meta( $post_id, '_lafka_shipping_area_polygon_coordinates', wp_slash( sanitize_text_field( wp_unslash( $_POST['lafka_shipping_area_polygon_coordinates'] ) ) ) );
		}
	}

	/**
	 * Sanitize the delivery/pickup datetime settings on save.
	 *
	 * register_setting() previously had no sanitize_callback, so the
	 * `min`/`max` on the number inputs were HTML-only — trivially bypassed by
	 * a crafted POST or a programmatic update_option(). A `timeslot_duration`
	 * of 0 or '' hangs the public wp_ajax_nopriv time-slots endpoint
	 * (Lafka_Timeslots::get_timeslots_for_date()'s while(1) never advances) or
	 * fatals on an empty DateInterval string, so it must be clamped
	 * server-side. This is defense-in-depth: the consuming code in
	 * Lafka_Timeslots floors the value too.
	 *
	 * @param mixed $input Raw option array submitted by the Settings API.
	 * @return array Sanitized option array.
	 */
	public static function sanitize_datetime_settings( $input ): array {
		$output = is_array( $input ) ? $input : array();

		// Days ahead: floor at 0, cap at 365 (mirrors the field's min/max). A
		// cleared field falls back to the 30 default rather than 0.
		$output['days_ahead'] = ( isset( $output['days_ahead'] ) && '' !== trim( (string) $output['days_ahead'] ) )
			? min( 365, max( 0, (int) $output['days_ahead'] ) )
			: 30;

		// Timeslot duration: clamp to 1..720 (mirrors the field's min/max).
		// Load-bearing — a value below 1 hangs/fatals the public AJAX endpoint.
		// A cleared field falls back to the 60 default rather than 1.
		$output['timeslot_duration'] = ( isset( $output['timeslot_duration'] ) && '' !== trim( (string) $output['timeslot_duration'] ) )
			? min( 720, max( 1, (int) $output['timeslot_duration'] ) )
			: 60;

		// Orders per timeslot: an empty value means "no cap" — keep it empty.
		// When set, floor at 1 and cap at 1000 (mirrors the field's min/max).
		if ( isset( $output['orders_per_timeslot'] ) && '' !== trim( (string) $output['orders_per_timeslot'] ) ) {
			$output['orders_per_timeslot'] = min( 1000, max( 1, (int) $output['orders_per_timeslot'] ) );
		}

		return $output;
	}

	/**
	 * Sanitize a settings group whose fields are all plain text, checkboxes
	 * and selects (API keys, country lists, order type, branch selection).
	 *
	 * @param mixed $input Raw option value from the settings form.
	 * @return array Sanitized option array.
	 */
	public static function sanitize_text_settings( $input ): array {
		return is_array( $input ) ? map_deep( $input, 'sanitize_text_field' ) : array();
	}

	/**
	 * Sanitize the general group. The Google Maps key is not stored here: it
	 * is saved to lafka[google_maps_api_key], its one home (an emptied field
	 * removes it).
	 *
	 * @param mixed $input Raw option value from the settings form.
	 * @return array Sanitized option array.
	 */
	public static function sanitize_general_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		if ( array_key_exists( 'google_maps_api_key', $input ) ) {
			lafka_set_google_maps_key( is_string( $input['google_maps_api_key'] ) ? trim( $input['google_maps_api_key'] ) : '' );
		}
		unset( $input['google_maps_api_key'], $input['secondary_google_maps_api_key'] );

		return map_deep( $input, 'sanitize_text_field' );
	}

	/**
	 * Sanitize the advanced group. The store point (URL-encoded JSON written
	 * by the map) is not stored here: a valid point is saved as the business
	 * geo, its one home, and replaces any pre-10.4 store_map_location copy.
	 * The retired "Set Store Location" mode is dropped.
	 *
	 * @param mixed $input Raw option value from the settings form.
	 * @return array Sanitized option array.
	 */
	public static function sanitize_advanced_settings( $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$point  = lafka_parse_store_map_location( isset( $input['store_point'] ) && is_string( $input['store_point'] ) ? $input['store_point'] : '' );
		$legacy = isset( $input['store_map_location'] ) && is_string( $input['store_map_location'] ) ? $input['store_map_location'] : '';
		if ( null !== $point ) {
			lafka_set_store_point( $point['lat'], $point['lng'] );
			$legacy = '';
		}
		unset( $input['store_point'], $input['store_map_location'], $input['set_store_location'] );

		$output = map_deep( $input, 'sanitize_text_field' );
		// A pre-10.4 pin that was never re-saved stays readable as the fallback.
		if ( '' !== $legacy && null !== lafka_parse_store_map_location( $legacy ) ) {
			$output['store_map_location'] = $legacy;
		}

		return $output;
	}

	private static function create_main_settings() {
		register_setting(
			'lafka_shipping_areas_general',
			'lafka_shipping_areas_general',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_general_settings' ),
			)
		);
		register_setting(
			'lafka_shipping_areas_advanced',
			'lafka_shipping_areas_advanced',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_advanced_settings' ),
			)
		);
		register_setting(
			'lafka_shipping_areas_datetime',
			'lafka_shipping_areas_datetime',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_datetime_settings' ),
			)
		);
		register_setting(
			'lafka_shipping_areas_branches',
			'lafka_shipping_areas_branches',
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_settings' ),
			)
		);

		add_settings_section( 'general_section', '', null, 'lafka_shipping_areas_general' );
		add_settings_field(
			'google_maps_api_key',
			esc_html__( 'Google Maps API Key (optional)', 'lafka-plugin' ),
			array(
				__CLASS__,
				'google_maps_api_key_cb',
			),
			'lafka_shipping_areas_general',
			'general_section',
			array(
				'label_for' => 'google_maps_api_key',
			)
		);
		add_settings_field(
			'pick_delivery_address',
			esc_html__( 'Pick Precise Delivery Address from Map', 'lafka-plugin' ),
			array(
				__CLASS__,
				'pick_delivery_address_cb',
			),
			'lafka_shipping_areas_general',
			'general_section',
			array(
				'label_for' => 'pick_delivery_address',
			)
		);
		add_settings_field(
			'mandatory_pickup_delivery',
			esc_html__( 'Mandatory to pick address', 'lafka-plugin' ),
			array(
				__CLASS__,
				'mandatory_pickup_delivery_cb',
			),
			'lafka_shipping_areas_general',
			'general_section',
			array(
				'label_for' => 'mandatory_pickup_delivery',
				'class'     => 'hidden',
			)
		);

		add_settings_section( 'advanced_section', '', null, 'lafka_shipping_areas_advanced' );
		add_settings_field(
			'store_point',
			esc_html__( 'Store location', 'lafka-plugin' ),
			array(
				__CLASS__,
				'store_point_cb',
			),
			'lafka_shipping_areas_advanced',
			'advanced_section',
			array(
				'label_for' => 'lafka_store_point',
			)
		);

		add_settings_section( 'datetime_section', '', null, 'lafka_shipping_areas_datetime' );
		add_settings_field(
			'enable_datetime_option',
			esc_html__( 'Enable Date Time Picker', 'lafka-plugin' ),
			array(
				__CLASS__,
				'enable_datetime_option_cb',
			),
			'lafka_shipping_areas_datetime',
			'datetime_section',
			array(
				'label_for' => 'enable_datetime_option',
			)
		);
		add_settings_field(
			'datetime_mandatory',
			esc_html__( 'Mandatory', 'lafka-plugin' ),
			array(
				__CLASS__,
				'datetime_mandatory_cb',
			),
			'lafka_shipping_areas_datetime',
			'datetime_section',
			array(
				'label_for' => 'datetime_mandatory',
			)
		);
		add_settings_field(
			'days_ahead',
			esc_html__( 'Number of days ahead to order', 'lafka-plugin' ),
			array(
				__CLASS__,
				'days_ahead_cb',
			),
			'lafka_shipping_areas_datetime',
			'datetime_section',
			array(
				'label_for' => 'days_ahead',
			)
		);
		add_settings_field(
			'timeslot_duration',
			esc_html__( 'Time Slot Duration', 'lafka-plugin' ),
			array(
				__CLASS__,
				'timeslot_duration_cb',
			),
			'lafka_shipping_areas_datetime',
			'datetime_section',
			array(
				'label_for' => 'timeslot_duration',
			)
		);
		add_settings_field(
			'orders_per_timeslot',
			esc_html__( 'Orders Per Time Slot', 'lafka-plugin' ),
			array(
				__CLASS__,
				'orders_per_timeslot_cb',
			),
			'lafka_shipping_areas_datetime',
			'datetime_section',
			array(
				'label_for' => 'orders_per_timeslot',
			)
		);

		add_settings_section(
			'branches_section',
			'',
			array(
				__CLASS__,
				'branches_section_cb',
			),
			'lafka_shipping_areas_branches'
		);
		add_settings_field(
			'enable_branch_selection_modal',
			esc_html__( 'Location Confirmation Popup', 'lafka-plugin' ),
			array(
				__CLASS__,
				'enable_branch_selection_modal_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'enable_branch_selection_modal',
			)
		);
		add_settings_field(
			'closable_popup',
			esc_html__( 'Closable Popup', 'lafka-plugin' ),
			array(
				__CLASS__,
				'closable_popup_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'closable_popup',
			)
		);
		add_settings_field(
			'allow_partial_address',
			esc_html__( 'Allow Partial Address', 'lafka-plugin' ),
			array(
				__CLASS__,
				'allow_partial_address_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'allow_partial_address',
			)
		);
		add_settings_field(
			'autocomplete_area',
			esc_html__( 'Google Autocomplete Predictions Area Bounds', 'lafka-plugin' ),
			array(
				__CLASS__,
				'autocomplete_area_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'autocomplete_area',
			)
		);
		add_settings_field(
			'autocomplete_countries',
			esc_html__( 'Google Autocomplete Predictions Limit By Countries', 'lafka-plugin' ),
			array(
				__CLASS__,
				'autocomplete_countries_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'autocomplete_countries',
			)
		);
		add_settings_field(
			'products_by_branches',
			esc_html__( 'Different Products in Branches', 'lafka-plugin' ),
			array(
				__CLASS__,
				'products_by_branches_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'products_by_branches',
			)
		);
		add_settings_field(
			'show_branches_info_in',
			esc_html__( 'Show Branches Info Box in', 'lafka-plugin' ),
			array(
				__CLASS__,
				'show_branches_info_in_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'show_branches_info_in',
			)
		);
		add_settings_field(
			'order_type',
			esc_html__( 'Order Type', 'lafka-plugin' ),
			array(
				__CLASS__,
				'order_type_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'order_type',
			)
		);
		add_settings_field(
			'hide_address_fields',
			esc_html__( 'Hide Address Fields', 'lafka-plugin' ),
			array(
				__CLASS__,
				'hide_address_fields_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'hide_address_fields',
			)
		);
		add_settings_field(
			'branch_selection_type',
			esc_html__( 'Branch Selection Type', 'lafka-plugin' ),
			array(
				__CLASS__,
				'branch_selection_type_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'branch_selection_type',
			)
		);
		add_settings_field(
			'disable_current_location',
			esc_html__( 'Disable Current Location', 'lafka-plugin' ),
			array(
				__CLASS__,
				'disable_current_location_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'disable_current_location',
			)
		);
		add_settings_field(
			'disable_order_emails',
			esc_html__( 'Disable Order Emails to Branch Managers', 'lafka-plugin' ),
			array(
				__CLASS__,
				'disable_order_emails_cb',
			),
			'lafka_shipping_areas_branches',
			'branches_section',
			array(
				'label_for' => 'disable_order_emails',
			)
		);
	}
}

Lafka_Shipping_Areas_Admin::init();
