<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JqueryMigrateDequeueTest extends TestCase {

    private string $module;

    protected function setUp(): void {
        parent::setUp();
        $this->module = file_get_contents(
            dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php'
        );
    }

    public function test_dequeue_function_present(): void {
        $this->assertStringContainsString( 'lafka_perf_dequeue_jquery_migrate', $this->module );
    }

    public function test_hooks_wp_default_scripts(): void {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]wp_default_scripts['\"]\s*,\s*['\"]lafka_perf_dequeue_jquery_migrate['\"]/",
            $this->module
        );
    }

    public function test_skips_in_admin(): void {
        $this->assertMatchesRegularExpression( '/if\s*\(\s*is_admin\(\)\s*\)/', $this->module );
    }

    public function test_filter_for_opt_out(): void {
        $this->assertStringContainsString( 'lafka_keep_jquery_migrate', $this->module );
    }
}
