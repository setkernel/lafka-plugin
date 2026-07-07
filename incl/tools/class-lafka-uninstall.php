<?php
/**
 * Lafka_Uninstall — inventory-driven data cleanup for plugin uninstall (NX1-06).
 *
 * uninstall.php is a thin WP_UNINSTALL_PLUGIN-guarded bootstrap that requires
 * this file and calls Lafka_Uninstall::run(). All of the real logic lives here
 * as static, side-effect-free methods so it can be unit-tested without booting
 * WordPress (uninstall.php itself is impossible to execute under PHPUnit).
 *
 * Two behaviours, selected by the opt-in `lafka_delete_data_on_uninstall`
 * option (a checkbox on Lafka → Modules, default OFF):
 *
 *   - Toggle OFF (default) — the historical "minimal cleanup": revert custom
 *     product-attribute types back to 'select', drop the two conversion tables
 *     (abandoned carts + push subscriptions), and delete their version/marker
 *     options. Everything else the plugin ever wrote is left in place, so a
 *     re-install picks up exactly where the operator left off.
 *
 *   - Toggle ON — full inventory-driven cleanup layered on top of the minimal
 *     pass: every `lafka*` option (enumerated by prefix, deleted with prepared
 *     LIKE statements), the three Lafka CPTs' posts (force-deleted so their meta
 *     cascades), the `lafka_branch_location` + `lafka_foodmenu_category` terms
 *     (term meta cascades with the term), lafka-prefixed transients, and the
 *     plugin-owned product + user meta keys.
 *
 * Intentionally RETAINED even under the toggle: WooCommerce orders and their
 * order-item meta (`_lafka_kds_*`, `_lafka_addon_*`, `_lafka_dl_*`, …). Orders
 * are the merchant's financial records; a plugin uninstall must not rewrite the
 * books. See retained_meta_keys() for the documented list.
 *
 * KDS note: the KDS options (`lafka_kds_*`) ARE removed under the toggle —
 * uninstalling the plugin already kills the kitchen display, so clearing its
 * options changes nothing about the live-kitchen token contract. This class
 * never touches incl/kitchen-display/ token storage; it only deletes option
 * rows by name.
 *
 * @package Lafka\Plugin\Tools
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Uninstall' ) ) {

	final class Lafka_Uninstall {

		/**
		 * Opt-in flag: when '1', full_cleanup() runs on uninstall.
		 */
		const DATA_TOGGLE_OPTION = 'lafka_delete_data_on_uninstall';

		/**
		 * Whether the operator opted in to a complete data wipe on uninstall.
		 *
		 * @return bool
		 */
		public static function should_delete_all_data(): bool {
			if ( ! function_exists( 'get_option' ) ) {
				return false;
			}
			$stored = get_option( self::DATA_TOGGLE_OPTION, '0' );
			return '1' === ( is_scalar( $stored ) ? (string) $stored : '0' );
		}

		/**
		 * Entry point invoked by uninstall.php.
		 *
		 * @return void
		 */
		public static function run(): void {
			$delete_all = self::should_delete_all_data();

			// Minimal pass (historical behaviour — runs regardless of the toggle).
			self::revert_attribute_types();
			self::drop_tables();
			self::delete_conversion_markers();

			if ( $delete_all ) {
				self::full_cleanup();
			}
		}

		/**
		 * Complete inventory-driven cleanup (toggle ON only).
		 *
		 * @return void
		 */
		public static function full_cleanup(): void {
			self::delete_options();
			self::delete_cpt_posts();
			self::delete_terms();
			self::delete_transients();
			self::delete_meta();
		}

		// ─── Inventory (testable lists) ─────────────────────────────────────────

		/**
		 * Custom tables owned by the plugin, WITHOUT the $wpdb prefix.
		 *
		 * @return array<int,string>
		 */
		public static function tables(): array {
			return array(
				'lafka_abandoned_carts',
				'lafka_push_subscriptions',
			);
		}

		/**
		 * Lafka custom-post-type slugs whose posts (and cascading post meta) are
		 * removed under the toggle.
		 *
		 * @return array<int,string>
		 */
		public static function post_types(): array {
			return array(
				'lafka-foodmenu',       // menu presentation CPT
				'lafka_shipping_areas', // delivery-zone polygons CPT
				'lafka_glb_addon',      // global add-on groups CPT
			);
		}

		/**
		 * Lafka-owned taxonomies whose terms (and cascading term meta) are removed
		 * under the toggle. Deleting a term also deletes its term meta.
		 *
		 * @return array<int,string>
		 */
		public static function taxonomies(): array {
			return array(
				'lafka_branch_location',  // branches + ~19 per-branch term-meta keys
				'lafka_foodmenu_category', // menu categories
			);
		}

		/**
		 * Option names deleted by an exact match (no LIKE).
		 *
		 * @return array<int,string>
		 */
		public static function exact_options(): array {
			return array(
				'lafka',                          // the master flag/settings array
				'lafka_last_processed_order_ids', // KDS poller cursor
				self::DATA_TOGGLE_OPTION,         // the uninstall toggle itself
			);
		}

		/**
		 * Option-name prefixes deleted with prepared LIKE statements.
		 *
		 * Enumerated from a codebase grep so the completeness test can lock the
		 * list against the known option inventory. Add a prefix here whenever a
		 * new option family is introduced; UninstallCleanupTest fails until the
		 * inventory is covered.
		 *
		 * @return array<int,string>
		 */
		public static function option_prefixes(): array {
			return array(
				'lafka_business_',       // NAP / geo / hours / identity SSOT
				'lafka_restaurant',      // legacy restaurant-info + schema toggles
				'lafka_shipping_areas_', // delivery-area option groups
				'lafka_order_hours_',    // order-hours schedule
				'lafka_kds_',            // kitchen-display options + token activity
				'lafka_push_',           // web-push db version + activity log
				'lafka_abandoned_cart',  // abandoned-cart db version
				'lafka_dietary_tags_',   // dietary-tag seeding marker
				'lafka_first_order_',    // first-order promo
				'lafka_free_delivery_',  // free-delivery threshold
				'lafka_slow_day_',       // slow-day promo
				'lafka_share_on_',       // social-share toggles
				'lafka_homepage_hero_',  // hero attachment id
				'lafka_github_updates_', // self-updater bookkeeping (defensive)
				'lafka_contact_',        // contact-block options
				'lafka_promotions_',     // promo knobs + migration-notice dismissal
			);
		}

		/**
		 * Whether an option name would be removed by full_cleanup().
		 *
		 * The completeness test drives every known option name through this.
		 *
		 * @param string $name Option name.
		 * @return bool
		 */
		public static function option_matches( string $name ): bool {
			if ( '' === $name ) {
				return false;
			}
			if ( in_array( $name, self::exact_options(), true ) ) {
				return true;
			}
			foreach ( self::option_prefixes() as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Option-name prefixes for lafka-owned transients (transients are stored
		 * as options with these WordPress-internal prefixes).
		 *
		 * @return array<int,string>
		 */
		public static function transient_prefixes(): array {
			return array(
				'_transient_lafka_',
				'_transient_timeout_lafka_',
				'_site_transient_lafka_',
				'_site_transient_timeout_lafka_',
			);
		}

		/**
		 * Plugin-owned post meta keys removed under the toggle. These sit on
		 * products / posts (catalog configuration), NOT on orders — the products
		 * themselves survive (they are WooCommerce catalog), but their Lafka
		 * configuration should not linger.
		 *
		 * @return array<int,string>
		 */
		public static function deleted_post_meta_keys(): array {
			return array(
				'_lafka_variable_in_catalog',
				'_lafka_allergen_info',
				'_lafka_product_allergens',
				'_lafka_nutrition_panel_present',
				'_lafka_meta_description',
			);
		}

		/**
		 * Plugin-owned user meta keys removed under the toggle (opt-outs and
		 * dismissals — customer preferences, not order records).
		 *
		 * @return array<int,string>
		 */
		public static function deleted_user_meta_keys(): array {
			return array(
				'_lafka_review_banner_dismissed',
				'_lafka_review_email_optout',
				'_lafka_push_reorder_opt_out',
				'_lafka_notified_order_ids', // per-user new-order alert bookkeeping
			);
		}

		/**
		 * Meta keys intentionally NOT deleted. Documented for the retention test
		 * so the boundary is explicit: everything here rides a WooCommerce order
		 * or order item, which is a merchant record and must survive uninstall.
		 *
		 * @return array<int,string>
		 */
		public static function retained_meta_keys(): array {
			return array(
				'_lafka_addon_',            // order-item add-on selections
				'_lafka_kds_',              // kitchen-display order state
				'_lafka_dl_',               // dataLayer purchase attribution
				'_lafka_special_instructions', // per-order kitchen note
				'_lafka_review_email_sent', // order-level send guard
				'_lafka_push_reorder_sent_', // order-level send guard
				'_lafka_winback_email',     // order-level send guard
			);
		}

		// ─── Cleanup operations ─────────────────────────────────────────────────

		/**
		 * Revert custom (Lafka swatch) attribute types back to the WooCommerce
		 * default 'select' so removing the plugin doesn't leave orphaned types.
		 *
		 * Scoped to the three types Lafka itself registers (color/image/label —
		 * see incl/swatches/variation-swatches.php): a blanket "everything but
		 * text" reset would also clobber attribute types owned by unrelated
		 * plugins the moment Lafka is uninstalled.
		 *
		 * @return void
		 */
		public static function revert_attribute_types(): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return;
			}
			$table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
					"UPDATE {$table} SET attribute_type = %s WHERE attribute_type IN ( %s, %s, %s )",
					'select',
					'color',
					'image',
					'label'
				)
			);
		}

		/**
		 * Drop the two custom conversion tables. Runs on every uninstall (matches
		 * pre-NX1-06 behaviour: deactivation keeps the tables, uninstall drops
		 * the schema).
		 *
		 * @return void
		 */
		public static function drop_tables(): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
				return;
			}
			foreach ( self::tables() as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			}
		}

		/**
		 * Delete the conversion tables' version/marker options (part of the
		 * minimal pass). Under the toggle these are also covered by the option
		 * LIKE deletes; deleting them here keeps the toggle-OFF path complete.
		 *
		 * @return void
		 */
		public static function delete_conversion_markers(): void {
			if ( ! function_exists( 'delete_option' ) ) {
				return;
			}
			delete_option( 'lafka_abandoned_cart_db_version' );
			delete_option( 'lafka_push_db_version' );
			delete_option( 'lafka_push_activity_log' );
		}

		/**
		 * Delete every lafka* option: exact names via delete_option(), families
		 * via one prepared LIKE DELETE per prefix.
		 *
		 * @return void
		 */
		public static function delete_options(): void {
			global $wpdb;

			if ( function_exists( 'delete_option' ) ) {
				foreach ( self::exact_options() as $name ) {
					delete_option( $name );
				}
			}

			if ( ! isset( $wpdb ) || ! is_object( $wpdb )
				|| ! method_exists( $wpdb, 'query' )
				|| ! method_exists( $wpdb, 'prepare' )
				|| ! method_exists( $wpdb, 'esc_like' ) ) {
				return;
			}

			$options = $wpdb->options;
			foreach ( self::option_prefixes() as $prefix ) {
				$like = $wpdb->esc_like( $prefix ) . '%';
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a core-controlled table name.
						"DELETE FROM {$options} WHERE option_name LIKE %s",
						$like
					)
				);
			}
		}

		/**
		 * Delete lafka-prefixed transients (and their timeout twins), including
		 * the site-transient variants for multisite.
		 *
		 * @return void
		 */
		public static function delete_transients(): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb )
				|| ! method_exists( $wpdb, 'query' )
				|| ! method_exists( $wpdb, 'prepare' )
				|| ! method_exists( $wpdb, 'esc_like' ) ) {
				return;
			}
			$options = $wpdb->options;
			foreach ( self::transient_prefixes() as $prefix ) {
				$like = $wpdb->esc_like( $prefix ) . '%';
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a core-controlled table name.
						"DELETE FROM {$options} WHERE option_name LIKE %s",
						$like
					)
				);
			}
		}

		/**
		 * Force-delete every post of the Lafka CPTs. force = true bypasses the
		 * trash and cascades post meta + term relationships.
		 *
		 * @return void
		 */
		public static function delete_cpt_posts(): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb )
				|| ! method_exists( $wpdb, 'get_col' )
				|| ! method_exists( $wpdb, 'prepare' )
				|| ! function_exists( 'wp_delete_post' ) ) {
				return;
			}
			$posts = $wpdb->posts;
			foreach ( self::post_types() as $post_type ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a core-controlled table name.
						"SELECT ID FROM {$posts} WHERE post_type = %s",
						$post_type
					)
				);
				if ( ! is_array( $ids ) ) {
					continue;
				}
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
			}
		}

		/**
		 * Delete every term in the Lafka-owned taxonomies. wp_delete_term()
		 * cascades the term's meta.
		 *
		 * @return void
		 */
		public static function delete_terms(): void {
			if ( ! function_exists( 'get_terms' ) || ! function_exists( 'wp_delete_term' ) ) {
				return;
			}
			foreach ( self::taxonomies() as $taxonomy ) {
				$term_ids = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				);
				if ( ! is_array( $term_ids ) ) {
					continue;
				}
				foreach ( $term_ids as $term_id ) {
					wp_delete_term( (int) $term_id, $taxonomy );
				}
			}
		}

		/**
		 * Delete plugin-owned product/user meta by key. Order + order-item meta is
		 * intentionally skipped (see retained_meta_keys()).
		 *
		 * @return void
		 */
		public static function delete_meta(): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return;
			}

			$postmeta = $wpdb->postmeta;
			foreach ( self::deleted_post_meta_keys() as $key ) {
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a core-controlled table name.
						"DELETE FROM {$postmeta} WHERE meta_key = %s",
						$key
					)
				);
			}

			$usermeta = $wpdb->usermeta;
			foreach ( self::deleted_user_meta_keys() as $key ) {
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->usermeta is a core-controlled table name.
						"DELETE FROM {$usermeta} WHERE meta_key = %s",
						$key
					)
				);
			}
		}
	}
}
