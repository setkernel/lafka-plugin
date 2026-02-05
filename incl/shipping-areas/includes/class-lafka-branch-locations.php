<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

class Lafka_Branch_Locations {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_footer', array( __CLASS__, 'output_in_footer' ) );

		// Handle branch selection Ajax
		add_action( 'wp_ajax_nopriv_lafka_select_branch', array( __CLASS__, 'select_branch' ) );
		add_action( 'wp_ajax_lafka_select_branch', array( __CLASS__, 'select_branch' ) );

		// Set WC session for not logged-in users
		add_action( 'wp_loaded', array( __CLASS__, 'create_wc_session_if_needed' ) );

		// Change branch and/or address
		add_action( 'wp_ajax_nopriv_lafka_change_branch', array( __CLASS__, 'change_branch' ) );
		add_action( 'wp_ajax_lafka_change_branch', array( __CLASS__, 'change_branch' ) );

		// Add meta fields to the order
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'checkout_field_update_order_meta_fields' ), 10, 1 );

		// Alter Products meta query to get only the corresponding branch products
		$options_branches = get_option( 'lafka_shipping_areas_branches' );
		if ( ! empty( $options_branches['products_by_branches'] ) ) {
			add_filter( 'woocommerce_product_query_tax_query', array( __CLASS__, 'modify_products_tax_query_to_get_branch_products' ), 10, 2 );
			add_filter( 'woocommerce_product_related_posts_query', array( __CLASS__, 'modify_related_products_query_to_get_branch_products' ) );
			add_filter( 'woocommerce_products_widget_query_args', array( __CLASS__, 'modify_products_tax_query_to_get_branch_products_for_widgets' ));
			add_filter( 'woocommerce_subcategory_count_html', '__return_false' );
		}
		// Where to show branch info box (for 'shop' look in the theme)
		if ( isset( $options_branches['show_branches_info_in'] ) && in_array( 'mini_cart', $options_branches['show_branches_info_in'] ) ) {
			add_action( 'woocommerce_before_mini_cart', array( __CLASS__, 'show_change_branch' ) );
		}
		if ( isset( $options_branches['show_branches_info_in'] ) && in_array( 'cart', $options_branches['show_branches_info_in'] ) ) {
			add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'show_change_branch' ) );
		}
		if ( isset( $options_branches['show_branches_info_in'] ) && in_array( 'checkout', $options_branches['show_branches_info_in'] ) ) {
			add_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'show_change_branch' ), 1 );
		}

		// Override default state when it is empty
		add_filter( 'default_checkout_billing_state', array( __CLASS__, 'change_default_checkout_state' ) );

		// Update the infobox address the checkout address
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_lafka_session_address' ) );

		// Disable shipping locations if Pick Up is selected
		add_filter( 'wc_shipping_enabled', array( __CLASS__, 'enable_shipping_only_for_delivery' ) );
	}

	public static function enqueue_scripts() {
		$branch_locations_json_data          = self::get_branch_locations_json_data();
		$lafka_order_hours_options           = get_option( 'lafka_order_hours_options' );
		$options_branches                    = get_option( 'lafka_shipping_areas_branches' );
		$closable_because_of_closed_branches = false;
		$closable_because_of_option          = false;
		if ( ! empty( $lafka_order_hours_options['lafka_order_hours_closed_stores_message_enabled'] ) ) {
			$closable_because_of_closed_branches = true;
		}
		if ( ! empty( $options_branches['closable_popup'] ) && empty( $options_branches['products_by_branches'] ) ) {
			$closable_because_of_option = true;
		}


		wp_enqueue_script( 'lafka-branch-locations-front', plugins_url( '../assets/js/frontend/lafka-branch-locations-front.min.js', __FILE__ ), array(
			'lafka-google-maps',
			'jquery-blockui',
			'wc-country-select'
		), '1.0', true );
		wp_localize_script( 'lafka-branch-locations-front', 'lafka_branch_locations_front', array(
			'ajax_url'                            => admin_url( 'admin-ajax.php' ),
			'closable_because_of_closed_branches' => $closable_because_of_closed_branches,
			'closable_because_of_option'          => $closable_because_of_option,
			'allow_partial_address'               => ! empty( $options_branches['allow_partial_address'] ),
			'autocomplete_area'                   => $options_branches['autocomplete_area'] ?? '',
			'autocomplete_countries'              => $options_branches['autocomplete_countries'] ?? array(),
			'order_type'                          => empty( $options_branches['order_type'] ) ? 'delivery_pickup' : $options_branches['order_type'],
			'has_session_value'                   => ( isset( WC()->session ) && ! empty( WC()->session->get( 'lafka_branch_location' ) ) ),
			'branch_locations_json_data'          => $branch_locations_json_data,
			'please_wait_message'                 => esc_html__( 'Please wait', 'lafka-plugin' ),
			'info_message_select_branch_delivery' => esc_html__( 'Select from branches serving your area', 'lafka-plugin' ),
			'info_message_select_branch_pickup'   => esc_html__( 'Select a branch', 'lafka-plugin' ),
			'error_message_no_address'            => esc_html__( 'Please type your address and select suggestion or click on "Use current location...".', 'lafka-plugin' ),
			'error_message_json_parse'            => esc_html__( 'Something is wrong. Please try again.', 'lafka-plugin' ),
			'error_message_no_suitable_branches'  => esc_html__( 'Sorry, your address cannot be served by any of our branches.', 'lafka-plugin' ),
			'error_message_not_found'             => esc_html__( 'No results found.', 'lafka-plugin' ),
			'error_message_geocoder_failed'       => esc_html__( 'Geocoder failed due to', 'lafka-plugin' ),
			'error_message_precise_address'       => esc_html__( 'Please enter precise address.', 'lafka-plugin' ),
			'error_message_select_branch'         => esc_html__( 'Please select branch to continue.', 'lafka-plugin' ),
		) );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

	public static function output_in_footer() {
		$options_branches = get_option( 'lafka_shipping_areas_branches' );
		?>
        <div id="lafka_select_branch_modal" class="mfp-hide">
			<?php
			$show_all_closed_message    = false;
			$all_legit_branch_locations = array();
			if ( is_lafka_order_hours( get_option( 'lafka' ) ) && class_exists( 'Lafka_Order_Hours' ) ) {
				$lafka_order_hours_options = get_option( 'lafka_order_hours_options' );
				if ( $lafka_order_hours_options['lafka_order_hours_closed_stores_message_enabled'] ?? false ) {
					$main_store_timezone              = null;
					$main_store_schedule              = $lafka_order_hours_options['lafka_order_hours_schedule'] ?? '';
					$main_store_force_override_check  = $lafka_order_hours_options['lafka_order_hours_force_override_check'] ?? false;
					$main_store_force_override_status = $lafka_order_hours_options['lafka_order_hours_force_override_status'] ?? '';
					$main_shop_status                 = Lafka_Order_Hours::get_shop_status( $main_store_timezone, $main_store_schedule, $main_store_force_override_check, $main_store_force_override_status );

					if ( $main_shop_status->code === 'closed' ) {
						$all_branches_closed        = true;
						$all_legit_branch_locations = Lafka_Shipping_Areas::get_all_legit_branch_locations();
						foreach ( $all_legit_branch_locations as $branch_id => $branch_name ) {
							$branch_status = Lafka_Order_Hours::get_branch_working_status( $branch_id );
							if ( $branch_status->code === 'open' ) {
								$all_branches_closed = false;
							}
						}
						if ( $all_branches_closed === true ) {
							$show_all_closed_message = true;
						}
					}
				}
			}
			?>
			<?php if ( $show_all_closed_message ): ?>
                <div class="lafka-all-stores-closed">
                    <p><?php echo esc_html( $lafka_order_hours_options['lafka_order_hours_closed_stores_message'] ?? '' ); ?></p>
					<?php
					$lafka_order_hours_options = get_option( 'lafka_order_hours_options' );
					if ( $lafka_order_hours_options['lafka_order_hours_message_countdown'] ?? false ) {
						$first_opening_branch_datetime = Lafka_Order_Hours::get_first_opening_branch_datetime( $all_legit_branch_locations );
						if ( ! is_null( $first_opening_branch_datetime ) ) {
							?>
                            <div class="lafka-all-stores-closed-countdown">
								<?php
								$countdown_output_format = '{hn}:{mnn}:{snn}';
								$difference              = $first_opening_branch_datetime->diff( Lafka_Order_Hours::get_order_hours_time() );
								if ( $difference && $difference->d > 0 ) {
									$countdown_output_format = '{dn} {dl} {hn}:{mnn}:{snn}';
								}
								?>
                                <div class="count_holder_small">
                                    <div class="lafka_order_hours_countdown"
                                         data-diff-days="<?php echo esc_attr( $difference->d ); ?>"
                                         data-diff-hours="<?php echo esc_attr( $difference->h ); ?>"
                                         data-diff-minutes="<?php echo esc_attr( $difference->i ); ?>"
                                         data-diff-seconds="<?php echo esc_attr( $difference->s ); ?>"
                                         data-output-format="<?php echo esc_attr( $countdown_output_format ); ?>"
                                    ></div>
                                    <div class="clear"></div>
                                </div>
                            </div>
							<?php
						}
					}
					?>
                </div>
			<?php else: ?>
                <form id="lafka_select_branch_form">
                    <div class="lafka-branch-order-type">
						<?php if ( in_array( 'delivery', self::get_order_type() ) ): ?>
                            <a href="javascript:" class="lafka-branch-delivery"><?php esc_html_e( 'Delivery', 'lafka-plugin' ); ?></a>
						<?php endif; ?>
						<?php if ( in_array( 'pickup', self::get_order_type() ) ): ?>
                            <a href="javascript:" class="lafka-branch-pickup"><?php esc_html_e( 'Local pickup', 'lafka-plugin' ); ?></a>
						<?php endif; ?>
                    </div>
                    <div class="lafka-branch-user-address">
                        <label for="lafka_branch_select_user_address"><?php esc_html_e( 'Address', 'lafka-plugin' ); ?>
                            <input type="text" id="lafka_branch_select_user_address" name="lafka_branch_select_user_address"
                                   placeholder="<?php esc_html_e( 'Enter a delivery address', 'lafka-plugin' ); ?>"/>
							<?php if ( empty( $options_branches['disable_current_location'] ) ): ?>
                                <a href="javascript:" class="lafka-branch-auto-locate" title="<?php esc_html_e( 'Use current location', 'lafka-plugin' ); ?>">
                                    <i class="fa fa-location-arrow"></i>
									<?php esc_html_e( 'or detect my current location', 'lafka-plugin' ); ?>
                                </a>
							<?php endif; ?>
                        </label>
                    </div>
					<?php
					$branches              = Lafka_Shipping_Areas::get_all_legit_branch_locations();
					$branch_selection_type = empty( $options_branches['branch_selection_type'] ) ? 'images' : $options_branches['branch_selection_type']
					?>
                    <div class="lafka-branch-selection">
						<?php if ( $branch_selection_type === 'images' ): ?>
                            <div class="lafka-branch-select-images">
								<?php if ( empty( $branches ) ): ?>
                                    <span class="lafka-branch-select-tip"><?php esc_html_e( 'Sorry, no location available to order for this address.', 'lafka-plugin' ); ?></span>
								<?php else: ?>
                                    <span class="lafka-branch-select-tip"></span>
									<?php foreach ( $branches as $id => $name ): ?>
                                        <span class="lafka-branch-select-image  lafka-branch-<?php echo esc_attr( $id ); ?>">
                                    <a href="javascript:" data-branch-id="<?php echo (int) $id; ?>">
                                        <?php
                                        $branch_image_id = get_term_meta( $id, 'lafka_branch_location_img_id', true );
                                        if ( $branch_image_id ) {
	                                        $branch_image_src = wp_get_attachment_thumb_url( $branch_image_id );
                                        } else {
	                                        $branch_image_src = wc_placeholder_img_src();
                                        }
                                        ?>
                                        <img src="<?php echo esc_url( $branch_image_src ); ?>" alt="<?php echo esc_attr( $name ); ?>"/>
                                        <span class="lafka-branch-select-name"><?php echo esc_html( $name ); ?></span>
                                    </a>
                                </span>
									<?php endforeach; ?>
								<?php endif; ?>
                                <input type="hidden" name="lafka_selected_branch_id" id="lafka_selected_branch_id"/>
                            </div>
						<?php elseif ( $branch_selection_type === 'select' ): ?>
                            <div class="lafka-branch-select-dropdown">
                                <label class="lafka_branch_select_label" for="lafka_branch_select"></label>
                                <select id="lafka_branch_select" name="lafka_branch_select">
                                    <option value="" disabled selected><?php esc_html_e( 'Select Branch', 'lafka-plugin' ); ?> ...</option>
									<?php foreach ( $branches as $id => $name ): ?>
                                        <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ) ?></option>
									<?php endforeach; ?>
                                </select>
                            </div>
						<?php endif; ?>
                    </div>
                    <div class="lafka-branch-select-message"></div>
                    <input type="hidden" name="lafka_branch_order_type" id="lafka_branch_order_type"/>
                    <input type="hidden" name="lafka_user_country" id="lafka_user_country"/>
                    <input type="hidden" name="lafka_user_address_1" id="lafka_user_address_1"/>
                    <input type="hidden" name="lafka_user_city" id="lafka_user_city"/>
                    <input type="hidden" name="lafka_user_state" id="lafka_user_state"/>
                    <input type="hidden" name="lafka_user_postcode" id="lafka_user_postcode"/>
                    <input type="hidden" name="lafka_user_geocoded_location" id="lafka_user_geocoded_location"/>
					<?php wp_nonce_field( 'lafka_select_branch' ); ?>
                    <a class="lafka-branch-select-submit button" href="javascript:"><?php esc_html_e( 'Start Order', 'lafka-plugin' ); ?></a>
                </form>
			<?php endif; ?>
        </div>
		<?php
	}

	public static function select_branch() {
		check_ajax_referer( 'lafka_select_branch' );

		$fields_string = urldecode( $_POST['fields'] );
		parse_str( $fields_string, $fields );

		$selected_branch_id = null;
		if ( ! empty( $fields['lafka_branch_select'] ) ) {
			$selected_branch_id = (int) $fields['lafka_branch_select'];
		} elseif ( ! empty( $fields['lafka_selected_branch_id'] ) ) {
			$selected_branch_id = (int) $fields['lafka_selected_branch_id'];
		}

		if ( empty( $selected_branch_id ) ) {
			wp_send_json_error( new WP_Error( 'empty_request', esc_html__( 'Please select branch to continue.', 'lafka-plugin' ) ) );
		}

		if ( empty( $fields['lafka_branch_order_type'] ) ) {
			wp_send_json_error( new WP_Error( 'empty_request', esc_html__( 'Please select order type.', 'lafka-plugin' ) ) );
		}

		if ( $fields['lafka_branch_order_type'] === 'delivery' && ( empty( $fields['lafka_branch_select_user_address'] ) || empty( $fields['lafka_user_country'] ) ) ) {
			wp_send_json_error( new WP_Error( 'empty_request', esc_html__( 'Please type your address and select suggested address or click on "Use current location".', 'lafka-plugin' ) ) );
		}

		$branch_location = get_term( $selected_branch_id );

		if ( empty( $branch_location ) || is_wp_error( $branch_location ) ) {
			wp_send_json_error( new WP_Error( 'no_branch', esc_html__( 'Something is wrong. No such branch location.', 'lafka-plugin' ) ) );
		}

		// Clear cart if products per branch is selected, so we don't have an unavailable product if we switch the branch
		$options_branches = get_option( 'lafka_shipping_areas_branches' );
		if ( ! empty( $options_branches['products_by_branches'] ) ) {
			WC()->cart->empty_cart();
		}

		// If we are here - all is good
		$lafka_branch_location_session = array(
			'branch_id'  => $selected_branch_id,
			'order_type' => $fields['lafka_branch_order_type'],
			'country'    => $fields['lafka_user_country'],
			'address_1'  => $fields['lafka_user_address_1'],
			'city'       => $fields['lafka_user_city'],
			'state'      => self::get_processed_state_code_from_google_state( $fields['lafka_user_state'], $fields['lafka_user_country'] ),
			'postcode'   => $fields['lafka_user_postcode'],
		);;

		$full_address                                  = self::build_full_address_from_components( $lafka_branch_location_session );
		$lafka_branch_location_session['full_address'] = $full_address;

		WC()->session->set( 'lafka_branch_location', $lafka_branch_location_session );

		WC()->customer->set_billing_country( $lafka_branch_location_session['country'] ?? '' );
		WC()->customer->set_shipping_country( $lafka_branch_location_session['country'] ?? '' );
		WC()->customer->set_billing_state( $lafka_branch_location_session['state'] ?? '' );
		WC()->customer->set_shipping_state( $lafka_branch_location_session['state'] ?? '' );
		WC()->customer->set_billing_address_1( $lafka_branch_location_session['address_1'] ?? '' );
		WC()->customer->set_shipping_address_1( $lafka_branch_location_session['address_1'] ?? '' );
		WC()->customer->set_billing_city( $lafka_branch_location_session['city'] ?? '' );
		WC()->customer->set_shipping_city( $lafka_branch_location_session['city'] ?? '' );
		WC()->customer->set_billing_postcode( $lafka_branch_location_session['postcode'] ?? '' );
		WC()->customer->set_shipping_postcode( $lafka_branch_location_session['postcode'] ?? '' );
		WC()->customer->save();
		wp_send_json_success();
	}

	public static function create_wc_session_if_needed() {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		if ( isset( WC()->session ) && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	public static function show_change_branch() {
		$branch_location_session = WC()->session->get( 'lafka_branch_location' );

		if ( ! empty( $branch_location_session ) ) {
			$branch = get_term( $branch_location_session['branch_id'], 'lafka_branch_location' );
			if ( is_wp_error( $branch ) || empty( $branch ) ) {
				$branch_name = '';
			} else {
				$branch_name = $branch->name;
			}
			$delivery_time   = get_term_meta( $branch_location_session['branch_id'], 'lafka_branch_delivery_time', true );
			$order_type_text = esc_html__( 'Delivery', 'lafka-plugin' );
			$class           = 'lafka-delivery-info';
			if ( $branch_location_session['order_type'] === 'pickup' ) {
				$order_type_text = esc_html__( 'Pickup', 'lafka-plugin' );
				$class           = 'lafka-pickup-info';
			}
			?>
            <div class="lafka-change-branch <?php echo sanitize_html_class( $class ); ?>">
				<?php if ( ! empty( $delivery_time ) ): ?>
                    <span class="lafka-estimated-time"><?php echo esc_html( $delivery_time ); ?></span>
				<?php endif; ?>
                <span><strong><?php echo esc_html( $order_type_text ); ?> <?php esc_html_e( 'from', 'lafka-plugin' ); ?>:</strong> <?php echo esc_html( $branch_name ); ?></span>
                <span>
                    <?php if ( $branch_location_session['order_type'] === 'delivery' ): ?>
                        <strong><?php esc_html_e( 'To', 'lafka-plugin' ); ?>:</strong>
                        <span class="lafka-change-branch-full-address"><?php echo esc_html( $branch_location_session['full_address'] ); ?></span>
                    <?php endif; ?>
                    <a href="javascript:" class="lafka-change-branch-button"
                       data-nonce="<?php echo esc_attr( wp_create_nonce( 'lafka_change_branch' ) ); ?>"><?php esc_html_e( 'Change', 'lafka-plugin' ); ?></a>
                </span>
            </div>
			<?php
		} else if ( ! empty( Lafka_Shipping_Areas::get_all_legit_branch_locations() ) ) {
			?>
            <div class="lafka-change-branch">
                <a href="javascript:" class="lafka-change-branch-button-select"
                   data-nonce="<?php echo esc_attr( wp_create_nonce( 'lafka_change_branch' ) ); ?>"><?php esc_html_e( 'Select Location', 'lafka-plugin' ); ?></a>
            </div>
			<?php
		}
	}

	public static function change_branch() {
		check_ajax_referer( 'lafka_change_branch' );

		if ( ! empty( WC()->session ) ) {
			WC()->session->set( 'lafka_branch_location', '' );
			wp_send_json_success();
		}

		wp_send_json_error( new WP_Error( 'error', __( 'Something is wrong. No session.', 'lafka-plugin' ) ) );
	}

	public static function change_default_checkout_state( $value ): string {
		$session_data = WC()->session->get( 'lafka_branch_location' );
		$state        = $session_data['state'] ?? '';
		$order_type   = $session_data['order_type'] ?? '';
		// Return the state only when we have delivery. When pickup keep defaults
		if ( $order_type === 'delivery' ) {
			return sanitize_text_field( $state );
		}

		return $value ?? '';
	}

	public static function modify_related_products_query_to_get_branch_products( $query ) {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session['branch_id'] ) && is_numeric( $branch_location_session['branch_id'] ) ) {
				global $wpdb;
				$query['join']  .= " LEFT JOIN  {$wpdb->prefix}term_relationships AS term_rel ON (p.ID = term_rel.object_id)";
				$query['where'] .= " AND term_rel.term_taxonomy_id = {$branch_location_session['branch_id']}";
			}
		}

		return $query;
	}

	public static function modify_products_tax_query_to_get_branch_products( $tax_query, $wc_query ) {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session['branch_id'] ) && is_numeric( $branch_location_session['branch_id'] ) ) {
				$branch_products_tax_args = array(
					'taxonomy' => 'lafka_branch_location',
					'field'    => 'term_taxonomy_id',
					'terms'    => $branch_location_session['branch_id']
				);
				$tax_query[]              = $branch_products_tax_args;
			}
		}

		return $tax_query;
	}

	public static function modify_products_tax_query_to_get_branch_products_for_widgets( $query_args ): array {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) && ! empty( $branch_location_session['branch_id'] ) && is_numeric( $branch_location_session['branch_id'] ) ) {
				$branch_products_tax_args  = array(
					'taxonomy' => 'lafka_branch_location',
					'field'    => 'term_taxonomy_id',
					'terms'    => $branch_location_session['branch_id']
				);
				$query_args['tax_query'][] = $branch_products_tax_args;
			}
		}

		return $query_args;
	}

	private static function build_full_address_from_components( $address_raw_components ): string {
		$address_components = array();

		if ( $address_raw_components['address_1'] ) {
			$address_components[] = $address_raw_components['address_1'];
		}
		if ( ! empty( $address_raw_components['address_2'] ) ) {
			$address_components[] = $address_raw_components['address_2'];
		}
		if ( $address_raw_components['postcode'] ) {
			$address_components[] = $address_raw_components['postcode'];
		}
		if ( $address_raw_components['city'] ) {
			$address_components[] = $address_raw_components['city'];
		}
		if ( $address_raw_components['state'] && $address_raw_components['country'] ) {
			$all_states           = WC()->countries->get_states( $address_raw_components['country'] );
			$address_components[] = $all_states[ $address_raw_components['state'] ] ?? '';
		}
		if ( $address_raw_components['country'] ) {
			$all_countries        = WC()->countries->get_countries();
			$address_components[] = $all_countries[ $address_raw_components['country'] ] ?? '';
		}

		return implode( ', ', $address_components );
	}

	public static function update_lafka_session_address( $post_data ) {
		$lafka_branch_location_session = WC()->session->get( 'lafka_branch_location' );

		if ( ! empty( $lafka_branch_location_session ) ) {
			parse_str( $post_data, $post_data_array );

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['country'] = isset( $post_data_array['billing_country'] ) ? wc_clean( $post_data_array['billing_country'] ) : '';
			} else {
				$lafka_branch_location_session['country'] = isset( $post_data_array['shipping_country'] ) ? wc_clean( $post_data_array['shipping_country'] ) : '';
			}

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['address_1'] = isset( $post_data_array['billing_address_1'] ) ? wc_clean( $post_data_array['billing_address_1'] ) : '';
			} else {
				$lafka_branch_location_session['address_1'] = isset( $post_data_array['shipping_address_1'] ) ? wc_clean( $post_data_array['shipping_address_1'] ) : '';
			}

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['address_2'] = isset( $post_data_array['billing_address_2'] ) ? wc_clean( $post_data_array['billing_address_2'] ) : '';
			} else {
				$lafka_branch_location_session['address_2'] = isset( $post_data_array['shipping_address_2'] ) ? wc_clean( $post_data_array['shipping_address_2'] ) : '';
			}

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['city'] = isset( $post_data_array['billing_city'] ) ? wc_clean( $post_data_array['billing_city'] ) : '';
			} else {
				$lafka_branch_location_session['city'] = isset( $post_data_array['shipping_city'] ) ? wc_clean( $post_data_array['shipping_city'] ) : '';
			}

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['state'] = isset( $post_data_array['billing_state'] ) ? wc_clean( $post_data_array['billing_state'] ) : '';
			} else {
				$lafka_branch_location_session['state'] = isset( $post_data_array['shipping_state'] ) ? wc_clean( $post_data_array['shipping_state'] ) : '';
			}

			if ( empty( $post_data_array['ship_to_different_address'] ) ) {
				$lafka_branch_location_session['postcode'] = isset( $post_data_array['billing_postcode'] ) ? wc_clean( $post_data_array['billing_postcode'] ) : '';
			} else {
				$lafka_branch_location_session['postcode'] = isset( $post_data_array['shipping_postcode'] ) ? wc_clean( $post_data_array['shipping_postcode'] ) : '';
			}

			$lafka_branch_location_session['full_address'] = self::build_full_address_from_components( $lafka_branch_location_session );

			WC()->session->set( 'lafka_branch_location', $lafka_branch_location_session );
		}
	}

	public static function enable_shipping_only_for_delivery(): bool {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session['order_type'] ) && $branch_location_session['order_type'] === 'pickup' ) {
				return false;
			}
		}

		return true;
	}

	public static function get_order_type(): array {
		$branches_options = get_option( 'lafka_shipping_areas_branches' );
		$order_type       = empty( $branches_options['order_type'] ) ? 'delivery_pickup' : $branches_options['order_type'];

		$to_return = array();
		switch ( $order_type ) {
			case 'delivery_pickup':
				$to_return[] = 'delivery';
				$to_return[] = 'pickup';
				break;
			case 'delivery':
				$to_return[] = 'delivery';
				break;
			case'pickup':
				$to_return[] = 'pickup';
				break;
		}

		return $to_return;
	}

	public static function checkout_field_update_order_meta_fields( $order_id ) {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order = wc_get_order( $order_id );
				$order->update_meta_data( 'lafka_selected_branch_id', sanitize_text_field( $branch_location_session['branch_id'] ?? null ) );
				if ( ! empty( $branch_location_session['order_type'] ) ) {
					$order->update_meta_data( 'lafka_order_type', sanitize_text_field( $branch_location_session['order_type'] ) );
				}
				$order->save();
			} else {
				update_post_meta( $order_id, 'lafka_selected_branch_id', sanitize_text_field( $branch_location_session['branch_id'] ?? null ) );
				if ( ! empty( $branch_location_session['order_type'] ) ) {
					update_post_meta( $order_id, 'lafka_order_type', sanitize_text_field( $branch_location_session['order_type'] ) );
				}
			}
		}
	}

	public static function get_user_branches( $user_id ): array { // TODO: This may cause issues - can't see all orders when order is from branch where user is not manager... not sure
		$args = array(
			'taxonomy'   => 'lafka_branch_location',
			'hide_empty' => false,
			'fields'     => 'id=>name',
			'meta_query' => array(
				array(
					'key'     => 'lafka_branch_address',
					'value'   => '',
					'compare' => '!='
				),
				array(
					'key'     => 'lafka_branch_address_geocoded',
					'value'   => '',
					'compare' => '!='
				),
				array(
					'key'   => 'lafka_branch_user',
					'value' => $user_id,
				)
			)
		);

		$all_legit_branch_locations_for_user = get_terms( $args );

		if ( is_array( $all_legit_branch_locations_for_user ) ) {
			return $all_legit_branch_locations_for_user;
		} else {
			return array();
		}
	}

	private static function get_branch_locations_json_data() {
		$locations_rich_data = array();

		$branch_locations = Lafka_Shipping_Areas::get_all_legit_branch_locations();
		foreach ( $branch_locations as $location_id => $location_name ) {
			$location_meta = get_term_meta( $location_id );
			if ( is_array( $location_meta ) ) {
				$shipping_areas = array();
				if ( ! empty( $location_meta['lafka_branch_shipping_areas'][0] ) ) {
					foreach ( json_decode( $location_meta['lafka_branch_shipping_areas'][0] ) as $shipping_area_id ) {
						$shipping_areas[] = array(
							'id'                       => $shipping_area_id,
							'area_polygon_coordinates' => get_post_meta( $shipping_area_id, '_lafka_shipping_area_polygon_coordinates', true )
						);
					}
				}
				$locations_rich_data[] = array(
					'id'                      => $location_id,
					'distance_restriction'    => $location_meta['lafka_branch_distance_restriction'][0] ?? '',
					'distance_unit'           => $location_meta['lafka_branch_distance_unit'][0] ?? '',
					'branch_address_geocoded' => $location_meta['lafka_branch_address_geocoded'][0] ?? '',
					'shipping_areas'          => $shipping_areas,
					'order_type'              => $location_meta['lafka_branch_order_type'][0] ?? 'delivery_pickup',
				);
			}
		}

		return json_encode( $locations_rich_data );
	}

	private static function get_processed_state_code_from_google_state( $state, $country ) {
		$known_google_geocoded_incompatible_states = array(
			'Sofia City Province' => 'BG-22',
			'Област София'        => 'BG-22',
		);
		$wc_state_code                             = '';

		if ( ! empty( $country ) && ! empty( WC()->countries ) ) {
			$wc_country_states = WC()->countries->get_states( $country );
			if ( $wc_country_states && ! empty( $state ) ) {
				if ( empty( $wc_country_states[ $state ] ) ) {
					$processed_state_code = $known_google_geocoded_incompatible_states[ $state ] ?? '';
					if ( ! empty( $processed_state_code ) ) {
						$wc_state_code = $processed_state_code;
					}
				} else {
					$wc_state_code = $state;
				}
			} else {
				$wc_state_code = $state;
			}
		}

		return $wc_state_code;
	}
}

Lafka_Branch_Locations::init();