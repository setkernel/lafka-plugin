<?php
/**
 * T-04 (GX): new JPEG / PNG uploads are saved as WebP (every generated size)
 * when the server's image editor can write WebP — default on (auto), an
 * operator toggle on Lafka → Modules, and a filter. Operator-set formats for
 * other MIME types are never overridden.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class WebpUploadsTest extends TestCase {

	/** @var list<array{0:string, 1:mixed, 2:int, 3:int}>|null */
	private static ?array $registrations = null;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $filters = array();

	private bool $supported = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options   = array();
		$this->filters   = array();
		$this->supported = true;

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		Functions\when( 'wp_image_editor_supports' )->alias( fn( $args ) => $this->supported && 'image/webp' === ( $args['mime_type'] ?? '' ) );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		if ( null === self::$registrations ) {
			$before = count( $GLOBALS['lafka_test_hooks'] );
			require_once dirname( __DIR__, 2 ) . '/incl/perf/lafka-webp-uploads.php';
			self::$registrations = array_slice( $GLOBALS['lafka_test_hooks'], $before );
		}
		lafka_webp_uploads_reset_cache();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_hooks_the_core_output_format_filter_and_the_modules_page(): void {
		$tags = array_map( static fn( $r ) => $r[0] . ' -> ' . $r[1], self::$registrations );
		self::assertContains( 'image_editor_output_format -> lafka_webp_uploads_output_format', $tags );
		self::assertContains( 'lafka_register_modules -> lafka_webp_uploads_register_module', $tags );
	}

	public function test_jpeg_and_png_uploads_become_webp_by_default_when_supported(): void {
		self::assertSame(
			array(
				'image/jpeg' => 'image/webp',
				'image/png'  => 'image/webp',
			),
			lafka_webp_uploads_output_format( array() )
		);
	}

	public function test_nothing_changes_when_the_server_cannot_write_webp(): void {
		$this->supported = false;
		self::assertSame( array(), lafka_webp_uploads_output_format( array() ) );

		$this->options['lafka_webp_uploads'] = 'yes';
		self::assertSame( array(), lafka_webp_uploads_output_format( array() ), 'Forcing on never breaks uploads on an unsupported server.' );
	}

	public function test_operator_off_and_filter_win(): void {
		$this->options['lafka_webp_uploads'] = 'no';
		self::assertSame( array(), lafka_webp_uploads_output_format( array() ) );

		$this->options = array();
		$this->filters['lafka_webp_uploads_enabled'] = false;
		self::assertSame( array(), lafka_webp_uploads_output_format( array() ) );
	}

	public function test_existing_mappings_are_kept(): void {
		self::assertSame(
			array(
				'image/png'  => 'image/avif',
				'image/jpeg' => 'image/webp',
			),
			lafka_webp_uploads_output_format( array( 'image/png' => 'image/avif' ) )
		);
	}

	public function test_listed_on_the_modules_page_and_toggleable(): void {
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
		require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
		\Lafka_Module_Registry::reset();

		lafka_webp_uploads_register_module();
		$module = \Lafka_Module_Registry::get( 'webp_uploads' );

		self::assertNotNull( $module );
		self::assertTrue( $module->default_enabled() );
		self::assertTrue( $module->is_enabled() );
		$module->set_enabled( false );
		self::assertSame( 'no', $this->options['lafka_webp_uploads'] );
		self::assertFalse( lafka_webp_uploads_enabled() );
		\Lafka_Module_Registry::reset();
	}
}
