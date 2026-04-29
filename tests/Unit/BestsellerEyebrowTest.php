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
}
