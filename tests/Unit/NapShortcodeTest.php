<?php
/**
 * [lafka_nap] (incl/schema/lafka-nap-shortcode.php): the NAP block and its
 * single parts, all from lafka_schema_get_nap() (Customizer-driven), escaped
 * on output, with a tap-to-call link only when a phone number is set.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NapShortcodeTest extends TestCase {

	private const NAP = array(
		'name'              => 'Test <Kitchen>',
		'street'            => '123 Test Street',
		'city'              => 'Testville',
		'region'            => 'TS',
		'postal'            => 'T1S 1S1',
		'country'           => 'CA',
		'telephone'         => '+15551234567',
		'telephone_display' => '(555) 123-4567',
	);

	/** @var list<array{0:string, 1:mixed}>|null add_shortcode() calls made when the module loaded. */
	private static ?array $shortcodes = null;

	/** @var array<string, string> */
	private array $nap = self::NAP;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->nap = self::NAP;

		$escape = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'shortcode_atts' )->alias( static fn( $defaults, $atts ) => array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ) );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => 'lafka_schema_nap' === $hook ? $this->nap : $value );
		// Inputs of lafka_get_restaurant_info(), which lafka_schema_get_nap() reads first.
		Functions\when( 'get_option' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
		if ( null === self::$shortcodes ) {
			self::$shortcodes = array();
			Functions\when( 'add_shortcode' )->alias(
				static function ( $tag, $callback ) {
					self::$shortcodes[] = array( $tag, $callback );
				}
			);
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-nap-shortcode.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_the_lafka_nap_shortcode(): void {
		$this->assertSame( array( array( 'lafka_nap', 'lafka_nap_shortcode' ) ), self::$shortcodes );
	}

	public function test_full_block_is_an_escaped_address_with_a_tap_to_call_link(): void {
		$this->assertSame(
			'<address class="lafka-nap"><strong>Test &lt;Kitchen&gt;</strong><br>123 Test Street, Testville, TS T1S 1S1<br><a href="tel:+15551234567">(555) 123-4567</a></address>',
			lafka_nap_shortcode( array() )
		);
		$this->assertSame( lafka_nap_shortcode( '' ), lafka_nap_shortcode( array( 'part' => 'unknown' ) ) );
	}

	public function test_single_parts(): void {
		$expected = array(
			'name'    => 'Test &lt;Kitchen&gt;',
			'address' => '123 Test Street, Testville, TS T1S 1S1',
			'street'  => '123 Test Street',
			'city'    => 'Testville',
			'region'  => 'TS',
			'postal'  => 'T1S 1S1',
			'phone'   => '<a href="tel:+15551234567">(555) 123-4567</a>',
		);
		foreach ( $expected as $part => $html ) {
			$this->assertSame( $html, lafka_nap_shortcode( array( 'part' => $part ) ), $part );
		}
	}

	public function test_phone_link_is_escaped(): void {
		$this->nap['telephone']         = '+1"555';
		$this->nap['telephone_display'] = '<b>555</b>';
		$link                           = '<a href="tel:+1&quot;555">&lt;b&gt;555&lt;/b&gt;</a>';
		$this->assertSame( $link, lafka_nap_shortcode( array( 'part' => 'phone' ) ) );
		$this->assertStringContainsString( '<br>' . $link . '</address>', lafka_nap_shortcode( array() ) );
	}

	public function test_no_phone_means_no_tel_link(): void {
		$this->nap['telephone'] = '';
		$this->assertSame( '', lafka_nap_shortcode( array( 'part' => 'phone' ) ) );
		$this->assertStringNotContainsString( 'tel:', lafka_nap_shortcode( array() ) );
	}

	public function test_address_skips_missing_pieces(): void {
		$this->nap = array_merge(
			self::NAP,
			array(
				'street' => '',
				'region' => '',
				'postal' => '',
			)
		);
		$this->assertSame( 'Testville', lafka_nap_shortcode( array( 'part' => 'address' ) ) );
	}
}
