<?php
/**
 * Lafka_Blocks_Integration — build-free block Cart/Checkout integration (NX1-04b).
 *
 * Registers a single WooCommerce Blocks IntegrationInterface on BOTH the cart and
 * checkout block registries so Lafka's two build-free JS components load on the
 * block money paths:
 *
 *   · Free-delivery progress — a SlotFill on the block CART reading the NX1-04a
 *     `lafka` cart-extension (threshold / remaining already exposed server-side).
 *   · Timeslot picker — a SlotFill on the block CHECKOUT rendering date + timeslot
 *     selects driven by the existing `time_slots_for_date` AJAX endpoint, pushing
 *     the selection through the NX1-04a `lafka` cart/extensions update callback.
 *
 * There is NO JS build pipeline in the plugin (and none may be added): the enqueued
 * script is plain ES using globals (wp.element.createElement — no JSX, wp.plugins,
 * wc.blocksCheckout SlotFills + extensionCartUpdate). It degrades safely — if the
 * script fails to load, checkout still submits and the NX1-04a server gates reject
 * any invalid state. The order_type + branch selects are separate (registered by
 * Lafka_Checkout_Fields through the Additional Checkout Fields API, no JS needed).
 *
 * The integration is registered only when the WooCommerce Blocks IntegrationRegistry
 * is present and only in blocks checkout mode.
 *
 * @package Lafka\Plugin\Checkout
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Blocks_Integration' )
	&& interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {

	/**
	 * Cart + Checkout block integration for Lafka's build-free components.
	 */
	final class Lafka_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

		/**
		 * Integration name — also the `<name>_data` key the client reads via
		 * wc.wcSettings.getSetting().
		 */
		const NAME = 'lafka-checkout';

		/**
		 * Frontend script handle.
		 */
		const SCRIPT_HANDLE = 'lafka-blocks-checkout';

		/**
		 * Hook the cart + checkout block integration registries. Called once from
		 * the plugin bootstrap; each registry only fires when its block renders.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_integration' ) );
			add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
		}

		/**
		 * Register a fresh integration instance on the given block registry, gated
		 * on blocks mode.
		 *
		 * @param mixed $registry WooCommerce Blocks IntegrationRegistry.
		 * @return void
		 */
		public static function register_integration( $registry ) {
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
				return;
			}
			if ( ! class_exists( 'Lafka_Checkout_Mode' ) || ! Lafka_Checkout_Mode::is_blocks() ) {
				return;
			}
			$registry->register( new self() );
		}

		/**
		 * The name of the integration.
		 *
		 * @return string
		 */
		public function get_name() {
			return self::NAME;
		}

		/**
		 * Register the frontend script (build-free, plain JS via globals).
		 *
		 * @return void
		 */
		public function initialize() {
			$relative = 'incl/checkout/assets/js/lafka-blocks-checkout.js';
			$version  = function_exists( 'lafka_plugin_asset_version' )
				? lafka_plugin_asset_version( $relative )
				: '1.0.0';

			wp_register_script(
				self::SCRIPT_HANDLE,
				plugins_url( $relative, LAFKA_PLUGIN_FILE ),
				array(
					'wp-element',
					'wp-plugins',
					'wp-data',
					'wp-i18n',
					'wc-blocks-checkout',
					'wc-settings',
				),
				$version,
				true
			);
		}

		/**
		 * Frontend script handles to enqueue.
		 *
		 * @return string[]
		 */
		public function get_script_handles() {
			return array( self::SCRIPT_HANDLE );
		}

		/**
		 * Editor script handles — none (the components are frontend-only and degrade
		 * to nothing in the editor preview).
		 *
		 * @return string[]
		 */
		public function get_editor_script_handles() {
			return array();
		}

		/**
		 * Data made available to the client as wc.wcSettings.getSetting('lafka-checkout_data').
		 *
		 * @return array
		 */
		public function get_script_data() {
			return array(
				'currencySymbol'  => self::currency_symbol(),
				'ajaxUrl'         => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
				'timeslot'        => self::timeslot_config(),
				'i18n'            => array(
					'freeDeliveryRemaining' => __( 'Add %s more for free delivery', 'lafka-plugin' ),
					'freeDeliveryReached'   => __( 'You have unlocked free delivery!', 'lafka-plugin' ),
					'timeslotHeading'       => __( 'Delivery / pickup time', 'lafka-plugin' ),
					'chooseDate'            => __( 'Choose a date', 'lafka-plugin' ),
					'chooseTime'            => __( 'Choose a time', 'lafka-plugin' ),
					'loadingSlots'          => __( 'Loading times…', 'lafka-plugin' ),
					'noSlots'               => __( 'No times available for this date.', 'lafka-plugin' ),
				),
			);
		}

		/**
		 * Timeslot picker config for the client — mirrors the classic datetime
		 * conditions. `enabled` is false unless the datetime feature is turned on,
		 * so the picker component simply never registers.
		 *
		 * @return array
		 */
		private static function timeslot_config() {
			$options = get_option( 'lafka_shipping_areas_datetime' );
			$enabled = is_array( $options ) && ! empty( $options['enable_datetime_option'] );

			$mandatory  = false;
			$days_ahead = 30;
			if ( $enabled && class_exists( 'Lafka_Timeslots' ) ) {
				$timeslots = Lafka_Timeslots::instance();
				if ( $timeslots instanceof Lafka_Timeslots ) {
					$mandatory  = $timeslots->is_mandatory();
					$days_ahead = $timeslots->get_days_ahead();
				}
			}

			return array(
				'enabled'   => $enabled,
				'mandatory' => (bool) $mandatory,
				'daysAhead' => (int) $days_ahead,
				'nonce'     => ( $enabled && function_exists( 'wp_create_nonce' ) ) ? wp_create_nonce( 'time_slots_for_date' ) : '',
			);
		}

		/**
		 * Active currency symbol as plain text (HTML entities decoded) for the
		 * progress copy.
		 *
		 * @return string
		 */
		private static function currency_symbol() {
			if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
				return '';
			}
			$symbol = get_woocommerce_currency_symbol();

			return function_exists( 'html_entity_decode' ) ? html_entity_decode( $symbol ) : $symbol;
		}
	}
}
