<?php
/**
 * Lafka_Insights_Page — "Lafka → Insights" (GX2 / B3).
 *
 * Server-rendered with core admin styles and inline SVG bars: no JS
 * framework, no chart library, nothing enqueued. Sections:
 *
 *   1. Funnel — visits → menu → product → added → cart → checkout → payment →
 *      order, with the step-to-step drop-off and the biggest leak called out.
 *   2. Why no order — visits that reached the cart without ordering, grouped by
 *      the last checkout refusal; plus visits while the store was closed.
 *   3. Items — viewed / added / ordered, add→order per item, and the
 *      "viewed but not bought" list.
 *   4. Search — top terms and zero-result terms.
 *   5. Audience — device, visits vs orders by source (orders from WooCommerce
 *      Order Attribution), top sources + campaigns, hour × weekday heatmap.
 *   6. Payment health — payment attempts vs failures by class.
 *
 * Small-number honesty: shares are "3 of 9" below 20; trends only when both
 * periods averaged ≥ 30 visits a week. Data comes from
 * Lafka_Insights_Queries::report() (10-minute cache).
 *
 * @package Lafka\Plugin\Admin
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Page' ) ) {

	final class Lafka_Insights_Page {

		const PARENT_SLUG = 'lafka-modules';
		const MENU_SLUG   = 'lafka-insights';
		const CAPABILITY  = 'manage_woocommerce';

		/** @var Lafka_Insights_Page|null */
		private static $instance = null;

		/**
		 * @return Lafka_Insights_Page
		 */
		public static function instance(): Lafka_Insights_Page {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		}

		/**
		 * Submenu under the top-level Lafka menu.
		 *
		 * @return void
		 */
		public function register_menu(): void {
			add_submenu_page(
				self::PARENT_SLUG,
				esc_html__( 'Lafka Insights', 'lafka-plugin' ),
				esc_html__( 'Insights', 'lafka-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * The requested range (7 / 30 / 90). Read-only view parameter.
		 *
		 * @return int
		 */
		public static function requested_range(): int {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report range, no state change.
			$raw = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '30';
			return Lafka_Insights_Queries::sanitize_range( $raw );
		}

		/**
		 * Render the screen.
		 *
		 * @return void
		 */
		public function render_page(): void {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to view Insights.', 'lafka-plugin' ), 403 );
			}
			echo '<div class="wrap lafka-insights">';
			echo '<h1>' . esc_html__( 'Insights', 'lafka-plugin' ) . '</h1>';
			if ( ! Lafka_Insights::is_enabled() ) {
				self::render_off_notice();
				echo '</div>';
				return;
			}
			$days   = self::requested_range();
			$report = Lafka_Insights_Queries::report( $days );

			self::render_styles();
			self::render_range_tabs( $days );
			self::render_mode_notice();
			$this->render_report( $report );
			echo '</div>';
		}

		/**
		 * Render every section for a report (also used by tests).
		 *
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		public function render_report( array $report ): void {
			$sentences = Lafka_Insights_Narrative::build( $report );
			echo '<div class="notice notice-info inline lafka-insights__summary"><ul>';
			foreach ( $sentences as $sentence ) {
				echo '<li>' . esc_html( $sentence ) . '</li>';
			}
			echo '</ul></div>';

			if ( 0 === (int) ( $report['funnel']['visit'] ?? 0 ) ) {
				return;
			}
			self::render_funnel( $report );
			self::render_why_no_order( $report );
			self::render_items( (array) ( $report['items'] ?? array() ) );
			self::render_search( $report );
			self::render_audience( $report );
			self::render_payments( $report );
		}

		// ─── Sections ────────────────────────────────────────────────────────

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_funnel( array $report ): void {
			$funnel = (array) $report['funnel'];
			$leak   = $report['leak'] ?? null;
			$max    = max( 1, (int) ( $funnel['visit'] ?? 0 ) );
			self::section_open( __( 'Funnel', 'lafka-plugin' ) );
			self::render_trend( $report );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Step', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Visits', 'lafka-plugin' ) . '</th><th></th><th>' . esc_html__( 'Left before this step', 'lafka-plugin' ) . '</th></tr></thead><tbody>';
			$prev = null;
			foreach ( $funnel as $stage => $count ) {
				$is_leak = is_array( $leak ) && $leak['to'] === $stage;
				echo '<tr' . ( $is_leak ? ' class="lafka-insights__leak"' : '' ) . '>';
				echo '<td>' . esc_html( ucfirst( Lafka_Insights_Narrative::stage_label( (string) $stage ) ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) $count ) ) . '</td>';
				echo '<td>' . self::bar( (int) $count, $max ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bar() builds escaped SVG.
				echo '<td>';
				if ( null !== $prev && $prev > 0 ) {
					$lost = max( 0, $prev - (int) $count );
					echo esc_html( Lafka_Insights_Narrative::share( $lost, $prev ) );
					if ( $is_leak ) {
						echo ' <strong>' . esc_html__( '← biggest leak', 'lafka-plugin' ) . '</strong>';
					}
				}
				echo '</td></tr>';
				$prev = (int) $count;
			}
			echo '</tbody></table>';
			self::section_close();
		}

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_why_no_order( array $report ): void {
			$abandon = (array) ( $report['abandon'] ?? array() );
			$total   = array_sum( array_map( 'intval', $abandon ) );
			self::section_open( __( 'Why no order', 'lafka-plugin' ) );
			if ( $total > 0 ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %d: visits. */
						_n( '%d visit reached the cart but did not order. Last reason shown to the customer:', '%d visits reached the cart but did not order. Last reason shown to the customer:', $total, 'lafka-plugin' ),
						$total
					)
				) . '</p>';
				self::render_bar_table( $abandon, $total, array( 'Lafka_Insights_Narrative', 'reason_label' ) );
			} else {
				echo '<p>' . esc_html__( 'No visit left with food in the cart in this period.', 'lafka-plugin' ) . '</p>';
			}
			$closed = (int) ( $report['closed_visits'] ?? 0 );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: visits. */
					_n( 'Visits while the store was closed: %d (buy buttons are hidden then, so these are silent losses).', 'Visits while the store was closed: %d (buy buttons are hidden then, so these are silent losses).', $closed, 'lafka-plugin' ),
					$closed
				)
			) . '</p>';
			self::section_close();
		}

		/**
		 * @param array<string,array<string,mixed>> $items Items.
		 * @return void
		 */
		private static function render_items( array $items ): void {
			self::section_open( __( 'Items', 'lafka-plugin' ) );
			if ( empty( $items ) ) {
				echo '<p>' . esc_html__( 'No item activity yet.', 'lafka-plugin' ) . '</p>';
				self::section_close();
				return;
			}
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Viewed', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Added', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Ordered', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Added → ordered', 'lafka-plugin' ) . '</th></tr></thead><tbody>';
			$not_bought = array();
			foreach ( $items as $item ) {
				$adds   = (int) $item['adds'];
				$orders = (int) $item['orders'];
				echo '<tr><td>' . esc_html( (string) $item['name'] ) . '</td><td>' . esc_html( (string) (int) $item['views'] ) . '</td><td>' . esc_html( (string) $adds ) . '</td><td>' . esc_html( (string) $orders ) . '</td><td>' . esc_html( $adds > 0 ? Lafka_Insights_Narrative::share( min( $orders, $adds ), $adds ) : '—' ) . '</td></tr>';
				if ( (int) $item['views'] >= Lafka_Insights_Narrative::MIN_VIEWS_NOT_BOUGHT && 0 === $orders ) {
					$not_bought[] = $item;
				}
			}
			echo '</tbody></table>';
			echo '<h3>' . esc_html__( 'Viewed but not bought', 'lafka-plugin' ) . '</h3>';
			if ( empty( $not_bought ) ) {
				/* translators: %d: minimum views. */
				echo '<p>' . esc_html( sprintf( __( 'No item was viewed %d or more times without an order.', 'lafka-plugin' ), Lafka_Insights_Narrative::MIN_VIEWS_NOT_BOUGHT ) ) . '</p>';
			} else {
				echo '<ul>';
				foreach ( $not_bought as $item ) {
					/* translators: 1: product name, 2: views. */
					echo '<li>' . esc_html( sprintf( __( '%1$s — %2$d views, 0 orders', 'lafka-plugin' ), (string) $item['name'], (int) $item['views'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			self::section_close();
		}

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_search( array $report ): void {
			self::section_open( __( 'Menu search', 'lafka-plugin' ) );
			echo '<div class="lafka-insights__cols"><div><h3>' . esc_html__( 'Top searches', 'lafka-plugin' ) . '</h3>';
			self::render_count_list( (array) ( $report['search'] ?? array() ) );
			echo '</div><div><h3>' . esc_html__( 'Searches that found nothing', 'lafka-plugin' ) . '</h3>';
			self::render_count_list( (array) ( $report['search_zero'] ?? array() ) );
			echo '</div></div>';
			self::section_close();
		}

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_audience( array $report ): void {
			$visits = (int) ( $report['funnel']['visit'] ?? 0 );
			self::section_open( __( 'Audience', 'lafka-plugin' ) );

			echo '<h3>' . esc_html__( 'Device', 'lafka-plugin' ) . '</h3>';
			$device_labels = array(
				'mobile'  => __( 'Phone', 'lafka-plugin' ),
				'tablet'  => __( 'Tablet', 'lafka-plugin' ),
				'desktop' => __( 'Desktop', 'lafka-plugin' ),
				'unknown' => __( 'Unknown', 'lafka-plugin' ),
			);
			self::render_bar_table(
				(array) ( $report['device'] ?? array() ),
				$visits,
				static function ( $key ) use ( $device_labels ) {
					return $device_labels[ $key ] ?? $key;
				}
			);

			echo '<h3>' . esc_html__( 'Visits and orders by source', 'lafka-plugin' ) . '</h3>';
			$sources = (array) ( $report['source'] ?? array() );
			$orders  = (array) ( $report['orders_by_source'] ?? array() );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Visits', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Orders', 'lafka-plugin' ) . '</th><th>' . esc_html__( 'Orders per visit', 'lafka-plugin' ) . '</th></tr></thead><tbody>';
			foreach ( array_unique( array_merge( array_keys( $sources ), array_keys( $orders ) ) ) as $type ) {
				$v = (int) ( $sources[ $type ] ?? 0 );
				$o = (int) ( $orders[ $type ] ?? 0 );
				echo '<tr><td>' . esc_html( self::source_label( (string) $type ) ) . '</td><td>' . esc_html( (string) $v ) . '</td><td>' . esc_html( (string) $o ) . '</td><td>' . esc_html( $v > 0 ? Lafka_Insights_Narrative::share( min( $o, $v ), $v ) : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Orders by source come from WooCommerce Order Attribution; visits from Insights.', 'lafka-plugin' ) . '</p>';

			echo '<div class="lafka-insights__cols"><div><h3>' . esc_html__( 'Top sources', 'lafka-plugin' ) . '</h3>';
			self::render_count_list( (array) ( $report['source_name'] ?? array() ) );
			echo '</div><div><h3>' . esc_html__( 'Campaigns', 'lafka-plugin' ) . '</h3>';
			self::render_count_list( (array) ( $report['campaign'] ?? array() ) );
			echo '</div></div>';

			echo '<h3>' . esc_html__( 'When people visit (hour × weekday, site time)', 'lafka-plugin' ) . '</h3>';
			self::render_heatmap( (array) ( $report['hour_dow'] ?? array() ) );
			self::section_close();
		}

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_payments( array $report ): void {
			$attempts = (int) ( $report['funnel']['pay_attempt'] ?? 0 );
			$fails    = (array) ( $report['pay_fail'] ?? array() );
			$failed   = array_sum( array_map( 'intval', $fails ) );
			self::section_open( __( 'Payment health', 'lafka-plugin' ) );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: visits that attempted payment, 2: failed payments, 3: orders. */
					__( 'Visits that tried to pay: %1$d · failed payments: %2$d · orders: %3$d', 'lafka-plugin' ),
					$attempts,
					$failed,
					(int) ( $report['funnel']['order'] ?? 0 )
				)
			) . '</p>';
			if ( $failed > 0 ) {
				$labels = array(
					'avs'           => __( 'Address mismatch (AVS)', 'lafka-plugin' ),
					'cvv'           => __( 'Security code (CVV)', 'lafka-plugin' ),
					'declined'      => __( 'Declined by the bank', 'lafka-plugin' ),
					'gateway_error' => __( 'Gateway error', 'lafka-plugin' ),
					'other'         => __( 'Other', 'lafka-plugin' ),
				);
				self::render_bar_table(
					$fails,
					$failed,
					static function ( $key ) use ( $labels ) {
						return $labels[ $key ] ?? $key;
					}
				);
			}
			$diag = function_exists( 'apply_filters' ) ? (string) apply_filters( 'lafka_insights_diagnostics_url', class_exists( 'Lafka_Diagnostics_Page' ) ? admin_url( 'admin.php?page=lafka-diagnostics' ) : '' ) : '';
			if ( '' !== $diag ) {
				echo '<p><a href="' . esc_url( $diag ) . '">' . esc_html__( 'See the failure details in Diagnostics', 'lafka-plugin' ) . '</a></p>';
			}
			self::section_close();
		}

		// ─── Helpers ─────────────────────────────────────────────────────────

		/**
		 * @param array<string,mixed> $report Report.
		 * @return void
		 */
		private static function render_trend( array $report ): void {
			$visits = (int) ( $report['funnel']['visit'] ?? 0 );
			$prev   = (int) ( $report['prev']['visit'] ?? 0 );
			if ( ! Lafka_Insights_Narrative::trend_allowed( $visits, $prev, (int) ( $report['days'] ?? 30 ) ) ) {
				echo '<p class="description">' . esc_html__( 'Trends appear once both this and the previous period average at least 30 visits a week.', 'lafka-plugin' ) . '</p>';
				return;
			}
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: visits now, 2: visits previous period, 3: orders now, 4: orders previous period. */
					__( 'Visits %1$d (previous period %2$d) · orders %3$d (previous period %4$d)', 'lafka-plugin' ),
					$visits,
					$prev,
					(int) ( $report['funnel']['order'] ?? 0 ),
					(int) ( $report['prev']['order'] ?? 0 )
				)
			) . '</p>';
		}

		/**
		 * dim => count table with bars and shares.
		 *
		 * @param array<string,int> $rows  Rows.
		 * @param int               $total Denominator.
		 * @param callable          $label Key → label.
		 * @return void
		 */
		private static function render_bar_table( array $rows, int $total, callable $label ): void {
			$max = max( 1, (int) max( array_merge( array( 0 ), array_map( 'intval', $rows ) ) ) );
			echo '<table class="widefat striped"><tbody>';
			foreach ( $rows as $key => $count ) {
				echo '<tr><td>' . esc_html( (string) call_user_func( $label, (string) $key ) ) . '</td><td>' . esc_html( Lafka_Insights_Narrative::share( (int) $count, max( 1, $total ) ) ) . '</td><td>' . self::bar( (int) $count, $max ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bar() builds escaped SVG.
			}
			echo '</tbody></table>';
		}

		/**
		 * @param array<string,int> $rows term => count.
		 * @return void
		 */
		private static function render_count_list( array $rows ): void {
			if ( empty( $rows ) ) {
				echo '<p>' . esc_html__( 'Nothing yet.', 'lafka-plugin' ) . '</p>';
				return;
			}
			echo '<ol>';
			foreach ( $rows as $term => $count ) {
				echo '<li>' . esc_html( (string) $term ) . ' <span class="count">(' . esc_html( (string) (int) $count ) . ')</span></li>';
			}
			echo '</ol>';
		}

		/**
		 * 7 × 24 heatmap as a table; cell shade is the share of the busiest slot.
		 *
		 * @param array<string,int> $slots "dow-hour" => visits.
		 * @return void
		 */
		private static function render_heatmap( array $slots ): void {
			$max = max( 1, (int) max( array_merge( array( 0 ), array_map( 'intval', $slots ) ) ) );
			echo '<table class="lafka-insights__heat"><thead><tr><th></th>';
			for ( $h = 0; $h < 24; $h++ ) {
				echo '<th scope="col">' . esc_html( (string) $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			$start = (int) get_option( 'start_of_week', 1 );
			for ( $i = 0; $i < 7; $i++ ) {
				$dow = ( $start + $i ) % 7;
				echo '<tr><th scope="row">' . esc_html( Lafka_Insights_Narrative::weekday( $dow ) ) . '</th>';
				for ( $h = 0; $h < 24; $h++ ) {
					$n     = (int) ( $slots[ $dow . '-' . $h ] ?? 0 );
					$alpha = $n > 0 ? 0.12 + 0.88 * $n / $max : 0;
					echo '<td title="' . esc_attr( (string) $n ) . '" style="background:rgba(34,113,177,' . esc_attr( (string) round( $alpha, 2 ) ) . ')">' . ( $n > 0 ? esc_html( (string) $n ) : '' ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		/**
		 * Inline SVG bar.
		 *
		 * @param int $value Value.
		 * @param int $max   Scale maximum.
		 * @return string
		 */
		public static function bar( int $value, int $max ): string {
			$width = $max > 0 ? (int) round( 200 * max( 0, $value ) / $max ) : 0;
			return '<svg width="200" height="12" viewBox="0 0 200 12" aria-hidden="true" focusable="false"><rect width="200" height="12" fill="#f0f0f1"/><rect width="' . esc_attr( (string) $width ) . '" height="12" fill="#2271b1"/></svg>';
		}

		/**
		 * @param string $type WC Order Attribution source type.
		 * @return string
		 */
		private static function source_label( string $type ): string {
			$labels = array(
				'typein'     => __( 'Direct (typed in / bookmark)', 'lafka-plugin' ),
				'organic'    => __( 'Search engines', 'lafka-plugin' ),
				'referral'   => __( 'Other websites', 'lafka-plugin' ),
				'utm'        => __( 'Campaign links (UTM)', 'lafka-plugin' ),
				'admin'      => __( 'Created in admin', 'lafka-plugin' ),
				'mobile_app' => __( 'Mobile app', 'lafka-plugin' ),
				'unknown'    => __( 'Unknown', 'lafka-plugin' ),
			);
			return $labels[ $type ] ?? $type;
		}

		/**
		 * @param int $days Current range.
		 * @return void
		 */
		private static function render_range_tabs( int $days ): void {
			echo '<nav class="nav-tab-wrapper">';
			foreach ( Lafka_Insights_Queries::RANGES as $range ) {
				$url = add_query_arg(
					array(
						'page'  => self::MENU_SLUG,
						'range' => $range,
					),
					admin_url( 'admin.php' )
				);
				/* translators: %d: days. */
				echo '<a class="nav-tab' . ( $range === $days ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Last %d days', 'lafka-plugin' ), $range ) ) . '</a>';
			}
			echo '</nav>';
		}

		/**
		 * The module is off: say what it does and where to switch it on.
		 *
		 * @return void
		 */
		public static function render_off_notice(): void {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Insights is off. It measures your ordering funnel on your own site — where visitors drop out and why no order was placed — without cookies or a Google account, and emails you a plain-English summary every Monday.', 'lafka-plugin' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ) . '">' . esc_html__( 'Turn it on in Lafka → Modules', 'lafka-plugin' ) . '</a></p></div>';
		}

		/**
		 * Notice when collection is off (consent mode "off").
		 *
		 * @return void
		 */
		private static function render_mode_notice(): void {
			if ( Lafka_Insights::is_collecting() ) {
				return;
			}
			$url = admin_url( 'customize.php?autofocus[section]=lafka_analytics_insights' );
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Collection is switched off (consent mode "Off"). Existing data is shown; nothing new is recorded.', 'lafka-plugin' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Change the consent mode', 'lafka-plugin' ) . '</a></p></div>';
		}

		/**
		 * @param string $title Section title.
		 * @return void
		 */
		private static function section_open( string $title ): void {
			echo '<div class="card lafka-insights__card"><h2>' . esc_html( $title ) . '</h2>';
		}

		/**
		 * @return void
		 */
		private static function section_close(): void {
			echo '</div>';
		}

		/**
		 * @return void
		 */
		private static function render_styles(): void {
			echo '<style>.lafka-insights__card{max-width:none}.lafka-insights__leak td{background:#fcf0f1}.lafka-insights__cols{display:flex;flex-wrap:wrap;gap:24px}.lafka-insights__cols>div{flex:1 1 280px}.lafka-insights__heat{border-collapse:collapse;font-size:11px}.lafka-insights__heat td,.lafka-insights__heat th{padding:2px 4px;text-align:center;border:1px solid #f0f0f1;min-width:18px}.lafka-insights__summary li{font-size:14px}</style>';
		}
	}
}
