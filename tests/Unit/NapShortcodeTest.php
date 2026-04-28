<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-UX-5 regression lock: canonical NAP must be the single source-of-truth.
 * If these strings ever drift from GBP / Yelp / TripAdvisor citation values,
 * local SEO consistency breaks.
 */
final class NapShortcodeTest extends TestCase {

    public function test_canonical_nap_values_in_shortcode(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
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
        // Phone: tel link must be all-digit "+19022525353"
        $this->assertStringContainsString( "'+19022525353'", $src );
        // Display phone in human-friendly form
        $this->assertStringContainsString( "'+1 902-252-5353'", $src );
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
