<?php
/**
 * WP-CLI: export / import a Lafka configuration bundle (NX1-05).
 *
 *   wp lafka config export --file=bundle.json
 *   wp lafka config import --file=bundle.json
 *   wp lafka config import --file=bundle.json --dry-run
 *
 * All logic lives in Lafka_Config_Bundle (incl/tools/class-lafka-config-bundle.php);
 * this command is a thin CLI shell over it. Self-gates: the file returns early
 * when WP_CLI is not defined, so it is safe to require unconditionally.
 *
 * @package Lafka\Plugin\CLI
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once dirname( __DIR__ ) . '/tools/class-lafka-config-bundle.php';

class Lafka_Config_CLI_Command {

	/**
	 * Export the current install's configuration to a versioned JSON bundle.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write the bundle to this path. Default: stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka config export --file=lafka-config.json
	 *     wp lafka config export > lafka-config.json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$json = Lafka_Config_Bundle::export_json();

		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		if ( '' === $file ) {
			WP_CLI::line( $json );
			return;
		}

		if ( false === file_put_contents( $file, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes to an operator-chosen path.
			WP_CLI::error( "Could not write to: $file" );
		}

		$bundle   = json_decode( $json, true );
		$sections = is_array( $bundle ) && isset( $bundle['sections'] ) ? count( $bundle['sections'] ) : 0;
		WP_CLI::success( sprintf( 'Exported %d sections to %s (%s).', $sections, $file, size_format( strlen( $json ) ) ) );
		foreach ( Lafka_Config_Bundle::excluded_notes() as $note ) {
			WP_CLI::log( '  • ' . $note );
		}
	}

	/**
	 * Import a Lafka configuration bundle. Create/update only — never deletes.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Read the bundle from this path.
	 *
	 * [--dry-run]
	 * : Report the per-section create/update/skip diff without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka config import --file=lafka-config.json --dry-run
	 *     wp lafka config import --file=lafka-config.json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "File not found or unreadable: $file" );
		}
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$json = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI reads an operator-chosen local path.
		if ( false === $json ) {
			WP_CLI::error( "Could not read: $file" );
		}

		$report = Lafka_Config_Bundle::import_json( (string) $json, $dry_run );

		if ( $dry_run ) {
			WP_CLI::log( '(dry-run — no changes written)' );
		}

		$rows = array();
		foreach ( $report['sections'] as $id => $counts ) {
			$rows[] = array(
				'section' => $id,
				'create'  => $counts['created'],
				'update'  => $counts['updated'],
				'skip'    => $counts['skipped'],
			);
		}
		if ( ! empty( $rows ) ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'section', 'create', 'update', 'skip' ) );
		}

		foreach ( $report['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		foreach ( $report['errors'] as $error ) {
			WP_CLI::warning( $error );
		}

		if ( ! $report['ok'] ) {
			WP_CLI::error( 'Import failed — one or more sections were rejected (see above). No partial section was applied.' );
		}

		WP_CLI::success( $dry_run ? 'Dry-run complete.' : 'Import complete.' );
	}
}

WP_CLI::add_command( 'lafka config', 'Lafka_Config_CLI_Command' );
