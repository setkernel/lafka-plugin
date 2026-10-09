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
 * Wording: the block checkout says "Ship" and "Shipping address" where the rest
 * of the site says Delivery. The checkout's own block attributes carry those
 * labels (the same ones the merchant can edit in the editor), so the rendered
 * blocks get Delivery defaults through render_block when the merchant has not
 * set their own (filter `lafka_blocks_checkout_labels`).
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.0.0
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
		 * Checkout blocks that get the Delivery wording (see labels()).
		 */
		const LABELLED_BLOCKS = array(
			'woocommerce/checkout-shipping-method-block',
			'woocommerce/checkout-shipping-address-block',
			'woocommerce/checkout-shipping-methods-block',
		);

		/**
		 * Hook the cart + checkout block integration registries. Called once from
		 * the plugin bootstrap; each registry only fires when its block renders.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_integration' ) );
			add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
			// The names only: the translated labels are read at render time.
			foreach ( self::LABELLED_BLOCKS as $block_name ) {
				add_filter( 'render_block_' . $block_name, array( __CLASS__, 'label_block' ), 10, 2 );
			}
		}

		/**
		 * Delivery wording for the block checkout: block name => attribute => text.
		 *
		 * @return array<string, array<string, string>>
		 */
		public static function labels(): array {
			$labels = array(
				'woocommerce/checkout-shipping-method-block'  => array(
					'title'        => __( 'Pickup or delivery', 'lafka-plugin' ),
					'shippingText' => __( 'Delivery', 'lafka-plugin' ),
				),
				'woocommerce/checkout-shipping-address-block' => array(
					'title' => __( 'Delivery address', 'lafka-plugin' ),
				),
				'woocommerce/checkout-shipping-methods-block' => array(
					'title' => __( 'Delivery options', 'lafka-plugin' ),
				),
			);

			/**
			 * Filter the block checkout's delivery wording (block attribute defaults).
			 * Return an empty array for a block to keep WooCommerce's own words.
			 *
			 * @since 10.4.0
			 * @param array<string, array<string, string>> $labels Block name => attribute => text.
			 */
			return (array) apply_filters( 'lafka_blocks_checkout_labels', $labels );
		}

		/**
		 * render_block_{name}: give the checkout block the Delivery wording as
		 * its attributes (WooCommerce reads them from the block's data-*
		 * attributes), unless the merchant set that attribute in the editor.
		 *
		 * @param mixed $html  Rendered block.
		 * @param mixed $block Parsed block.
		 * @return mixed
		 */
		public static function label_block( $html, $block ) {
			if ( ! is_string( $html ) || '' === $html || ! is_array( $block ) || ! class_exists( 'WP_HTML_Tag_Processor' )
				|| ! class_exists( 'Lafka_Checkout_Mode' ) || ! Lafka_Checkout_Mode::is_blocks() ) {
				return $html;
			}
			$name   = (string) ( $block['blockName'] ?? '' );
			$labels = self::labels();
			$set    = (array) ( $block['attrs'] ?? array() );
			$tags   = new WP_HTML_Tag_Processor( $html );
			if ( empty( $labels[ $name ] ) || ! $tags->next_tag() ) {
				return $html;
			}
			foreach ( (array) $labels[ $name ] as $attribute => $text ) {
				if ( array_key_exists( $attribute, $set ) || '' === (string) $text ) {
					continue;
				}
				$data = 'data-' . strtolower( (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', (string) $attribute ) );
				if ( null === $tags->get_attribute( $data ) ) {
					$tags->set_attribute( $data, (string) $text );
				}
			}

			return $tags->get_updated_html();
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
					'wc-blocks-data-store',
					'wc-blocks-checkout',
					'wc-settings',
					'lafka-core',
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
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'timeslot' => self::timeslot_config(),
				'i18n'     => array(
					'freeDeliveryRemaining' => /* translators: %s: amount still needed to qualify for free delivery. */ __( 'Add %s more for free delivery', 'lafka-plugin' ),
					'freeDeliveryReached'   => __( 'You have unlocked free delivery!', 'lafka-plugin' ),
					'timeslotHeading'       => __( 'Delivery / pickup time', 'lafka-plugin' ),
					'chooseDate'            => __( 'Choose a date', 'lafka-plugin' ),
					'chooseTime'            => __( 'Choose a time', 'lafka-plugin' ),
					'loadingSlots'          => __( 'Loading times…', 'lafka-plugin' ),
					'noSlots'               => __( 'No times available for this date.', 'lafka-plugin' ),
					'deliveryQuotePending'  => __( 'Checking the delivery price for your address…', 'lafka-plugin' ),
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
				'nonce'     => $enabled ? wp_create_nonce( 'time_slots_for_date' ) : '',
			);
		}
	}
}
