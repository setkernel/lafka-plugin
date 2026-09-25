<?php
/**
 * Minimal WC_Shipping_Zones / WC_Cache_Helper stand-ins: tests set the zones
 * (each a list of { id, enabled } methods) and the "shipping" transient
 * version directly.
 *
 * @package Lafka\Plugin\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	class WC_Shipping_Zones { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/** @var array<int, list<array{0:string,1:bool}>> zone id => [ method id, enabled ] */
		public static array $zones = array();

		public static int $calls = 0;

		/** @return list<array{shipping_methods: list<object>}> */
		public static function get_zones() {
			++self::$calls;
			$out = array();
			foreach ( self::$zones as $id => $methods ) {
				if ( 0 !== $id ) {
					$out[] = array( 'shipping_methods' => self::methods( $methods ) );
				}
			}
			return $out;
		}

		public static function get_zone( $id ) {
			$methods = self::methods( self::$zones[ (int) $id ] ?? array() );
			return new class( $methods ) {
				public function __construct( private array $methods ) {}
				public function get_shipping_methods( $enabled_only = false ) {
					return $enabled_only ? array_values( array_filter( $this->methods, static fn( $m ) => $m->is_enabled() ) ) : $this->methods;
				}
			};
		}

		/** @param list<array{0:string,1:bool}> $methods */
		private static function methods( array $methods ): array {
			return array_map(
				static fn( $m ) => new class( $m[0], $m[1] ) {
					public function __construct( public string $id, private bool $on ) {}
					public function is_enabled() {
						return $this->on;
					}
				},
				$methods
			);
		}
	}
}

if ( ! class_exists( 'WC_Cache_Helper' ) ) {
	class WC_Cache_Helper { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/** @var array<string, string> */
		public static array $versions = array();

		public static function get_transient_version( $group, $refresh = false ) {
			if ( $refresh || ! isset( self::$versions[ $group ] ) ) {
				self::$versions[ $group ] = (string) ( (int) ( self::$versions[ $group ] ?? 0 ) + 1 );
			}
			return self::$versions[ $group ];
		}
	}
}
