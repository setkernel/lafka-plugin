<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Combos Admin Class.
 *
 * Loads admin scripts, includes admin classes and adds admin hooks.
 *
 * @class    WC_LafkaCombos_Admin
 * @version  6.3.0
 */
class WC_LafkaCombos_Admin {

	/**
	 * Combined selectSW library version.
	 *
	 * @var string
	 */
	private static $combined_selectsw_version = '1.1.3';

	/**
	 * Setup Admin class.
	 */
	public static function init() {

		// Admin includes.
		add_action( 'init', array( __CLASS__, 'admin_init' ) );

		// Add a message in the WP Privacy Policy Guide page.
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_guide_content' ) );

		// selectSW scripts.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_register_selectsw' ), 0 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_load_selectsw' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_display_selectsw_notice' ), 0 );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ), 11 );

		// Add template override scan path in tracking info.
		add_filter( 'woocommerce_template_overrides_scan_paths', array( __CLASS__, 'template_scan_path' ) );

		// Add "Insufficient Stock" report tab.
		add_filter( 'woocommerce_admin_reports', array( __CLASS__, 'add_insufficient_stock_report_tab' ) );
		add_action( 'admin_print_styles', array( __CLASS__, 'maybe_add_insufficient_stock_report_notice' ) );

		// Add body class for WP 5.3 compatibility.
		add_filter( 'admin_body_class', array( __CLASS__, 'include_admin_body_class' ) );
	}

	/**
	 * Admin init.
	 */
	public static function admin_init() {
		self::includes();
	}

	/**
	 * Inclusions.
	 */
	public static function includes() {

		// Product Import/Export.
		if ( WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.1' ) ) {
			require_once WC_LafkaCombos_ABSPATH . 'includes/admin/export/class-lafka-combos-product-export.php';
			require_once WC_LafkaCombos_ABSPATH . 'includes/admin/import/class-lafka-combos-product-import.php';
		}

		// Product Metaboxes.
		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/meta-boxes/class-lafka-combos-meta-box-product-data.php';

		// Post type stuff.
		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin-post-types.php';

		// Admin AJAX.
		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin-ajax.php';

		// Admin edit-order screen.
		if ( WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.2' ) ) {
			require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin-order.php';
		}
	}

	/**
	 * Register own version of select2 library.
	 *
	 * @since 5.1.0
	 */
	public static function maybe_register_selectsw() {

		$is_registered      = wp_script_is( 'sw-admin-select-init', $list = 'registered' );
		$registered_version = $is_registered ? wp_scripts()->registered['sw-admin-select-init']->ver : '';
		$register           = ! $is_registered || version_compare( self::$combined_selectsw_version, $registered_version, '>' );

		if ( $register ) {

			if ( $is_registered ) {
				wp_deregister_script( 'sw-admin-select-init' );
			}

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			// Register own select2 initialization library.
			wp_register_script( 'sw-admin-select-init', WC_LafkaCombos()->plugin_url() . '/assets/js/admin/select2-init' . $suffix . '.js', array( 'jquery', 'sw-admin-select' ), self::$combined_selectsw_version );
		}
	}

	/**
	 * Load own version of select2 library.
	 *
	 * @since 5.1.0
	 */
	public static function maybe_load_selectsw() {

		// Responsible for loading selectsw?
		if ( self::load_selectsw() ) {

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			// Register selectSW library.
			wp_register_script( 'sw-admin-select', WC_LafkaCombos()->plugin_url() . '/assets/js/admin/select2' . $suffix . '.js', array( 'jquery' ), self::$combined_selectsw_version );

			// Register selectSW styles.
			wp_register_style( 'sw-admin-css-select', WC_LafkaCombos()->plugin_url() . '/assets/css/admin/select2.css', array(), self::$combined_selectsw_version );
			wp_style_add_data( 'sw-admin-css-select', 'rtl', 'replace' );
		}
	}

