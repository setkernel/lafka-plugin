<?php
/**
 * `wp lafka image-alts scan|apply`: product images get the product name,
 * filename-like alts are replaced by the parent title or cleared, meaningful
 * alts are left alone, and only `apply` writes.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ImageAltBackfillTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Separate process: the module only loads when the WP_CLI constant is
	 * true, which must not leak into other suites.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_scan_reports_and_apply_rewrites_only_bad_alts(): void {
		define( 'WP_CLI', true );
		class_alias(
			get_class(
				new class() {
					public static function add_command( ...$args ): void {}
					public static function log( ...$args ): void {}
					public static function success( ...$args ): void {}
				}
			),
			'WP_CLI'
		);

		// attachment id => [ current alt, parent id ]
		$attachments = array(
			1 => array( '', 10 ),                          // on a product
			2 => array( 'Untitled-design-11', 0 ),          // filename-like, no parent
			3 => array( 'A wood fired pizza on a board', 0 ), // meaningful
			4 => array( 'image-600x600', 20 ),              // filename-like, parent page
			5 => array( 'IMG_2041.jpg', 20 ),               // filename-like, parent page
		);
		$parents = array(
			10 => array( 'product', 'Garden Salad' ),
			20 => array( 'page', 'About Us' ),
		);
		Functions\when( 'get_posts' )->justReturn( array_keys( $attachments ) );
		Functions\when( 'get_post_meta' )->alias( static fn( $id ) => $attachments[ $id ][0] );
		Functions\when( 'wp_get_post_parent_id' )->alias( static fn( $id ) => $attachments[ $id ][1] );
		Functions\when( 'get_post_type' )->alias( static fn( $id ) => $parents[ $id ][0] ?? 'attachment' );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => $parents[ $id ][1] ?? '' );
		Functions\when( 'WP_CLI\Utils\format_items' )->justReturn( null );
		Functions\when( 'sanitize_key' )->returnArg();
		$writes = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$writes ) {
				$writes[ $id ] = array( $key, $value );
			}
		);

		require dirname( __DIR__, 2 ) . '/incl/cli/lafka-image-alt-backfill.php';
		$command = new \Lafka_Image_Alt_Backfill_Command();

		$command->scan( array(), array() );
		$this->assertSame( array(), $writes, 'scan must be read-only.' );

		$command->apply( array(), array() );
		$this->assertSame(
			array(
				1 => array( '_wp_attachment_image_alt', 'Garden Salad' ),
				2 => array( '_wp_attachment_image_alt', '' ),
				4 => array( '_wp_attachment_image_alt', 'About Us' ),
				5 => array( '_wp_attachment_image_alt', 'About Us' ),
			),
			$writes
		);

		// --post-type limits the run to images attached to that post type: the
		// unattached image (2) and the page images (4, 5) are left alone.
		$writes = array();
		$command->apply( array(), array( 'post-type' => 'product' ) );
		$this->assertSame( array( 1 => array( '_wp_attachment_image_alt', 'Garden Salad' ) ), $writes );
	}
}
