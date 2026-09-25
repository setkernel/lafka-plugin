<?php
/**
 * T-19 (GX): add-on group headings. With the theme opt-in
 * (`lafka-addon-group-toggle`) the heading is `<h3><button type="button"
 * aria-expanded aria-controls>` wrapping a `.lafka-addon-body` region — a real
 * disclosure instead of `<h3 role="button">`. Without it the heading stays a
 * plain `<h3>` (no inert button) and the markup is unchanged.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/incl/addons/lafka-product-addons.php';

final class AddonGroupToggleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $filters = array();

	private bool $theme_support = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filters       = array();
		$this->theme_support = false;
		$escape              = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html__' )->alias( $escape );
		Functions\when( 'esc_html_e' )->alias( static fn( $v ) => print( htmlspecialchars( (string) $v, ENT_QUOTES ) ) );
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'current_theme_supports' )->alias( fn( $feature ) => 'lafka-addon-group-toggle' === $feature && $this->theme_support );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		if ( ! class_exists( 'Lafka_Engine_Display' ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/display/class-engine-display.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render addon-start + a fake option row + addon-end.
	 *
	 * @param array<string, mixed> $vars Template variables.
	 */
	private function render( array $vars ): string {
		$dir  = dirname( __DIR__, 2 ) . '/incl/addons/templates/';
		$vars = array_merge(
			array(
				'addon'                   => array( 'type' => 'checkbox' ),
				'required'                => 1,
				'name'                    => 'Toppings',
				'description'             => '',
				'type'                    => 'checkbox',
				'has_options_with_images' => false,
			),
			$vars
		);
		ob_start();
		( static function () use ( $dir, $vars ) {
			extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors wc_get_template().
			include $dir . 'addon-start.php';
			echo '<p class="form-row">option</p>';
			include $dir . 'addon-end.php';
		} )();
		return (string) ob_get_clean();
	}

	public function test_opted_in_heading_is_a_disclosure_button_controlling_the_body(): void {
		$html = $this->render(
			array(
				'toggle'  => true,
				'body_id' => 'lafka-addon-body-3',
			)
		);

		self::assertMatchesRegularExpression( '#<h3 class="addon-name"><button type="button" class="lafka-addon-toggle" aria-expanded="true" aria-controls="lafka-addon-body-3">Toppings#', $html );
		self::assertStringContainsString( '<abbr class="required"', $html, 'The required marker stays inside the button name.' );
		self::assertStringNotContainsString( 'role="button"', $html );
		self::assertMatchesRegularExpression( '#<div class="lafka-addon-body" id="lafka-addon-body-3">.*<p class="form-row">option</p>.*</div><!-- \.lafka-addon-body -->\s*</div>#s', $html );
		self::assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'Balanced markup.' );
	}

	public function test_without_the_opt_in_the_heading_is_unchanged(): void {
		foreach ( array( array(), array( 'toggle' => false ), array( 'toggle' => true ) /* no body id */ ) as $vars ) {
			$html = $this->render( $vars );
			self::assertMatchesRegularExpression( '#<h3 class="addon-name">Toppings#', $html );
			self::assertStringNotContainsString( '<button', $html );
			self::assertStringNotContainsString( 'lafka-addon-body', $html );
			self::assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ) );
		}
	}

	public function test_a_group_without_a_name_never_gets_a_button(): void {
		$html = $this->render(
			array(
				'name'    => '',
				'toggle'  => true,
				'body_id' => 'lafka-addon-body-4',
			)
		);
		self::assertStringNotContainsString( '<button', $html );
	}

	public function test_the_opt_in_is_a_theme_feature_and_a_filter(): void {
		self::assertFalse( \Lafka_Engine_Display::group_toggle_enabled( array() ) );

		$this->theme_support = true;
		self::assertTrue( \Lafka_Engine_Display::group_toggle_enabled( array() ) );

		$this->filters['lafka_addon_group_toggle'] = false;
		self::assertFalse( \Lafka_Engine_Display::group_toggle_enabled( array() ) );
	}
}
