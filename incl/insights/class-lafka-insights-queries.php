<?php
/**
 * Lafka_Insights_Queries — builds the report the admin page and the weekly
 * email read. Aggregates only, bounded ranges (7 / 30 / 90 days), cached for
 * 10 minutes.
 *
 * Sources:
 *   - finished days: rollup rows in {prefix}lafka_insights_daily
 *   - today: the same rollup computed live from today's session rows
 *   - live counters (items, search, refusals, payment failures): the daily table
 *   - orders by source, two different questions kept apart:
 *       orders_by_source     visits of each source that ordered (Insights'
 *                            own sessions — never more than the visits);
 *       wc_orders_by_source  every placed order in the range by WooCommerce
 *                            Order Attribution meta, incl. orders from before
 *                            Insights collected and from visitors it does not
 *                            measure — shown separately, never divided by visits.
 *
 * Coverage window: every number that combines visits with orders starts at
 * max( range start, the day Insights started collecting ) — `coverage_from`.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Queries' ) ) {

	final class Lafka_Insights_Queries {

		/** Allowed ranges (days). */
		const RANGES = array( 7, 30, 90 );

		/** Report cache lifetime (seconds). */
		const CACHE_TTL = 600;

		/** Live (non-rollup) counter metrics. */
		const LIVE_METRICS = array( 'item_view', 'item_add', 'item_remove', 'item_order', 'search', 'search_zero', 'block', 'pay_fail', 'orders', 'fulfilment', 'order_channel' );

		/**
		 * Clamp a requested range to 7 / 30 / 90.
		 *
		 * @param mixed $days Requested.
		 * @return int
		 */
		public static function sanitize_range( $days ): int {
			$days = (int) $days;
			return in_array( $days, self::RANGES, true ) ? $days : 30;
		}

		/**
		 * The report for the last $days days (today included), cached 10 min.
		 *
		 * @param int  $days  7 / 30 / 90.
		 * @param bool $fresh Bypass the cache.
		 * @return array<string,mixed>
		 */
		public static function report( int $days, bool $fresh = false ): array {
			$days = self::sanitize_range( $days );
			$key  = 'lafka_insights_report_' . $days;
			if ( ! $fresh ) {
				$cached = get_transient( $key );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}
			Lafka_Insights_Rollup::catch_up();
			$report = self::build( $days, Lafka_Insights_Session::today() );
			set_transient( $key, $report, self::CACHE_TTL );
			return $report;
		}

		/**
		 * Drop every cached report.
		 *
		 * @return void
		 */
		public static function flush_cache(): void {
			foreach ( self::RANGES as $days ) {
				delete_transient( 'lafka_insights_report_' . $days );
			}
		}

		/**
		 * Build the report (uncached).
		 *
		 * @param int    $days  Range length.
		 * @param string $today Y-m-d.
		 * @return array<string,mixed>
		 */
		public static function build( int $days, string $today, ?string $since = null ): array {
			$since     = null === $since ? Lafka_Insights::collecting_since() : $since;
			$base      = strtotime( $today . ' 00:00:00 UTC' );
			$from      = gmdate( 'Y-m-d', $base - ( $days - 1 ) * 86400 );
			$yesterday = gmdate( 'Y-m-d', $base - 86400 );
			$prev_to   = gmdate( 'Y-m-d', $base - $days * 86400 );
			$prev_from = gmdate( 'Y-m-d', $base - ( 2 * $days - 1 ) * 86400 );
			$coverage  = min( $today, max( $from, $since ) );
			$covered   = (int) round( ( $base - strtotime( $coverage . ' 00:00:00 UTC' ) ) / 86400 ) + 1;

			$m = self::group( Lafka_Insights_DB::counters( $coverage, $yesterday, Lafka_Insights_Rollup::metrics() ) );
			foreach ( Lafka_Insights_Rollup::rollup_rows( Lafka_Insights_DB::sessions_for_day( $today ) ) as $metric => $dims ) {
				foreach ( $dims as $dim => $value ) {
					$m[ $metric ][ $dim ] = ( $m[ $metric ][ $dim ] ?? 0 ) + $value;
				}
			}
			$live = self::group( Lafka_Insights_DB::counters( $coverage, $today, self::LIVE_METRICS ) );
			// A previous period is only comparable when Insights covered all of it.
			$prev_covered = $prev_from >= $since;
			$prev         = $prev_covered ? self::group( Lafka_Insights_DB::counters( $prev_from, $prev_to, array( 'funnel' ) ) ) : array();

			$funnel = array();
			foreach ( array_keys( Lafka_Insights_DB::funnel_stages() ) as $stage ) {
				$funnel[ $stage ] = (int) ( $m['funnel'][ $stage ] ?? 0 );
			}

			$report = array(
				'days'            => $days,
				'from'            => $from,
				'to'              => $today,
				'since'           => $since,
				'coverage_from'   => $coverage,
				'coverage_days'   => $covered,
				'funnel'          => $funnel,
				'closed_visits'   => (int) ( $m['funnel']['closed'] ?? 0 ),
				'pay_failed'      => (int) ( $m['funnel']['pay_failed'] ?? 0 ),
				'abandon'         => self::sorted( $m['abandon'] ?? array() ),
				'device'          => self::sorted( $m['device'] ?? array() ),
				'source'          => self::sorted( $m['source'] ?? array() ),
				'source_name'     => array_slice( self::sorted( $m['source_name'] ?? array() ), 0, 10, true ),
				'campaign'        => array_slice( self::sorted( $m['campaign'] ?? array() ), 0, 10, true ),
				'landing'         => self::sorted( $m['landing'] ?? array() ),
				'hour_dow'        => $m['hour_dow'] ?? array(),
				'closed_hour_dow' => $m['closed_hour_dow'] ?? array(),
				'items'           => self::items( $live ),
				'search'          => array_slice( self::sorted( $live['search'] ?? array() ), 0, 15, true ),
				'search_zero'     => array_slice( self::sorted( $live['search_zero'] ?? array() ), 0, 15, true ),
				'block'           => self::sorted( $live['block'] ?? array() ),
				'pay_fail'        => self::sorted( $live['pay_fail'] ?? array() ),
				'fulfilment'      => self::sorted( $live['fulfilment'] ?? array() ),
				'order_channel'   => self::sorted( $live['order_channel'] ?? array() ),
				'orders_by_source' => self::sorted( $m['source_order'] ?? array() ),
				'wc_orders_by_source' => self::orders_by_source( $from, $today ),
				'prev'            => array(
					'covered' => $prev_covered,
					'visit'   => (int) ( $prev['funnel']['visit'] ?? 0 ),
					'order'   => (int) ( $prev['funnel']['order'] ?? 0 ),
				),
			);
			$report['leak'] = self::biggest_leak( $funnel );
			return $report;
		}

		/**
		 * The consecutive funnel step that loses the most visits.
		 *
		 * @param array<string,int> $funnel Cumulative stage counts.
		 * @return array{from:string,to:string,lost:int,of:int}|null
		 */
		public static function biggest_leak( array $funnel ): ?array {
			$best  = null;
			$steps = array_keys( $funnel );
			for ( $i = 1, $n = count( $steps ); $i < $n; $i++ ) {
				$of   = (int) $funnel[ $steps[ $i - 1 ] ];
				$lost = $of - (int) $funnel[ $steps[ $i ] ];
				if ( $lost > 0 && ( null === $best || $lost > $best['lost'] ) ) {
					$best = array(
						'from' => $steps[ $i - 1 ],
						'to'   => $steps[ $i ],
						'lost' => $lost,
						'of'   => $of,
					);
				}
			}
			return $best;
		}

		/**
		 * Rows → metric => [ dim => value ].
		 *
		 * @param array<int,array<string,mixed>> $rows Counter rows.
		 * @return array<string,array<string,int>>
		 */
		private static function group( array $rows ): array {
			$out = array();
			foreach ( $rows as $row ) {
				$metric = (string) ( $row['metric'] ?? '' );
				$dim    = (string) ( $row['dim'] ?? '' );
				if ( '' === $metric ) {
					continue;
				}
				$out[ $metric ][ $dim ] = ( $out[ $metric ][ $dim ] ?? 0 ) + (int) ( $row['value'] ?? 0 );
			}
			return $out;
		}

		/**
		 * Sort a dim => value map by value, descending (string keys kept).
		 *
		 * @param array<string,int> $map Map.
		 * @return array<string,int>
		 */
		private static function sorted( array $map ): array {
			arsort( $map );
			return $map;
		}

		/**
		 * Per-item views / adds / orders, with product names (top 50 by activity).
		 *
		 * @param array<string,array<string,int>> $live Live counters.
		 * @return array<string,array{name:string,views:int,adds:int,orders:int}>
		 */
		private static function items( array $live ): array {
			$ids = array_unique(
				array_merge(
					array_keys( $live['item_view'] ?? array() ),
					array_keys( $live['item_add'] ?? array() ),
					array_keys( $live['item_order'] ?? array() )
				)
			);
			$out = array();
			foreach ( $ids as $id ) {
				$id          = (string) $id;
				$out[ $id ] = array(
					'name'   => '',
					'views'  => (int) ( $live['item_view'][ $id ] ?? 0 ),
					'adds'   => (int) ( $live['item_add'][ $id ] ?? 0 ),
					'orders' => (int) ( $live['item_order'][ $id ] ?? 0 ),
				);
			}
			uasort(
				$out,
				static function ( $a, $b ) {
					return ( $b['views'] + $b['adds'] + $b['orders'] ) <=> ( $a['views'] + $a['adds'] + $a['orders'] );
				}
			);
			$out = array_slice( $out, 0, 50, true );
			foreach ( $out as $id => &$item ) {
				$product      = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
				$item['name'] = ( is_object( $product ) && method_exists( $product, 'get_name' ) )
					? (string) $product->get_name()
					/* translators: %s: product id. */
					: sprintf( __( 'Product #%s', 'lafka-plugin' ), $id );
			}
			unset( $item );
			return $out;
		}

		/**
		 * Placed orders in the range grouped by WooCommerce Order Attribution
		 * source type (read straight from the order meta).
		 *
		 * @param string $from Y-m-d.
		 * @param string $to   Y-m-d.
		 * @return array<string,int>
		 */
		public static function orders_by_source( string $from, string $to ): array {
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return array();
			}
			$orders = wc_get_orders(
				array(
					'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'date_created' => $from . '...' . $to,
					'limit'        => 1000,
					'return'       => 'objects',
				)
			);
			$out = array();
			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
					continue;
				}
				$type         = (string) $order->get_meta( '_wc_order_attribution_source_type', true );
				$type         = '' !== $type ? $type : 'unknown';
				$out[ $type ] = ( $out[ $type ] ?? 0 ) + 1;
			}
			arsort( $out );
			return $out;
		}
	}
}
