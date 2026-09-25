<?php
/**
 * GX1 / A5: only fatals whose file lives in Lafka code become `php`
 * incidents; other plugins' fatals are left to WooCommerce.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Fatal_Capture;
use Lafka_Log;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-fatal-capture.php';

final class FatalCaptureScopeTest extends TestCase {

	/** @var array<int,array{level:string,message:string,context:array}> */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Log::reset();
		$this->logged = array();
		Functions\when( 'get_option' )->justReturn( array() );
		$test = $this;
		Lafka_Log::set_logger(
			new class( $test ) {
				public function __construct( private $test ) {}
				public function log( $level, $message, $context = array() ): void {
					$this->test->capture( $level, $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		Lafka_Log::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Logger double sink. */
	public function capture( $level, $message, $context ): void {
		$this->logged[] = compact( 'level', 'message', 'context' );
	}

	private static function plugin_file( string $relative ): string {
		return dirname( LAFKA_PLUGIN_FILE ) . '/' . $relative;
	}

	public function test_a_fatal_in_the_plugin_is_recorded_as_a_critical_php_incident(): void {
		$recorded = Lafka_Fatal_Capture::on_shutdown_error(
			array(
				'type'    => E_ERROR,
				'message' => "Uncaught TypeError: bad arg in /srv/www/wp-content/plugins/lafka-plugin/incl/x.php:12\nStack trace:\n#0 {main}",
				'file'    => self::plugin_file( 'incl/store-api/class-lafka-store-api.php' ),
				'line'    => 99,
			)
		);

		self::assertTrue( $recorded );
		self::assertSame( 'critical', $this->logged[0]['level'] );
		self::assertSame( 'lafka-php', $this->logged[0]['context']['source'] );
		self::assertSame( 'php_fatal', $this->logged[0]['context']['code'] );
		self::assertSame( 99, $this->logged[0]['context']['line'] );
		self::assertStringNotContainsString( 'Stack trace', $this->logged[0]['message'] );
		self::assertStringNotContainsString( '/srv/www', $this->logged[0]['message'] );
	}

	public function test_fatals_outside_lafka_are_ignored(): void {
		self::assertFalse(
			Lafka_Fatal_Capture::on_shutdown_error(
				array(
					'type'    => E_ERROR,
					'message' => 'boom',
					'file'    => '/srv/www/wp-content/plugins/some-other-plugin/main.php',
					'line'    => 1,
				)
			)
		);
		self::assertSame( array(), $this->logged );
	}

	public function test_non_fatal_error_types_are_ignored(): void {
		self::assertFalse(
			Lafka_Fatal_Capture::on_shutdown_error(
				array(
					'type'    => E_WARNING,
					'message' => 'meh',
					'file'    => self::plugin_file( 'lafka-plugin.php' ),
					'line'    => 1,
				)
			)
		);
		self::assertFalse( Lafka_Fatal_Capture::on_shutdown_error( 'not an array' ) );
	}

	public function test_scope_follows_the_roots_and_their_filter(): void {
		self::assertTrue( Lafka_Fatal_Capture::is_lafka_file( '/themes/lafka/functions.php', array( '/themes/lafka' ) ) );
		self::assertFalse( Lafka_Fatal_Capture::is_lafka_file( '/themes/lafka-other/functions.php', array( '/themes/lafka' ) ) );
		self::assertFalse( Lafka_Fatal_Capture::is_lafka_file( '', array( '/themes/lafka' ) ) );

		Functions\when( 'get_template' )->justReturn( 'lafka' );
		Functions\when( 'get_template_directory' )->justReturn( '/srv/themes/lafka' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/srv/themes/lafka-child' );
		$roots = Lafka_Fatal_Capture::roots();
		self::assertContains( '/srv/themes/lafka/', $roots );
		self::assertContains( '/srv/themes/lafka-child/', $roots );

		Functions\when( 'get_template' )->justReturn( 'twentytwentyfive' );
		self::assertNotContains( '/srv/themes/lafka/', Lafka_Fatal_Capture::roots() );
	}
}
