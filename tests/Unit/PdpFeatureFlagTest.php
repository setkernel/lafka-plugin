<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PdpFeatureFlagTest extends TestCase {

    public function test_pdp_customizer_class_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-pdp.php' );
        $this->assertStringContainsString( 'class Lafka_Customizer_PDP', $src );
    }

    public function test_redesign_enabled_setting_registered(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-pdp.php' );
        $this->assertStringContainsString( "'lafka_pdp_redesign_enabled'", $src );
    }

    public function test_eyebrow_toggle_setting_registered(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-pdp.php' );
        $this->assertStringContainsString( "'lafka_pdp_show_bestseller_eyebrow'", $src );
    }

    public function test_helper_function_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-pdp.php' );
        $this->assertStringContainsString( 'function lafka_pdp_redesign_enabled', $src );
    }
}
