<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BestsellerEyebrowTest extends TestCase {

    public function test_get_bestseller_ids_function(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString( 'function lafka_pdp_get_bestseller_ids', $src );
    }

    public function test_uses_transient_cache(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString( 'get_transient', $src );
        $this->assertStringContainsString( 'set_transient', $src );
    }

    public function test_queries_90_day_window(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString( 'INTERVAL 90 DAY', $src );
    }

    public function test_respects_eyebrow_toggle(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString( 'lafka_pdp_show_bestseller_eyebrow', $src );
    }

    public function test_render_function_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString( 'function lafka_pdp_render_bestseller_eyebrow', $src );
    }

    public function test_query_routes_on_hpos_status(): void {
        // Regression lock for v9.7.7. Pre-fix the query hardcoded the HPOS
        // wc_orders table — non-HPOS sites got an empty bestseller list
        // silently. The fix branches on OrderUtil::custom_orders_table_usage_is_enabled()
        // and uses {$wpdb->posts} with post_type='shop_order' on legacy installs.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertStringContainsString(
            'OrderUtil::custom_orders_table_usage_is_enabled',
            $src,
            'Bestseller query must check HPOS status before picking the order table.'
        );
    }

    public function test_legacy_branch_joins_wp_posts_with_shop_order_filter(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php' );
        $this->assertMatchesRegularExpression(
            "/o\.post_type\s*=\s*'shop_order'/",
            $src,
            'Legacy CPT branch must filter the order join by post_type=shop_order.'
        );
        $this->assertMatchesRegularExpression(
            "/post_status\s+IN\s*\(\s*'wc-completed'\s*,\s*'wc-processing'\s*\)/",
            $src,
            'Legacy branch must use post_status (not status) on wp_posts.'
        );
    }
}
