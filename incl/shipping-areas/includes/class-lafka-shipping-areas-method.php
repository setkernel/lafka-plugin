<?php
defined( 'ABSPATH' ) || exit;

function lafka_shipping_areas_method_init() {
	if ( ! class_exists( 'Lafka_Shipping_Areas_Method' ) ) {
		class Lafka_Shipping_Areas_Method extends WC_Shipping_Method {
			/**
			 * @var mixed
			 */
			private $distance_unit;
			/**
			 * @var mixed
			 */
			private $branch_location;
			/**
			 * @var mixed
			 */
			private $rate_mode;
			/**
			 * @var mixed
			 */
			private $rate;
			/**
			 * @var mixed
			 */
			private $rate_fixed;
			/**
			 * @var mixed
			 */
			private $rate_distance;
			/**
			 * @var mixed
			 */
			private $round_distance;
			/**
			 * @var mixed
			 */
			private $minamount;
			/**
			 * @var mixed
			 */
			private $restrict_by;
			/**
			 * @var mixed
			 */
			private $delivery_area;
			/**
			 * @var mixed
			 */
			private $max_radius;

			/**
			 * Constructor for your shipping class
			 *
			 * @param int $instance_id Instance ID.
			 *
			 * @access public
			 * @return void
			 */
			public function __construct( $instance_id = 0 ) {
				$this->id                 = 'lafka_shipping_areas_method'; // Id for your shipping method. Should be uunique.
				$this->instance_id        = absint( $instance_id );
				$this->method_title       = __( 'Lafka Shipping Areas Method', 'lafka-plugin' );  // Title shown in admin
				$this->method_description = __( 'Shipping method to be used with Lafka Shipping Areas', 'lafka-plugin' ); // Description shown in admin
				$this->title              = __( 'Lafka Shipping Areas', 'lafka-plugin' );
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal'
				);

				$this->init();

				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			/**
			 * Init your settings
			 *
			 * @access public
			 * @return void
			 */
			function init() {
				// Load the settings API
				$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
				$this->init_settings(); // This is part of the settings API. Loads settings you previously init.

				// Define user set variables.
				$this->title           = $this->get_option( 'title' );
				$this->distance_unit   = $this->get_option( 'distance_unit' );
				$this->branch_location = $this->get_option( 'branch_location' );
				$this->rate_mode       = $this->get_option( 'rate_mode' );
				$this->rate            = $this->get_option( 'rate' );
				$this->rate_fixed      = $this->get_option( 'rate_fixed' );
				$this->rate_distance   = $this->get_option( 'rate_distance' );
				$this->round_distance  = $this->get_option( 'round_distance' );
				$this->tax_status      = $this->get_option( 'tax_status' );
				$this->minamount       = $this->get_option( 'minamount' );
				$this->restrict_by     = $this->get_option( 'restrict_by' );
				$this->delivery_area   = $this->get_option( 'delivery_area' );
				$this->max_radius      = $this->get_option( 'max_radius' );
			}

			/**
			 * Check if this shipping method is available.
			 *
			 * @param array $package Package of items from cart.
			 * @return bool
			 */
			public function is_available( $package ) {
				// Branch restriction: hide method when session branch doesn't match
				if ( ! empty( $this->branch_location ) && $this->branch_location !== 'lafka_all_branches' ) {
					$branch_location_session = WC()->session->get( 'lafka_branch_location' );
					if ( empty( $branch_location_session['branch_id'] ) || $this->branch_location !== (string) $branch_location_session['branch_id'] ) {
						return false;
					}
				}

				// Distance mode requires an API key to function
				if ( in_array( $this->rate_mode, array( 'distance', 'fixed_and_distance' ), true ) ) {
					$options           = get_option( 'lafka_shipping_areas_advanced' );
					$has_secondary_key = ! empty( $options['secondary_google_maps_api_key'] );
					$has_primary_key   = (bool) lafka_get_option( 'google_maps_api_key' );
					if ( ! $has_secondary_key && ! $has_primary_key ) {
						return false;
					}
				}

				return parent::is_available( $package );
			}

			/**
			 * Init form fields.
			 */
			public function init_form_fields() {
				global /** @var Lafka_Shipping_Areas $lafka_shipping_areas */
				$lafka_shipping_areas;

				$delivery_areas = array();
				foreach ( $lafka_shipping_areas->get_all_delivery_areas() as $area ) {
					$delivery_areas[ $area->ID ] = get_the_title( $area->ID );
				}

				$this->instance_form_fields = array(
					'title'           => array(
						'title'       => __( 'Title', 'lafka-plugin' ),
						'type'        => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'lafka-plugin' ),
						'default'     => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
						'desc_tip'    => true,
					),
					'branch_location' => array(
						'title'       => __( 'Branch', 'lafka-plugin' ),
						'type'        => 'select',
						'class'       => 'wc-enhanced-select',
						'default'     => 'lafka_all_branches',
						'description' => __( 'Select branch (if defined), where the shipping method will apply. Or set "All Branches" to ignore this check.', 'lafka-plugin' ),
						'options'     => ( array( 'lafka_all_branches' => esc_html__( 'All Branches', 'lafka-plugin' ) ) + Lafka_Shipping_Areas::get_all_legit_branch_locations() ),
						'desc_tip'    => true
					),
					'distance_unit'   => array(
						'title'       => __( 'Distance Unit', 'lafka-plugin' ),
						'type'        => 'select',
						'class'       => 'wc-enhanced-select',
						'desc_tip'    => true,
						'description' => __( 'Choose what distance unit to use.', 'lafka-plugin' ),
						'default'     => 'metric',
						'options'     => array(
							'metric'   => __( 'Metric (km)', 'lafka-plugin' ),
							'imperial' => __( 'Imperial (miles)', 'lafka-plugin' ),
						),
					),
					'rate_mode'       => array(
						'title'    => __( 'Shipping Rate', 'lafka-plugin' ),
						'type'     => 'select',
						'class'    => 'wc-enhanced-select',
						'default'  => 'flat',
						'desc_tip' => true,
						'options'  => array(
							'flat'               => __( 'Flat Rate', 'lafka-plugin' ),
							'distance'           => __( 'By driving distance', 'lafka-plugin' ),
							'fixed_and_distance' => __( 'By fixed rate + per driving distance', 'lafka-plugin' ),
						),
					),
					'rate'            => array(
						'title'             => __( 'Flat Rate', 'lafka-plugin' ),
						'type'              => 'text',
						'description'       => __( 'Enter a cost (excl. tax) or sum, e.g. <code>10.00 * [qty]</code>.', 'woocommerce' ) . '<br/><br/>' . __( 'Use <code>[qty]</code> for the number of items, <br/><code>[cost]</code> for the total cost of items, and <code>[fee percent="10" min_fee="20" max_fee=""]</code> for percentage based fees.', 'woocommerce' ),
						'desc_tip'          => true,
						'default'           => '0',
						'sanitize_callback' => array(
							$this,
							'sanitize_cost'
						),
					),
					'rate_fixed'      => array(
						'title'       => __( 'Fixed Rate', 'lafka-plugin' ),
						'type'        => 'text',
						'description' => __( 'Enter a fixed shipping rate.', 'lafka-plugin' ),
						'desc_tip'    => true,
						'default'     => '0'
					),
					'rate_distance'   => array(
						'title'       => __( 'Distance Unit Rate', 'lafka-plugin' ),
						'type'        => 'text',
						'description' => __( 'Enter the rate per shipping distance unit.', 'lafka-plugin' ),
						'desc_tip'    => true,
						'default'     => '0'
					),
					'round_distance'  => array(
						'title'       => __( 'Round Up Distance', 'lafka-plugin' ),
						'type'        => 'checkbox',
						'description' => __( 'Round up the calculated shipping distance with decimal to the nearest absolute number.', 'lafka-plugin' ),
						'desc_tip'    => true,
						'default'     => 'no'
					),
					'tax_status'      => array(
						'title'   => __( 'Tax status', 'lafka-plugin' ),
						'type'    => 'select',
						'class'   => 'wc-enhanced-select',
						'default' => 'taxable',
						'options' => array(
							'taxable' => __( 'Taxable', 'lafka-plugin' ),
							'none'    => _x( 'None', 'Tax status', 'lafka-plugin' ),
						),
					),
					'minamount'       => array(
						'title'       => __( 'Minimum order amount', 'lafka-plugin' ),
						'type'        => 'text',
						'description' => __( 'Enter the minimum order amount which is needed to apply the rate.', 'lafka-plugin' ),
						'desc_tip'    => true,
						'default'     => '0',
					),
					array(
						'title' => __( 'Location Based Restrictions', 'lafka-plugin' ),
						'type'  => 'title',
					),
					'restrict_by'     => array(
						'title'       => __( 'Restrict by', 'lafka-plugin' ),
						'type'        => 'select',
						'class'       => 'wc-enhanced-select',
						'description' => __( 'Select a method for restriction', 'lafka-plugin' ),
						'desc_tip'    => true,
						'options'     => array(
							'shipping_area' => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
							'radius'        => __( 'Radius', 'lafka-plugin' ),
							'none'          => __( 'None', 'lafka-plugin' ),
						),
						'default'     => 'none',
					),
					'delivery_area'   => array(
						'title'       => __( 'Delivery Area', 'lafka-plugin' ),
						'type'        => 'select',
						'class'       => 'wc-enhanced-select',
						'description' => __( 'Select lafka shipping area or specify the area by radius', 'lafka-plugin' ),
						'desc_tip'    => true,
						'options'     => $delivery_areas,
						'default'     => '',
					),
					'max_radius'      => array(
						'title'       => __( 'Maximum radius', 'lafka-plugin' ),
						'type'        => 'text',
						'description' => __( 'Maximum radius in (km/miles) from the store/branch address. If set to "All Branches", the WooCommerce store address will be used.', 'lafka-plugin' ),
						'desc_tip'    => true,
						'default'     => '0'
					),
				);
			}

			/**
			 * Calculate the shipping costs.
			 *
			 * @param array $package Package of items from cart.
			 */
			public function calculate_shipping( $package = array() ) {
				// Check if is restricted to branch locations
				$branch_location_session          = WC()->session->get( 'lafka_branch_location' );
				$branch_location_address_geocoded = '';
				if ( ! empty( $this->branch_location ) && $this->branch_location !== 'lafka_all_branches' && ! empty( $branch_location_session['branch_id'] ) ) {
					if ( $this->branch_location === (string) $branch_location_session['branch_id'] ) {
						$branch_location_address_geocoded = get_term_meta( $this->branch_location, 'lafka_branch_address_geocoded', true );
					} else {
						return;
					}
				}

				// If order amount is less than the minimal amount don't show the shipping method
				if ( $this->minamount > 0 && $package['contents_cost'] < $this->minamount ) {
					$notice_message = sprintf(
						esc_html__( 'Minimum order amount for %1$s is %2$s. You need just %3$s more for %1$s. %4$s', 'lafka-plugin' ),
						'<span>' . $this->title . '</span>',
						wc_price( $this->minamount ),
						wc_price( $this->minamount - $package['contents_cost'] ),
						'<a class="lafka-continue-shopping" href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">' . esc_html__( 'Continue Shopping', 'lafka-plugin' ) . '</a>'
					);
					if ( ! wc_has_notice( $notice_message ) ) {
						wc_add_notice( $notice_message, 'success', array(
								'lafka-notice'    => 'minimum-amount',
								'lafka-method-id' => $this->get_instance_id()
							)
						);
					}
				}

				$this->handle_js_area_check( $package, $branch_location_address_geocoded );

				$calculated_rate     = '';
				$rate_label          = $this->title;
				$distance_api_result = array();

				if ( in_array( $this->rate_mode, array( 'distance', 'fixed_and_distance' ) ) ) {
					$options             = get_option( 'lafka_shipping_areas_advanced' );
					$store_location_type = empty( $options['set_store_location'] ) ? 'geo_woo_store' : $options['set_store_location'];
					$store_map_location  = empty( $options['store_map_location'] ) ? '' : $options['store_map_location'];

					$origin = '';
					if ( ! empty( $branch_location_address_geocoded ) ) {
						$branch_location_decoded = json_decode( urldecode( $branch_location_address_geocoded ) );
						$branch_lat = isset( $branch_location_decoded->lat ) ? floatval( $branch_location_decoded->lat ) : 0;
						$branch_lng = isset( $branch_location_decoded->lng ) ? floatval( $branch_location_decoded->lng ) : 0;
						if ( $branch_lat >= -90 && $branch_lat <= 90 && $branch_lng >= -180 && $branch_lng <= 180 && ( $branch_lat !== 0.0 || $branch_lng !== 0.0 ) ) {
							$origin = array( 'lat' => $branch_lat, 'lng' => $branch_lng );
						}
					} else if ( $store_location_type === 'geo_woo_store' ) {
						$origin = Lafka_Shipping_Areas::get_store_address();
					} else {
						$store_map_location_decoded = json_decode( urldecode( $store_map_location ) );
						$store_lat = isset( $store_map_location_decoded->lat ) ? floatval( $store_map_location_decoded->lat ) : 0;
						$store_lng = isset( $store_map_location_decoded->lng ) ? floatval( $store_map_location_decoded->lng ) : 0;
						if ( $store_lat >= -90 && $store_lat <= 90 && $store_lng >= -180 && $store_lng <= 180 && ( $store_lat !== 0.0 || $store_lng !== 0.0 ) ) {
							$origin = array( 'lat' => $store_lat, 'lng' => $store_lng );
						}
					}

					if ( ! empty( $origin ) ) {
						$parsed_data = array();
						if ( isset( $_POST['post_data'] ) ) {
							$post_data = sanitize_text_field( wp_unslash( $_POST['post_data'] ) );
							parse_str( $post_data, $parsed_data );
						}

						$destination = '';
						if ( empty( $parsed_data['lafka_picked_delivery_geocoded'] ) ) {
							$destination = $this->convert_address_to_geocode_format( $package['destination'] );
						} else {
							$picked_location_decoded = json_decode( urldecode( $parsed_data['lafka_picked_delivery_geocoded'] ) );
							$picked_lat = isset( $picked_location_decoded->lat ) ? floatval( $picked_location_decoded->lat ) : 0;
							$picked_lng = isset( $picked_location_decoded->lng ) ? floatval( $picked_location_decoded->lng ) : 0;
							if ( $picked_lat >= -90 && $picked_lat <= 90 && $picked_lng >= -180 && $picked_lng <= 180 && ( $picked_lat !== 0.0 || $picked_lng !== 0.0 ) ) {
								$destination = array( 'lat' => $picked_lat, 'lng' => $picked_lng );
							}
						}

						$distance_api_result = $this->request_distance_api( $origin, $destination );
						if ( empty( $distance_api_result ) ) {
							$notice_msg = esc_html__( 'Shipping could not be calculated. Please check your address or contact us for assistance.', 'lafka-plugin' );
							if ( ! wc_has_notice( $notice_msg, 'notice' ) ) {
								wc_add_notice( $notice_msg, 'notice' );
							}
							return;
						}
					}

					// If we are here, then we have distance result
					if ( ! empty( $distance_api_result ) && ! empty( $this->rate_distance ) ) {
						$calculated_rate = $distance_api_result['distance'] * $this->rate_distance;
					}

					if ( ! empty( $this->rate_fixed ) ) {
						$calculated_rate += $this->rate_fixed;
					}

					if ( ! empty( $distance_api_result['distance_text'] ) ) {
						$rate_label = sprintf( '%s (%s)', $this->title, $distance_api_result['distance_text'] );
					}
				} elseif ( $this->rate_mode === 'flat' ) {
					$calculated_rate = $this->rate;
				}

				$rate = array(
					'id'      => $this->get_rate_id(),
					'label'   => $rate_label,
					'cost'    => 0,
					'package' => $package,
				);

				// Calculate the costs.
				$has_costs = false; // True when a cost is set. False if all costs are blank strings.
				$cost      = $calculated_rate;

				if ( '' !== $cost ) {
					$has_costs    = true;
					$rate['cost'] = $this->evaluate_cost( $cost, array(
						'qty'  => $this->get_package_item_qty( $package ),
						'cost' => $package['contents_cost'],
					) );
				}

				if ( $has_costs ) {
					$this->add_rate( $rate );
				}
			}

			private function handle_js_area_check( $package, $branch_location_address_geocoded ) {
				// Add the destination address to be used when in cart
				wp_add_inline_script( 'lafka-shipping-areas-handle-shipping', 'lafka_shipping_destination_address_property = ' . wp_json_encode( $package['destination'] ), 'before' );
				if ( $this->restrict_by === 'shipping_area' && is_numeric( $this->delivery_area ) ) {
					$shipping_area_coordinates = get_post_meta( $this->delivery_area, '_lafka_shipping_area_polygon_coordinates', true );
					wp_add_inline_script( 'lafka-shipping-areas-handle-shipping', '
						lafka_shipping_properties.shipping_area_instance_' . intval( $this->instance_id ) . ' = ' . wp_json_encode( array(
							'shipping_area_coordinates' => $shipping_area_coordinates,
							'min_amount'                => $this->minamount,
							'cart_subtotal'             => $package['cart_subtotal'] ?? '',
						) ), 'before' );
				} elseif ( $this->restrict_by === 'radius' && is_numeric( $this->max_radius ) ) {
					wp_add_inline_script( 'lafka-shipping-areas-handle-shipping', '
						lafka_shipping_properties.radius_area_instance_' . intval( $this->instance_id ) . ' = ' . wp_json_encode( array(
							'max_radius'                       => $this->max_radius,
							'shipping_method_distance_unit'    => $this->distance_unit,
							'branch_location_address_geocoded' => $branch_location_address_geocoded,
							'min_amount'                       => $this->minamount,
							'cart_subtotal'                    => $package['cart_subtotal'] ?? '',
						) ), 'before' );
				}
			}

			protected function evaluate_cost( $sum, $args = array() ) {
				// Add warning for subclasses.
				if ( ! is_array( $args ) || ! array_key_exists( 'qty', $args ) || ! array_key_exists( 'cost', $args ) ) {
					wc_doing_it_wrong( __FUNCTION__, '$args must contain `cost` and `qty` keys.', '4.0.1' );
				}

				include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';

				// Allow 3rd parties to process shipping cost arguments.
				$args           = apply_filters( 'woocommerce_evaluate_shipping_cost_args', $args, $sum, $this );
				$locale         = localeconv();
				$decimals       = array( wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ',' );
				$this->fee_cost = $args['cost'];

				// Expand shortcodes.
				add_shortcode( 'fee', array( $this, 'fee' ) );

				$sum = do_shortcode( str_replace( array(
					'[qty]',
					'[cost]',
				), array(
					$args['qty'],
					$args['cost'],
				), $sum ) );

				remove_shortcode( 'fee', array( $this, 'fee' ) );

				// Remove whitespace from string.
				$sum = preg_replace( '/\s+/', '', $sum );

				// Remove locale from string.
				$sum = str_replace( $decimals, '.', $sum );

				// Trim invalid start/end characters.
				$sum = rtrim( ltrim( $sum, "\t\n\r\0\x0B+*/" ), "\t\n\r\0\x0B+-*/" );

				// Do the math.
				return $sum ? WC_Eval_Math::evaluate( $sum ) : 0;
			}

			/**
			 * Work out fee (shortcode).
			 *
			 * @param array $atts Attributes.
			 *
			 * @return string
			 */
			public function fee( $atts ) {
				$atts = shortcode_atts( array(
					'percent' => '',
					'min_fee' => '',
					'max_fee' => '',
				), $atts, 'fee' );

				$calculated_fee = 0;

				if ( $atts['percent'] ) {
					$calculated_fee = $this->fee_cost * ( floatval( $atts['percent'] ) / 100 );
				}

				if ( $atts['min_fee'] && $calculated_fee < $atts['min_fee'] ) {
					$calculated_fee = $atts['min_fee'];
				}

				if ( $atts['max_fee'] && $calculated_fee > $atts['max_fee'] ) {
					$calculated_fee = $atts['max_fee'];
				}

				return $calculated_fee;
			}

			public function get_package_item_qty( $package ) {
				$total_quantity = 0;
				foreach ( $package['contents'] as $item_id => $values ) {
					if ( $values['quantity'] > 0 && $values['data']->needs_shipping() ) {
						$total_quantity += $values['quantity'];
					}
				}

				return $total_quantity;
			}

			public function find_shipping_classes( $package ): array {
				$found_shipping_classes = array();

				foreach ( $package['contents'] as $item_id => $values ) {
					if ( $values['data']->needs_shipping() ) {
						$found_class = $values['data']->get_shipping_class();

						if ( ! isset( $found_shipping_classes[ $found_class ] ) ) {
							$found_shipping_classes[ $found_class ] = array();
						}

						$found_shipping_classes[ $found_class ][ $item_id ] = $values;
					}
				}

				return $found_shipping_classes;
			}

			private function convert_address_to_geocode_format( $destination_address ): string {
				$parts = array(
					$destination_address['address_1'] ?? '',
					$destination_address['address_2'] ?? '',
					$destination_address['postcode'] ?? '',
					$destination_address['city'] ?? '',
					$destination_address['state'] ?? '',
					$destination_address['country'] ?? '',
				);

				return implode( ', ', array_filter( $parts, 'strlen' ) );
			}

			private function request_distance_api( $origin, $destination, $cache = true ): array {
				$api_key = lafka_get_option( 'google_maps_api_key' );
				$options = get_option( 'lafka_shipping_areas_general' );
				if ( ! empty( $options['secondary_google_maps_api_key'] ) ) {
					$api_key = $options['secondary_google_maps_api_key'];
				}

				if ( empty( $api_key ) ) {
					error_log( '[Lafka Shipping] Distance API request failed: no Google Maps API key configured.' );
					return array();
				}

				$api_request_data = array(
					'origins'      => $origin,
					'destinations' => $destination,
					'language'     => get_locale(),
					'key'          => $api_key,
				);

				$cache_key = '';
				if ( $cache && ! $this->is_debug_mode() ) {
					$cache_key = $this->autoprefixer( 'distance_api_request_' . md5( wp_json_encode( $api_request_data ) ) );

					// Check if the data already cached and return it.
					$cached_data = get_transient( $cache_key );

					if ( false !== $cached_data ) {
						return $cached_data;
					}
				}

				$calculate_distance_result = Lafka_API::calculate_distance( $api_request_data );

				if ( is_wp_error( $calculate_distance_result ) ) {
					lafka_write_log( $calculate_distance_result );

					if ( ! empty( get_option( 'lafka_shipping_areas_advanced' )['debug_mode'] ) ) {
						return array(
							'distance'      => 0,
							'distance_text' => 'Google Maps Distance API Error: ' . $calculate_distance_result->get_error_message()
						);
					} else {
						return array();
					}
				}

				$result = array();
				if ( isset( $calculate_distance_result[0] ) && ! empty( $calculate_distance_result[0]['distance'] ) ) {
					$converted_distance = $this->convert_distance( $calculate_distance_result[0]['distance'] );
					$distance           = $this->round_distance === 'yes' ? ceil( $converted_distance ) : round( $converted_distance, 1 );

					$result['distance']      = $distance;
					$result['distance_text'] = $this->get_text_of_distance( $distance );
				}

				if ( $result && $cache && ! empty( $cache_key ) && ! $this->is_debug_mode() ) {
					set_transient( $cache_key, $result, HOUR_IN_SECONDS ); // Store the data to transient with expiration in 1 hour for later use.
				}

				return $result;
			}

			/**
			 * Check if WooCommerce shipping is in debug mode
			 *
			 * @return bool
			 */
			public function is_debug_mode(): bool {
				return get_option( 'woocommerce_shipping_debug_mode', 'no' ) === 'yes';
			}

			protected function autoprefixer( $suffix ): string {
				return $this->id . '_' . $this->get_instance_id() . '_' . trim( $suffix, '_' );
			}

			/**
			 * Get text of formatted distance.
			 *
			 * @param float $distance Distance in km/mi unit.
			 *
			 * @return string
			 */
			protected function get_text_of_distance( float $distance ): string {
				$distance_formatted = wc_format_decimal( $distance, 1, true );

				if ( 'imperial' === $this->distance_unit ) {
					return $distance_formatted . ' mi';
				}

				return $distance_formatted . ' km';
			}

			/**
			 * Convert Meters to Distance Unit
			 *
			 * @param int $meters Number of meters to convert.
			 *
			 * @return float
			 */
			public function convert_distance( int $meters ): float {
				if ( 'imperial' === $this->distance_unit ) {
					return $this->convert_distance_to_mi( $meters );
				}

				return $this->convert_distance_to_km( $meters );
			}

			/**
			 * Convert Meters to Miles
			 *
			 * @param int $meters Number of meters to convert.
			 *
			 * @return float
			 */
			public function convert_distance_to_mi( int $meters ): float {
				return floatVal( ( $meters * 0.000621371 ) );
			}

			/**
			 * Convert Meters to Kilometers
			 *
			 * @param int $meters Number of meters to convert.
			 *
			 * @return float
			 */
			public function convert_distance_to_km( int $meters ): float {
				return floatVal( ( $meters * 0.001 ) );
			}
		}
	}
}
