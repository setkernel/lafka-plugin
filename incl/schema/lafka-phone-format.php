<?php
/**
 * Phone display formatting.
 *
 * The restaurant phone is stored as E.164 ("+19025550100") because tel: links
 * and schema.org need it, but printed raw it reads like a code, not a phone
 * number. lafka_format_phone_display() turns a bare number into what people
 * expect to read: "(902) 555-0100" for North American (NANP) numbers, and a
 * grouped international form ("+44 207 946 0958") elsewhere. Text an operator
 * already formatted by hand ("902-555-0100 ext. 2") is returned unchanged.
 *
 * Used by lafka_get_restaurant_info() for `phone_display` when no display
 * value exists or the stored one is itself a bare number; the theme routes any
 * raw fallback through it too. Filter: `lafka_format_phone_display`.
 *
 * @package Lafka\Plugin\Schema
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_phone_is_bare_number' ) ) {
	/**
	 * Whether a phone string is an unformatted number: optional "+" then
	 * 7–15 digits and nothing else.
	 *
	 * @param string $phone Phone text.
	 * @return bool
	 */
	function lafka_phone_is_bare_number( string $phone ): bool {
		return 1 === preg_match( '/^\+?\d{7,15}$/', trim( $phone ) );
	}
}

if ( ! function_exists( 'lafka_format_phone_display' ) ) {
	/**
	 * Human-readable phone text for display.
	 *
	 * @param string $phone   Phone number (E.164 or bare digits; anything else is kept as typed).
	 * @param string $country ISO country of the store ('' = WooCommerce base country).
	 * @return string
	 */
	function lafka_format_phone_display( string $phone, string $country = '' ): string {
		$raw = trim( $phone );
		if ( '' === $country && function_exists( 'get_option' ) ) {
			$country = strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' );
			$country = false === $country ? '' : $country;
		}
		$country = strtoupper( $country );

		$formatted = $raw;
		if ( lafka_phone_is_bare_number( $raw ) ) {
			$formatted = lafka_phone_format_bare_number( $raw, $country );
		}

		/**
		 * Filter the display text of a phone number.
		 *
		 * @param string $formatted Display text.
		 * @param string $raw       Number as stored.
		 * @param string $country   Store country used for national numbers.
		 */
		return (string) apply_filters( 'lafka_format_phone_display', $formatted, $raw, $country );
	}
}

if ( ! function_exists( 'lafka_phone_format_bare_number' ) ) {
	/**
	 * Format a bare number (see lafka_phone_is_bare_number()).
	 *
	 * @param string $raw     "+" and/or digits.
	 * @param string $country Upper-case store country.
	 * @return string
	 */
	function lafka_phone_format_bare_number( string $raw, string $country ): string {
		$international = '+' === $raw[0];
		$digits        = ltrim( $raw, '+' );

		// Countries in the North American Numbering Plan (+1).
		$nanp = array( 'US', 'CA', 'AG', 'AI', 'AS', 'BB', 'BM', 'BS', 'DM', 'DO', 'GD', 'GU', 'JM', 'KN', 'KY', 'LC', 'MP', 'MS', 'PR', 'SX', 'TC', 'TT', 'VC', 'VG', 'VI' );

		$national = null;
		if ( $international && 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$national = substr( $digits, 1 );
		} elseif ( ! $international && in_array( $country, $nanp, true ) ) {
			if ( 10 === strlen( $digits ) ) {
				$national = $digits;
			} elseif ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
				$national = substr( $digits, 1 );
			}
		}
		if ( null !== $national ) {
			return sprintf( '(%s) %s-%s', substr( $national, 0, 3 ), substr( $national, 3, 3 ), substr( $national, 6 ) );
		}

		if ( ! $international ) {
			return $raw;
		}

		// ITU country codes: 1 and 7 are one digit, this set two, the rest three.
		$two_digit = array( '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98' );
		if ( in_array( $digits[0], array( '1', '7' ), true ) ) {
			$code_length = 1;
		} elseif ( in_array( substr( $digits, 0, 2 ), $two_digit, true ) ) {
			$code_length = 2;
		} else {
			$code_length = 3;
		}
		$code = substr( $digits, 0, $code_length );
		$rest = substr( $digits, $code_length );

		$shapes = array(
			7  => array( 3, 4 ),
			8  => array( 4, 4 ),
			9  => array( 3, 3, 3 ),
			10 => array( 3, 3, 4 ),
			11 => array( 3, 4, 4 ),
		);
		$groups = array();
		if ( isset( $shapes[ strlen( $rest ) ] ) ) {
			$offset = 0;
			foreach ( $shapes[ strlen( $rest ) ] as $size ) {
				$groups[] = substr( $rest, $offset, $size );
				$offset  += $size;
			}
		} else {
			$groups = str_split( $rest, 3 );
			$last   = count( $groups ) - 1;
			if ( $last > 0 && 1 === strlen( $groups[ $last ] ) ) {
				$groups[ $last - 1 ] .= array_pop( $groups );
			}
		}

		return '+' . $code . ' ' . implode( ' ', $groups );
	}
}
