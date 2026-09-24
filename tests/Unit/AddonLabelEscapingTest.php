<?php
/**
 * Add-on option templates (checkbox / radiobutton / textarea) escape the
 * stored option label (stored XSS from a shop-manager-editable field reaches
 * every product page) while keeping the wc_price() markup of the price intact
 * (escaping it rendered raw <span> tags to customers in v8.17.0).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/incl/addons/lafka-product-addons.php';

final class AddonLabelEscapingTest extends TestCase {

	private const HOSTILE_LABEL = 'Cheese <img src=x onerror=alert(1)>';
	private const PRICE_HTML    = '<span class="lafka-addon-price">(<span class="woocommerce-Price-amount amount"><bdi>$1.50</bdi></span>)</span>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_textarea' )->alias( $escape );
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ) );
		// The rendered price as WooCommerce's wc_price() (via the option-price filter) builds it.
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value = null ) => 'lafka_product_addons_option_price' === $hook ? self::PRICE_HTML : $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: string}> */
	public static function templates(): array {
		return array(
			'checkbox'    => array( 'checkbox' ),
			'radiobutton' => array( 'radiobutton' ),
			'textarea'    => array( 'textarea' ),
		);
	}

	#[DataProvider( 'templates' )]
	public function test_label_is_escaped_and_price_markup_survives( string $template ): void {
		$html = $this->render(
			$template,
			array(
				'field-name' => '94-extras-0',
				'options'    => array(
					array(
						'id'    => 'cheese',
						'label' => self::HOSTILE_LABEL,
						'price' => '',
					),
				),
			)
		);

		self::assertStringNotContainsString( '<img', $html, 'A stored label must never reach the page as markup.' );
		self::assertStringContainsString( 'Cheese &lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringContainsString( self::PRICE_HTML, $html, 'The wc_price() markup must render as HTML, not as escaped text.' );
	}

	private function render( string $template, array $addon ): string {
		$previous_product                = $GLOBALS['product'] ?? null;
		$previous_display                = $GLOBALS['Lafka_Engine_Display'] ?? null;
		$GLOBALS['product']              = null;
		$GLOBALS['Lafka_Engine_Display'] = new class() {
			public function get_addon_option_custom_image_id( $option ) {
				return 0;
			}
			public function get_addon_option_image_classes( $image_id ) {
				return array();
			}
		};

		$path = dirname( __DIR__, 2 ) . '/incl/addons/templates/' . $template . '.php';
		ob_start();
		( static function () use ( $path, $addon ) {
			include $path;
		} )();
		$html = (string) ob_get_clean();

		$GLOBALS['product']              = $previous_product;
		$GLOBALS['Lafka_Engine_Display'] = $previous_display;

		return $html;
	}
}
