<?php
/**
 * Social share links (incl/lafka-share-links.php).
 *
 *   - lafka_has_to_show_share(): posts follow the per-post switch, falling
 *     back to the global "share on posts" option unless the post opts out;
 *     products follow the global "share on products" option;
 *   - lafka_share_links(): every link opens a new tab with
 *     rel="noopener noreferrer" (tab-nabbing), every href goes through
 *     esc_url(), and the default networks are all HTTPS (or mailto:);
 *   - the `lafka_share_networks` filter can add networks; malformed entries
 *     are skipped.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShareLinksTest extends TestCase {

	/** @var array<string, string> */
	private array $options = array();

	private string $single_meta = '';

	private bool $is_product = false;

	/** @var array<string, mixed>|null Forced `lafka_share_networks` result. */
	private ?array $networks = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options     = array();
		$this->single_meta = '';
		$this->is_product  = false;
		$this->networks    = null;

		$escape = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES );
		Functions\when( 'lafka_get_option' )->justReturn( false );
		Functions\when( 'get_option' )->alias( fn( $key ) => $this->options[ $key ] ?? false );
		Functions\when( 'get_the_ID' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => 7 === $id && 'lafka_show_share' === $key ? $this->single_meta : '' );
		Functions\when( 'is_product' )->alias( fn() => $this->is_product );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.test/pizza.jpg' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		// Like WordPress: a disallowed protocol yields ''.
		Functions\when( 'esc_url' )->alias( static fn( $url ) => preg_match( '#^(https://|mailto:)#', (string) $url ) ? $escape( $url ) : '' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => 'lafka_share_networks' === $hook && null !== $this->networks ? $this->networks : $value );

		require_once dirname( __DIR__, 2 ) . '/incl/lafka-share-links.php';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['post'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( string $title = 'Fish & Chips' ): string {
		$GLOBALS['post'] = (object) array( 'ID' => 7 );
		ob_start();
		lafka_share_links( $title, 'https://example.test/fish-and-chips/' );
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, string> Network key => href.
	 */
	private static function links( string $html ): array {
		preg_match_all( '/<a class="lafka-share-([a-z]+)" title="[^"]*" href="([^"]*)" target="_blank" rel="noopener noreferrer">/', $html, $m );
		self::assertSame( substr_count( $html, '<a ' ), count( $m[0] ), 'Every share link opens a new tab with rel="noopener noreferrer".' );
		return array_combine( $m[1], $m[2] );
	}

	public function test_posts_follow_the_per_post_switch_over_the_global_option(): void {
		$this->assertFalse( lafka_has_to_show_share() );

		$this->single_meta = 'yes';
		$this->assertTrue( lafka_has_to_show_share() );

		$this->options['lafka_share_on_posts'] = 'yes';
		$this->single_meta                     = '';
		$this->assertTrue( lafka_has_to_show_share() );

		$this->single_meta = 'no';
		$this->assertFalse( lafka_has_to_show_share() );
	}

	public function test_products_follow_the_global_product_option(): void {
		$this->is_product                      = true;
		$this->options['lafka_share_on_posts'] = 'yes';
		$this->single_meta                     = 'yes';
		$this->assertFalse( lafka_has_to_show_share() );

		$this->options['lafka_share_on_products'] = 'yes';
		$this->assertTrue( lafka_has_to_show_share() );
	}

	public function test_nothing_renders_when_sharing_is_off(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_default_networks_are_https_noopener_and_encoded(): void {
		$this->single_meta = 'yes';
		$html              = $this->render();
		$links             = self::links( $html );

		$this->assertSame( array( 'facebook', 'twitter', 'pinterest', 'linkedin', 'whatsapp', 'telegram', 'email', 'vkontakte' ), array_keys( $links ) );
		foreach ( $links as $network => $href ) {
			$this->assertMatchesRegularExpression( '#^(https://|mailto:)#', $href, $network );
		}
		// Title and link are RFC 3986-encoded into the query (then HTML-escaped).
		$this->assertStringContainsString( 'u=https%3A%2F%2Fexample.test%2Ffish-and-chips%2F&amp;t=Fish%20%26%20Chips', $links['facebook'] );
		$this->assertStringContainsString( 'media=https%3A%2F%2Fexample.test%2Fpizza.jpg', $links['pinterest'] );
		$this->assertStringStartsWith( '<div class="lafka-share-links"><span>Share:</span>', $html );
	}

	public function test_filtered_networks_are_escaped_and_malformed_entries_skipped(): void {
		$this->single_meta = 'yes';
		$this->networks    = array(
			'mastodon' => array(
				'label' => 'Toot "this"',
				'url'   => 'https://share.example.test/?text=x',
			),
			'evil'     => array(
				'label' => 'Evil',
				'url'   => 'javascript:alert(1)',
			),
			'nolabel'  => array( 'url' => 'https://example.test/' ),
			'scalar'   => 'https://example.test/',
		);
		$html  = $this->render();
		$links = self::links( $html );

		$this->assertSame( array( 'mastodon', 'evil' ), array_keys( $links ) );
		$this->assertSame( '', $links['evil'] );
		$this->assertStringContainsString( 'title="Toot &quot;this&quot;"', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}
}
