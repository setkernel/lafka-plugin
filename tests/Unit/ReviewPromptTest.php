<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReviewPromptTest extends TestCase {

	public function test_email_module_exists(): void {
		$this->assertNotEmpty( file_get_contents( dirname( __DIR__, 2 ) . '/incl/emails/lafka-review-prompt-email.php' ) );
	}

	public function test_cli_module_exists(): void {
		$this->assertNotEmpty( file_get_contents( dirname( __DIR__, 2 ) . '/incl/cli/lafka-reviews-cli.php' ) );
	}

	public function test_email_hooks_order_completed(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/incl/emails/lafka-review-prompt-email.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]woocommerce_order_status_completed['\"]/",
			$module
		);
	}

	public function test_email_uses_wp_schedule_single_event(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/incl/emails/lafka-review-prompt-email.php' );
		$this->assertStringContainsString( 'wp_schedule_single_event', $module );
	}

	public function test_email_filters_exposed_for_operator_control(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/incl/emails/lafka-review-prompt-email.php' );
		$this->assertStringContainsString( 'lafka_review_prompt_delay_days', $module );
		$this->assertStringContainsString( 'lafka_review_prompt_enabled', $module );
		$this->assertStringContainsString( 'lafka_review_prompt_subject', $module );
		$this->assertStringContainsString( 'lafka_review_prompt_message', $module );
	}

	public function test_cli_command_registered(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/incl/cli/lafka-reviews-cli.php' );
		$this->assertMatchesRegularExpression(
			"/WP_CLI::add_command\(\s*['\"]lafka reviews['\"]/",
			$module
		);
	}

	public function test_cli_subcommands_present(): void {
		$module = file_get_contents( dirname( __DIR__, 2 ) . '/incl/cli/lafka-reviews-cli.php' );
		$this->assertMatchesRegularExpression( '/public function status\(/', $module );
		$this->assertMatchesRegularExpression( '/public function enable\(/', $module );
		$this->assertMatchesRegularExpression( '/public function disable\(/', $module );
	}

	public function test_main_plugin_requires_modules(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka-reviews-cli.php', $main );
		$this->assertStringContainsString( 'lafka-review-prompt-email.php', $main );
	}
}
