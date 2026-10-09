<?php
/**
 * Loyalty points in My Account (balance and history), on the user profile in
 * wp-admin (balance, history, adjustment) and in WordPress's privacy tools.
 *
 * My Account gets a "Points" endpoint through WooCommerce's own endpoint and
 * menu filters. The admin adjustment writes an ordinary ledger row, so it is
 * auditable and recomputable like any other.
 *
 * @package Lafka\Plugin\Loyalty
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Loyalty_Account' ) ) {

	/**
	 * Account and admin screens.
	 */
	final class Lafka_Loyalty_Account {

		/** My Account endpoint. */
		const ENDPOINT = 'loyalty';

		/** History rows per page. */
		const PER_PAGE = 20;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
			add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
			add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
			add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ), 5 );

			add_action( 'show_user_profile', array( __CLASS__, 'profile_box' ) );
			add_action( 'edit_user_profile', array( __CLASS__, 'profile_box' ) );
			add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
			add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );

			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		}

		/**
		 * The endpoint as a WooCommerce query var.
		 *
		 * @param array<string,string> $vars Query vars.
		 * @return array<string,string>
		 */
		public static function query_vars( $vars ) {
			$vars                   = (array) $vars;
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
			return $vars;
		}

		/**
		 * Rebuild the rewrite rules once when the endpoint is missing from them.
		 *
		 * @return void
		 */
		public static function maybe_flush_rewrites(): void {
			$rules = get_option( 'rewrite_rules' );
			if ( is_array( $rules ) && false === strpos( implode( "\n", array_keys( $rules ) ), '/' . self::ENDPOINT . '(' ) ) {
				flush_rewrite_rules( false );
			}
		}

		/**
		 * The menu entry, before Log out.
		 *
		 * @param array<string,string> $items Menu items.
		 * @return array<string,string>
		 */
		public static function menu_items( $items ) {
			$items  = (array) $items;
			$out    = array();
			$label  = __( 'Points', 'lafka-plugin' );
			$placed = false;
			foreach ( $items as $key => $text ) {
				if ( 'customer-logout' === $key ) {
					$out[ self::ENDPOINT ] = $label;
					$placed                = true;
				}
				$out[ $key ] = $text;
			}
			if ( ! $placed ) {
				$out[ self::ENDPOINT ] = $label;
			}
			return $out;
		}

		/**
		 * The page title.
		 *
		 * @return string
		 */
		public static function title(): string {
			return __( 'Loyalty points', 'lafka-plugin' );
		}

		/**
		 * The structure stylesheet on the account page (the theme refines it).
		 *
		 * @return void
		 */
		public static function enqueue_style(): void {
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				wp_enqueue_style( 'lafka-loyalty', plugins_url( 'assets/css/lafka-loyalty.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/lafka-loyalty.css' ) );
			}
		}

		/**
		 * A ledger row as a sentence for the customer.
		 *
		 * @param array<string,mixed> $row Row.
		 * @return string
		 */
		public static function describe( array $row ): string {
			$labels = array(
				'earn'    => __( 'Points earned', 'lafka-plugin' ),
				'reserve' => __( 'Points used', 'lafka-plugin' ),
				'release' => __( 'Points returned', 'lafka-plugin' ),
				'refund'  => __( 'Points taken back for a refund', 'lafka-plugin' ),
				'cancel'  => __( 'Points taken back for a cancelled order', 'lafka-plugin' ),
				'settle'  => __( 'Points still owed from an earlier order', 'lafka-plugin' ),
				'expire'  => __( 'Points expired', 'lafka-plugin' ),
				'adjust'  => __( 'Adjustment', 'lafka-plugin' ),
			);
			$label  = $labels[ $row['reason'] ] ?? (string) $row['reason'];
			return '' !== (string) $row['note'] ? $label . ' · ' . $row['note'] : $label;
		}

		/**
		 * The note on a row that could not take back all it should.
		 *
		 * @param int $points Points that could not be taken back.
		 * @return string
		 */
		private static function shortfall_text( int $points ): string {
			/* translators: %s: points. */
			return sprintf( __( '%s could not be taken back', 'lafka-plugin' ), Lafka_Loyalty::format( $points ) );
		}

		/**
		 * My Account → Points.
		 *
		 * @return void
		 */
		public static function render(): void {
			$user = get_current_user_id();
			if ( $user <= 0 ) {
				return;
			}
			$balance = Lafka_Loyalty_Ledger::balance( $user );
			$page    = max( 1, (int) filter_input( INPUT_GET, 'points_page', FILTER_VALIDATE_INT ) );
			$total   = Lafka_Loyalty_Ledger::count( $user );
			$rows    = Lafka_Loyalty_Ledger::history( $user, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
			$expires = Lafka_Loyalty::expires_on( $user );
			?>
			<section class="lafka-loyalty-account">
				<div class="lafka-loyalty-account__balance lafka-card">
					<p class="lafka-loyalty-account__label"><?php esc_html_e( 'Your points', 'lafka-plugin' ); ?></p>
					<p class="lafka-loyalty-account__points"><?php echo esc_html( Lafka_Loyalty::format( $balance ) ); ?></p>
					<p class="lafka-loyalty-account__worth">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: money. */
								__( 'Worth %s off your next order.', 'lafka-plugin' ),
								lafka_price_plain( Lafka_Loyalty::value_of( $balance ) )
							)
						);
						?>
					</p>
					<p class="lafka-loyalty-account__rules">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: points earned per currency unit, 2: that unit as money, 3: minimum points to use, 4: percent of items. */
								__( 'Points earned: %1$s for every %2$s you spend on food. Use points from %3$s at a time, up to %4$d%% of your items.', 'lafka-plugin' ),
								Lafka_Loyalty::format( (int) round( Lafka_Loyalty::earn_rate() ) ),
								lafka_price_plain( 1.0, true ),
								Lafka_Loyalty::format( Lafka_Loyalty::min_redeem() ),
								Lafka_Loyalty::max_share()
							)
						);
						?>
					</p>
					<?php if ( '' !== $expires ) : ?>
						<p class="lafka-loyalty-account__expiry">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: date. */
									__( 'Points expire on %s unless you order before then.', 'lafka-plugin' ),
									$expires
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<h3 class="lafka-loyalty-account__heading"><?php esc_html_e( 'History', 'lafka-plugin' ); ?></h3>
				<?php if ( array() === $rows ) : ?>
					<p><?php esc_html_e( 'No points yet. You earn points when an order is completed.', 'lafka-plugin' ); ?></p>
				<?php else : ?>
					<ul class="lafka-loyalty-account__history">
						<?php foreach ( $rows as $row ) : ?>
							<?php $delta = (int) $row['delta']; ?>
							<li class="lafka-loyalty-account__row">
								<span class="lafka-loyalty-account__what"><?php echo esc_html( self::describe( $row ) ); ?></span>
								<span class="lafka-loyalty-account__date"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $row['created_at'] . ' UTC' ) ) ); ?></span>
								<span class="lafka-loyalty-account__delta <?php echo $delta < 0 ? 'is-out' : 'is-in'; ?>"><?php echo esc_html( ( $delta > 0 ? '+' : '' ) . Lafka_Loyalty::format( $delta ) ); ?></span>
								<span class="lafka-loyalty-account__after">
									<?php
									/* translators: %s: balance after this change. */
									echo esc_html( sprintf( __( 'Balance %s', 'lafka-plugin' ), Lafka_Loyalty::format( (int) $row['balance_after'] ) ) );
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $total > $page * self::PER_PAGE ) : ?>
						<p><a class="button" href="<?php echo esc_url( add_query_arg( 'points_page', $page + 1, wc_get_endpoint_url( self::ENDPOINT ) ) ); ?>"><?php esc_html_e( 'Older', 'lafka-plugin' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>
			<?php
		}

		// ---------------------------------------------------------------- wp-admin.

		/**
		 * The profile section: balance, recent history and the adjustment form.
		 *
		 * @param WP_User $user User being edited.
		 * @return void
		 */
		public static function profile_box( $user ): void {
			if ( ! $user instanceof WP_User || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			$balance = Lafka_Loyalty_Ledger::balance( $user->ID );
			$rows    = Lafka_Loyalty_Ledger::history( $user->ID, 10 );
			wp_nonce_field( 'lafka_loyalty_adjust_' . $user->ID, 'lafka_loyalty_nonce' );
			?>
			<h2><?php esc_html_e( 'Loyalty points', 'lafka-plugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Balance', 'lafka-plugin' ); ?></th>
					<td>
						<strong><?php echo esc_html( Lafka_Loyalty::format( $balance ) ); ?></strong>
						<span class="description"> (<?php echo esc_html( lafka_price_plain( Lafka_Loyalty::value_of( $balance ) ) ); ?>)</span>
					</td>
				</tr>
				<tr>
					<th><label for="lafka_loyalty_adjust"><?php esc_html_e( 'Adjust points', 'lafka-plugin' ); ?></label></th>
					<td>
						<input type="number" step="1" name="lafka_loyalty_adjust" id="lafka_loyalty_adjust" class="small-text" value="">
						<input type="text" name="lafka_loyalty_note" class="regular-text" maxlength="120" placeholder="<?php esc_attr_e( 'Reason (shown to the customer)', 'lafka-plugin' ); ?>">
						<p class="description"><?php esc_html_e( 'A positive number adds points, a negative one takes them off (never below zero). Saved with the profile.', 'lafka-plugin' ); ?></p>
					</td>
				</tr>
				<?php if ( array() !== $rows ) : ?>
				<tr>
					<th><?php esc_html_e( 'Recent changes', 'lafka-plugin' ); ?></th>
					<td>
						<table class="widefat striped">
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) ) ); ?></td>
									<td><?php echo esc_html( self::describe( $row ) ); ?><?php echo (int) $row['shortfall'] > 0 ? esc_html( ' · ' . self::shortfall_text( (int) $row['shortfall'] ) ) : ''; ?></td>
									<td><?php echo esc_html( ( (int) $row['delta'] > 0 ? '+' : '' ) . Lafka_Loyalty::format( (int) $row['delta'] ) ); ?></td>
									<td><?php echo esc_html( Lafka_Loyalty::format( (int) $row['balance_after'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<?php
		}

		/**
		 * Save the adjustment from the profile.
		 *
		 * @param int $user_id User id.
		 * @return void
		 */
		public static function profile_save( $user_id ): void {
			$user_id = (int) $user_id;
			if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['lafka_loyalty_nonce'], $_POST['lafka_loyalty_adjust'] ) ) {
				return;
			}
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lafka_loyalty_nonce'] ) ), 'lafka_loyalty_adjust_' . $user_id ) ) {
				return;
			}
			$delta = (int) sanitize_text_field( wp_unslash( $_POST['lafka_loyalty_adjust'] ) );
			if ( 0 === $delta ) {
				return;
			}
			$note = isset( $_POST['lafka_loyalty_note'] ) ? sanitize_text_field( wp_unslash( $_POST['lafka_loyalty_note'] ) ) : '';
			Lafka_Loyalty_Ledger::add( $user_id, $delta, 'adjust', 0, null, '' !== $note ? $note : __( 'Adjusted by the shop', 'lafka-plugin' ) );
		}

		// ---------------------------------------------------------------- privacy.

		/**
		 * Register the personal-data exporter.
		 *
		 * @param array<string,array<string,mixed>> $exporters Exporters.
		 * @return array<string,array<string,mixed>>
		 */
		public static function register_exporter( $exporters ) {
			$exporters['lafka-loyalty'] = array(
				'exporter_friendly_name' => __( 'Lafka loyalty points', 'lafka-plugin' ),
				'callback'               => array( __CLASS__, 'export' ),
			);
			return $exporters;
		}

		/**
		 * Register the eraser.
		 *
		 * @param array<string,array<string,mixed>> $erasers Erasers.
		 * @return array<string,array<string,mixed>>
		 */
		public static function register_eraser( $erasers ) {
			$erasers['lafka-loyalty'] = array(
				'eraser_friendly_name' => __( 'Lafka loyalty points', 'lafka-plugin' ),
				'callback'             => array( __CLASS__, 'erase' ),
			);
			return $erasers;
		}

		/**
		 * Export a person's ledger.
		 *
		 * @param string $email Email address.
		 * @return array<string,mixed>
		 */
		public static function export( $email ): array {
			$user = get_user_by( 'email', (string) $email );
			$data = array();
			if ( $user ) {
				foreach ( Lafka_Loyalty_Ledger::history( $user->ID, 1000 ) as $row ) {
					$data[] = array(
						'group_id'    => 'lafka-loyalty',
						'group_label' => __( 'Loyalty points', 'lafka-plugin' ),
						'item_id'     => 'lafka-loyalty-' . $row['id'],
						'data'        => array(
							array(
								'name'  => __( 'Date', 'lafka-plugin' ),
								'value' => $row['created_at'],
							),
							array(
								'name'  => __( 'What happened', 'lafka-plugin' ),
								'value' => self::describe( $row ),
							),
							array(
								'name'  => __( 'Points', 'lafka-plugin' ),
								'value' => (string) $row['delta'],
							),
						),
					);
				}
			}
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		/**
		 * Erase a person's ledger.
		 *
		 * @param string $email Email address.
		 * @return array<string,mixed>
		 */
		public static function erase( $email ): array {
			$user    = get_user_by( 'email', (string) $email );
			$removed = false;
			if ( $user && Lafka_Loyalty_Ledger::count( $user->ID ) > 0 ) {
				Lafka_Loyalty_Ledger::erase( $user->ID );
				$removed = true;
			}
			return array(
				'items_removed'  => $removed,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
	}
}
