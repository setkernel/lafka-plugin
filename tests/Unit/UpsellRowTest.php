<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpsellRowTest extends TestCase {

    public function test_panel_class_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-upsell.php' );
        $this->assertStringContainsString( 'class Lafka_Customizer_Upsell', $src );
    }

    public function test_panel_registers_per_category_section(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-upsell.php' );
        $this->assertStringContainsString( 'add_section', $src );
        $this->assertStringContainsString( "'lafka_upsell_'", $src );
    }

    public function test_each_section_has_four_settings(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-upsell.php' );
        $this->assertMatchesRegularExpression( '/\$i\s*<=\s*4/', $src );
        $this->assertStringContainsString( 'for ( $i = 1', $src );
    }

    public function test_render_function_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php' );
        $this->assertStringContainsString( 'function lafka_pdp_render_upsell_row', $src );
    }

    public function test_get_upsell_ids_function(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php' );
        $this->assertStringContainsString( 'function lafka_pdp_get_upsell_ids', $src );
    }

    public function test_falls_back_to_bestsellers(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php' );
        $this->assertStringContainsString( 'lafka_pdp_get_bestseller_ids', $src );
    }
}
