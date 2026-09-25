<?php
/**
 * GX3: one-time migration of the legacy Customizer NAP store into options.
 *
 * Business facts (name / address / phone / geo / hours / cuisines / payment
 * / sameAs …) used to live in TWO stores: wp_options `lafka_business_*`
 * (written by WooCommerce → Settings → Restaurant) and theme_mods of the
 * same names (written by the Customizer "Restaurant Information" panel),
 * with the option silently winning. An operator who fixed the phone in the
 * Customizer never saw it reach the site.
 *
 * From 10.2.0 the option is the only store: the Customizer settings are
 * `type => option`, and lafka_get_restaurant_info() no longer reads
 * theme_mods. This migration copies each legacy theme_mod into its option
 * ONLY where the option is empty, exactly once (version-flagged), so no
 * operator value is lost and no option the operator already set is
 * overwritten. The literal "Array" (a pre-9.11 cast bug) is never copied.
 *
 * The theme_mods themselves are left in place (non-destructive); Site
 * Health (incl/site-health/class-lafka-site-health-seo.php) reports any
 * that still disagree with the option so the operator can settle them.
 *
 * @package Lafka\Plugin\Schema
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_NAP_STORE_VERSION' ) ) {
	/** Bump to re-run the migration on the next request. */
	define( 'LAFKA_NAP_STORE_VERSION', 1 );
}

if ( ! function_exists( 'lafka_nap_field_keys' ) ) {
	/**
	 * The `lafka_business_<key>` suffixes that make up the business record.
	 *
	 * @return list<string>
	 */
	function lafka_nap_field_keys(): array {
		return array(
			'name',
			'street',
			'city',
			'region',
			'postal',
			'country',
			'phone_e164',
			'phone_display',
			'email',
			'geo_lat',
			'geo_lng',
			'price_range',
			'business_type',
			'cuisines',
			'payment_methods',
			'same_as',
			'hours_mon',
			'hours_tue',
			'hours_wed',
			'hours_thu',
			'hours_fri',
			'hours_sat',
			'hours_sun',
			'description',
			'map_url',
			'service_areas',
		);
	}
}

if ( ! function_exists( 'lafka_nap_migrate_theme_mods' ) ) {
	/**
	 * Copy legacy `lafka_business_*` theme_mods into empty options, then mark
	 * the store migrated. Idempotent: an already-set option is never touched.
	 *
	 * @return array<string,mixed> Map of option name => value that was copied.
	 */
	function lafka_nap_migrate_theme_mods(): array {
		$copied = array();
		foreach ( lafka_nap_field_keys() as $key ) {
			$name   = 'lafka_business_' . $key;
			$option = get_option( $name, null );
			if ( lafka_schema_is_set_value( $option ) ) {
				continue;
			}
			$legacy = get_theme_mod( $name, null );
			if ( ! lafka_schema_is_set_value( $legacy ) ) {
				continue;
			}
			// List fields were sometimes stored as arrays by third-party
			// sanitizers; the option store is the one-line/CSV text the
			// settings screens edit.
			if ( is_array( $legacy ) ) {
				$legacy = implode( 'same_as' === $key || 'service_areas' === $key ? "\n" : ', ', lafka_schema_normalize_line_list( $legacy ) );
				if ( '' === $legacy ) {
					continue;
				}
			}
			update_option( $name, $legacy );
			$copied[ $name ] = $legacy;
		}
		update_option( 'lafka_business_store_version', LAFKA_NAP_STORE_VERSION );
		return $copied;
	}
}

if ( ! function_exists( 'lafka_nap_maybe_migrate' ) ) {
	/**
	 * Run the migration once per LAFKA_NAP_STORE_VERSION. Hooked on `init`
	 * (theme_mods are readable once the theme is set up), so an in-place
	 * upgrade migrates on its first request — no activation needed.
	 *
	 * @return void
	 */
	function lafka_nap_maybe_migrate(): void {
		if ( (int) get_option( 'lafka_business_store_version', 0 ) >= LAFKA_NAP_STORE_VERSION ) {
			return;
		}
		lafka_nap_migrate_theme_mods();
	}
}

add_action( 'init', 'lafka_nap_maybe_migrate', 1 );
