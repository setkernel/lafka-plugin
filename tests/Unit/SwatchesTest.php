<?php
/**
 * Variation swatches: term-meta saving is limited to product-attribute
 * taxonomies and authorised users (v9.7.10), swatch types are extensible
 * (v9.7.11), and swatches render escaped markup.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_WC_Variation_Swatches;
use Lafka_WC_Variation_Swatches_Admin;
use Lafka_WC_Variation_Swatches_Frontend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

require_once dirname( __DIR__, 2 ) . '/incl/swatches/variation-swatches.php';
require_once dirname( __DIR__, 2 ) . '/incl/swatches/classes/class-admin.php';
require_once dirname( __DIR__, 2 ) . '/incl/swatches/classes/class-frontend.php';

final class SwatchesTest extends TestCase {

	/** @var array<int, array<string, string>> term id => meta written */
	private array $term_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->term_meta = array();
		$_POST           = array();

		// Like WordPress, escaping does not double-encode existing entities.
		$escape = static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( trim( (string) $v ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'update_term_meta' )->alias(
			function ( $term_id, $key, $value ) {
				$this->term_meta[ $term_id ][ $key ] = $value;
				return true;
			}
		);

		// The plugin singleton without its WordPress hook wiring.
		$swatches = ( new ReflectionClass( Lafka_WC_Variation_Swatches::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( Lafka_WC_Variation_Swatches::class, 'instance' ) )->setValue( null, $swatches );
		$swatches->init_types();
	}

	protected function tearDown(): void {
		( new ReflectionProperty( Lafka_WC_Variation_Swatches::class, 'instance' ) )->setValue( null, null );
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, array{0: string, 1: bool, 2: array<int, array<string, string>>}> */
	public static function term_saves(): array {
		return array(
			'product category'           => array( 'product_cat', true, array() ),
			'attribute, no capability'   => array( 'pa_size', false, array() ),
			'attribute, authorised user' => array(
				'pa_size',
				true,
				array(
					7 => array(
						'color' => '#ff0000',
						'label' => 'XL',
					),
				),
			),
		);
	}

	#[DataProvider( 'term_saves' )]
	public function test_swatch_meta_is_saved_only_on_attribute_terms_by_authorised_users( string $taxonomy, bool $can, array $expected ): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $can && 'manage_product_terms' === $cap );
		$_POST = array(
			'color'    => '#ff0000',
			'label'    => ' XL ',
			'fabric'   => 'not a swatch type',
			'tag-name' => 'Size',
		);

		( new Lafka_WC_Variation_Swatches_Admin() )->save_term_meta( 7, 70, $taxonomy );

		self::assertSame( $expected, $this->term_meta );
	}

	public function test_swatch_types_registered_through_the_filter_are_offered_and_saved(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $types ) => 'lafka_wcs_attribute_types' === $hook ? $types + array( 'gradient' => 'Gradient' ) : $types
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		\Lafka_WCVS()->init_types();

		$selector = \Lafka_WCVS()->add_attribute_types( array( 'select' => 'Select' ) );
		$_POST    = array( 'gradient' => 'sunset' );
		( new Lafka_WC_Variation_Swatches_Admin() )->save_term_meta( 7, 70, 'pa_style' );

		self::assertSame( array( 'select', 'color', 'image', 'label', 'gradient' ), array_keys( $selector ) );
		self::assertSame( array( 7 => array( 'gradient' => 'sunset' ) ), $this->term_meta );
	}

	public function test_translated_type_label_is_escaped_in_the_term_form(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $types ) => array( 'label' => '<script>alert(1)</script>Label' ) + $types
		);
		\Lafka_WCVS()->init_types();

		ob_start();
		( new Lafka_WC_Variation_Swatches_Admin() )->attribute_fields( 'label', 'XL', 'edit' );
		$html = (string) ob_get_clean();

		self::assertStringStartsWith( '<tr class="form-field"><th><label for="term-label">&lt;script&gt;', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'name="label" value="XL"', $html );
	}

	private static function term( string $slug, string $name ): object {
		return (object) array(
			'term_id' => 11,
			'slug'    => $slug,
			'name'    => $name,
		);
	}

	private static function swatch( string $type, object $term, string $selected ): string {
		return ( new Lafka_WC_Variation_Swatches_Frontend() )->swatch_html(
			'',
			$term,
			(object) array( 'attribute_type' => $type ),
			array( 'selected' => $selected )
		);
	}

	public function test_color_swatch_uses_the_term_color_with_a_readable_text_overlay(): void {
		Functions\when( 'get_term_meta' )->justReturn( '#ff8000' );

		self::assertSame(
			'<span class="swatch swatch-color swatch-orange selected" style="background-color:#ff8000;color:rgba(255,128,0,0.5);" title="Orange &lt;b&gt;" data-value="orange">Orange &lt;b&gt;</span>',
			self::swatch( 'color', self::term( 'orange', 'Orange <b>' ), 'Orange' )
		);
	}

	public function test_image_swatch_without_an_image_falls_back_to_the_woocommerce_placeholder(): void {
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'WC' )->justReturn(
			new class() {
				public function plugin_url() {
					return 'https://example.test/wp-content/plugins/woocommerce';
				}
			}
		);

		$html = self::swatch( 'image', self::term( 'thin', 'Thin' ), 'thick' );

		self::assertStringContainsString( '<img src="https://example.test/wp-content/plugins/woocommerce/assets/images/placeholder.png"', $html );
		self::assertStringNotContainsString( 'selected', $html );
	}

	public function test_label_swatch_prefers_the_term_label_meta(): void {
		Functions\when( 'get_term_meta' )->alias( static fn( $id, $key ) => 'label' === $key ? 'L' : '' );

		self::assertSame(
			'<span class="swatch swatch-label swatch-large " title="Large" data-value="large">L</span>',
			self::swatch( 'label', self::term( 'large', 'Large' ), '' )
		);
	}

	/**
	 * save_term_meta() only learns the taxonomy (and so only engages its
	 * product-attribute guard) when the term hooks pass 3 arguments. The hook
	 * registration itself can't be observed in this harness: tests/bootstrap.php
	 * defines add_action() as a no-op before Brain Monkey loads.
	 */
	public function test_term_save_hooks_pass_the_taxonomy_argument(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/swatches/classes/class-admin.php' );

		foreach ( array( 'created_term', 'edit_term' ) as $hook ) {
			self::assertMatchesRegularExpression(
				"/add_action\\(\\s*'{$hook}'\\s*,\\s*array\\(\\s*\\\$this\\s*,\\s*'save_term_meta'\\s*\\)\\s*,\\s*10\\s*,\\s*3\\s*\\)/",
				$src
			);
		}
	}
}
