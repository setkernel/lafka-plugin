<?php
/**
 * WP-CLI: untick Virtual on menu items that skip pickup and delivery.
 *
 *   wp lafka products unvirtual                 # show what would change
 *   wp lafka products unvirtual --yes           # change every flagged item
 *   wp lafka products unvirtual --ids=12,34 --yes
 *
 * All logic lives in Lafka_Virtual_Menu_Items
 * (incl/site-health/class-lafka-virtual-menu-items.php); this is a thin shell.
 * Self-gates: the file returns early when WP_CLI is not defined.
 *
 * @package Lafka\Plugin\CLI
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once dirname( __DIR__ ) . '/site-health/class-lafka-virtual-menu-items.php';

/**
 * Menu-item maintenance.
 */
class Lafka_Products_CLI_Command {

	/**
	 * Untick Virtual on menu items so customers can choose pickup or delivery.
	 *
	 * Without --ids, covers every published item Site Health flags (items the
	 * operator marked "meant to be virtual", downloadable items and the
	 * `lafka_virtual_ok_*` filters are skipped). Shows the changes and writes
	 * nothing unless --yes is given.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated product or variation ids. A product id covers its
	 * virtual variations.
	 *
	 * [--dry-run]
	 * : Only show what would change (the default without --yes).
	 *
	 * [--yes]
	 * : Write the changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka products unvirtual
	 *     wp lafka products unvirtual --yes
	 *     wp lafka products unvirtual --ids=123,456 --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args       Positional args (unused).
	 * @param array<string,mixed> $assoc_args Flags.
	 * @return void
	 */
	public function unvirtual( $args, $assoc_args ) {
		$ids = array();
		if ( isset( $assoc_args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) ) ) );
		}

		$rows = Lafka_Virtual_Menu_Items::plan( $ids );
		if ( array() === $rows ) {
			WP_CLI::success( 'No published menu item is marked Virtual.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'item', 'name' ) );

		$write = ! empty( $assoc_args['yes'] ) && empty( $assoc_args['dry-run'] );
		if ( ! $write ) {
			WP_CLI::log( sprintf( 'Dry run: %d item(s) would have Virtual unticked. Re-run with --yes to apply.', count( $rows ) ) );
			return;
		}

		$changed = Lafka_Virtual_Menu_Items::apply( $rows );
		WP_CLI::success( sprintf( 'Unticked Virtual on %d %s.', $changed, 1 === $changed ? 'item' : 'items' ) );
	}
}

WP_CLI::add_command( 'lafka products', 'Lafka_Products_CLI_Command' );
