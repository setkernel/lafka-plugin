<?php
/**
 * Lafka_Checkout_Fields — order_type + branch on the block checkout (NX1-04b).
 *
 * Registers Lafka's two ordering selects on WooCommerce's block Checkout through
 * the Additional Checkout Fields API (location 'order'), shown conditionally to
 * mirror the classic branch selector:
 *
 *   · order_type (delivery / pickup) — when the shipping-areas module is on and
 *     the site enables more than one order type.
 *   · branch     (branch location)   — when the shipping-areas module is on and
 *     more than one branch exists.
 *
 * The field VALUES round into the SAME WC session / order-meta state the classic
 * checkout uses. When the block persists a field value, sync_field_to_session()
 * writes the `lafka_branch_location` session array (branch_id + order_type) — the
 * exact structure the classic AJAX select_branch endpoint writes. NX1-04a's
 * on_checkout_update_order_from_request() then reads that session and calls the
 * classic order-meta writer (Lafka_Branch_Locations::checkout_field_update_order_meta_fields),
 * so KDS, branch routing and analytics see byte-identical `lafka_order_type` /
 * `lafka_selected_branch_id` meta on a block order and a classic order.
 *
 * Server is the authority: the NX1-04a gate (woocommerce_store_api_cart_errors)
 * re-validates the resulting session at checkout, so a stale or crafted field
 * value can never place an order that violates the branch/order-type allow-list.
 *
 * @package Lafka\Plugin\Checkout
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Checkout_Fields' ) ) {

	/**
	 * Block checkout order_type + branch fields, wired to the classic session.
	 */
	final class Lafka_Checkout_Fields {

		/**
		 * Additional Checkout Field id for the order type select.
		 */
		const FIELD_ORDER_TYPE = 'lafka/order-type';

		/**
		 * Additional Checkout Field id for the branch select.
		 */
		const FIELD_BRANCH = 'lafka/branch';

		/**
		 * WC session key holding { branch_id, order_type } — the classic SSOT.
		 */
		const BRANCH_SESSION_KEY = 'lafka_branch_location';

		/**
		 * Branch taxonomy.
		 */
		const BRANCH_TAXONOMY = 'lafka_branch_location';

		/**
		 * Register the fields + the session-sync hook once WooCommerce is ready.
		 * Only fires in blocks mode (the classic selector owns this in classic
		 * mode) and only when the Additional Checkout Fields API is present.
		 *
		 * Hooked to `init` priority 20 — AFTER the shipping-areas module registers
		 * the `lafka_branch_location` taxonomy (init:10). Registering earlier (e.g.
		 * on woocommerce_init, which fires before init:10) would build the branch
		 * select from an unregistered taxonomy → empty options → the branch field
		 * would silently fail to register. WooCommerce Blocks has long since loaded
		 * by init, so woocommerce_register_additional_checkout_field runs directly.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register' ), 20 );
		}

		/**
		 * Register the conditional fields and wire the value→session sync.
		 *
		 * @return void
		 */
		public static function register() {
			if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
				return;
			}
			if ( ! class_exists( 'Lafka_Checkout_Mode' ) || ! Lafka_Checkout_Mode::is_blocks() ) {
				return;
			}

			if ( self::should_show_order_type_field() ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'                         => self::FIELD_ORDER_TYPE,
						'label'                      => __( 'Order type', 'lafka-plugin' ),
						'location'                   => 'order',
						'type'                       => 'select',
						'required'                   => true,
						'options'                    => self::get_order_type_options(),
						'show_in_order_confirmation' => false,
					)
				);
			}

			if ( self::should_show_branch_field() ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'                         => self::FIELD_BRANCH,
						'label'                      => __( 'Branch', 'lafka-plugin' ),
						'location'                   => 'order',
						'type'                       => 'select',
						'required'                   => true,
						'options'                    => self::get_branch_options(),
						'show_in_order_confirmation' => false,
					)
				);
			}

			add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'sync_field_to_session' ), 10, 4 );
		}

		/* --------------------------------------------------------------------- *
		 *  Conditional display
		 * --------------------------------------------------------------------- */

		/**
		 * Whether Lafka collects branch / order-type at all — the exact gate the
		 * classic path uses. The classic branch selector (and its order_type/branch
		 * session writes) only run when the shipping-areas module is on AND the
		 * operator turned on the branch-selection modal
		 * (`lafka_shipping_areas_branches[enable_branch_selection_modal]`, which also
		 * loads Lafka_Branch_Locations). Gating the block fields on the SAME switch
		 * keeps a block order and a classic order carrying identical order meta: when
		 * the operator does not collect branch/order-type, neither path does.
		 *
		 * @return bool
		 */
		public static function is_branch_selection_active(): bool {
			if ( ! function_exists( 'is_lafka_shipping_areas' ) || ! is_lafka_shipping_areas( get_option( 'lafka' ) ) ) {
				return false;
			}
			$branches = get_option( 'lafka_shipping_areas_branches' );

			return is_array( $branches ) && ! empty( $branches['enable_branch_selection_modal'] );
		}

		/**
		 * Show the order-type field only when branch selection is active AND the site
		 * offers more than one order type (a single type is implicit — nothing to
		 * choose).
		 *
		 * @return bool
		 */
		public static function should_show_order_type_field(): bool {
			if ( ! self::is_branch_selection_active() ) {
				return false;
			}

			return count( self::get_site_order_types() ) > 1;
		}

		/**
		 * Show the branch field only when branch selection is active AND more than one
		 * branch exists (mirrors the classic "pick a branch" step, which is a no-op
		 * with a single branch).
		 *
		 * @return bool
		 */
		public static function should_show_branch_field(): bool {
			if ( ! self::is_branch_selection_active() ) {
				return false;
			}

			return count( self::get_branch_terms() ) > 1;
		}

		/* --------------------------------------------------------------------- *
		 *  Field options
		 * --------------------------------------------------------------------- */

		/**
		 * Site-wide enabled order types (delivery / pickup), via the same helper the
		 * classic path reads.
		 *
		 * @return string[]
		 */
		public static function get_site_order_types(): array {
			if ( ! class_exists( 'Lafka_Branch_Locations' ) ) {
				return array();
			}
			$types = Lafka_Branch_Locations::get_order_type();

			return is_array( $types ) ? $types : array();
		}

		/**
		 * Order-type select options in the Additional Checkout Fields shape.
		 *
		 * @return array<int,array{value:string,label:string}>
		 */
		public static function get_order_type_options(): array {
			$labels = array(
				'delivery' => __( 'Delivery', 'lafka-plugin' ),
				'pickup'   => __( 'Pickup', 'lafka-plugin' ),
			);

			$options = array();
			foreach ( self::get_site_order_types() as $type ) {
				if ( isset( $labels[ $type ] ) ) {
					$options[] = array(
						'value' => (string) $type,
						'label' => $labels[ $type ],
					);
				}
			}

			return $options;
		}

		/**
		 * All branch terms as id=>name (used for both the display gate and options).
		 * Branch enumeration is intentionally taxonomy-wide (not the geocoded
		 * "legit" subset) so a single un-geocoded branch still round-trips its id.
		 *
		 * @return array<int,string>
		 */
		public static function get_branch_terms(): array {
			if ( ! function_exists( 'get_terms' ) ) {
				return array();
			}
			$terms = get_terms(
				array(
					'taxonomy'   => self::BRANCH_TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'id=>name',
				)
			);

			return is_array( $terms ) ? $terms : array();
		}

		/**
		 * Branch select options in the Additional Checkout Fields shape.
		 *
		 * @return array<int,array{value:string,label:string}>
		 */
		public static function get_branch_options(): array {
			$options = array();
			foreach ( self::get_branch_terms() as $id => $name ) {
				$options[] = array(
					'value' => (string) (int) $id,
					'label' => (string) $name,
				);
			}

			return $options;
		}

		/* --------------------------------------------------------------------- *
		 *  Value → session sync
		 * --------------------------------------------------------------------- */

		/**
		 * When the block checkout persists one of Lafka's fields, mirror it into the
		 * classic `lafka_branch_location` session array so the shared order-meta
		 * writer and the NX1-04a gate both see it. Non-Lafka fields are ignored.
		 *
		 * @param string $key       Field id being set (e.g. 'lafka/order-type').
		 * @param mixed  $value     Submitted field value.
		 * @param string $group     Field group (unused).
		 * @param mixed  $wc_object WC_Order or WC_Customer receiving the field (unused).
		 * @return void
		 */
		public static function sync_field_to_session( $key, $value, $group = 'other', $wc_object = null ) {
			unset( $group, $wc_object );

			if ( self::FIELD_ORDER_TYPE !== $key && self::FIELD_BRANCH !== $key ) {
				return;
			}

			$session = self::get_branch_session();

			if ( self::FIELD_ORDER_TYPE === $key ) {
				$session['order_type'] = sanitize_text_field( (string) $value );
			} else {
				$session['branch_id'] = (int) $value;
			}

			// Default the branch on a single-branch site (its field is hidden) so the
			// order-meta writer, which only persists order_type when branch_id > 0,
			// still records both. Uses the resolved single branch id.
			if ( empty( $session['branch_id'] ) ) {
				$single = self::single_branch_id();
				if ( $single > 0 ) {
					$session['branch_id'] = $single;
				}
			}

			self::set_branch_session( $session );
		}

		/**
		 * The lone branch id when exactly one branch exists, else 0.
		 *
		 * @return int
		 */
		public static function single_branch_id(): int {
			$terms = self::get_branch_terms();
			if ( 1 !== count( $terms ) ) {
				return 0;
			}
			$ids = array_keys( $terms );

			return (int) reset( $ids );
		}

		/* --------------------------------------------------------------------- *
		 *  Session helpers (guarded — no-op without a WC session)
		 * --------------------------------------------------------------------- */

		/**
		 * Read the branch selection array from the WC session.
		 *
		 * @return array
		 */
		private static function get_branch_session(): array {
			if ( ! function_exists( 'WC' ) ) {
				return array();
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return array();
			}
			$session = $wc->session->get( self::BRANCH_SESSION_KEY );

			return is_array( $session ) ? $session : array();
		}

		/**
		 * Write the branch selection array to the WC session.
		 *
		 * @param array $session Branch session array.
		 * @return void
		 */
		private static function set_branch_session( array $session ) {
			if ( ! function_exists( 'WC' ) ) {
				return;
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return;
			}
			$wc->session->set( self::BRANCH_SESSION_KEY, $session );
		}
	}
}
