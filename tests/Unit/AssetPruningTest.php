<?php
/**
 * Front-end asset pruning: Revolution Slider, WPBakery CSS and jquery-migrate
 * are dropped where the page can't need them, and kept where it can.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AssetPruningTest extends TestCase {

	/** @var list<string> "style:handle" / "script:handle" dequeues. */
	private array $dequeued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->dequeued = array();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 12 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_dequeue_style' )->alias( fn( $h ) => $this->dequeued[] = 'style:' . $h );
		Functions\when( 'wp_dequeue_script' )->alias( fn( $h ) => $this->dequeued[] = 'script:' . $h );
		Functions\when( 'wp_deregister_style' )->justReturn( true );
		Functions\when( 'wp_deregister_script' )->justReturn( true );
		$registry = static fn( array $registered ) => (object) array( 'registered' => $registered );
		Functions\when( 'wp_styles' )->justReturn(
			$registry(
				array(
					'rs-custom'    => (object) array( 'src' => 'https://example.test/wp-content/plugins/revslider/public/x.css' ),
					'vc-extra'     => (object) array( 'src' => 'https://example.test/wp-content/plugins/js_composer/assets/y.css' ),
					'theme-style'  => (object) array( 'src' => 'https://example.test/wp-content/themes/lafka/style.css' ),
				)
			)
		);
		Functions\when( 'wp_scripts' )->justReturn( $registry( array() ) );
		$this->with_content( '' );
		require_once dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function with_content( string $content ): void {
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => $content ) );
	}

	public function test_revslider_assets_are_dropped_on_pages_without_a_slider(): void {
		lafka_perf_dequeue_unused_revslider();
		$this->assertContains( 'style:sr7', $this->dequeued );
		$this->assertContains( 'style:rs-custom', $this->dequeued, 'Unknown handles are matched by the /revslider/ path.' );
		$this->assertNotContains( 'style:theme-style', $this->dequeued );
	}

	public function test_revslider_assets_are_kept_for_an_attached_slider_or_shortcode(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'home-slider' );
		lafka_perf_dequeue_unused_revslider();

		Functions\when( 'get_post_meta' )->justReturn( 'none' );
		$this->with_content( 'Intro [rev_slider alias="home"]' );
		lafka_perf_dequeue_unused_revslider();

		$this->assertSame( array(), $this->dequeued );
	}

	public function test_vc_css_is_kept_for_builder_content_but_dropped_on_native_templates(): void {
		$this->with_content( '[vc_row][vc_column]Hi[/vc_column][/vc_row]' );
		lafka_perf_dequeue_unused_vc();
		$this->assertSame( array(), $this->dequeued );

		// front-page.php renders no stored builder content, so a [vc_ marker is a false positive.
		Functions\when( 'is_front_page' )->justReturn( true );
		lafka_perf_dequeue_unused_vc();
		$this->assertContains( 'style:js_composer_front', $this->dequeued );
		$this->assertContains( 'style:vc-extra', $this->dequeued );
		$this->assertNotContains( 'style:theme-style', $this->dequeued );
	}

	public function test_jquery_migrate_is_detached_on_the_front_end_only(): void {
		$scripts = static fn() => (object) array(
			'registered' => array( 'jquery' => (object) array( 'deps' => array( 'jquery-core', 'jquery-migrate' ) ) ),
		);

		$front = $scripts();
		lafka_perf_dequeue_jquery_migrate( $front );
		$this->assertSame( array( 'jquery-core' ), array_values( $front->registered['jquery']->deps ) );

		Functions\when( 'is_admin' )->justReturn( true );
		$admin = $scripts();
		lafka_perf_dequeue_jquery_migrate( $admin );
		$this->assertSame( array( 'jquery-core', 'jquery-migrate' ), $admin->registered['jquery']->deps );

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => 'lafka_keep_jquery_migrate' === $hook ? true : $value );
		$opted_out = $scripts();
		lafka_perf_dequeue_jquery_migrate( $opted_out );
		$this->assertSame( array( 'jquery-core', 'jquery-migrate' ), $opted_out->registered['jquery']->deps );
	}
}
