<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrepTimeTest extends TestCase {

    public function test_get_prep_time_function_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php' );
        $this->assertStringContainsString( 'function lafka_pdp_get_prep_time', $src );
    }

    public function test_render_prep_time_function_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php' );
        $this->assertStringContainsString( 'function lafka_pdp_render_prep_time', $src );
    }

    public function test_falls_back_to_default_setting(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php' );
        $this->assertStringContainsString( "'lafka_pdp_prep_time_default'", $src );
    }

    public function test_per_category_setting_pattern(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php' );
        $this->assertStringContainsString( "'lafka_pdp_prep_time_'", $src );
    }

    public function test_closed_state_handled(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php' );
        $this->assertStringContainsString( 'lafka_get_restaurant_info', $src );
    }
}
