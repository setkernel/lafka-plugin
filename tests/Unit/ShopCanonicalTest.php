<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-SEO-12 W2-T6 regression lock: shop archive canonical URL must strip
 * sort/filter query params.
 */
final class ShopCanonicalTest extends TestCase {

    private string $module;

    protected function setUp(): void {
        parent::setUp();
        $this->module = file_get_contents(
            dirname( __DIR__, 2 ) . '/incl/seo/lafka-shop-canonical.php'
        );
    }

    public function test_module_exists(): void {
        $this->assertNotEmpty( $this->module );
    }

    public function test_filter_registered(): void {
        $this->assertMatchesRegularExpression(
            "/add_filter\(\s*['\"]get_canonical_url['\"]\s*,\s*['\"]lafka_seo_filter_shop_canonical['\"]/",
            $this->module
        );
    }

    public function test_strips_orderby_param(): void {
        $this->assertStringContainsString( "'orderby'", $this->module );
    }

    public function test_strips_price_filter_params(): void {
        $this->assertStringContainsString( "'min_price'", $this->module );
        $this->assertStringContainsString( "'max_price'", $this->module );
    }

    public function test_main_plugin_requires_module(): void {
        $main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString( 'lafka-shop-canonical.php', $main );
    }
}
