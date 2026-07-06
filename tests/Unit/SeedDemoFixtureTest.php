<?php
/**
 * NX1-09a: `wp lafka seed-demo` fixture + seeder logic contract.
 *
 * Full end-to-end seeding needs a live WP+WC install (it creates products,
 * sideloads images, writes terms/posts). These are the unit-level guarantees
 * that DON'T need WordPress and that regression-lock the deterministic kernel
 * every downstream e2e/preset job depends on:
 *
 *   - the fixture data file is deterministic + structurally valid (12 products
 *     across 4 neutral categories, unique slugs, both simple + variable types),
 *   - the fixture text carries ZERO operator-specific literals (a public,
 *     sellable demo store must never leak the launch operator's brand) — reuses
 *     the exact DocsNoOperatorLiteralsTest literal list,
 *   - both required addon pricing strategies are exercised (flat_per_option +
 *     flat_group) and assigned to a real category,
 *   - business info is fake-but-schema-valid (E.164 phone, numeric geo, email),
 *   - the always-open order-hours schedule decodes to 7 open days,
 *   - the manifest round-trips through the option store,
 *   - the create-vs-update-by-slug idempotency decision is correct,
 *   - the generated delivery polygon encodes to a string the plugin's own
 *     geo-fence decoder reproduces (center inside, far point outside).
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_CLI_Seed_Demo;
use Lafka_Shipping_Areas;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/cli/class-lafka-cli-seed-demo.php';
require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';

final class SeedDemoFixtureTest extends TestCase {

	/**
	 * The exact operator-literal list guarded in DocsNoOperatorLiteralsTest —
	 * the seeded demo store is public + sellable and must read as a generic
	 * restaurant, never as the launch operator's site.
	 *
	 * @var array<int, string>
	 */
	private const OPERATOR_LITERALS = array(
		'Peppery',
		'pepperypizzapoutine',
		'poutine',
		'Sackville',
		'Halifax',
		'\bHRM\b',
		'Garlic Fingers',
		'Meat Lovers',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── First-run taxonomy self-registration ──────────────────────────────
	// The shipping_areas module (which owns lafka_branch_location) is gated
	// OFF on a fresh install and only loads at plugins_loaded, so the first
	// seed run must register the taxonomy itself or wp_insert_term() rejects
	// the branch with "Invalid taxonomy" (caught live on wp-env, 2026-07-06).

	public function test_ensure_branch_taxonomy_registers_when_absent(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\expect( 'register_taxonomy' )
			->once()
			->with( 'lafka_branch_location', 'product', \Mockery::type( 'array' ) );

		Lafka_CLI_Seed_Demo::ensure_branch_taxonomy();
		$this->addToAssertionCount( 1 ); // Mockery verifies the expectation in tearDown.
	}

	public function test_ensure_branch_taxonomy_noops_when_present(): void {
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\expect( 'register_taxonomy' )->never();

		Lafka_CLI_Seed_Demo::ensure_branch_taxonomy();
		$this->addToAssertionCount( 1 ); // Mockery verifies the expectation in tearDown.
	}

	// ─── Fixture integrity ──────────────────────────────────────────────────

	public function test_fixtures_expose_all_top_level_sections(): void {
		$f = Lafka_CLI_Seed_Demo::fixtures();
		foreach ( array( 'business', 'order_hours', 'flags', 'categories', 'products', 'addon_groups', 'branch', 'area', 'page_menu' ) as $key ) {
			self::assertArrayHasKey( $key, $f, "fixtures() is missing the '$key' section" );
		}
	}

	public function test_exactly_four_categories_with_unique_deterministic_slugs(): void {
		$cats  = Lafka_CLI_Seed_Demo::fixtures()['categories'];
		$slugs = array_column( $cats, 'slug' );

		self::assertCount( 4, $cats );
		self::assertSame( array_unique( $slugs ), $slugs, 'category slugs must be unique' );
		foreach ( $slugs as $slug ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9-]+$/', (string) $slug, 'category slug must be a clean deterministic slug' );
		}
	}

	public function test_exactly_twelve_products_spread_across_all_four_categories(): void {
		$f          = Lafka_CLI_Seed_Demo::fixtures();
		$cat_slugs  = array_column( $f['categories'], 'slug' );
		$products   = $f['products'];
		$prod_slugs = array_column( $products, 'slug' );
		$used_cats  = array();

		self::assertCount( 12, $products );
		self::assertSame( array_unique( $prod_slugs ), $prod_slugs, 'product slugs must be unique' );

		foreach ( $products as $product ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9-]+$/', (string) $product['slug'] );
			self::assertContains( $product['category'], $cat_slugs, "product {$product['slug']} references an unknown category" );
			self::assertContains( $product['type'], array( 'simple', 'variable' ) );
			$used_cats[ $product['category'] ] = true;
		}

		foreach ( $cat_slugs as $slug ) {
			self::assertArrayHasKey( $slug, $used_cats, "category '$slug' has no products" );
		}
	}

	public function test_products_include_both_simple_and_variable_types(): void {
		$types = array_column( Lafka_CLI_Seed_Demo::fixtures()['products'], 'type' );
		self::assertContains( 'simple', $types );
		self::assertContains( 'variable', $types );
	}

	public function test_every_product_price_is_a_numeric_string(): void {
		foreach ( Lafka_CLI_Seed_Demo::fixtures()['products'] as $product ) {
			if ( 'variable' === $product['type'] ) {
				self::assertNotEmpty( $product['variations'], "variable product {$product['slug']} needs variations" );
				foreach ( $product['variations'] as $variation ) {
					self::assertIsNumeric( $variation['price'], "variation price for {$product['slug']} must be numeric" );
				}
				continue;
			}
			self::assertIsNumeric( $product['price'], "price for {$product['slug']} must be numeric" );
		}
	}

	public function test_fixture_text_is_free_of_operator_literals(): void {
		$blob = $this->flatten_strings( Lafka_CLI_Seed_Demo::fixtures() );
		$hits = array();
		foreach ( self::OPERATOR_LITERALS as $literal ) {
			if ( 1 === preg_match( '/' . $literal . '/i', $blob ) ) {
				$hits[] = $literal;
			}
		}
		self::assertSame( array(), $hits, 'seed fixtures must not contain any operator-specific literal: ' . implode( ', ', $hits ) );
	}

	// ─── Addon groups ───────────────────────────────────────────────────────

	public function test_addon_groups_exercise_both_required_pricing_strategies(): void {
		$f          = Lafka_CLI_Seed_Demo::fixtures();
		$cat_slugs  = array_column( $f['categories'], 'slug' );
		$addon_sets = $f['addon_groups'];
		$modes      = array();

		self::assertGreaterThanOrEqual( 2, count( $addon_sets ), 'need at least two addon groups' );

		foreach ( $addon_sets as $set ) {
			self::assertContains( $set['category'], $cat_slugs, 'addon group must target a real category' );
			self::assertSame( 'pizzas', $set['category'], 'the demo assigns addon groups to the pizza category' );
			foreach ( $set['product_addons'] as $group ) {
				$modes[ $group['pricing_mode'] ] = true;
			}
		}

		self::assertArrayHasKey( 'flat_per_option', $modes, 'flat_per_option strategy must be exercised' );
		self::assertArrayHasKey( 'flat_group', $modes, 'flat_group strategy must be exercised' );
	}

	public function test_flat_group_addon_carries_a_group_flat_price(): void {
		foreach ( Lafka_CLI_Seed_Demo::fixtures()['addon_groups'] as $set ) {
			foreach ( $set['product_addons'] as $group ) {
				if ( 'flat_group' === $group['pricing_mode'] ) {
					self::assertIsNumeric( $group['group_flat_price'], 'flat_group addon needs a numeric group_flat_price' );
				}
				if ( 'flat_per_option' === $group['pricing_mode'] ) {
					foreach ( $group['options'] as $opt ) {
						self::assertIsNumeric( $opt['price'], 'flat_per_option addon options need numeric prices' );
					}
				}
			}
		}
	}

	// ─── Business info (fake but schema-valid) ──────────────────────────────

	public function test_business_info_is_fake_but_schema_valid(): void {
		$b = Lafka_CLI_Seed_Demo::fixtures()['business'];

		self::assertNotEmpty( $b['lafka_business_name'] );
		self::assertMatchesRegularExpression( '/^\S+@\S+\.\S+$/', (string) $b['lafka_business_email'] );
		self::assertMatchesRegularExpression( '/^\+[1-9]\d{6,14}$/', (string) $b['lafka_business_phone_e164'], 'phone must be valid E.164' );
		self::assertIsNumeric( $b['lafka_business_geo_lat'] );
		self::assertIsNumeric( $b['lafka_business_geo_lng'] );
		// example.com / example.* addresses are the reserved fake-data domain.
		self::assertStringContainsString( 'example', strtolower( (string) $b['lafka_business_email'] ) );
	}

	// ─── Always-open order hours ────────────────────────────────────────────

	public function test_order_hours_schedule_is_open_all_seven_days(): void {
		$opts = Lafka_CLI_Seed_Demo::fixtures()['order_hours'];
		self::assertArrayHasKey( 'lafka_order_hours_schedule', $opts );

		$schedule = json_decode( (string) $opts['lafka_order_hours_schedule'], true );
		self::assertIsArray( $schedule );
		self::assertCount( 7, $schedule, 'schedule must cover all 7 days' );
		foreach ( $schedule as $day ) {
			self::assertNotEmpty( $day['periods'], 'each day must have at least one open period' );
		}
	}

	public function test_flags_enable_order_hours_and_shipping_areas(): void {
		$flags = Lafka_CLI_Seed_Demo::fixtures()['flags'];
		self::assertSame( 'enabled', $flags['order_hours'] );
		self::assertSame( 'enabled', $flags['shipping_areas'] );
	}

	// ─── Manifest round-trip ────────────────────────────────────────────────

	public function test_empty_manifest_has_versioned_id_buckets(): void {
		$m = Lafka_CLI_Seed_Demo::empty_manifest();
		self::assertSame( Lafka_CLI_Seed_Demo::MANIFEST_VERSION, $m['version'] );
		self::assertArrayHasKey( 'ids', $m );
		self::assertIsArray( $m['ids'] );
	}

	public function test_record_then_recorded_id_returns_the_id(): void {
		$m = Lafka_CLI_Seed_Demo::empty_manifest();
		$m = Lafka_CLI_Seed_Demo::record( $m, 'products', 'margherita-pizza', 42 );

		self::assertSame( 42, Lafka_CLI_Seed_Demo::recorded_id( $m, 'products', 'margherita-pizza' ) );
		self::assertSame( 0, Lafka_CLI_Seed_Demo::recorded_id( $m, 'products', 'does-not-exist' ) );
		self::assertSame( 0, Lafka_CLI_Seed_Demo::recorded_id( $m, 'nope', 'margherita-pizza' ) );
	}

	public function test_manifest_saves_and_loads_through_the_option_store(): void {
		$store = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		$m = Lafka_CLI_Seed_Demo::empty_manifest();
		$m = Lafka_CLI_Seed_Demo::record( $m, 'categories', 'pizzas', 7 );
		$m = Lafka_CLI_Seed_Demo::record( $m, 'areas', 'demo-delivery-zone', 99 );
		Lafka_CLI_Seed_Demo::save_manifest( $m );

		self::assertArrayHasKey( Lafka_CLI_Seed_Demo::MANIFEST_OPTION, $store );

		$loaded = Lafka_CLI_Seed_Demo::load_manifest();
		self::assertSame( 7, Lafka_CLI_Seed_Demo::recorded_id( $loaded, 'categories', 'pizzas' ) );
		self::assertSame( 99, Lafka_CLI_Seed_Demo::recorded_id( $loaded, 'areas', 'demo-delivery-zone' ) );
	}

	public function test_load_manifest_normalises_a_missing_or_corrupt_option(): void {
		Functions\when( 'get_option' )->justReturn( 'not-an-array' );
		$m = Lafka_CLI_Seed_Demo::load_manifest();
		self::assertSame( Lafka_CLI_Seed_Demo::MANIFEST_VERSION, $m['version'] );
		self::assertSame( 0, Lafka_CLI_Seed_Demo::recorded_id( $m, 'products', 'anything' ) );
	}

	// ─── Idempotency decision ───────────────────────────────────────────────

	public function test_decide_action_is_create_when_absent_and_update_when_present(): void {
		self::assertSame( 'create', Lafka_CLI_Seed_Demo::decide_action( 0 ) );
		self::assertSame( 'create', Lafka_CLI_Seed_Demo::decide_action( -1 ) );
		self::assertSame( 'update', Lafka_CLI_Seed_Demo::decide_action( 5 ) );
	}

	// ─── Delivery polygon ───────────────────────────────────────────────────

	public function test_square_polygon_encodes_to_a_geofence_the_plugin_decodes(): void {
		$f      = Lafka_CLI_Seed_Demo::fixtures();
		$lat    = (float) $f['area']['lat'];
		$lng    = (float) $f['area']['lng'];
		$points = Lafka_CLI_Seed_Demo::square_polygon( $lat, $lng, 0.05 );

		self::assertGreaterThanOrEqual( 4, count( $points ) );

		$encoded = Lafka_CLI_Seed_Demo::encode_polygon_coordinates( $points );
		$decoded = Lafka_Shipping_Areas::decode_polygon_coordinates( $encoded );

		self::assertGreaterThanOrEqual( 3, count( $decoded ), 'a usable geo-fence needs at least 3 vertices' );
		self::assertTrue( Lafka_Shipping_Areas::point_in_polygon( $lat, $lng, $decoded ), 'the fake centre must fall inside the seeded delivery zone' );
		self::assertFalse( Lafka_Shipping_Areas::point_in_polygon( $lat + 10.0, $lng, $decoded ), 'a far-away point must fall outside the seeded delivery zone' );
	}

	// ─── CLI registration ───────────────────────────────────────────────────

	public function test_cli_command_is_registered_and_loaded_by_the_plugin(): void {
		$module = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/cli/class-lafka-cli-seed-demo.php' );
		self::assertMatchesRegularExpression(
			"/WP_CLI::add_command\(\s*['\"]lafka seed-demo['\"]\s*,/",
			$module,
			'the seeder must register the `lafka seed-demo` command'
		);

		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		self::assertStringContainsString( 'incl/cli/class-lafka-cli-seed-demo.php', $main, 'the plugin must require the seeder' );
	}

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Recursively concatenate every string key and value in an array so a
	 * single regex sweep can prove no operator literal hides anywhere.
	 *
	 * @param mixed $data Fixture value.
	 * @return string
	 */
	private function flatten_strings( $data ): string {
		$out = '';
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$out .= ' ' . ( is_string( $key ) ? $key : '' ) . ' ' . $this->flatten_strings( $value );
			}
			return $out;
		}
		if ( is_scalar( $data ) ) {
			return ' ' . (string) $data;
		}
		return $out;
	}
}
