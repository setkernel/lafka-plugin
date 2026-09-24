<?php
/**
 * Best-seller data + PDP eyebrow badge.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class BestsellerEyebrowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		require_once dirname( __DIR__, 2 ) . '/tests/Unit/Stubs/wc-orderutil-stub.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php';
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'esc_html' )->returnArg();
		// A translation that visibly marks every string it translates.
		Functions\when( '__' )->alias( static fn( $text ) => '[fr] ' . $text );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( int $product_id ): string {
		ob_start();
		lafka_pdp_render_bestseller_eyebrow( $product_id );
		return (string) ob_get_clean();
	}

	public function test_badge_shows_the_translated_rank_for_a_top_three_product(): void {
		Functions\when( 'wp_cache_get' )->justReturn( array( 5, 9, 12, 40 ) );

		$this->assertSame(
			'<span class="lafka-pdp-eyebrow lafka-pdp-eyebrow--bestseller">[fr] ★ #2 BEST SELLER</span>',
			$this->render( 9 )
		);
		$this->assertSame( '', $this->render( 40 ), 'Only the top three carry the badge.' );
	}

	public function test_badge_respects_the_customizer_toggle(): void {
		Functions\when( 'wp_cache_get' )->justReturn( array( 5 ) );
		Functions\when( 'get_theme_mod' )->justReturn( 'no' );

		$this->assertSame( '', $this->render( 5 ) );
	}

	public function test_best_sellers_skip_deleted_products_and_are_cached(): void {
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		$live = Mockery::mock( 'WC_Product' );
		Functions\when( 'wc_get_product' )->alias( static fn( $id ) => in_array( $id, array( 5, 9 ), true ) ? $live : false );
		$cached = null;
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$cached ) {
				$cached = $value;
			}
		);
		$previous        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';
			public function get_col( $sql ) {
				return array( '5', '0', '77', '9' ); // 77 was deleted.
			}
		};

		$ids             = lafka_pdp_get_bestseller_ids();
		$GLOBALS['wpdb'] = $previous;

		$this->assertSame( array( 5, 9 ), $ids );
		$this->assertSame( array( 5, 9 ), $cached );
	}
}
