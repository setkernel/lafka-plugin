<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// PHPCS suppression for this WPML extension class: hooks fire from WPML/WC
// product-save flow where nonce verification happens upstream in the WC
// product editor (update-post_<id>) before our hook callbacks run. Reads
// of $_GET in display methods are for admin UI state, not state mutation.
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

/**
 * Class WCML_Lafka_Product_Addons
 */
class WCML_Lafka_Product_Addons {

	const PRICE_OPTION_KEY = '_product_addon_prices';

	/**
	 * @var SitePress
	 */
	public $sitepress;
	/**
	 * @var woocommerce_wpml
	 */
	private $woocommerce_wpml;
	/**
	 * @var int
	 */
	private $multi_currency_mode;

	/**
	 * WCML_Lafka_Product_Addons constructor.
	 * @param SitePress $sitepress
	 * @param woocommerce_wpml $woocommerce_wpml
	 */
	function __construct( SitePress $sitepress, woocommerce_wpml $woocommerce_wpml ) {
		$this->sitepress           = $sitepress;
		$this->woocommerce_wpml    = $woocommerce_wpml;
		$this->multi_currency_mode = $woocommerce_wpml->settings['enable_multi_currency'];
	}

	public function add_hooks() {

		add_action( 'init', array( $this, 'load_assets' ) );
		add_filter( 'get_product_addons_product_terms', array( $this, 'addons_product_terms' ) );

		add_action( 'updated_post_meta', array( $this, 'register_addons_strings' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'register_addons_strings' ), 10, 4 );

		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'wcml_gui_additional_box_html', array( $this, 'custom_box_html' ), 10, 3 );
			add_filter( 'wcml_gui_additional_box_data', array( $this, 'custom_box_html_data' ), 10, 3 );
			add_action( 'wcml_update_extra_fields', array( $this, 'addons_update' ), 10, 3 );

			add_action( 'woocommerce_product_data_panels', array( $this, 'show_pointer_info' ) );

			add_filter( 'wcml_do_not_display_custom_fields_for_product', array( $this, 'replace_tm_editor_custom_fields_with_own_sections' ) );

