<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-UX-5 + P6-SEO-1 regression lock: canonical NAP must be the single
 * source-of-truth.
 *
 * After the W2-T1 refactor the literal strings live in
 * incl/schema/lafka-schema-helpers.php (lafka_schema_get_nap()) and the
 * [lafka_nap] shortcode in lafka-plugin.php delegates to that helper.
 * Tests verify:
 *   1. Canonical values are present in the helpers file.
 *   2. The shortcode in lafka-plugin.php delegates to lafka_schema_get_nap()
 *      instead of duplicating the literals.
 *   3. The shortcode is registered and implements the part= attribute.
 *
 * If these strings ever drift from GBP / Yelp / TripAdvisor citation values,
 * local SEO consistency breaks.
 */
final class NapShortcodeTest extends TestCase {

    /**
     * Canonical NAP values must live in the schema helpers file (single source-of-truth).
     */
    public function test_canonical_nap_values_in_helpers(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php' );
        // Restaurant name
        $this->assertStringContainsString( "Peppery Pizza & Poutine", $src );
        // Street: must be "Drive" (full word), not "Dr"
        $this->assertStringContainsString( "'512 Sackville Drive'", $src );
        // City
        $this->assertStringContainsString( "'Lower Sackville'", $src );
        // Region: must be 2-letter "NS" (postal-address standard), not "Nova Scotia"
        $this->assertStringContainsString( "'NS'", $src );
        // Postal
        $this->assertStringContainsString( "'B4C 2R8'", $src );
        // Phone: E.164 format
        $this->assertStringContainsString( "'+19022525353'", $src );
        // Display phone in human-friendly form
        $this->assertStringContainsString( "'+1 902-252-5353'", $src );
    }

    /**
     * The shortcode must delegate to lafka_schema_get_nap() — no inline literals.
     */
    public function test_shortcode_delegates_to_nap_helper(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            'lafka_schema_get_nap()',
            $src,
            'lafka_nap_shortcode must call lafka_schema_get_nap() (not duplicate inline literals)'
        );
    }

    public function test_shortcode_registered(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertMatchesRegularExpression(
            "/add_shortcode\(\s*['\"]lafka_nap['\"]\s*,\s*['\"]lafka_nap_shortcode['\"]/",
            $src
        );
        $this->assertStringContainsString( 'function lafka_nap_shortcode', $src );
    }

    public function test_part_attribute_supported(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        // Must support part-based switching (verify via switch case keywords)
        foreach ( [ 'name', 'address', 'street', 'city', 'region', 'postal', 'phone' ] as $part ) {
            $this->assertMatchesRegularExpression(
                "/case\s+['\"]{$part}['\"]/",
                $src,
                "[lafka_nap part='{$part}'] is not handled"
            );
        }
    }
}
