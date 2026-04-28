<?php
/**
 * P6-SEO-1/2/3/6: JSON-LD structured data regression tests.
 *
 * Tests are a mix of source-grep locks (cheap, catch registration drift) and
 * functional generator tests (exercise the helper/generator functions directly
 * without booting WordPress via Brain Monkey stubs).
 *
 * Why source-grep for class/hook registration:
 *   A source-grep test that asserts "add_action('wp_head', ..., 11)" is a
 *   zero-cost lock that fires immediately if a refactor accidentally removes
 *   the hook registration, without needing to spin up WP.
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

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
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

    public function test_graph_wrapper_used(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
        $this->assertStringContainsString( "'@graph'", $src );
        $this->assertStringContainsString( "'@context'", $src );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Helpers: lafka_schema_get_nap() — single source-of-truth
    // ────────────────────────────────────────────────────────────────────────

    public function test_helpers_nap_matches_shortcode_canonical_values(): void {
        $nap = lafka_schema_get_nap();

        $this->assertSame( 'Peppery Pizza & Poutine', $nap['name'] );
        $this->assertSame( '512 Sackville Drive', $nap['street'] );
        $this->assertSame( 'Lower Sackville', $nap['city'] );
        $this->assertSame( 'NS', $nap['region'] );
        $this->assertSame( 'B4C 2R8', $nap['postal'] );
        $this->assertSame( 'CA', $nap['country'] );
        $this->assertSame( '+19022525353', $nap['telephone'] );
        $this->assertSame( '+1 902-252-5353', $nap['telephone_display'] );
    }

    public function test_helpers_geo_returns_expected_coordinates(): void {
        $geo = lafka_schema_get_geo();

        $this->assertSame( 'GeoCoordinates', $geo['@type'] );
        $this->assertEqualsWithDelta( 44.7720, $geo['latitude'], 0.0001 );
        $this->assertEqualsWithDelta( -63.6789, $geo['longitude'], 0.0001 );
    }

    public function test_helpers_opening_hours_covers_all_seven_days(): void {
        $hours = lafka_schema_get_opening_hours();

        $this->assertCount( 1, $hours, 'Should be one OpeningHoursSpecification block (Mon-Sun same hours)' );
        $spec = $hours[0];

        $this->assertSame( 'OpeningHoursSpecification', $spec['@type'] );
        $this->assertSame( '11:00', $spec['opens'] );
        $this->assertSame( '23:00', $spec['closes'] );
        $this->assertCount( 7, $spec['dayOfWeek'], 'All 7 days must be listed' );
    }

    public function test_helpers_same_as_contains_required_platforms(): void {
        Functions\when( 'apply_filters' )->returnArg( 2 );

        $same_as = lafka_schema_get_same_as();

        $this->assertContains( 'https://www.facebook.com/three.ppps/', $same_as );
        $this->assertContains( 'https://www.yelp.com/biz/peppery-pizza-and-poutine-lower-sackville', $same_as );
        $this->assertContains( 'https://www.yellowpages.ca/bus/Nova-Scotia/Lower-Sackville/Peppery-Pizza-and-Poutine/100178791.html', $same_as );
    }

    public function test_helpers_postal_address_structure(): void {
        $addr = lafka_schema_get_postal_address();

        $this->assertSame( 'PostalAddress', $addr['@type'] );
        $this->assertSame( '512 Sackville Drive', $addr['streetAddress'] );
        $this->assertSame( 'Lower Sackville', $addr['addressLocality'] );
        $this->assertSame( 'NS', $addr['addressRegion'] );
        $this->assertSame( 'B4C 2R8', $addr['postalCode'] );
        $this->assertSame( 'CA', $addr['addressCountry'] );
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Restaurant generator: required fields
    // ────────────────────────────────────────────────────────────────────────

    public function test_restaurant_generator_returns_required_fields(): void {
        Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
        Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
        Functions\when( 'get_site_icon_url' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        $schema = lafka_schema_restaurant();

        $this->assertIsArray( $schema );
        $this->assertContains( 'Restaurant', (array) $schema['@type'] );
        $this->assertContains( 'LocalBusiness', (array) $schema['@type'] );
        $this->assertContains( 'FoodEstablishment', (array) $schema['@type'] );
        $this->assertSame( 'Peppery Pizza & Poutine', $schema['name'] );
        $this->assertSame( '+19022525353', $schema['telephone'] );
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

    public function test_restaurant_schema_encodes_to_valid_json(): void {
        Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
        Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
        Functions\when( 'get_site_icon_url' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 2 );

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
        Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
        Functions\when( 'home_url' )->justReturn( 'http://localhost:8891' );
        Functions\when( 'get_site_icon_url' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        $schema = lafka_schema_restaurant();
        $json   = json_encode( $schema, JSON_UNESCAPED_SLASHES );

        // json_encode (without JSON_HEX_AMP) preserves "&" as the literal character,
        // which is correct for JSON-LD — only HTML contexts need &amp;.
        // Verify: no HTML escaping (&amp;) and the name literal is present.
        $this->assertStringNotContainsString( '&amp;', $json );
        $this->assertStringContainsString( 'Peppery Pizza', $json );
        $this->assertStringContainsString( 'Poutine', $json );
        // The raw ampersand must appear (not HTML-escaped and not unicode-escaped).
        $decoded = json_decode( $json, true );
        $this->assertSame( 'Peppery Pizza & Poutine', $decoded['name'] );
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
}
