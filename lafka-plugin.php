<?php
/*
  Plugin Name: Lafka Plugin
  Plugin URI: https://github.com/setkernel/lafka-plugin
  Description: Companion plugin for the Lafka WooCommerce theme. Originally by theAlThemist, now community-maintained.
  Version: 8.3.4
  Author: theAlThemist, Contributors
  Author URI: https://github.com/setkernel/lafka-plugin
  WC requires at least: 8
  WC tested up to: 9
  License: GPL v2 or later
  License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_PLUGIN_FILE' ) ) {
	define( 'LAFKA_PLUGIN_FILE', __FILE__ );
}

if ( ! function_exists('lafka_write_log')) {
	function lafka_write_log ( $log )  {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}
}

// Check if WooCommerce is active (supports regular plugins and MU-plugins)
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )
	|| ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'WooCommerce' ) ) {
	define('LAFKA_PLUGIN_IS_WOOCOMMERCE', TRUE);
} else {
	define('LAFKA_PLUGIN_IS_WOOCOMMERCE', FALSE);
}

// Check if bbPress is active
if (class_exists('bbPress')) {
	define('LAFKA_PLUGIN_IS_BBPRESS', TRUE);
} else {
	define('LAFKA_PLUGIN_IS_BBPRESS', FALSE);
}

if ( in_array( 'revslider/revslider.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )
	|| ( is_multisite() && array_key_exists( 'revslider/revslider.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'RevSliderBase' ) ) {
	define('LAFKA_PLUGIN_IS_REVOLUTION', TRUE);
} else {
	define('LAFKA_PLUGIN_IS_REVOLUTION', FALSE);
}

// Check if WC Marketplace is active
if ( in_array( 'dc-woocommerce-multi-vendor/dc_product_vendor.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) )
	|| ( is_multisite() && array_key_exists( 'dc-woocommerce-multi-vendor/dc_product_vendor.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'WCMp' ) ) {
	define('LAFKA_PLUGIN_IS_WC_MARKETPLACE', TRUE);
} else {
	define('LAFKA_PLUGIN_IS_WC_MARKETPLACE', FALSE);
}

// Check product_addons are enabled in Theme Options
function is_lafka_product_addons($lafka_options) {
	if ( isset( $lafka_options['product_addons'] ) && $lafka_options['product_addons'] === 'enabled' ) {
		return true;
	}
	return false;
}

// Check shipping_areas are enabled in Theme Options
function is_lafka_shipping_areas($lafka_options) {
	if ( isset( $lafka_options['shipping_areas'] ) && $lafka_options['shipping_areas'] === 'enabled' ) {
		return true;
	}
	return false;
}

// Check product-combos are enabled in Theme Options
function is_lafka_product_combos($lafka_options) {
	if ( isset( $lafka_options['product_combos'] ) && $lafka_options['product_combos'] === 'enabled' ) {
		return true;
	}
	return false;
}

// Check order_hours are enabled in Theme Options
function is_lafka_order_hours($lafka_options) {
	if ( isset( $lafka_options['order_hours'] ) && $lafka_options['order_hours'] === 'enabled' ) {
		return true;
	}
	return false;
}

// Check kitchen_display is enabled in Theme Options
function is_lafka_kitchen_display($lafka_options) {
	if ( isset( $lafka_options['kitchen_display'] ) && $lafka_options['kitchen_display'] === 'enabled' ) {
		return true;
	}
	return false;
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

if ( LAFKA_PLUGIN_IS_WOOCOMMERCE ) {

	/* Load nutrition and allergens */
	require_once(plugin_dir_path( __FILE__ ) . '/incl/nutrition/lafka-nutrition.php');

	if( is_lafka_product_addons( get_option( 'lafka' )) ) {
		/* Load addons */
		require_once( plugin_dir_path( __FILE__ ) . '/incl/addons/lafka-product-addons.php' );
	}

	if( is_lafka_product_combos( get_option( 'lafka' )) ) {
		/* Load combos */
		require_once( plugin_dir_path( __FILE__ ) . '/incl/combos/lafka-product-combos.php' );
	}

	if( is_lafka_shipping_areas( get_option( 'lafka' ) ) ) {
		require_once( plugin_dir_path( __FILE__ ) . '/incl/shipping-areas/class-lafka-shipping-areas.php' );
	}

	if( is_lafka_order_hours( get_option( 'lafka' )) ) {
		/* Load order_hours */
		require_once( plugin_dir_path( __FILE__ ) . '/incl/order-hours/Lafka_Order_Hours.php' );
	}

	if( is_lafka_kitchen_display( get_option( 'lafka' )) ) {
		/* Load kitchen display */
		require_once( plugin_dir_path( __FILE__ ) . '/incl/kitchen-display/class-lafka-kitchen-display.php' );
	}
}

add_action('plugins_loaded', 'lafka_plugin_after_plugins_loaded' );
add_action( 'plugins_loaded', 'lafka_wc_variation_swatches_constructor' );

