<?php
/**
 * WP-CLI: loyalty points maintenance. Self-gates on WP_CLI.
 *
 *   wp lafka loyalty recalc              # rebuild every balance from the ledger
 *   wp lafka loyalty recalc --user=12    # one customer
 *   wp lafka loyalty recalc --dry-run    # report only
 *
 * @package Lafka\Plugin\CLI
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Loyalty points.
 */
class Lafka_Loyalty_CLI_Command {

	/**
	 * Rebuild balances from the ledger.
	 *
	 * The ledger is the truth: this sets each customer's cached balance to the
	 * sum of their ledger rows and repairs the running balance on each row.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<id>]
	 * : Only this customer.
	 *
	 * [--dry-run]
	 * : Report what differs without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka loyalty recalc
	 *     wp lafka loyalty recalc --user=12 --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public function recalc( $args, $assoc_args ) {
		unset( $args );
		$apply = empty( $assoc_args['dry-run'] );
		$users = isset( $assoc_args['user'] ) ? array( absint( $assoc_args['user'] ) ) : Lafka_Loyalty_Ledger::user_ids();
		$off   = 0;
		foreach ( $users as $user_id ) {
			$result  = Lafka_Loyalty_Ledger::recalc( $user_id, $apply );
			$differs = $result['cached'] !== $result['ledger'] || $result['rows_fixed'] > 0;
			$off    += $differs ? 1 : 0;
			WP_CLI::log( sprintf( 'user %d: cached %d, ledger %d, rows repaired %d%s', $user_id, $result['cached'], $result['ledger'], $result['rows_fixed'], $differs ? ( $apply ? ' (fixed)' : ' (differs)' ) : '' ) );
		}
		WP_CLI::success( sprintf( '%d customer(s) checked, %d %s.', count( $users ), $off, $apply ? 'fixed' : 'differ' ) );
	}
}

WP_CLI::add_command( 'lafka loyalty', 'Lafka_Loyalty_CLI_Command' );
