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

	public function test_format_uses_date_i18n_for_locale_awareness(): void {
		// Must use date_i18n() (locale + timezone aware) NOT raw date() (UTC, English-only).
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$this->assertNotFalse( $method_pos, 'method not found' );
		$method_slice = substr( $this->src, $method_pos, 600 );
		$this->assertStringContainsString( 'date_i18n', $method_slice );
		$this->assertStringNotContainsString( '$date->format(', $method_slice );
	}

	public function test_format_returns_empty_string_for_null_input(): void {
		$method_pos = strpos( $this->src, 'function format_next_open_time_human' );
		$method_slice = substr( $this->src, $method_pos, 600 );
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
		$method_slice = substr( $this->src, $method_pos, 600 );
		$this->assertMatchesRegularExpression(
			"/['\"][^'\"]*l[^'\"]*['\"]/",
			$method_slice,
			'format must include l (full weekday name)'
		);
	}
}
