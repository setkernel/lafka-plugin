<?php
/**
 * P6-SEO-1/2/3/6 + W2-T1: JSON-LD structured data regression tests.
 *
 * Tests are a mix of source-grep locks (cheap, catch registration drift) and
 * functional generator tests (exercise the helper/generator functions directly
 * without booting WordPress via Brain Monkey stubs).
 *
 * After W2-T1 the schema generators read from `lafka_get_restaurant_info()`
 * (Customizer-driven). Tests assert STRUCTURE + Customizer-override behavior,
 * not literal Peppery values.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   8.8.1
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Bring in the schema module files directly so we can call the generator
 * functions without going through lafka-plugin.php (which would try to
 * load WP core and WooCommerce at include time).
 *
 * Brain Monkey stubs out the WP functions each generator needs.
 */
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-breadcrumb.php';
// lafka-schema-menu.php and lafka-schema-product.php depend heavily on
// WC_Product / wc_get_products — tested via source-grep and structural checks.

final class JsonLdSchemaTest extends TestCase {

	/**
	 * Test fixtures injected via stubbed get_theme_mod() so the resolver returns
	 * non-empty values for the structural assertions below.
	 */
	private const FIXTURES = array(
		'lafka_business_name'             => 'Acme Test Cafe',
		'lafka_business_street'           => '123 Test Street',
		'lafka_business_city'             => 'Testville',
		'lafka_business_region'           => 'TS',
		'lafka_business_postal'           => 'T1S 1S1',
		'lafka_business_country'          => 'CA',
		'lafka_business_phone_e164'       => '+15551234567',
		'lafka_business_phone_display'    => '+1 555-123-4567',
		'lafka_business_email'            => 'hello@example.test',
		'lafka_business_geo_lat'          => '45.0',
		'lafka_business_geo_lng'          => '-75.0',
		'lafka_business_price_range'      => '$$',
		'lafka_business_cuisines'         => 'Pizza, Italian',
		'lafka_business_payment_methods'  => 'Cash, Visa',
		'lafka_business_same_as'          => "https://example.test/page1\nhttps://example.test/page2",
		'lafka_business_hours_mon'        => '11:00-23:00',
		'lafka_business_hours_tue'        => '11:00-23:00',
		'lafka_business_hours_wed'        => '11:00-23:00',
		'lafka_business_hours_thu'        => '11:00-23:00',
		'lafka_business_hours_fri'        => '11:00-23:00',
		'lafka_business_hours_sat'        => '11:00-23:00',
		'lafka_business_hours_sun'        => '11:00-23:00',
		// Social-proof rating + count (theme_mods written by the Lafka theme's
		// social-proof Customizer panel). v9.22.0 surfaces these in the
		// Restaurant schema as aggregateRating.
		'lafka_social_proof_rating'       => '4.8',
		'lafka_social_proof_count'        => 1200,
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Wire up Brain Monkey stubs that simulate a populated Customizer install.
	 * Helper used by every functional test below.
	 */
	private function stub_populated_install(): void {
		Functions\when( 'get_theme_mod' )->alias( function ( $key, $default = null ) {
			return self::FIXTURES[ $key ] ?? $default;
		} );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Wire up Brain Monkey stubs that simulate an unconfigured install
	 * (no theme_mods, no options).
	 */
	private function stub_unconfigured_install(): void {
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 1. Orchestrator source-grep locks
	// ────────────────────────────────────────────────────────────────────────

	public function test_orchestrator_class_exists_in_source(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString( 'class Lafka_JSON_LD', $src );
	}

	public function test_wp_head_callback_registered_at_priority_11(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_head'\s*,\s*array\(\s*__CLASS__\s*,\s*'emit'\s*\)\s*,\s*11\s*\)/",
			$src,
			"wp_head hook must be registered at priority 11"
		);
	}

	public function test_orchestrator_loaded_from_main_plugin_file(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString(
			"incl/schema/class-lafka-json-ld.php",
			$src,
			'Main plugin file must require the JSON-LD orchestrator'
		);
	}

	public function test_emit_skips_admin_feed_404(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString( 'is_admin()', $src );
		$this->assertStringContainsString( 'is_feed()', $src );
		$this->assertStringContainsString( 'is_404()', $src );
	}

	public function test_emit_skips_when_basics_unconfigured(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		// Orchestrator must guard Restaurant emission on resolver basics being populated.
		$this->assertStringContainsString( 'lafka_get_restaurant_info', $src );
		$this->assertStringContainsString( '$has_basics', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.22.2 — Product schema description fallback (160-char cap)
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * Visual QA on 2026-05-18 found PDP Product entities had
	 * description: null because operators left short_description empty.
	 * Fallback to the first 160 chars of the long description prevents
	 * null and keeps it inside Google's rich-results truncation window.
	 */
	public function test_product_schema_falls_back_to_full_description(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString(
			'get_description',
			$src,
			'Product schema must read $product->get_description() as a fallback when short_description is empty.'
		);
	}

	/**
	 * The 160-char cap mirrors the SERP-snippet upper bound used by Google
	 * for Product.description in rich results.
	 */
	public function test_product_schema_caps_fallback_at_160_chars(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertMatchesRegularExpression(
			'/>\s*160|160\s*\)/',
			$src,
			'Product description fallback must cap at 160 chars.'
		);
	}

	/**
	 * Operator-set short_description must always win — never fall through
	 * to the truncated long description when an actual short blurb exists.
	 */
	public function test_product_schema_prefers_short_description(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString(
			'get_short_description',
			$src,
			'Product schema must check short_description first.'
		);
	}

	public function test_graph_wrapper_used(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString( "'@graph'", $src );
		$this->assertStringContainsString( "'@context'", $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 2. Helpers: lafka_schema_get_nap() — single source-of-truth
	// ────────────────────────────────────────────────────────────────────────

	public function test_helpers_nap_exposes_required_keys(): void {
		$this->stub_populated_install();
		$nap = lafka_schema_get_nap();

		$expected = array( 'name', 'street', 'city', 'region', 'postal', 'country', 'telephone', 'telephone_display' );
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $nap );
			$this->assertIsString( $nap[ $key ] );
		}
		$this->assertNotEmpty( $nap['name'] );
		$this->assertNotEmpty( $nap['street'] );
	}

	public function test_helpers_geo_returns_valid_coordinates_when_configured(): void {
		$this->stub_populated_install();
		$geo = lafka_schema_get_geo();

		$this->assertIsArray( $geo );
		$this->assertSame( 'GeoCoordinates', $geo['@type'] );
		$this->assertIsFloat( $geo['latitude'] );
		$this->assertIsFloat( $geo['longitude'] );
		$this->assertGreaterThanOrEqual( -90.0, $geo['latitude'] );
		$this->assertLessThanOrEqual( 90.0, $geo['latitude'] );
		$this->assertGreaterThanOrEqual( -180.0, $geo['longitude'] );
		$this->assertLessThanOrEqual( 180.0, $geo['longitude'] );
	}

	public function test_helpers_geo_returns_null_when_unconfigured(): void {
		$this->stub_unconfigured_install();
		$geo = lafka_schema_get_geo();
		$this->assertNull( $geo, 'Geo block must be null when lat/lng not both set, so schema generator can skip the field.' );
	}

	public function test_helpers_opening_hours_one_block_per_day_when_configured(): void {
		$this->stub_populated_install();
		$hours = lafka_schema_get_opening_hours();

		$this->assertNotEmpty( $hours );
		$this->assertCount( 7, $hours, 'Resolver emits one OpeningHoursSpecification block per configured day.' );
		foreach ( $hours as $spec ) {
			$this->assertSame( 'OpeningHoursSpecification', $spec['@type'] );
			$this->assertArrayHasKey( 'dayOfWeek', $spec );
			$this->assertArrayHasKey( 'opens', $spec );
			$this->assertArrayHasKey( 'closes', $spec );
		}
	}

	public function test_helpers_opening_hours_empty_when_unconfigured(): void {
		$this->stub_unconfigured_install();
		$this->assertSame( array(), lafka_schema_get_opening_hours() );
	}

	public function test_helpers_same_as_returns_filtered_url_list(): void {
		$this->stub_populated_install();
		$same_as = lafka_schema_get_same_as();
		$this->assertIsArray( $same_as );
		$this->assertNotEmpty( $same_as );
		foreach ( $same_as as $url ) {
			$this->assertIsString( $url );
			$this->assertNotFalse( filter_var( $url, FILTER_VALIDATE_URL ) );
		}
	}

	public function test_helpers_postal_address_structure(): void {
		$this->stub_populated_install();
		$addr = lafka_schema_get_postal_address();

		$this->assertIsArray( $addr );
		$this->assertSame( 'PostalAddress', $addr['@type'] );
		foreach ( array( 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry' ) as $k ) {
			$this->assertArrayHasKey( $k, $addr );
			$this->assertIsString( $addr[ $k ] );
		}
	}

	public function test_helpers_postal_address_returns_null_when_unconfigured(): void {
		$this->stub_unconfigured_install();
		$this->assertNull( lafka_schema_get_postal_address() );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 3. Restaurant generator: required fields
	// ────────────────────────────────────────────────────────────────────────

	public function test_restaurant_generator_returns_required_fields(): void {
		$this->stub_populated_install();
		$schema = lafka_schema_restaurant();

		$this->assertIsArray( $schema );
		$this->assertContains( 'Restaurant', (array) $schema['@type'] );
		$this->assertContains( 'LocalBusiness', (array) $schema['@type'] );
		$this->assertContains( 'FoodEstablishment', (array) $schema['@type'] );
		$this->assertArrayHasKey( 'name', $schema );
		$this->assertIsString( $schema['name'] );
		$this->assertNotEmpty( $schema['name'] );
		$this->assertArrayHasKey( 'telephone', $schema );
		$this->assertSame( '$$', $schema['priceRange'] );
		$this->assertFalse( $schema['acceptsReservations'] );
		$this->assertArrayHasKey( 'address', $schema );
		$this->assertSame( 'PostalAddress', $schema['address']['@type'] );
		$this->assertArrayHasKey( 'geo', $schema );
		$this->assertSame( 'GeoCoordinates', $schema['geo']['@type'] );
		$this->assertArrayHasKey( 'openingHoursSpecification', $schema );
		$this->assertNotEmpty( $schema['openingHoursSpecification'] );
		$this->assertArrayHasKey( 'sameAs', $schema );
		$this->assertNotEmpty( $schema['sameAs'] );
		$this->assertStringContainsString( '#restaurant', $schema['@id'] );
	}

	public function test_restaurant_generator_skips_empty_fields_when_unconfigured(): void {
		$this->stub_unconfigured_install();
		$schema = lafka_schema_restaurant();

		$this->assertIsArray( $schema );
		// Skipped because no values configured: address, geo, hours, sameAs, cuisines.
		$this->assertArrayNotHasKey( 'address', $schema, 'Address must be skipped when unconfigured.' );
		$this->assertArrayNotHasKey( 'geo', $schema, 'Geo must be skipped when unconfigured.' );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $schema, 'Hours must be skipped when unconfigured.' );
		$this->assertArrayNotHasKey( 'sameAs', $schema, 'sameAs must be skipped when empty.' );
		$this->assertArrayNotHasKey( 'servesCuisine', $schema, 'servesCuisine must be skipped when empty.' );
	}

	public function test_restaurant_schema_encodes_to_valid_json(): void {
		$this->stub_populated_install();
		$schema  = lafka_schema_restaurant();
		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $schema ),
		);

		$json = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $json, 'json_encode should not return false' );

		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'Decoded JSON must be an array' );
		$this->assertArrayHasKey( '@graph', $decoded );
	}

	public function test_restaurant_name_not_html_escaped_in_json(): void {
		// Inject a fixture name with an ampersand to verify JSON encoding.
		Functions\when( 'get_theme_mod' )->alias( function ( $key, $default = null ) {
			$fixtures = self::FIXTURES;
			$fixtures['lafka_business_name'] = 'Test & Co.';
			return $fixtures[ $key ] ?? $default;
		} );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$schema = lafka_schema_restaurant();
		$json   = json_encode( $schema, JSON_UNESCAPED_SLASHES );

		// json_encode (without JSON_HEX_AMP) preserves "&" as the literal character,
		// which is correct for JSON-LD — only HTML contexts need &amp;.
		$this->assertStringNotContainsString( '&amp;', $json );
		$decoded = json_decode( $json, true );
		$this->assertSame( 'Test & Co.', $decoded['name'] );
	}

	public function test_restaurant_emits_aggregate_rating_when_social_proof_configured(): void {
		$this->stub_populated_install();
		$schema = lafka_schema_restaurant();

		$this->assertArrayHasKey( 'aggregateRating', $schema );
		$this->assertSame( 'AggregateRating', $schema['aggregateRating']['@type'] );
		$this->assertSame( '4.8', $schema['aggregateRating']['ratingValue'] );
		$this->assertSame( 1200, $schema['aggregateRating']['reviewCount'] );
		$this->assertSame( '5', $schema['aggregateRating']['bestRating'] );
		$this->assertSame( '1', $schema['aggregateRating']['worstRating'] );
	}

	public function test_restaurant_omits_aggregate_rating_when_unconfigured(): void {
		$this->stub_unconfigured_install();
		$schema = lafka_schema_restaurant();

		$this->assertArrayNotHasKey( 'aggregateRating', $schema, 'aggregateRating must be skipped when social-proof unconfigured.' );
	}

	public function test_restaurant_omits_aggregate_rating_when_only_rating_set(): void {
		// Rating alone (no review count) is ambiguous — Google's rich-result
		// validator requires both. Verify partial data triggers omission.
		Functions\when( 'get_theme_mod' )->alias( function ( $key, $default = null ) {
			$only_rating = self::FIXTURES;
			$only_rating['lafka_social_proof_count'] = 0;
			return $only_rating[ $key ] ?? $default;
		} );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$schema = lafka_schema_restaurant();
		$this->assertArrayNotHasKey( 'aggregateRating', $schema, 'aggregateRating requires both rating AND count to emit.' );
	}

	public function test_wc_breadcrumb_jsonld_suppression_registered(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/seo/lafka-suppress-wc-breadcrumb-jsonld.php' );
		$this->assertNotFalse( $src, 'Suppression file must exist.' );
		$this->assertStringContainsString( "add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array' )", $src );
	}

	public function test_wc_breadcrumb_suppression_loaded_from_main_plugin_file(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka-suppress-wc-breadcrumb-jsonld.php', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 4. Breadcrumb generator
	// ────────────────────────────────────────────────────────────────────────

	public function test_breadcrumb_returns_null_on_front_page(): void {
		Functions\when( 'is_front_page' )->justReturn( true );

		$result = lafka_schema_breadcrumb();
		$this->assertNull( $result );
	}

	public function test_breadcrumb_list_item_builder(): void {
		$item = lafka_schema_breadcrumb_item( 1, 'Home', 'http://localhost:8891/' );

		$this->assertSame( 'ListItem', $item['@type'] );
		$this->assertSame( 1, $item['position'] );
		$this->assertSame( 'Home', $item['name'] );
		$this->assertSame( 'http://localhost:8891/', $item['item'] );
	}

	public function test_breadcrumb_source_contains_required_types(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-breadcrumb.php' );
		$this->assertStringContainsString( 'BreadcrumbList', $src );
		$this->assertStringContainsString( 'ListItem', $src );
		$this->assertStringContainsString( 'itemListElement', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 5. Menu generator source-grep locks
	// ────────────────────────────────────────────────────────────────────────

	public function test_menu_generator_uses_transient_cache(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
		$this->assertStringContainsString( 'get_transient', $src );
		$this->assertStringContainsString( 'set_transient', $src );
		$this->assertStringContainsString( 'lafka_menu_jsonld', $src );
	}

	public function test_menu_cache_busted_on_product_save(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
		$this->assertStringContainsString( 'save_post_product', $src );
		$this->assertStringContainsString( 'delete_transient', $src );
	}

	/**
	 * @dataProvider cacheBustHookProvider
	 */
	public function test_menu_cache_busted_on_each_relevant_hook( string $hook ): void {
		// Regression lock for v9.7.5 — before this version only save_post_product
		// busted the cache, so a product going out of stock or a category rename
		// could leave stale schema in the transient for up to 12 hours.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'" . preg_quote( $hook, '/' ) . "'/",
			$src,
			"Menu schema must bust its transient cache on the '{$hook}' hook."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function cacheBustHookProvider(): array {
		return array(
			'product save'              => array( 'save_post_product' ),
			'product delete'            => array( 'delete_post' ),
			'stock status change'       => array( 'woocommerce_product_set_stock_status' ),
			'variation stock change'    => array( 'woocommerce_variation_set_stock_status' ),
			'product API update'        => array( 'woocommerce_update_product' ),
			'category edit'             => array( 'edited_product_cat' ),
			'category create'           => array( 'created_product_cat' ),
			'category delete'           => array( 'delete_product_cat' ),
		);
	}

	public function test_delete_post_bust_filtered_to_product_post_type(): void {
		// Naively hooking delete_post would bust the cache on every post deletion
		// sitewide — must be filtered to 'product' to avoid pointless invalidation.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
		$this->assertMatchesRegularExpression(
			"/'product'\s*===\s*get_post_type/",
			$src,
			'delete_post hook must filter on product post-type before busting cache.'
		);
	}

	public function test_menu_generator_filters_uncategorized(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php' );
		$this->assertStringContainsString( 'uncategorized', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 6. Product generator source-grep locks
	// ────────────────────────────────────────────────────────────────────────

	public function test_product_generator_handles_variable_products(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString( 'AggregateOffer', $src );
		$this->assertStringContainsString( 'get_variation_prices', $src );
	}

	public function test_product_generator_emits_price_valid_until(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString( 'priceValidUntil', $src );
	}

	public function test_product_generator_emits_brand(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString( "'Brand'", $src );
		$this->assertStringContainsString( "'brand'", $src );
	}

	public function test_product_generator_conditionally_emits_aggregate_rating(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php' );
		$this->assertStringContainsString( 'AggregateRating', $src );
		$this->assertStringContainsString( 'get_review_count', $src );
		$this->assertStringContainsString( 'get_average_rating', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 7. Currency resolution (v9.7.3 — replaces hardcoded 'CAD' literals)
	// ────────────────────────────────────────────────────────────────────────

	public function test_no_hardcoded_currency_literals_in_schema_files(): void {
		// Regression lock for v9.7.3. Before this version six 'priceCurrency'
		// emissions were hardcoded to 'CAD' — wrong on every non-CAD store.
		$paths = array(
			dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php',
			dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php',
		);
		foreach ( $paths as $path ) {
			$src = file_get_contents( $path );
			$this->assertDoesNotMatchRegularExpression(
				"/'priceCurrency'\s*=>\s*'[A-Z]{3}'/",
				$src,
				basename( $path ) . ' must not hardcode an ISO-4217 currency literal in priceCurrency.'
			);
		}
	}

	public function test_currency_helper_reads_woocommerce_currency(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( 'EUR', \lafka_schema_get_price_currency() );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 8. Breadcrumb i18n (v9.7.4 — labels translatable for non-English stores)
	// ────────────────────────────────────────────────────────────────────────

	public function test_breadcrumb_home_label_is_translatable(): void {
		// Regression lock: 'Home' as a string literal in lafka_schema_breadcrumb_item()
		// would emit untranslated to non-English stores. Must be wrapped in __().
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-breadcrumb.php' );
		$this->assertMatchesRegularExpression(
			"/__\(\s*'Home'\s*,\s*'lafka-plugin'\s*\)/",
			$src,
			"Breadcrumb 'Home' label must be translatable via __()."
		);
	}

	public function test_breadcrumb_menu_label_is_translatable(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-breadcrumb.php' );
		$this->assertMatchesRegularExpression(
			"/__\(\s*'Menu'\s*,\s*'lafka-plugin'\s*\)/",
			$src,
			"Breadcrumb 'Menu' label must be translatable via __()."
		);
	}

	public function test_currency_filter_can_override(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'CAD' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'lafka_schema_price_currency' === $hook ? 'GBP' : $value;
			}
		);

		$this->assertSame( 'GBP', \lafka_schema_get_price_currency() );
	}

	public function test_currency_helper_treats_empty_wc_currency_as_usd(): void {
		// Some headless WC configurations return '' from get_woocommerce_currency
		// during early bootstrap; emitting '' would produce invalid schema.
		Functions\when( 'get_woocommerce_currency' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( 'USD', \lafka_schema_get_price_currency() );
	}
}
