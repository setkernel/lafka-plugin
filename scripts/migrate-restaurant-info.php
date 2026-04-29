<?php
/**
 * Operator helper: populate the "Lafka — Restaurant Information" Customizer
 * settings (`lafka_business_*`) in bulk from a JSON config file.
 *
 * Run via WP-CLI:
 *
 *   LAFKA_RESTAURANT_INFO_JSON=/abs/path/to/config.json \
 *     wp eval-file wp-content/plugins/lafka-plugin/scripts/migrate-restaurant-info.php
 *
 * Optional environment flags:
 *
 *   LAFKA_RESTAURANT_INFO_DRY_RUN=1    Print planned changes without writing.
 *   LAFKA_RESTAURANT_INFO_FORCE=1      Overwrite settings that already have
 *                                      a non-empty value (default: skip).
 *
 * Sample config: see `scripts/sample-restaurant-info.json`.
 *
 * Idempotent. Safe to re-run. Writes to theme_mods (the same store
 * the Customizer panel writes to), so values appear in the Customizer UI
 * after the next admin reload.
 *
 * This script ships empty by design — the public OSS repo must not advertise
 * any specific restaurant. Operators bring their own NAP via the JSON file.
 *
 * @package Lafka\Plugin\Scripts
 * @since   8.11.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "ERROR: This script must be run via `wp eval-file`.\n" );
	exit( 1 );
}

/**
 * Tiny WP-CLI / stderr-aware logger.
 */
$log = static function ( string $msg, string $level = 'info' ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		switch ( $level ) {
			case 'error':   WP_CLI::error( $msg, false ); return;
			case 'warning': WP_CLI::warning( $msg ); return;
			case 'success': WP_CLI::success( $msg ); return;
			default:        WP_CLI::log( $msg ); return;
		}
	}
	$prefix = 'error' === $level ? 'ERROR: ' : ( 'warning' === $level ? 'WARN: ' : '' );
	$stream = 'error' === $level ? STDERR : STDOUT;
	fwrite( $stream, $prefix . $msg . "\n" );
};

$json_path = (string) getenv( 'LAFKA_RESTAURANT_INFO_JSON' );
$dry_run   = (bool) getenv( 'LAFKA_RESTAURANT_INFO_DRY_RUN' );
$force     = (bool) getenv( 'LAFKA_RESTAURANT_INFO_FORCE' );

if ( '' === $json_path ) {
	$log( 'LAFKA_RESTAURANT_INFO_JSON env var is required (absolute path to config JSON).', 'error' );
	exit( 1 );
}
if ( ! is_readable( $json_path ) ) {
	$log( "Cannot read config file: {$json_path}", 'error' );
	exit( 1 );
}

$raw = file_get_contents( $json_path );
if ( false === $raw ) {
	$log( "Failed to read: {$json_path}", 'error' );
	exit( 1 );
}

$config = json_decode( $raw, true );
if ( ! is_array( $config ) ) {
	$log( 'Config JSON did not decode to an object/array. Check syntax.', 'error' );
	exit( 1 );
}

/**
 * Whitelist of accepted keys → sanitizer callable.
 *
 * Keys without `hours_` prefix map directly to `lafka_business_<key>`.
 * Hours are nested under `config["hours"]` as a `{day: "HH:MM-HH:MM"|"closed"}` map
 * and written to `lafka_business_hours_<day>`.
 */
$schema = array(
	// Identity.
	'name'            => 'sanitize_text_field',
	'business_type'   => 'sanitize_text_field',
	'price_range'     => 'sanitize_text_field',

	// Location.
	'street'          => 'sanitize_text_field',
	'city'            => 'sanitize_text_field',
	'region'          => 'sanitize_text_field',
	'postal'          => 'sanitize_text_field',
	'country'         => 'sanitize_text_field',
	'geo_lat'         => static fn( $v ) => is_numeric( $v ) ? (string) (float) $v : '',
	'geo_lng'         => static fn( $v ) => is_numeric( $v ) ? (string) (float) $v : '',

	// Contact.
	'phone_e164'      => 'sanitize_text_field',
	'phone_display'   => 'sanitize_text_field',
	'email'           => 'sanitize_email',

	// Cuisine + payment (comma-separated lists).
	'cuisines'        => 'sanitize_text_field',
	'payment_methods' => 'sanitize_text_field',

	// Citations (newline-separated URLs).
	'same_as'         => 'sanitize_textarea_field',
);

$valid_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

$planned = array();
$skipped = array();

foreach ( $schema as $key => $sanitizer ) {
	if ( ! array_key_exists( $key, $config ) ) {
		continue;
	}
	$value = call_user_func( $sanitizer, (string) $config[ $key ] );
	if ( '' === $value ) {
		continue;
	}
	$mod_key = 'lafka_business_' . $key;
	$current = (string) get_theme_mod( $mod_key, '' );
	if ( '' !== $current && ! $force ) {
		$skipped[ $mod_key ] = $current;
		continue;
	}
	$planned[ $mod_key ] = $value;
}

if ( isset( $config['hours'] ) && is_array( $config['hours'] ) ) {
	foreach ( $config['hours'] as $day => $val ) {
		$day = strtolower( (string) $day );
		if ( ! in_array( $day, $valid_days, true ) ) {
			$log( "Skipping unknown hours day: {$day}", 'warning' );
			continue;
		}
		$val = trim( (string) $val );
		if ( '' === $val ) {
			continue;
		}
		if ( 'closed' !== strtolower( $val ) && ! preg_match( '/^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/', $val ) ) {
			$log( "Skipping malformed hours for {$day}: {$val} (expected HH:MM-HH:MM or 'closed')", 'warning' );
			continue;
		}
		$mod_key = 'lafka_business_hours_' . $day;
		$current = (string) get_theme_mod( $mod_key, '' );
		if ( '' !== $current && ! $force ) {
			$skipped[ $mod_key ] = $current;
			continue;
		}
		$planned[ $mod_key ] = $val;
	}
}

$log( '— Lafka restaurant-info migration —' );
$log( 'Source: ' . $json_path );
$log( 'Mode:   ' . ( $dry_run ? 'DRY RUN (no writes)' : 'WRITE' ) . ( $force ? ' [force overwrite]' : ' [skip-if-set]' ) );
$log( 'Planned: ' . count( $planned ) . ' / Skipped (already set): ' . count( $skipped ) );

if ( count( $skipped ) > 0 ) {
	$log( '' );
	$log( 'Skipped (already set, use LAFKA_RESTAURANT_INFO_FORCE=1 to overwrite):' );
	foreach ( $skipped as $k => $v ) {
		$log( "  - {$k} = " . ( strlen( $v ) > 60 ? substr( $v, 0, 57 ) . '...' : $v ) );
	}
}

if ( count( $planned ) === 0 ) {
	$log( 'Nothing to write.', 'success' );
	exit( 0 );
}

$log( '' );
$log( 'Planned writes:' );
foreach ( $planned as $k => $v ) {
	$log( "  + {$k} = " . ( strlen( $v ) > 60 ? substr( $v, 0, 57 ) . '...' : $v ) );
}

if ( $dry_run ) {
	$log( 'Dry run complete. Re-run without LAFKA_RESTAURANT_INFO_DRY_RUN to apply.', 'success' );
	exit( 0 );
}

$written = 0;
foreach ( $planned as $k => $v ) {
	set_theme_mod( $k, $v );
	$written++;
}

$log( "Wrote {$written} settings.", 'success' );
$log( 'Verify in Customizer → Lafka — Restaurant Information.' );
