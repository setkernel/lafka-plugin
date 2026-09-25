<?php
/**
 * PDP "Make it a meal" upsell row: operator-curated picks per top-level
 * category (Customizer lafka_upsell_<root-slug>_<n>), falling back to best
 * sellers topped up with recent in-stock products.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UpsellRowTest extends TestCase {

	/** @var array<string, int> */
	private array $theme_mods = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->theme_mods = array();
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-bestseller.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-upsell-row.php';

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) $k ) );
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => $this->theme_mods[ $key ] ?? $default );
		// Best sellers come from the object cache (no order query).
		Functions\when( 'wp_cache_get' )->justReturn( array( 5, 9 ) );
		// Recent in-stock products used to top up the best-seller fallback.
		Functions\when( 'wc_get_products' )->justReturn( array( 9, 30, 31, 32, 33 ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function product_in_category( string $slug, array $ancestor_ids = array() ): void {
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array(
				(object) array(
					'slug'    => $slug,
					'term_id' => 50,
				),
			)
		);
		Functions\when( 'get_ancestors' )->justReturn( $ancestor_ids );
		Functions\when( 'get_term' )->alias( static fn( $id ) => (object) array( 'slug' => 'root-' . $id ) );
	}

	public function test_curated_picks_come_from_the_root_category(): void {
		// Product sits in a sub-category whose top-level ancestor is term 1.
		$this->product_in_category( 'margherita', array( 3, 1 ) );
		$this->theme_mods = array(
			'lafka_upsell_root-1_1'     => 21,
			'lafka_upsell_root-1_3'     => 23,
			'lafka_upsell_margherita_1' => 99, // Sub-category keys are ignored.
		);

		self::assertSame( array( 21, 23 ), lafka_pdp_get_upsell_ids( 42 ) );
	}

	/**
	 * Per-product categories: product id => [ slug, term_id ] (all top level).
	 *
	 * @param array<int, array{0:string,1:int}> $map
	 */
	private function catalogue( array $map ): void {
		Functions\when( 'wp_get_post_terms' )->alias(
			static function ( $id ) use ( $map ) {
				$row = $map[ (int) $id ] ?? array( 'misc', 99 );
				return array(
					(object) array(
						'slug'    => $row[0],
						'term_id' => $row[1],
					),
				);
			}
		);
		Functions\when( 'get_ancestors' )->justReturn( array() );
	}

	public function test_falls_back_to_best_sellers_topped_up_with_recent_products(): void {
		$this->catalogue( array( 42 => array( 'wings', 50 ) ) );

		self::assertSame( array( 5, 9, 30, 31, 32, 33 ), lafka_pdp_get_upsell_ids( 42 ) );
	}

	public function test_fallback_skips_the_products_own_top_category(): void {
		// M-17: a poutine page must not offer four poutines.
		$this->catalogue(
			array(
				42 => array( 'poutine', 60 ),
				5  => array( 'poutine', 60 ),
				9  => array( 'drinks', 61 ),
				30 => array( 'poutine', 60 ),
				31 => array( 'sides', 62 ),
			)
		);

		self::assertSame( array( 9, 31, 32, 33 ), lafka_pdp_get_upsell_ids( 42 ) );
	}

	public function test_row_skips_the_current_product_and_hidden_products_and_shows_at_most_four(): void {
		$this->catalogue( array( 9 => array( 'wings', 50 ) ) );
		Functions\when( 'wc_get_product' )->alias(
			static fn( $id ) => new class( $id ) {
				public function __construct( private int $id ) {}
				public function is_visible() {
					return 31 !== $this->id;
				}
				public function get_name() {
					return 'Item ' . $this->id;
				}
				public function get_price_html() {
					return '$5.00';
				}
				public function get_type() {
					return 'simple';
				}
			}
		);
		Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/p/' . $id );
		Functions\when( 'get_the_post_thumbnail' )->justReturn( '' );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();

		ob_start();
		lafka_pdp_render_upsell_row( 9 );
		$html = (string) ob_get_clean();

		preg_match_all( '/data-product-id="(\d+)"/', $html, $ids );
		// Candidates 5, 9, 30, 31, 32, 33 → drop the current product (9), keep the
		// first four (5, 30, 31, 32), skip the hidden one (31).
		self::assertSame( array( '5', '30', '32' ), $ids[1] );
	}
}
