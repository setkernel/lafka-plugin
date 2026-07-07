<?php
/**
 * Lafka_Order_Notifications — admin new-order alert poller (NX1-08b).
 *
 * MOVED from the parent theme (lafka-theme/incl/woocommerce-functions.php) where
 * the AJAX handler, its persisted state and the poller JS registration lived — a
 * wp.org theme-review blocker and audit finding #58 (business logic belongs in the
 * plugin, not the theme). This is a MOVE, not a rewrite: the AJAX action name
 * (`lafka_new_orders_notification`), the nonce action (`lafka_ajax_nonce`), the
 * persisted-state option (`lafka_last_processed_order_ids`) and the JS polling
 * behaviour are all preserved verbatim so existing installs keep working.
 *
 * What it does: a 30s admin poller asks this handler "is there a new processing
 * order I have not been alerted about yet?". The handler reads the branch-routing
 * meta (`lafka_selected_branch_id`) HPOS-safely — under High-Performance Order
 * Storage that meta lives in `wc_orders_meta`, NOT `wp_postmeta` — via the plugin's
 * canonical Lafka_Shipping_Areas::get_order_meta_backward_compatible() accessor, so
 * multi-branch routing does not silently misroute. When a new order is found it
 * returns a notification payload the plugin's own service worker renders.
 *
 * Gate: the feature reads the shared `order_notifications` flag from the `lafka`
 * option array (the same value the operator toggles — now also surfaced in the
 * Lafka → Modules dashboard, NX1-01) and is additionally filterable via
 * `lafka_order_notifications_enabled`. Default preserves current behaviour.
 *
 * @package Lafka\Plugin\Admin
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Order_Notifications' ) ) {

	/**
	 * Admin poller + AJAX handler that alerts shop managers to new processing orders.
	 */
	final class Lafka_Order_Notifications {

		/** AJAX action name — preserved from the theme so the poller contract is unchanged. */
		const AJAX_ACTION = 'lafka_new_orders_notification';

		/** Nonce action — preserved from the theme. */
		const NONCE_ACTION = 'lafka_ajax_nonce';

		/**
		 * LEGACY shared option (JSON array of notified order IDs) — the theme-era
		 * storage. One site-wide list meant the first manager whose poll landed
		 * consumed the alert for every other logged-in manager. Kept only as a
		 * one-time seed for the per-user state and for uninstall cleanup.
		 */
		const STATE_OPTION = 'lafka_last_processed_order_ids';

		/**
		 * Per-user meta key holding the order IDs this user was already alerted
		 * about — each concurrent shop manager gets their own notification.
		 */
		const STATE_META = '_lafka_notified_order_ids';

		/** Capability required to receive/serve order notifications. */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Wire the AJAX handler, the poller enqueue and the permission dialog.
		 */
		public static function init(): void {
			add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_new_orders_notification' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_poller' ) );
			add_action( 'admin_footer', array( __CLASS__, 'render_permission_dialog' ) );
		}

		/**
		 * Whether the order-notification poller is enabled.
		 *
		 * Reads the shared `order_notifications` flag (a checkbox stored in the
		 * `lafka` option array — truthy when enabled) and lets integrators force
		 * the state via the `lafka_order_notifications_enabled` filter.
		 */
		public static function is_enabled(): bool {
			$enabled = class_exists( 'Lafka_Options' ) ? (bool) Lafka_Options::get( 'order_notifications' ) : false;

			return (bool) apply_filters( 'lafka_order_notifications_enabled', $enabled );
		}

		/**
		 * The three runtime prerequisites shared by the enqueue + dialog hooks:
		 * WooCommerce active, feature enabled, current user is a shop manager.
		 */
		private static function should_run(): bool {
			return class_exists( 'WooCommerce' )
				&& self::is_enabled()
				&& current_user_can( self::CAPABILITY );
		}

		/**
		 * URL of the plugin's own service worker that renders the notification.
		 *
		 * Plugin ships its own feature JS (theme-agnostic): the poller registers
		 * this worker, which listens for the poll response and calls
		 * showNotification(). Filterable so an operator can point it at a custom
		 * worker.
		 */
		public static function service_worker_url(): string {
			$url = plugins_url( 'assets/js/lafka-order-notifications-sw.js', __FILE__ );

			return (string) apply_filters( 'lafka_order_notifications_sw_url', $url );
		}

		/**
		 * Enqueue + localise the admin poller. Runs on every admin screen (the
		 * feature alerts staff to new orders wherever they are in wp-admin), gated
		 * on WooCommerce + the enable flag + the shop-manager capability.
		 *
		 * @param string $hook Current admin page hook (unused; poller is site-wide).
		 */
		public static function enqueue_poller( $hook = '' ): void {
			unset( $hook );

			if ( ! self::should_run() ) {
				return;
			}

			wp_enqueue_style( 'wp-jquery-ui-dialog' );
			wp_enqueue_script(
				'lafka-order-notifications',
				plugins_url( 'assets/js/lafka-order-notifications.js', __FILE__ ),
				array( 'jquery', 'jquery-ui-dialog' ),
				lafka_plugin_asset_version( 'incl/admin/assets/js/lafka-order-notifications.js' ),
				true
			);
			wp_localize_script(
				'lafka-order-notifications',
				'lafka_order_notifications_params',
				array(
					'new_orders_push_notifications' => 'yes',
					'action'                        => self::AJAX_ACTION,
					'nonce'                         => wp_create_nonce( self::NONCE_ACTION ),
					'service_worker_path'           => self::service_worker_url(),
					'allow_label'                   => esc_html__( 'Set Permission', 'lafka-plugin' ),
					'cancel_label'                  => esc_html__( 'Close', 'lafka-plugin' ),
				)
			);
		}

		/**
		 * Output the browser-permission confirmation dialog the poller opens on
		 * first run. Rendered in the admin footer so jQuery UI dialog can adopt it.
		 */
		public static function render_permission_dialog(): void {
			if ( ! self::should_run() ) {
				return;
			}
			?>
			<div id="lafka-push-confirm" title="<?php esc_attr_e( 'Push notifications for new orders by Lafka', 'lafka-plugin' ); ?>">
				<p>
					<span class="dashicons dashicons-testimonial"></span>
					<?php esc_html_e( 'To receive notification for new orders, you have to allow this permission in your browser.', 'lafka-plugin' ); ?>
				</p>
			</div>
			<?php
		}

		/**
		 * AJAX endpoint: return the next un-notified processing order (or '').
		 *
		 * Preserves the theme contract exactly — verify the nonce, require the
		 * shop-manager capability, then emit the notification payload as JSON.
		 */
		public static function ajax_new_orders_notification(): void {
			check_ajax_referer( self::NONCE_ACTION, 'security' );

			if ( current_user_can( self::CAPABILITY ) ) {
				wp_send_json( self::compute_notification() );
			}

			wp_die();
		}

		/**
		 * Core logic (side-effect: persists the notified-order state). Returns the
		 * notification payload array for the next un-notified processing order, or
		 * '' when there is nothing new for the current user.
		 *
		 * Extracted from the AJAX wrapper so it is unit-testable without booting the
		 * admin-ajax nonce/capability machinery.
		 *
		 * @return array<string,string>|string
		 */
		public static function compute_notification() {
			$order_id_to_notify       = 0;
			$user_id                  = (int) get_current_user_id();
			$notified_order_ids_array = get_user_meta( $user_id, self::STATE_META, true );
			if ( ! is_array( $notified_order_ids_array ) ) {
				// First per-user poll (fresh user, or the pre-per-user upgrade):
				// seed from the legacy shared option so still-processing orders
				// that were already announced don't re-alert after the upgrade.
				$legacy                   = json_decode( (string) get_option( self::STATE_OPTION, '' ), true );
				$notified_order_ids_array = is_array( $legacy ) ? $legacy : array();
			}

			$order_ids_to_be_processed_array = wc_get_orders(
				array(
					'status' => 'processing',
					'return' => 'ids',
				)
			);
			if ( ! is_array( $order_ids_to_be_processed_array ) ) {
				$order_ids_to_be_processed_array = array();
			}

			// Clear already-notified orders that are no longer processing.
			$notified_order_ids_array = array_intersect( $notified_order_ids_array, $order_ids_to_be_processed_array );

			// Prime meta cache for all order IDs to avoid N+1 queries. Under HPOS
			// order meta lives in `wc_orders_meta` (not `wp_postmeta`) and is already
			// loaded onto the WC_Order objects wc_get_orders() returned, so priming
			// the 'post' meta cache is unnecessary — and wrong for orders that have no
			// `wp_posts` row at all. Mirrors incl/kitchen-display/includes/class-lafka-kds-ajax.php.
			if ( $order_ids_to_be_processed_array && ! self::hpos_enabled() ) {
				update_meta_cache( 'post', $order_ids_to_be_processed_array );
			}

			foreach ( $order_ids_to_be_processed_array as $order_id ) {
				if ( in_array( $order_id, $notified_order_ids_array, true ) ) {
					continue;
				}

				$to_notify = true;
				$branch_id = self::get_order_meta( $order_id, 'lafka_selected_branch_id' );
				if ( ! empty( $branch_id ) ) {
					$branch_user_id = get_term_meta( $branch_id, 'lafka_branch_user', true );
					if ( ! empty( $branch_user_id ) && get_current_user_id() !== (int) $branch_user_id ) {
						$to_notify = false;
					}
				}

				if ( $to_notify ) {
					$order_id_to_notify = $order_id;
					break;
				}
			}

			$notification = '';
			if ( $order_id_to_notify ) {
				$notification               = self::build_payload( $order_id_to_notify );
				$notified_order_ids_array[] = $order_id_to_notify;
			}

			update_user_meta( $user_id, self::STATE_META, array_values( $notified_order_ids_array ) );

			return $notification;
		}

		/**
		 * Build the notification payload for a given order — title (branch aware),
		 * body, icon (branch image or default), sound and deep-link URL.
		 *
		 * @param int $order_id_to_notify Order to alert about.
		 * @return array<string,string>
		 */
		private static function build_payload( $order_id_to_notify ): array {
			$branch_id       = self::get_order_meta( $order_id_to_notify, 'lafka_selected_branch_id' );
			$branch_location = $branch_id ? get_term( $branch_id ) : null;
			$icon_url        = self::default_icon_url();

			if ( ! empty( $branch_location ) && ! is_wp_error( $branch_location ) ) {
				/* translators: %s: branch/location name. */
				$title           = sprintf( esc_html__( 'New Order for %s', 'lafka-plugin' ), esc_html( $branch_location->name ) );
				$branch_image_id = get_term_meta( $branch_id, 'lafka_branch_location_img_id', true );
				if ( $branch_image_id ) {
					$branch_image_src = wp_get_attachment_thumb_url( $branch_image_id );
					if ( $branch_image_src ) {
						$icon_url = esc_url_raw( $branch_image_src );
					}
				}
			} else {
				$title = esc_html__( 'New Order', 'lafka-plugin' );
			}

			return array(
				'title' => $title,
				'body'  => esc_html__( 'Order', 'lafka-plugin' ) . ' #' . esc_html( (string) $order_id_to_notify ) . ' ' . esc_html__( 'is waiting to be processed.', 'lafka-plugin' ),
				'icon'  => $icon_url,
				'sound' => self::default_sound_url(),
				'url'   => esc_url_raw( admin_url( 'post.php?post=' . $order_id_to_notify . '&action=edit' ) ),
			);
		}

		/**
		 * Default notification icon URL (plugin asset), filterable.
		 */
		private static function default_icon_url(): string {
			$url = plugins_url( 'assets/images/order-notification.png', __FILE__ );

			return (string) apply_filters( 'lafka_order_notifications_icon_url', esc_url_raw( $url ) );
		}

		/**
		 * Default notification sound URL (plugin asset), filterable.
		 */
		private static function default_sound_url(): string {
			$url = plugins_url( 'assets/images/cart_add.wav', __FILE__ );

			return (string) apply_filters( 'lafka_order_notifications_sound_url', esc_url_raw( $url ) );
		}

		/**
		 * Read order meta in an HPOS-safe way, preferring the plugin's canonical
		 * back-compat accessor and falling back to the WC_Order object.
		 *
		 * @param int    $order_id Order ID.
		 * @param string $meta_key Meta key to read.
		 * @return mixed Meta value, or '' when the order cannot be loaded.
		 */
		private static function get_order_meta( $order_id, $meta_key ) {
			if ( class_exists( 'Lafka_Shipping_Areas' ) && method_exists( 'Lafka_Shipping_Areas', 'get_order_meta_backward_compatible' ) ) {
				return Lafka_Shipping_Areas::get_order_meta_backward_compatible( $order_id, $meta_key );
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			return $order ? $order->get_meta( $meta_key ) : '';
		}

		/**
		 * Whether WooCommerce High-Performance Order Storage is active.
		 */
		private static function hpos_enabled(): bool {
			return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
	}

	Lafka_Order_Notifications::init();
}
