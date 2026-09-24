<?php
/**
 * The vendors-list shortcode partial escapes vendor data, category names and
 * translations (v9.7.19 removed `echo __()` from it). Translations are hostile
 * here: the esc_*() variants escape them, plain __()/_e() do not, so an
 * unescaped translation or value leaks the payload.
 *
 * The contact-form partial is covered by ContactFormPartialTest.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShortcodePartialsEscapingTest extends TestCase {

	private const PAYLOAD = '"><script>x()</script>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$escape = static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES );
		Functions\when( '__' )->justReturn( self::PAYLOAD );
		Functions\when( '_e' )->alias(
			static function () {
				echo self::PAYLOAD; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- models an unescaped translation.
			}
		);
		Functions\when( 'esc_html__' )->justReturn( $escape( self::PAYLOAD ) );
		Functions\when( 'esc_attr__' )->justReturn( $escape( self::PAYLOAD ) );
		Functions\when( 'esc_html_e' )->alias(
			static function () use ( $escape ) {
				echo $escape( self::PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			static function () use ( $escape ) {
				echo $escape( self::PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
			}
		);
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->alias( $escape );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wcmp_get_vendor_review_info' )->justReturn( array() );
		Functions\when( 'get_terms' )->justReturn(
			array(
				(object) array(
					'term_id' => 5,
					'name'    => self::PAYLOAD,
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['WCMp'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_vendors_list_escapes_vendor_data_and_translations(): void {
		$GLOBALS['WCMp'] = (object) array(
			'template' => new class() {
				public function get_template( $name, $args = array() ): void {}
			},
		);
		// Variables the shortcode handler hands the partial.
		$hide_order_by     = 'no';
		$sort_type         = 'name';
		$selected_category = 5;
		$vendor_info       = array(
			array(
				'term_id'          => 1,
				'ID'               => 2,
				'vendor_permalink' => 'https://example.test/' . self::PAYLOAD,
				'vendor_image'     => 'https://example.test/' . self::PAYLOAD,
				'vendor_name'      => self::PAYLOAD,
			),
		);

		ob_start();
		include dirname( __DIR__, 2 ) . '/shortcodes/partials/vendors_list.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="button">' . htmlspecialchars( self::PAYLOAD, ENT_QUOTES ) . '</a>', $html, 'The vendor rendered.' );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
