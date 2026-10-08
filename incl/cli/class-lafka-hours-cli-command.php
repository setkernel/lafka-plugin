<?php
/**
 * WP-CLI: the store's opening hours.
 *
 *   wp lafka hours status                    # open now? closes / next opens, and where the answer comes from
 *   wp lafka hours status --at="2026-10-09 23:30"
 *   wp lafka hours check                     # per-day display hours vs the order schedule
 *   wp lafka hours sync --yes                # copy the schedule into the per-day fields
 *
 * The answers come from Lafka_Order_Hours; this is a thin shell. Self-gates:
 * the file returns early when WP_CLI is not defined.
 *
 * @package Lafka\Plugin\CLI
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Opening hours.
 */
class Lafka_Hours_CLI_Command {

	/**
	 * Show whether the store is open and where that answer comes from.
	 *
	 * ## OPTIONS
	 *
	 * [--at=<datetime>]
	 * : Answer for this moment on the store's clock (default: now).
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$now = null;
		if ( ! empty( $assoc_args['at'] ) ) {
			try {
				$now = new DateTimeImmutable( (string) $assoc_args['at'], wp_timezone() );
			} catch ( Exception $e ) {
				WP_CLI::error( 'Could not read --at as a date and time.' );
			}
		}
		$status = Lafka_Order_Hours::status( $now );
		$text   = Lafka_Order_Hours::status_text( $status );

		WP_CLI::log( 'Moment:     ' . $status['now']->format( 'D Y-m-d H:i T' ) );
		WP_CLI::log( 'Open:       ' . ( $status['is_open'] ? 'yes' : 'no' ) );
		WP_CLI::log( 'Says:       ' . html_entity_decode( $text['label'], ENT_QUOTES, 'UTF-8' ) );
		WP_CLI::log( 'Closes at:  ' . ( $status['closes_at'] ? $status['closes_at']->format( 'D Y-m-d H:i' ) : '-' ) );
		WP_CLI::log( 'Next open:  ' . ( $status['next_open'] ? $status['next_open']->format( 'D Y-m-d H:i' ) : '-' ) );
		WP_CLI::log( 'Source:     ' . $status['source'] . ( $status['gated'] ? ' (gates ordering)' : ' (display only)' ) );
		WP_CLI::log( 'Timezone:   ' . $status['timezone'] );
	}

	/**
	 * Compare the per-day display hours with the order-hours schedule.
	 *
	 * The schedule decides when orders are accepted and what the site shows;
	 * this lists every weekday where the per-day fields say something else.
	 *
	 * @when after_wp_load
	 *
	 * @return void
	 */
	public function check() {
		if ( ! Lafka_Order_Hours::schedule_is_source() ) {
			WP_CLI::success( 'The order-hours schedule is not in use, so the per-day fields are the hours.' );
			return;
		}
		$differences = Lafka_Order_Hours::hours_discrepancies();
		if ( array() === $differences ) {
			WP_CLI::success( 'The per-day display hours and the order schedule agree.' );
			return;
		}
		$rows = array();
		foreach ( $differences as $day => $pair ) {
			$rows[] = array(
				'day'      => $day,
				'display'  => $pair['display'],
				'schedule' => $pair['schedule'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'day', 'display', 'schedule' ) );
		WP_CLI::warning( count( $rows ) . ' weekday(s) differ. The schedule is what the site shows and enforces; run `wp lafka hours sync --yes` to copy it into the per-day fields.' );
	}

	/**
	 * Copy the order-hours schedule into the per-day display fields.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Write the fields. Without it nothing is changed.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) {
		if ( ! Lafka_Order_Hours::schedule_is_source() ) {
			WP_CLI::error( 'There is no order-hours schedule to copy.' );
		}
		if ( empty( $assoc_args['yes'] ) ) {
			$this->check();
			WP_CLI::log( 'Nothing written. Add --yes to copy the schedule into the per-day fields.' );
			return;
		}
		$written = Lafka_Order_Hours::sync_display_hours();
		WP_CLI::success( 'Per-day hours now read: ' . implode( ' | ', $written ) );
	}
}

WP_CLI::add_command( 'lafka hours', 'Lafka_Hours_CLI_Command' );
