<?php
/**
 * Opening-hours maths: the one place that turns a weekly schedule into
 * "open now / closes at / next opens".
 *
 * Pure: DateTime in, array out, no WordPress calls, so every reader of the
 * store's hours (the order gate, the header badge, the product-page trust
 * line, schema, Insights, llms.txt, the live status script) gets the same
 * answer from Lafka_Order_Hours::status(), which feeds this engine.
 *
 * A week is a list of seven days (Monday first); a day is a list of
 * [open, close] pairs in minutes since midnight. A close at or before its
 * open runs past midnight into the next day; "00:00"/"24:00" as a close is the
 * end of the day. Adjacent windows (Friday to 24:00 then Saturday from 00:00)
 * are merged, so "closes at" is the real closing time of the whole run.
 *
 * @package Lafka\Plugin\OrderHours
 * @since   10.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lafka_Order_Hours_Engine {

	/** Days of schedule laid out around "now" (yesterday plus the next eight). */
	private const HORIZON_DAYS = 10;

	/** Day names, Monday first (index 0..6 == ISO weekday - 1). */
	public const DAYS = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

	/**
	 * "HH:MM" as minutes since midnight (0..1440), or -1 when unparseable.
	 *
	 * @param string $hhmm Clock time; "24:00" is the end of the day.
	 * @return int
	 */
	public static function to_minutes( string $hhmm ): int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) {
			return -1;
		}
		$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];
		return ( (int) $m[2] > 59 || $minutes > 1440 ) ? -1 : $minutes;
	}

	/**
	 * Minutes since midnight as "HH:MM" (1440 renders "24:00").
	 *
	 * @param int $minutes 0..1440.
	 * @return string
	 */
	public static function to_hhmm( int $minutes ): string {
		$minutes = max( 0, min( 1440, $minutes ) );
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * One window from a start and an end time.
	 *
	 * @param string $start Open time.
	 * @param string $end   Close time; "00:00" is the end of the day.
	 * @return array{0:int,1:int}|null Null when unusable.
	 */
	private static function window( string $start, string $end ): ?array {
		$open  = self::to_minutes( $start );
		$close = self::to_minutes( $end );
		if ( $open < 0 || $open >= 1440 || $close < 0 ) {
			return null;
		}
		if ( 0 === $close ) {
			$close = 1440;
		}
		if ( $close === $open ) {
			return null;
		}
		return array( $open, $close );
	}

	/**
	 * The weekly schedule JSON (the order-hours scheduler's export) as a week.
	 * Array position is the weekday (0 = Monday), matching the scheduler.
	 *
	 * @param string $json Schedule JSON.
	 * @return array<int,array<int,array{0:int,1:int}>>|null Null when there is no usable schedule.
	 */
	public static function parse_schedule( string $json ): ?array {
		if ( '' === trim( $json ) ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return null;
		}
		$decoded = array_values( $decoded );
		$week    = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$week[ $i ] = array();
			$periods    = isset( $decoded[ $i ]['periods'] ) && is_array( $decoded[ $i ]['periods'] ) ? $decoded[ $i ]['periods'] : array();
			foreach ( $periods as $period ) {
				$window = self::window( (string) ( $period['start'] ?? '' ), (string) ( $period['end'] ?? '' ) );
				if ( null !== $window ) {
					$week[ $i ][] = $window;
				}
			}
		}
		return $week;
	}

	/**
	 * A display-hours week ("11:00-23:00", "11:00-14:00, 17:00-22:00", "Closed"
	 * per weekday, Monday first) as a week. Null when no day has hours.
	 *
	 * @param array<int,string> $days Seven strings, Monday first.
	 * @return array<int,array<int,array{0:int,1:int}>>|null
	 */
	public static function parse_display( array $days ): ?array {
		$week = array();
		$any  = false;
		for ( $i = 0; $i < 7; $i++ ) {
			$week[ $i ] = array();
			foreach ( explode( ',', (string) ( $days[ $i ] ?? '' ) ) as $span ) {
				if ( preg_match( '/^\s*(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})\s*$/', $span, $m ) ) {
					$window = self::window( $m[1], $m[2] );
					if ( null !== $window ) {
						$week[ $i ][] = $window;
						$any          = true;
					}
				}
			}
		}
		return $any ? $week : null;
	}

	/**
	 * A week as the display map the storefront, schema and llms.txt read:
	 * [ 'Monday' => '11:00-23:00', 'Tuesday' => '11:00-14:00, 17:00-22:00',
	 * 'Wednesday' => 'Closed', … ]. Empty when no day has hours.
	 *
	 * @param array<int,array<int,array{0:int,1:int}>>|null $week Week.
	 * @return array<string,string>
	 */
	public static function display_map( ?array $week ): array {
		if ( null === $week ) {
			return array();
		}
		$map = array();
		$any = false;
		foreach ( self::DAYS as $i => $name ) {
			$spans = array();
			foreach ( $week[ $i ] ?? array() as $window ) {
				$spans[] = self::to_hhmm( $window[0] ) . '-' . self::to_hhmm( $window[1] );
			}
			$map[ $name ] = array() === $spans ? 'Closed' : implode( ', ', $spans );
			$any          = $any || array() !== $spans;
		}
		return $any ? $map : array();
	}

	/**
	 * Schema.org OpeningHoursSpecification rows, one per window.
	 *
	 * @param array<int,array<int,array{0:int,1:int}>>|null $week Week.
	 * @return array<int,array<string,string>>
	 */
	public static function schema_specification( ?array $week ): array {
		$rows = array();
		foreach ( self::DAYS as $i => $name ) {
			foreach ( $week[ $i ] ?? array() as $window ) {
				$rows[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => 'https://schema.org/' . $name,
					'opens'     => self::to_hhmm( $window[0] ),
					'closes'    => self::to_hhmm( $window[1] % 1440 ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Holiday dates from the scheduler's comma-separated "Y-m-d" calendar.
	 *
	 * @param string $calendar Calendar string.
	 * @return string[]
	 */
	public static function parse_holidays( string $calendar ): array {
		$dates = array();
		foreach ( explode( ',', $calendar ) as $date ) {
			$date = trim( $date );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$dates[] = $date;
			}
		}
		return $dates;
	}

	/**
	 * Open now, when it closes and when it next opens.
	 *
	 * @param DateTimeImmutable                             $now       The moment, on the store's clock.
	 * @param array<int,array<int,array{0:int,1:int}>>|null $week      Weekly hours, or null for none.
	 * @param string[]                                      $holidays  "Y-m-d" dates the store is closed all day.
	 * @return array{has_hours:bool,is_open:bool,closes_at:?DateTimeImmutable,next_open:?DateTimeImmutable,holiday:bool}
	 */
	public static function resolve( DateTimeImmutable $now, ?array $week, array $holidays ): array {
		$holiday_today = in_array( $now->format( 'Y-m-d' ), $holidays, true );
		$result        = array(
			'has_hours' => null !== $week,
			'is_open'   => null === $week && ! $holiday_today,
			'closes_at' => null,
			'next_open' => null,
			'holiday'   => $holiday_today,
		);
		if ( null === $week ) {
			return $result;
		}

		$first     = $now->setTime( 0, 0 )->modify( '-1 day' );
		$horizon   = $first->modify( '+' . self::HORIZON_DAYS . ' days' )->getTimestamp();
		$intervals = array();
		for ( $i = 0; $i < self::HORIZON_DAYS; $i++ ) {
			$day = $first->modify( '+' . $i . ' days' );
			if ( in_array( $day->format( 'Y-m-d' ), $holidays, true ) ) {
				continue;
			}
			$next_day = $day->modify( '+1 day' );
			$cut      = in_array( $next_day->format( 'Y-m-d' ), $holidays, true ) ? $next_day->getTimestamp() : null;
			foreach ( $week[ (int) $day->format( 'N' ) - 1 ] ?? array() as $window ) {
				$open  = $window[0];
				$close = $window[1] <= $open ? $window[1] + 1440 : $window[1];
				$end   = $day->setTime( intdiv( $close, 60 ), $close % 60 )->getTimestamp();
				if ( null !== $cut && $end > $cut ) {
					$end = $cut;
				}
				$intervals[] = array( $day->setTime( intdiv( $open, 60 ), $open % 60 )->getTimestamp(), $end );
			}
		}

		usort(
			$intervals,
			static function ( array $a, array $b ): int {
				return $a[0] <=> $b[0];
			}
		);
		$merged = array();
		foreach ( $intervals as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $interval[1] );
			} else {
				$merged[] = $interval;
			}
		}

		$ts = $now->getTimestamp();
		foreach ( $merged as $interval ) {
			if ( $interval[0] <= $ts && $ts < $interval[1] ) {
				$result['is_open'] = true;
				// A run that reaches the end of the laid-out days has no known close.
				$result['closes_at'] = $interval[1] >= $horizon ? null : $now->setTimestamp( $interval[1] );
				return $result;
			}
			if ( $interval[0] > $ts ) {
				$result['next_open'] = $now->setTimestamp( $interval[0] );
				return $result;
			}
		}
		return $result;
	}
}
