<?php
/**
 * Lafka_Diagnostics_Page — the "Lafka → Diagnostics" screen (GX1 / A8).
 *
 * Tabs:
 *   1. Incidents — deduplicated problems (count, first/last seen), mute /
 *      resolve / reopen, and a link to the WooCommerce log viewer filtered to
 *      the incident's `lafka-{channel}` source.
 *   2. Checkout failures — refusals by reason for the last 30 days
 *      (`lafka_checkout_blocked`), recent failed orders with their failure
 *      class + request id, and leftover WooCommerce place-order traces
 *      (attempts that never finished) with the last step each reached.
 *   3. Health — versions, checkout mode, modules, WooCommerce logging,
 *      background job status.
 *   4. Settings — minimum log level, incident retention, digest email link,
 *      "Send test error" and "Run daily check now".
 *
 * Gated on the `diagnostics` module; manage_woocommerce; every write is an
 * admin-post action behind a nonce. Uses core WP admin styles only.
 *
 * @package Lafka\Plugin\Admin
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Diagnostics_Page' ) ) {

	/**
	 * Diagnostics admin screen.
	 */
	final class Lafka_Diagnostics_Page {

		const PARENT_SLUG     = 'lafka-modules';
		const MENU_SLUG       = 'lafka-diagnostics';
		const CAPABILITY      = 'manage_woocommerce';
		const STATUS_ACTION   = 'lafka_diag_incident';
		const SETTINGS_ACTION = 'lafka_diag_settings';
		const TEST_ACTION     = 'lafka_diag_test';
		const RUN_ACTION      = 'lafka_diag_run';
		const PER_PAGE        = 25;

		/** @var Lafka_Diagnostics_Page|null */
		private static $instance = null;

		/**
		 * @return Lafka_Diagnostics_Page
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
			add_action( 'admin_post_' . self::STATUS_ACTION, array( $this, 'handle_status' ) );
			add_action( 'admin_post_' . self::SETTINGS_ACTION, array( $this, 'handle_settings' ) );
			add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test' ) );
			add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'handle_run' ) );
		}

		/**
		 * Register "Diagnostics" under the top-level Lafka menu.
		 *
		 * @return void
		 */
		public function register_menu() {
			$open  = class_exists( 'Lafka_Incidents' ) ? Lafka_Incidents::status_counts()['open'] : 0;
			$label = esc_html__( 'Diagnostics', 'lafka-plugin' );
			if ( $open > 0 ) {
				$label .= ' <span class="awaiting-mod">' . esc_html( (string) $open ) . '</span>';
			}
			add_submenu_page(
				self::PARENT_SLUG,
				esc_html__( 'Lafka Diagnostics', 'lafka-plugin' ),
				$label,
				self::CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		// ─── Actions ────────────────────────────────────────────────────────

		/**
		 * Mute / resolve / reopen one incident.
		 *
		 * @return void
		 */
		public function handle_status() {
			$this->authorize();
			$id = isset( $_GET['incident'] ) ? absint( wp_unslash( $_GET['incident'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() on the next line (the nonce action embeds the id).
			check_admin_referer( self::STATUS_ACTION . '_' . $id );
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			$ok     = Lafka_Incidents::set_status( $id, $status );
			$this->redirect(
				array(
					'tab'        => 'incidents',
					'lafka_diag' => $ok ? 'status_' . $status : 'status_failed',
				)
			);
		}

		/**
		 * Save the Settings tab.
		 *
		 * @return void
		 */
		public function handle_settings() {
			$this->authorize();
			check_admin_referer( self::SETTINGS_ACTION );
			$level = isset( $_POST['lafka_min_level'] ) ? sanitize_key( wp_unslash( $_POST['lafka_min_level'] ) ) : '';
			$days  = isset( $_POST['lafka_retention_days'] ) ? absint( wp_unslash( $_POST['lafka_retention_days'] ) ) : Lafka_Incidents::RETENTION_DAYS;

			Lafka_Log::update_settings(
				array(
					'min_level'      => isset( Lafka_Log::LEVELS[ $level ] ) ? $level : '',
					'retention_days' => max( 7, min( 365, $days ) ),
				)
			);
			$this->redirect(
				array(
					'tab'        => 'settings',
					'lafka_diag' => 'saved',
				)
			);
		}

		/**
		 * Record a test incident so the operator can see the pipeline work.
		 *
		 * @return void
		 */
		public function handle_test() {
			$this->authorize();
			check_admin_referer( self::TEST_ACTION );
			Lafka_Log::warning( 'core', 'Diagnostics test incident (sent from Lafka → Diagnostics)', array( 'code' => 'diagnostics_test' ) );
			$this->redirect(
				array(
					'tab'        => 'incidents',
					'lafka_diag' => 'test_sent',
				)
			);
		}

		/**
		 * Run the daily job now (prune, index traces, digest).
		 *
		 * @return void
		 */
		public function handle_run() {
			$this->authorize();
			check_admin_referer( self::RUN_ACTION );
			$summary = Lafka_Diagnostics::run_daily();
			$this->redirect(
				array(
					'tab'        => 'settings',
					'lafka_diag' => 'ran',
					'digest'     => (int) $summary['digest'],
					'traces'     => (int) $summary['traces'],
				)
			);
		}

		/**
		 * @return void
		 */
		private function authorize() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to manage Lafka diagnostics.', 'lafka-plugin' ), 403 );
			}
		}

		/**
		 * @param array<string,mixed> $args Query args.
		 * @return void
		 */
		private function redirect( array $args ) {
			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * admin-post URL for an action.
		 *
		 * @param string              $action Action.
		 * @param string              $nonce  Nonce action.
		 * @param array<string,mixed> $args   Extra args.
		 * @return string
		 */
		private function action_url( string $action, string $nonce, array $args = array() ): string {
			return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $nonce );
		}

		// ─── Rendering ──────────────────────────────────────────────────────

		/**
		 * Render the screen.
		 *
		 * @return void
		 */
		public function render_page() {
			$this->authorize();
			$tabs = array(
				'incidents' => __( 'Incidents', 'lafka-plugin' ),
				'checkout'  => __( 'Checkout failures', 'lafka-plugin' ),
				'health'    => __( 'Health', 'lafka-plugin' ),
				'settings'  => __( 'Settings', 'lafka-plugin' ),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'incidents';
			$tab = isset( $tabs[ $tab ] ) ? $tab : 'incidents';

			echo '<div class="wrap lafka-diagnostics">';
			echo '<h1>' . esc_html__( 'Lafka Diagnostics', 'lafka-plugin' ) . '</h1>';
			echo '<p class="description">' . esc_html__( 'What went wrong on your store, and why customers could not order. Details are in WooCommerce → Status → Logs (sources starting with "lafka-"); personal data is removed before anything is written.', 'lafka-plugin' ) . '</p>';
			$this->render_notice();

			echo '<nav class="nav-tab-wrapper">';
			foreach ( $tabs as $slug => $label ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
					esc_url(
                        add_query_arg(
                            array(
								'page' => self::MENU_SLUG,
								'tab' => $slug,
                            ),
                            admin_url( 'admin.php' ) 
                        ) 
                    ),
					$slug === $tab ? ' nav-tab-active' : '',
					esc_html( $label )
				);
			}
			echo '</nav>';

			switch ( $tab ) {
				case 'checkout':
					$this->render_checkout_tab();
					break;
				case 'health':
					$this->render_health_tab();
					break;
				case 'settings':
					$this->render_settings_tab();
					break;
				default:
					$this->render_incidents_tab();
			}
			echo '</div>';
		}

		/**
		 * Result notice after an action.
		 *
		 * @return void
		 */
		private function render_notice() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags set by our own redirects.
			$flag = isset( $_GET['lafka_diag'] ) ? sanitize_key( wp_unslash( $_GET['lafka_diag'] ) ) : '';
			if ( '' === $flag ) {
				return;
			}
			$messages = array(
				'status_muted'    => __( 'Incident muted. It is still counted but left out of the digest.', 'lafka-plugin' ),
				'status_resolved' => __( 'Incident resolved. It re-opens automatically if it happens again.', 'lafka-plugin' ),
				'status_open'     => __( 'Incident re-opened.', 'lafka-plugin' ),
				'status_failed'   => __( 'The incident could not be updated.', 'lafka-plugin' ),
				'saved'           => __( 'Settings saved.', 'lafka-plugin' ),
				'test_sent'       => __( 'Test incident recorded. It appears below and in WooCommerce → Status → Logs (source lafka-core).', 'lafka-plugin' ),
			);
			if ( 'ran' === $flag ) {
				$digest = isset( $_GET['digest'] ) ? absint( wp_unslash( $_GET['digest'] ) ) : 0;
				$traces = isset( $_GET['traces'] ) ? absint( wp_unslash( $_GET['traces'] ) ) : 0;
				$text   = sprintf(
					/* translators: 1: incidents emailed, 2: checkout traces indexed */
					__( 'Daily check done: %1$d incident(s) emailed, %2$d unfinished checkout attempt(s) indexed.', 'lafka-plugin' ),
					$digest,
					$traces
				);
			} else {
				$text = $messages[ $flag ] ?? '';
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( '' !== $text ) {
				$class = 'status_failed' === $flag ? 'notice-error' : 'notice-success';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}

		/**
		 * Incidents tab.
		 *
		 * @return void
		 */
		private function render_incidents_tab() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open';
			$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			$status = in_array( $status, array_merge( Lafka_Incidents::STATUSES, array( 'all' ) ), true ) ? $status : 'open';
			$counts = Lafka_Incidents::status_counts();

			$filters = array(
				'open'     => __( 'Open', 'lafka-plugin' ),
				'muted'    => __( 'Muted', 'lafka-plugin' ),
				'resolved' => __( 'Resolved', 'lafka-plugin' ),
				'all'      => __( 'All', 'lafka-plugin' ),
			);
			echo '<ul class="subsubsub">';
			$links = array();
			foreach ( $filters as $slug => $label ) {
				$n       = 'all' === $slug ? array_sum( $counts ) : ( $counts[ $slug ] ?? 0 );
				$links[] = sprintf(
					'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a></li>',
					esc_url(
                        add_query_arg(
                            array(
								'page' => self::MENU_SLUG,
								'tab' => 'incidents',
								'status' => $slug,
                            ),
                            admin_url( 'admin.php' ) 
                        ) 
                    ),
					$slug === $status ? ' class="current"' : '',
					esc_html( $label ),
					(int) $n
				);
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped above.
			echo '</ul><div class="clear"></div>';

			if ( ! Lafka_Incidents::is_installed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The incident table is not installed yet. It is created automatically on the next page load; if this persists, deactivate and re-activate the Lafka plugin.', 'lafka-plugin' ) . '</p></div>';
				return;
			}

			$rows = Lafka_Incidents::query(
				array(
					'status'   => $status,
					'per_page' => self::PER_PAGE,
					'page'     => $paged,
				)
			);

			if ( empty( $rows ) ) {
				echo '<p>' . esc_html__( 'Nothing here. Problems appear automatically when Lafka records a warning or an error.', 'lafka-plugin' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr>';
				foreach ( array( __( 'Level', 'lafka-plugin' ), __( 'Area', 'lafka-plugin' ), __( 'Problem', 'lafka-plugin' ), __( 'Times', 'lafka-plugin' ), __( 'First seen', 'lafka-plugin' ), __( 'Last seen', 'lafka-plugin' ), __( 'Actions', 'lafka-plugin' ) ) as $heading ) {
					echo '<th scope="col">' . esc_html( $heading ) . '</th>';
				}
				echo '</tr></thead><tbody>';
				foreach ( $rows as $row ) {
					$this->render_incident_row( $row );
				}
				echo '</tbody></table>';

				$total = 'all' === $status ? array_sum( $counts ) : ( $counts[ $status ] ?? 0 );
				$pages = (int) ceil( $total / self::PER_PAGE );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					echo '</div></div>';
				}
			}

			printf(
				'<p><a class="button" href="%1$s">%2$s</a> <a class="button-link" href="%3$s">%4$s</a></p>',
				esc_url( $this->action_url( self::TEST_ACTION, self::TEST_ACTION ) ),
				esc_html__( 'Send test error', 'lafka-plugin' ),
				esc_url( Lafka_Diagnostics::logs_url() ),
				esc_html__( 'Open WooCommerce logs', 'lafka-plugin' )
			);
		}

		/**
		 * One incident row.
		 *
		 * @param object $row Incident.
		 * @return void
		 */
		private function render_incident_row( $row ) {
			$id      = (int) $row->id;
			$channel = (string) $row->channel;
			$source  = 'php' === $channel ? 'fatal-errors' : Lafka_Log::source( $channel );
			$code    = (string) $row->code;
			$label   = Lafka_Checkout_Block_Reasons::is_known( $code ) ? Lafka_Checkout_Block_Reasons::label( $code ) : '';

			$actions = array();
			foreach ( array(
				'muted'    => __( 'Mute', 'lafka-plugin' ),
				'resolved' => __( 'Resolve', 'lafka-plugin' ),
				'open'     => __( 'Reopen', 'lafka-plugin' ),
			) as $target => $text ) {
				if ( $target === $row->status ) {
					continue;
				}
				$actions[] = sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						$this->action_url(
							self::STATUS_ACTION,
							self::STATUS_ACTION . '_' . $id,
							array(
								'incident' => $id,
								'status'   => $target,
							)
						)
					),
					esc_html( $text )
				);
			}
			$actions[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Lafka_Diagnostics::logs_url( $source ) ), esc_html__( 'Logs', 'lafka-plugin' ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row->level ) . '</td>';
			echo '<td><code>' . esc_html( $channel ) . '</code></td>';
			echo '<td>' . ( '' !== $label ? '<strong>' . esc_html( $label ) . '</strong><br>' : '' ) . esc_html( (string) $row->message );
			if ( '' !== $code ) {
				echo '<br><code>' . esc_html( $code ) . '</code>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row->hit_count ) ) . '</td>';
			echo '<td>' . esc_html( $this->local_time( (string) $row->first_seen ) ) . '</td>';
			echo '<td>' . esc_html( $this->local_time( (string) $row->last_seen ) ) . '</td>';
			echo '<td>' . implode( ' | ', $actions ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link escaped above.
			echo '</tr>';
		}

		/**
		 * Checkout failures tab.
		 *
		 * @return void
		 */
		private function render_checkout_tab() {
			$totals = Lafka_Checkout_Failures::totals( 30 );

			echo '<h2>' . esc_html__( 'Why orders did not go through (last 30 days)', 'lafka-plugin' ) . '</h2>';
			if ( empty( $totals ) ) {
				echo '<p>' . esc_html__( 'No refusals recorded in the last 30 days.', 'lafka-plugin' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th scope="col">' . esc_html__( 'Reason', 'lafka-plugin' ) . '</th><th scope="col" style="text-align:right">' . esc_html__( 'Times', 'lafka-plugin' ) . '</th></tr></thead><tbody>';
				foreach ( $totals as $reason => $count ) {
					echo '<tr><td>' . esc_html( Lafka_Checkout_Block_Reasons::label( (string) $reason ) ) . ' <code>' . esc_html( (string) $reason ) . '</code></td><td style="text-align:right">' . esc_html( number_format_i18n( (int) $count ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
				echo '<p class="description">' . esc_html__( 'Each row counts refused attempts (add to cart, checkout or payment), not customers: one customer retrying three times counts three.', 'lafka-plugin' ) . '</p>';
			}

			$this->render_failed_orders();
			$this->render_traces();
		}

		/**
		 * Recent failed orders with failure class + request id.
		 *
		 * @return void
		 */
		private function render_failed_orders() {
			echo '<h2>' . esc_html__( 'Recent failed payments', 'lafka-plugin' ) . '</h2>';
			if ( ! function_exists( 'wc_get_orders' ) ) {
				echo '<p>' . esc_html__( 'WooCommerce is not active.', 'lafka-plugin' ) . '</p>';
				return;
			}
			$orders = wc_get_orders(
				array(
					'status'       => array( 'failed' ),
					'limit'        => 10,
					'orderby'      => 'date',
					'order'        => 'DESC',
					'date_created' => '>' . ( time() - 30 * DAY_IN_SECONDS ),
				)
			);
			if ( empty( $orders ) ) {
				echo '<p>' . esc_html__( 'No failed payments in the last 30 days.', 'lafka-plugin' ) . '</p>';
				return;
			}
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Order', 'lafka-plugin' ), __( 'Date', 'lafka-plugin' ), __( 'Payment method', 'lafka-plugin' ), __( 'Failure', 'lafka-plugin' ), __( 'Request id', 'lafka-plugin' ), __( 'WooCommerce trace', 'lafka-plugin' ) ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
					continue;
				}
				$class  = $this->failure_class_for_order( (int) $order->get_id() );
				$reason = Lafka_Checkout_Block_Reasons::from_payment_class( $class );
				$trace  = (string) $order->get_meta( '_debug_log_source' );
				$date   = $order->get_date_created();
				echo '<tr>';
				printf( '<td><a href="%1$s">#%2$s</a></td>', esc_url( $order->get_edit_order_url() ), esc_html( (string) $order->get_order_number() ) );
				echo '<td>' . esc_html( $date ? $date->date_i18n( 'Y-m-d H:i' ) : '' ) . '</td>';
				echo '<td>' . esc_html( (string) $order->get_payment_method_title() ) . '</td>';
				echo '<td>' . esc_html( Lafka_Checkout_Block_Reasons::label( $reason ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) $order->get_meta( Lafka_Log::ORDER_META ) ) . '</code></td>';
				echo '<td>' . ( '' !== $trace ? '<a href="' . esc_url( Lafka_Diagnostics::logs_url( $trace ) ) . '">' . esc_html( $trace ) . '</a>' : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		/**
		 * Classify an order's latest failure note.
		 *
		 * @param int $order_id Order id.
		 * @return string
		 */
		private function failure_class_for_order( int $order_id ): string {
			if ( ! function_exists( 'wc_get_order_notes' ) ) {
				return 'other';
			}
			$notes = wc_get_order_notes(
				array(
					'order_id' => $order_id,
					'limit'    => 5,
				)
			);
			foreach ( is_array( $notes ) ? $notes : array() as $note ) {
				$text = isset( $note->content ) ? (string) $note->content : '';
				if ( preg_match( '/fail|declin|denied|reject|error/i', $text ) ) {
					return Lafka_Checkout_Failures::classify_payment_failure( $text );
				}
			}
			return 'other';
		}

		/**
		 * Leftover WooCommerce place-order traces.
		 *
		 * @return void
		 */
		private function render_traces() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view toggle.
			$show_finished = isset( $_GET['show_finished'] ) && '1' === sanitize_key( wp_unslash( $_GET['show_finished'] ) );

			echo '<h2>' . esc_html__( 'Checkout attempts that never finished', 'lafka-plugin' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'WooCommerce (9.9+) records every place-order attempt step by step. An attempt that stopped part-way (for example inside the payment gateway, or at form validation) is listed here with the last step it reached. Completed checkouts whose record WooCommerce has not cleared yet are hidden. The daily check keeps a copy of each unfinished attempt as an incident.', 'lafka-plugin' ) . '</p>';

			$all      = Lafka_Diagnostics::place_order_traces( 100 );
			$visible  = Lafka_Diagnostics::filter_traces( $all, $show_finished );
			$hidden   = count( $all ) - count( Lafka_Diagnostics::filter_traces( $all, false ) );
			$base_url = add_query_arg(
				array(
					'page' => self::MENU_SLUG,
					'tab'  => 'checkout',
				),
				admin_url( 'admin.php' )
			);
			if ( $show_finished ) {
				printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $base_url ), esc_html__( 'Hide finished attempts', 'lafka-plugin' ) );
			} elseif ( $hidden > 0 ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( add_query_arg( 'show_finished', '1', $base_url ) ),
					esc_html(
						sprintf(
							/* translators: %d: number of completed checkout attempts hidden from the list */
							_n( 'Show finished attempts (%d hidden)', 'Show finished attempts (%d hidden)', $hidden, 'lafka-plugin' ),
							$hidden
						)
					)
				);
			}

			if ( empty( $visible ) ) {
				echo '<p>' . esc_html__( 'None found (or this WooCommerce version / log handler does not record place-order steps).', 'lafka-plugin' ) . '</p>';
				return;
			}
			$outcomes = array(
				'unfinished' => __( 'Stopped part-way', 'lafka-plugin' ),
				'failed'     => __( 'Failed with an error', 'lafka-plugin' ),
				'finished'   => __( 'Finished', 'lafka-plugin' ),
			);
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Started', 'lafka-plugin' ), __( 'Steps', 'lafka-plugin' ), __( 'Last step reached', 'lafka-plugin' ), __( 'Outcome', 'lafka-plugin' ), __( 'Order', 'lafka-plugin' ), __( 'Trace', 'lafka-plugin' ) ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $visible, 0, 20 ) as $trace ) {
				$order_id = (int) $trace['order_id'];
				$started  = '' !== $trace['started'] ? strtotime( (string) $trace['started'] ) : false;
				$outcome  = (string) ( $trace['outcome'] ?? 'unfinished' );
				echo '<tr>';
				echo '<td>' . esc_html( $started ? wp_date( 'Y-m-d H:i:s', $started ) : '' ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $trace['steps'] ) . '</td>';
				echo '<td>' . esc_html( (string) $trace['last_step'] ) . '</td>';
				echo '<td>' . esc_html( $outcomes[ $outcome ] ?? $outcome ) . '</td>';
				if ( $order_id > 0 && function_exists( 'wc_get_order' ) && ( $order = wc_get_order( $order_id ) ) ) {
					printf( '<td><a href="%1$s">#%2$s</a> (%3$s)</td>', esc_url( $order->get_edit_order_url() ), esc_html( (string) $order->get_order_number() ), esc_html( wc_get_order_status_name( $order->get_status() ) ) );
				} else {
					echo '<td>—</td>';
				}
				$url = '' !== (string) $trace['file_id'] ? Lafka_Diagnostics::log_file_url( (string) $trace['file_id'] ) : Lafka_Diagnostics::logs_url( (string) $trace['source'] );
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $trace['source'] ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		/**
		 * Health tab.
		 *
		 * @return void
		 */
		private function render_health_tab() {
			$plugin  = function_exists( 'get_file_data' ) ? get_file_data( LAFKA_PLUGIN_FILE, array( 'Version' => 'Version' ) ) : array();
			$theme   = wp_get_theme();
			$parent  = $theme->parent() ? $theme->parent() : $theme;
			$wc      = function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->version ) ? (string) WC()->version : __( 'Inactive', 'lafka-plugin' );
			$next    = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( Lafka_Diagnostics::DAILY_HOOK, array(), Lafka_Diagnostics::AS_GROUP ) : false;
			$last    = (int) get_option( Lafka_Diagnostics::LAST_RUN_OPTION, 0 );
			$counts  = Lafka_Incidents::status_counts();
			$logging = class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' );

			$rows = array(
				__( 'Lafka plugin', 'lafka-plugin' )            => (string) ( $plugin['Version'] ?? '' ),
				__( 'Theme', 'lafka-plugin' )                   => $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ),
				__( 'Child theme', 'lafka-plugin' )             => is_child_theme() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : __( 'None', 'lafka-plugin' ),
				__( 'WooCommerce', 'lafka-plugin' )             => $wc,
				__( 'PHP', 'lafka-plugin' )                     => PHP_VERSION,
				__( 'Checkout experience', 'lafka-plugin' )     => class_exists( 'Lafka_Checkout_Mode' )
					/* translators: 1: configured checkout mode, 2: mode the Checkout page renders */
					? sprintf( __( '%1$s (setting) · %2$s (checkout page)', 'lafka-plugin' ), Lafka_Checkout_Mode::get_mode(), Lafka_Checkout_Mode::get_effective_mode() )
					: '',
				__( 'Lafka minimum log level', 'lafka-plugin' ) => Lafka_Log::min_level(),
				__( 'WooCommerce logging', 'lafka-plugin' )     => $logging && method_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil', 'logging_is_enabled' ) && ! \Automattic\WooCommerce\Utilities\LoggingUtil::logging_is_enabled() ? __( 'Disabled — Lafka records will not be written', 'lafka-plugin' ) : __( 'Enabled', 'lafka-plugin' ),
				__( 'WooCommerce log handler', 'lafka-plugin' ) => Lafka_Diagnostics::log_handler(),
				__( 'WooCommerce log retention', 'lafka-plugin' ) => $logging && method_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil', 'get_retention_period' )
					? sprintf( /* translators: %d: days */ __( '%d days (30 recommended)', 'lafka-plugin' ), (int) \Automattic\WooCommerce\Utilities\LoggingUtil::get_retention_period() )
					: '',
				__( 'Incident table', 'lafka-plugin' )          => Lafka_Incidents::is_installed()
					? sprintf( /* translators: 1: open, 2: muted, 3: resolved */ __( 'Installed — %1$d open, %2$d muted, %3$d resolved', 'lafka-plugin' ), $counts['open'], $counts['muted'], $counts['resolved'] )
					: __( 'Not installed', 'lafka-plugin' ),
				__( 'Incident retention', 'lafka-plugin' )      => sprintf( /* translators: %d: days */ __( '%d days after last seen', 'lafka-plugin' ), Lafka_Diagnostics::retention_days() ),
				__( 'Daily check — next run', 'lafka-plugin' )  => is_int( $next ) ? wp_date( 'Y-m-d H:i', $next ) : ( true === $next ? __( 'Running', 'lafka-plugin' ) : __( 'Not scheduled', 'lafka-plugin' ) ),
				__( 'Daily check — last run', 'lafka-plugin' )  => $last > 0 ? wp_date( 'Y-m-d H:i', $last ) : __( 'Never', 'lafka-plugin' ),
			);

			echo '<h2>' . esc_html__( 'Environment', 'lafka-plugin' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:800px"><tbody>';
			foreach ( $rows as $label => $value ) {
				echo '<tr><th scope="row" style="width:40%">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
			}
			echo '</tbody></table>';

			echo '<h2>' . esc_html__( 'Modules', 'lafka-plugin' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:800px"><tbody>';
			foreach ( Lafka_Module_Registry::all() as $module ) {
				echo '<tr><th scope="row" style="width:40%">' . esc_html( $module->get_label() ) . '</th><td>' . ( $module->is_enabled() ? esc_html__( 'On', 'lafka-plugin' ) : esc_html__( 'Off', 'lafka-plugin' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';

			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'site-health.php' ) ),
				esc_html__( 'Open Site Health for the Lafka checks (fatal errors, payment failures, background jobs).', 'lafka-plugin' )
			);
		}

		/**
		 * Settings tab.
		 *
		 * @return void
		 */
		private function render_settings_tab() {
			$settings = Lafka_Log::settings();
			$current  = (string) ( $settings['min_level'] ?? '' );
			$levels   = array(
				''         => __( 'Automatic — warnings and errors (everything when WP_DEBUG is on)', 'lafka-plugin' ),
				'debug'    => __( 'Debug — everything (troubleshooting only)', 'lafka-plugin' ),
				'info'     => __( 'Info', 'lafka-plugin' ),
				'notice'   => __( 'Notice — includes every checkout refusal', 'lafka-plugin' ),
				'warning'  => __( 'Warning', 'lafka-plugin' ),
				'error'    => __( 'Error', 'lafka-plugin' ),
				'critical' => __( 'Critical only', 'lafka-plugin' ),
			);

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::SETTINGS_ACTION ) . '">';
			wp_nonce_field( self::SETTINGS_ACTION );
			echo '<table class="form-table" role="presentation"><tbody>';

			echo '<tr><th scope="row"><label for="lafka_min_level">' . esc_html__( 'Minimum log level', 'lafka-plugin' ) . '</label></th><td><select id="lafka_min_level" name="lafka_min_level">';
			foreach ( $levels as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
			}
			echo '</select><p class="description">' . esc_html__( 'Records below this level are not written to the WooCommerce log. Warnings and above also appear under Incidents.', 'lafka-plugin' ) . '</p></td></tr>';

			printf(
				'<tr><th scope="row"><label for="lafka_retention_days">%1$s</label></th><td><input type="number" min="7" max="365" id="lafka_retention_days" name="lafka_retention_days" value="%2$d" class="small-text"> %3$s<p class="description">%4$s</p></td></tr>',
				esc_html__( 'Keep incidents for', 'lafka-plugin' ),
				(int) ( $settings['retention_days'] ?? Lafka_Incidents::RETENTION_DAYS ),
				esc_html__( 'days after they were last seen', 'lafka-plugin' ),
				esc_html__( 'Detailed log lines follow WooCommerce\'s own log retention (WooCommerce → Status → Logs → Settings).', 'lafka-plugin' )
			);

			$email_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( Lafka_Diagnostics::EMAIL_CLASS ) );
			printf(
				'<tr><th scope="row">%1$s</th><td><a href="%2$s">%3$s</a><p class="description">%4$s</p></td></tr>',
				esc_html__( 'Daily error digest', 'lafka-plugin' ),
				esc_url( $email_url ),
				esc_html__( 'Turn on/off and choose the recipient in WooCommerce → Settings → Emails → Lafka error digest', 'lafka-plugin' ),
				esc_html__( 'Sent once a day, only when new payment failures, checkout problems or site errors were recorded. On by default, to the site admin email.', 'lafka-plugin' )
			);

			echo '</tbody></table>';
			submit_button( __( 'Save settings', 'lafka-plugin' ) );
			echo '</form>';

			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( $this->action_url( self::RUN_ACTION, self::RUN_ACTION ) ),
				esc_html__( 'Run the daily check now', 'lafka-plugin' )
			);
		}

		/**
		 * UTC MySQL datetime → site time.
		 *
		 * @param string $gmt Y-m-d H:i:s UTC.
		 * @return string
		 */
		private function local_time( string $gmt ): string {
			return '' === $gmt ? '' : (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' );
		}
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		Lafka_Diagnostics_Page::instance();
	}
}
