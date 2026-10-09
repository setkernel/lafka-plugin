<?php
/**
 * Tips at checkout: preset percentages, an optional custom amount, added as
 * a non-taxable fee. Classic and block checkout.
 *
 * Settings: WooCommerce → Settings → Restaurant → Tips
 *   lafka_tips_enabled  yes|no (default no)
 *   lafka_tips_presets  "10,15,20" (percent of the order's items)
 *   lafka_tips_custom   yes|no  (a custom amount field)
 *   lafka_tips_scope    all|delivery (delivery: hidden for pickup orders)
 *   lafka_tips_label    fee label, e.g. "Tip for the team"
 *
 * The choice lives in the WooCommerce session (`lafka_tip`: mode percent |
 * amount | none, value) and is cleared once the order is placed.
 *   - Classic: radio pills in the order review; admin-ajax `lafka_set_tip`,
 *     then WooCommerce's `update_checkout`.
 *   - Blocks: a picker in the order summary; Store API extensionCartUpdate
 *     (namespace `lafka-tips`); the cart extension exposes the options.
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Tips' ) ) {

	/**
	 * Tips.
	 */
	final class Lafka_Tips {

		/** Session key. */
		const SESSION = 'lafka_tip';

		/** Store API namespace. */
		const NAMESPACE = 'lafka-tips';

		/**
		 * Hook in when tips are on.
		 *
		 * @return void
		 */
		public static function init(): void {
			if ( 'yes' !== get_option( 'lafka_tips_enabled', 'no' ) ) {
				return;
			}
			add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee' ), 30 );
			add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render_classic' ) );
			add_action( 'wp_ajax_lafka_set_tip', array( __CLASS__, 'ajax_set' ) );
			add_action( 'wp_ajax_nopriv_lafka_set_tip', array( __CLASS__, 'ajax_set' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear' ) );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'clear' ) );
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				self::register_store_api();
			}
		}

		/**
		 * Preset percentages.
		 *
		 * @return float[]
		 */
		public static function presets(): array {
			$raw  = explode( ',', (string) get_option( 'lafka_tips_presets', '10,15,20' ) );
			$list = array();
			foreach ( $raw as $value ) {
				$value = (float) trim( $value );
				if ( $value > 0 && $value <= 100 ) {
					$list[] = $value;
				}
			}
			return array_values( array_unique( $list ) );
		}

		/**
		 * The fee label.
		 *
		 * @return string
		 */
		public static function label(): string {
			$label = trim( (string) get_option( 'lafka_tips_label', '' ) );
			return '' !== $label ? $label : __( 'Tip', 'lafka-plugin' );
		}

		/**
		 * Whether the current cart can carry a tip (scope "delivery" leaves
		 * pickup orders out).
		 *
		 * @return bool
		 */
		public static function applies(): bool {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return false;
			}
			if ( 'delivery' !== get_option( 'lafka_tips_scope', 'all' ) ) {
				return true;
			}
			foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $method ) {
				if ( function_exists( 'lafka_is_pickup_shipping_method' ) && lafka_is_pickup_shipping_method( (string) $method ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * The customer's choice from the session.
		 *
		 * @return array{mode: string, value: float}
		 */
		public static function choice(): array {
			$tip = WC()->session ? WC()->session->get( self::SESSION ) : null;
			if ( ! is_array( $tip ) || ! in_array( $tip['mode'] ?? '', array( 'percent', 'amount' ), true ) ) {
				return array(
					'mode'  => 'none',
					'value' => 0.0,
				);
			}
			return array(
				'mode'  => (string) $tip['mode'],
				'value' => (float) $tip['value'],
			);
		}

		/**
		 * Validate and store a choice.
		 *
		 * @param string $mode  percent | amount | none.
		 * @param float  $value Percent or amount.
		 * @return void
		 */
		public static function set( string $mode, float $value ): void {
			if ( ! WC()->session ) {
				return;
			}
			$ok = ( 'percent' === $mode && in_array( $value, self::presets(), true ) )
				|| ( 'amount' === $mode && 'yes' === get_option( 'lafka_tips_custom', 'yes' ) && $value > 0 && $value <= 1000 );
			WC()->session->set(
				self::SESSION,
				$ok ? array(
					'mode'  => $mode,
					'value' => round( $value, 2 ),
				) : null
			);
		}

		/**
		 * The order's items total the percentages apply to (after discounts:
		 * coupons and the first-order / slow-day / combo discount; before tax
		 * and delivery).
		 *
		 * @return float
		 */
		private static function base(): float {
			$cart  = WC()->cart;
			$promo = function_exists( 'lafka_order_discount_fee_amount' ) ? lafka_order_discount_fee_amount( $cart ) : 0.0;
			return max( 0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() - $promo );
		}

		/**
		 * Tip amount for a choice.
		 *
		 * @param array{mode: string, value: float} $choice Choice.
		 * @return float
		 */
		public static function amount( array $choice ): float {
			if ( 'percent' === $choice['mode'] ) {
				return round( self::base() * $choice['value'] / 100, 2 );
			}
			return 'amount' === $choice['mode'] ? round( $choice['value'], 2 ) : 0.0;
		}

		/**
		 * Add the tip as a non-taxable fee.
		 *
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function add_fee( $cart ): void {
			if ( ! $cart instanceof WC_Cart || ! self::applies() ) {
				return;
			}
			$amount = self::amount( self::choice() );
			if ( $amount > 0 ) {
				$cart->add_fee( self::label(), $amount, false );
			}
		}

		/**
		 * Classic checkout: the picker as an order-review row.
		 *
		 * @return void
		 */
		public static function render_classic(): void {
			if ( ! self::applies() ) {
				return;
			}
			$choice = self::choice();
			?>
			<tr class="lafka-tips-row">
				<td colspan="2">
					<div class="lafka-tips">
					<p class="lafka-tips__title"><?php echo esc_html( self::label() ); ?></p>
					<div class="lafka-tips__options" role="radiogroup" aria-label="<?php echo esc_attr( self::label() ); ?>" data-lafka-tips="<?php echo esc_attr( wp_create_nonce( 'lafka_tips' ) ); ?>">
						<label><input type="radio" name="lafka_tip" value="none" <?php checked( 'none', $choice['mode'] ); ?>> <?php esc_html_e( 'No tip', 'lafka-plugin' ); ?></label>
						<?php foreach ( self::presets() as $percent ) : ?>
							<?php
							$amount = self::amount(
								array(
									'mode'  => 'percent',
									'value' => $percent,
								)
							);
							?>
							<label><input type="radio" name="lafka_tip" value="percent:<?php echo esc_attr( (string) $percent ); ?>" <?php checked( 'percent' === $choice['mode'] && (float) $percent === $choice['value'] ); ?>> <?php echo esc_html( wc_format_localized_decimal( (string) $percent ) . '%' ); ?> <span class="lafka-tips__amount">(<?php echo wp_kses_post( wc_price( $amount ) ); ?>)</span></label>
						<?php endforeach; ?>
						<?php if ( 'yes' === get_option( 'lafka_tips_custom', 'yes' ) ) : ?>
							<label class="lafka-tips__custom"><input type="radio" name="lafka_tip" value="amount" <?php checked( 'amount', $choice['mode'] ); ?>> <?php esc_html_e( 'Other', 'lafka-plugin' ); ?>
								<input type="number" min="0" max="1000" step="0.01" inputmode="decimal" class="input-text" name="lafka_tip_amount" value="<?php echo 'amount' === $choice['mode'] ? esc_attr( (string) $choice['value'] ) : ''; ?>" aria-label="<?php esc_attr_e( 'Tip amount', 'lafka-plugin' ); ?>">
							</label>
						<?php endif; ?>
					</div>
					</div>
				</td>
			</tr>
			<?php
		}

		/**
		 * Classic checkout: store the choice.
		 *
		 * @return void
		 */
		public static function ajax_set(): void {
			check_ajax_referer( 'lafka_tips', 'nonce' );
			$tip   = isset( $_POST['tip'] ) ? sanitize_text_field( wp_unslash( $_POST['tip'] ) ) : 'none';
			$value = isset( $_POST['amount'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0.0;
			if ( 0 === strpos( $tip, 'percent:' ) ) {
				self::set( 'percent', (float) substr( $tip, 8 ) );
			} elseif ( 'amount' === $tip ) {
				self::set( 'amount', $value );
			} else {
				self::set( 'none', 0.0 );
			}
			wp_send_json_success();
		}

		/**
		 * Scripts: the classic handler on the classic checkout, the block
		 * picker on the block checkout.
		 *
		 * @return void
		 */
		public static function assets(): void {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
				return;
			}
			$classic = lafka_plugin_script_path( 'assets/js/lafka-tips.min.js' );
			wp_enqueue_script( 'lafka-tips', plugins_url( $classic, LAFKA_PLUGIN_FILE ), array( 'lafka-core', 'jquery' ), lafka_plugin_asset_version( $classic ), true );
			wp_localize_script( 'lafka-tips', 'lafkaTips', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );

			if ( wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
				$blocks = lafka_plugin_script_path( 'incl/checkout/assets/js/lafka-tips-blocks.min.js' );
				wp_enqueue_script( 'lafka-tips-blocks', plugins_url( $blocks, LAFKA_PLUGIN_FILE ), array( 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-checkout' ), lafka_plugin_asset_version( $blocks ), true );
			}
		}

		/**
		 * Block checkout: expose the options and apply choices.
		 *
		 * @return void
		 */
		public static function register_store_api(): void {
			static $done = false;
			if ( $done || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			$done = true;
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( __CLASS__, 'cart_data' ),
					'schema_callback' => array( __CLASS__, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( __CLASS__, 'store_api_update' ),
				)
			);
		}

		/**
		 * Cart extension data for the block picker.
		 *
		 * @return array<string,mixed>
		 */
		public static function cart_data(): array {
			$choice  = self::choice();
			$options = array();
			if ( self::applies() ) {
				foreach ( self::presets() as $percent ) {
					$options[] = array(
						'value'  => 'percent:' . $percent,
						'label'  => wc_format_localized_decimal( (string) $percent ) . '%',
						'amount' => html_entity_decode(
							wp_strip_all_tags(
								wc_price(
									self::amount(
										array(
											'mode'  => 'percent',
											'value' => $percent,
										)
									)
								)
							),
							ENT_QUOTES,
							'UTF-8'
						),
					);
				}
			}
			return array(
				'applies'  => self::applies(),
				'label'    => self::label(),
				'custom'   => 'yes' === get_option( 'lafka_tips_custom', 'yes' ),
				'options'  => $options,
				'selected' => 'percent' === $choice['mode'] ? 'percent:' . $choice['value'] : $choice['mode'],
				'amount'   => 'amount' === $choice['mode'] ? $choice['value'] : 0,
				'text'     => array(
					'none'   => __( 'No tip', 'lafka-plugin' ),
					'other'  => __( 'Other amount', 'lafka-plugin' ),
					'amount' => __( 'Tip amount', 'lafka-plugin' ),
					'add'    => __( 'Add', 'lafka-plugin' ),
				),
			);
		}

		/**
		 * Schema of the cart extension.
		 *
		 * @return array<string, array<string,mixed>>
		 */
		public static function cart_schema(): array {
			$field = static function ( string $type, string $description ): array {
				return array(
					'description' => $description,
					'type'        => $type,
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				);
			};
			return array(
				'applies'  => $field( 'boolean', 'Whether this order can carry a tip.' ),
				'label'    => $field( 'string', 'Tip label.' ),
				'custom'   => $field( 'boolean', 'Whether a custom amount is offered.' ),
				'options'  => $field( 'array', 'Preset tips: value, label, amount.' ),
				'selected' => $field( 'string', 'Current choice: none, amount or percent:<n>.' ),
				'amount'   => $field( 'number', 'Current custom amount.' ),
				'text'     => $field( 'object', 'Translated labels.' ),
			);
		}

		/**
		 * Store API update from the block picker.
		 *
		 * @param array<string,mixed> $data { tip: none|amount|percent:<n>, amount?: number }.
		 * @return void
		 */
		public static function store_api_update( $data ): void {
			$data = is_array( $data ) ? $data : array();
			$tip  = sanitize_text_field( (string) ( $data['tip'] ?? 'none' ) );
			if ( 0 === strpos( $tip, 'percent:' ) ) {
				self::set( 'percent', (float) substr( $tip, 8 ) );
			} elseif ( 'amount' === $tip ) {
				self::set( 'amount', (float) ( $data['amount'] ?? 0 ) );
			} else {
				self::set( 'none', 0.0 );
			}
		}

		/**
		 * A placed order takes its tip; the next order starts without one.
		 *
		 * @return void
		 */
		public static function clear(): void {
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( self::SESSION, null );
			}
		}
	}
}
