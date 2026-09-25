<?php
/**
 * Menu items marked Virtual: Site Health check, product-screen notice and
 * the backend of `wp lafka products unvirtual`.
 *
 * WooCommerce treats a Virtual product as needing no shipping. A cart that
 * holds only virtual items therefore gets no shipping rates: the customer is
 * never asked pickup or delivery, the billing address stays required (the
 * short pickup checkout never applies) and no delivery fee is charged. Stores
 * migrated from other ordering plugins often have their deals and combos
 * ticked Virtual; food should essentially never be.
 *
 *   Site Health → "Menu items that skip pickup and delivery" (recommended):
 *     the count, up to ten linked titles, and what to do.
 *   Product edit screen: a one-line warning on a flagged, published product,
 *     with a per-product "this item is meant to be virtual" link (post meta
 *     `_lafka_virtual_ok`), which also drops it from Site Health.
 *   `wp lafka products unvirtual` (incl/cli/lafka-products-cli.php): plan() +
 *     apply() below.
 *
 * Checks run only while the store offers pickup or delivery
 * (lafka_fulfilment_modes(); without it, WooCommerce shipping enabled).
 *
 * Filters:
 *   `lafka_virtual_items_check_enabled` (bool)   force the checks on/off.
 *   `lafka_virtual_ok_product_ids` (int[])        products (or variations) that
 *                                                 are meant to be virtual.
 *   `lafka_virtual_ok_terms` (array)              taxonomy => term slugs/ids
 *     whose products are meant to be virtual, e.g.
 *     array( 'product_cat' => array( 'gift-cards' ) ). Default: the product
 *     types of common gift-card plugins. Downloadable items are always exempt.
 *
 * @package Lafka\Plugin\SiteHealth
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Virtual_Menu_Items' ) ) {

	/**
	 * Finds and reports menu items that skip pickup and delivery.
	 */
	final class Lafka_Virtual_Menu_Items {

		/** Site Health test id. */
		const TEST_ID = 'lafka_virtual_menu_items';

		/** Transient caching flagged_items(). */
		const CACHE_KEY = 'lafka_virtual_menu_items';

		/** Post meta: the operator says this item is meant to be virtual. */
		const META_OK = '_lafka_virtual_ok';

		/** admin-post action (and nonce action prefix) of the notice link. */
		const DISMISS_ACTION = 'lafka_virtual_ok';

		/** Most titles listed in Site Health. */
		const LIST_LIMIT = 10;

		/**
		 * Wire Site Health, the notice, its dismissal and the cache busting.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'site_status_tests', array( __CLASS__, 'register_test' ) );
			add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
			add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );

			foreach ( array(
				'woocommerce_new_product',
				'woocommerce_update_product',
				'woocommerce_new_product_variation',
				'woocommerce_update_product_variation',
				'save_post_product',
				'save_post_product_variation',
			) as $hook ) {
				add_action( $hook, array( __CLASS__, 'flush' ) );
			}
			foreach ( array( 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'flush_for_post' ), 10, 2 );
			}
		}

		/**
		 * Whether the store offers pickup or delivery (so Virtual matters).
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			if ( function_exists( 'lafka_fulfilment_modes' ) ) {
				$active = array() !== lafka_fulfilment_modes();
			} else {
				$active = function_exists( 'wc_shipping_enabled' ) && wc_shipping_enabled();
			}

			/**
			 * Filter whether Lafka checks for menu items marked Virtual.
			 *
			 * @since 10.2.0
			 * @param bool $active True when the store offers pickup or delivery.
			 */
			return (bool) apply_filters( 'lafka_virtual_items_check_enabled', $active );
		}

		// ─── Collector ───────────────────────────────────────────────────

		/**
		 * Product types to query: the registered ones, any legacy type still
		 * assigned to products (a migrated store's `combo`), and variations.
		 *
		 * @return string[]
		 */
		private static function query_types(): array {
			$types  = function_exists( 'wc_get_product_types' ) ? array_keys( (array) wc_get_product_types() ) : array( 'simple', 'variable' );
			$in_use = get_terms(
				array(
					'taxonomy'   => 'product_type',
					'hide_empty' => true,
					'fields'     => 'slugs',
				)
			);
			if ( is_array( $in_use ) && ! is_wp_error( $in_use ) ) {
				$types = array_merge( $types, array_map( 'strval', $in_use ) );
			}
			$types[] = 'variation';
			return array_values( array_unique( $types ) );
		}

		/**
		 * Published products and variations marked Virtual.
		 *
		 * @return int[]
		 */
		private static function virtual_ids(): array {
			if ( ! function_exists( 'wc_get_products' ) ) {
				return array();
			}
			$ids = wc_get_products(
				array(
					'virtual' => true,
					'status'  => 'publish',
					'type'    => self::query_types(),
					'limit'   => -1,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
		}

		/**
		 * The menu item (product) an id belongs to: a variation's parent.
		 *
		 * @param int $id Product or variation id.
		 * @return int
		 */
		private static function item_of( int $id ): int {
			$parent = (int) wp_get_post_parent_id( $id );
			return $parent > 0 ? $parent : $id;
		}

		/**
		 * Whether a virtual id is meant to be virtual (or not on the menu).
		 *
		 * @param int $id   Product or variation id.
		 * @param int $item Its menu item.
		 * @return bool
		 */
		private static function is_exempt( int $id, int $item ): bool {
			if ( $item !== $id && 'publish' !== get_post_status( $item ) ) {
				return true; // Variation of a product that is not on the menu.
			}
			if ( 'yes' === get_post_meta( $id, '_downloadable', true ) || '' !== (string) get_post_meta( $item, self::META_OK, true ) ) {
				return true;
			}

			/**
			 * Filter the products (or variations) that are meant to be virtual.
			 *
			 * @since 10.2.0
			 * @param int[] $ids Product / variation ids.
			 */
			$ok_ids = array_map( 'intval', (array) apply_filters( 'lafka_virtual_ok_product_ids', array() ) );
			if ( in_array( $id, $ok_ids, true ) || in_array( $item, $ok_ids, true ) ) {
				return true;
			}

			/**
			 * Filter the terms whose products are meant to be virtual.
			 *
			 * @since 10.2.0
			 * @param array<string,array<int|string>> $terms Taxonomy => term slugs or ids.
			 */
			$ok_terms = (array) apply_filters(
				'lafka_virtual_ok_terms',
				array( 'product_type' => array( 'gift-card', 'pw-gift-card' ) )
			);
			foreach ( $ok_terms as $taxonomy => $terms ) {
				if ( ! empty( $terms ) && has_term( (array) $terms, (string) $taxonomy, $item ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Uncached scan: menu item id => its virtual product/variation ids.
		 *
		 * @return array<int,int[]>
		 */
		private static function scan(): array {
			$items = array();
			foreach ( self::virtual_ids() as $id ) {
				$item = self::item_of( $id );
				if ( ! self::is_exempt( $id, $item ) ) {
					$items[ $item ][] = $id;
				}
			}
			return $items;
		}

		/**
		 * Menu items that skip pickup and delivery (cached until a product
		 * changes): item id => its virtual product/variation ids.
		 *
		 * @return array<int,int[]>
		 */
		public static function flagged_items(): array {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['items'] ) && is_array( $cached['items'] ) ) {
				return $cached['items'];
			}
			$items = self::scan();
			set_transient( self::CACHE_KEY, array( 'items' => $items ), DAY_IN_SECONDS );
			return $items;
		}

		/**
		 * Drop the cache.
		 *
		 * @return void
		 */
		public static function flush() {
			delete_transient( self::CACHE_KEY );
		}

		/**
		 * Drop the cache when a product or variation is trashed/restored/deleted.
		 *
		 * @param int          $post_id Post id.
		 * @param WP_Post|null $post    Post (deleted_post passes it; the row is gone).
		 * @return void
		 */
		public static function flush_for_post( $post_id, $post = null ) {
			$type = is_object( $post ) && isset( $post->post_type ) ? $post->post_type : get_post_type( (int) $post_id );
			if ( 'product' === $type || 'product_variation' === $type ) {
				self::flush();
			}
		}

		// ─── Site Health ─────────────────────────────────────────────────

		/**
		 * `site_status_tests` callback.
		 *
		 * @param array<string,array> $tests Tests.
		 * @return array<string,array>
		 */
		public static function register_test( $tests ) {
			if ( self::enabled() ) {
				$tests['direct'][ self::TEST_ID ] = array(
					'label' => esc_html__( 'Menu items that skip pickup and delivery', 'lafka-plugin' ),
					'test'  => array( __CLASS__, 'test' ),
				);
			}
			return $tests;
		}

		/**
		 * The guidance line shared by Site Health and the notice.
		 *
		 * @return string
		 */
		private static function guidance(): string {
			return __( 'Untick Virtual so customers can choose pickup or delivery (Product data → General; for a variable product, on each variation).', 'lafka-plugin' );
		}

		/**
		 * Site Health result.
		 *
		 * @return array<string,mixed>
		 */
		public static function test() {
			$items = self::flagged_items();
			$count = count( $items );
			$badge = array(
				'label' => esc_html__( 'Ordering', 'lafka-plugin' ),
				'color' => 0 === $count ? 'blue' : 'orange',
			);

			if ( 0 === $count ) {
				return array(
					'label'       => esc_html__( 'Every menu item offers pickup and delivery', 'lafka-plugin' ),
					'status'      => 'good',
					'badge'       => $badge,
					'description' => '<p>' . esc_html__( 'No published menu item is marked Virtual, so every order asks the customer for pickup or delivery.', 'lafka-plugin' ) . '</p>',
					'actions'     => '',
					'test'        => self::TEST_ID,
				);
			}

			$list = '';
			foreach ( array_slice( array_keys( $items ), 0, self::LIST_LIMIT ) as $item ) {
				$list .= '<li><a href="' . esc_url( (string) get_edit_post_link( $item, 'raw' ) ) . '">' . esc_html( (string) get_the_title( $item ) ) . '</a></li>';
			}
			$more = '';
			if ( $count > self::LIST_LIMIT ) {
				/* translators: %d: number of further menu items. */
				$more = '<p>' . esc_html( sprintf( __( '…and %d more.', 'lafka-plugin' ), $count - self::LIST_LIMIT ) ) . '</p>';
			}

			$description  = '<p>' . esc_html__( 'These menu items are marked Virtual. WooCommerce ships nothing for a virtual item, so an order made only of them never asks pickup or delivery, still asks for the full billing address, and charges no delivery fee.', 'lafka-plugin' ) . '</p>';
			$description .= '<ul>' . $list . '</ul>' . $more;
			$description .= '<p>' . esc_html( self::guidance() ) . '</p>';
			$description .= '<p>' . esc_html__( 'To fix them all at once: wp lafka products unvirtual (shows the changes; add --yes to apply). If an item really is digital (a gift card, say), open it and choose "This item is meant to be virtual".', 'lafka-plugin' ) . '</p>';

			return array(
				'label'       => esc_html(
					sprintf(
						/* translators: %d: number of menu items. */
						_n( '%d menu item skips pickup and delivery', '%d menu items skip pickup and delivery', $count, 'lafka-plugin' ),
						$count
					)
				),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => $description,
				'actions'     => '',
				'test'        => self::TEST_ID,
			);
		}

		// ─── Product edit screen ─────────────────────────────────────────

		/**
		 * One-line warning on a flagged, published product's edit screen.
		 *
		 * @return void
		 */
		public static function render_notice() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! is_object( $screen ) || 'post' !== ( $screen->base ?? '' ) || 'product' !== ( $screen->post_type ?? '' ) ) {
				return;
			}
			$post = get_post();
			if ( ! is_object( $post ) || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
				return;
			}
			$id = (int) $post->ID;
			if ( ! current_user_can( 'edit_post', $id ) || ! self::enabled() ) {
				return;
			}
			if ( ! array_key_exists( $id, self::flagged_items() ) ) {
				return;
			}

			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::DISMISS_ACTION,
						'post'   => $id,
					),
					admin_url( 'admin-post.php' )
				),
				self::DISMISS_ACTION . '_' . $id
			);

			printf(
				'<div class="notice notice-warning"><p>%1$s %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'This item is marked Virtual, so an order of only this item skips pickup and delivery.', 'lafka-plugin' ),
				esc_html( self::guidance() ),
				esc_url( $url ),
				esc_html__( 'This item is meant to be virtual', 'lafka-plugin' )
			);
		}

		/**
		 * admin-post handler: remember that this product is meant to be virtual.
		 *
		 * @return void
		 */
		public static function handle_dismiss() {
			$id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() below, once the id is known.
			if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
				wp_die( esc_html__( 'You are not allowed to edit this item.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::DISMISS_ACTION . '_' . $id );

			update_post_meta( $id, self::META_OK, '1' );
			self::flush();

			$back = (string) get_edit_post_link( $id, 'raw' );
			wp_safe_redirect( '' !== $back ? $back : admin_url( 'edit.php?post_type=product' ) );
			exit;
		}

		// ─── Unvirtual (WP-CLI backend) ──────────────────────────────────

		/**
		 * What `unvirtual` would change: every flagged item, or the given ids.
		 *
		 * A given product id covers its virtual variations. Given ids skip the
		 * exemptions (the operator named them); ids that are not published and
		 * virtual are left out.
		 *
		 * @param int[] $ids Product / variation ids; empty = every flagged item.
		 * @return list<array{id:int,item:int,name:string}>
		 */
		public static function plan( array $ids ): array {
			$ids  = array_map( 'intval', $ids );
			$rows = array();
			if ( array() === $ids ) {
				foreach ( self::scan() as $item => $virtual ) {
					foreach ( $virtual as $id ) {
						$rows[] = self::row( $id, $item );
					}
				}
				return $rows;
			}
			foreach ( self::virtual_ids() as $id ) {
				$item = self::item_of( $id );
				if ( in_array( $id, $ids, true ) || in_array( $item, $ids, true ) ) {
					$rows[] = self::row( $id, $item );
				}
			}
			return $rows;
		}

		/**
		 * One plan row.
		 *
		 * @param int $id   Product / variation id.
		 * @param int $item Menu item id.
		 * @return array{id:int,item:int,name:string}
		 */
		private static function row( int $id, int $item ): array {
			return array(
				'id'   => $id,
				'item' => $item,
				'name' => (string) get_the_title( $id ),
			);
		}

		/**
		 * Untick Virtual on the planned rows through the product CRUD.
		 *
		 * @param list<array{id:int}> $rows Rows from plan().
		 * @return int Items changed.
		 */
		public static function apply( array $rows ): int {
			$changed = 0;
			foreach ( $rows as $row ) {
				$product = wc_get_product( (int) $row['id'] );
				if ( ! is_object( $product ) || ! $product->is_virtual() ) {
					continue;
				}
				$product->set_virtual( false );
				$product->save();
				++$changed;
			}
			self::flush();
			return $changed;
		}
	}
}
