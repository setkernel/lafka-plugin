<?php
/**
 * Lafka_Insights_Narrative — the report in plain English (pure functions).
 *
 * build() turns a Lafka_Insights_Queries report into a short list of
 * sentences for the weekly owner email and the top of Lafka → Insights, e.g.
 *
 *   "112 people visited; 31 opened the menu; 9 added food to a cart;
 *    3 reached checkout; 1 ordered."
 *   "The biggest drop was between cart and checkout: 6 of 9 left."
 *   "14 visits came while you were closed, mostly Sunday 10:00–11:00."
 *   "“Veggie Wrap” was viewed 22 times but never ordered."
 *   "2 card payments were declined for an address mismatch (AVS) — …"
 *
 * Small-number honesty: a share is written as "3 of 9" (never "33%") when the
 * denominator is under 20, and week-on-week trends are only mentioned when
 * both weeks averaged at least 30 visits. Zero visits means tracking may be
 * broken, and the text says so.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Narrative' ) ) {

	final class Lafka_Insights_Narrative {

		/** Below this denominator a share is written as "n of N". */
		const MIN_PERCENT_BASE = 20;

		/** Trends need at least this many visits per week in both periods. */
		const MIN_WEEKLY_VISITS_FOR_TREND = 30;

		/** A product needs this many views before "viewed but never ordered" is said. */
		const MIN_VIEWS_NOT_BOUGHT = 5;

		/**
		 * "3 of 9" below the base, "33%" at or above it.
		 *
		 * @param int $part  Numerator.
		 * @param int $whole Denominator.
		 * @return string
		 */
		public static function share( int $part, int $whole ): string {
			if ( $whole <= 0 ) {
				return '0';
			}
			if ( $whole < self::MIN_PERCENT_BASE ) {
				/* translators: 1: part, 2: whole (e.g. "3 of 9"). */
				return sprintf( __( '%1$d of %2$d', 'lafka-plugin' ), $part, $whole );
			}
			return sprintf( '%d%%', (int) round( 100 * $part / $whole ) );
		}

		/**
		 * Whether a period-over-period trend may be shown.
		 *
		 * @param int $visits      Visits this period.
		 * @param int $prev_visits Visits the previous period.
		 * @param int $days        Period length in days.
		 * @return bool
		 */
		public static function trend_allowed( int $visits, int $prev_visits, int $days ): bool {
			$weeks = max( 1, $days ) / 7;
			return ( $visits / $weeks ) >= self::MIN_WEEKLY_VISITS_FOR_TREND
				&& ( $prev_visits / $weeks ) >= self::MIN_WEEKLY_VISITS_FOR_TREND;
		}

		/**
		 * Human label for a funnel stage.
		 *
		 * @param string $stage Stage slug.
		 * @return string
		 */
		public static function stage_label( string $stage ): string {
			$labels = array(
				'visit'       => __( 'visit', 'lafka-plugin' ),
				'menu'        => __( 'menu', 'lafka-plugin' ),
				'product'     => __( 'product page', 'lafka-plugin' ),
				'add'         => __( 'add to cart', 'lafka-plugin' ),
				'cart'        => __( 'cart', 'lafka-plugin' ),
				'checkout'    => __( 'checkout', 'lafka-plugin' ),
				'pay_attempt' => __( 'payment', 'lafka-plugin' ),
				'order'       => __( 'order', 'lafka-plugin' ),
			);
			return $labels[ $stage ] ?? $stage;
		}

		/**
		 * Human label for a checkout-refusal reason. Filterable through
		 * `lafka_checkout_block_reasons` (the shared reason → label map).
		 *
		 * @param string $reason Reason slug.
		 * @return string
		 */
		public static function reason_label( string $reason ): string {
			$labels = array(
				'store_closed'           => __( 'the store was closed', 'lafka-plugin' ),
				'outside_delivery_zone'  => __( 'the address was outside the delivery zone', 'lafka-plugin' ),
				'address_unpinned'       => __( 'the delivery location was not pinned', 'lafka-plugin' ),
				'below_delivery_minimum' => __( 'the order was below the delivery minimum', 'lafka-plugin' ),
				'timeslot_invalid'       => __( 'the chosen time slot was not available', 'lafka-plugin' ),
				'branch_invalid'         => __( 'the chosen branch was not available', 'lafka-plugin' ),
				'addon_invalid'          => __( 'an item option was invalid', 'lafka-plugin' ),
				'validation'             => __( 'a checkout field was rejected', 'lafka-plugin' ),
				'no_shipping_method'     => __( 'no delivery method was available', 'lafka-plugin' ),
				'payment_failed'         => __( 'the payment failed', 'lafka-plugin' ),
				'payment_declined'       => __( 'the card was declined', 'lafka-plugin' ),
				'payment_avs'            => __( 'the card address did not match (AVS)', 'lafka-plugin' ),
				'payment_cvv'            => __( 'the card security code did not match', 'lafka-plugin' ),
				'payment_gateway_error'  => __( 'the payment gateway had an error', 'lafka-plugin' ),
				'payment_other'          => __( 'the payment failed', 'lafka-plugin' ),
				'none'                   => __( 'no error was shown', 'lafka-plugin' ),
			);
			if ( function_exists( 'apply_filters' ) ) {
				$labels = (array) apply_filters( 'lafka_checkout_block_reasons', $labels );
			}
			return isset( $labels[ $reason ] ) && is_string( $labels[ $reason ] ) ? $labels[ $reason ] : str_replace( '_', ' ', $reason );
		}

		/**
		 * Localised weekday name (0 = Sunday).
		 *
		 * @param int $dow Day of week.
		 * @return string
		 */
		public static function weekday( int $dow ): string {
			global $wp_locale;
			if ( is_object( $wp_locale ) && method_exists( $wp_locale, 'get_weekday' ) ) {
				return (string) $wp_locale->get_weekday( $dow );
			}
			$names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
			return $names[ $dow % 7 ];
		}

		/**
		 * Busiest "dow-hour" slot of a map, or null.
		 *
		 * @param array<string,int> $slots "dow-hour" => count.
		 * @return array{dow:int,hour:int,count:int}|null
		 */
		public static function peak_slot( array $slots ): ?array {
			$best = null;
			foreach ( $slots as $slot => $count ) {
				if ( 1 !== preg_match( '/^(\d)-(\d{1,2})$/', (string) $slot, $m ) ) {
					continue;
				}
				if ( null === $best || (int) $count > $best['count'] ) {
					$best = array(
						'dow'   => (int) $m[1],
						'hour'  => (int) $m[2],
						'count' => (int) $count,
					);
				}
			}
			return $best;
		}

		/**
		 * The plain-English summary. Pure: depends only on the report array.
		 *
		 * @param array<string,mixed> $report Lafka_Insights_Queries report.
		 * @return array<int,string> Sentences.
		 */
		public static function build( array $report ): array {
			$funnel = (array) ( $report['funnel'] ?? array() );
			$visits = (int) ( $funnel['visit'] ?? 0 );
			$days   = (int) ( $report['days'] ?? 7 );

			if ( 0 === $visits ) {
				return array(
					sprintf(
						/* translators: %d: number of days. */
						_n( 'No visits were recorded in the last %d day.', 'No visits were recorded in the last %d days.', $days, 'lafka-plugin' ),
						$days
					) . ' ' . __( 'Tracking may be broken: check Tools → Site Health, and that nothing (a cache, a security plugin or a content-security policy) blocks the Insights beacon.', 'lafka-plugin' ),
				);
			}

			$out = array();

			$parts = array(
				/* translators: %d: visitors. */
				sprintf( _n( '%d person visited', '%d people visited', $visits, 'lafka-plugin' ), $visits ),
				/* translators: %d: visitors. */
				sprintf( _n( '%d opened the menu', '%d opened the menu', (int) ( $funnel['menu'] ?? 0 ), 'lafka-plugin' ), (int) ( $funnel['menu'] ?? 0 ) ),
				/* translators: %d: visitors. */
				sprintf( _n( '%d added food to a cart', '%d added food to a cart', (int) ( $funnel['add'] ?? 0 ), 'lafka-plugin' ), (int) ( $funnel['add'] ?? 0 ) ),
				/* translators: %d: visitors. */
				sprintf( _n( '%d reached checkout', '%d reached checkout', (int) ( $funnel['checkout'] ?? 0 ), 'lafka-plugin' ), (int) ( $funnel['checkout'] ?? 0 ) ),
				/* translators: %d: visitors. */
				sprintf( _n( '%d ordered', '%d ordered', (int) ( $funnel['order'] ?? 0 ), 'lafka-plugin' ), (int) ( $funnel['order'] ?? 0 ) ),
			);
			$out[] = implode( '; ', $parts ) . '.';

			$leak = $report['leak'] ?? null;
			if ( is_array( $leak ) && (int) ( $leak['lost'] ?? 0 ) > 0 ) {
				$out[] = sprintf(
					/* translators: 1: funnel step, 2: next funnel step, 3: share that left ("6 of 9" or "67%"). */
					__( 'The biggest drop was between %1$s and %2$s: %3$s left.', 'lafka-plugin' ),
					self::stage_label( (string) $leak['from'] ),
					self::stage_label( (string) $leak['to'] ),
					self::share( (int) $leak['lost'], (int) $leak['of'] )
				);
			}

			$abandon = (array) ( $report['abandon'] ?? array() );
			$left    = array_sum( array_map( 'intval', $abandon ) );
			unset( $abandon['none'] );
			arsort( $abandon );
			if ( $left > 0 && ! empty( $abandon ) ) {
				$reason = (string) array_key_first( $abandon );
				$out[]  = sprintf(
					/* translators: 1: number of visitors who left with a cart, 2: share ("4 of 9"), 3: reason. */
					_n( '%1$d visitor left with food in the cart; for %2$s of them, %3$s.', '%1$d visitors left with food in the cart; for %2$s of them, %3$s.', $left, 'lafka-plugin' ),
					$left,
					self::share( (int) $abandon[ $reason ], $left ),
					self::reason_label( $reason )
				);
			}

			$closed = (int) ( $report['closed_visits'] ?? 0 );
			if ( $closed > 0 ) {
				$peak     = self::peak_slot( (array) ( $report['closed_hour_dow'] ?? array() ) );
				$sentence = sprintf(
					/* translators: %d: visits. */
					_n( '%d visit came while you were closed', '%d visits came while you were closed', $closed, 'lafka-plugin' ),
					$closed
				);
				if ( null !== $peak && $closed >= 3 ) {
					$sentence .= sprintf(
						/* translators: 1: weekday, 2: start hour (HH:00), 3: end hour. */
						__( ', mostly %1$s %2$s–%3$s', 'lafka-plugin' ),
						self::weekday( $peak['dow'] ),
						sprintf( '%02d:00', $peak['hour'] ),
						sprintf( '%02d:00', ( $peak['hour'] + 1 ) % 24 )
					);
				}
				$out[] = $sentence . '.';
			}

			$not_bought = array();
			foreach ( (array) ( $report['items'] ?? array() ) as $item ) {
				if ( (int) ( $item['views'] ?? 0 ) >= self::MIN_VIEWS_NOT_BOUGHT && 0 === (int) ( $item['orders'] ?? 0 ) ) {
					$not_bought[] = $item;
				}
			}
			usort(
				$not_bought,
				static function ( $a, $b ) {
					return (int) $b['views'] <=> (int) $a['views'];
				}
			);
			foreach ( array_slice( $not_bought, 0, 2 ) as $item ) {
				$out[] = sprintf(
					/* translators: 1: product name, 2: views. */
					_n( '“%1$s” was viewed %2$d time but never ordered.', '“%1$s” was viewed %2$d times but never ordered.', (int) $item['views'], 'lafka-plugin' ),
					(string) $item['name'],
					(int) $item['views']
				);
			}

			$zero = (array) ( $report['search_zero'] ?? array() );
			if ( ! empty( $zero ) ) {
				$term  = (string) array_key_first( $zero );
				$out[] = sprintf(
					/* translators: 1: search term, 2: times. */
					_n( 'Someone searched the menu for “%1$s” and found nothing (%2$d time).', 'People searched the menu for “%1$s” and found nothing (%2$d times).', (int) $zero[ $term ], 'lafka-plugin' ),
					$term,
					(int) $zero[ $term ]
				);
			}

			$fails = (array) ( $report['pay_fail'] ?? array() );
			foreach ( array( 'avs', 'cvv', 'declined', 'gateway_error' ) as $class ) {
				$n = (int) ( $fails[ $class ] ?? 0 );
				if ( $n <= 0 ) {
					continue;
				}
				switch ( $class ) {
					case 'avs':
						/* translators: %d: failed payments. */
						$out[] = sprintf( _n( '%d card payment was declined for an address mismatch (AVS) — consider relaxing the AVS rules in your payment gateway.', '%d card payments were declined for an address mismatch (AVS) — consider relaxing the AVS rules in your payment gateway.', $n, 'lafka-plugin' ), $n );
						break;
					case 'cvv':
						/* translators: %d: failed payments. */
						$out[] = sprintf( _n( '%d card payment failed on the security code (CVV).', '%d card payments failed on the security code (CVV).', $n, 'lafka-plugin' ), $n );
						break;
					case 'declined':
						/* translators: %d: failed payments. */
						$out[] = sprintf( _n( '%d card payment was declined by the bank.', '%d card payments were declined by the bank.', $n, 'lafka-plugin' ), $n );
						break;
					default:
						/* translators: %d: failed payments. */
						$out[] = sprintf( _n( '%d payment failed because of a payment-gateway error.', '%d payments failed because of a payment-gateway error.', $n, 'lafka-plugin' ), $n );
				}
			}

			$prev = (array) ( $report['prev'] ?? array() );
			$prev_visits = (int) ( $prev['visit'] ?? 0 );
			if ( self::trend_allowed( $visits, $prev_visits, $days ) ) {
				$change = (int) round( 100 * ( $visits - $prev_visits ) / $prev_visits );
				if ( 0 !== $change ) {
					$out[] = $change > 0
						/* translators: %d: percent. */
						? sprintf( __( 'Visits were up %d%% on the previous period.', 'lafka-plugin' ), $change )
						/* translators: %d: percent. */
						: sprintf( __( 'Visits were down %d%% on the previous period.', 'lafka-plugin' ), abs( $change ) );
				}
			}

			return $out;
		}
	}
}
