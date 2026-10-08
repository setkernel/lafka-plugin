<?php
/**
 * WP-CLI: move from WooCommerce Distance Rate Shipping to Lafka's own
 * distance-priced delivery.
 *
 *   wp lafka shipping migrate-drs             # show every DRS instance and its mapping
 *   wp lafka shipping migrate-drs --apply     # add a disabled lafka_distance instance per DRS instance
 *
 * The mapping lives in Lafka_DRS_Migration; this is a thin shell. Self-gates:
 * the file returns early when WP_CLI is not defined.
 *
 * @package Lafka\Plugin\CLI
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once dirname( __DIR__ ) . '/shipping-distance/class-lafka-drs-migration.php';

/**
 * Shipping maintenance.
 */
class Lafka_Shipping_CLI_Command {

	/**
	 * Show (and optionally copy) the Distance Rate Shipping instances.
	 *
	 * Prints, for every WooCommerce Distance Rate Shipping instance on the
	 * site: its zone, settings and rules, the equivalent Lafka distance bands,
	 * and everything that cannot be mapped. API keys are never printed. With
	 * --apply, adds an equivalent "Delivery by distance" instance to the same
	 * zone, DISABLED, so you can compare fees and then switch (disable the old
	 * method, enable the new one). Running --apply again adds nothing for an
	 * instance already copied.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Add the disabled Lafka instances. Without it nothing is written.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka shipping migrate-drs
	 *     wp lafka shipping migrate-drs --apply
	 *
	 * @subcommand migrate-drs
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public function migrate_drs( $args, $assoc_args ) {
		$instances = Lafka_DRS_Migration::instances();
		if ( array() === $instances ) {
			WP_CLI::success( 'No Distance Rate Shipping instance found in any shipping zone.' );
			return;
		}
		$apply = ! empty( $assoc_args['apply'] );

		$key_set     = '' !== lafka_google_maps_key();
		$drs_options = get_option( 'woocommerce_distance_rate_settings', array() );
		$drs_key_set = is_array( $drs_options ) && '' !== trim( (string) ( $drs_options['api_key'] ?? '' ) );
		$store       = lafka_get_store_point();
		WP_CLI::log( sprintf( 'Lafka store point: %s. Lafka Google Maps key: %s.', null === $store ? 'NOT SET' : sprintf( '%.6f, %.6f', $store['lat'], $store['lng'] ), $key_set ? 'set' : 'not set' ) );

		foreach ( $instances as $instance ) {
			$drs    = $instance['settings'];
			$mapped = Lafka_DRS_Migration::map( $drs );
			$origin = array();
			foreach ( array( 'address_1', 'address_2', 'city', 'state_province', 'postal_code', 'country' ) as $field ) {
				if ( '' !== trim( (string) ( $drs[ $field ] ?? '' ) ) ) {
					$origin[] = trim( (string) $drs[ $field ] );
				}
			}
			$address = implode( ', ', $origin );

			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '== Zone "%s" (id %d), DRS instance %d (%s) ==', $instance['zone_name'], $instance['zone_id'], $instance['instance_id'], $instance['enabled'] ? 'enabled' : 'disabled' ) );
			WP_CLI::log( sprintf( 'DRS settings: title="%s" tax_status=%s mode=%s unit=%s avoid=%s show_distance=%s show_duration=%s', $drs['title'] ?? '', $drs['tax_status'] ?? '', $drs['mode'] ?? '', $drs['unit'] ?? '', $drs['avoid'] ?? '', $drs['show_distance'] ?? '', $drs['show_duration'] ?? '' ) );
			WP_CLI::log( sprintf( 'DRS origin address: %s (DRS has its own Google key: %s; never printed)', '' === $address ? '(none)' : $address, $drs_key_set ? 'set' : 'not set' ) );
			WP_CLI::log( 'DRS rules:' );
			foreach ( $mapped['rules'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}

			$s = $mapped['settings'];
			WP_CLI::log( sprintf( 'Lafka distance method: title="%s" tax_status=%s unit=%s mode=%s road_factor=%s max_distance=%s free_over=%s show_distance=%s', $s['title'], $s['tax_status'], $s['unit'], $s['mode'], $s['road_factor'], '' === $s['max_distance'] ? '(none)' : $s['max_distance'], $s['free_over'], $s['show_distance'] ) );
			if ( 'driving' === $s['mode'] && '' === Lafka_Distance_Resolver::driving_provider() ) {
				WP_CLI::warning( 'Driving distance needs the Lafka Google Maps key (Lafka Shipping Settings) or an OSRM server; neither is set here, so this instance offers no rate until one is.' );
			}
			WP_CLI::log( 'Lafka bands:' );
			$rows = array();
			foreach ( $s['bands'] as $band ) {
				$rows[] = array(
					'up_to'    => '' === $band['up_to'] ? '(no limit)' : $band['up_to'],
					'fee'      => $band['fee'],
					'plus_per' => $band['per_unit'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'up_to', 'fee', 'plus_per' ) );
			foreach ( $mapped['notes'] as $note ) {
				WP_CLI::log( 'Note: ' . $note );
			}
			if ( array() === $mapped['unmapped'] ) {
				WP_CLI::log( 'Cannot be mapped: nothing.' );
			} else {
				WP_CLI::log( 'Cannot be mapped:' );
				foreach ( $mapped['unmapped'] as $line ) {
					WP_CLI::warning( $line );
				}
			}
			WP_CLI::log( 'Origin: DRS measures from the address above; Lafka measures from the store point (or the chosen branch). Check they are the same place.' );

			if ( ! $apply ) {
				continue;
			}
			$result = Lafka_DRS_Migration::apply( $instance, $s );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( $result->get_error_message() );
			} elseif ( 0 === $result ) {
				WP_CLI::log( 'Already copied earlier: nothing added.' );
			} else {
				WP_CLI::success( sprintf( 'Added "Delivery by distance" instance %d to zone "%s", disabled. Compare, then disable DRS instance %d and enable it.', $result, $instance['zone_name'], $instance['instance_id'] ) );
			}
		}

		if ( ! $apply ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Dry run: nothing written. Re-run with --apply to add the disabled instances.' );
		}
	}
}

WP_CLI::add_command( 'lafka shipping', 'Lafka_Shipping_CLI_Command' );
