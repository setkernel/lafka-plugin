<?php
/**
 * CLS guard: <img> tags missing width/height get them from the attachment
 * record, else from the local file (cached); remote images are never read.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ImageDimensionsTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		$this->uploads = sys_get_temp_dir() . '/lafka-imgdims-' . getmypid();
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => 'https://example.test/wp-content/uploads',
				'basedir' => $this->uploads,
			)
		);
		Functions\when( 'content_url' )->justReturn( 'https://example.test/wp-content' );
		require_once dirname( __DIR__, 2 ) . '/incl/perf/image-dimensions.php';
	}

	protected function tearDown(): void {
		if ( is_dir( $this->uploads ) ) {
			array_map( 'unlink', glob( $this->uploads . '/*' ) ?: array() );
			rmdir( $this->uploads );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dimensions_come_from_the_attachment_record(): void {
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( $id ) => 55 === $id ? array( 'width' => 800, 'height' => 600 ) : false
		);
		$html = '<p><img class="wp-image-55" src="https://example.test/wp-content/uploads/a.jpg" alt=""></p>';

		$this->assertSame(
			'<p><img class="wp-image-55" src="https://example.test/wp-content/uploads/a.jpg" alt="" width="800" height="600"></p>',
			lafka_inject_image_dimensions( $html )
		);
	}

	public function test_local_file_is_measured_once_and_cached(): void {
		mkdir( $this->uploads );
		// 3x2 PNG.
		file_put_contents( $this->uploads . '/b.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAEklEQVR4nGP4z8DAwMDAwMAAAB7wAv8gHkNtAAAAAElFTkSuQmCC' ) );
		$cached = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$cached ) {
				$cached[ $key ] = $value;
				return true;
			}
		);

		$out = lafka_inject_image_dimensions( '<img src="https://example.test/wp-content/uploads/b.png">' );

		$this->assertSame( '<img src="https://example.test/wp-content/uploads/b.png" width="3" height="2">', $out );
		$this->assertSame( array( 'lafka_imgdims_' . md5( 'https://example.test/wp-content/uploads/b.png' ) => array( 3, 2 ) ), $cached );
	}

	public function test_images_that_already_have_dimensions_or_are_remote_are_left_alone(): void {
		$sized  = '<img src="https://example.test/wp-content/uploads/c.jpg" width="10" height="10">';
		$remote = '<img src="https://cdn.other.test/d.jpg">';

		$this->assertSame( $sized . $remote, lafka_inject_image_dimensions( $sized . $remote ) );
		$this->assertNull( lafka_url_to_local_path( 'https://cdn.other.test/d.jpg' ) );
		$this->assertSame( $this->uploads . '/2026/e.jpg', lafka_url_to_local_path( 'https://example.test/wp-content/uploads/2026/e.jpg' ) );
	}
}
