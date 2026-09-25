<?php
/**
 * Lafka_Diagnostics_Health — Site Health tests + debug info for Diagnostics (GX1 / A8).
 *
 * Tools → Site Health → Status gains (module `diagnostics` on):
 *   - lafka_recent_fatals     critical when Lafka code fataled in the last 24 h;
 *   - lafka_payment_failures  recommended at ≥ 3 payment failures in 7 days
 *                             (filter `lafka_payment_failure_alert_threshold`);
 *   - lafka_cron_health       recommended when the daily Diagnostics job has
 *                             not run for 2 days (background jobs not running).
 * The Info tab gains a "Lafka diagnostics" section.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Diagnostics_Health' ) ) {

	/**
	 * Site Health integration.
	 */
	final class Lafka_Diagnostics_Health {

		/**
		 * Hook Site Health (admin only; module gated at call time).
		 *
		 * @return void
		 */
		public static function register(): void {
			add_filter( 'site_status_tests', array( __CLASS__, 'add_tests' ) );
			add_filter( 'debug_information', array( __CLASS__, 'add_debug_information' ) );
		}

		/**
		 * @param mixed $tests Site Health tests.
		 * @return array
		 */
		public static function add_tests( $tests ) {
			$tests = is_array( $tests ) ? $tests : array();
			if ( ! Lafka_Diagnostics::is_enabled() ) {
				return $tests;
			}
			$tests['direct']['lafka_recent_fatals']    = array(
				'label' => __( 'Lafka fatal errors', 'lafka-plugin' ),
				'test'  => array( __CLASS__, 'test_recent_fatals' ),
			);
			$tests['direct']['lafka_payment_failures'] = array(
				'label' => __( 'Lafka payment failures', 'lafka-plugin' ),
				'test'  => array( __CLASS__, 'test_payment_failures' ),
			);
			$tests['direct']['lafka_cron_health']      = array(
				'label' => __( 'Lafka background jobs', 'lafka-plugin' ),
				'test'  => array( __CLASS__, 'test_cron_health' ),
			);
			return $tests;
		}

		/**
		 * Critical when a Lafka fatal was recorded in the last 24 hours.
		 *
		 * @return array<string,mixed>
		 */
		public static function test_recent_fatals(): array {
			$count = Lafka_Incidents::recent_count( 'php', 24 );
			if ( $count > 0 ) {
				return self::result(
					'lafka_recent_fatals',
					'critical',
					'red',
					sprintf( _n( 'Lafka code caused %d fatal error in the last 24 hours', 'Lafka code caused %d fatal errors in the last 24 hours', $count, 'lafka-plugin' ), $count ),
					__( 'A PHP fatal error inside the Lafka plugin or theme stopped a page from loading. Open Lafka → Diagnostics for the file, line and how often it happened.', 'lafka-plugin' ),
					true
				);
			}
			return self::result( 'lafka_recent_fatals', 'good', 'blue', __( 'No Lafka fatal errors in the last 24 hours', 'lafka-plugin' ), __( 'No PHP fatal error was traced to Lafka code recently.', 'lafka-plugin' ) );
		}

		/**
		 * Recommended at ≥ threshold payment failures in 7 days.
		 *
		 * @return array<string,mixed>
		 */
		public static function test_payment_failures(): array {
			$threshold = (int) apply_filters( 'lafka_payment_failure_alert_threshold', 3 );
			$count     = Lafka_Checkout_Failures::payment_failures( 7 );
			if ( $count >= max( 1, $threshold ) ) {
				return self::result(
					'lafka_payment_failures',
					'recommended',
					'orange',
					sprintf( _n( '%d payment failed in the last 7 days', '%d payments failed in the last 7 days', $count, 'lafka-plugin' ), $count ),
					__( 'Customers tried to pay and the payment was refused. Lafka → Diagnostics → Checkout failures shows whether cards were declined, failed the address check (AVS) or security code (CVV), or the gateway errored — address-check declines often mean the gateway\'s AVS rules are stricter than your customers\' billing details.', 'lafka-plugin' ),
					true
				);
			}
			return self::result( 'lafka_payment_failures', 'good', 'blue', __( 'Payments are going through', 'lafka-plugin' ), __( 'Fewer payment failures than the alert threshold in the last 7 days.', 'lafka-plugin' ) );
		}

		/**
		 * Recommended when the daily job has not run for 2 days.
		 *
		 * @return array<string,mixed>
		 */
		public static function test_cron_health(): array {
			$last = (int) get_option( Lafka_Diagnostics::LAST_RUN_OPTION, 0 );
			if ( ! function_exists( 'as_next_scheduled_action' ) ) {
				return self::result( 'lafka_cron_health', 'recommended', 'orange', __( 'Action Scheduler is not available', 'lafka-plugin' ), __( 'Lafka schedules its daily maintenance (incident clean-up, error digest) with WooCommerce\'s Action Scheduler, which was not found. Make sure WooCommerce is active.', 'lafka-plugin' ) );
			}
			if ( $last > 0 && ( time() - $last ) > 2 * DAY_IN_SECONDS ) {
				return self::result(
					'lafka_cron_health',
					'recommended',
					'orange',
					__( 'Lafka background jobs are not running', 'lafka-plugin' ),
					sprintf(
						/* translators: %s: human time difference, e.g. "3 days" */
						__( 'The daily Diagnostics job last ran %s ago. Scheduled tasks (WP-Cron / Action Scheduler) appear to be stalled, so error digests, abandoned-cart emails and clean-ups are not happening. If WP-Cron is disabled, make sure a real system cron calls wp-cron.php.', 'lafka-plugin' ),
						human_time_diff( $last, time() )
					)
				);
			}
			if ( 0 === $last && false === as_next_scheduled_action( Lafka_Diagnostics::DAILY_HOOK, array(), Lafka_Diagnostics::AS_GROUP ) ) {
				return self::result( 'lafka_cron_health', 'recommended', 'orange', __( 'Lafka daily maintenance is not scheduled', 'lafka-plugin' ), __( 'Visit any admin page once to let Lafka schedule its daily Diagnostics job.', 'lafka-plugin' ) );
			}
			return self::result( 'lafka_cron_health', 'good', 'blue', __( 'Lafka background jobs are running', 'lafka-plugin' ), __( 'The daily Diagnostics job is scheduled and running.', 'lafka-plugin' ) );
		}

		/**
		 * "Lafka diagnostics" Info section.
		 *
		 * @param mixed $info Debug info.
		 * @return array
		 */
		public static function add_debug_information( $info ) {
			$info   = is_array( $info ) ? $info : array();
			$counts = Lafka_Incidents::status_counts();
			$last   = (int) get_option( Lafka_Diagnostics::LAST_RUN_OPTION, 0 );

			$info['lafka_diagnostics'] = array(
				'label'  => __( 'Lafka diagnostics', 'lafka-plugin' ),
				'fields' => array(
					'module'        => array(
						'label' => __( 'Diagnostics module', 'lafka-plugin' ),
						'value' => Lafka_Diagnostics::is_enabled() ? __( 'Enabled', 'lafka-plugin' ) : __( 'Disabled (logging still on)', 'lafka-plugin' ),
					),
					'min_level'     => array(
						'label' => __( 'Minimum log level', 'lafka-plugin' ),
						'value' => Lafka_Log::min_level(),
					),
					'wc_handler'    => array(
						'label' => __( 'WooCommerce log handler', 'lafka-plugin' ),
						'value' => '' !== Lafka_Diagnostics::log_handler() ? Lafka_Diagnostics::log_handler() : __( 'Unknown', 'lafka-plugin' ),
					),
					'open'          => array(
						'label' => __( 'Open incidents', 'lafka-plugin' ),
						'value' => (string) $counts['open'],
					),
					'checkout_30d'  => array(
						'label' => __( 'Checkout refusals (30 days)', 'lafka-plugin' ),
						'value' => (string) array_sum( Lafka_Checkout_Failures::totals( 30 ) ),
					),
					'last_daily'    => array(
						'label' => __( 'Daily job last run', 'lafka-plugin' ),
						'value' => $last > 0 ? gmdate( 'Y-m-d H:i', $last ) . ' UTC' : __( 'Never', 'lafka-plugin' ),
					),
					'incidents_db'  => array(
						'label' => __( 'Incident table', 'lafka-plugin' ),
						'value' => Lafka_Incidents::is_installed() ? Lafka_Incidents::DB_VERSION : __( 'Not installed', 'lafka-plugin' ),
					),
				),
			);
			return $info;
		}

		/**
		 * Site Health result array.
		 *
		 * @param string $test        Test id.
		 * @param string $status      good | recommended | critical.
		 * @param string $color       Badge colour.
		 * @param string $label       Heading.
		 * @param string $description Body text.
		 * @param bool   $link        Add a link to Lafka → Diagnostics.
		 * @return array<string,mixed>
		 */
		private static function result( string $test, string $status, string $color, string $label, string $description, bool $link = false ): array {
			$result = array(
				'label'       => esc_html( $label ),
				'status'      => $status,
				'badge'       => array(
					'label' => esc_html__( 'Lafka', 'lafka-plugin' ),
					'color' => $color,
				),
				'description' => '<p>' . esc_html( $description ) . '</p>',
				'test'        => $test,
			);
			if ( $link && function_exists( 'admin_url' ) ) {
				$result['actions'] = '<p><a href="' . esc_url( admin_url( 'admin.php?page=lafka-diagnostics' ) ) . '">' . esc_html__( 'Open Lafka → Diagnostics', 'lafka-plugin' ) . '</a></p>';
			}
			return $result;
		}
	}
}
