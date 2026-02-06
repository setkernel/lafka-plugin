<?php
defined( 'ABSPATH' ) || exit;

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

		// Save Google Maps api key on both places
		add_action( 'update_option_lafka_shipping_areas_general', array( __CLASS__, 'override_theme_options_api_key' ), 10, 3 );
		add_action( 'update_option_lafka', array( __CLASS__, 'override_shipping_areas_options_api_key' ), 10, 3 );

		// 'lafka_shipping_areas' shortcode WPBakery Page Builder integration
		add_filter( 'vc_autocomplete_lafka_shipping_areas_areas_area_id_callback', array( __CLASS__, 'lafka_shipping_areas_shortcode_area_id_search' ), 10, 1 );
		add_filter( 'vc_autocomplete_lafka_shipping_areas_areas_area_id_render', array( __CLASS__, 'lafka_shipping_areas_shortcode_area_id_render' ), 10, 1 );
	}

	public static function admin_init() {
		self::create_main_settings();
	}

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', esc_html__( 'Lafka Shipping Settings', 'lafka-plugin' ), esc_html__( 'Lafka Shipping Settings', 'lafka-plugin' ), 'manage_woocommerce', 'lafka_shipping_areas_admin', array(
			__CLASS__,
			'show_lafka_shipping_areas_settings_page'
		) );
	}

	public static function show_lafka_shipping_areas_settings_page() {

		// check user capabilities
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// add error/update messages

		// check if the user have submitted the settings
		// WordPress will add the "settings-updated" $_GET parameter to the url
		if ( isset( $_GET['settings-updated'] ) ) {
			// add settings saved message with the class of "updated"
			add_settings_error( 'lafka_shipping_areas_messages', 'lafka_shipping_areas_message', esc_html__( 'Settings Saved', 'lafka-plugin' ), 'updated' );
		}

		// show error/update messages
		settings_errors( 'lafka_shipping_areas_messages' );
		?>
        <div class="lafka-shipping-areas-admin-wrap wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			$active_tab      = isset( $_GET["tab"] ) ? sanitize_text_field( wp_unslash( $_GET["tab"] ) ) : "general";
			$active_general  = ( $active_tab == 'general' ? 'nav-tab-active' : '' );
			$active_advanced = ( $active_tab == 'advanced' ? 'nav-tab-active' : '' );
			$active_datetime = ( $active_tab == 'datetime' ? 'nav-tab-active' : '' );
			$active_branches = ( $active_tab == 'branches' ? 'nav-tab-active' : '' );
			?>
            <h2 class="nav-tab-wrapper">
                <a href="?page=lafka_shipping_areas_admin&tab=general" class="nav-tab <?php echo sanitize_html_class( $active_general ) ?>"><?php esc_html_e( 'General', 'lafka-plugin' ) ?></a>
                <a href="?page=lafka_shipping_areas_admin&tab=advanced" class="nav-tab <?php echo sanitize_html_class( $active_advanced ) ?>"><?php esc_html_e( 'Advanced', 'lafka-plugin' ) ?></a>
                <a href="?page=lafka_shipping_areas_admin&tab=datetime"
                   class="nav-tab <?php echo sanitize_html_class( $active_datetime ) ?>"><?php esc_html_e( 'Delivery/Pickup Date Time', 'lafka-plugin' ) ?></a>
                <a href="?page=lafka_shipping_areas_admin&tab=branches"
                   class="nav-tab <?php echo sanitize_html_class( $active_branches ) ?>"><?php esc_html_e( 'Branch Locations', 'lafka-plugin' ) ?></a>
            </h2>
            <form id="lafka-plugin-shipping-areas-form" action="options.php" method="post">
				<?php
				if ( $active_tab === "general" ) {
					settings_fields( 'lafka_shipping_areas_general' );
					do_settings_sections( 'lafka_shipping_areas_general' );
				} else if ( $active_tab === "advanced" ) {
					settings_fields( 'lafka_shipping_areas_advanced' );
					do_settings_sections( 'lafka_shipping_areas_advanced' );
				} else if ( $active_tab === "datetime" ) {
					settings_fields( 'lafka_shipping_areas_datetime' );
					do_settings_sections( 'lafka_shipping_areas_datetime' );
				} else if ( $active_tab === "branches" ) {
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

	public static function enqueue_scripts() {
		wp_enqueue_script( 'lafka-shipping-areas-admin', plugins_url( '../assets/js/backend/lafka-shipping-areas-admin.min.js', __FILE__ ), array( 'jquery' ), '1.0', true );
		wp_enqueue_style( 'lafka-shipping-areas-admin', plugins_url( '../assets/css/backend/lafka-shipping-areas-admin.css', __FILE__ ), array(), '1.0' );
		$screen = get_current_screen();
		if ( is_object( $screen ) ) {
			if ( $screen->id === 'woocommerce_page_lafka_shipping_areas_admin' ) {
				wp_enqueue_script( 'lafka-shipping-areas-admin-store-map', plugins_url( '../assets/js/backend/lafka-shipping-areas-pick-address-map.min.js', __FILE__ ), array( 'lafka-google-maps' ), '1.0', true );
			} elseif ( $screen->id === 'lafka_shipping_areas' ) {
				wp_enqueue_script( 'lafka-shipping-areas-admin-define-area', plugins_url( '../assets/js/backend/lafka-shipping-areas-define-area.min.js', __FILE__ ), array( 'lafka-google-maps' ), '1.0', true );
			}
		}

	}

	public static function google_maps_api_key_cb( $args ) {
		$google_maps_api_key = '';
		if ( function_exists( 'lafka_get_option' ) ) {
			$google_maps_api_key = lafka_get_option( 'google_maps_api_key' );
		}
		$options = get_option( 'lafka_shipping_areas_general' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
               class="lafka-admin-maps-api-key"
               type="text"
               value="<?php echo ! empty( $options[ $args['label_for'] ] ) ? esc_attr( $options[ $args['label_for'] ] ) : esc_attr( $google_maps_api_key ); ?>"
        >
        <p class="description">
			<?php esc_html_e( 'Note: Google Maps API Key may already be set in', 'lafka-plugin' ); ?>
            <a href="<?php echo admin_url( 'admin.php?page=lafka-optionsframework#of-option-general' ); ?>" target="_blank"><?php esc_html_e( 'Theme Options', 'lafka-plugin' ); ?></a>.
			<?php esc_html_e( 'In this case it will be pre-filled.', 'lafka-plugin' ); ?>
            <br>
			<?php esc_html_e( 'If you don\'t have API key, see how to ', 'lafka-plugin' ); ?>
            <a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank"><?php esc_html_e( 'Generate Google Maps JavaScript API key', 'lafka-plugin' ); ?></a>
			<?php esc_html_e( '(Enable following APIs: Places API, Geocoding API, Distance Matrix API)', 'lafka-plugin' ); ?>
        </p>
		<?php
	}

	public static function secondary_google_maps_api_key_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
               class="lafka-admin-maps-api-key"
               type="text"
               value="<?php echo isset( $options[ $args['label_for'] ] ) ? esc_attr( $options[ $args['label_for'] ] ) : ''; ?>"
        >
        <p class="description">
			<?php esc_html_e( 'If your main API Key has restrictions by HTTP referrers (web sites) you will need to enter a secondary API Key for the server to serve Distance Matrix API requests which is used for calculation of distance based shipping rates.', 'lafka-plugin' ); ?>
			<?php esc_html_e( 'This Key can not be restricted by HTTP referrers (web sites) and only need the Distance Matrix API activated.', 'lafka-plugin' ); ?>
        </p>
		<?php
	}

	public static function lowest_cost_shipping_method_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
                   name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
                   type="checkbox"
                   value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
            >
			<?php esc_html_e( 'At checkout, show only the available shipping method with the lowest cost. Might be more than one if cost is the same.', 'lafka-plugin' ); ?>
            <br>
			<?php esc_html_e( 'Note: If there is "Local pickup", it always be included in the list as an option.', 'lafka-plugin' ); ?>
        </label>
		<?php
	}

	public static function hide_shipping_cost_at_cart_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
                   name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
                   type="checkbox"
                   value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
            >
			<?php esc_html_e( 'At cart page, hide the shipping costs.', 'lafka-plugin' ); ?>
        </label>
		<?php
	}

	public static function pick_delivery_address_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_general' );
		$values  = array(
			''          => __( 'Disabled', 'lafka-plugin' ),
			'always'    => __( 'Always show the delivery map', 'lafka-plugin' ),
			'when_fail' => __( 'Show the delivery map when Google fail to geocode the delivery address', 'lafka-plugin' ),
		);
		?>
        <select id="<?php echo esc_attr( $args['label_for'] ); ?>"
                name="lafka_shipping_areas_general[<?php echo esc_attr( $args['label_for'] ); ?>]"
        >
			<?php foreach ( $values as $key => $value ): ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $key, false ) ) : ( '' ); ?>>
					<?php echo esc_html( $value ); ?>
                </option>
			<?php endforeach; ?>
        </select>
        <p class="description">
			<?php esc_html_e( 'A map can be shown at checkout that let users pick their precise delivery location.', 'lafka-plugin' ); ?>
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

	public static function deactivate_post_code_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
                   name="lafka_shipping_areas_advanced[<?php echo esc_attr( $args['label_for'] ); ?>]"
                   type="checkbox"
                   value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
            >
			<?php esc_html_e( 'Makes postcode field optional in address and checkout forms and disables post code restrictions.', 'lafka-plugin' ); ?>
        </label>
		<?php
	}

	public static function disable_state_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
                   name="lafka_shipping_areas_advanced[<?php echo esc_attr( $args['label_for'] ); ?>]"
                   type="checkbox"
                   value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
            >
			<?php esc_html_e( 'Remove State field from the checkout form.', 'lafka-plugin' ); ?>
            <p class="description">
		        <?php esc_html_e( 'This is useful when google address lookup doesn\'t return state for some countries and customers can\'t complete their order.', 'lafka-plugin' ); ?>
            </p>
        </label>
		<?php
	}

	public static function debug_mode_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		?>
        <label for="<?php echo esc_attr( $args['label_for'] ); ?>">
            <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
                   name="lafka_shipping_areas_advanced[<?php echo esc_attr( $args['label_for'] ); ?>]"
                   type="checkbox"
                   value="1"
				<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
            >
			<?php esc_html_e( 'Show request and response data from Google calls.', 'lafka-plugin' ); ?>
        </label>
		<?php
	}

	public static function set_store_location_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		$values  = array(
			'geo_woo_store'      => __( 'Geocode WooCommerce Store Address on Checkout', 'lafka-plugin' ),
			'pick_store_address' => __( 'Pick Store Location from Map', 'lafka-plugin' )
		);
		?>
        <select id="<?php echo esc_attr( $args['label_for'] ); ?>"
                name="lafka_shipping_areas_advanced[<?php echo esc_attr( $args['label_for'] ); ?>]"
        >
			<?php foreach ( $values as $key => $value ): ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], $key, false ) ) : ( '' ); ?>>
					<?php echo esc_html( $value ); ?>
                </option>
			<?php endforeach; ?>
        </select>
        <p class="description">
			<?php esc_html_e( 'This location will be used as starting point when calculating dynamic shipping rates and as center point when using radius as shipping method restriction.', 'lafka-plugin' ); ?>
            <br><br>
			<?php esc_html_e( 'IMPORTANT: It is highly recommended to use the "Pick Store Location from Map" option whenever possible. Otherwise large amount of google traffic will be generated, which will cause slow performance and may generate additional costs.', 'lafka-plugin' ); ?>
            <br><br>
			<?php esc_html_e( 'NOTE: If you have set up Branch Locations (Products -> Lafka Branch Locations) and are using them in the shipping methods, then selected Branch Location will be used for calculating the radius restriction.', 'lafka-plugin' ); ?>
        </p>
		<?php
	}

	public static function store_map_location_cb( $args ) {
		$options = get_option( 'lafka_shipping_areas_advanced' );
		wp_add_inline_script( 'lafka-shipping-areas-admin-store-map', 'const lafka_admin_map_params = ' . json_encode( array(
				'saved_store_address_lat_long' => $options[ $args['label_for'] ] ?? '',
				'store_address'                => Lafka_Shipping_Areas::get_store_address(),
			) ), 'before' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_shipping_areas_advanced[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="hidden"
               value="<?php echo ! empty( $options[ $args['label_for'] ] ) ? ( $options[ $args['label_for'] ] ) : ''; ?>"
        >
        <button type="button" class="button-secondary"
                id="lafka_shipping_store_map_locate"><?php esc_html_e( 'Try to Geocode WooCommerce Store Address and save the coordinates', 'lafka-plugin' ); ?></button>
        <p>
			<?php esc_html_e( 'Or click on the map to pinpoint the exact store location. Note that this will not change the address set in WooCommerce settings.', 'lafka-plugin' ); ?>
        </p>
        <span id="lafka-shipping-areas-floating-search-panel">
            <input id="lafka-shipping-areas-search-address" type="textbox" placeholder="<?php esc_html_e( 'Sydney, NSW', 'lafka-plugin' ); ?>"/>
            <input id="lafka-shipping-areas-floating-search-panel-submit" type="button" value="<?php esc_html_e( 'Geocode', 'lafka-plugin' ); ?>"/>
        </span>
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
               value="<?php echo isset( $options[ $args['label_for'] ] ) ? ( $options[ $args['label_for'] ] ) : ( '30' ); ?>"
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
               value="<?php echo isset( $options[ $args['label_for'] ] ) ? ( $options[ $args['label_for'] ] ) : ( '60' ); ?>"
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

	public static function branches_section_cb( $args ) {
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
			<?php esc_html_e( 'In some areas Google doesn\'t resolve to the full addresses. If you operate in such areas, you can enable this option to allow entering partial addresses in the popup. ' .
			                  'The users will be able to enter the site and can precise their location at checkout.', 'lafka-plugin' ); ?>
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
               value="<?php echo $options[ $args['label_for'] ] ?? ''; ?>"
        >
        <p class="description">
			<?php esc_html_e( 'Rectangle coordinates to set strict bounds where google will look to autocomplete the address. Enter the coordinates, separated by commas in the following format', 'lafka-plugin' ); ?>
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
				<?php $selected = isset( $options[ $args['label_for'] ] ) && is_array( $options[ $args['label_for'] ] ) && in_array( $code, $options[ $args['label_for'] ] ) ? ' selected="selected" ' : ''; ?>
                <option value="<?php echo esc_attr( $code ); ?>" <?php echo esc_html( $selected ) ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
        </select>
        <p class="description">
			<?php esc_html_e( 'Limit Google address autocomplete to up to 5 countries. This is the maximum allowed number by Google.', 'lafka-plugin' ); ?>
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
			'shop'      => __( 'Shop and Category', 'lafka-plugin' )
		);
		?>
        <select id="<?php echo esc_attr( $args['label_for'] ); ?>" multiple="multiple" class="lafka-admin-select2"
                name="lafka_shipping_areas_branches[<?php echo esc_attr( $args['label_for'] ); ?>][]">
			<?php foreach ( $values as $key => $value ): ?>
				<?php $selected = isset( $options[ $args['label_for'] ] ) && in_array( $key, $options[ $args['label_for'] ] ) ? ' selected="selected" ' : ''; ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php echo esc_html( $selected ) ?>>
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
			<?php foreach ( $values as $key => $value ): ?>
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
			<?php foreach ( $values as $key => $value ): ?>
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

	public static function override_theme_options_api_key( $old_value, $value, $option ) {
		if ( function_exists( 'lafka_get_option' ) && ! empty( $value['google_maps_api_key'] ) ) {
			$lafka_options = get_option( 'lafka' );
			if ( isset( $lafka_options['google_maps_api_key'] ) && $lafka_options['google_maps_api_key'] !== $value['google_maps_api_key'] ) {
				$lafka_options['google_maps_api_key'] = $value['google_maps_api_key'];
				// Unhook to prevent recursion (this hook fires update_option_lafka which calls back here).
				remove_action( 'update_option_lafka', array( __CLASS__, 'override_shipping_areas_options_api_key' ), 10 );
				update_option( 'lafka', $lafka_options );
				add_action( 'update_option_lafka', array( __CLASS__, 'override_shipping_areas_options_api_key' ), 10, 3 );
			}
		}
	}

	public static function override_shipping_areas_options_api_key( $old_value, $value, $option ) {
		if ( ! is_array( $value ) || empty( $value['google_maps_api_key'] ) ) {
			return;
		}
		$options = get_option( 'lafka_shipping_areas_general' );
		if ( is_array( $options ) && ! empty( $options['google_maps_api_key'] ) && $options['google_maps_api_key'] !== $value['google_maps_api_key'] ) {
			$options['google_maps_api_key'] = $value['google_maps_api_key'];
			// Unhook to prevent recursion (this hook fires update_option_lafka_shipping_areas_general which calls back here).
			remove_action( 'update_option_lafka_shipping_areas_general', array( __CLASS__, 'override_theme_options_api_key' ), 10 );
			update_option( 'lafka_shipping_areas_general', $options );
			add_action( 'update_option_lafka_shipping_areas_general', array( __CLASS__, 'override_theme_options_api_key' ), 10, 3 );
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'shipping_areas_define_map', esc_html__( 'Draw Shipping Area', 'lafka-plugin' ), array(
			__CLASS__,
			'shipping_areas_define_map_html'
		), 'lafka_shipping_areas', 'normal', 'high' );
	}

	public static function shipping_areas_define_map_html( $post ) {
		$value = get_post_meta( $post->ID, '_lafka_shipping_area_polygon_coordinates', true );
		// Use nonce for verification
		wp_nonce_field( 'lafka_shipping_area_save', 'lafka_shipping_area_polygon_nonce' );
		?>
        <div id="lafka-shipping-areas-admin-define-area-map"></div>
        <input type="hidden" name="lafka_shipping_area_polygon_coordinates" id="lafka_shipping_area_polygon_coordinates" value="<?php esc_attr_e( $value ); ?>"
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
			if ( ! wp_verify_nonce( $_POST['lafka_shipping_area_polygon_nonce'], 'lafka_shipping_area_save' ) ) {
				return;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			update_post_meta( $post_id, '_lafka_shipping_area_polygon_coordinates', sanitize_text_field( $_POST['lafka_shipping_area_polygon_coordinates'] ) );
		}
	}

	public static function lafka_shipping_areas_shortcode_area_id_search( $search_string ): array {
		$query                           = $search_string;
		$data                            = array();
		$args                            = array(
			's'         => $query,
			'post_type' => 'lafka_shipping_areas',
		);
		$args['vc_search_by_title_only'] = true;
		$args['numberposts']             = - 1;
		if ( 0 === strlen( $args['s'] ) ) {
			unset( $args['s'] );
		}
		add_filter( 'posts_search', 'vc_search_by_title_only', 500, 2 );
		$posts = get_posts( $args );
		if ( is_array( $posts ) && ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$data[] = array(
					'value' => $post->ID,
					'label' => $post->post_title
				);
			}
		}

		return $data;
	}

	public static function lafka_shipping_areas_shortcode_area_id_render( $value ) {
		$post = get_post( $value['value'] );

		return is_null( $post ) ? false : array(
			'label' => $post->post_title,
			'value' => $post->ID,
		);
	}

	private static function create_main_settings() {
		register_setting( 'lafka_shipping_areas_general', 'lafka_shipping_areas_general' );
		register_setting( 'lafka_shipping_areas_advanced', 'lafka_shipping_areas_advanced' );
		register_setting( 'lafka_shipping_areas_datetime', 'lafka_shipping_areas_datetime' );
		register_setting( 'lafka_shipping_areas_branches', 'lafka_shipping_areas_branches' );

		add_settings_section( 'general_section', '', null, 'lafka_shipping_areas_general' );
		add_settings_field( 'google_maps_api_key', esc_html__( 'Google Maps API Key', 'lafka-plugin' ), array(
			__CLASS__,
			'google_maps_api_key_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'google_maps_api_key',
		] );
		add_settings_field( 'secondary_google_maps_api_key', esc_html__( 'Secondary Google Maps API Key', 'lafka-plugin' ), array(
			__CLASS__,
			'secondary_google_maps_api_key_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'secondary_google_maps_api_key',
		] );
		add_settings_field( 'lowest_cost_shipping', esc_html__( 'Show only lowest cost shipping method', 'lafka-plugin' ), array(
			__CLASS__,
			'lowest_cost_shipping_method_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'lowest_cost_shipping',
		] );
		add_settings_field( 'hide_shipping_cost_at_cart', esc_html__( 'Hide shipping costs at cart page', 'lafka-plugin' ), array(
			__CLASS__,
			'hide_shipping_cost_at_cart_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'hide_shipping_cost_at_cart',
		] );
		add_settings_field( 'pick_delivery_address', esc_html__( 'Pick Precise Delivery Address from Map', 'lafka-plugin' ), array(
			__CLASS__,
			'pick_delivery_address_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'pick_delivery_address',
		] );
		add_settings_field( 'mandatory_pickup_delivery', esc_html__( 'Mandatory to pick address', 'lafka-plugin' ), array(
			__CLASS__,
			'mandatory_pickup_delivery_cb'
		), 'lafka_shipping_areas_general', 'general_section', [
			'label_for' => 'mandatory_pickup_delivery',
			'class'     => 'hidden'
		] );

		add_settings_section( 'advanced_section', '', null, 'lafka_shipping_areas_advanced' );
		add_settings_field( 'deactivate_post_code', esc_html__( 'Optional Postcode Field', 'lafka-plugin' ), array(
			__CLASS__,
			'deactivate_post_code_cb'
		), 'lafka_shipping_areas_advanced', 'advanced_section', [
			'label_for' => 'deactivate_post_code',
		] );
		add_settings_field( 'disable_state', esc_html__( 'Disable State Field on Checkout', 'lafka-plugin' ), array(
			__CLASS__,
			'disable_state_cb'
		), 'lafka_shipping_areas_advanced', 'advanced_section', [
			'label_for' => 'disable_state',
		] );
		add_settings_field( 'debug_mode', esc_html__( 'Debug Mode', 'lafka-plugin' ), array( __CLASS__, 'debug_mode_cb' ), 'lafka_shipping_areas_advanced', 'advanced_section', [
			'label_for' => 'debug_mode',
		] );
		add_settings_field( 'set_store_location', esc_html__( 'Set Store Location', 'lafka-plugin' ), array(
			__CLASS__,
			'set_store_location_cb'
		), 'lafka_shipping_areas_advanced', 'advanced_section', [
			'label_for' => 'set_store_location',
		] );
		add_settings_field( 'store_map_location', esc_html__( 'Pick Store Location', 'lafka-plugin' ), array(
			__CLASS__,
			'store_map_location_cb'
		), 'lafka_shipping_areas_advanced', 'advanced_section', [
			'label_for' => 'store_map_location',
			'class'     => 'lafka-shipping-pick-store-location-container hidden'
		] );

		add_settings_section( 'datetime_section', '', null, 'lafka_shipping_areas_datetime' );
		add_settings_field( 'enable_datetime_option', esc_html__( 'Enable Date Time Picker', 'lafka-plugin' ), array(
			__CLASS__,
			'enable_datetime_option_cb'
		), 'lafka_shipping_areas_datetime', 'datetime_section', [
			'label_for' => 'enable_datetime_option',
		] );
		add_settings_field( 'datetime_mandatory', esc_html__( 'Mandatory', 'lafka-plugin' ), array(
			__CLASS__,
			'datetime_mandatory_cb'
		), 'lafka_shipping_areas_datetime', 'datetime_section', [
			'label_for' => 'datetime_mandatory',
		] );
		add_settings_field( 'days_ahead', esc_html__( 'Number of days ahead to order', 'lafka-plugin' ), array(
			__CLASS__,
			'days_ahead_cb'
		), 'lafka_shipping_areas_datetime', 'datetime_section', [
			'label_for' => 'days_ahead',
		] );
		add_settings_field( 'timeslot_duration', esc_html__( 'Time Slot Duration', 'lafka-plugin' ), array(
			__CLASS__,
			'timeslot_duration_cb'
		), 'lafka_shipping_areas_datetime', 'datetime_section', [
			'label_for' => 'timeslot_duration',
		] );
		add_settings_field( 'orders_per_timeslot', esc_html__( 'Orders Per Time Slot', 'lafka-plugin' ), array(
			__CLASS__,
			'orders_per_timeslot_cb'
		), 'lafka_shipping_areas_datetime', 'datetime_section', [
			'label_for' => 'orders_per_timeslot',
		] );

		add_settings_section(
			'branches_section',
			'',
			array(
				__CLASS__,
				'branches_section_cb'
			),
			'lafka_shipping_areas_branches'
		);
		add_settings_field( 'enable_branch_selection_modal', esc_html__( 'Location Confirmation Popup', 'lafka-plugin' ), array(
			__CLASS__,
			'enable_branch_selection_modal_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'enable_branch_selection_modal',
		] );
		add_settings_field( 'closable_popup', esc_html__( 'Closable Popup', 'lafka-plugin' ), array(
			__CLASS__,
			'closable_popup_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'closable_popup',
		] );
		add_settings_field( 'allow_partial_address', esc_html__( 'Allow Partial Address', 'lafka-plugin' ), array(
			__CLASS__,
			'allow_partial_address_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'allow_partial_address',
		] );
		add_settings_field( 'autocomplete_area', esc_html__( 'Google Autocomplete Predictions Area Bounds', 'lafka-plugin' ), array(
			__CLASS__,
			'autocomplete_area_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'autocomplete_area',
		] );
		add_settings_field( 'autocomplete_countries', esc_html__( 'Google Autocomplete Predictions Limit By Countries', 'lafka-plugin' ), array(
			__CLASS__,
			'autocomplete_countries_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'autocomplete_countries',
		] );
		add_settings_field( 'products_by_branches', esc_html__( 'Different Products in Branches', 'lafka-plugin' ), array(
			__CLASS__,
			'products_by_branches_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'products_by_branches',
		] );
		add_settings_field( 'show_branches_info_in', esc_html__( 'Show Branches Info Box in', 'lafka-plugin' ), array(
			__CLASS__,
			'show_branches_info_in_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'show_branches_info_in',
		] );
		add_settings_field( 'order_type', esc_html__( 'Order Type', 'lafka-plugin' ), array(
			__CLASS__,
			'order_type_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'order_type',
		] );
		add_settings_field( 'hide_address_fields', esc_html__( 'Hide Address Fields', 'lafka-plugin' ), array(
			__CLASS__,
			'hide_address_fields_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'hide_address_fields',
		] );
		add_settings_field( 'branch_selection_type', esc_html__( 'Branch Selection Type', 'lafka-plugin' ), array(
			__CLASS__,
			'branch_selection_type_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'branch_selection_type',
		] );
		add_settings_field( 'disable_current_location', esc_html__( 'Disable Current Location', 'lafka-plugin' ), array(
			__CLASS__,
			'disable_current_location_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'disable_current_location',
		] );
		add_settings_field( 'disable_order_emails', esc_html__( 'Disable Order Emails to Branch Managers', 'lafka-plugin' ), array(
			__CLASS__,
			'disable_order_emails_cb'
		), 'lafka_shipping_areas_branches', 'branches_section', [
			'label_for' => 'disable_order_emails',
		] );
	}
}

Lafka_Shipping_Areas_Admin::init();