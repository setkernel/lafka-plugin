<?php
/**
 * Prep-time trust signal — "Ready in X min".
 *
 * Per-category override via lafka_pdp_prep_time_<slug>; falls back to
 * lafka_pdp_prep_time_default. When closed, copy switches to
 * "Closed — order ahead".
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_get_prep_time' ) ) {
	function lafka_pdp_get_prep_time( int $product_id ): int {
		$default = (int) get_theme_mod( 'lafka_pdp_prep_time_default', 25 );

		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return $default;
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $default;
		}
		foreach ( $terms as $slug ) {
			$key = 'lafka_pdp_prep_time_' . sanitize_key( $slug );
			$val = get_theme_mod( $key, null );
			if ( null !== $val && '' !== $val ) {
				return (int) $val;
			}
		}
		return $default;
	}
}

if ( ! function_exists( 'lafka_pdp_hours_to_minutes' ) ) {
	/**
	 * "HH:MM" → minutes since midnight (0..1440), or -1 when unparseable.
	 *
	 * "24:00" is accepted as end-of-day (1440).
	 *
	 * @param string $hhmm Clock time.
	 * @return int
	 */
	function lafka_pdp_hours_to_minutes( string $hhmm ): int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $hhmm ), $m ) ) {
			return -1;
		}
		$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];
		return ( (int) $m[2] > 59 || $minutes > 1440 ) ? -1 : $minutes;
	}
}

if ( ! function_exists( 'lafka_pdp_hours_window_is_open' ) ) {
	/**
	 * Whether a store-clock minute falls inside today's opening window, or
	 * inside the after-midnight spill of yesterday's overnight window.
	 *
	 * Windows are "HH:MM-HH:MM" (the lafka_get_restaurant_info() display map
	 * shape). A close of "00:00" (or "24:00") means end of day, and a close at
	 * or before the open time is an overnight window (e.g. "17:00-02:00") that
	 * runs past midnight into the next day. The close minute is exclusive.
	 *
	 * An unparseable, non-"closed" today value is treated as open (the helper
	 * never invents a closure from data it cannot read).
	 *
	 * @param string $today     Today's window, "Closed", or ''.
	 * @param string $yesterday Yesterday's window, "Closed", or ''.
	 * @param int    $now_min   Minutes since midnight on the store clock.
	 * @return bool
	 */
	function lafka_pdp_hours_window_is_open( string $today, string $yesterday, int $now_min ): bool {
		$parse = static function ( string $window ): ?array {
			if ( ! preg_match( '/^\s*(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})\s*$/', $window, $m ) ) {
				return null;
			}
			$open  = lafka_pdp_hours_to_minutes( $m[1] );
			$close = lafka_pdp_hours_to_minutes( $m[2] );
			if ( $open < 0 || $close < 0 ) {
				return null;
			}
			if ( 0 === $close || 1440 === $close ) {
				$close = 1440; // Midnight close = end of the same day.
			}
			return array( $open, $close );
		};

		// After-midnight spill of yesterday's overnight window.
		$prev = $parse( $yesterday );
		if ( null !== $prev && $prev[1] <= $prev[0] && $now_min < $prev[1] ) {
			return true;
		}

		$today = trim( $today );
		if ( '' === $today || 'closed' === strtolower( $today ) ) {
			return false;
		}
		$win = $parse( $today );
		if ( null === $win ) {
			return true;
		}
		list( $open, $close ) = $win;
		if ( $close <= $open ) {
			// Overnight: open from $open until midnight (the spill is checked above).
			return $now_min >= $open;
		}
		return $now_min >= $open && $now_min < $close;
	}
}

if ( ! function_exists( 'lafka_pdp_is_store_open' ) ) {
	/**
	 * Whether the store is open right now, for the PDP trust line.
	 *
	 * One source with the header "Open now" badge: when the order-hours gate
	 * (Lafka_Order_Hours) is configured — a schedule or a force open/closed
	 * override — it is authoritative, exactly as the theme's header status
	 * defers to it. Otherwise the display hours map from
	 * lafka_get_restaurant_info() is read on the store clock (WP timezone),
	 * with midnight and overnight closes handled.
	 *
	 * Filter `lafka_pdp_is_store_open` (bool $open) adjusts the result.
	 *
	 * @return bool
	 */
	function lafka_pdp_is_store_open(): bool {
		$open = true;

		$gate_configured = class_exists( 'Lafka_Order_Hours' )
			&& method_exists( 'Lafka_Order_Hours', 'is_shop_open' )
			&& ( ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) || ! empty( Lafka_Order_Hours::$lafka_order_hours_force_override_check ) );

		if ( $gate_configured ) {
			$open = (bool) Lafka_Order_Hours::is_shop_open();
		} elseif ( function_exists( 'lafka_get_restaurant_info' ) ) {
			$info  = lafka_get_restaurant_info();
			$hours = ( ! empty( $info['hours'] ) && is_array( $info['hours'] ) ) ? $info['hours'] : array();
			if ( ! empty( $hours ) ) {
				// ISO-8601 day number, not the (locale-translated) day name.
				$days    = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
				$today_n = (int) wp_date( 'N' );
				$today_n = ( $today_n >= 1 && $today_n <= 7 ) ? $today_n - 1 : 0;
				$prev_n  = 0 === $today_n ? 6 : $today_n - 1;
				$now_min = lafka_pdp_hours_to_minutes( (string) wp_date( 'H:i' ) );
				$open    = lafka_pdp_hours_window_is_open(
					(string) ( $hours[ $days[ $today_n ] ] ?? '' ),
					(string) ( $hours[ $days[ $prev_n ] ] ?? '' ),
					max( 0, $now_min )
				);
			}
		}

		return (bool) apply_filters( 'lafka_pdp_is_store_open', $open );
	}
}

if ( ! function_exists( 'lafka_pdp_render_prep_time' ) ) {
	function lafka_pdp_render_prep_time( int $product_id ): void {
		if ( ! lafka_pdp_is_store_open() ) {
			printf(
				'<span class="lafka-pdp-trust lafka-pdp-trust--closed">%s</span>',
				esc_html__( 'Closed — order ahead', 'lafka-plugin' )
			);
			return;
		}
		$minutes = lafka_pdp_get_prep_time( $product_id );
		/* translators: %d: minutes until the order is ready. */
		$text = sprintf( __( 'Ready in ~%d min', 'lafka-plugin' ), $minutes );
		/**
		 * Filter the PDP ready-time line, e.g. so a theme's store-wide ETA
		 * ("20–30 min") is the one source the whole storefront quotes.
		 *
		 * @param string $text       "Ready in ~25 min".
		 * @param int    $minutes    Resolved prep minutes.
		 * @param int    $product_id Product.
		 */
		$text = (string) apply_filters( 'lafka_pdp_prep_time_text', $text, $minutes, $product_id );
		printf(
			'<span class="lafka-pdp-trust lafka-pdp-trust--open">⏱ %s</span>',
			esc_html( $text )
		);
	}
}
