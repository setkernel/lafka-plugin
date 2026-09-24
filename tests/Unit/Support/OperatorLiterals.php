<?php
/**
 * Deny-list of the launch operator's identifying literals, stored ONLY as
 * sha1 hashes of their normalised forms so this public repository never
 * carries them in plain text.
 *
 * Normalised form: lowercase words joined by one space (`two words`), a
 * dotted token as-is (`host.name`), a phone number as its last ten digits,
 * a coordinate as its absolute value with four decimals.
 *
 * To add an entry: `php -r "echo sha1('normalised form'), PHP_EOL;"` and
 * paste the hash — never the literal.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Support;

final class OperatorLiterals {

	/** Brand, domain, street, postal code, locality, phone, coordinates. */
	private const IDENTIFYING = array(
		'cd236484e641cee73139dd69ee6d10f7fddda5fc',
		'f18ba828145cb08542be04dd5b48226d524e2cb7',
		'5a5e4d6d5378e96e0ee370b8d2be97d7d7926921',
		'406b0ab42e8b2482ddc107619f531ac16ad62f9c',
		'27d77e7c0ba4dbe42620f8a80cca74263ada506f',
		'07c764f1eba2477a2597fafbdb601c9af83fbe97',
		'8b75abddfde47f1c12a2efe735443967105756a5',
		'c8a501df254739499591856a8965187d41a834df',
		'd8c7f1c690939479778748eaabb45dd1aa2c5f59',
		'250cf127d5d01acf7dc8b7a073e8c5f1e2b4453d',
		'67e5e332d36437792b3bf67555ff75ede1a2daf1',
		'19e67b50c86f6a23cdd3a9a24740a3e11d3a522b',
	);

	/**
	 * Signature menu items: fine as generic category slugs in code, but they
	 * make docs and demo content read as the launch operator's menu.
	 */
	private const SIGNATURE_MENU = array(
		'1954c58a76233d7bb6df160f7a1db34aef3ed7ee',
		'cd38a7162287917952e904e73cb6c05a128296e3',
		'ac8fb9ed17a4a532c8bdaf7bc7dd75e310b9116e',
	);

	/**
	 * The denied literals present in $text, as they appear there.
	 *
	 * @param string $text          Text to scan.
	 * @param bool   $include_menu  Also deny the signature menu items.
	 * @return string[]
	 */
	public static function find( string $text, bool $include_menu = false ): array {
		return self::find_hashed( $text, $include_menu ? array_merge( self::IDENTIFYING, self::SIGNATURE_MENU ) : self::IDENTIFYING );
	}

	/**
	 * The literals in $text whose normalised form hashes into $hashes.
	 *
	 * @param string   $text   Text to scan.
	 * @param string[] $hashes sha1 hashes of normalised literals.
	 * @return string[]
	 */
	public static function find_hashed( string $text, array $hashes ): array {
		$denied = array_flip( $hashes );
		$lower  = strtolower( $text );
		$hits   = array();

		$check = static function ( string $candidate ) use ( $denied, &$hits ): void {
			if ( isset( $denied[ sha1( $candidate ) ] ) ) {
				$hits[ $candidate ] = true;
			}
		};

		// Words, dotted tokens and their parts, and adjacent word pairs.
		preg_match_all( '/[a-z0-9]+(?:\.[a-z0-9]+)*/', $lower, $m );
		$tokens = $m[0];
		foreach ( $tokens as $i => $token ) {
			$check( $token );
			if ( str_contains( $token, '.' ) ) {
				foreach ( explode( '.', $token ) as $part ) {
					$check( $part );
				}
			}
			if ( isset( $tokens[ $i + 1 ] ) ) {
				$check( $token . ' ' . $tokens[ $i + 1 ] );
			}
		}

		// Phone numbers in any punctuation: compare the last ten digits.
		preg_match_all( '/\+?\d[\d\s().\-]{8,}\d/', $text, $m );
		foreach ( $m[0] as $run ) {
			$digits = preg_replace( '/\D/', '', $run );
			if ( strlen( $digits ) >= 10 ) {
				$check( substr( $digits, -10 ) );
			}
		}

		// Coordinates at four-decimal precision.
		preg_match_all( '/\d{1,3}\.\d{3,}/', $text, $m );
		foreach ( $m[0] as $number ) {
			$check( sprintf( '%.4f', (float) $number ) );
		}

		return array_map( 'strval', array_keys( $hits ) );
	}
}