	/**
	 * Display notice when selectSW library is unsupported.
	 *
	 * @since 5.1.0
	 */
	public static function maybe_display_selectsw_notice() {

		if ( ! wp_scripts()->query( 'sw-admin-select-init' ) ) {
			return;
		}

		$registered_version       = wp_scripts()->registered['sw-admin-select-init']->ver;
		$registered_version_major = strstr( $registered_version, '.', true );
		$combined_version_major   = strstr( self::$combined_selectsw_version, '.', true );

		if ( version_compare( $combined_version_major, $registered_version_major, '<' ) ) {
			$notice = __( 'The installed version of <strong>Product Combos</strong> is not compatible with the <code>selectSW</code> library found on your system. Please update Product Combos to the latest version.', 'lafka-plugin' );
			WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'error' );
		}
	}

	/**
	 * Whether to load own version of select2 library or not.
	 *
	 * @since   5.1.0
	 *
	 * @return  boolean
	 */
	private static function load_selectsw() {

		$load_selectsw_from = wp_scripts()->registered['sw-admin-select-init']->src;

		return strpos( $load_selectsw_from, WC_LafkaCombos()->plugin_url() ) === 0;
	}

	/**
	 * Message to add in the WP Privacy Policy Guide page.
	 *
	 * @since  5.7.10
	 *
	 * @return string
	 */
	protected static function get_privacy_policy_guide_message() {

		$content = '
			<div contenteditable="false">' .
				'<p class="wp-policy-help">' .
					__( 'Product Combos does not collect, store or share any personal data.', 'lafka-plugin' ) .
				'</p>' .
			'</div>';

		return $content;
	}

	/**
	 * Include admin classes.
	 *
	 * @since  5.13.3
	 *
	 * @param  String  $classes
	 * @return String
	 */
	public static function include_admin_body_class( $classes ) {

		if ( strpos( $classes, 'sw-wp-version-gte-53' ) !== false ) {
			return $classes;
		}

		if ( WC_LafkaCombos_Core_Compatibility::is_wp_version_gte( '5.3' ) ) {
			$classes .= ' sw-wp-version-gte-53';
		}

		return $classes;
	}

	/**
	 * Add a message in the WP Privacy Policy Guide page.
	 *
	 * @since  5.7.10
	 */
	public static function add_privacy_policy_guide_content() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'WooCommerce Product Combos', self::get_privacy_policy_guide_message() );
		}
	}

	/**
	 * Admin writepanel scripts.
	 */
	public static function admin_scripts() {

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'wc-pb-admin-product-panel', WC_LafkaCombos()->plugin_url() . '/assets/js/admin/meta-boxes-product' . $suffix . '.js', array( 'wc-admin-product-meta-boxes', 'sw-admin-select-init' ), WC_LafkaCombos()->version );
		wp_register_script( 'wc-pb-admin-order-panel', WC_LafkaCombos()->plugin_url() . '/assets/js/admin/meta-boxes-order' . $suffix . '.js', array( 'wc-admin-order-meta-boxes' ), WC_LafkaCombos()->version );

		wp_register_style( 'wc-pb-admin-css', WC_LafkaCombos()->plugin_url() . '/assets/css/admin/admin.css', array(), WC_LafkaCombos()->version );
		wp_style_add_data( 'wc-pb-admin-css', 'rtl', 'replace' );

		wp_register_style( 'wc-pb-admin-product-css', WC_LafkaCombos()->plugin_url() . '/assets/css/admin/meta-boxes-product.css', array( 'woocommerce_admin_styles' ), WC_LafkaCombos()->version );
		wp_style_add_data( 'wc-pb-admin-product-css', 'rtl', 'replace' );

		wp_register_style( 'wc-pb-admin-edit-order-css', WC_LafkaCombos()->plugin_url() . '/assets/css/admin/meta-boxes-order.css', array( 'woocommerce_admin_styles' ), WC_LafkaCombos()->version );
		wp_style_add_data( 'wc-pb-admin-edit-order-css', 'rtl', 'replace' );

		wp_enqueue_style( 'wc-pb-admin-css' );

		// Get admin screen ID.
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		/*
		 * Enqueue styles.
		 */
		if ( in_array( $screen_id, array( 'edit-product', 'product' ) ) ) {
			wp_enqueue_style( 'wc-pb-admin-product-css', 'sw-admin-css-select' );
		} elseif ( in_array( $screen_id, array( 'shop_order', 'edit-shop_order', 'shop_subscription', 'edit-shop_subscription' ) ) ) {
			wp_enqueue_style( 'wc-pb-admin-edit-order-css' );
		}

		/*
		 * Enqueue scripts.
		 */
		if ( 'product' === $screen_id ) {

			wp_enqueue_script( 'wc-pb-admin-product-panel' );

			// Find group modes with a parent item.
			$group_mode_options      = WC_Product_Combo::get_group_mode_options();
			$group_modes_with_parent = array();

			foreach ( $group_mode_options as $group_mode_key => $group_mode_title ) {
				if ( WC_Product_Combo::group_mode_has( $group_mode_key, 'parent_item' ) || WC_Product_Combo::group_mode_has( $group_mode_key, 'faked_parent_item' ) ) {
					$group_modes_with_parent[] = $group_mode_key;
				}
			}

			$params = array(
				'add_combined_product_nonce' => wp_create_nonce( 'wc_combos_add_combined_product' ),
				'group_modes_with_parent'    => $group_modes_with_parent,
				'is_first_combo'             => isset( $_GET['wc_pb_first_combo'] ) ? 'yes' : 'no',
				'is_wc_version_gte_3_2'      => WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.2' ) ? 'yes' : 'no',
			);

			wp_localize_script( 'wc-pb-admin-product-panel', 'wc_combos_admin_params', $params );

		} elseif ( 'edit-product' === $screen_id ) {

			wc_enqueue_js(
				"
				jQuery( function( $ ) {
					jQuery( '.show_insufficient_stock_items' ).on( 'click', function() {
						var anchor = jQuery( this ),
							panel  = jQuery( this ).parent().find( '.insufficient_stock_items' );

						if ( anchor.hasClass( 'closed' ) ) {
							anchor.removeClass( 'closed' );
							panel.slideDown( 200 );
						} else {
							anchor.addClass( 'closed' );
							panel.slideUp( 200 );
						}
						return false;
					} );
				} );
			"
			);

		} elseif ( in_array( $screen_id, array( 'shop_order', 'shop_subscription' ) ) ) {

			wp_enqueue_script( 'wc-pb-admin-order-panel' );

			$params = array(
				'edit_combo_nonce'      => wp_create_nonce( 'wc_combos_edit_combo' ),
				'is_wc_version_gte_3_4' => WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.4' ) ? 'yes' : 'no',
				'is_wc_version_gte_3_6' => WC_LafkaCombos_Core_Compatibility::is_wc_version_gte( '3.6' ) ? 'yes' : 'no',
				'i18n_configure'        => __( 'Configure', 'lafka-plugin' ),
				'i18n_edit'             => __( 'Edit', 'lafka-plugin' ),
				'i18n_form_error'       => __( 'Failed to initialize form. If this issue persists, please reload the page and try again.', 'lafka-plugin' ),
				'i18n_validation_error' => __( 'Failed to validate configuration. If this issue persists, please reload the page and try again.', 'lafka-plugin' ),
			);

			wp_localize_script( 'wc-pb-admin-order-panel', 'wc_combos_admin_order_params', $params );
		}
	}

	/**
	 * Support scanning for template overrides in extension.
	 *
	 * @param  array  $paths
	 * @return array
	 */
	public static function template_scan_path( $paths ) {

		$paths['WooCommerce Product Combos'] = WC_LafkaCombos()->plugin_path() . '/templates/';

		return $paths;
	}

	/**
	 * Adds an "Insufficient stock" tab to the WC stock reports.
	 *
	 * @param  array  $reports
	 * @return array
	 */
	public static function add_insufficient_stock_report_tab( $reports ) {

		$reports['stock']['reports']['insufficient_stock'] = array(
			'title'       => __( 'Insufficient stock', 'lafka-plugin' ),
			'description' => '',
			'hide_title'  => true,
			'callback'    => array( __CLASS__, 'get_insufficient_stock_report_content' ),
		);

		return $reports;
	}

	/**
	 * Renders the "Insufficient stock" report content.
	 *
	 * @param  string  $name
	 * @return void
	 */
	public static function get_insufficient_stock_report_content( $name ) {

		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/reports/class-lafka-combos-report-insufficient-stock.php';

		$report = new WC_LafkaCombos_Report_Insufficient_Stock();
		$report->output_report();
	}

	/**
	 * Renders a notice in the "Insufficient stock" report page.
	 *
	 * @since  5.9.0
	 *
	 * @return void
	 */
	public static function maybe_add_insufficient_stock_report_notice() {

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( 'woocommerce_page_wc-reports' !== $screen_id ) {
			return;
		}

		if ( empty( $_GET['combo_id'] ) ) {
			return;
		}

		$combo  = wc_get_product( absint( $_GET['combo_id'] ) );
		$notice = sprintf( __( 'You are currently viewing a filtered version of this report for <strong>%1$s</strong>. <a href="%2$s" class="wc_pb_forward">Clear Filter</a>', 'lafka-plugin' ), $combo->get_title(), admin_url( 'admin.php?page=wc-reports&tab=stock&report=insufficient_stock' ) );
		WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'info' );
	}
}

WC_LafkaCombos_Admin::init();
