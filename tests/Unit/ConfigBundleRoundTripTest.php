<?php
/**
 * NX1-05: Lafka_Config_Bundle export/import contract.
 *
 * Drives the whole bundle through an in-memory WordPress mock (options,
 * theme_mods, terms + term meta, posts + post meta) so the round-trip can be
 * asserted per section without booting WordPress:
 *
 *   - envelope is versioned + carries an excluded-secrets manifest,
 *   - export NEVER contains a known secret (Google Maps key, VAPID keys,
 *     analytics/tracking IDs, KDS options),
 *   - export → wipe → import restores an identical bundle for EVERY section
 *     (terms/posts matched by slug/title so ids need not survive),
 *   - a second import is a no-op (idempotent create/update, never delete),
 *   - unknown sections are skipped with a warning,
 *   - a malformed known section fails loudly (ok=false) and is NOT applied,
 *   - dry-run reports counts without writing anything.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Config_Bundle;
use Lafka_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 2 ) . '/incl/tools/class-lafka-config-bundle.php';

final class ConfigBundleRoundTripTest extends TestCase {

	/** @var array<string,mixed> In-memory WordPress state, wired via wire_wp(). */
	private $stores = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── Envelope ───────────────────────────────────────────────────────────

	public function test_export_envelope_is_versioned_and_carries_manifest(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$bundle = Lafka_Config_Bundle::export();

		self::assertSame( 1, Lafka_Config_Bundle::SCHEMA_VERSION );
		self::assertSame( 1, $bundle['schema_version'] );
		self::assertArrayHasKey( 'generated_at', $bundle );
		self::assertSame( 'https://example.test', $bundle['site_url'] );
		self::assertArrayHasKey( 'sections', $bundle );
		self::assertArrayHasKey( 'manifest', $bundle );
		self::assertNotEmpty( $bundle['manifest']['excluded'] );

		// Every declared section id is present in the export.
		foreach ( Lafka_Config_Bundle::section_ids() as $id ) {
			self::assertArrayHasKey( $id, $bundle['sections'], "section $id missing from export" );
		}
	}

	public function test_export_json_round_trips_through_json(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$json    = Lafka_Config_Bundle::export_json();
		$decoded = json_decode( $json, true );

		self::assertIsArray( $decoded );
		self::assertSame( Lafka_Config_Bundle::export(), $decoded );
	}

	// ─── Secrets exclusion ──────────────────────────────────────────────────

	public function test_export_never_contains_any_known_secret(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$json = Lafka_Config_Bundle::export_json();

		foreach (
			array(
				'SECRET_MAPS_KEY',        // Google Maps API key (flags + shipping general)
				'VAPID_PUBLIC_SECRET',    // web-push VAPID public key (theme_mod)
				'VAPID_PRIVATE_SECRET',   // web-push VAPID private key (theme_mod)
				'G-ANALYTICSID',          // GA4 measurement id (theme_mod)
				'GTM-SECRET99',           // GTM container id (theme_mod)
				'CF_BEACON_SECRET',       // Cloudflare beacon token (theme_mod)
				'KDS_SECRET_TOKEN',       // KDS options (excluded entirely)
			) as $needle
		) {
			self::assertStringNotContainsString( $needle, $json, "secret '$needle' leaked into the bundle" );
		}

		$bundle = Lafka_Config_Bundle::export();
		self::assertArrayNotHasKey( 'google_maps_api_key', $bundle['sections']['flags'] );
		self::assertArrayNotHasKey( 'google_maps_api_key', $bundle['sections']['shipping_areas']['lafka_shipping_areas_general'] );
		// Non-secret shipping settings survive.
		self::assertSame( '5', $bundle['sections']['shipping_areas']['lafka_shipping_areas_general']['default_radius'] );
	}

	public function test_theme_mods_section_keeps_only_lafka_non_secret_keys(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$mods = Lafka_Config_Bundle::export()['sections']['theme_mods'];

		// Kept: lafka-prefixed, non-secret feature toggles.
		self::assertSame( '1', $mods['lafka_ac_enabled'] );
		self::assertSame( '1', $mods['lafka_pdp_redesign_enabled'] );
		// Dropped: core theme mods + every analytics/secret key.
		self::assertArrayNotHasKey( 'custom_logo', $mods );
		self::assertArrayNotHasKey( 'lafka_ga4_measurement_id', $mods );
		self::assertArrayNotHasKey( 'lafka_gtm_container_id', $mods );
		self::assertArrayNotHasKey( 'lafka_push_vapid_public_key', $mods );
		self::assertArrayNotHasKey( 'lafka_push_vapid_private_key', $mods );
		self::assertArrayNotHasKey( 'lafka_analytics_clarity_id', $mods );
		self::assertArrayNotHasKey( 'lafka_cf_beacon_token', $mods );
	}

	// ─── Round-trip: export → wipe → import → identical ─────────────────────

	public function test_round_trip_restores_every_section(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$first = Lafka_Config_Bundle::export();

		// Wipe every backing store, then import the bundle into the empty site.
		$this->wipe_state();
		$report = Lafka_Config_Bundle::import( $first );

		self::assertTrue( $report['ok'], 'import must succeed for a well-formed bundle' );
		self::assertEmpty( $report['errors'] );

		// Re-export the freshly-imported site: it must equal the original
		// (payloads key on slug/title + meta, never on volatile term/post ids).
		$second = Lafka_Config_Bundle::export();
		self::assertEquals( $first['sections'], $second['sections'] );

		// Spot-check a representative unit per storage backend.
		self::assertSame( 'enabled', $this->stores['options']['lafka']['kitchen_display'] );
		self::assertSame( 'Fake Pizzeria', $this->stores['options']['lafka_business_name'] );
		self::assertSame( '25', $this->stores['options']['lafka_free_delivery_threshold'] );
		$branch = $this->find_term( 'downtown', 'lafka_branch_location' );
		self::assertNotNull( $branch );
		self::assertSame( 'delivery', $this->stores['term_meta'][ $branch->term_id ]['lafka_branch_order_type'] );
		$area = $this->find_post( 'North Zone', 'lafka_shipping_areas' );
		self::assertNotNull( $area );
		self::assertSame( '44.6,-63.6|44.7,-63.5', $this->stores['post_meta'][ $area->ID ]['_lafka_shipping_area_polygon_coordinates'] );
		$addon = $this->find_post( 'Pizza Toppings', 'lafka_glb_addon' );
		self::assertNotNull( $addon );
		self::assertSame( array( array( 'name' => 'Extra cheese' ) ), $this->stores['post_meta'][ $addon->ID ]['_product_addons'] );
	}

	public function test_import_is_idempotent_on_second_run(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();
		$bundle = Lafka_Config_Bundle::export();

		$this->wipe_state();

		$first  = Lafka_Config_Bundle::import( $bundle );
		$second = Lafka_Config_Bundle::import( $bundle );

		self::assertTrue( $second['ok'] );
		foreach ( $second['sections'] as $id => $counts ) {
			self::assertSame( 0, $counts['created'], "section $id created on the idempotent second import" );
			self::assertSame( 0, $counts['updated'], "section $id updated on the idempotent second import" );
		}
		// The first import did real work (sanity: not a no-op harness).
		$created_first = array_sum( array_column( $first['sections'], 'created' ) );
		self::assertGreaterThan( 0, $created_first );
	}

	// ─── Validation + robustness ────────────────────────────────────────────

	public function test_unknown_section_is_skipped_with_a_warning(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();

		$report = Lafka_Config_Bundle::import(
			array(
				'schema_version' => 1,
				'sections'       => array( 'not_a_real_section' => array( 'x' => 'y' ) ),
			)
		);

		self::assertTrue( $report['ok'], 'an unknown section is a warning, not a hard failure' );
		self::assertNotEmpty( $report['warnings'] );
		self::assertArrayNotHasKey( 'not_a_real_section', $report['sections'] );
	}

	public function test_malformed_known_section_fails_loudly_and_is_not_applied(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();
		$before = $this->stores['options'];

		// branches must be a LIST of term records; a scalar is malformed.
		$report = Lafka_Config_Bundle::import(
			array(
				'schema_version' => 1,
				'sections'       => array( 'branches' => 'not-a-list' ),
			)
		);

		self::assertFalse( $report['ok'], 'a malformed known section must fail loudly' );
		self::assertNotEmpty( $report['errors'] );
		self::assertSame( $before, $this->stores['options'], 'a rejected section must not write anything' );
	}

	public function test_a_valid_section_is_not_applied_when_a_sibling_is_malformed(): void {
		$this->stores           = $this->build_full_state();
		$this->stores['options'] = array(); // start clean so a write would be visible
		$this->wire_wp();

		$report = Lafka_Config_Bundle::import(
			array(
				'schema_version' => 1,
				'sections'       => array(
					'business' => array( 'lafka_business_name' => 'Should Not Persist' ),
					'branches' => 'not-a-list', // malformed → blocks the whole import
				),
			)
		);

		self::assertFalse( $report['ok'] );
		self::assertArrayNotHasKey( 'business', $report['sections'], 'no section may be applied when a sibling is malformed' );
		self::assertArrayNotHasKey( 'lafka_business_name', $this->stores['options'], 'the valid section must not have been written' );
	}

	public function test_import_json_rejects_invalid_json(): void {
		$this->wire_wp();

		$report = Lafka_Config_Bundle::import_json( '{ this is not json ]' );

		self::assertFalse( $report['ok'] );
		self::assertNotEmpty( $report['errors'] );
	}

	public function test_import_rejects_wrong_schema_version(): void {
		$this->wire_wp();

		$report = Lafka_Config_Bundle::import(
			array(
				'schema_version' => 999,
				'sections'       => array(),
			)
		);

		self::assertFalse( $report['ok'] );
		self::assertNotEmpty( $report['errors'] );
	}

	public function test_dry_run_reports_counts_without_writing(): void {
		$this->stores = $this->build_full_state();
		$this->wire_wp();
		$bundle = Lafka_Config_Bundle::export();

		$this->wipe_state();
		$snapshot = $this->snapshot_state();

		$report = Lafka_Config_Bundle::import( $bundle, true );

		self::assertTrue( $report['dry_run'] );
		self::assertTrue( $report['ok'] );
		// It still computes the create/update/skip counts for the diff table…
		$created = array_sum( array_column( $report['sections'], 'created' ) );
		self::assertGreaterThan( 0, $created );
		// …but touched nothing.
		self::assertEquals( $snapshot, $this->snapshot_state(), 'dry-run must not mutate any store' );
	}

	// ─── In-memory WordPress harness ────────────────────────────────────────

	/**
	 * A representative site: every section populated, secrets planted so the
	 * exclusion assertions have something to catch.
	 *
	 * @return array<string,mixed>
	 */
	private function build_full_state(): array {
		$term = new stdClass();
		$term->term_id  = 11;
		$term->name     = 'Downtown';
		$term->slug     = 'downtown';
		$term->taxonomy = 'lafka_branch_location';

		$area = new stdClass();
		$area->ID          = 21;
		$area->post_title  = 'North Zone';
		$area->post_type   = 'lafka_shipping_areas';
		$area->post_status = 'publish';

		$addon = new stdClass();
		$addon->ID          = 31;
		$addon->post_title  = 'Pizza Toppings';
		$addon->post_type   = 'lafka_glb_addon';
		$addon->post_status = 'publish';

		return array(
			'next_term_id' => 100,
			'next_post_id' => 500,
			'options'      => array(
				'lafka'                              => array(
					'product_addons'     => 'enabled',
					'shipping_areas'     => 'enabled',
					'order_hours'        => 'enabled',
					'kitchen_display'    => 'enabled',
					'order_notifications' => '1',
					'google_maps_api_key' => 'SECRET_MAPS_KEY',
				),
				'lafka_business_name'                => 'Fake Pizzeria',
				'lafka_business_phone_display'       => '(902) 555-0100',
				'lafka_business_geo_lat'             => '44.6488',
				'lafka_business_hours_mon'           => '11:00-23:00',
				'lafka_free_delivery_threshold'      => '25',
				'lafka_first_order_discount_percent' => '10',
				'lafka_slow_day_days'                => array( '1', '2' ),
				'lafka_order_hours_options'          => array(
					'lafka_order_hours_schedule' => 'mon=11:00-23:00',
					'lafka_order_hours_message'  => 'Closed',
				),
				'lafka_shipping_areas_general'       => array(
					'default_radius'      => '5',
					'google_maps_api_key' => 'SECRET_MAPS_KEY',
				),
				'lafka_shipping_areas_datetime'      => array( 'timeslot_duration' => '30' ),
				// Excluded-by-design: KDS options must never appear in the bundle.
				'lafka_kds_options'                  => array( 'token' => 'KDS_SECRET_TOKEN' ),
			),
			'theme_mods'   => array(
				'lafka_ac_enabled'             => '1',
				'lafka_pdp_redesign_enabled'   => '1',
				'lafka_review_target_url'      => 'https://example.test/review',
				'lafka_ga4_measurement_id'     => 'G-ANALYTICSID',
				'lafka_gtm_container_id'       => 'GTM-SECRET99',
				'lafka_analytics_clarity_id'   => 'CLARITY1',
				'lafka_cf_beacon_token'        => 'CF_BEACON_SECRET',
				'lafka_push_vapid_public_key'  => 'VAPID_PUBLIC_SECRET',
				'lafka_push_vapid_private_key' => 'VAPID_PRIVATE_SECRET',
				'custom_logo'                  => 7,
			),
			'terms'        => array( 11 => $term ),
			'term_meta'    => array(
				11 => array(
					'lafka_branch_order_type'   => 'delivery',
					'lafka_branch_address'      => '123 Main St',
					'lafka_branch_timezone'     => 'America/Halifax',
					'branch_id'                 => '11',
				),
			),
			'posts'        => array(
				21 => $area,
				31 => $addon,
			),
			'post_meta'    => array(
				21 => array(
					'_lafka_shipping_area_polygon_coordinates' => '44.6,-63.6|44.7,-63.5',
				),
				31 => array(
					'_product_addons'                => array( array( 'name' => 'Extra cheese' ) ),
					'_all_products'                  => '1',
					'_priority'                      => '10',
					'_product_addons_exclude_global' => '',
				),
			),
		);
	}

	private function wipe_state(): void {
		$this->stores['options']    = array();
		$this->stores['theme_mods'] = array();
		$this->stores['terms']      = array();
		$this->stores['term_meta']  = array();
		$this->stores['posts']      = array();
		$this->stores['post_meta']  = array();
		Lafka_Options::flush();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function snapshot_state(): array {
		return array(
			'options'    => $this->stores['options'],
			'theme_mods' => $this->stores['theme_mods'],
			'terms'      => array_keys( $this->stores['terms'] ),
			'term_meta'  => $this->stores['term_meta'],
			'posts'      => array_keys( $this->stores['posts'] ),
			'post_meta'  => $this->stores['post_meta'],
		);
	}

	private function find_term( string $slug, string $tax ): ?object {
		foreach ( $this->stores['terms'] as $term ) {
			if ( $term->taxonomy === $tax && $term->slug === $slug ) {
				return $term;
			}
		}
		return null;
	}

	private function find_post( string $title, string $type ): ?object {
		foreach ( $this->stores['posts'] as $post ) {
			if ( $post->post_type === $type && $post->post_title === $title ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Wire every WordPress function the bundle touches against $this->stores.
	 */
	private function wire_wp(): void {
		$stores =& $this->stores;

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0, $depth = 512 ) {
				return json_encode( $data, $flags, $depth );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $title ) );
			}
		);

		// ── Options ──
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$stores ) {
				return array_key_exists( $key, $stores['options'] ) ? $stores['options'][ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$stores ) {
				$stores['options'][ $key ] = $value;
				return true;
			}
		);

		// ── Theme mods ──
		Functions\when( 'get_theme_mods' )->alias(
			static function () use ( &$stores ) {
				return $stores['theme_mods'];
			}
		);
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = false ) use ( &$stores ) {
				return array_key_exists( $key, $stores['theme_mods'] ) ? $stores['theme_mods'][ $key ] : $default;
			}
		);
		Functions\when( 'set_theme_mod' )->alias(
			static function ( $key, $value ) use ( &$stores ) {
				$stores['theme_mods'][ $key ] = $value;
				return true;
			}
		);

		// ── Terms ──
		Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( &$stores ) {
				$tax = is_array( $args ) ? ( $args['taxonomy'] ?? '' ) : '';
				$out = array();
				foreach ( $stores['terms'] as $term ) {
					if ( $term->taxonomy === $tax ) {
						$out[] = $term;
					}
				}
				return $out;
			}
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $value, $tax ) use ( &$stores ) {
				foreach ( $stores['terms'] as $term ) {
					if ( $term->taxonomy === $tax && ( $term->{$field} ?? null ) === $value ) {
						return $term;
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			static function ( $name, $tax, $args = array() ) use ( &$stores ) {
				$id             = $stores['next_term_id']++;
				$term           = new stdClass();
				$term->term_id  = $id;
				$term->name     = $name;
				$term->slug     = $args['slug'] ?? strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $name ) );
				$term->taxonomy = $tax;
				$stores['terms'][ $id ] = $term;
				return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( $id, $key = '', $single = false ) use ( &$stores ) {
				if ( '' === $key ) {
					return $stores['term_meta'][ $id ] ?? array();
				}
				return $stores['term_meta'][ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_term_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$stores ) {
				$stores['term_meta'][ $id ][ $key ] = $value;
				return true;
			}
		);

		// ── Posts ──
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$stores ) {
				$type  = $args['post_type'] ?? 'post';
				$title = $args['title'] ?? null;
				$out   = array();
				foreach ( $stores['posts'] as $post ) {
					if ( $post->post_type !== $type ) {
						continue;
					}
					if ( null !== $title && '' !== $title && $post->post_title !== $title ) {
						continue;
					}
					$out[] = $post;
				}
				return $out;
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $arr ) use ( &$stores ) {
				$id               = $stores['next_post_id']++;
				$post             = new stdClass();
				$post->ID         = $id;
				$post->post_title = $arr['post_title'] ?? '';
				$post->post_type  = $arr['post_type'] ?? 'post';
				$post->post_status = $arr['post_status'] ?? 'publish';
				$stores['posts'][ $id ] = $post;
				return $id;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key = '', $single = false ) use ( &$stores ) {
				return $stores['post_meta'][ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$stores ) {
				$stores['post_meta'][ $id ][ $key ] = $value;
				return true;
			}
		);
	}

	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
