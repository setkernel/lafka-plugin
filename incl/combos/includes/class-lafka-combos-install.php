<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles installation and updating tasks.
 *
 * @class    WC_LafkaCombos_Install
 * @version  6.4.0
 */
class WC_LafkaCombos_Install {

	/**
	 * Whether install() ran in this request.
	 * @var boolean
	 */
	private static $is_install_request;

	/**
	 * Term runtime cache.
	 * @var boolean
	 */
	private static $combo_term_exists;

	/**
	 * Current plugin version.
	 * @var string
	 */
	private static $current_version;

	/**
	 * Current DB version.
	 * @var string
	 */
	private static $current_db_version;

	/**
	 * Hook in tabs.
	 */
	public static function init() {

		// Installation and DB updates handling.
		add_action( 'init', array( __CLASS__, 'maybe_install' ) );

		// Adds support for the Combo type - added here instead of 'WC_LafkaCombos_Meta_Box_Product_Data' as it's used in REST context.
		add_filter( 'product_type_selector', array( __CLASS__, 'product_selector_filter' ) );

		// Get PB plugin and DB versions.
		self::$current_version    = get_option( 'woocommerce_product_combos_version', null );
		self::$current_db_version = get_option( 'woocommerce_product_combos_db_version', null );
	}

	/**
	 * Add support for the 'combo' product type.
	 *
	 * @param  array  $options
	 * @return array
	 */
	public static function product_selector_filter( $options ) {

		$options['combo'] = __( 'Product combo', 'lafka-plugin' );

		return $options;
	}

	/**
	 * Installation needed?
	 *
	 * @since  5.5.0
	 *
	 * @return boolean
	 */
	private static function must_install() {
		return version_compare( self::$current_version, WC_LafkaCombos()->plugin_version(), '<' );
	}

	/**
	 * Installation possible?
	 *
	 * @since  5.5.0
	 *
	 * @return boolean
	 */
	private static function can_install() {
		return ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) && ! defined( 'IFRAME_REQUEST' ) && ! self::is_installing();
	}

	/**
	 * Check version and run the installer if necessary.
	 *
	 * @since  5.5.0
	 */
	public static function maybe_install() {
		if ( self::can_install() && self::must_install() ) {
			self::install();
		}
	}

	/**
	 * Check version and run the installer if necessary.
	 *
	 * @since  6.2.4
	 */
	private static function is_installing() {
		return 'yes' === get_transient( 'wc_pb_installing' );
	}

	/**
	 * Check version and run the installer if necessary.
	 *
	 * @since  6.2.4
	 */
	private static function is_new_install() {
		if ( is_null( self::$combo_term_exists ) ) {
			self::$combo_term_exists = get_term_by( 'slug', 'combo', 'product_type' );
		}
		return ! self::$combo_term_exists;
	}

	/**
	 * Install PB.
	 */
	public static function install() {

		if ( ! is_blog_installed() ) {
			return;
		}

		// Running for the first time? Set a transient now. Used in 'can_install' to prevent race conditions.
		set_transient( 'wc_pb_installing', 'yes', 10 );

		// Set a flag to indicate we're installing in the current request.
		self::$is_install_request = true;

		// Create tables.
		self::create_tables();

		// if combo type does not exist, create it.
		if ( self::is_new_install() ) {
			wp_insert_term( 'combo', 'product_type' );
		}

		if ( ! class_exists( 'WC_LafkaCombos_Admin_Notices' ) ) {
			require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin-notices.php';
		}

		// Update plugin version - once set, 'maybe_install' will not call 'install' again.
		self::update_version();
	}

	/**
	 * Set up the database tables which the plugin needs to function.
	 *
	 * Tables:
	 *     woocommerce_lafka_combined_items - Each combined item id is associated with a "contained" product id (the combined product), and a "container" combo id (the product combo).
	 *     woocommerce_lafka_combined_itemmeta - Combined item meta for storing extra data.
	 */
	private static function create_tables() {
		global $wpdb;
		$wpdb->hide_errors();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::get_schema() );
	}

	/**
	 * Get table schema.
	 *
	 * @return string
	 */
	private static function get_schema() {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$max_index_length = 191;

		$tables = "
CREATE TABLE {$wpdb->prefix}woocommerce_lafka_combined_items (
  combined_item_id BIGINT UNSIGNED NOT NULL auto_increment,
  product_id BIGINT UNSIGNED NOT NULL,
  combo_id BIGINT UNSIGNED NOT NULL,
  menu_order BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (combined_item_id),
  KEY product_id (product_id),
  KEY combo_id (combo_id)
) $collate;
CREATE TABLE {$wpdb->prefix}woocommerce_lafka_combined_itemmeta (
  meta_id BIGINT UNSIGNED NOT NULL auto_increment,
  combined_item_id BIGINT UNSIGNED NOT NULL,
  meta_key varchar(255) default NULL,
  meta_value longtext NULL,
  PRIMARY KEY  (meta_id),
  KEY combined_item_id (combined_item_id),
  KEY meta_key (meta_key($max_index_length))
) $collate;
		";

		return $tables;
	}

	/**
	 * Update WC PB version to current.
	 */
	private static function update_version() {
		delete_option( 'woocommerce_product_combos_version' );
		add_option( 'woocommerce_product_combos_version', WC_LafkaCombos()->plugin_version() );
	}
}

WC_LafkaCombos_Install::init();
