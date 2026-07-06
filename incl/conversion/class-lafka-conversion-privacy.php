<?php
/**
 * Lafka_Conversion_Privacy — GDPR exporter + eraser for the conversion tables
 * that hold personal data (NX1-06).
 *
 * Two data stores carry customer personal data outside the WooCommerce order
 * record, so they need their own WP privacy hooks:
 *
 *   - Web-push subscriptions (`wp_lafka_push_subscriptions`) — endpoint URL,
 *     browser user-agent, locale. The table keys on endpoint + user_id, so a
 *     subject-request email is resolved to a WP user and matched by user_id.
 *     Guest subscriptions (user_id NULL) cannot be matched from an email alone;
 *     they are pruned by the module's own 60-day soft-delete cleanup.
 *
 *   - Abandoned carts (`wp_lafka_abandoned_carts`) — customer email + a cart
 *     snapshot. Matched directly on the email column.
 *
 * Export follows core's data-group contract; erase hard-deletes matching rows.
 * Both callbacks page in batches of 50 and report `done` for core's paging loop.
 *
 * Registered on the `wp_privacy_personal_data_exporters` /
 * `wp_privacy_personal_data_erasers` filters from lafka-plugin.php. Mirrors the
 * addon-engine privacy contract (incl/addons/engine/class-engine-privacy.php).
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Conversion_Privacy' ) ) {

	class Lafka_Conversion_Privacy {

		const EXPORTER_PUSH = 'lafka-push-subscriptions';
		const EXPORTER_AC   = 'lafka-abandoned-carts';
		const PAGE_SIZE     = 50;

		/**
		 * Wire the exporter + eraser onto the WP privacy filters.
		 *
		 * @return void
		 */
		public function register(): void {
			add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		}

		/**
		 * @param array $exporters
		 * @return array
		 */
		public function register_exporters( array $exporters ): array {
			$exporters[ self::EXPORTER_PUSH ] = array(
				'exporter_friendly_name' => __( 'Lafka Web-Push Subscriptions', 'lafka-plugin' ),
				'callback'               => array( $this, 'export_push' ),
			);
			$exporters[ self::EXPORTER_AC ] = array(
				'exporter_friendly_name' => __( 'Lafka Abandoned Carts', 'lafka-plugin' ),
				'callback'               => array( $this, 'export_abandoned_carts' ),
			);
			return $exporters;
		}

		/**
		 * @param array $erasers
		 * @return array
		 */
		public function register_erasers( array $erasers ): array {
			$erasers[ self::EXPORTER_PUSH ] = array(
				'eraser_friendly_name' => __( 'Lafka Web-Push Subscriptions', 'lafka-plugin' ),
				'callback'             => array( $this, 'erase_push' ),
			);
			$erasers[ self::EXPORTER_AC ] = array(
				'eraser_friendly_name' => __( 'Lafka Abandoned Carts', 'lafka-plugin' ),
				'callback'             => array( $this, 'erase_abandoned_carts' ),
			);
			return $erasers;
		}

		// ─── Web-push subscriptions ─────────────────────────────────────────────

		/**
		 * Export push subscriptions owned by the email's WP user.
		 *
		 * @param string $email_address
		 * @param int    $page 1-indexed.
		 * @return array{data:array,done:bool}
		 */
		public function export_push( string $email_address, int $page = 1 ): array {
			$data = array();
			$rows = $this->get_push_rows_for_email( $email_address, $page );

			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => 'lafka_push_subscriptions',
					'group_label' => __( 'Web-Push Subscriptions', 'lafka-plugin' ),
					'item_id'     => 'lafka-push-' . (int) ( $row->id ?? 0 ),
					'data'        => array(
						array(
							'name'  => __( 'Endpoint', 'lafka-plugin' ),
							'value' => (string) ( $row->endpoint ?? '' ),
						),
						array(
							'name'  => __( 'Browser (user agent)', 'lafka-plugin' ),
							'value' => (string) ( $row->user_agent ?? '' ),
						),
						array(
							'name'  => __( 'Locale', 'lafka-plugin' ),
							'value' => (string) ( $row->locale ?? '' ),
						),
						array(
							'name'  => __( 'Subscribed', 'lafka-plugin' ),
							'value' => (string) ( $row->created_at ?? '' ),
						),
						array(
							'name'  => __( 'Last seen', 'lafka-plugin' ),
							'value' => (string) ( $row->last_seen_at ?? '' ),
						),
					),
				);
			}

			return array(
				'data' => $data,
				'done' => count( $rows ) < self::PAGE_SIZE,
			);
		}

		/**
		 * Hard-delete every push subscription owned by the email's WP user.
		 *
		 * @param string $email_address
		 * @param int    $page 1-indexed (unused — a single DELETE clears all rows).
		 * @return array{items_removed:int,items_retained:bool,messages:array,done:bool}
		 */
		public function erase_push( string $email_address, int $page = 1 ): array {
			global $wpdb;
			$removed = 0;
			$user_id = $this->user_id_for_email( $email_address );

			if ( $user_id > 0 && isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
				$deleted = $wpdb->delete(
					$this->push_table(),
					array( 'user_id' => $user_id ),
					array( '%d' )
				);
				$removed = is_numeric( $deleted ) ? (int) $deleted : 0;
			}

			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// ─── Abandoned carts ────────────────────────────────────────────────────

		/**
		 * Export abandoned carts matched on the email column.
		 *
		 * @param string $email_address
		 * @param int    $page 1-indexed.
		 * @return array{data:array,done:bool}
		 */
		public function export_abandoned_carts( string $email_address, int $page = 1 ): array {
			$data = array();
			$rows = $this->get_ac_rows_for_email( $email_address, $page );

			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => 'lafka_abandoned_carts',
					'group_label' => __( 'Abandoned Carts', 'lafka-plugin' ),
					'item_id'     => 'lafka-ac-' . (int) ( $row->id ?? 0 ),
					'data'        => array(
						array(
							'name'  => __( 'Email', 'lafka-plugin' ),
							'value' => (string) ( $row->customer_email ?? '' ),
						),
						array(
							'name'  => __( 'Cart summary', 'lafka-plugin' ),
							'value' => $this->summarise_cart( $row ),
						),
						array(
							'name'  => __( 'Cart total', 'lafka-plugin' ),
							'value' => trim( (string) ( $row->cart_total ?? '' ) . ' ' . (string) ( $row->currency ?? '' ) ),
						),
						array(
							'name'  => __( 'Created', 'lafka-plugin' ),
							'value' => (string) ( $row->created_at ?? '' ),
						),
						array(
							'name'  => __( 'Last seen', 'lafka-plugin' ),
							'value' => (string) ( $row->last_seen_at ?? '' ),
						),
						array(
							'name'  => __( 'Recovery email sent', 'lafka-plugin' ),
							'value' => (string) ( $row->recovery_sent_at ?? '' ),
						),
					),
				);
			}

			return array(
				'data' => $data,
				'done' => count( $rows ) < self::PAGE_SIZE,
			);
		}

		/**
		 * Hard-delete every abandoned cart for the email. Reuses the module's
		 * existing right-to-be-forgotten helper.
		 *
		 * @param string $email_address
		 * @param int    $page 1-indexed (unused — the helper clears all rows).
		 * @return array{items_removed:int,items_retained:bool,messages:array,done:bool}
		 */
		public function erase_abandoned_carts( string $email_address, int $page = 1 ): array {
			$removed = 0;
			if ( '' !== $email_address && function_exists( 'lafka_ac_delete_by_email' ) ) {
				$removed = (int) lafka_ac_delete_by_email( $email_address );
			}
			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// ─── Helpers ────────────────────────────────────────────────────────────

		/**
		 * @param string $email
		 * @param int    $page
		 * @return array<int,object>
		 */
		private function get_push_rows_for_email( string $email, int $page ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return array();
			}
			$user_id = $this->user_id_for_email( $email );
			if ( $user_id <= 0 ) {
				return array();
			}
			$table  = $this->push_table();
			$offset = ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name derived from $wpdb->prefix.
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
					$user_id,
					self::PAGE_SIZE,
					$offset
				)
			);
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * @param string $email
		 * @param int    $page
		 * @return array<int,object>
		 */
		private function get_ac_rows_for_email( string $email, int $page ): array {
			global $wpdb;
			if ( '' === $email || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return array();
			}
			$table  = $this->ac_table();
			$offset = ( max( 1, $page ) - 1 ) * self::PAGE_SIZE;
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name derived from $wpdb->prefix.
					"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$email,
					self::PAGE_SIZE,
					$offset
				)
			);
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Resolve an email to a WP user ID, or 0 when no account matches.
		 *
		 * @param string $email
		 * @return int
		 */
		private function user_id_for_email( string $email ): int {
			if ( '' === $email || ! function_exists( 'get_user_by' ) ) {
				return 0;
			}
			$user = get_user_by( 'email', $email );
			return ( $user && isset( $user->ID ) ) ? (int) $user->ID : 0;
		}

		/**
		 * A short, non-sensitive summary of the stored cart (line count + total
		 * quantity) — the raw cart payload is intentionally not dumped.
		 *
		 * @param object $row
		 * @return string
		 */
		private function summarise_cart( $row ): string {
			$raw     = isset( $row->cart_contents ) ? (string) $row->cart_contents : '';
			$decoded = '' === $raw ? array() : json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return '';
			}
			$lines = count( $decoded );
			$qty   = 0;
			foreach ( $decoded as $item ) {
				if ( is_array( $item ) && isset( $item['quantity'] ) ) {
					$qty += (int) $item['quantity'];
				}
			}
			return sprintf(
				/* translators: 1: number of distinct line items, 2: total quantity. */
				_n( '%1$d line item, %2$d total quantity', '%1$d line items, %2$d total quantity', $lines, 'lafka-plugin' ),
				$lines,
				$qty
			);
		}

		/**
		 * @return string
		 */
		private function push_table(): string {
			if ( function_exists( 'lafka_push_table_name' ) ) {
				return lafka_push_table_name();
			}
			global $wpdb;
			$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			return $prefix . 'lafka_push_subscriptions';
		}

		/**
		 * @return string
		 */
		private function ac_table(): string {
			if ( function_exists( 'lafka_ac_table_name' ) ) {
				return lafka_ac_table_name();
			}
			global $wpdb;
			$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			return $prefix . 'lafka_abandoned_carts';
		}
	}
}