function lafka_plugin_after_plugins_loaded() {
	// Load Nutrition Config - it may be needed also for menu entries
	require_once( plugin_dir_path(__FILE__) . '/incl/nutrition/includes/class-lafka-nutrition-config.php' );

	/* independent widgets */
	foreach (array('LafkaAboutWidget', 'LafkaContactsWidget', 'LafkaPaymentOptionsWidget', 'LafkaPopularPostsWidget',
		'LafkaLatestMenuEntriesWidget'
	) as $file) {
		require_once( plugin_dir_path(__FILE__) . 'widgets/' . $file . '.php' );
	}

	if(LAFKA_PLUGIN_IS_WOOCOMMERCE) {
		/* WooCommerce dependent widgets */
		foreach ( array( 'LafkaProductFilterWidget' ) as $file ) {
			require_once( plugin_dir_path( __FILE__ ) . 'widgets/wc_widgets/' . $file . '.php' );
		}

		require_once(plugin_dir_path( __FILE__ ) . '/incl/woocommerce-metaboxes.php');
		require_once(plugin_dir_path( __FILE__ ) . '/incl/woocommerce-functions.php');

		// subcategories after 3.3.1 - will need refactoring in future
		remove_filter( 'woocommerce_product_loop_start', 'woocommerce_maybe_show_product_subcategories' );

        // Check if WPML and WooCommerce Multilingual are active
		if (class_exists('SitePress') && class_exists('woocommerce_wpml')) {
			define('LAFKA_PLUGIN_IS_WPML_WCML', TRUE);
		} else {
			define('LAFKA_PLUGIN_IS_WPML_WCML', FALSE);
		}

		global $sitepress;
		global $woocommerce_wpml;
		if ( LAFKA_PLUGIN_IS_WPML_WCML && is_lafka_product_addons( get_option( 'lafka' ) ) && ! empty( $sitepress ) && ! empty( $woocommerce_wpml ) ) {
			require_once( plugin_dir_path( __FILE__ ) . '/incl/wpml/addons/class-wcml-lafka-product-addons.php' );
			$lafka_product_addons = new WCML_Lafka_Product_Addons( $sitepress, $woocommerce_wpml );
			$lafka_product_addons->add_hooks();
		}

		// Suspend account notices on the cart page, because cart notices got taken by the account form in header
		add_action( 'wp', 'lafka_suspend_account_notice' );
		if ( ! function_exists( 'lafka_suspend_account_notice' ) ) {
			function lafka_suspend_account_notice() {
				if ( is_cart() ) {
					remove_action( 'woocommerce_before_customer_login_form', 'woocommerce_output_all_notices', 10 );
				}
			}
		}
	}

	/* shortcodes */
	require_once( plugin_dir_path(__FILE__) . 'shortcodes/shortcodes.php' );

	/* Map all Lafka shortcodes to VC */
	add_action('vc_before_init', 'lafka_integrateWithVC');
	require_once( plugin_dir_path(__FILE__) . 'shortcodes/shortcodes_to_vc_mapping.php' );

	/* Load variation product swatches */
	require_once( plugin_dir_path(__FILE__) . 'incl/swatches/variation-swatches.php' );

	/* include metaboxes.php */
	require_once( plugin_dir_path(__FILE__) . '/incl/metaboxes.php');

	/* Load foodmenu_category ordering in admin */
	require_once( plugin_dir_path(__FILE__) . '/incl/foodmenu-category-ordering.php');

	/* include customizer class */
	require_once( plugin_dir_path(__FILE__) . '/incl/customizer/class-lafka-customizer.php');

    // Removed because causes categories to appear twice in shop and category view.
    // Functionality not lost, because "woocommerce_maybe_show_product_subcategories" is called
	remove_filter( 'woocommerce_product_loop_start', 'woocommerce_maybe_show_product_subcategories' );
}

add_action('init', 'lafka_load_plugin_text_domain');
if(!function_exists('lafka_load_plugin_text_domain')) {
	function lafka_load_plugin_text_domain() {
		load_plugin_textdomain('lafka-plugin', FALSE, dirname(plugin_basename(__FILE__)) . '/languages/');
	}
}

// Fix bbpress  Notice: bp_setup_current_user was called incorrectly
if (class_exists( 'bbPress' )) {
	remove_action('set_current_user', 'bbp_setup_current_user', 10);
	add_action('set_current_user', 'lafka_bbp_setup_current_user', 10);
}

if (!function_exists('lafka_bbp_setup_current_user')) {

	function lafka_bbp_setup_current_user() {
		do_action('bbp_setup_current_user');
	}

}

