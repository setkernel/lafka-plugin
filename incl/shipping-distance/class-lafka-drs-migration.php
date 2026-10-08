<?php
/**
 * Lafka_DRS_Migration: reads the WooCommerce Distance Rate Shipping
 * (`distance_rate`) instances of a site and maps each to an equivalent
 * `lafka_distance` instance.
 *
 * What maps exactly: a rule whose only condition is a distance range (and
 * nothing else) becomes a band: "up to" is the rule's
 * maximum, the fee is its flat cost, "plus per km" is its per-distance cost.
 * Title, tax status, unit, driving / non-driving mode and the display of the
 * distance carry over too.
 *
 * What cannot, and is listed instead of guessed: travel-time, weight and
 * quantity conditions, OR groups, shipping classes, per-quantity costs,
 * percentage fees, break / abort flags, route restrictions (avoid tolls,
 * highways, ferries), travel-time display, walking / cycling modes, and DRS's
 * "add up every matching rule" behaviour (Lafka uses the first matching band).
 *
 * The migration never reads or prints the DRS API key.
 *
 * @package Lafka\Plugin\ShippingDistance
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_DRS_Migration' ) ) {

	/**
	 * Distance Rate Shipping to `lafka_distance`.
	 */
	final class Lafka_DRS_Migration {

		/** DRS method id. */
		const DRS_ID = 'distance_rate';

		/** Site option: DRS instance id => the lafka_distance instance made from it. */
		const MAP_OPTION = 'lafka_distance_migrations';

		/**
		 * Every DRS instance on the site, enabled or not, with its zone.
		 *
		 * @return array<int,array{instance_id:int,zone_id:int,zone_name:string,enabled:bool,settings:array}>
		 */
		public static function instances(): array {
			if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
				return array();
			}
			$store = WC_Data_Store::load( 'shipping-zone' );
			$zones = array_merge( array( 0 ), array_map( 'intval', wp_list_pluck( (array) WC_Shipping_Zones::get_zones(), 'zone_id' ) ) );
			$found = array();
			foreach ( array_unique( $zones ) as $zone_id ) {
				$zone = WC_Shipping_Zones::get_zone( $zone_id );
				foreach ( (array) $store->get_methods( $zone_id, false ) as $row ) {
					if ( self::DRS_ID !== $row->method_id ) {
						continue;
					}
					$settings = get_option( 'woocommerce_' . self::DRS_ID . '_' . (int) $row->instance_id . '_settings', array() );
					$found[]  = array(
						'instance_id' => (int) $row->instance_id,
						'zone_id'     => $zone_id,
						'zone_name'   => $zone->get_zone_name(),
						'enabled'     => (bool) $row->is_enabled,
						'settings'    => is_array( $settings ) ? $settings : array(),
					);
				}
			}

			return $found;
		}

		/**
		 * Map one DRS instance's settings.
		 *
		 * @param array $drs DRS instance settings (woocommerce_distance_rate_{id}_settings).
		 * @return array{settings:array,rules:array<int,string>,unmapped:array<int,string>,notes:array<int,string>}
		 *         `rules` describes each DRS rule as read; `unmapped` lists what could not be carried over.
		 */
		public static function map( array $drs ): array {
			$unmapped = array();
			$notes    = array();
			$rules    = array();

			$unit = 'imperial' === ( $drs['unit'] ?? 'metric' ) ? 'mi' : 'km';
			$mode = (string) ( $drs['mode'] ?? 'driving' );
			if ( 'driving' !== $mode ) {
				$unmapped[] = sprintf( 'Travel mode "%s" (Lafka measures straight line x road factor, or driving).', $mode );
			}
			if ( 'none' !== ( $drs['avoid'] ?? 'none' ) && '' !== ( $drs['avoid'] ?? '' ) ) {
				$unmapped[] = sprintf( 'Route restriction "avoid %s".', (string) $drs['avoid'] );
			}
			if ( 'yes' === ( $drs['show_duration'] ?? 'no' ) ) {
				$unmapped[] = 'Showing the travel time (Lafka shows the distance only).';
			}

			$bands  = array();
			$ranges = array();
			foreach ( (array) ( $drs['rules'] ?? array() ) as $index => $rule ) {
				$number  = $index + 1;
				$rule    = is_array( $rule ) ? $rule : array();
				$result  = self::map_rule( $rule );
				$rules[] = sprintf( 'Rule %d: %s', $number, $result['describe'] );
				if ( null === $result['band'] ) {
					$unmapped[] = sprintf( 'Rule %d: %s', $number, $result['why'] );
					continue;
				}
				foreach ( $result['flags'] as $flag ) {
					$unmapped[] = sprintf( 'Rule %d: %s', $number, $flag );
				}
				$bands[]  = $result['band'];
				$ranges[] = array(
					'min' => $result['min'],
					'max' => $result['band']['up_to'],
				);
			}

			usort(
				$bands,
				static function ( $a, $b ) {
					return ( '' === $a['up_to'] ? INF : (float) $a['up_to'] ) <=> ( '' === $b['up_to'] ? INF : (float) $b['up_to'] );
				}
			);
			usort(
				$ranges,
				static function ( $a, $b ) {
					return ( '' === $a['max'] ? INF : (float) $a['max'] ) <=> ( '' === $b['max'] ? INF : (float) $b['max'] );
				}
			);
			$previous_max = 0.0;
			foreach ( $ranges as $range ) {
				if ( $range['min'] > $previous_max + 0.0001 ) {
					$notes[] = sprintf( 'Distances between %s and %s %s matched no DRS rule (no rate there); Lafka charges the next band instead.', self::num( $previous_max ), self::num( $range['min'] ), $unit );
				} elseif ( $range['min'] < $previous_max - 0.0001 ) {
					$unmapped[] = sprintf( 'Overlapping rules around %s %s: DRS adds up every matching rule, Lafka uses the first matching band.', self::num( $range['min'] ), $unit );
				} elseif ( $previous_max > 0 && $range['min'] > 0 ) {
					$notes[] = sprintf( 'At exactly %s %s DRS adds both neighbouring rules; Lafka uses the lower band.', self::num( $previous_max ), $unit );
				}
				$previous_max = '' === $range['max'] ? INF : (float) $range['max'];
			}

			$open    = false;
			$highest = 0.0;
			foreach ( $bands as $band ) {
				if ( '' === $band['up_to'] ) {
					$open = true;
				} else {
					$highest = max( $highest, (float) $band['up_to'] );
				}
			}

			$settings = array(
				'title'         => (string) ( $drs['title'] ?? '' ) !== '' ? (string) $drs['title'] : __( 'Delivery', 'lafka-plugin' ),
				'tax_status'    => 'none' === ( $drs['tax_status'] ?? 'taxable' ) ? 'none' : 'taxable',
				'unit'          => $unit,
				'mode'          => 'driving' === $mode ? 'driving' : 'straight',
				'road_factor'   => '1.3',
				'max_distance'  => $open || $highest <= 0 ? '' : (string) $highest,
				'free_over'     => 'yes',
				'show_distance' => 'no' === ( $drs['show_distance'] ?? 'yes' ) ? 'no' : 'yes',
				'bands'         => $bands,
			);

			return array(
				'settings' => $settings,
				'rules'    => $rules,
				'unmapped' => $unmapped,
				'notes'    => $notes,
			);
		}

		/**
		 * Map one DRS rule to a band.
		 *
		 * @param array $rule DRS rule.
		 * @return array{band:?array,min:float,describe:string,why:string,flags:array<int,string>}
		 */
		private static function map_rule( array $rule ): array {
			$groups = $rule['conditions'] ?? array();
			if ( array() === $groups && ! empty( $rule['condition'] ) ) {
				$groups = array(
					array(
						array(
							'condition' => $rule['condition'],
							'min'       => $rule['min'] ?? '',
							'max'       => $rule['max'] ?? '',
						),
					),
				);
			}
			$describe = array();
			foreach ( (array) $groups as $group ) {
				$and = array();
				foreach ( (array) $group as $condition ) {
					$low   = (string) ( $condition['min'] ?? '' );
					$high  = (string) ( $condition['max'] ?? '' );
					$and[] = sprintf( '%s %s to %s', (string) ( $condition['condition'] ?? '?' ), '' === $low ? 'any' : $low, '' === $high ? 'any' : $high );
				}
				$describe[] = implode( ' AND ', $and );
			}
			$cost_unit = (float) ( $rule['cost_unit'] ?? 0 );
			$cost      = (float) ( $rule['cost'] ?? 0 );
			$text      = sprintf( 'when %s => cost %s + %s per unit of %s', implode( ' OR ', $describe ), self::num( $cost ), self::num( $cost_unit ), (string) ( $rule['unit'] ?? 'distance' ) );
			foreach ( array(
				'fee'            => 'fee',
				'per_qty'        => 'per_qty',
				'break'          => 'break',
				'abort'          => 'abort',
				'shipping_class' => 'shipping_class',
			) as $key => $label ) {
				if ( ! empty( $rule[ $key ] ) && 'no' !== $rule[ $key ] ) {
					$text .= sprintf( ', %s=%s', $label, is_scalar( $rule[ $key ] ) ? (string) $rule[ $key ] : 'set' );
				}
			}

			$fail = static function ( string $why ) use ( $text ): array {
				return array(
					'band'     => null,
					'min'      => 0.0,
					'describe' => $text,
					'why'      => $why,
					'flags'    => array(),
				);
			};

			if ( 1 !== count( (array) $groups ) ) {
				return $fail( 'OR groups of conditions cannot be mapped.' );
			}
			if ( 'time' === ( $rule['unit'] ?? 'distance' ) ) {
				return $fail( 'A cost per minute of travel time cannot be mapped.' );
			}
			$distance = null;
			$total    = null;
			foreach ( (array) $groups[0] as $condition ) {
				$kind = (string) ( $condition['condition'] ?? '' );
				if ( 'distance' === $kind && null === $distance ) {
					$distance = $condition;
				} elseif ( 'total' === $kind && null === $total ) {
					$total = $condition;
				} else {
					return $fail( sprintf( 'The "%s" condition cannot be mapped.', $kind ) );
				}
			}
			if ( null === $distance ) {
				return $fail( 'A rule with no distance condition cannot be mapped.' );
			}
			if ( null !== $total ) {
				return $fail( 'An order-total condition cannot be mapped. Set a delivery minimum under Promotions (lafka_delivery_minimum) instead.' );
			}

			$flags = array();
			if ( ! empty( $rule['fee'] ) ) {
				$flags[] = 'A percentage / fixed fee on the order (' . (string) $rule['fee'] . ') is not carried over.';
			}
			if ( 'yes' === ( $rule['per_qty'] ?? 'no' ) ) {
				$flags[] = 'Cost per item quantity is not carried over.';
			}
			if ( ! empty( $rule['shipping_class'] ) ) {
				$flags[] = 'The shipping-class filter is not carried over.';
			}
			if ( 'yes' === ( $rule['abort'] ?? 'no' ) ) {
				$flags[] = '"Abort" (hide delivery) is not carried over.';
			}

			$max = '' === (string) ( $distance['max'] ?? '' ) ? '' : (string) (float) $distance['max'];

			return array(
				'band'     => array(
					'up_to'    => $max,
					'fee'      => (string) $cost,
					'per_unit' => $cost_unit > 0 ? (string) $cost_unit : '',
				),
				'min'      => (float) ( $distance['min'] ?? 0 ),
				'describe' => $text,
				'why'      => '',
				'flags'    => $flags,
			);
		}

		/**
		 * Add a disabled `lafka_distance` instance to a DRS instance's zone.
		 * Running it again does nothing for an instance already migrated.
		 *
		 * @param array $instance One row of instances().
		 * @param array $settings The mapped settings.
		 * @return int|WP_Error The new instance id (0 when it already exists).
		 */
		public static function apply( array $instance, array $settings ) {
			$done = (array) get_option( self::MAP_OPTION, array() );
			$old  = (int) $instance['instance_id'];
			if ( isset( $done[ $old ] ) ) {
				$exists = array_filter(
					(array) WC_Data_Store::load( 'shipping-zone' )->get_methods( (int) $instance['zone_id'], false ),
					static function ( $row ) use ( $done, $old ) {
						return 'lafka_distance' === $row->method_id && (int) $row->instance_id === (int) $done[ $old ];
					}
				);
				if ( array() !== $exists ) {
					return 0;
				}
			}

			$zone = WC_Shipping_Zones::get_zone( (int) $instance['zone_id'] );
			$new  = (int) $zone->add_shipping_method( 'lafka_distance' );
			if ( $new <= 0 ) {
				return new WP_Error( 'lafka_distance_add_failed', 'WooCommerce could not add the shipping method to the zone.' );
			}
			update_option( 'woocommerce_lafka_distance_' . $new . '_settings', $settings );

			// Disabled, so the operator compares fees before switching.
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'woocommerce_shipping_zone_methods', array( 'is_enabled' => 0 ), array( 'instance_id' => $new ) );
			do_action( 'woocommerce_shipping_zone_method_status_toggled', $new, 'lafka_distance', (int) $instance['zone_id'], 0 );
			WC_Cache_Helper::get_transient_version( 'shipping', true );

			$done[ $old ] = $new;
			update_option( self::MAP_OPTION, $done, false );

			return $new;
		}

		/**
		 * A number without trailing zeros.
		 *
		 * @param float $value Number.
		 * @return string
		 */
		private static function num( float $value ): string {
			return is_infinite( $value ) ? 'any' : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
		}
	}
}
