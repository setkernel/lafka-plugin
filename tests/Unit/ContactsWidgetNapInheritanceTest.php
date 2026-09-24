<?php
/**
 * LafkaContactsWidget renders the canonical NAP from lafka_get_restaurant_info()
 * (WooCommerce store settings → Lafka options) for every field left blank, and
 * an operator-typed per-widget value when one is set.
 *
 * lafka_get_restaurant_info() is real (it cannot be redefined once another test
 * loads it), so its WordPress inputs are stubbed instead.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-widget-stub.php';

final class ContactsWidgetNapInheritanceTest extends TestCase {

	/** @var array<string, string> */
	private array $options = array();

	/** @var array<string, string> */
	private array $theme_mods = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->theme_mods = array();

		Functions\when( 'get_option' )->alias( fn( $key, $default = '' ) => $this->options[ $key ] ?? $default );
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => $this->theme_mods[ $key ] ?? $default );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );

		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/widgets/LafkaContactsWidget.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $instance Widget instance.
	 */
	private function render( array $instance ): string {
		ob_start();
		( new \LafkaContactsWidget() )->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			$instance
		);
		return (string) ob_get_clean();
	}

	private function configure_store(): void {
		$this->options = array(
			'woocommerce_store_address'   => '1 Example Street',
			'woocommerce_store_city'      => 'Exampleville',
			'woocommerce_store_postcode'  => '00000',
			'woocommerce_default_country' => 'US:IL',
			'woocommerce_store_phone'     => '+15550100',
			'lafka_business_email'        => 'hello@example.test',
		);
	}

	public function test_blank_fields_inherit_the_canonical_nap(): void {
		$this->configure_store();

		$html = $this->render( array() );

		$this->assertStringContainsString( '<span class="footer_address">1 Example Street, Exampleville</span>', $html );
		$this->assertStringContainsString( '<a href="tel:+15550100">+15550100</a>', $html );
		$this->assertStringContainsString( '<a href="mailto:hello@example.test">hello@example.test</a>', $html );
	}

	public function test_widget_value_overrides_and_whitespace_falls_back(): void {
		$this->configure_store();

		$html = $this->render(
			array(
				'address' => 'Widget Address',
				'phone'   => '   ',
			)
		);

		$this->assertStringContainsString( '<span class="footer_address">Widget Address</span>', $html );
		$this->assertStringNotContainsString( 'Exampleville', $html );
		$this->assertStringContainsString( '<a href="tel:+15550100">+15550100</a>', $html, 'A whitespace-only override must not suppress the canonical phone.' );
	}

	public function test_worktime_and_fax_have_no_canonical_fallback(): void {
		$this->configure_store();
		$this->theme_mods['lafka_business_hours_mon'] = '11:00-23:00';

		$this->assertStringNotContainsString( 'footer_time', $this->render( array() ) );
		$this->assertStringNotContainsString( 'footer_fax', $this->render( array() ) );
		$this->assertStringContainsString( '<span class="footer_fax">555-0101</span>', $this->render( array( 'fax' => '555-0101' ) ) );
	}

	public function test_nothing_renders_for_an_unconfigured_store(): void {
		$this->assertSame( '', $this->render( array() ) );
	}
}