			if ( $this->is_multi_currency_on() ) {
				add_action( 'wcml_before_sync_product', array( $this, 'update_custom_prices_values' ) );
			}
		} else {
			add_filter( 'get_post_metadata', array( $this, 'translate_addons_strings' ), 10, 4 );
		}

		add_filter(
			'wcml_cart_contents_not_changed',
			array(
				$this,
				'filter_booking_addon_product_in_cart_contents',
			),
			20
		);

		add_filter(
			'get_product_addons_global_query_args',
			array(
				$this,
				'set_global_ids_in_query_args',
			)
		);
	}



	/**
	 * @param string $product_id
	 *
	 * @return array
	 */
	private function get_product_addons( $product_id ) {
		return maybe_unserialize( get_post_meta( $product_id, '_product_addons', true ) );
	}

	/**
	 * @param $meta_id
	 * @param $id
	 * @param $meta_key
	 * @param $addons
	 */
	function register_addons_strings( $meta_id, $id, $meta_key, $addons ) {
		if ( '_product_addons' === $meta_key && 'lafka_glb_addon' === get_post_type( $id ) ) {
			$this->update_custom_prices_values( $id );
			foreach ( $addons as $addon ) {
				//register name
				do_action( 'wpml_register_single_string', 'wc_product_addons_strings', $id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_name', $addon['name'] );
				//register description
				do_action( 'wpml_register_single_string', 'wc_product_addons_strings', $id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_description', $addon['description'] );
				//register options labels
				foreach ( $addon['options'] as $key => $option ) {
					do_action( 'wpml_register_single_string', 'wc_product_addons_strings', $id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_option_label_' . $key, $option['label'] );
				}
			}
		}
	}

	/**
	 * @param $null
	 * @param $object_id
	 * @param $meta_key
	 * @param $single
	 *
	 * @return array
	 */
	function translate_addons_strings( $null, $object_id, $meta_key, $single ) {

		if ( '_product_addons' === $meta_key && 'lafka_glb_addon' === get_post_type( $object_id ) ) {

			remove_filter( 'get_post_metadata', array( $this, 'translate_addons_strings' ), 10, 4 );
			$addons = get_post_meta( $object_id, $meta_key, true );
			add_filter( 'get_post_metadata', array( $this, 'translate_addons_strings' ), 10, 4 );

			if ( is_array( $addons ) ) {
				foreach ( $addons as $key => $addon ) {
					//register name
					$addons[ $key ]['name'] = apply_filters( 'wpml_translate_single_string', $addon['name'], 'wc_product_addons_strings', $object_id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_name' );
					//register description
					$addons[ $key ]['description'] = apply_filters( 'wpml_translate_single_string', $addon['description'], 'wc_product_addons_strings', $object_id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_description' );
					//register options labels
					foreach ( $addon['options'] as $opt_key => $option ) {
						$addons[ $key ]['options'][ $opt_key ]['label'] = apply_filters( 'wpml_translate_single_string', $option['label'], 'wc_product_addons_strings', $object_id . '_addon_' . $addon['type'] . '_' . $addon['position'] . '_option_label_' . $opt_key );
					}
				}
			}

			return array( 0 => $addons );
		}

		return $null;
	}


	/**
	 * @param $product_terms
	 *
	 * @return array
	 */
	function addons_product_terms( $product_terms ) {
		foreach ( $product_terms as $key => $product_term ) {
			$product_terms[ $key ] = apply_filters( 'translate_object_id', $product_term, 'product_cat', true, $this->sitepress->get_default_language() );
		}

		return $product_terms;
	}


	/**
	 * @param $obj
	 * @param $product_id
	 * @param $data
	 */
	function custom_box_html( $obj, $product_id, $data ) {

		$product_addons = $this->get_product_addons( $product_id );

		if ( ! empty( $product_addons ) ) {
			foreach ( $product_addons as $addon_id => $product_addon ) {

				$addons_section = new WPML_Editor_UI_Field_Section( sprintf( __( 'Product Lafka Add-ons Group "%s"', 'lafka-plugin' ), $product_addon['name'] ) );

				$group       = new WPML_Editor_UI_Field_Group( '', true );
				$addon_field = new WPML_Editor_UI_Single_Line_Field( 'addon_' . $addon_id . '_name', __( 'Name', 'lafka-plugin' ), $data, false );
				$group->add_field( $addon_field );
				$addon_field = new WPML_Editor_UI_Single_Line_Field( 'addon_' . $addon_id . '_description', __( 'Description', 'lafka-plugin' ), $data, false );
				$group->add_field( $addon_field );

				$addons_section->add_field( $group );

				if ( ! empty( $product_addon['options'] ) ) {

					$labels_group = new WPML_Editor_UI_Field_Group( __( 'Options', 'lafka-plugin' ), true );

					foreach ( $product_addon['options'] as $option_id => $option ) {
						$option_label_field = new WPML_Editor_UI_Single_Line_Field( 'addon_' . $addon_id . '_option_' . $option_id . '_label', __( 'Label', 'lafka-plugin' ), $data, false );
						$labels_group->add_field( $option_label_field );
					}
					$addons_section->add_field( $labels_group );
				}
				$obj->add_field( $addons_section );
			}
		}
	}

	/**
	 * @param $data
	 * @param $product_id
	 * @param $translation
	 *
	 * @return mixed
	 */
	function custom_box_html_data( $data, $product_id, $translation ) {

		$product_addons = $this->get_product_addons( $product_id );

		if ( ! empty( $product_addons ) ) {
			foreach ( $product_addons as $addon_id => $product_addon ) {
				$data[ 'addon_' . $addon_id . '_name' ]        = array( 'original' => $product_addon['name'] );
				$data[ 'addon_' . $addon_id . '_description' ] = array( 'original' => $product_addon['description'] );
				if ( ! empty( $product_addon['options'] ) ) {
					foreach ( $product_addon['options'] as $option_id => $option ) {
						$data[ 'addon_' . $addon_id . '_option_' . $option_id . '_label' ] = array( 'original' => $option['label'] );
					}
				}
			}

			if ( $translation ) {
				$translated_product_addons = $this->get_product_addons( $translation->ID );
				if ( ! empty( $translated_product_addons ) ) {
					foreach ( $translated_product_addons as $addon_id => $transalted_product_addon ) {
						$data[ 'addon_' . $addon_id . '_name' ]['translation']        = $transalted_product_addon['name'];
						$data[ 'addon_' . $addon_id . '_description' ]['translation'] = $transalted_product_addon['description'];
						if ( ! empty( $transalted_product_addon['options'] ) ) {
							foreach ( $transalted_product_addon['options'] as $option_id => $option ) {
								$data[ 'addon_' . $addon_id . '_option_' . $option_id . '_label' ]['translation'] = $option['label'];
							}
						}
					}
				}
			}
		}

		return $data;
	}

	/**
	 * @param $original_product_id
	 * @param $product_id
	 * @param $data
	 */
	function addons_update( $original_product_id, $product_id, $data ) {

		$product_addons = $this->get_product_addons( $original_product_id );

		if ( ! empty( $product_addons ) ) {

			foreach ( $product_addons as $addon_id => $product_addon ) {

				$product_addons[ $addon_id ]['name']        = $data[ md5( 'addon_' . $addon_id . '_name' ) ];
				$product_addons[ $addon_id ]['description'] = $data[ md5( 'addon_' . $addon_id . '_description' ) ];

				if ( ! empty( $product_addon['options'] ) ) {

					foreach ( $product_addon['options'] as $option_id => $option ) {
						$product_addons[ $addon_id ]['options'][ $option_id ]['label'] = $data[ md5( 'addon_' . $addon_id . '_option_' . $option_id . '_label' ) ];
					}
				}
			}
		}

		update_post_meta( $product_id, '_product_addons', $product_addons );
	}

	public function show_pointer_info() {

		$pointer_ui = new WCML_Pointer_UI(
			sprintf( __( 'You can translate the Group Name, Group Description and every Option Label of your product add-on on the %1$sWooCommerce product translation page%2$s', 'lafka-plugin' ), '<a href="' . admin_url( 'admin.php?page=wpml-wcml' ) . '">', '</a>' ),
			'',
			'product_addons_data>p'
		);

		$pointer_ui->show();
	}

	function replace_tm_editor_custom_fields_with_own_sections( $fields ) {
		$fields[] = '_product_addons';

		return $fields;
	}

	// special case for WC Bookings plugin - need add addon cost after re-calculating booking costs #wcml-1877
	public function filter_booking_addon_product_in_cart_contents( $cart_item ) {

		$is_booking_product_with_addons = $cart_item['data'] instanceof WC_Product_Booking && isset( $cart_item['addons'] );

		if ( $this->is_multi_currency_on() && $is_booking_product_with_addons ) {
			$cost = $cart_item['data']->get_price();

			foreach ( $cart_item['addons'] as $addon ) {
				$cost += $addon['price'];
			}

			$cart_item['data']->set_price( $cost );
		}

		return $cart_item;
	}

	public function set_global_ids_in_query_args( $args ) {

		if ( ! is_archive() ) {

			remove_filter( 'get_terms_args', array( $this->sitepress, 'get_terms_args_filter' ), 10, 2 );
			remove_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1 );
			remove_filter( 'terms_clauses', array( $this->sitepress, 'terms_clauses' ), 10 );

			$matched_addons_ids = wp_list_pluck( get_posts( $args ), 'ID' );

			if ( $matched_addons_ids ) {
				$args['include'] = $matched_addons_ids;
				unset( $args['tax_query'] );
			}

			add_filter( 'get_terms_args', array( $this->sitepress, 'get_terms_args_filter' ), 10, 2 );
			add_filter( 'get_term', array( $this->sitepress, 'get_term_adjust_id' ), 1 );
			add_filter( 'terms_clauses', array( $this->sitepress, 'terms_clauses' ), 10, 3 );
		}

		return $args;
	}

	/**
	 * @return bool
	 */
	private function is_multi_currency_on() {
		return $this->multi_currency_mode === $this->sitepress->get_wp_api()->constant( 'WCML_MULTI_CURRENCIES_INDEPENDENT' );
	}





	/**
	 * @return array
	 */
	private function get_one_price_types() {

		return array(
			'custom_text',
			'textarea',
			'file_upload',
			'input_multiplier',
		);
	}




	public function load_assets() {
		global $pagenow;

		$is_product_page     = 'post.php' === $pagenow && isset( $_GET['post'] );
		$is_product_new_page = 'post-new.php' === $pagenow && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'];
		if ( $is_product_page || $is_product_new_page ) {
			wp_enqueue_script( 'wcml-product-addons', WCML_PLUGIN_URL . '/compatibility/res/js/wcml-product-addons' . WCML_JS_MIN . '.js', array( 'jquery' ), WCML_VERSION );
			wp_enqueue_style( 'wcml-product-addons', WCML_PLUGIN_URL . '/compatibility/res/css/wcml-product-addons.css', '', WCML_VERSION );
		}
	}

	/**
	 * @param string $product_id
	 */
	public function update_custom_prices_values( $product_id ) {

		if ( $this->is_multi_currency_on() ) {
			$this->save_global_addon_prices_setting( $product_id );
			$product_addons = $this->get_product_addons( $product_id );

			if ( $product_addons ) {
				$active_currencies = $this->woocommerce_wpml->multi_currency->get_currencies();

				foreach ( $product_addons as $addon_key => $product_addon ) {

					foreach ( $active_currencies as $code => $currency ) {
						$price_option_key = self::PRICE_OPTION_KEY;

						if ( in_array( $product_addon['type'], $this->get_one_price_types(), true ) ) {
							$product_addons = $this->update_single_option_prices( $product_addons, $price_option_key, $addon_key, $code );
						} else {
							$product_addons = $this->update_multiple_options_prices( $product_addons, $price_option_key, $addon_key, $code );
						}
					}
				}

				update_post_meta( $product_id, '_product_addons', $product_addons );
			}
		}
	}

	/**
	 * @param array $product_addons
	 * @param string $price_option_key
	 * @param string $addon_key
	 * @param string $code
	 *
	 * @return array
	 */
	private function update_single_option_prices( $product_addons, $price_option_key, $addon_key, $code ) {
		if ( isset( $_POST[ $price_option_key ][ $addon_key ][ 'price_' . $code ][0] ) ) {
			$product_addons[ $addon_key ][ 'price_' . $code ] = wc_format_decimal( $_POST[ $price_option_key ][ $addon_key ][ 'price_' . $code ][0] );
		}

		return $product_addons;
	}

	/**
	 * @param array $product_addons
	 * @param string $price_option_key
	 * @param string $addon_key
	 * @param string $code
	 *
	 * @return array
	 */
	private function update_multiple_options_prices( $product_addons, $price_option_key, $addon_key, $code ) {
		foreach ( $product_addons[ $addon_key ]['options'] as $option_key => $option ) {
			if ( isset( $_POST[ $price_option_key ][ $addon_key ][ 'price_' . $code ][ $option_key ] ) ) {
				$product_addons[ $addon_key ]['options'][ $option_key ][ 'price_' . $code ] = wc_format_decimal( $_POST[ $price_option_key ][ $addon_key ][ 'price_' . $code ][ $option_key ] );
			}
		}

		return $product_addons;
	}




	/**
	 * @param int $global_addon_id
	 */
	private function save_global_addon_prices_setting( $global_addon_id ) {

		$nonce = filter_var( isset( $_POST['_wcml_custom_prices_nonce'] ) ? $_POST['_wcml_custom_prices_nonce'] : '', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( isset( $_POST['_wcml_custom_prices'] ) && isset( $nonce ) && wp_verify_nonce( $nonce, 'wcml_save_custom_prices' ) ) {
			update_post_meta( $global_addon_id, '_wcml_custom_prices_status', $_POST['_wcml_custom_prices'] );
		}
	}
}
// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
