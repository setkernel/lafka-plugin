<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OrderHoursFormattingTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php' );
	}

	public function test_format_next_open_time_human_method_defined(): void {
		$this->assertStringContainsString(
			'public static function format_next_open_time_human',
			$this->src,
			'Lafka_Order_Hours must define a public static format_next_open_time_human() method.'
		);
	}

	public function test_format_uses_wp_date_for_timezone_awareness(): void {
		// Must use wp_date() (locale + timezone aware) NOT raw date() (UTC, English-only)
		// and NOT date_i18n() (which discards the DateTime's timezone for the timestamp arg).
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$this->assertNotFalse( $method_pos, 'method not found' );
		$method_slice = substr( $this->src, $method_pos, 1000 );
		$this->assertStringContainsString( 'wp_date', $method_slice );
		$this->assertStringContainsString( '$datetime->getTimezone()', $method_slice );
		$this->assertStringNotContainsString( '$date->format(', $method_slice );
	}

	public function test_format_returns_empty_string_for_null_input(): void {
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$method_slice = substr( $this->src, $method_pos, 1000 );
		$this->assertMatchesRegularExpression(
			'/null\s*===\s*\$datetime|\$datetime\s*===\s*null|is_null\s*\(\s*\$datetime/',
			$method_slice,
			'must guard against null input'
		);
		$this->assertStringContainsString( "return ''", $method_slice );
	}

	public function test_format_uses_full_day_name_and_12h_time(): void {
		// "Saturday at 11:00 AM" — full weekday (l) + locale-friendly time.
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$method_slice = substr( $this->src, $method_pos, 1000 );
		$this->assertMatchesRegularExpression(
			"/['\"][^'\"]*l[^'\"]*['\"]/",
			$method_slice,
			'format must include l (full weekday name)'
		);
	}

	public function test_format_filter_name_is_public_api(): void {
		// Operators can hook lafka_next_open_time_format to customize the
		// rendered string. Renaming this filter silently breaks their
		// customizations — lock the name as a regression test.
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$method_slice = substr( $this->src, $method_pos, 1000 );
		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*['\"]lafka_next_open_time_format['\"]/",
			$method_slice,
			'lafka_next_open_time_format filter name is public API; do not rename'
		);
	}
}