if (!function_exists('get_plugin_data')) {
	include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

if (!defined('LAFKA_PLUGIN_IMAGES_PATH')) {
	define('LAFKA_PLUGIN_IMAGES_PATH', plugins_url('/assets/image/', plugin_basename(__FILE__)));
}

/**
 * Generate excerpt by post Id
 *
 * @param type $post_id
 * @param type $excerpt_length
 * @param type $dots_to_link
 * @return string
 */
if (!function_exists('lafka_get_excerpt_by_id')) {

	function lafka_get_excerpt_by_id($post_id, $excerpt_length = 35, $dots_to_link = false) {

		$the_post = get_post($post_id);
		if ( ! $the_post ) {
			return '';
		}
		$the_excerpt = wp_strip_all_tags( $the_post->post_excerpt );
		$the_excerpt = '<p>' . esc_html( $the_excerpt ) . '</p>';

		return $the_excerpt;
	}

}

/**
 * Define Foodmenu custom post type
 * 'lafka-foodmenu'
 */
if (!function_exists('lafka_register_cpt_lafka_foodmenu')) {
	add_action('init', 'lafka_register_cpt_lafka_foodmenu', 5);

	function lafka_register_cpt_lafka_foodmenu() {

		$labels = array(
				'name' => esc_html__('Restaurant Menu', 'lafka-plugin'),
				'singular_name' => esc_html__('Menu Entry', 'lafka-plugin'),
				'add_new' => esc_html__('Add New Menu Entry', 'lafka-plugin'),
				'add_new_item' => esc_html__('Add New Menu Entry', 'lafka-plugin'),
				'edit_item' => esc_html__('Edit Restaurant Menu Entry', 'lafka-plugin'),
				'new_item' => esc_html__('New Menu Entry', 'lafka-plugin'),
				'view_item' => esc_html__('View Menu Entry', 'lafka-plugin'),
				'search_items' => esc_html__('Search Menu Entries', 'lafka-plugin'),
				'not_found' => esc_html__('No Menu Entries Found', 'lafka-plugin'),
				'not_found_in_trash' => esc_html__('No Menu Entries found in Trash', 'lafka-plugin'),
				'parent_item_colon' => esc_html__('Parent Menu Entry:', 'lafka-plugin'),
				'menu_name' => esc_html__('Restaurant Menu', 'lafka-plugin'),
		);

		$args = array(
				'labels' => $labels,
				'hierarchical' => false,
				'description' => esc_html__( 'Lafka Restaurant Menu Post Type', 'lafka-plugin' ),
				'supports' => array('title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions'),
				'taxonomies' => array('lafka_foodmenu_category'),
				'public' => true,
				'show_ui' => true,
				'show_in_menu' => true,
				'show_in_nav_menus' => true,
				'publicly_queryable' => true,
				'exclude_from_search' => false,
				'has_archive' => true,
				'query_var' => true,
				'can_export' => true,
				'capability_type' => 'page',
				'menu_icon' => 'dashicons-list-view',
				'rewrite' => array(
						'slug' => esc_html__('restaurant-menu', 'lafka-plugin')
				)
		);

		register_post_type('lafka-foodmenu', $args);
	}

}

/**
 * Define lafka_foodmenu_category taxonomy
 * used by lafka-foodmenu post type
 */
if (!function_exists('lafka_register_taxonomy_lafka_foodmenu_category')) {
	add_action('init', 'lafka_register_taxonomy_lafka_foodmenu_category', 5);

	function lafka_register_taxonomy_lafka_foodmenu_category() {

		$labels = array(
				'name' => esc_html__('Menu Categories', 'lafka-plugin'),
				'singular_name' => esc_html__('Menu Category', 'lafka-plugin'),
				'search_items' => esc_html__('Search Menu Categories', 'lafka-plugin'),
				'popular_items' => esc_html__('Popular Menu Categories', 'lafka-plugin'),
				'all_items' => esc_html__('All Menu Categories', 'lafka-plugin'),
				'parent_item' => esc_html__('Parent Menu Category', 'lafka-plugin'),
				'parent_item_colon' => esc_html__('Parent Menu Category:', 'lafka-plugin'),
				'edit_item' => esc_html__('Edit Menu Category', 'lafka-plugin'),
				'update_item' => esc_html__('Update Menu Category', 'lafka-plugin'),
				'add_new_item' => esc_html__('Add New', 'lafka-plugin'),
				'new_item_name' => esc_html__('New Menu Category', 'lafka-plugin'),
				'separate_items_with_commas' => esc_html__('Separate Menu Categories with commas', 'lafka-plugin'),
				'add_or_remove_items' => esc_html__('Add or remove Menu Category', 'lafka-plugin'),
				'choose_from_most_used' => esc_html__('Choose from the most used Menu Categories', 'lafka-plugin'),
				'menu_name' => esc_html__('Menu Categories', 'lafka-plugin'),
		);

		$args = array(
				'labels' => $labels,
				'public' => true,
				'show_in_nav_menus' => true,
				'show_ui' => true,
				'show_tagcloud' => true,
				'show_admin_column' => false,
				'hierarchical' => true,
				'query_var' => true,
				'rewrite' => array(
						'slug' => 'restaurant-menu-category'
				)
		);

		register_taxonomy('lafka_foodmenu_category', array('lafka-foodmenu'), $args);
	}

}

add_action('init', 'lafka_theme_options_link');
if(!function_exists('lafka_theme_options_link')) {
	function lafka_theme_options_link() {
		if ( wp_get_theme()->get_template() === 'lafka' && current_user_can( 'edit_theme_options' ) ) {
			add_action( 'wp_before_admin_bar_render', 'lafka_optionsframework_adminbar' );
		}
	}
}

/**
 * Add Theme Options menu item to Admin Bar.
 */
if(!function_exists('lafka_optionsframework_adminbar')) {
	function lafka_optionsframework_adminbar() {

		global $wp_admin_bar;

		if ( ! $wp_admin_bar ) {
			return;
		}

		$wp_admin_bar->add_menu( array(
			'parent' => false,
			'id'     => 'lafka_of_theme_options',
			'title'  => esc_html__( 'Theme Options', 'lafka-plugin' ),
			'href'   => esc_url( admin_url( 'themes.php?page=lafka-optionsframework' ) ),
			'meta'   => array( 'class' => 'althemist-admin-opitons' )
		) );
	}
}

// Register scripts
add_action('wp_enqueue_scripts', 'lafka_register_plugin_scripts');
if (!function_exists('lafka_register_plugin_scripts')) {

	function lafka_register_plugin_scripts() {

		// flexslider
		wp_enqueue_script('flexslider', get_template_directory_uri() . "/js/flex/jquery.flexslider-min.js", array('jquery'), '2.2.2', true);
		wp_enqueue_style('flexslider', get_template_directory_uri() . "/styles/flex/flexslider.css", array(), '2.2.2');

		// owl-carousel
		wp_enqueue_script('owl-carousel', get_template_directory_uri() . "/js/owl-carousel2-dist/owl.carousel.min.js", array('jquery'), '2.3.4', true);
		wp_enqueue_style('owl-carousel', get_template_directory_uri() . "/styles/owl-carousel2-dist/assets/owl.carousel.min.css", array(), '2.3.4');
		wp_enqueue_style('owl-carousel-theme-default', get_template_directory_uri() . "/styles/owl-carousel2-dist/assets/owl.theme.default.min.css", array(), '2.3.4');
		wp_enqueue_style('owl-carousel-animate', get_template_directory_uri() . "/styles/owl-carousel2-dist/assets/animate.css", array(), '2.3.4');

		// cloud-zoom
		wp_enqueue_script('cloud-zoom', get_template_directory_uri() . "/js/cloud-zoom/cloud-zoom.1.0.2.min.js", array('jquery'), '1.0.2', true);
		wp_enqueue_style('cloud-zoom', get_template_directory_uri() . "/styles/cloud-zoom/cloud-zoom.css", array(), '1.0.2');

		// countdown
		wp_register_script('jquery-plugin', get_template_directory_uri() . "/js/count/jquery.plugin.min.js", array('jquery'), '2.1.0', true);
		wp_enqueue_script('countdown', get_template_directory_uri() . "/js/count/jquery.countdown.min.js", array('jquery', 'jquery-plugin'), '2.1.0', true);

		// Flatpickr
		wp_register_script( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.js', __FILE__ ), array( 'jquery' ), '4.6.13', true );
		$flatpickr_locale = apply_filters( 'lafka_flatpickr_locale', strtok( get_locale(), '_' ), get_locale() );
		if ( file_exists( untrailingslashit( plugin_dir_path( LAFKA_PLUGIN_FILE ) . "assets/js/flatpickr/l10n/$flatpickr_locale.js" ) ) ) {
			wp_register_script( 'flatpickr-local', plugins_url( "assets/js/flatpickr/l10n/$flatpickr_locale.js", __FILE__ ), array( 'flatpickr' ), '4.6.13', true );
		} else if ( file_exists( untrailingslashit( get_stylesheet_directory() . "/lafka_plugin_templates/flatpickr_l10n/$flatpickr_locale.js" ) ) ) {
			wp_register_script( 'flatpickr-local', get_stylesheet_directory_uri() . "/lafka_plugin_templates/flatpickr_l10n/$flatpickr_locale.js", array( 'flatpickr' ), '4.6.13', true );
		}

		wp_register_style( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.css', __FILE__ ), array(), '4.6.13' );

		// magnific
		wp_enqueue_script('magnific', get_template_directory_uri() . "/js/magnific/jquery.magnific-popup.min.js", array('jquery'), '1.0.0', true);
		wp_enqueue_style('magnific', get_template_directory_uri() . "/styles/magnific/magnific-popup.css", array(), '1.0.2');

		// appear
		wp_enqueue_script('appear', get_template_directory_uri() . "/js/jquery.appear.min.js", array('jquery'), '1.0.0', true);

		// appear
		wp_enqueue_script('typed', get_template_directory_uri() . "/js/typed.min.js", array(), '2.0.16', true);

		// nice-select
		wp_enqueue_script('nice-select', get_template_directory_uri() . "/js/jquery.nice-select.min.js", array('jquery'), '1.0.0', true);

		// is-in-viewport
		wp_enqueue_script('is-in-viewport', get_template_directory_uri() . "/js/isInViewport.min.js", array('jquery'), '1.0.0', true);

		// Isotope
		wp_register_script('isotope', get_template_directory_uri() . "/js/isotope/dist/isotope.pkgd.min.js", array('jquery', 'imagesloaded'), false, true);
		// google maps
        if(function_exists('lafka_get_option')) {
	        wp_register_script( 'lafka-google-maps', 'https://maps.googleapis.com/maps/api/js?' . ( lafka_get_option( 'google_maps_api_key' ) ? 'key=' . lafka_get_option( 'google_maps_api_key' ) . '&' : '' ) . 'libraries=geometry,places&v=weekly&language=' . get_locale() . '&callback=Function.prototype', array( 'jquery' ), false, true );
        }
	}

}

// Register scripts
add_action('admin_enqueue_scripts', 'lafka_register_admin_plugin_scripts');
if (!function_exists('lafka_register_admin_plugin_scripts')) {
	function lafka_register_admin_plugin_scripts() {
		// Flatpickr
		wp_register_script( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.js', __FILE__ ), array( 'jquery' ), '4.6.13', true );
		wp_register_style( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.css', __FILE__ ), array(), '4.6.13' );

		// Schedule
		wp_register_script( 'lafka-schedule', plugins_url( 'assets/js/schedule/jquery.schedule.min.js', __FILE__ ), array(
			'jquery-ui-core',
			'jquery-ui-draggable',
			'jquery-ui-resizable'
		), '2.1.0', true );
		wp_register_style( 'lafka-schedule', plugins_url( 'assets/css/schedule/jquery.schedule.min.css', __FILE__ ), array(), '2.1.0' );

		// ajax upload files
		wp_enqueue_script( 'plupload' );
		wp_enqueue_script( 'lafka-plugin-admin', plugins_url( 'assets/js/lafka-plugin-admin.js', __FILE__ ), array( 'plupload' ), false, true );
		wp_localize_script('lafka-plugin-admin', 'localise', array(
			'confirm_import_1' => esc_html__('Confirm importing settings from', 'lafka-plugin'),
			'confirm_import_2' => esc_html__('. Current Theme Options will be overwritten. Continue?', 'lafka-plugin'),
			'import_success' => esc_html__('Options successfully imported. Reloading.', 'lafka-plugin'),
			'upload_error' => esc_html__('There was a problem with the upload. Error', 'lafka-plugin'),
			'export_url' => esc_url( add_query_arg( 'action', 'lafka_options_export', admin_url( 'admin-post.php' ) ) ),
			'options_upload_nonce' => wp_create_nonce( 'lafka_options_upload_nonce' )
		));

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		if ( strstr( $screen_id, 'lafka_foodmenu_category' ) && ! empty( $_GET['taxonomy'] ) && in_array( wp_unslash( $_GET['taxonomy'] ), array( 'lafka_foodmenu_category' ) ) ) {
			wp_register_script( 'lafka-plugin-term-ordering', plugins_url( 'assets/js/lafka-plugin-foodmenu-cat-ordering.js', __FILE__ ), array( 'jquery-ui-sortable' ) );
			wp_enqueue_script( 'lafka-plugin-term-ordering' );
			wp_localize_script( 'lafka-plugin-term-ordering', 'lafka_cat_ordering', array(
				'nonce' => wp_create_nonce( 'lafka-foodmenu-cat-ordering' ),
			) );
			wp_enqueue_style('lafka-plugin-term-ordering-style', plugins_url('assets/css/lafka-plugin-term-ordering.css', __FILE__));
		}
		// google maps
		if(function_exists('lafka_get_option')) {
			wp_register_script( 'lafka-google-maps', 'https://maps.googleapis.com/maps/api/js?' . ( lafka_get_option( 'google_maps_api_key' ) ? 'key=' . lafka_get_option( 'google_maps_api_key' ) . '&' : '' ) . 'libraries=geometry&v=weekly&language=' . get_locale() . '&callback=Function.prototype', array( 'jquery' ), false, true );
		}
	}
}

// Enqueue the script for proper positioning the custom added font in vc edit form
add_filter('vc_edit_form_enqueue_script', 'lafka_enqueue_edit_form_scripts');
if (!function_exists('lafka_enqueue_edit_form_scripts')) {

	function lafka_enqueue_edit_form_scripts($scripts) {
		$scripts[] = plugin_dir_url(__FILE__) . 'assets/js/lafka-vc-edit-form.js';
		return $scripts;
	}

}

add_filter('vc_iconpicker-type-etline', 'lafka_vc_iconpicker_type_etline');

/**
 * Elegant Icons Font icons
 *
 * @param $icons - taken from filter - vc_map param field settings['source'] provided icons (default empty array).
 * If array categorized it will auto-enable category dropdown
 *
 * @since 4.4
 * @return array - of icons for iconpicker, can be categorized, or not.
 */
if (!function_exists('lafka_vc_iconpicker_type_etline')) {

	function lafka_vc_iconpicker_type_etline($icons) {
		// Categorized icons ( you can also output simple array ( key=> value ), where key = icon class, value = icon readable name ).
		$etline_icons = array(
				array('icon-mobile' => 'Mobile'),
				array('icon-laptop' => 'Laptop'),
				array('icon-desktop' => 'Desktop'),
				array('icon-tablet' => 'Tablet'),
				array('icon-phone' => 'Phone'),
				array('icon-document' => 'Document'),
				array('icon-documents' => 'Documents'),
				array('icon-search' => 'Search'),
				array('icon-clipboard' => 'Clipboard'),
				array('icon-newspaper' => 'Newspaper'),
				array('icon-notebook' => 'Notebook'),
				array('icon-book-open' => 'Open'),
				array('icon-browser' => 'Browser'),
				array('icon-calendar' => 'Calendar'),
				array('icon-presentation' => 'Presentation'),
				array('icon-picture' => 'Picture'),
				array('icon-pictures' => 'Pictures'),
				array('icon-video' => 'Video'),
				array('icon-camera' => 'Camera'),
				array('icon-printer' => 'Printer'),
				array('icon-toolbox' => 'Toolbox'),
				array('icon-briefcase' => 'Briefcase'),
				array('icon-wallet' => 'Wallet'),
				array('icon-gift' => 'Gift'),
				array('icon-bargraph' => 'Bargraph'),
				array('icon-grid' => 'Grid'),
				array('icon-expand' => 'Expand'),
				array('icon-focus' => 'Focus'),
				array('icon-edit' => 'Edit'),
				array('icon-adjustments' => 'Adjustments'),
				array('icon-ribbon' => 'Ribbon'),
				array('icon-hourglass' => 'Hourglass'),
				array('icon-lock' => 'Lock'),
				array('icon-megaphone' => 'Megaphone'),
				array('icon-shield' => 'Shield'),
				array('icon-trophy' => 'Trophy'),
				array('icon-flag' => 'Flag'),
				array('icon-map' => 'Map'),
				array('icon-puzzle' => 'Puzzle'),
				array('icon-basket' => 'Basket'),
				array('icon-envelope' => 'Envelope'),
				array('icon-streetsign' => 'Streetsign'),
				array('icon-telescope' => 'Telescope'),
				array('icon-gears' => 'Gears'),
				array('icon-key' => 'Key'),
				array('icon-paperclip' => 'Paperclip'),
				array('icon-attachment' => 'Attachment'),
				array('icon-pricetags' => 'Pricetags'),
				array('icon-lightbulb' => 'Lightbulb'),
				array('icon-layers' => 'Layers'),
				array('icon-pencil' => 'Pencil'),
				array('icon-tools' => 'Tools'),
				array('icon-tools-2' => '2'),
				array('icon-scissors' => 'Scissors'),
				array('icon-paintbrush' => 'Paintbrush'),
				array('icon-magnifying-glass' => 'Glass'),
				array('icon-circle-compass' => 'Compass'),
				array('icon-linegraph' => 'Linegraph'),
				array('icon-mic' => 'Mic'),
				array('icon-strategy' => 'Strategy'),
				array('icon-beaker' => 'Beaker'),
				array('icon-caution' => 'Caution'),
				array('icon-recycle' => 'Recycle'),
				array('icon-anchor' => 'Anchor'),
				array('icon-profile-male' => 'Male'),
				array('icon-profile-female' => 'Female'),
				array('icon-bike' => 'Bike'),
				array('icon-wine' => 'Wine'),
				array('icon-hotairballoon' => 'Hotairballoon'),
				array('icon-globe' => 'Globe'),
				array('icon-genius' => 'Genius'),
				array('icon-map-pin' => 'Pin'),
				array('icon-dial' => 'Dial'),
				array('icon-chat' => 'Chat'),
				array('icon-heart' => 'Heart'),
				array('icon-cloud' => 'Cloud'),
				array('icon-upload' => 'Upload'),
				array('icon-download' => 'Download'),
				array('icon-target' => 'Target'),
				array('icon-hazardous' => 'Hazardous'),
				array('icon-piechart' => 'Piechart'),
				array('icon-speedometer' => 'Speedometer'),
				array('icon-global' => 'Global'),
				array('icon-compass' => 'Compass'),
				array('icon-lifesaver' => 'Lifesaver'),
				array('icon-clock' => 'Clock'),
				array('icon-aperture' => 'Aperture'),
				array('icon-quote' => 'Quote'),
				array('icon-scope' => 'Scope'),
				array('icon-alarmclock' => 'Alarmclock'),
				array('icon-refresh' => 'Refresh'),
				array('icon-happy' => 'Happy'),
				array('icon-sad' => 'Sad'),
				array('icon-facebook' => 'Facebook'),
				array('icon-twitter' => 'Twitter'),
				array('icon-googleplus' => 'Googleplus'),
				array('icon-rss' => 'Rss'),
				array('icon-tumblr' => 'Tumblr'),
				array('icon-linkedin' => 'Linkedin'),
				array('icon-dribbble' => 'Dribbble'),
		);

		return array_merge($icons, $etline_icons);
	}

}

add_filter('vc_iconpicker-type-flaticon', 'lafka_vc_iconpicker_type_flaticon');

/**
 * Flaticon Icons Font icons
 *
 * @param $icons - taken from filter - vc_map param field settings['source'] provided icons (default empty array).
 * If array categorized it will auto-enable category dropdown
 *
 * @since 4.4
 * @return array - of icons for iconpicker, can be categorized, or not.
 */
if (!function_exists('lafka_vc_iconpicker_type_flaticon')) {

	function lafka_vc_iconpicker_type_flaticon($icons) {
		// Categorized icons ( you can also output simple array ( key=> value ), where key = icon class, value = icon readable name ).
		$flaticon_icons = array(
			array('flaticon-001-popcorn' => 'popcorn'),
			array('flaticon-002-tea' => 'tea'),
			array('flaticon-003-chinese-food' => 'chinese food'),
			array('flaticon-004-tomato-sauce' => 'tomato sauce'),
			array('flaticon-005-cola-1' => 'cola 1'),
			array('flaticon-006-burger-2' => 'burger 2'),
			array('flaticon-007-burger-1' => 'burger 1'),
			array('flaticon-008-fried-potatoes' => 'fried potatoes'),
			array('flaticon-009-coffee' => 'coffee'),
			array('flaticon-010-burger' => 'burger'),
			array('flaticon-011-ice-cream-1' => 'ice cream 1'),
			array('flaticon-012-cola' => 'cola'),
			array('flaticon-013-milkshake' => 'milkshake'),
			array('flaticon-014-sauces' => 'sauces'),
			array('flaticon-015-hot-dog-1' => 'hotdog 1'),
			array('flaticon-016-chicken-leg-1' => 'chicken leg 1'),
			array('flaticon-017-croissant' => 'croissant'),
			array('flaticon-018-cheese' => 'cheese'),
			array('flaticon-019-sausage' => 'sausage'),
			array('flaticon-020-fried-egg' => 'fried egg'),
			array('flaticon-021-fried-chicken' => 'fried-chicken'),
			array('flaticon-022-serving-dish' => 'serving dish'),
			array('flaticon-023-pizza-slice' => 'pizza slice'),
			array('flaticon-024-chef-hat' => 'chef hat'),
			array('flaticon-025-meat' => 'meat'),
			array('flaticon-026-ice-cream' => 'ice cream'),
			array('flaticon-027-donut' => 'donut'),
			array('flaticon-028-rice' => 'rice'),
			array('flaticon-029-package' => 'package'),
			array('flaticon-030-kebab' => 'kebab'),
			array('flaticon-031-delivery' => 'delivery'),
			array('flaticon-032-food-truck' => 'food truck'),
			array('flaticon-033-waiter-1' => 'waiter 1'),
			array('flaticon-034-waiter' => 'waiter'),
			array('flaticon-035-taco' => 'taco'),
			array('flaticon-036-chips' => 'chips'),
			array('flaticon-037-soda' => 'soda'),
			array('flaticon-038-take-away' => 'take away'),
			array('flaticon-039-fork' => 'fork'),
			array('flaticon-040-coffee-cup' => 'coffee cup'),
			array('flaticon-041-waffle' => 'waffle'),
			array('flaticon-042-beer' => 'beer'),
			array('flaticon-043-chicken-leg' => 'chicken leg'),
			array('flaticon-044-pitcher' => 'pitcher'),
			array('flaticon-045-coffee-machine' => 'coffee machine'),
			array('flaticon-046-noodles' => 'noodles'),
			array('flaticon-047-menu' => 'menu'),
			array('flaticon-048-hot-dog' => 'hot-dog'),
			array('flaticon-049-breakfast' => 'breakfast'),
			array('flaticon-050-french-fries' => 'french fries'),
		);

		return array_merge($icons, $flaticon_icons);
	}

}

if (!function_exists('lafka_foodmenu_category_field_search')) {

	function lafka_foodmenu_category_field_search($search_string) {
		$data = array();

		$vc_taxonomies_types = array('lafka_foodmenu_category');
		$vc_taxonomies = get_terms($vc_taxonomies_types, array(
				'hide_empty' => false,
				'search' => $search_string,
		));
		if (is_array($vc_taxonomies) && !empty($vc_taxonomies)) {
			foreach ($vc_taxonomies as $t) {
				if (is_object($t)) {
					$data[] = vc_get_term_object($t);
				}
			}
		}

		return $data;
	}

}

if (!function_exists('lafka_latest_posts_category_field_search')) {

	function lafka_latest_posts_category_field_search($search_string) {
		$data = array();

		$vc_taxonomies_types = array('category');
		$vc_taxonomies = get_terms($vc_taxonomies_types, array(
				'hide_empty' => false,
				'search' => $search_string,
		));
		if (is_array($vc_taxonomies) && !empty($vc_taxonomies)) {
			foreach ($vc_taxonomies as $t) {
				if (is_object($t)) {
					$data[] = vc_get_term_object($t);
				}
			}
		}

		return $data;
	}

}
add_action('admin_init', 'lafka_load_incl_importer', 99);
if (!function_exists('lafka_load_incl_importer')) {

	function lafka_load_incl_importer() {
		/* load required files */

        // Load Importer API
		require_once ABSPATH . 'wp-admin/includes/import.php';

		if (!class_exists('WP_Importer')) {
			$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
			if (file_exists($class_wp_importer)) {
				require_once $class_wp_importer;
			}
		}

		$class_lafka_importer = plugin_dir_path(__FILE__) . "importer/lafka-wordpress-importer.php";
		if (file_exists($class_lafka_importer)) {
			require_once $class_lafka_importer;
		}
	}

}

// Contact form ajax actions
if (!function_exists('lafka_submit_contact')) {

	function lafka_submit_contact() {

		check_ajax_referer('lafka_contactform', false, true);

		$unique_id = array_key_exists('unique_id', $_POST) ? sanitize_text_field($_POST['unique_id']) : '';
		$nonce = array_key_exists('_ajax_nonce', $_POST) ? sanitize_text_field($_POST['_ajax_nonce']) : '';

		ob_start();
		?>
		<script>
            //<![CDATA[
            "use strict";
            jQuery(document).ready(function () {
                var submitButton = jQuery('#holder_<?php echo esc_js($unique_id) ?> input:submit');
                var loader = jQuery('<img id="<?php echo esc_js($unique_id) ?>_loading_gif" class="lafka-contacts-loading" src="<?php echo esc_url(plugin_dir_url(__FILE__)) ?>assets/image/contacts_ajax_loading.png" />').prependTo('#holder_<?php echo esc_attr($unique_id) ?> div.buttons div.left').hide();

                jQuery('#holder_<?php echo esc_js($unique_id) ?> form').ajaxForm({
                    target: '#holder_<?php echo esc_js($unique_id) ?>',
                    data: {
                        // additional data to be included along with the form fields
                        unique_id: '<?php echo esc_js($unique_id) ?>',
                        action: 'lafka_submit_contact',
                        _ajax_nonce: '<?php echo esc_js($nonce); ?>'
                    },
                    beforeSubmit: function (formData, jqForm, options) {
                        // optionally process data before submitting the form via AJAX
                        submitButton.hide();
                        loader.show();
                    },
                    success: function (responseText, statusText, xhr, $form) {
                        // code that's executed when the request is processed successfully
                        loader.remove();
                        submitButton.show();
                    }
                });
            });
            //]]>
		</script>
		<?php
		require(plugin_dir_path( __FILE__ ) . 'shortcodes/partials/contact-form.php');

		$output = ob_get_contents();
		ob_end_clean();

		echo $output; // All dynamic data escaped
		wp_die();
	}

}

add_action('wp_ajax_lafka_submit_contact', 'lafka_submit_contact');
add_action('wp_ajax_nopriv_lafka_submit_contact', 'lafka_submit_contact');

//function to generate response
if (!function_exists('lafka_contact_form_generate_response')) {

	function lafka_contact_form_generate_response($type, $message) {

		$lafka_contactform_response = '';

		if ($type == "success") {
			$lafka_contactform_response = "<div class='success-message'>" . esc_html($message) . "</div>";
		} else {
			$lafka_contactform_response .= "<div class='error-message'>" . esc_html($message) . "</div>";
		}

		return $lafka_contactform_response;
	}

}

if (!function_exists('lafka_share_links')) {

	/**
	 * Displays social networks share links
	 *
	 * @param $title
	 * @param $link
	 */
    function lafka_share_links($title, $link) {

        $has_to_show_share = lafka_has_to_show_share();

        if ( $has_to_show_share ) {
	        global $post;

	        $media = get_the_post_thumbnail_url( $post->ID, 'large' );
	        $share_links_html = '<span>' . esc_html__( 'Share', 'lafka-plugin' ) . ':</span>';

            $share_links_html .= sprintf(
                '<a class="lafka-share-facebook" title="%s" href="http://www.facebook.com/sharer.php?u=%s&t=%s" target="_blank" ></a>',
                esc_attr__( 'Share on Facebook', 'lafka-plugin' ),
                urlencode( $link ),
	            urlencode( html_entity_decode($title) )
            );
	        $share_links_html .= sprintf(
		        '<a class="lafka-share-twitter"  title="%s" href="http://twitter.com/share?text=%s&url=%s" target="_blank"></a>',
		        esc_attr__( 'Share on Twitter', 'lafka-plugin' ),
		        urlencode( html_entity_decode($title) ),
                urlencode( $link )
	        );
	        $share_links_html .= sprintf(
		        '<a class="lafka-share-pinterest" title="%s"  href="http://pinterest.com/pin/create/button?media=%s&url=%s&description=%s" target="_blank"></a>',
		        esc_attr__( 'Share on Pinterest', 'lafka-plugin' ),
		        urlencode( $media ),
		        urlencode( $link ),
		        urlencode( html_entity_decode($title) )
	        );
	        $share_links_html .= sprintf(
		        '<a class="lafka-share-linkedin" title="%s" href="http://www.linkedin.com/shareArticle?url=%s&title=%s" target="_blank"></a>',
		        esc_attr__( 'Share on LinkedIn', 'lafka-plugin' ),
		        urlencode( $link ),
		        urlencode( html_entity_decode($title) )
	        );
	        $share_links_html .= sprintf(
		        '<a class="lafka-share-vkontakte" title="%s"  href="http://vk.com/share.php?url=%s&title=%s&image=%s" target="_blank"></a>',
		        esc_attr__( 'Share on VK', 'lafka-plugin' ),
		        urlencode( $link ),
		        urlencode( html_entity_decode($title) ),
		        urlencode( $media )
	        );

            printf( '<div class="lafka-share-links">%s<div class="clear"></div></div>', $share_links_html );
        }

    }
}

add_action('wp_head', 'lafka_insert_og_tags');
if (!function_exists('lafka_insert_og_tags')) {
	/**
	 * Insert og tags sharers
	 */
    function lafka_insert_og_tags() {
        global $post;

        if(is_singular() && lafka_has_to_show_share()) {
            $large_size_width = get_option( "large_size_w" );
	        $large_size_height = get_option( "large_size_h" );

	        printf( '<meta property="og:image" content="%s">', get_the_post_thumbnail_url( $post->ID, 'large' ) );
	        printf( '<meta property="og:image:width" content="%d">', $large_size_width );
	        printf( '<meta property="og:image:height" content="%d">', $large_size_height );
        }
    }
}

if (!function_exists('lafka_has_to_show_share')) {
	function lafka_has_to_show_share() {

		if(function_exists('lafka_get_option')) {
			$general_option = get_option( 'lafka_share_on_posts' ) === 'yes';
			$general_option_product = get_option( 'lafka_share_on_products' ) === 'yes';
			$single_meta            = get_post_meta( get_the_ID(), 'lafka_show_share', true );

			$target = 'single';
			if (function_exists('is_product') && is_product()) {
			    $target = 'product';
			}

			$has_to_show_share = false;

			if ( $target === 'single' && $single_meta === 'yes' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'single' && $general_option && $single_meta !== 'no' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'product' && $general_option_product ) {
				$has_to_show_share = true;
			}

			return $has_to_show_share;
		}

		return false;
	}
}

add_action( 'woocommerce_single_product_summary', 'lafka_show_custom_product_popup_link', 12 );
if ( ! function_exists( 'lafka_show_custom_product_popup_link' ) ) {
	function lafka_show_custom_product_popup_link() {
		if ( function_exists( 'lafka_get_option' ) && trim( lafka_get_option( 'custom_product_popup_link' ) ) !== '' && trim( lafka_get_option( 'custom_product_popup_content' ) ) !== '' ) {
		    global $product;

			$link_text     = lafka_get_option( 'custom_product_popup_link' );
			$popup_content = lafka_get_option( 'custom_product_popup_content' );

			echo '<div class="lafka-product-popup-link"><a href="#lafka-product-' . esc_attr( $product->get_id() ) . '-popup-content" title="' . esc_attr( $link_text ) . '" >' . esc_html( $link_text ) . '</a></div>';

			echo '<div id="lafka-product-' . esc_attr( $product->get_id() ) . '-popup-content" class="mfp-hide">';
			echo wp_kses_post( do_shortcode( $popup_content ) );
			echo '</div>';

			$inline_script_data = "(function ($) {
                $(document).ready(function () {
                    $('.lafka-product-popup-link a').magnificPopup({
                        mainClass: 'lafka-product-popup-content mfp-fade',
                        type: 'inline',
                        midClick: true
                    });
                });
            })(window.jQuery);";

			wp_add_inline_script( 'magnific', $inline_script_data );

		}
	}
}

// Promo info tooltips
add_action( 'woocommerce_single_product_summary', function () {
	lafka_output_info_tooltips( 'above-price' );
}, 9 );
add_action( 'woocommerce_single_product_summary', function () {
	lafka_output_info_tooltips( 'below-price' );
}, 11 );
add_action( 'woocommerce_single_product_summary', function () {
	lafka_output_info_tooltips( 'below-add-to-cart' );
}, 39 );
add_action('woocommerce_after_shop_loop_item_title', function () {
	lafka_output_info_tooltips( '', true );
}, 11);

if ( ! function_exists( 'lafka_output_info_tooltips' ) ) {
	function lafka_output_info_tooltips( $position, $show_in_listing = false ) {
		for ( $i = 1; $i <= 3; $i ++ ) {
			if (function_exists( 'lafka_get_option' ) && lafka_get_option( 'promo_tooltip_' . $i . '_trigger_text' ) && ( $position === lafka_get_option( 'promo_tooltip_' . $i . '_position' ) || $show_in_listing && lafka_get_option( 'promo_tooltip_' . $i . '_show_in_listing' ) ) ) {
				?>
                <div class="lafka-promo-wrapper<?php if($position) echo ' lafka-promo-' . esc_attr( $position ) ?>">
                    <div class="lafka-promo-text">
						<?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_text' ) ) ?>
                        <span class="lafka-promo-trigger">
                            <?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_trigger_text' ) ) ?>
                            <span class="lafka-promo-content">
                                <?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_content' ) ) ?>
                            </span>
                        </span>
                    </div>
                </div>
				<?php
			}
		}
	}
}

// Import theme options
add_action('wp_ajax_lafka_options_upload', 'lafka_options_upload');
if ( ! function_exists( 'lafka_options_upload' ) ) {
	function lafka_options_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		check_ajax_referer( 'lafka_options_upload_nonce', 'security' );

		if ( isset( $_FILES['file']['tmp_name'] ) ) {
			$lafka_transfer_content = Lafka_Transfer_Content::getInstance();
			$result = $lafka_transfer_content->importSettings( $_FILES['file']['tmp_name'], false, false, false, true );
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( array( 'message' => 'No file provided' ) );
		}
	}
}

// Export theme options
add_action( 'admin_post_lafka_options_export', 'lafka_options_export' );
if ( ! function_exists( 'lafka_options_export' ) ) {
	function lafka_options_export() {
		if ( current_user_can( 'administrator' ) ) {
			$lafka_transfer_content = Lafka_Transfer_Content::getInstance();
			$export_file_path       = $lafka_transfer_content->exportThemeOptions();

			if ( file_exists( $export_file_path ) ) {
				header( 'Content-Description: File Transfer' );
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . basename( $export_file_path ) . '"' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );
				header( 'Content-Length: ' . filesize( $export_file_path ) );
				readfile( $export_file_path );
				exit;
			} else {
				wp_redirect( admin_url( 'admin.php?page=lafka-optionsframework' ) );
			}
		} else {
			wp_redirect(home_url());
		}
	}
}

// Allow safe HTML descriptions in WordPress Menu (related to Mega menu)
remove_filter('nav_menu_description', 'strip_tags');
add_filter('nav_menu_description', 'wp_kses_post');

// Allow Shortcodes in the Excerpt field (only when shortcode brackets are present)
add_filter('the_excerpt', function( $excerpt ) {
	if ( false !== strpos( $excerpt, '[' ) ) {
		return do_shortcode( $excerpt );
	}
	return $excerpt;
});

add_action( 'after_setup_theme', 'lafka_after_setup_theme' );
if ( ! function_exists( 'lafka_after_setup_theme' ) ) {
	/**
	 * Doing stuff which require theme to be loaded so we have 'lafka_get_option' function available etc.
	 */
	function lafka_after_setup_theme() {
		// Move product taxonomy description below products if 'category_description_position' = 'bottom'
		if ( function_exists( 'lafka_get_option' ) && lafka_get_option( 'category_description_position' ) === 'lafka-bottom-description' ) {
			remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
			add_action( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 1 );
		}
	}
}

add_filter( 'script_loader_tag', 'lafka_defer_script_loader_tags', 10, 3 );
if ( ! function_exists( 'lafka_defer_script_loader_tags' ) ) {
	/**
	 * Add async to script tags with defined handles.
	 *
	 * @param string $tag HTML for the script tag.
	 * @param string $handle Handle of script.
	 * @param string $src Src of script.
	 *
	 * @return string
	 */
	function lafka_defer_script_loader_tags( $tag, $handle, $src ) {
		if ( ! in_array( $handle, array( 'lafka-google-maps' ), true ) ) {
			return $tag;
		}

		return str_replace( ' src', ' defer src', $tag );
	}
}

add_filter( 'sgo_js_async_exclude', 'lafka_js_async_exclude' );
if ( ! function_exists( 'lafka_js_async_exclude' ) ) {
	function lafka_js_async_exclude( $exclude_list ) {
		$exclude_list[] = 'lafka-google-maps';

		return $exclude_list;
	}
}