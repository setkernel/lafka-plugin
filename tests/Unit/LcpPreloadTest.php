<?php
/**
 * Homepage LCP hero: the preload URL and the fetchpriority hint come from the
 * same configured hero, on the front page only.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LcpPreloadTest extends TestCase {

	/** @var array<string, mixed> */
	private array $mods = array();
	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => $this->mods[ $key ] ?? $default );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( $id ) => "https://example.test/uploads/{$id}.jpg" );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( true );
		require_once dirname( __DIR__, 2 ) . '/incl/perf/lcp-preload.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: array, 1: array, 2: string, 3: int}>
	 */
	public static function heroes(): array {
		return array(
			'canonical Customizer hero'   => array( array( 'lafka_home_hero_image_id' => 12 ), array(), 'https://example.test/uploads/12.jpg', 12 ),
			'canonical wins over legacy'  => array( array( 'lafka_home_hero_image_id' => 12, 'lafka_homepage_hero_image' => '34' ), array(), 'https://example.test/uploads/12.jpg', 12 ),
			'legacy theme_mod attachment' => array( array( 'lafka_homepage_hero_image' => '34' ), array(), 'https://example.test/uploads/34.jpg', 34 ),
			'legacy theme_mod URL'        => array( array( 'lafka_homepage_hero_image' => 'https://cdn.example.test/hero.jpg' ), array(), 'https://cdn.example.test/hero.jpg', 0 ),
			'legacy option attachment'    => array( array(), array( 'lafka_homepage_hero_attachment_id' => 56 ), 'https://example.test/uploads/56.jpg', 56 ),
		);
	}

	#[DataProvider( 'heroes' )]
	public function test_preload_and_fetchpriority_follow_the_same_hero( array $mods, array $options, string $url, int $attachment_id ): void {
		$this->mods    = $mods;
		$this->options = $options;

		$this->assertSame( $url, lafka_lcp_image_url( '' ) );

		foreach ( array( 12, 34, 56 ) as $id ) {
			$attr = lafka_lcp_hero_image_attributes( array(), (object) array( 'ID' => $id ) );
			$this->assertSame( $id === $attachment_id ? 'high' : null, $attr['fetchpriority'] ?? null, "Attachment {$id}" );
		}
	}

	public function test_no_hints_off_the_front_page(): void {
		$this->mods = array( 'lafka_home_hero_image_id' => 12 );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame( 'upstream', lafka_lcp_image_url( 'upstream' ) );
		$this->assertSame( array(), lafka_lcp_hero_image_attributes( array(), (object) array( 'ID' => 12 ) ) );
	}

	public function test_without_a_configured_hero_the_first_front_page_image_is_preloaded(): void {
		$this->options = array( 'page_on_front' => 5 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => '<p>Hi</p><img class="x" src="https://example.test/hero.webp">' ) );

		$this->assertSame( 'https://example.test/hero.webp', lafka_lcp_image_url( '' ) );
	}
}
