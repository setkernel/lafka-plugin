<?php
/**
 * Slow-day discount — standalone, always-loaded.
 *
 * A percentage discount that only applies on operator-chosen weekdays, to pull
 * demand into the slow days (this store's are Mon/Tue/Wed/Sat — but the days are
 * a setting, never hardcoded). Independently toggled like free-delivery /
 * first-order; activates only when percent > 0 AND today is a configured day.
 *
 * Weekday numbering follows WordPress `current_time('w')`: 0=Sun … 6=Sat, in the
 * SITE timezone (so "Tuesday" means Tuesday where the store is, not UTC).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_slow_day_percent' ) ) {
	/** @return float 0–100 (0 = off). */
	function lafka_slow_day_percent(): float {
		$percent = 0.0;
		if ( function_exists( 'get_option' ) ) {
			$percent = (float) get_option( 'lafka_slow_day_discount_percent', 0 );
		}
		if ( $percent <= 0 && function_exists( 'get_theme_mod' ) ) {
			$percent = (float) get_theme_mod( 'lafka_slow_day_discount_percent', 0 );
		}
		$percent = (float) apply_filters( 'lafka_slow_day_discount_percent', $percent );
		return min( 100.0, max( 0.0, $percent ) );
	}
}

if ( ! function_exists( 'lafka_slow_day_normalize_days' ) ) {
	/**
	 * Normalize a CSV string or array into unique weekday ints in 0..6.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	function lafka_slow_day_normalize_days( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$days = array();
		foreach ( $raw as $d ) {
			$d = (int) trim( (string) $d );
			if ( $d >= 0 && $d <= 6 ) {
				$days[ $d ] = $d;
			}
		}
		sort( $days );
		return array_values( $days );
	}
}

if ( ! function_exists( 'lafka_slow_day_days' ) ) {
	/** @return int[] Configured slow weekdays (0=Sun..6=Sat). */
	function lafka_slow_day_days(): array {
		$raw = '';
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( 'lafka_slow_day_days', '' );
		}
		if ( ( '' === $raw || array() === $raw ) && function_exists( 'get_theme_mod' ) ) {
			$raw = get_theme_mod( 'lafka_slow_day_days', '' );
		}
		$raw = apply_filters( 'lafka_slow_day_days', $raw );
		return lafka_slow_day_normalize_days( $raw );
	}
}

if ( ! function_exists( 'lafka_slow_day_is_active_dow' ) ) {
	/**
	 * Pure: is weekday $dow one of the slow days? (testable)
	 *
	 * @param int   $dow       0=Sun..6=Sat
	 * @param int[] $slow_days
	 * @return bool
	 */
	function lafka_slow_day_is_active_dow( int $dow, array $slow_days ): bool {
		return in_array( $dow, array_map( 'intval', $slow_days ), true );
	}
}

if ( ! function_exists( 'lafka_is_slow_day' ) ) {
	/** @return bool Is today (site timezone) a configured slow day? */
	function lafka_is_slow_day(): bool {
		$dow = function_exists( 'current_time' ) ? (int) current_time( 'w' ) : -1;
		return lafka_slow_day_is_active_dow( $dow, lafka_slow_day_days() );
	}
}

if ( ! function_exists( 'lafka_slow_day_eligible' ) ) {
	/** @return bool Feature on AND today qualifies. */
	function lafka_slow_day_eligible(): bool {
		return lafka_slow_day_percent() > 0 && lafka_is_slow_day();
	}
}

if ( ! function_exists( 'lafka_slow_day_discount_component' ) ) {
	add_filter( 'lafka_order_discount_components', 'lafka_slow_day_discount_component', 10, 2 );
	/**
	 * Feed the slow-day discount into the shared order-discount coordinator
	 * (lafka_order_discount_apply) instead of adding its own cart fee, so it stacks
	 * sequentially with the other promos under ONE combined, capped fee rather than
	 * additively off the raw subtotal.
	 *
	 * @param array         $components Discount components collected so far.
	 * @param \WC_Cart|null $cart      Current cart (unused; eligibility is contextual).
	 * @return array
	 */
	function lafka_slow_day_discount_component( $components, $cart = null ) {
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		if ( ! lafka_slow_day_eligible() ) {
			return $components;
		}
		$percent = lafka_slow_day_percent();
		if ( $percent > 0 ) {
			$components[] = array(
				'source' => 'slow_day',
				'type'   => 'percent',
				'value'  => $percent,
				'label'  => sprintf(
					/* translators: %s = discount percent */
					__( 'Slow-day special (%s%% off)', 'lafka-plugin' ),
					(string) ( (float) $percent )
				),
			);
		}
		return $components;
	}
}
