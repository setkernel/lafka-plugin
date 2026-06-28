<?php
/**
 * Phase 3E (v9.29.0): Web Push notifications - admin sender UI.
 *
 * Adds a "Push notifications" submenu under WooCommerce that lets the operator
 * compose + send a one-shot push to:
 *
 *   - All active subscribers
 *   - Recent customers (placed an order in the last 60 days)
 *   - A comma-separated list of specific WP user IDs
 *
 * Form fields: title, body, URL (deep-link target), icon URL.
 *
 * Two buttons:
 *   - "Preview" - renders the notification shape without sending
 *   - "Send now" - queues the broadcast via lafka_push_enqueue_broadcast() so
 *     the actual per-row sends run off the request thread (WP-Cron batches),
 *     and the admin request returns immediately with a "queued" status.
 *
 * Activity log shows the last 20 sends (audience, title, count, timestamp).
 *
 * @package Lafka\Plugin\Admin
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Push_Admin' ) ) {

	/**
	 * Admin page for composing + sending Web Push broadcasts.
	 */
	final class Lafka_Push_Admin {

		const NONCE_ACTION = 'lafka_push_admin_send';
		const NONCE_NAME   = 'lafka_push_admin_nonce';
		const MENU_SLUG    = 'lafka-push-notifications';
		const CAPABILITY   = 'manage_woocommerce';

		/**
		 * Hook the menu registration.
		 */
		public static function init(): void {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		}

		/**
		 * Register the submenu under WooCommerce.
		 */
		public static function register_menu(): void {
			$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
			add_submenu_page(
				$parent,
				esc_html__( 'Push notifications', 'lafka-plugin' ),
				esc_html__( 'Push notifications', 'lafka-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * Render the admin page. Handles the POST itself so we don't need a
		 * separate admin-post.php callback.
		 */
		public static function render_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'lafka-plugin' ) );
			}

			$status = '';
			$result = null;

			if ( isset( $_POST['lafka_push_action'] ) ) {
				$nonce_ok = isset( $_POST[ self::NONCE_NAME ] )
					&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION );
				if ( ! $nonce_ok ) {
					$status = 'nonce_failed';
				} else {
					$action = sanitize_text_field( wp_unslash( $_POST['lafka_push_action'] ) );
					if ( 'send' === $action ) {
						$result = self::handle_send( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
						if ( is_array( $result ) ) {
							$status = empty( $result['queued'] ) ? 'sent' : 'queued';
						} else {
							$status = 'error';
						}
					} elseif ( 'preview' === $action ) {
						$result = self::handle_preview( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
						$status = 'preview';
					}
				}
			}

			$vapid           = function_exists( 'lafka_push_get_vapid_config' ) ? lafka_push_get_vapid_config() : array( 'enabled' => false );
			$activity_log    = function_exists( 'lafka_push_get_activity_log' ) ? lafka_push_get_activity_log() : array();
			$active_count    = self::count_active_subscriptions();
			$customizer_link = admin_url( 'customize.php?autofocus[panel]=lafka_push' );

			?>
			<div class="wrap lafka-push-admin">
				<h1><?php echo esc_html__( 'Push notifications', 'lafka-plugin' ); ?></h1>

				<?php if ( empty( $vapid['enabled'] ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<strong><?php echo esc_html__( 'Push is disabled.', 'lafka-plugin' ); ?></strong>
							<?php echo esc_html__( 'Enable it and paste your VAPID keys in the Customizer panel below.', 'lafka-plugin' ); ?>
							<a href="<?php echo esc_url( $customizer_link ); ?>"><?php echo esc_html__( 'Open Customizer', 'lafka-plugin' ); ?></a>
						</p>
					</div>
				<?php elseif ( empty( $vapid['public'] ) || empty( $vapid['private'] ) ) : ?>
					<div class="notice notice-error">
						<p>
							<strong><?php echo esc_html__( 'VAPID keys missing.', 'lafka-plugin' ); ?></strong>
							<?php echo esc_html__( 'Paste your public + private VAPID keys in the Customizer panel before sending.', 'lafka-plugin' ); ?>
							<a href="<?php echo esc_url( $customizer_link ); ?>"><?php echo esc_html__( 'Open Customizer', 'lafka-plugin' ); ?></a>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( 'nonce_failed' === $status ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html__( 'Security check failed. Please reload and try again.', 'lafka-plugin' ); ?></p></div>
				<?php elseif ( 'error' === $status ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html__( 'Could not queue the broadcast. Check that a title and body are set and that push is enabled.', 'lafka-plugin' ); ?></p></div>
				<?php elseif ( 'queued' === $status && is_array( $result ) ) : ?>
					<div class="notice notice-success">
						<p>
							<?php
							echo esc_html(
								sprintf(
									// translators: %d is the number of subscribers the broadcast was queued for.
									_n(
										'Broadcast queued for %d subscriber. Sending runs in the background — refresh this page to watch progress in the activity log below.',
										'Broadcast queued for %d subscribers. Sending runs in the background — refresh this page to watch progress in the activity log below.',
										(int) $result['audience_size'],
										'lafka-plugin'
									),
									(int) $result['audience_size']
								)
							);
							?>
						</p>
					</div>
				<?php elseif ( 'sent' === $status && is_array( $result ) ) : ?>
					<div class="notice notice-success">
						<p>
							<?php
							echo esc_html(
								sprintf(
									// translators: 1 sent count, 2 failed count, 3 total audience size.
									__( 'Sent %1$d / %3$d (failed: %2$d).', 'lafka-plugin' ),
									(int) $result['sent'],
									(int) $result['failed'],
									(int) $result['audience_size']
								)
							);
							?>
						</p>
					</div>
				<?php elseif ( 'preview' === $status && is_array( $result ) ) : ?>
					<div class="notice notice-info">
						<p><strong><?php echo esc_html__( 'Preview', 'lafka-plugin' ); ?></strong></p>
						<pre style="background:#fff;padding:12px;border:1px solid #ddd;"><?php echo esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT ) ); ?></pre>
					</div>
				<?php endif; ?>

				<p>
					<?php
					echo esc_html(
						sprintf(
							// translators: subscriber count.
							_n( '%d active subscriber.', '%d active subscribers.', (int) $active_count, 'lafka-plugin' ),
							(int) $active_count
						)
					);
					?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="lafka_push_audience"><?php echo esc_html__( 'Audience', 'lafka-plugin' ); ?></label></th>
							<td>
								<select name="audience" id="lafka_push_audience">
									<option value="all"><?php echo esc_html__( 'All active subscribers', 'lafka-plugin' ); ?></option>
									<option value="recent_customers"><?php echo esc_html__( 'Recent customers (last 60 days)', 'lafka-plugin' ); ?></option>
									<option value="user_ids"><?php echo esc_html__( 'Specific user IDs', 'lafka-plugin' ); ?></option>
								</select>
								<p class="description"><?php echo esc_html__( 'For "Specific user IDs", enter a comma-separated list below.', 'lafka-plugin' ); ?></p>
								<input name="user_ids" id="lafka_push_user_ids" type="text" class="regular-text" placeholder="12, 47, 89" value="" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="lafka_push_title"><?php echo esc_html__( 'Title', 'lafka-plugin' ); ?></label></th>
							<td><input name="title" id="lafka_push_title" type="text" class="regular-text" maxlength="60" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lafka_push_body"><?php echo esc_html__( 'Body', 'lafka-plugin' ); ?></label></th>
							<td><textarea name="body" id="lafka_push_body" rows="3" class="large-text" maxlength="200" required></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="lafka_push_url"><?php echo esc_html__( 'Click URL', 'lafka-plugin' ); ?></label></th>
							<td><input name="url" id="lafka_push_url" type="url" class="regular-text" value="<?php echo esc_attr( function_exists( 'lafka_get_menu_url' ) ? lafka_get_menu_url() : home_url( '/menu/' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="lafka_push_icon"><?php echo esc_html__( 'Icon URL (optional)', 'lafka-plugin' ); ?></label></th>
							<td><input name="icon" id="lafka_push_icon" type="url" class="regular-text" value="" placeholder="https://..." /></td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button" name="lafka_push_action" value="preview"><?php echo esc_html__( 'Preview', 'lafka-plugin' ); ?></button>
						<button type="submit" class="button button-primary" name="lafka_push_action" value="send" onclick="return confirm('<?php echo esc_js( __( 'Send this push to all selected subscribers?', 'lafka-plugin' ) ); ?>');"><?php echo esc_html__( 'Send now', 'lafka-plugin' ); ?></button>
					</p>
				</form>

				<h2><?php echo esc_html__( 'Activity log (last 20 sends)', 'lafka-plugin' ); ?></h2>
				<?php if ( empty( $activity_log ) ) : ?>
					<p><em><?php echo esc_html__( 'No push activity yet.', 'lafka-plugin' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'When', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Audience', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Title', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Sent', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Failed', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Size', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'lafka-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $activity_log as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( gmdate( 'Y-m-d H:i', isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0 ) ); ?> UTC</td>
									<td><?php echo esc_html( (string) ( $entry['audience'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['title'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['sent'] ?? 0 ) ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['failed'] ?? 0 ) ); ?></td>
									<td><?php echo esc_html( (string) ( $entry['size'] ?? 0 ) ); ?></td>
									<td><?php echo esc_html( self::format_status_label( (string) ( $entry['status'] ?? 'done' ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Handle the Send button. Sanitizes input, resolves audience, and queues
		 * the broadcast for background delivery via lafka_push_enqueue_broadcast()
		 * so the admin request never blocks on the per-row send loop.
		 *
		 * @param array $post Raw POST.
		 * @return array|null Queue descriptor (queued/job_id/audience_size), or null on failure.
		 */
		public static function handle_send( array $post ): ?array {
			$payload = self::build_payload_from_post( $post );
			if ( null === $payload ) {
				return null;
			}
			$audience = self::resolve_audience_from_post( $post );
			if ( ! function_exists( 'lafka_push_enqueue_broadcast' ) ) {
				return null;
			}
			return lafka_push_enqueue_broadcast( $audience, $payload );
		}

		/**
		 * Handle the Preview button. Returns the payload + resolved audience
		 * size without sending.
		 */
		public static function handle_preview( array $post ): array {
			$payload  = self::build_payload_from_post( $post );
			$audience = self::resolve_audience_from_post( $post );
			$user_ids = function_exists( 'lafka_push_resolve_audience' ) ? lafka_push_resolve_audience( $audience ) : array();
			$rows     = function_exists( 'lafka_push_get_active_subscriptions' )
				? lafka_push_get_active_subscriptions( $user_ids, 5000 )
				: array();
			return array(
				'payload'       => $payload,
				'audience'      => $audience,
				'audience_size' => is_array( $rows ) ? count( $rows ) : 0,
			);
		}

		/**
		 * Sanitize the title/body/url/icon fields into the push payload shape.
		 */
		private static function build_payload_from_post( array $post ): ?array {
			$title = isset( $post['title'] ) ? sanitize_text_field( wp_unslash( $post['title'] ) ) : '';
			$body  = isset( $post['body'] ) ? sanitize_textarea_field( wp_unslash( $post['body'] ) ) : '';
			$url   = isset( $post['url'] ) ? esc_url_raw( wp_unslash( $post['url'] ) ) : '';
			$icon  = isset( $post['icon'] ) ? esc_url_raw( wp_unslash( $post['icon'] ) ) : '';
			if ( '' === $title || '' === $body ) {
				return null;
			}
			$payload = array(
				'title' => mb_substr( $title, 0, 60 ),
				'body'  => mb_substr( $body, 0, 200 ),
				'url'   => $url,
				'icon'  => $icon,
				'badge' => $icon,
			);
			return $payload;
		}

		/**
		 * Map the audience radio + user_ids text into the shape that
		 * lafka_push_broadcast() accepts.
		 *
		 * @return string|array<int,int>
		 */
		private static function resolve_audience_from_post( array $post ) {
			$audience = isset( $post['audience'] ) ? sanitize_text_field( wp_unslash( $post['audience'] ) ) : 'all';
			if ( 'user_ids' === $audience ) {
				$raw = isset( $post['user_ids'] ) ? sanitize_text_field( wp_unslash( $post['user_ids'] ) ) : '';
				$ids = array_filter(
					array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ?: array() ),
					static function ( $v ) {
						return $v > 0;
					}
				);
				return array_values( $ids );
			}
			if ( 'recent_customers' === $audience ) {
				return 'recent_customers';
			}
			return 'all';
		}

		/**
		 * Map a stored activity-log status key to a translated, human label.
		 * Older entries (and the legacy synchronous path) carry no status and
		 * are treated as completed.
		 */
		private static function format_status_label( string $status ): string {
			switch ( $status ) {
				case 'queued':
					return esc_html__( 'Queued', 'lafka-plugin' );
				case 'sending':
					return esc_html__( 'Sending…', 'lafka-plugin' );
				case 'done':
				default:
					return esc_html__( 'Done', 'lafka-plugin' );
			}
		}

		/**
		 * Quick count of active subscriptions for the dashboard line.
		 */
		private static function count_active_subscriptions(): int {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
				return 0;
			}
			if ( ! function_exists( 'lafka_push_table_name' ) ) {
				return 0;
			}
			$table = lafka_push_table_name();
			$count = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE unsubscribed_at IS NULL"
			);
			return max( 0, $count );
		}
	}

	Lafka_Push_Admin::init();
}
