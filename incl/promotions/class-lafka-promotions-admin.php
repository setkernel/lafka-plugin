<?php
/**
 * Lafka_Promotions_Admin — admin UI for the 4 promo knobs (P2-01a).
 *
 * The Lafka_Promotions module is gated by `lafka['promotions']`. Once enabled,
 * 4 knobs control the math: DELIVERY_MIN, BOGO_DISCOUNT, PROMO_KEY, DISMISS_DAYS.
 * Previously hardcoded as class constants; this UI lets ops change them
 * without WP-CLI / DB editing.
 *
 * Settings live in the dedicated `lafka_promotions_options` option array
 * (mirrors the KDS pattern). Empty values fall back to the constants in
 * Lafka_Promotions, which is how the math stays correct when the module is
 * loaded but the option doesn't exist yet.
 *
 * @package Lafka
 * @since   8.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Promotions_Admin' ) ) {

	final class Lafka_Promotions_Admin {

		const PAGE_SLUG    = 'lafka-promotions';
		const NONCE_ACTION = 'lafka_promotions_save';

		/** @var Lafka_Promotions_Admin|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_lafka_promotions_save', array( $this, 'handle_save' ) );
		}

		public function register_menu() {
			$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
			add_submenu_page(
				$parent,
				esc_html__( 'Lafka Promotions', 'lafka-plugin' ),
				esc_html__( 'Lafka Promotions', 'lafka-plugin' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		public function handle_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to change Lafka promotions settings.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::NONCE_ACTION );

			$delivery_min  = isset( $_POST['delivery_min'] ) ? max( 0, (float) wp_unslash( $_POST['delivery_min'] ) ) : Lafka_Promotions::DELIVERY_MIN;
			$bogo_discount = isset( $_POST['bogo_discount'] ) ? max( 0, min( 1, (float) wp_unslash( $_POST['bogo_discount'] ) ) ) : Lafka_Promotions::BOGO_DISCOUNT;
			$promo_key     = isset( $_POST['promo_key'] ) ? sanitize_key( wp_unslash( $_POST['promo_key'] ) ) : Lafka_Promotions::PROMO_KEY;
			$dismiss_days  = isset( $_POST['dismiss_days'] ) ? max( 0, (int) wp_unslash( $_POST['dismiss_days'] ) ) : Lafka_Promotions::DISMISS_DAYS;

			$opts = array(
				'delivery_min'  => $delivery_min,
				'bogo_discount' => $bogo_discount,
				'promo_key'     => $promo_key,
				'dismiss_days'  => $dismiss_days,
			);

			update_option( Lafka_Promotions::OPTION_KEY, $opts );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'updated' => 1,
					),
					admin_url( class_exists( 'WooCommerce' ) ? 'admin.php' : 'tools.php' )
				)
			);
			exit;
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'lafka-plugin' ), 403 );
			}

			$delivery_min  = Lafka_Promotions::knob( 'delivery_min' );
			$bogo_discount = Lafka_Promotions::knob( 'bogo_discount' );
			$promo_key     = Lafka_Promotions::knob( 'promo_key' );
			$dismiss_days  = Lafka_Promotions::knob( 'dismiss_days' );
			$gated_on      = function_exists( 'is_lafka_promotions' ) && is_lafka_promotions();
			$updated       = isset( $_GET['updated'] );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Lafka Promotions', 'lafka-plugin' ); ?></h1>

				<?php if ( $updated ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Promotion settings saved.', 'lafka-plugin' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! $gated_on ) : ?>
					<div class="notice notice-warning">
						<p>
							<strong><?php esc_html_e( 'Module is OFF.', 'lafka-plugin' ); ?></strong>
							<?php esc_html_e( 'Settings here have no effect until the lafka-plugin promotions module is enabled. To enable:', 'lafka-plugin' ); ?>
							<code>wp option patch update lafka promotions enabled</code>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lafka_promotions_save">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="delivery_min"><?php esc_html_e( 'Delivery minimum', 'lafka-plugin' ); ?></label>
							</th>
							<td>
								<input type="number" id="delivery_min" name="delivery_min" value="<?php echo esc_attr( $delivery_min ); ?>" min="0" step="0.01" class="small-text">
								<p class="description"><?php esc_html_e( 'Cart subtotal below this hides all delivery shipping methods (only local pickup remains). 0 disables the floor.', 'lafka-plugin' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="bogo_discount"><?php esc_html_e( 'BOGO discount', 'lafka-plugin' ); ?></label>
							</th>
							<td>
								<input type="number" id="bogo_discount" name="bogo_discount" value="<?php echo esc_attr( $bogo_discount ); ?>" min="0" max="1" step="0.05" class="small-text">
								<p class="description"><?php esc_html_e( 'Fraction off the cheapest paired item. 0.5 = 50% off (default). 1 = free.', 'lafka-plugin' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="promo_key"><?php esc_html_e( 'Promo key', 'lafka-plugin' ); ?></label>
							</th>
							<td>
								<input type="text" id="promo_key" name="promo_key" value="<?php echo esc_attr( $promo_key ); ?>" class="regular-text" pattern="[a-z0-9_-]+">
								<p class="description"><?php esc_html_e( 'localStorage dismissal key. Rotating this re-arms the banner for everyone (intentional — new promo, new key). Lowercase + hyphens + underscores only.', 'lafka-plugin' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="dismiss_days"><?php esc_html_e( 'Dismiss days', 'lafka-plugin' ); ?></label>
							</th>
							<td>
								<input type="number" id="dismiss_days" name="dismiss_days" value="<?php echo esc_attr( $dismiss_days ); ?>" min="0" step="1" class="small-text">
								<p class="description"><?php esc_html_e( 'How long a dismissed banner stays dismissed. 0 = always show until current visit ends.', 'lafka-plugin' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( esc_html__( 'Save', 'lafka-plugin' ) ); ?>
				</form>
			</div>
			<?php
		}
	}

	if ( is_admin() ) {
		Lafka_Promotions_Admin::instance();
	}
}
